<?php
/**
 * Backfill sintético del histórico de mantenimiento preventivo.
 *
 * Genera intervenciones realistas para cada tarea activa desde una fecha
 * de arranque (default 2025-09-01) hasta hoy, respetando la periodicidad
 * con jitter y aplicando un offset por máquina para que no arranquen todas
 * el mismo día. Asigna un operario aleatorio (de los 8 activos).
 *
 * Para racks/plataformas: todas las sub-tareas de la misma máquina comparten
 * fecha y operario en cada visita (consolidación), con cadencia = la
 * periodicidad más exigente de las tareas de esa máquina.
 *
 * USO:
 *   php tools/mant_backfill_history.php [--commit] [--seed=N] [--start=YYYY-MM-DD]
 *
 *   Sin --commit: dry-run, imprime resumen sin escribir.
 *   --seed: fija la semilla del PRNG para resultados reproducibles.
 *   --start: fecha base de arranque (default 2025-09-01).
 *
 * En commit:
 *   1) DELETE de mant_completions con fecha_intervencion >= start.
 *   2) INSERT de las completions sintéticas (bulk, transaccional).
 *   3) UPDATE de mant_plan.ultima_revision/proxima_revision por tarea.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

// ────────────── Configuración ──────────────
$args = array_slice($argv, 1);
$COMMIT = in_array('--commit', $args, true);
$START_DATE = '2025-09-01';
$TODAY = date('Y-m-d');
$SEED = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--seed=')) $SEED = (int)substr($a, 7);
    if (str_starts_with($a, '--start=')) $START_DATE = substr($a, 8);
}
if ($SEED !== null) mt_srand($SEED);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $START_DATE)) {
    fwrite(STDERR, "Fecha de arranque inválida: $START_DATE\n");
    exit(1);
}

/** Días entre revisiones por periodicidad. */
$PERIODICIDAD_DIAS = [
    'DIARIO'        => 1,
    'SEMANAL'       => 7,
    'QUINCENAL'     => 15,
    'MENSUAL'       => 30,
    'BIMENSUAL'     => 60,
    'BIMESTRAL'     => 60,
    'TRIMESTRAL'    => 90,
    'CUATRIMESTRAL' => 120,
    'SEMESTRAL'     => 180,
    'ANUAL'         => 365,
    'TRIANUAL'      => 1095,
];
/** Jitter en días sobre cada cadencia (mayor periodicidad → mayor margen). */
$PERIODICIDAD_JITTER = [
    'DIARIO'        => 0,
    'SEMANAL'       => 1,
    'QUINCENAL'     => 2,
    'MENSUAL'       => 3,
    'BIMENSUAL'     => 5,
    'BIMESTRAL'     => 5,
    'TRIMESTRAL'    => 7,
    'CUATRIMESTRAL' => 10,
    'SEMESTRAL'     => 14,
    'ANUAL'         => 20,
    'TRIANUAL'      => 30,
];

/** Operarios activos en el catálogo. */
$OPERARIOS = ['1004', '1374', '1593', '1886', '2338', '2417', '2418', '2898'];

