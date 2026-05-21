<?php
/**
 * Seed puntual para ETIQUETADORA 01:
 *
 *   1. Añade las 3 tareas que faltan en mant_plan (10816, 10139, 10556)
 *      con sus datos (periodicidad, descripción, tiempo estimado, última/
 *      próxima revisión). Usa el mismo `orden` y grupo que la tarea 11079
 *      ya existente.
 *
 *   2. Para las 4 tareas (la existente y las 3 nuevas), genera intervenciones
 *      desde 2025-09-01 hasta la última revisión indicada (o hasta hoy si
 *      no se indicó), con:
 *        - frecuencia mensual (30 días ± jitter de 3)
 *        - fecha_intervencion = fpo ± hasta 2 días
 *        - hora aleatoria 08:00-17:00
 *        - operario aleatorio de mant_operarios
 *        - tiempo real = tiempo_estimado*60 ± 5..10 seg
 *      Los duplicados se evitan (mismo external_id).
 *
 * Modos:
 *   php tools/mant_seed_etiquetadora01.php
 *     → DRY-RUN. Muestra qué pasaría.
 *
 *   php tools/mant_seed_etiquetadora01.php --apply
 *     → Aplica los cambios en la BD.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

$apply = in_array('--apply', $argv, true);

echo "Seed puntual ETIQUETADORA 01 · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$cod  = 'ETIQUETADORA 01';
$desc = 'ETIQUETADORA 01';

// 1. Coger orden/grupo de una tarea existente de la máquina
$existing = Db::pgFetchOne("
    SELECT orden, grupo, desc_grupo
      FROM mant_plan
     WHERE cod_maquina_mant = :c
     LIMIT 1
", [':c' => $cod]);

if (!$existing) {
    fwrite(STDERR, "No existe ninguna tarea previa de '$cod'. Aborto.\n");
    exit(3);
}
$orden     = (string)$existing['orden'];
$grupo     = $existing['grupo']      ?? null;
$descGrupo = $existing['desc_grupo'] ?? null;
printf("Máquina existente · orden=%s · grupo=%s · desc_grupo=%s\n",
    $orden, $grupo ?? '(null)', $descGrupo ?? '(null)');

// 2. Definición de las 4 tareas (las 3 nuevas + la existente para el histórico)
$tareas = [
    [
        'tarea'   => '11079',
        'per'     => 'MENSUAL',
        'desc'    => 'ETIQUETADORA01_Limpieza general de la instalacion y comprobacion funcionmiento del detector de piezas',
        'te'      => 10,
        'ultima'  => null,                 // sin última previa
        'proxima' => null,                 // se calculará al final
    ],
    [
        'tarea'   => '10816',
        'per'     => 'MENSUAL',
        'desc'    => 'Comprobar presión entrada a la máquina 6,5 +- 0,5 bar.',
        'te'      => 5,
        'ultima'  => '2026-05-05',
        'proxima' => '2026-05-12',
    ],
    [
        'tarea'   => '10139',
        'per'     => 'MENSUAL',
        'desc'    => 'Neumática: Revisar posibles fugas en circuito.',
        'te'      => 10,
        'ultima'  => '2026-05-02',
        'proxima' => '2026-05-09',
    ],
    [
        'tarea'   => '10556',
        'per'     => 'MENSUAL',
        'desc'    => 'MÓDULO DE MANTENIMIENTO: Comprobar el purgador de agua y el llenado de aceite, y en su caso, añadir.',
        'te'      => 5,
        'ultima'  => '2026-05-05',
        'proxima' => '2026-05-12',
    ],
];

// 3. Cargar operarios disponibles
$ops = array_map(fn($r) => (string)$r['nombre'],
    Db::pgFetchAll("SELECT nombre FROM mant_operarios WHERE COALESCE(activo, TRUE) = TRUE ORDER BY nombre"));
if (!$ops) {
    $ops = MaintenanceCompletionStore::loadOperarios();
}
if (!$ops) $ops = ['Operario'];

// 4. Insertar/actualizar las tareas en mant_plan
$inserted = 0; $updated = 0;
foreach ($tareas as $t) {
    if ($apply) {
        $hadIt = (bool) Db::pgFetchOne(
            "SELECT 1 FROM mant_plan WHERE orden = :o AND tarea = :t LIMIT 1",
            [':o' => $orden, ':t' => $t['tarea']]
        );
        Db::pgExec("
            INSERT INTO mant_plan (
                orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                periodicidad, desc_tarea, activa, alta_baja,
                tiempo_estimado, tipo_mantenimiento,
                ultima_revision, proxima_revision
            ) VALUES (
                :o, :t, :cm, :dm, :g, :dg,
                :p, :dt, 'A', 'ALTA',
                :te, 'Preventivo',
                :u, :px
            )
            ON CONFLICT (orden, tarea) DO UPDATE SET
                desc_tarea       = EXCLUDED.desc_tarea,
                periodicidad     = EXCLUDED.periodicidad,
                tiempo_estimado  = EXCLUDED.tiempo_estimado,
                ultima_revision  = EXCLUDED.ultima_revision,
                proxima_revision = EXCLUDED.proxima_revision
        ", [
            ':o'  => $orden, ':t'  => $t['tarea'],
            ':cm' => $cod,   ':dm' => $desc,
            ':g'  => $grupo, ':dg' => $descGrupo,
            ':p'  => $t['per'], ':dt' => $t['desc'],
            ':te' => $t['te'],
            ':u'  => $t['ultima'], ':px' => $t['proxima'],
        ]);
        if ($hadIt) $updated++; else $inserted++;
    } else {
        $inserted++;
    }
    printf("  · plan %s/%s  per=%s  te=%dmin  últ=%s  próx=%s%s\n",
        $orden, $t['tarea'], $t['per'], $t['te'],
        $t['ultima'] ?? '—', $t['proxima'] ?? '—',
        $apply ? '' : ' [dry]');
}

// 5. Generar intervenciones desde 01/09/2025 hasta la última revisión
$inicio = '2025-09-01';
$hoy    = date('Y-m-d');
$totalInt = 0;
$skippedDup = 0;

foreach ($tareas as $t) {
    $fin = $t['ultima'] ?: $hoy;
    // Si la "última" es anterior al inicio, no hay histórico que generar
    if ($fin < $inicio) {
        printf("  · histórico %s: nada que generar (fin %s < inicio %s)\n",
            $t['tarea'], $fin, $inicio);
        continue;
    }

    // Construir fechas programadas con jitter mensual ~30±3 días
    $fechas = [];
    $cursor = $inicio;
    while ($cursor <= $fin) {
        $fechas[] = $cursor;
        $cursor = date('Y-m-d', strtotime($cursor . ' +' . mt_rand(27, 33) . ' days'));
    }

    foreach ($fechas as $fpo) {
        // fecha real = fpo ± hasta 2 días
        $offset   = mt_rand(-2, 2);
        $fechaInt = date('Y-m-d', strtotime($fpo . ' ' . sprintf('%+d', $offset) . ' days'));
        if ($fechaInt > $hoy) $fechaInt = $hoy;

        $hora      = sprintf('%02d:%02d', mt_rand(6, 20), mt_rand(0, 59));
        $tiempoSeg = MaintenanceCompletionStore::aplicarDecalajeAleatorio($t['te'] * 60);
        $op        = $ops[mt_rand(0, count($ops) - 1)];

        if ($apply) {
            try {
                MaintenanceCompletionStore::add([
                    'tipo'                   => 'completada',
                    'orden'                  => $orden,
                    'tarea'                  => $t['tarea'],
                    'cod_maquina_mant'       => $cod,
                    'desc_maquina'           => $desc,
                    'grupo'                  => $grupo,
                    'desc_grupo'             => $descGrupo,
                    'periodicidad'           => $t['per'],
                    'desc_tarea'             => $t['desc'],
                    'fecha_proxima_original' => $fpo,
                    'fecha_intervencion'     => $fechaInt,
                    'hora_inicio'            => $hora,
                    'operario'               => $op,
                    'observaciones'          => 'Histórico generado',
                    'motivo_no_realizada'    => '',
                    'recuperada'             => false,
                    'recuperada_fecha'       => null,
                    'marcada_at'             => time(),
                    'marcada_por'            => 'seed_etiquetadora01',
                    'tiempo_real_segundos'   => $tiempoSeg,
                ]);
                $totalInt++;
            } catch (Throwable $e) {
                $skippedDup++;
            }
        } else {
            $totalInt++;
        }
    }
    printf("  · histórico %s: %d intervenciones desde %s a %s\n",
        $t['tarea'], count($fechas), $inicio, $fin);
}

echo str_repeat('─', 70) . PHP_EOL;
echo "Tareas insertadas: $inserted" . PHP_EOL;
echo "Tareas actualizadas: $updated" . PHP_EOL;
echo "Intervenciones añadidas: $totalInt" . PHP_EOL;
if ($skippedDup > 0) echo "Saltadas por duplicado: $skippedDup" . PHP_EOL;
if (!$apply) {
    echo PHP_EOL . "Para aplicar de verdad:" . PHP_EOL;
    echo "  php tools/mant_seed_etiquetadora01.php --apply" . PHP_EOL;
}
