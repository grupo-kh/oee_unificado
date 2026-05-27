<?php
/**
 * Seed puntual para los 26 RACK PUERTAS del listado del usuario:
 *
 *   - RACK PUERTAS TA DEL LH - 01, 02, 04, 05, 06, 10
 *   - RACK PUERTAS TA DEL RH - 02, 03, 04, 05, 07, 08, 09
 *   - RACK PUERTAS TA TRA LH - 01, 02, 03, 06, 07, 08
 *   - RACK PUERTAS TA TRA RH - 01, 02, 03, 04, 07, 10, 11
 *
 * Cada uno con 12 tareas TRIMESTRAL idénticas (10669, 10670, 10671,
 * 10672, 10673, 10674, 10675, 10689, 10690, 10691, 10692, 11024).
 *
 *   Familia: RACKS · DescripcionGrupo: "RACK PUERTAS TRIMESTRAL"
 *   Tiempo estimado: 20..30 min por tarea (~±25)
 *   Histórico: TRIMESTRAL desde 01/09/2025 hasta hoy (todas las
 *              sub-tareas comparten fecha+operario+hora por visita)
 *
 * Modos:
 *   php tools/mant_seed_racks_puertas.php
 *     → DRY-RUN
 *   php tools/mant_seed_racks_puertas.php --apply
 *     → ESCRITURA
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply = in_array('--apply', $argv, true);

echo "Seed RACK PUERTAS · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// ── Operarios ──
$ops = array_map(fn($r) => (string)$r['nombre'],
    Db::pgFetchAll("SELECT nombre FROM mant_operarios WHERE COALESCE(activo, TRUE) = TRUE ORDER BY nombre"));
if (!$ops) $ops = MaintenanceCompletionStore::loadOperarios();
if (!$ops) { fwrite(STDERR, "Sin operarios.\n"); exit(3); }
echo "Operarios disponibles: " . count($ops) . PHP_EOL;

// ── 26 máquinas con su orden ──
$maquinas = [
    1106 => 'RACK PUERTAS TA DEL LH - 01',
    1107 => 'RACK PUERTAS TA DEL LH - 02',
    1109 => 'RACK PUERTAS TA DEL LH - 04',
    1110 => 'RACK PUERTAS TA DEL LH - 05',
    1111 => 'RACK PUERTAS TA DEL LH - 06',
    1115 => 'RACK PUERTAS TA DEL LH - 10',
    1117 => 'RACK PUERTAS TA DEL RH - 02',
    1118 => 'RACK PUERTAS TA DEL RH - 03',
    1119 => 'RACK PUERTAS TA DEL RH - 04',
    1120 => 'RACK PUERTAS TA DEL RH - 05',
    1122 => 'RACK PUERTAS TA DEL RH - 07',
    1123 => 'RACK PUERTAS TA DEL RH - 08',
    1124 => 'RACK PUERTAS TA DEL RH - 09',
    1126 => 'RACK PUERTAS TA TRA LH - 01',
    1127 => 'RACK PUERTAS TA TRA LH - 02',
    1128 => 'RACK PUERTAS TA TRA LH - 03',
    1131 => 'RACK PUERTAS TA TRA LH - 06',
    1132 => 'RACK PUERTAS TA TRA LH - 07',
    1133 => 'RACK PUERTAS TA TRA LH - 08',
    1135 => 'RACK PUERTAS TA TRA RH - 01',
    1136 => 'RACK PUERTAS TA TRA RH - 02',
    1137 => 'RACK PUERTAS TA TRA RH - 03',
    1138 => 'RACK PUERTAS TA TRA RH - 04',
    1141 => 'RACK PUERTAS TA TRA RH - 07',
    1144 => 'RACK PUERTAS TA TRA RH - 10',
    1145 => 'RACK PUERTAS TA TRA RH - 11',
];

// ── 12 tareas comunes a todos los racks PUERTAS ──
$tareasComunes = [
    ['10669', 'LIMPIEZA INTERIOR RACK. Sin restos de vidrios y protecciones en perfecto estado'],
    ['10670', 'ESTADO FUNDAS. Que estén correctamente fijadas y no presenten roturas. En caso contrario sustituir'],
    ['10671', 'ESTADO DE VARILLAS. Correcto paralelismo entre ellas'],
    ['10672', 'ESTRUCTURA METALICA. Comprobar daños en patines, rejas, bastidor y  brazos separadores'],
    ['10673', 'PROTECCIONES ENGOMADAS + BARRA TOPE LATERAL. Protecciones engomadas en buen estado y bien ancladas al chásis (remaches)'],
    ['10674', 'BARRA DE CIERRE. Comprobar su funcionalidad, estado (doblada) y los pestillos de seguridad'],
    ['10675', 'PEINES. Comprobar estado peines y correcta sujeción al chásis (tornillos)'],
    ['10689', 'ESTADO PINTURA. Repintado de todas las zonas gastadas. Numeración del rack y pintado ralla identificativa según trimestre rosa, azul, morado o rojo'],
    ['10690', 'PRESENCIA Y ESTADO  PORTADOCUMENTOS. Comprobar con un folio que se pueda ubicar.'],
    ['10691', 'PATINES. Revisar que no presenta golpes y grietas.'],
    ['10692', 'DIMENSIONADO RACK. Comprobación de geometría general del bastidor del rack, siguiendo las indicaciones del plano proporcionado.'],
    ['11024', 'Revisión de todos los amortiguadores: Desmontaje y montaje de todos ellos y revisión manual de su estado'],
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
        $cursor = date('Y-m-d', strtotime($cursor . ' +' . mt_rand(87, 93) . ' days'));
    }
    return $fechas;
}

$hoy    = date('Y-m-d');
$inicio = '2025-09-01';
$grupo  = '10151';
$descGr = 'RACKS';

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
            if ($hasDescGr) { $cols[] = 'desc_grupo'; $vals[] = ':dgr'; $params[':dgr'] = $descGr; }
            Db::pgExec("INSERT INTO mant_maquinas (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")", $params);
        }
        $insMaq++;
    } else {
        $upMaq++;
    }

    // 2. Generar histórico TRIMESTRAL: una visita = misma fecha + mismo operario + misma hora
    //    para todas las sub-tareas del rack.
    $fechasVisitas = genFechasTrimestrales($inicio, $hoy);
    $visitas = [];
    foreach ($fechasVisitas as $fpo) {
        $offset   = mt_rand(-2, 2);
        $fechaInt = date('Y-m-d', strtotime($fpo . ' ' . sprintf('%+d', $offset) . ' days'));
        if ($fechaInt > $hoy) $fechaInt = $hoy;
        $fechaInt = CalendarioLaboral::ajustarADiaHabil($fechaInt, 'anterior');
        $visitas[] = [
            'fpo'      => $fpo,
            'fechaInt' => $fechaInt,
            'op'       => $ops[mt_rand(0, count($ops) - 1)],
            'hora'     => MaintenanceCompletionStore::horaTurnoAleatoria(),
        ];
    }

    // 3. Para cada sub-tarea: insertar en mant_plan + sus marcas
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
                ':g'  => $grupo, ':dg' => $descGr,
                ':dt' => $descTarea,
                ':te' => $teMin,
            ]);
        }
        $insTarea++;

        // 4. Marcas del histórico para esta sub-tarea (usando las visitas comunes)
        $ultIntFecha = null;
        foreach ($visitas as $v) {
            $ultIntFecha = $v['fechaInt'];
            if ($apply) {
                try {
                    MaintenanceCompletionStore::add([
                        'tipo'                   => 'completada',
                        'orden'                  => (string)$orden,
                        'tarea'                  => $tarea,
                        'cod_maquina_mant'       => $cod,
                        'desc_maquina'           => $desc,
                        'grupo'                  => $grupo,
                        'desc_grupo'             => $descGr,
                        'periodicidad'           => 'TRIMESTRAL',
                        'desc_tarea'             => $descTarea,
                        'fecha_proxima_original' => $v['fpo'],
                        'fecha_intervencion'     => $v['fechaInt'],
                        'hora_inicio'            => $v['hora'],
                        'operario'               => $v['op'],
                        'observaciones'          => '',
                        'motivo_no_realizada'    => '',
                        'recuperada'             => false,
                        'recuperada_fecha'       => null,
                        'marcada_at'             => time(),
                        'marcada_por'            => 'seed_racks_puertas',
                        'tiempo_real_segundos'   => MaintenanceCompletionStore::aplicarDecalajeAleatorio($teMin * 60),
                    ]);
                    $insInter++;
                } catch (Throwable $e) { /* skip dup */ }
            } else {
                $insInter++;
            }
        }

        // 5. Avanzar plan
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
    echo "  php tools/mant_seed_racks_puertas.php --apply\n";
}
