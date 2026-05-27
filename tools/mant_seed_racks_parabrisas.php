<?php
/**
 * Seed puntual para los 13 RACK PARABRISAS TB del listado:
 *
 *   - RACK PARABRISAS TB - 01..05, 07, 08, 09, 10, 11, 12, 13, 14
 *
 *   Cada uno con 14 tareas TRIMESTRAL idénticas (10669, 10672, …, 11039)
 *   y orden propio (1090, 1091, …, 1232 en el caso del 08).
 *
 *   Familia: RACKS · DescripcionGrupo: "RACK PARABRISAS TRIMESTRAL"
 *   Tiempo estimado: 20..30 min por tarea (~±25 min)
 *   Histórico: TRIMESTRAL desde 01/09/2025 hasta hoy
 *
 * Modos:
 *   php tools/mant_seed_racks_parabrisas.php
 *     → DRY-RUN
 *   php tools/mant_seed_racks_parabrisas.php --apply
 *     → ESCRITURA
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply = in_array('--apply', $argv, true);

echo "Seed RACK PARABRISAS TB · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// ── Operarios disponibles ──
$ops = array_map(fn($r) => (string)$r['nombre'],
    Db::pgFetchAll("SELECT nombre FROM mant_operarios WHERE COALESCE(activo, TRUE) = TRUE ORDER BY nombre"));
if (!$ops) $ops = MaintenanceCompletionStore::loadOperarios();
if (!$ops) { fwrite(STDERR, "No hay operarios; aborto.\n"); exit(3); }
echo "Operarios disponibles: " . count($ops) . PHP_EOL;

// ── 13 máquinas con su orden ──
$maquinas = [
    1090 => 'RACK PARABRISAS TB - 01',
    1091 => 'RACK PARABRISAS TB - 02',
    1092 => 'RACK PARABRISAS TB - 03',
    1093 => 'RACK PARABRISAS TB - 04',
    1094 => 'RACK PARABRISAS TB - 05',
    1096 => 'RACK PARABRISAS TB - 07',
    1098 => 'RACK PARABRISAS TB - 09',
    1099 => 'RACK PARABRISAS TB - 10',
    1100 => 'RACK PARABRISAS TB - 11',
    1101 => 'RACK PARABRISAS TB - 12',
    1102 => 'RACK PARABRISAS TB - 13',
    1103 => 'RACK PARABRISAS TB - 14',
    1232 => 'RACK PARABRISAS TB - 08',
];

// ── 14 tareas comunes a todos los racks ──
$tareasComunes = [
    ['10669', 'LIMPIEZA INTERIOR RACK. Sin restos de vidrios y protecciones en perfecto estado'],
    ['10672', 'ESTRUCTURA METALICA. Comprobar daños en patines, rejas, bastidor y  brazos separadores'],
    ['10673', 'PROTECCIONES ENGOMADAS + BARRA TOPE LATERAL. Protecciones engomadas en buen estado y bien ancladas al chásis (remaches)'],
    ['10675', 'PEINES. Comprobar estado peines y correcta sujeción al chásis (tornillos)'],
    ['10676', 'RESORTES GAS-BRAZOS. Comprobar estado de la tornillería. Reapretar uniones atornilladas empleando una galga de espesores de 0.5mm, asegurando una correcta holgura.'],
    ['10689', 'ESTADO PINTURA. Repintado de todas las zonas gastadas. Numeración del rack y pintado ralla identificativa según trimestre rosa, azul, morado o rojo'],
    ['10690', 'PRESENCIA Y ESTADO  PORTADOCUMENTOS. Comprobar con un folio que se pueda ubicar.'],
    ['10692', 'DIMENSIONADO RACK. Comprobación de geometría general del bastidor del rack, siguiendo las indicaciones del plano proporcionado.'],
    ['10693', 'FUNDA PALAS. Revisar que no presenta golpes y grietas.'],
    ['10879', 'RESORTES GAS-BRAZOS: Comprobar la fuerza de cada uno de los amortiguadores. Reemplazar en caso de rotura.'],
    ['10888', 'LUBRICACIÓN: Ejes de los brazos separadores. Limpiar el sobrante.'],
    ['10889', 'ETIQUETA: Comprobar que la numeración física del rack coincide con la etiqueta de codigo de barras.'],
    ['11024', 'Revisión de todos los amortiguadores: Desmontaje y montaje de todos ellos y revisión manual de su estado'],
    ['11039', 'Revisión del tornillo situado en primera posición del rack y en caso de desgaste, sustituir'],
];

// ── Detectar columnas mant_maquinas ──
$colNames = array_column(Db::pgFetchAll("
    SELECT column_name FROM information_schema.columns WHERE table_name = 'mant_maquinas'
"), 'column_name');
$hasGrupo  = in_array('grupo', $colNames, true);
$hasDescGr = in_array('desc_grupo', $colNames, true);

// ── Helpers ──
function genFechasTrimestrales(string $desde, string $hasta): array {
    $fechas = [];
    $cursor = $desde;
    while ($cursor <= $hasta) {
        $fechas[] = $cursor;
        $cursor = date('Y-m-d', strtotime($cursor . ' +' . mt_rand(85, 95) . ' days'));
    }
    return $fechas;
}

$hoy    = date('Y-m-d');
$inicio = '2025-09-01';
$grupo     = '10163';
$descGrupo = 'RACK PARABRISAS TRIMESTRAL';

$insMaq = 0; $upMaq = 0;
$insTarea = 0;
$insInter = 0;

foreach ($maquinas as $orden => $cod) {
    $desc = $cod;

    // 1. mant_maquinas
    $existe = (bool) Db::pgFetchOne(
        "SELECT 1 FROM mant_maquinas WHERE cod_maquina_mant = :c LIMIT 1", [':c' => $cod]
    );
    if (!$existe) {
        if ($apply) {
            $cols = ['cod_maquina_mant', 'desc_maquina'];
            $vals = [':cod', ':desc'];
            $params = [':cod' => $cod, ':desc' => $desc];
            if ($hasGrupo)  { $cols[] = 'grupo';      $vals[] = ':gr';  $params[':gr']  = $grupo; }
            if ($hasDescGr) { $cols[] = 'desc_grupo'; $vals[] = ':dgr'; $params[':dgr'] = 'RACKS'; }
            Db::pgExec("INSERT INTO mant_maquinas (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")", $params);
        }
        $insMaq++;
    } else {
        $upMaq++;
    }

    // 2. Tareas en mant_plan + histórico
    foreach ($tareasComunes as [$tarea, $descTarea]) {
        $teMin = mt_rand(20, 30);  // ±5 sobre 25

        if ($apply) {
            Db::pgExec("
                INSERT INTO mant_plan (
                    orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                    periodicidad, desc_tarea, activa, alta_baja,
                    tiempo_estimado, tipo_mantenimiento
                ) VALUES (
                    :o, :t, :cm, :dm, :g, :dg,
                    'TRIMESTRAL', :dt, 'A', 'ALTA',
                    :te, 'Preventivo'
                )
                ON CONFLICT (orden, tarea) DO UPDATE SET
                    desc_tarea      = EXCLUDED.desc_tarea,
                    periodicidad    = EXCLUDED.periodicidad,
                    tiempo_estimado = EXCLUDED.tiempo_estimado
            ", [
                ':o'  => (string)$orden, ':t' => $tarea,
                ':cm' => $cod, ':dm' => $desc,
                ':g'  => $grupo, ':dg' => 'RACKS',
                ':dt' => $descTarea,
                ':te' => $teMin,
            ]);
        }
        $insTarea++;

        // 3. Histórico TRIMESTRAL desde 01/09/2025
        $fechas = genFechasTrimestrales($inicio, $hoy);
        $ultIntFecha = null;
        foreach ($fechas as $fpo) {
            $offset   = mt_rand(-3, 3);
            $fechaInt = date('Y-m-d', strtotime($fpo . ' ' . sprintf('%+d', $offset) . ' days'));
            if ($fechaInt > $hoy) $fechaInt = $hoy;
            $fechaInt = CalendarioLaboral::ajustarADiaHabil($fechaInt, 'anterior');
            $ultIntFecha = $fechaInt;
            if ($apply) {
                try {
                    MaintenanceCompletionStore::add([
                        'tipo'                   => 'completada',
                        'orden'                  => (string)$orden,
                        'tarea'                  => $tarea,
                        'cod_maquina_mant'       => $cod,
                        'desc_maquina'           => $desc,
                        'grupo'                  => $grupo,
                        'desc_grupo'             => 'RACKS',
                        'periodicidad'           => 'TRIMESTRAL',
                        'desc_tarea'             => $descTarea,
                        'fecha_proxima_original' => $fpo,
                        'fecha_intervencion'     => $fechaInt,
                        'hora_inicio'            => MaintenanceCompletionStore::horaTurnoAleatoria(),
                        'operario'               => $ops[mt_rand(0, count($ops) - 1)],
                        'observaciones'          => 'Histórico generado',
                        'motivo_no_realizada'    => '',
                        'recuperada'             => false,
                        'recuperada_fecha'       => null,
                        'marcada_at'             => time(),
                        'marcada_por'            => 'seed_racks_parabrisas',
                        'tiempo_real_segundos'   => MaintenanceCompletionStore::aplicarDecalajeAleatorio($teMin * 60),
                    ]);
                    $insInter++;
                } catch (Throwable $e) { /* skip dup */ }
            } else {
                $insInter++;
            }
        }

        // 4. Avanzar plan
        if ($apply && $ultIntFecha) {
            $proxima = date('Y-m-d', strtotime($ultIntFecha . ' +90 days'));
            Db::pgExec(
                "UPDATE mant_plan SET ultima_revision = :u, proxima_revision = :p
                  WHERE orden = :o AND tarea = :t",
                [':u' => $ultIntFecha, ':p' => $proxima, ':o' => (string)$orden, ':t' => $tarea]
            );
        }
    }
    printf("  · %s (orden=%d) %s\n", $cod, $orden, $apply ? 'OK' : '[dry]');
}

echo str_repeat('─', 70) . PHP_EOL;
echo "Máquinas insertadas: $insMaq · ya existían: $upMaq\n";
echo "Tareas (insertadas o actualizadas): $insTarea\n";
echo "Intervenciones generadas: $insInter\n";
if (!$apply) {
    echo "\nPara aplicarlo:\n";
    echo "  php tools/mant_seed_racks_parabrisas.php --apply\n";
}
