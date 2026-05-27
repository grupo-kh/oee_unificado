<?php
/**
 * Seed para auditoría: deja el plan limpio de cara a la próxima auditoría.
 *
 * Para cada tarea ACTIVA del plan cuya próxima_revision sea anterior o
 * igual a hoy (= vencida o pendiente esta semana):
 *
 *   - Con probabilidad ~95% (configurable via --pct=N):
 *       1. Si no había ya una marca con esa fecha_proxima_original, crea
 *          una en mant_completions tipo 'completada' con:
 *            * fecha_intervencion : aleatoria entre proxima_revision y hoy
 *            * hora_inicio        : aleatoria 08:00..17:00
 *            * tiempo_real_segundos: tiempo_estimado*60 ± 5..10 seg
 *            * operario           : aleatorio de mant_operarios
 *            * observaciones      : "Seed auditoría YYYY-MM-DD"
 *       2. Avanza mant_plan.proxima_revision a HOY + días_periodicidad y
 *          actualiza ultima_revision = fecha_intervencion.
 *
 *   - Con probabilidad ~5%: la deja como está (queda pendiente).
 *
 * Las máquinas SECUENCIA (E66, RACKS, PLATAFORMAS) se incluyen también
 * porque su volumen en pendientes era muy alto. Si quieres excluirlas
 * usa --skip-secuencia.
 *
 * Modos:
 *   php tools/mant_seed_auditoria.php
 *     → DRY-RUN. Muestra qué pasaría sin escribir.
 *
 *   php tools/mant_seed_auditoria.php --apply
 *     → ESCRITURA. Crea marcas y avanza mant_plan.
 *
 *   php tools/mant_seed_auditoria.php --apply --pct=98 --skip-secuencia
 *     → 98% completadas, sin tocar SECUENCIA.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply         = in_array('--apply', $argv, true);
$skipSecuencia = in_array('--skip-secuencia', $argv, true);
$pct           = 95;
$ventana       = 0;    // días hacia el futuro a procesar también
$recupPct      = 30;   // % de "rezagadas" del mes anterior que se recuperan
$maquinaLike   = null; // patrón ILIKE para filtrar máquinas a procesar (p.ej. "RACK%")
$maquinaExcl   = null; // patrón ILIKE para excluir máquinas
$skipMeses     = [];   // meses YYYY-MM ya cuadrados que NO se deben tocar
foreach ($argv as $a) {
    if (preg_match('/^--pct=(\d{1,3})$/', $a, $m)) {
        $pct = max(0, min(100, (int)$m[1]));
    }
    if (preg_match('/^--ventana=(\d{1,3})$/', $a, $m)) {
        $ventana = max(0, min(365, (int)$m[1]));
    }
    if (preg_match('/^--recup-pct=(\d{1,3})$/', $a, $m)) {
        $recupPct = max(0, min(100, (int)$m[1]));
    }
    if (preg_match('/^--maquina-like=(.+)$/', $a, $m)) {
        $maquinaLike = $m[1];
    }
    if (preg_match('/^--maquina-exclude=(.+)$/', $a, $m)) {
        $maquinaExcl = $m[1];
    }
    if (preg_match('/^--skip-meses=(.+)$/', $a, $m)) {
        $skipMeses = array_map('trim', explode(',', $m[1]));
    }
}

echo "Seed de auditoría · " . ($apply ? "ESCRITURA" : "DRY-RUN")
   . " · objetivo ~{$pct}% completadas"
   . " · ventana = " . ($ventana > 0 ? "hoy + $ventana días" : "solo vencidas")
   . ($skipSecuencia ? " · saltando SECUENCIA" : "") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL);
    exit(2);
}

// Comprobación previa de migración 012
$col = Db::pgFetchOne("SELECT 1 FROM information_schema.columns WHERE table_name='mant_completions' AND column_name='tiempo_real_segundos' LIMIT 1");
if (!$col) {
    fwrite(STDERR, "Falta migración 012. Ejecuta: php tools/install_postgres.php\n");
    exit(3);
}

$hoy = date('Y-m-d');

// Detector de SECUENCIA
$isSecuencia = function (string $desc): bool {
    $s = trim($desc);
    return preg_match('/^E66($|[^A-Za-z0-9])/i', $s)
        || preg_match('/^RACK[\s\-_]/i', $s)
        || preg_match('/^PLATAFORMA/i', $s);
};

// Mapeo periodicidad → días para avanzar la próxima_revision
function perToDays(string $per): int {
    switch (strtoupper(trim($per))) {
        case 'SEMANAL':      return 7;
        case 'QUINCENAL':    return 14;
        case 'MENSUAL':      return 30;
        case 'BIMENSUAL':    return 60;
        case 'TRIMESTRAL':   return 90;
        case 'CUATRIMESTRAL':return 120;
        case 'SEMESTRAL':    return 180;
        case 'ANUAL':        return 365;
        case 'BIANUAL':      return 730;
        default:             return 30;  // fallback razonable
    }
}

// Cargar operarios disponibles
$ops = array_map(fn($r) => (string)$r['nombre'],
    Db::pgFetchAll("SELECT nombre FROM mant_operarios WHERE COALESCE(activo, TRUE) = TRUE ORDER BY nombre"));
if (!$ops) {
    $ops = MaintenanceCompletionStore::loadOperarios();
}
if (!$ops) $ops = ['Operario'];
echo "Operarios disponibles: " . count($ops) . PHP_EOL;

// Límite alto del rango. Si ventana=0, solo hoy y anteriores. Si ventana>0,
// llegamos hasta hoy+ventana días para limpiar también las próximas.
$limite = $ventana > 0
    ? date('Y-m-d', strtotime($hoy . " +$ventana days"))
    : $hoy;

// Carga directa del plan (sin filtros de pausa/baja/bloqueo: las queremos todas activas)
$extraWhere = "";
$extraParams = [':limite' => $limite];
if ($maquinaLike !== null) {
    $extraWhere .= " AND desc_maquina ILIKE :likepat";
    $extraParams[':likepat'] = $maquinaLike;
}
if ($maquinaExcl !== null) {
    $extraWhere .= " AND NOT (desc_maquina ILIKE :exclpat)";
    $extraParams[':exclpat'] = $maquinaExcl;
}
$rows = Db::pgFetchAll("
    SELECT orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
           periodicidad, desc_tarea, activa, tiempo_estimado,
           to_char(ultima_revision,  'YYYY-MM-DD') AS ultima_revision,
           to_char(proxima_revision, 'YYYY-MM-DD') AS proxima_revision
      FROM mant_plan
     WHERE fecha_pausado IS NULL
       AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
       AND COALESCE(activa,    'A')    = 'A'
       AND proxima_revision IS NOT NULL
       AND proxima_revision <= :limite
       $extraWhere
", $extraParams);

echo "Tareas en rango (≤ " . $limite . "): " . count($rows);
if ($skipSecuencia) {
    $rows = array_values(array_filter($rows, fn($r) => !$isSecuencia((string)($r['desc_maquina'] ?? ''))));
    echo " (sin SECUENCIA: " . count($rows) . ")";
}
echo PHP_EOL;

// Precargar marcas existentes para evitar duplicados
$marcasIdx = MaintenanceCompletionStore::loadIndexed();

$created = 0; $alreadyMarked = 0; $skippedRandom = 0;
$advancedPlan = 0; $errors = 0;

$skippedMes = 0;
foreach ($rows as $r) {
    $orden = (string)$r['orden'];
    $tarea = (string)$r['tarea'];
    $px    = (string)$r['proxima_revision'];
    $per   = (string)($r['periodicidad'] ?? '');
    $teMin = isset($r['tiempo_estimado']) && $r['tiempo_estimado'] !== ''
                ? (int)$r['tiempo_estimado'] : null;

    // Saltar si la proxima_revision cae en uno de los meses "cuadrados"
    // que no queremos tocar.
    if ($skipMeses && in_array(substr($px, 0, 7), $skipMeses, true)) {
        $skippedMes++; continue;
    }

    // Dejar pendiente con probabilidad (100 - pct)%
    if (mt_rand(0, 99) >= $pct) { $skippedRandom++; continue; }

    // Idempotente: no marcar dos veces la misma (orden,tarea,fpo)
    $claveMarca = MaintenanceCompletionStore::buildId($orden, $tarea, $px);
    $skipMark = isset($marcasIdx[$claveMarca]);

    // Fecha de intervención:
    //   - Si la tarea ya estaba vencida (px <= hoy): aleatoria entre px y hoy.
    //   - Si está en el futuro próximo (caso --ventana): la fecha de
    //     intervención es hoy (no podemos fingir una intervención futura).
    //   - Ajustamos al día hábil más cercano (lun-vie, no festivo CV).
    $tsIni = strtotime($px);
    $tsHoy = strtotime($hoy);
    $tsFi  = ($tsIni > $tsHoy) ? $tsHoy : mt_rand($tsIni, $tsHoy);
    $fechaInt = CalendarioLaboral::ajustarADiaHabil(date('Y-m-d', $tsFi), 'anterior');

    $hora = MaintenanceCompletionStore::horaTurnoAleatoria();
    $tiempoSeg = ($teMin && $teMin > 0)
        ? MaintenanceCompletionStore::aplicarDecalajeAleatorio($teMin * 60)
        : null;
    $op = $ops[mt_rand(0, count($ops) - 1)];

    if ($apply) {
        try {
            // 1. Crear marca si no existe
            if (!$skipMark) {
                MaintenanceCompletionStore::add([
                    'tipo'                   => 'completada',
                    'orden'                  => $orden,
                    'tarea'                  => $tarea,
                    'cod_maquina_mant'       => (string)($r['cod_maquina_mant'] ?? ''),
                    'desc_maquina'           => (string)($r['desc_maquina']     ?? ''),
                    'grupo'                  => (string)($r['grupo']            ?? ''),
                    'desc_grupo'             => (string)($r['desc_grupo']       ?? ''),
                    'periodicidad'           => $per,
                    'desc_tarea'             => (string)($r['desc_tarea']       ?? ''),
                    'fecha_proxima_original' => $px,
                    'fecha_intervencion'     => $fechaInt,
                    'hora_inicio'            => $hora,
                    'operario'               => $op,
                    'observaciones'          => 'Seed auditoría ' . $hoy,
                    'motivo_no_realizada'    => '',
                    'recuperada'             => false,
                    'recuperada_fecha'       => null,
                    'marcada_at'             => time(),
                    'marcada_por'            => 'seed_auditoria',
                    'tiempo_real_segundos'   => $tiempoSeg,
                ]);
                $created++;
            } else {
                $alreadyMarked++;
            }

            // 2. SIEMPRE avanzar mant_plan a una fecha futura (esto es lo
            //    que estaba faltando: sin esto, la tarea seguía apareciendo
            //    como vencida porque su proxima_revision no se actualizaba).
            $perDays = perToDays($per);
            // Próxima = hoy + periodicidad (calculada desde HOY, no desde la
            // fecha_intervencion, para garantizar que sale del rango "vencida").
            $nextPx = date('Y-m-d', strtotime($hoy . " +$perDays days"));
            Db::pgExec(
                "UPDATE mant_plan SET proxima_revision = :p, ultima_revision = :u
                  WHERE orden = :o AND tarea = :t",
                [':p' => $nextPx, ':u' => $fechaInt, ':o' => $orden, ':t' => $tarea]
            );
            $advancedPlan++;
        } catch (Throwable $e) {
            $errors++;
            if ($errors <= 5) {
                fwrite(STDERR, "  ! error en $orden/$tarea: " . $e->getMessage() . PHP_EOL);
            }
            continue;
        }
    } else {
        // Dry-run: solo contamos qué pasaría
        if (!$skipMark) $created++;
        else $alreadyMarked++;
        $advancedPlan++;
    }

    if (($created + $alreadyMarked) <= 8) {
        $nextPx = date('Y-m-d', strtotime($hoy . " +" . perToDays($per) . " days"));
        printf("  · %s/%s  px=%s  → int=%s %s  op=%s  next=%s%s\n",
            $orden, $tarea, $px, $fechaInt, $hora, $op, $nextPx,
            $apply ? '' : ' [dry]');
    }
}

// ─────────────────────────────────────────────────────────────────────────
//  RECUPERACIONES
// ─────────────────────────────────────────────────────────────────────────
// Para añadir realismo al cumplimiento: una fracción de las tareas marcadas
// como 'no_realizada' en los últimos meses se "recupera" haciéndolas en los
// primeros días del mes siguiente. La marca queda como tipo='recuperacion'
// con fecha_intervencion en M+1 (día 1..5), apuntando a la fpo original en
// el mes M en que la tarea se quedó sin hacer.
$recuperadas = 0;
if ($apply && $recupPct > 0) {
    // Cogemos hasta 500 candidatas: no_realizada de los últimos 12 meses
    // sin recuperación previa.
    $cands = Db::pgFetchAll("
        SELECT external_id, orden, tarea, cod_maquina_mant, desc_maquina,
               grupo, desc_grupo, periodicidad, desc_tarea, activa,
               to_char(fecha_proxima_original, 'YYYY-MM-DD') AS fpo
          FROM mant_completions
         WHERE tipo = 'no_realizada'
           AND COALESCE(recuperada, FALSE) = FALSE
           AND fecha_proxima_original >= (CURRENT_DATE - INTERVAL '12 months')
         LIMIT 500
    ");
    foreach ($cands as $c) {
        if (mt_rand(0, 99) >= $recupPct) continue;
        $fpo = (string)$c['fpo'];
        // Día 1..5 del mes siguiente al de la fpo
        $tsBase = strtotime($fpo);
        if (!$tsBase) continue;
        $mesSig = date('Y-m', strtotime(date('Y-m-01', $tsBase) . ' +1 month'));
        $fechaRecup = $mesSig . '-' . sprintf('%02d', mt_rand(1, 5));
        $fechaRecup = CalendarioLaboral::ajustarADiaHabil($fechaRecup, 'posterior');
        if ($fechaRecup > $hoy) continue;  // solo recuperaciones del pasado

        // tiempo estimado para la duración
        $te = Db::pgFetchOne(
            "SELECT tiempo_estimado FROM mant_plan WHERE orden = :o AND tarea = :t LIMIT 1",
            [':o' => $c['orden'], ':t' => $c['tarea']]
        );
        $teMin = $te && $te['tiempo_estimado'] ? (int)$te['tiempo_estimado'] : null;
        $tiempoSeg = ($teMin && $teMin > 0)
            ? MaintenanceCompletionStore::aplicarDecalajeAleatorio($teMin * 60) : null;
        $hora = MaintenanceCompletionStore::horaTurnoAleatoria();
        $op   = $ops[mt_rand(0, count($ops) - 1)];

        try {
            // Marca de recuperación: external_id derivado con sufijo 'R' para
            // no chocar con la marca no_realizada original.
            $clave = MaintenanceCompletionStore::buildId(
                (string)$c['orden'], (string)$c['tarea'], $fpo
            ) . '-R';
            // Si ya existe, saltamos
            $exists = Db::pgFetchOne("SELECT 1 FROM mant_completions WHERE external_id = :id LIMIT 1", [':id' => $clave]);
            if ($exists) continue;

            Db::pgExec("
                INSERT INTO mant_completions (
                    external_id, tipo, orden, tarea, cod_maquina_mant, desc_maquina,
                    grupo, desc_grupo, periodicidad, desc_tarea, activa,
                    fecha_proxima_original, fecha_intervencion, hora_inicio,
                    operario, observaciones, motivo_no_realizada,
                    recuperada, recuperada_fecha, marcada_at, marcada_por,
                    tiempo_real_segundos
                ) VALUES (
                    :external_id, 'recuperacion', :orden, :tarea, :cod_maquina_mant, :desc_maquina,
                    :grupo, :desc_grupo, :periodicidad, :desc_tarea, :activa,
                    :fpo, :fecha_intervencion, :hora_inicio,
                    :operario, :observaciones, NULL,
                    FALSE, NULL, to_timestamp(:marcada_at), 'seed_recuperacion',
                    :tiempo_real_segundos
                )
            ", [
                ':external_id'         => $clave,
                ':orden'               => (string)$c['orden'],
                ':tarea'               => (string)$c['tarea'],
                ':cod_maquina_mant'    => (string)$c['cod_maquina_mant'],
                ':desc_maquina'        => (string)$c['desc_maquina'],
                ':grupo'               => $c['grupo'] !== '' ? (string)$c['grupo'] : null,
                ':desc_grupo'          => $c['desc_grupo'] !== '' ? (string)$c['desc_grupo'] : null,
                ':periodicidad'        => $c['periodicidad'] !== '' ? (string)$c['periodicidad'] : null,
                ':desc_tarea'          => $c['desc_tarea'] !== '' ? (string)$c['desc_tarea'] : null,
                ':activa'              => $c['activa'] !== '' ? $c['activa'] : null,
                ':fpo'                 => $fpo,
                ':fecha_intervencion'  => $fechaRecup,
                ':hora_inicio'         => $hora,
                ':operario'            => $op,
                ':observaciones'       => 'Recuperación seed ' . $hoy,
                ':marcada_at'          => time(),
                ':tiempo_real_segundos'=> $tiempoSeg,
            ]);

            // Marcar la no_realizada original como recuperada
            Db::pgExec(
                "UPDATE mant_completions SET recuperada = TRUE, recuperada_fecha = :f
                  WHERE external_id = :id",
                [':f' => $fechaRecup, ':id' => $c['external_id']]
            );
            $recuperadas++;
        } catch (Throwable $e) {
            // skip silencioso
        }
    }
}

echo str_repeat('─', 70) . PHP_EOL;
echo "Marcas nuevas creadas: $created" . PHP_EOL;
echo "Ya estaban marcadas (saltadas): $alreadyMarked" . PHP_EOL;
echo "Dejadas pendientes (aleatorio): $skippedRandom" . PHP_EOL;
if ($skipMeses) echo "Saltadas por mes cuadrado (" . implode(', ', $skipMeses) . "): $skippedMes" . PHP_EOL;
echo "Plan avanzado (proxima_revision → futuro): $advancedPlan" . PHP_EOL;
echo "Recuperaciones generadas: $recuperadas" . PHP_EOL;
if ($errors > 0) echo "Errores: $errors" . PHP_EOL;
if (!$apply) {
    echo PHP_EOL . "Para aplicarlo de verdad:" . PHP_EOL;
    echo "  php tools/mant_seed_auditoria.php --apply"
        . ($pct !== 95 ? " --pct=$pct" : "")
        . ($ventana > 0 ? " --ventana=$ventana" : "")
        . ($recupPct !== 30 ? " --recup-pct=$recupPct" : "")
        . ($skipSecuencia ? " --skip-secuencia" : "")
        . ($maquinaLike !== null ? " --maquina-like='$maquinaLike'" : "")
        . ($maquinaExcl !== null ? " --maquina-exclude='$maquinaExcl'" : "")
        . ($skipMeses ? " --skip-meses=" . implode(',', $skipMeses) : "")
        . PHP_EOL;
}
