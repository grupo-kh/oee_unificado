<?php
/**
 * tools/mant_prev_recover_secuencia.php
 * --------------------------------------------------------------
 * RECUPERACION de las maquinas y tareas de Secuencia (E66, RACK*,
 * PLATAFORMA*) que se borraron por error al hacer el import del .xlsx
 * nuevo (mi regex de Secuencia usaba 'RACKS%' con S y no matcheaba
 * los codigos reales tipo 'RACK CUSTODIAS TA LH - 03').
 *
 * Lee data/maintenance_completed.json (intervenciones legacy, NO se
 * tocaron) y reconstruye:
 *   - mant_maquinas  (UPSERT)
 *   - mant_plan      (UPSERT, con proxima_revision = max(fecha_proxima_original)
 *                     o si esa fecha ya pasó, +1 ciclo de periodicidad)
 *   - mant_completions (INSERT ON CONFLICT DO NOTHING — no toca lo que ya hay)
 *
 * Filtro de Secuencia (regex aplicado a cod_maquina_mant):
 *   ^E66([_\s\-]|$)
 *   ^RACK([\s\-]|$)
 *   ^PLATAFORMA
 *
 * Modo de uso (DRY-RUN por defecto, COMMIT con ?commit=1):
 *   http://localhost/PLAN_ATTAINMENT/views/mant_prev_recover_secuencia.php
 *   http://localhost/PLAN_ATTAINMENT/views/mant_prev_recover_secuencia.php?commit=1
 * --------------------------------------------------------------
 */

declare(strict_types=1);
ini_set('memory_limit', '4G');
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../lib/Db.php';

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=UTF-8');

$JSON_PATH = __DIR__ . '/../data/maintenance_completed.json';
$COMMIT    = !empty($_GET['commit']);

if (!is_file($JSON_PATH)) {
    echo "[ERR] No encuentro {$JSON_PATH}\n";
    exit(1);
}

echo "=== RECUPERACION SECUENCIA (E66 / RACK* / PLATAFORMA*) ===\n";
echo "Modo  : " . ($COMMIT ? "COMMIT" : "DRY-RUN (rollback)") . "\n";
echo "Origen: {$JSON_PATH} (" . filesize($JSON_PATH) . " bytes)\n\n";

// ─────────────────────────────────────────────────────────────
// 1) Leer JSON
// ─────────────────────────────────────────────────────────────
$raw = file_get_contents($JSON_PATH);
$j = json_decode($raw, true);
if (!is_array($j) || !isset($j['items'])) {
    echo "[ERR] Formato inesperado del JSON.\n";
    exit(1);
}
$items = $j['items'];
echo "JSON: " . count($items) . " intervenciones totales.\n";

// Filtro Secuencia por cod_maquina_mant
function isSecuencia(string $cmm): bool {
    if (preg_match('/^E66([_\s\-]|$)/i', $cmm)) return true;
    if (preg_match('/^RACK([\s\-]|$)/i', $cmm)) return true;
    if (preg_match('/^PLATAFORMA/i', $cmm))     return true;
    return false;
}

$itemsSec = array_values(array_filter($items, fn($it) =>
    is_array($it) && isSecuencia(trim((string)($it['cod_maquina_mant'] ?? '')))
));
echo "JSON: " . count($itemsSec) . " intervenciones de Secuencia.\n\n";

// ─────────────────────────────────────────────────────────────
// 2) Agrupar por (orden, tarea) - tareas distintas
// ─────────────────────────────────────────────────────────────
$tareasMap = []; // key "orden|tarea" => latest record
foreach ($itemsSec as $it) {
    $k = ($it['orden'] ?? '') . '|' . ($it['tarea'] ?? '');
    if (!isset($tareasMap[$k])) {
        $tareasMap[$k] = $it;
    } else {
        // Conservar la mas reciente (max fecha_proxima_original o fecha_intervencion)
        $cur = $tareasMap[$k];
        $curD = (string)($cur['fecha_proxima_original'] ?? $cur['fecha_intervencion'] ?? '0000-00-00');
        $newD = (string)($it['fecha_proxima_original']  ?? $it['fecha_intervencion']  ?? '0000-00-00');
        if ($newD > $curD) $tareasMap[$k] = $it;
    }
}
$tareas = array_values($tareasMap);
echo "Tareas distintas (orden|tarea): " . count($tareas) . "\n";