echo "═══════════════════════════════════════════════════════════════\n";
echo "  Backfill histórico mantenimiento preventivo\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Modo:     " . ($COMMIT ? 'COMMIT (escribe en BD)' : 'DRY-RUN (solo simula)') . "\n";
echo "  Rango:    $START_DATE → $TODAY\n";
echo "  Seed:     " . ($SEED ?? '(aleatorio)') . "\n";
echo "  Operarios: " . implode(', ', $OPERARIOS) . "\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ────────────── Cargar tareas activas ──────────────
$tareas = Db::pgFetchAll("
    SELECT id, orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
           UPPER(COALESCE(periodicidad,'')) AS periodicidad,
           desc_tarea, activa
      FROM mant_plan
     WHERE COALESCE(alta_baja,'ALTA') = 'ALTA'
       AND COALESCE(activa,    'A')   = 'A'
       AND fecha_pausado IS NULL
       AND NOT (
            fecha_bloqueo_ini IS NOT NULL AND fecha_bloqueo_fin IS NOT NULL
            AND CURRENT_DATE BETWEEN fecha_bloqueo_ini AND fecha_bloqueo_fin
       )
");

// Agrupar por máquina para aplicar offset por máquina y la consolidación
$porMaquina = [];
foreach ($tareas as $t) {
    $cod = (string)$t['cod_maquina_mant'];
    if (!isset($porMaquina[$cod])) {
        $porMaquina[$cod] = ['desc' => $t['desc_maquina'], 'tareas' => []];
    }
    $porMaquina[$cod]['tareas'][] = $t;
}

echo "Tareas activas: " . count($tareas) . " en " . count($porMaquina) . " máquinas\n\n";

// ────────────── Generación de fechas ──────────────
$startBaseTs = strtotime($START_DATE);
$todayTs     = strtotime($TODAY);

/**
 * Genera la secuencia de fechas planificadas (sin jitter) y realizadas (con
 * jitter) desde $startTs hasta hoy, con intervalo $days y desviación $jitter.
 * Devuelve [[planned, actual], …].
 */
function generarFechas(int $startTs, int $todayTs, int $days, int $jitter): array
{
    $out = [];
    $plannedTs = $startTs;
    while ($plannedTs <= $todayTs) {
        $jit = $jitter > 0 ? mt_rand(-$jitter, $jitter) : 0;
        $actualTs = $plannedTs + $jit * 86400;
        // Si por jitter cae más allá de hoy, se omite (la tarea aún no se ha hecho)
        if ($actualTs > $todayTs) {
            $plannedTs += $days * 86400;
            continue;
        }
        // Si cae antes del start global, lo arrastramos a partir de start
        if ($actualTs < strtotime('2025-08-22')) {
            $actualTs = strtotime('2025-08-22');
        }
        $out[] = [date('Y-m-d', $plannedTs), date('Y-m-d', $actualTs)];
        $plannedTs += $days * 86400;
    }
    return $out;
}

/** Hora de inicio aleatoria entre 06:00 y 14:00, minutos en 0/15/30/45. */
function horaAleatoria(): string
{
    $h = mt_rand(6, 13);
    $m = [0, 15, 30, 45][mt_rand(0, 3)];
    return sprintf('%02d:%02d', $h, $m);
}

// ────────────── Plan de inserts ──────────────
$inserts = []; // filas a insertar en mant_completions
$lastByTask = []; // (orden|tarea) → última fecha sintética (ISO)
$stats = ['consolidadas' => 0, 'individuales' => 0, 'visitas_consol' => 0, 'visitas_indiv' => 0];

foreach ($porMaquina as $codMaq => $info) {
    $desc = (string)$info['desc'];
    $tareasMaq = $info['tareas'];

    // Offset por máquina en [-10, +10] días
    $maqOffsetDias = mt_rand(-10, 10);
    $maqStartTs = $startBaseTs + $maqOffsetDias * 86400;

    $isConsol = MaintenancePlanStore::esConsolidable($desc) && count($tareasMaq) > 1;
    if ($isConsol) {
        // Periodicidad más exigente del grupo (intervalo más corto)
        $minDays = null; $minPer = null;
        foreach ($tareasMaq as $t) {
            $p = (string)$t['periodicidad'];
            if (!isset($PERIODICIDAD_DIAS[$p])) continue;
            $d = $PERIODICIDAD_DIAS[$p];
            if ($minDays === null || $d < $minDays) { $minDays = $d; $minPer = $p; }
        }
        if ($minDays === null) {
            // No hay periodicidad útil entre las sub-tareas → omitimos esta máquina
            continue;
        }
        $jitter = $PERIODICIDAD_JITTER[$minPer] ?? 0;

        $fechas = generarFechas($maqStartTs, $todayTs, $minDays, $jitter);
        $stats['consolidadas']++;
        $stats['visitas_consol'] += count($fechas);

        foreach ($fechas as [$planned, $actual]) {
            // MISMO operario y MISMA hora para todas las sub-tareas (es una visita)
            $operario = $OPERARIOS[mt_rand(0, count($OPERARIOS) - 1)];
            $hora     = horaAleatoria();
            foreach ($tareasMaq as $t) {
                $orden = (string)$t['orden'];
                $tarea = (string)$t['tarea'];
                $inserts[] = [
                    'external_id'            => $orden . '|' . $tarea . '|' . $planned,
                    'tipo'                   => 'completada',
                    'orden'                  => $orden,
                    'tarea'                  => $tarea,
                    'cod_maquina_mant'       => $codMaq,
                    'desc_maquina'           => $desc,
                    'grupo'                  => $t['grupo'] ?: null,
                    'desc_grupo'             => $t['desc_grupo'] ?: null,
                    'periodicidad'           => $t['periodicidad'] ?: null,
                    'desc_tarea'             => $t['desc_tarea'] ?: null,
                    'activa'                 => $t['activa'] ?: 'A',
                    'fecha_proxima_original' => $planned,
                    'fecha_intervencion'     => $actual,
                    'hora_inicio'            => $hora,
                    'operario'               => $operario,
                    'observaciones'          => null,
                    'motivo_no_realizada'    => null,
                    'recuperada'             => false,
                    'recuperada_fecha'       => null,
                    'marcada_por'            => 'synthetic_backfill',
                ];
                $k = $orden . '|' . $tarea;
                if (!isset($lastByTask[$k]) || $actual > $lastByTask[$k]['fecha']) {
                    $lastByTask[$k] = ['fecha' => $actual, 'per' => $t['periodicidad']];
                }
            }
        }
    } else {
        // Cada tarea independiente, comparte el start de máquina
        foreach ($tareasMaq as $t) {
            $p = (string)$t['periodicidad'];
            if (!isset($PERIODICIDAD_DIAS[$p])) continue;
            $d = $PERIODICIDAD_DIAS[$p];
            $jitter = $PERIODICIDAD_JITTER[$p] ?? 0;
            $fechas = generarFechas($maqStartTs, $todayTs, $d, $jitter);
            $stats['individuales']++;
            $stats['visitas_indiv'] += count($fechas);

            $orden = (string)$t['orden'];
            $tarea = (string)$t['tarea'];
            foreach ($fechas as [$planned, $actual]) {
                $operario = $OPERARIOS[mt_rand(0, count($OPERARIOS) - 1)];
                $inserts[] = [
                    'external_id'            => $orden . '|' . $tarea . '|' . $planned,
                    'tipo'                   => 'completada',
                    'orden'                  => $orden,
                    'tarea'                  => $tarea,
                    'cod_maquina_mant'       => $codMaq,
                    'desc_maquina'           => $desc,
                    'grupo'                  => $t['grupo'] ?: null,
                    'desc_grupo'             => $t['desc_grupo'] ?: null,
                    'periodicidad'           => $p ?: null,
                    'desc_tarea'             => $t['desc_tarea'] ?: null,
                    'activa'                 => $t['activa'] ?: 'A',
                    'fecha_proxima_original' => $planned,
                    'fecha_intervencion'     => $actual,
                    'hora_inicio'            => horaAleatoria(),
                    'operario'               => $operario,
                    'observaciones'          => null,
                    'motivo_no_realizada'    => null,
                    'recuperada'             => false,
                    'recuperada_fecha'       => null,
                    'marcada_por'            => 'synthetic_backfill',
                ];
                $k = $orden . '|' . $tarea;
                if (!isset($lastByTask[$k]) || $actual > $lastByTask[$k]['fecha']) {
                    $lastByTask[$k] = ['fecha' => $actual, 'per' => $p];
                }
            }
        }
    }
}

echo "Plan generado:\n";
echo "  Filas a insertar:       " . count($inserts) . "\n";
echo "  Máquinas consolidadas:  " . $stats['consolidadas'] . " (visitas: " . $stats['visitas_consol'] . ")\n";
echo "  Tareas individuales:    " . $stats['individuales'] . " (visitas: " . $stats['visitas_indiv'] . ")\n";
echo "  Tareas con últ. revisión: " . count($lastByTask) . "\n\n";

if (!$COMMIT) {
    echo "🚫 DRY-RUN — no se escribe nada. Re-ejecuta con --commit para aplicar.\n";
    exit(0);
}

// ────────────── COMMIT ──────────────
$pdo = Db::pg();
$pdo->beginTransaction();
try {
    echo "→ Borrando mant_completions con fecha_intervencion >= $START_DATE…\n";
    $st = $pdo->prepare("DELETE FROM mant_completions WHERE fecha_intervencion >= ?");
    $st->execute([$START_DATE]);
    echo "   borradas: " . $st->rowCount() . "\n";

    echo "→ Insertando " . count($inserts) . " filas (en lotes de 500)…\n";
    $sql = "INSERT INTO mant_completions (
                external_id, tipo, orden, tarea, cod_maquina_mant, desc_maquina,
                grupo, desc_grupo, periodicidad, desc_tarea, activa,
                fecha_proxima_original, fecha_intervencion, hora_inicio,
                operario, observaciones, motivo_no_realizada,
                recuperada, recuperada_fecha, marcada_at, marcada_por
            ) VALUES (
                :external_id, :tipo, :orden, :tarea, :cod_maquina_mant, :desc_maquina,
                :grupo, :desc_grupo, :periodicidad, :desc_tarea, :activa,
                :fecha_proxima_original, :fecha_intervencion, :hora_inicio,
                :operario, :observaciones, :motivo_no_realizada,
                :recuperada, :recuperada_fecha, NOW(), :marcada_por
            )
            ON CONFLICT (external_id) DO UPDATE SET
                tipo                   = EXCLUDED.tipo,
                fecha_proxima_original = EXCLUDED.fecha_proxima_original,
                fecha_intervencion     = EXCLUDED.fecha_intervencion,
                hora_inicio            = EXCLUDED.hora_inicio,
                operario               = EXCLUDED.operario,
                marcada_por            = EXCLUDED.marcada_por";
    $ins = $pdo->prepare($sql);
    $batch = 0;
    foreach ($inserts as $i => $row) {
        $ins->execute([
            ':external_id'            => $row['external_id'],
            ':tipo'                   => $row['tipo'],
            ':orden'                  => $row['orden'],
            ':tarea'                  => $row['tarea'],
            ':cod_maquina_mant'       => $row['cod_maquina_mant'],
            ':desc_maquina'           => $row['desc_maquina'],
            ':grupo'                  => $row['grupo'],
            ':desc_grupo'             => $row['desc_grupo'],
            ':periodicidad'           => $row['periodicidad'],
            ':desc_tarea'             => $row['desc_tarea'],
            ':activa'                 => $row['activa'],
            ':fecha_proxima_original' => $row['fecha_proxima_original'],
            ':fecha_intervencion'     => $row['fecha_intervencion'],
            ':hora_inicio'            => $row['hora_inicio'],
            ':operario'               => $row['operario'],
            ':observaciones'          => $row['observaciones'],
            ':motivo_no_realizada'    => $row['motivo_no_realizada'],
            ':recuperada'             => $row['recuperada'] ? 'true' : 'false',
            ':recuperada_fecha'       => $row['recuperada_fecha'],
            ':marcada_por'            => $row['marcada_por'],
        ]);
        $batch++;
        if ($batch % 500 === 0) echo "   …$batch / " . count($inserts) . "\n";
    }
    echo "   total insertadas/upserts: $batch\n";

    echo "→ Recalculando mant_plan.ultima_revision y proxima_revision…\n";
    $upd = $pdo->prepare("
        UPDATE mant_plan
           SET ultima_revision  = :ultima,
               proxima_revision = :proxima
         WHERE orden = :orden AND tarea = :tarea
    ");
    $updCount = 0;
    foreach ($lastByTask as $k => $info) {
        [$orden, $tarea] = explode('|', $k, 2);
        $per = (string)$info['per'];
        $days = $PERIODICIDAD_DIAS[$per] ?? null;
        $proxima = null;
        if ($days !== null) {
            $proxima = date('Y-m-d', strtotime($info['fecha']) + $days * 86400);
        }
        $upd->execute([
            ':ultima'  => $info['fecha'],
            ':proxima' => $proxima,
            ':orden'   => $orden,
            ':tarea'   => $tarea,
        ]);
        if ($upd->rowCount() > 0) $updCount++;
    }
    echo "   tareas actualizadas: $updCount\n";

    $pdo->commit();
    echo "\n✓ Commit completado.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\n✗ ERROR — rollback: " . $e->getMessage() . "\n");
    exit(1);
}

// ────────────── Estado final ──────────────
echo "\nEstado final mant_completions:\n";
$total = Db::pgFetchOne("SELECT COUNT(*) AS c FROM mant_completions")['c'];
$rng   = Db::pgFetchOne("SELECT MIN(fecha_intervencion) AS minf, MAX(fecha_intervencion) AS maxf FROM mant_completions");
echo "  total: $total\n";
echo "  rango: " . $rng['minf'] . " → " . $rng['maxf'] . "\n";
