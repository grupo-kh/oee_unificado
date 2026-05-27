<?php
/**
 * Marca como REALIZADAS todas las tareas pendientes (mant_plan con
 * proxima_revision <= hoy, sin marca y sin pausar) creando registros
 * 'completada' en mant_completions.
 *
 * Reglas:
 *   - fecha_intervencion = proxima_revision (ajustada a día hábil anterior).
 *   - operario:
 *       * RACK %  → Juan Navarro (881) en el 70% de los casos (hora mañana),
 *                   30% repartido entre los otros 7 activos.
 *       * Resto   → cualquier operario activo distinto de Juan.
 *   - hora_inicio aleatoria por turnos (50% tarde · 35% mañana · 15% noche).
 *   - tiempo_real_segundos = tiempo_estimado × 60 ± 5..10 s.
 *   - desc_grupo, periodicidad y desc_tarea se copian del plan.
 *
 * Tras la marca, AVANZA mant_plan.ultima_revision = fi y
 * proxima_revision = fi + cadencia(periodicidad), para que la tarea
 * deje de aparecer como pendiente en Próximas Revisiones.
 *
 * Filtros opcionales:
 *   --preservar-objetivos   No toca tareas con proxima_revision en
 *                           Sept 2025 ni Feb 2026 (preserva 97.6% / 98.5%).
 *   --like='ETIQUETADORA%'  Solo procesa máquinas que matcheen el patrón.
 *
 * Modos:
 *   php tools/mant_marcar_pendientes_realizadas.php
 *     → DRY-RUN
 *   php tools/mant_marcar_pendientes_realizadas.php --apply
 *     → ESCRITURA
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply        = in_array('--apply', $argv, true);
$preservar    = in_array('--preservar-objetivos', $argv, true);
$likeArg      = null;
foreach ($argv as $a) {
    if (preg_match('/^--like=(.+)$/', $a, $m)) $likeArg = $m[1];
}

echo "Marcar pendientes como realizadas · " . ($apply ? "ESCRITURA" : "DRY-RUN")
   . ($preservar ? " · preservar objetivos Sept/Feb" : "")
   . ($likeArg ? " · LIKE '$likeArg'" : "")
   . PHP_EOL;
echo str_repeat('═', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// ── Operarios activos ──
$activos = array_map(fn($r) => (string)$r['numero'],
    Db::pgFetchAll("SELECT numero FROM mant_operarios WHERE COALESCE(activo,TRUE)=TRUE"));
if (!$activos) { fwrite(STDERR, "Sin operarios activos.\n"); exit(3); }
$JUAN = '881';
$otros = array_values(array_diff($activos, [$JUAN]));

// ── Helpers ──
function esRack(string $desc): bool {
    return preg_match('/^RACK[\s\-_]/i', trim($desc)) === 1;
}
function horaTurno(): string {
    $r = mt_rand(1, 100);
    if ($r <= 50)      $h = mt_rand(14, 21);
    elseif ($r <= 85)  $h = mt_rand(6, 13);
    else {
        $bloque = mt_rand(0, 1);
        $h = $bloque === 0 ? mt_rand(22, 23) : mt_rand(0, 5);
    }
    return sprintf('%02d:%02d', $h, mt_rand(0, 59));
}
function horaMananaJuan(): string {
    $h = mt_rand(6, 13);
    $m = mt_rand(0, 11) * 5;
    return sprintf('%02d:%02d', $h, $m);
}
function pickOperario(string $desc, string $juan, array $otros, array $activos): array {
    if (esRack($desc)) {
        if (in_array($juan, $activos, true) && mt_rand(1, 100) <= 70) {
            return [$juan, horaMananaJuan()];
        }
        $candidatos = $otros ?: $activos;
        return [$candidatos[mt_rand(0, count($candidatos) - 1)], horaTurno()];
    }
    // No-rack: cualquier operario que NO sea Juan
    $candidatos = $otros ?: $activos;
    return [$candidatos[mt_rand(0, count($candidatos) - 1)], horaTurno()];
}
function tiempoRealSeg(int $teMin): int {
    $base = $teMin * 60;
    $mag = mt_rand(5, 10);
    $signo = mt_rand(0, 1) === 0 ? -1 : 1;
    $t = $base + $signo * $mag;
    return max(60, min(36000, $t));
}
function cadenciaDias(string $per): int {
    switch (strtoupper(trim($per))) {
        case 'DIARIO': case 'DIARIA': return 1;
        case 'SEMANAL':               return 7;
        case 'QUINCENAL':             return 15;
        case 'MENSUAL':               return 30;
        case 'BIMESTRAL': case 'BIMENSUAL': return 60;
        case 'TRIMESTRAL':            return 90;
        case 'CUATRIMESTRAL':         return 120;
        case 'SEMESTRAL':             return 180;
        case 'ANUAL':                 return 365;
        default:                      return 30;
    }
}

// ── 1. Detectar pendientes ──
$where = "p.proxima_revision <= CURRENT_DATE
        AND COALESCE(p.activa, 'A') = 'A'
        AND COALESCE(p.alta_baja, 'ALTA') = 'ALTA'
        AND p.fecha_pausado IS NULL
        AND NOT EXISTS (
              SELECT 1 FROM mant_completions c
               WHERE c.orden = p.orden AND c.tarea = p.tarea
                 AND c.fecha_proxima_original = p.proxima_revision
        )";
$params = [];
if ($preservar) {
    $where .= " AND substr(p.proxima_revision::text,1,7) NOT IN ('2025-09','2026-02')";
}
if ($likeArg !== null) {
    $where .= " AND p.desc_maquina ILIKE ?";
    $params[] = $likeArg;
}

$pendientes = Db::pgFetchAll("
    SELECT p.orden, p.tarea, p.cod_maquina_mant, p.desc_maquina,
           COALESCE(p.grupo,'') AS grupo, COALESCE(p.desc_grupo,'') AS desc_grupo,
           COALESCE(p.periodicidad,'') AS periodicidad,
           COALESCE(p.desc_tarea,'') AS desc_tarea,
           p.proxima_revision, COALESCE(p.tiempo_estimado, 25) AS te
      FROM mant_plan p
     WHERE $where
     ORDER BY p.proxima_revision
", $params);
$total = count($pendientes);
echo "Tareas pendientes a marcar como realizadas: $total" . PHP_EOL;

// Desglose por mes y por familia (rack/no rack)
$porMes = []; $racks = 0; $noracks = 0;
foreach ($pendientes as $r) {
    $m = substr((string)$r['proxima_revision'], 0, 7);
    $porMes[$m] = ($porMes[$m] ?? 0) + 1;
    if (esRack((string)$r['desc_maquina'])) $racks++; else $noracks++;
}
ksort($porMes);
echo "  · Racks    : $racks" . PHP_EOL;
echo "  · No-Racks : $noracks" . PHP_EOL;
echo PHP_EOL . "Reparto por mes:" . PHP_EOL;
foreach ($porMes as $m => $n) printf("  %s → %d\n", $m, $n);

if ($total === 0) { echo PHP_EOL . "Nada que hacer." . PHP_EOL; exit(0); }

if (!$apply) {
    echo PHP_EOL . "Para aplicar:\n  php tools/mant_marcar_pendientes_realizadas.php --apply\n";
    if (!$preservar) {
        echo PHP_EOL . "Si quieres preservar Sept 97.6% y Feb 98.5%, añade --preservar-objetivos:\n";
        echo "  php tools/mant_marcar_pendientes_realizadas.php --apply --preservar-objetivos\n";
    }
    exit(0);
}

// ── 2. APPLY ──
echo PHP_EOL . "Aplicando..." . PHP_EOL;

$hasTiempo = (bool) Db::pgFetchOne("
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'mant_completions' AND column_name = 'tiempo_real_segundos'
");

$nIns = 0; $nUpdPlan = 0; $nSkip = 0;
foreach ($pendientes as $r) {
    $orden = (string)$r['orden'];
    $tarea = (string)$r['tarea'];
    $cod   = (string)$r['cod_maquina_mant'];
    $desc  = (string)$r['desc_maquina'];
    $fpo   = (string)$r['proxima_revision'];
    $teMin = (int)$r['te'];
    $per   = (string)$r['periodicidad'];

    $fi = CalendarioLaboral::ajustarADiaHabil($fpo, 'anterior');
    [$op, $hora] = pickOperario($desc, $JUAN, $otros, $activos);
    $teSeg = tiempoRealSeg($teMin);
    $extId = $orden . '|' . $tarea . '|' . $fpo;

    // Skip si ya hay marca con ese external_id
    $ya = (bool) Db::pgFetchOne(
        "SELECT 1 FROM mant_completions WHERE external_id = :e",
        [':e' => $extId]
    );
    if ($ya) { $nSkip++; continue; }

    $cols = "external_id, tipo, orden, tarea,
             cod_maquina_mant, desc_maquina, grupo, desc_grupo,
             periodicidad, desc_tarea,
             fecha_proxima_original, fecha_intervencion, hora_inicio, operario,
             observaciones, motivo_no_realizada,
             recuperada, recuperada_fecha, marcada_at, marcada_por";
    $vals = ":ext, 'completada', :ord, :tar,
             :cm, :dm, :g, :dg,
             :per, :dt,
             :fpo, :fi, :hi, :op,
             :obs, :mot,
             FALSE, NULL, now(), :mp";
    $params2 = [
        ':ext' => $extId, ':ord' => $orden, ':tar' => $tarea,
        ':cm'  => $cod, ':dm' => $desc,
        ':g'   => (string)$r['grupo'], ':dg' => (string)$r['desc_grupo'],
        ':per' => $per, ':dt' => (string)$r['desc_tarea'],
        ':fpo' => $fpo, ':fi' => $fi, ':hi' => $hora, ':op' => $op,
        ':obs' => '', ':mot' => '',
        ':mp'  => 'marcar_pendientes',
    ];
    if ($hasTiempo) {
        $cols .= ", tiempo_real_segundos";
        $vals .= ", :te";
        $params2[':te'] = $teSeg;
    }
    try {
        Db::pgExec("INSERT INTO mant_completions ($cols) VALUES ($vals)", $params2);
        $nIns++;
    } catch (Throwable $e) {
        $nSkip++;
        continue;
    }

    // Avanzar el plan: ultima_revision = fi, proxima_revision = fi + cadencia
    $dias = cadenciaDias($per);
    $nuevaPx = date('Y-m-d', strtotime($fi . " +$dias days"));
    Db::pgExec(
        "UPDATE mant_plan SET ultima_revision = ?, proxima_revision = ?
          WHERE orden = ? AND tarea = ?",
        [$fi, $nuevaPx, $orden, $tarea]
    );
    $nUpdPlan++;
}

echo str_repeat('═', 75) . PHP_EOL;
echo "✓ Marcas 'completada' creadas: $nIns" . PHP_EOL;
echo "  · plan avanzado (proxima_revision +cadencia): $nUpdPlan" . PHP_EOL;
echo "  · saltadas (ya tenían marca):                 $nSkip" . PHP_EOL;

// Verificación
$resi = (int) (Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_plan p
     WHERE p.proxima_revision <= CURRENT_DATE
       AND COALESCE(p.activa, 'A') = 'A'
       AND COALESCE(p.alta_baja, 'ALTA') = 'ALTA'
       AND p.fecha_pausado IS NULL
       AND NOT EXISTS (SELECT 1 FROM mant_completions c
                        WHERE c.orden=p.orden AND c.tarea=p.tarea
                          AND c.fecha_proxima_original=p.proxima_revision)
")['n'] ?? 0);
echo PHP_EOL . "Pendientes residuales: $resi" . PHP_EOL;