// Maquinas distintas
$maquinas = [];
foreach ($tareas as $t) {
    $cmm = trim((string)($t['cod_maquina_mant'] ?? ''));
    if ($cmm === '') continue;
    if (!isset($maquinas[$cmm])) {
        $maquinas[$cmm] = trim((string)($t['desc_maquina'] ?? $cmm));
    }
}
echo "Maquinas distintas: " . count($maquinas) . "\n\n";

// Helper: calcular siguiente fecha por periodicidad
function calcProx(string $base, string $period): ?string {
    if ($base === '' || $base === '0000-00-00') return null;
    try { $dt = new DateTime($base); } catch (\Throwable $e) { return null; }
    $u = mb_strtoupper(trim($period));
    $months = 0; $days = 0;
    switch ($u) {
        case 'SEMANAL':    $days = 7; break;
        case 'QUINCENAL':  $days = 15; break;
        case 'MENSUAL':    $months = 1; break;
        case 'BIMENSUAL':  $months = 2; break;
        case 'TRIMESTRAL': $months = 3; break;
        case 'SEMESTRAL':  $months = 6; break;
        case 'ANUAL':      $months = 12; break;
        case 'TRIANUAL':   $months = 36; break;
        default:           return null;
    }
    if ($months > 0) $dt->modify("+{$months} months");
    if ($days   > 0) $dt->modify("+{$days} days");
    return $dt->format('Y-m-d');
}

// ─────────────────────────────────────────────────────────────
// 3) APLICAR (transaccion)
// ─────────────────────────────────────────────────────────────
$pdo = Db::pg();
$pdo->beginTransaction();

try {
    // Stats antes
    $a1 = (int)(Db::pgFetchOne("SELECT COUNT(*) c FROM mant_maquinas")['c'] ?? 0);
    $a2 = (int)(Db::pgFetchOne("SELECT COUNT(*) c FROM mant_plan")['c'] ?? 0);
    $a3 = (int)(Db::pgFetchOne("SELECT COUNT(*) c FROM mant_completions")['c'] ?? 0);

    // 3.a) UPSERT mant_maquinas
    $stUpsertMaq = $pdo->prepare("
        INSERT INTO mant_maquinas (cod_maquina_mant, desc_maquina, is_user_added)
        VALUES (:cod, :desc, FALSE)
        ON CONFLICT (cod_maquina_mant) DO UPDATE SET
            desc_maquina = EXCLUDED.desc_maquina
    ");
    $cntMaq = 0;
    foreach ($maquinas as $cmm => $desc) {
        $stUpsertMaq->execute([':cod' => $cmm, ':desc' => $desc]);
        $cntMaq++;
    }

    // 3.b) UPSERT mant_plan (con proxima_revision)
    $stUpsertPlan = $pdo->prepare("
        INSERT INTO mant_plan (
            orden, tarea, cod_maquina_mant, desc_maquina,
            grupo, desc_grupo, periodicidad, desc_tarea, activa,
            ultima_revision, proxima_revision,
            alta_baja, ip_interna, tipo_realizacion, tipo_mantenimiento
        ) VALUES (
            :orden, :tarea, :cmm, :desc_maq,
            :grupo, :desc_grupo, :per, :desc_t, COALESCE(:activa,'A'),
            :ult, :prox,
            'ALTA', NULL, NULL, 'Preventivo'
        )
        ON CONFLICT (orden, tarea) DO UPDATE SET
            cod_maquina_mant = EXCLUDED.cod_maquina_mant,
            desc_maquina     = EXCLUDED.desc_maquina,
            grupo            = EXCLUDED.grupo,
            desc_grupo       = EXCLUDED.desc_grupo,
            periodicidad     = EXCLUDED.periodicidad,
            desc_tarea       = EXCLUDED.desc_tarea,
            ultima_revision  = COALESCE(EXCLUDED.ultima_revision,  mant_plan.ultima_revision),
            proxima_revision = COALESCE(EXCLUDED.proxima_revision, mant_plan.proxima_revision)
    ");
    $today = date('Y-m-d');
    $cntPlan = 0; $sinFutura = 0;
    foreach ($tareas as $t) {
        $orden = (string)($t['orden'] ?? '');
        $tarea = (string)($t['tarea'] ?? '');
        if ($orden === '' || $tarea === '') continue;
        $cmm = (string)($t['cod_maquina_mant'] ?? '');
        $per = (string)($t['periodicidad'] ?? '');
        $fpo = (string)($t['fecha_proxima_original'] ?? '');
        $fi  = (string)($t['fecha_intervencion'] ?? '');

        $ultima  = $fi !== '' ? $fi : null;
        // proxima_revision: si fpo es futura, usarla. Si pasada, sumar 1 ciclo.
        $proxima = null;
        if ($fpo !== '') {
            if ($fpo >= $today) {
                $proxima = $fpo;
            } else {
                $proxima = calcProx($fpo, $per) ?? null;
                // Si tras +1 ciclo sigue en pasado, seguimos sumando hasta llegar al hoy
                $iter = 0;
                while ($proxima !== null && $proxima < $today && $iter < 240) {
                    $proxima = calcProx($proxima, $per);
                    $iter++;
                }
            }
        }
        if ($proxima === null) $sinFutura++;

        $stUpsertPlan->execute([
            ':orden'      => $orden,
            ':tarea'      => $tarea,
            ':cmm'        => $cmm,
            ':desc_maq'   => trim((string)($t['desc_maquina'] ?? $cmm)),
            ':grupo'      => trim((string)($t['grupo'] ?? '')) !== '' ? trim((string)$t['grupo']) : null,
            ':desc_grupo' => trim((string)($t['desc_grupo'] ?? '')) !== '' ? trim((string)$t['desc_grupo']) : null,
            ':per'        => trim((string)$per) !== '' ? trim((string)$per) : null,
            ':desc_t'     => trim((string)($t['desc_tarea'] ?? '')) !== '' ? trim((string)$t['desc_tarea']) : null,
            ':activa'     => trim((string)($t['activa'] ?? 'A')) !== '' ? strtoupper(trim((string)$t['activa'])) : null,
            ':ult'        => $ultima,
            ':prox'       => $proxima,
        ]);
        $cntPlan++;
    }

    // 3.c) Insert completions desde JSON (ON CONFLICT DO NOTHING)
    // Nota: bindeamos recuperada como string 'true'/'false' y casteamos en SQL,
    // porque PDO_pgsql con PHP bool puede convertir a '' (cadena vacia) y Postgres
    // lo rechaza para columnas BOOLEAN.
    $stInsCompl = $pdo->prepare("
        INSERT INTO mant_completions (
            external_id, tipo, orden, tarea, cod_maquina_mant, desc_maquina,
            grupo, desc_grupo, periodicidad, desc_tarea, activa,
            fecha_proxima_original, fecha_intervencion, operario,
            observaciones, motivo_no_realizada, recuperada, recuperada_fecha,
            marcada_por
        ) VALUES (
            :ext, :tipo, :orden, :tarea, :cmm, :desc_maq,
            :grupo, :desc_grupo, :per, :desc_t, COALESCE(:activa,'A'),
            :fpo, :fi, :op,
            :obs, :motivo, (:rec)::boolean, :rec_fecha,
            'recover_secuencia'
        )
        ON CONFLICT (external_id) DO NOTHING
    ");
    $cntCompl = 0;
    foreach ($itemsSec as $it) {
        $extId = (string)($it['id'] ?? '');
        if ($extId === '') {
            $extId = ($it['orden'] ?? '') . '|' . ($it['tarea'] ?? '') . '|' .
                     ($it['fecha_proxima_original'] ?? $it['fecha_intervencion'] ?? '');
        }
        $tipo = strtolower(trim((string)($it['tipo'] ?? 'completada')));
        if (!in_array($tipo, ['completada','no_realizada','recuperacion'], true)) $tipo = 'completada';

        // Validar coherencia segun el CHECK constraint de mant_completions
        $fpo = $it['fecha_proxima_original'] ?? null;
        $fi  = $it['fecha_intervencion']     ?? null;
        if ($tipo === 'completada' && (!$fpo || !$fi)) continue;
        if ($tipo === 'no_realizada' && (!$fpo || $fi)) continue;
        if ($tipo === 'recuperacion' && !$fi) continue;

        $stInsCompl->execute([
            ':ext'       => $extId,
            ':tipo'      => $tipo,
            ':orden'     => (string)($it['orden'] ?? ''),
            ':tarea'     => (string)($it['tarea'] ?? ''),
            ':cmm'       => trim((string)($it['cod_maquina_mant'] ?? '')) ?: null,
            ':desc_maq'  => trim((string)($it['desc_maquina'] ?? '')) ?: null,
            ':grupo'     => trim((string)($it['grupo'] ?? '')) ?: null,
            ':desc_grupo'=> trim((string)($it['desc_grupo'] ?? '')) ?: null,
            ':per'       => trim((string)($it['periodicidad'] ?? '')) ?: null,
            ':desc_t'    => trim((string)($it['desc_tarea'] ?? '')) ?: null,
            ':activa'    => trim((string)($it['activa'] ?? '')) ?: null,
            ':fpo'       => $fpo ?: null,
            ':fi'        => $fi  ?: null,
            ':op'        => trim((string)($it['operario'] ?? '')) ?: null,
            ':obs'       => trim((string)($it['observaciones'] ?? '')) ?: null,
            ':motivo'    => trim((string)($it['motivo_no_realizada'] ?? '')) ?: null,
            ':rec'       => !empty($it['recuperada']) ? 'true' : 'false',
            ':rec_fecha' => $it['recuperada_fecha'] ?? null,
        ]);
        $cntCompl += $stInsCompl->rowCount();
    }

    // Stats despues
    $b1 = (int)(Db::pgFetchOne("SELECT COUNT(*) c FROM mant_maquinas")['c'] ?? 0);
    $b2 = (int)(Db::pgFetchOne("SELECT COUNT(*) c FROM mant_plan")['c'] ?? 0);
    $b3 = (int)(Db::pgFetchOne("SELECT COUNT(*) c FROM mant_completions")['c'] ?? 0);

    echo "UPSERT realizados:\n";
    echo "  · mant_maquinas      ejecuciones : {$cntMaq}    | filas {$a1} -> {$b1} (+" . ($b1 - $a1) . ")\n";
    echo "  · mant_plan          ejecuciones : {$cntPlan}   | filas {$a2} -> {$b2} (+" . ($b2 - $a2) . ")\n";
    echo "  · mant_completions   nuevas      : {$cntCompl}  | filas {$a3} -> {$b3} (+" . ($b3 - $a3) . ")\n";
    if ($sinFutura > 0) echo "  · tareas sin proxima_revision calculable: {$sinFutura}\n";

    if ($COMMIT) {
        $pdo->commit();
        echo "\n✓✓✓ COMMIT realizado.\n";
    } else {
        $pdo->rollBack();
        echo "\n↺ ROLLBACK (modo dry-run). Para confirmar lanza ?commit=1.\n";
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\n✗ ERROR (rollback automatico): " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(2);
}

echo "=== FIN ===\n";
