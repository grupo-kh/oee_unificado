<?php
/**
 * RESET DEFINITIVO de SECUENCIA/RACKS al listado canónico del usuario:
 *
 *   - 8 RACK CUSTODIAS TA LH (969..977 sin 970)
 *   - 8 RACK CUSTODIAS TA RH (984..993 sin 989, 990)
 *   - 3 RACK CUSTODIAS TB (1209 LH-12, 1210 RH-11, 1211 LH-11)
 *   - 9 RACK LUNETAS TA   (1045..1055 con huecos)
 *   - 10 RACK LUNETAS TB  (1060..1070 sin 1066)
 *   - 13 RACK PARABRISAS TA (1076..1087 + 1206, 1212, 1231)
 *   - 12 RACK PARABRISAS TB (1090..1103 sin 1095, 1097)
 *   - 5 RACK PUERTAS TB TRA LH (1169..1174 con huecos)
 *   - 7 RACK PUERTAS TB TRA RH (1175..1182 con huecos)
 *
 * Total: 75 máquinas.
 *
 * 1. ELIMINA toda máquina RACK% que NO esté en la lista (mant_completions
 *    + mant_plan + mant_maquinas).
 * 2. INSERTA/actualiza las 75 con sus tareas (activa='A', alta='ALTA',
 *    tiempo_estimado 20..30 min).
 * 3. Genera histórico TRIMESTRAL desde 2025-09-01 a hoy: una visita por
 *    trimestre por máquina con mismo operario, hora y día hábil.
 *
 * Modos:
 *   php tools/mant_reset_racks_definitivo.php
 *     → DRY-RUN
 *   php tools/mant_reset_racks_definitivo.php --apply
 *     → ESCRITURA
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply = in_array('--apply', $argv, true);

echo "Reset DEFINITIVO de SECUENCIA/RACKS · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// ── Operarios ──
$ops = array_map(fn($r) => (string)$r['nombre'],
    Db::pgFetchAll("SELECT nombre FROM mant_operarios WHERE COALESCE(activo, TRUE) = TRUE ORDER BY nombre"));
if (!$ops) $ops = MaintenanceCompletionStore::loadOperarios();
if (!$ops) { fwrite(STDERR, "Sin operarios.\n"); exit(3); }

// ── 75 máquinas con su orden y grupo ──
// Formato: orden => [nombre, grupo]
$maquinas = [
    // CUSTODIAS (grupo 10167)
    969  => ['RACK CUSTODIAS TA LH - 01', '10167'],
    971  => ['RACK CUSTODIAS TA LH - 03', '10167'],
    972  => ['RACK CUSTODIAS TA LH - 04', '10167'],
    973  => ['RACK CUSTODIAS TA LH - 05', '10167'],
    974  => ['RACK CUSTODIAS TA LH - 06', '10167'],
    975  => ['RACK CUSTODIAS TA LH - 07', '10167'],
    976  => ['RACK CUSTODIAS TA LH - 08', '10167'],
    977  => ['RACK CUSTODIAS TA LH - 09', '10167'],
    984  => ['RACK CUSTODIAS TA RH - 01', '10167'],
    985  => ['RACK CUSTODIAS TA RH - 02', '10167'],
    986  => ['RACK CUSTODIAS TA RH - 03', '10167'],
    987  => ['RACK CUSTODIAS TA RH - 04', '10167'],
    988  => ['RACK CUSTODIAS TA RH - 05', '10167'],
    991  => ['RACK CUSTODIAS TA RH - 08', '10167'],
    992  => ['RACK CUSTODIAS TA RH - 09', '10167'],
    993  => ['RACK CUSTODIAS TA RH - 10', '10167'],
    1209 => ['RACK CUSTODIAS TB LH - 12', '10167'],
    1210 => ['RACK CUSTODIAS TB RH - 11', '10167'],
    1211 => ['RACK CUSTODIAS TB LH - 11', '10167'],
    // LUNETAS (grupo 10159)
    1045 => ['RACK LUNETAS TA - 01', '10159'],
    1046 => ['RACK LUNETAS TA - 02', '10159'],
    1047 => ['RACK LUNETAS TA - 03', '10159'],
    1048 => ['RACK LUNETAS TA - 04', '10159'],
    1049 => ['RACK LUNETAS TA - 05', '10159'],
    1050 => ['RACK LUNETAS TA - 06', '10159'],
    1051 => ['RACK LUNETAS TA - 07', '10159'],
    1054 => ['RACK LUNETAS TA - 10', '10159'],
    1055 => ['RACK LUNETAS TA - 11', '10159'],
    1060 => ['RACK LUNETAS TB - 01', '10159'],
    1061 => ['RACK LUNETAS TB - 02', '10159'],
    1062 => ['RACK LUNETAS TB - 03', '10159'],
    1063 => ['RACK LUNETAS TB - 04', '10159'],
    1064 => ['RACK LUNETAS TB - 05', '10159'],
    1065 => ['RACK LUNETAS TB - 06', '10159'],
    1067 => ['RACK LUNETAS TB - 08', '10159'],
    1068 => ['RACK LUNETAS TB - 09', '10159'],
    1069 => ['RACK LUNETAS TB - 10', '10159'],
    1070 => ['RACK LUNETAS TB - 11', '10159'],
    // PARABRISAS TA (grupo 10163)
    1076 => ['RACK PARABRISAS TA - 01', '10163'],
    1077 => ['RACK PARABRISAS TA - 02', '10163'],
    1078 => ['RACK PARABRISAS TA - 03', '10163'],
    1079 => ['RACK PARABRISAS TA - 04', '10163'],
    1080 => ['RACK PARABRISAS TA - 05', '10163'],
    1081 => ['RACK PARABRISAS TA - 06', '10163'],
    1083 => ['RACK PARABRISAS TA - 08', '10163'],
    1085 => ['RACK PARABRISAS TA - 10', '10163'],
    1086 => ['RACK PARABRISAS TA - 11', '10163'],
    1087 => ['RACK PARABRISAS TA - 12', '10163'],
    1206 => ['RACK PARABRISAS TA - 07', '10163'],
    1212 => ['RACK PARABRISAS TA - 13', '10163'],
    1231 => ['RACK PARABRISAS TA - 09', '10163'],
    // PARABRISAS TB (grupo 10163)
    1090 => ['RACK PARABRISAS TB - 01', '10163'],
    1091 => ['RACK PARABRISAS TB - 02', '10163'],
    1092 => ['RACK PARABRISAS TB - 03', '10163'],
    1093 => ['RACK PARABRISAS TB - 04', '10163'],
    1094 => ['RACK PARABRISAS TB - 05', '10163'],
    1096 => ['RACK PARABRISAS TB - 07', '10163'],
    1098 => ['RACK PARABRISAS TB - 09', '10163'],
    1099 => ['RACK PARABRISAS TB - 10', '10163'],
    1100 => ['RACK PARABRISAS TB - 11', '10163'],
    1101 => ['RACK PARABRISAS TB - 12', '10163'],
    1102 => ['RACK PARABRISAS TB - 13', '10163'],
    1103 => ['RACK PARABRISAS TB - 14', '10163'],
    // PUERTAS TB TRA (grupo 10151)
    1169 => ['RACK PUERTAS TB TRA LH - 05', '10151'],
    1170 => ['RACK PUERTAS TB TRA LH - 06', '10151'],
    1172 => ['RACK PUERTAS TB TRA LH - 08', '10151'],
    1173 => ['RACK PUERTAS TB TRA LH - 09', '10151'],
    1174 => ['RACK PUERTAS TB TRA LH - 10', '10151'],
    1175 => ['RACK PUERTAS TB TRA RH - 01', '10151'],
    1176 => ['RACK PUERTAS TB TRA RH - 02', '10151'],
    1178 => ['RACK PUERTAS TB TRA RH - 04', '10151'],
    1179 => ['RACK PUERTAS TB TRA RH - 05', '10151'],
    1180 => ['RACK PUERTAS TB TRA RH - 06', '10151'],
    1181 => ['RACK PUERTAS TB TRA RH - 07', '10151'],
    1182 => ['RACK PUERTAS TB TRA RH - 08', '10151'],
];

// ── Tareas por familia (código → descripción) ──
$tareasCustodias = [
    '10669' => 'LIMPIEZA INTERIOR RACK. Sin restos de vidrios y protecciones en perfecto estado',
    '10672' => 'ESTRUCTURA METALICA. Comprobar daños en patines, rejas, bastidor y  brazos separadores',
    '10673' => 'PROTECCIONES ENGOMADAS + BARRA TOPE LATERAL. Protecciones engomadas en buen estado y bien ancladas al chásis (remaches)',
    '10675' => 'PEINES. Comprobar estado peines y correcta sujeción al chásis (tornillos)',
    '10676' => 'RESORTES GAS-BRAZOS. Comprobar estado de la tornillería. Reapretar uniones atornilladas empleando una galga de espesores de 0.5mm, asegurando una correcta holgura.',
    '10678' => 'ESTADO TRAMPILLA. Comprobar movimiento apertura y cierre. Revisar la soldadadura de pestillo. Revisar estado de bisagra (Golpes, grietas). Alineación de trampilla',
    '10689' => 'ESTADO PINTURA. Repintado de todas las zonas gastadas. Numeración del rack y pintado ralla identificativa según trimestre rosa, azul, morado o rojo',
    '10690' => 'PRESENCIA Y ESTADO  PORTADOCUMENTOS. Comprobar con un folio que se pueda ubicar.',
    '10692' => 'DIMENSIONADO RACK. Comprobación de geometría general del bastidor del rack, siguiendo las indicaciones del plano proporcionado.',
    '10693' => 'FUNDA PALAS. Revisar que no presenta golpes y grietas.',
    '10879' => 'RESORTES GAS-BRAZOS: Comprobar la fuerza de cada uno de los amortiguadores. Reemplazar en caso de rotura.',
    '10888' => 'LUBRICACIÓN: Ejes de los brazos separadores. Limpiar el sobrante.',
    '10889' => 'ETIQUETA: Comprobar que la numeración física del rack coincide con la etiqueta de codigo de barras.',
];
$tareasLunetas = [
    '10669' => 'LIMPIEZA INTERIOR RACK. Sin restos de vidrios y protecciones en perfecto estado',
    '10672' => 'ESTRUCTURA METALICA. Comprobar daños en patines, rejas, bastidor y  brazos separadores',
    '10673' => 'PROTECCIONES ENGOMADAS + BARRA TOPE LATERAL. Protecciones engomadas en buen estado y bien ancladas al chásis (remaches)',
    '10675' => 'PEINES. Comprobar estado peines y correcta sujeción al chásis (tornillos)',
    '10676' => 'RESORTES GAS-BRAZOS. Comprobar estado de la tornillería. Reapretar uniones atornilladas empleando una galga de espesores de 0.5mm, asegurando una correcta holgura.',
    '10689' => 'ESTADO PINTURA. Repintado de todas las zonas gastadas. Numeración del rack y pintado ralla identificativa según trimestre rosa, azul, morado o rojo',
    '10690' => 'PRESENCIA Y ESTADO  PORTADOCUMENTOS. Comprobar con un folio que se pueda ubicar.',
    '10692' => 'DIMENSIONADO RACK. Comprobación de geometría general del bastidor del rack, siguiendo las indicaciones del plano proporcionado.',
    '10693' => 'FUNDA PALAS. Revisar que no presenta golpes y grietas.',
    '10879' => 'RESORTES GAS-BRAZOS: Comprobar la fuerza de cada uno de los amortiguadores. Reemplazar en caso de rotura.',
    '10888' => 'LUBRICACIÓN: Ejes de los brazos separadores. Limpiar el sobrante.',
    '10889' => 'ETIQUETA: Comprobar que la numeración física del rack coincide con la etiqueta de codigo de barras.',
    '10890' => 'PEINES ABATIBLES: Comprobar que la zona de contacto entre peinte y luneta está redondeada. ',
    '10891' => 'PEINE CENTRAL: Comprobar movimiento de retorno del muelle rotativo. Lubricar y accionar el movimiento 5 veces, al finalizar limpiar el sobrante.',
];
$tareasParabrisas = [
    '10669' => 'LIMPIEZA INTERIOR RACK. Sin restos de vidrios y protecciones en perfecto estado',
    '10672' => 'ESTRUCTURA METALICA. Comprobar daños en patines, rejas, bastidor y  brazos separadores',
    '10673' => 'PROTECCIONES ENGOMADAS + BARRA TOPE LATERAL. Protecciones engomadas en buen estado y bien ancladas al chásis (remaches)',
    '10675' => 'PEINES. Comprobar estado peines y correcta sujeción al chásis (tornillos)',
    '10676' => 'RESORTES GAS-BRAZOS. Comprobar estado de la tornillería. Reapretar uniones atornilladas empleando una galga de espesores de 0.5mm, asegurando una correcta holgura.',
    '10689' => 'ESTADO PINTURA. Repintado de todas las zonas gastadas. Numeración del rack y pintado ralla identificativa según trimestre rosa, azul, morado o rojo',
    '10690' => 'PRESENCIA Y ESTADO  PORTADOCUMENTOS. Comprobar con un folio que se pueda ubicar.',
    '10692' => 'DIMENSIONADO RACK. Comprobación de geometría general del bastidor del rack, siguiendo las indicaciones del plano proporcionado.',
    '10693' => 'FUNDA PALAS. Revisar que no presenta golpes y grietas.',
    '10879' => 'RESORTES GAS-BRAZOS: Comprobar la fuerza de cada uno de los amortiguadores. Reemplazar en caso de rotura.',
    '10888' => 'LUBRICACIÓN: Ejes de los brazos separadores. Limpiar el sobrante.',
    '10889' => 'ETIQUETA: Comprobar que la numeración física del rack coincide con la etiqueta de codigo de barras.',
    '11024' => 'Revisión de todos los amortiguadores: Desmontaje y montaje de todos ellos y revisión manual de su estado',
    '11039' => 'Revisión del tornillo situado en primera posición del rack y en caso de desgaste, sustituir',
];
$tareasPuertas = [
    '10669' => 'LIMPIEZA INTERIOR RACK. Sin restos de vidrios y protecciones en perfecto estado',
    '10670' => 'ESTADO FUNDAS. Que estén correctamente fijadas y no presenten roturas. En caso contrario sustituir',
    '10671' => 'ESTADO DE VARILLAS. Correcto paralelismo entre ellas',
    '10672' => 'ESTRUCTURA METALICA. Comprobar daños en patines, rejas, bastidor y  brazos separadores',
    '10673' => 'PROTECCIONES ENGOMADAS + BARRA TOPE LATERAL. Protecciones engomadas en buen estado y bien ancladas al chásis (remaches)',
    '10674' => 'BARRA DE CIERRE. Comprobar su funcionalidad, estado (doblada) y los pestillos de seguridad',
    '10675' => 'PEINES. Comprobar estado peines y correcta sujeción al chásis (tornillos)',
    '10689' => 'ESTADO PINTURA. Repintado de todas las zonas gastadas. Numeración del rack y pintado ralla identificativa según trimestre rosa, azul, morado o rojo',
    '10690' => 'PRESENCIA Y ESTADO  PORTADOCUMENTOS. Comprobar con un folio que se pueda ubicar.',
    '10691' => 'PATINES. Revisar que no presenta golpes y grietas.',
    '10692' => 'DIMENSIONADO RACK. Comprobación de geometría general del bastidor del rack, siguiendo las indicaciones del plano proporcionado.',
    '11024' => 'Revisión de todos los amortiguadores: Desmontaje y montaje de todos ellos y revisión manual de su estado',
];

// Grupo → desc grupo
$descGrupoPorCodigo = [
    '10167' => 'RACK CUSTODIAS - TRIMESTRAL',
    '10159' => 'RACK LUNETAS - TRIMESTRAL',
    '10163' => 'RACK PARABRISAS TRIMESTRAL',
    '10151' => 'RACK PUERTAS TRIMESTRAL',
];

// ── Detectar columnas mant_maquinas ──
$colNames = array_column(Db::pgFetchAll("
    SELECT column_name FROM information_schema.columns WHERE table_name = 'mant_maquinas'
"), 'column_name');
$hasGrupo  = in_array('grupo', $colNames, true);
$hasDescGr = in_array('desc_grupo', $colNames, true);

// Construir set de máquinas válidas (por nombre)
$nombresValidos = [];
foreach ($maquinas as $orden => [$nombre, $grupo]) {
    $nombresValidos[$nombre] = true;
}

echo "Máquinas en la lista: " . count($maquinas) . PHP_EOL;

// ── 1. ELIMINAR máquinas RACK% no listadas ──
$existentesRack = Db::pgFetchAll("
    SELECT cod_maquina_mant FROM mant_maquinas WHERE desc_maquina ILIKE 'RACK %'
");
$borradasMaq = 0; $borradasPlan = 0; $borradasComp = 0;
foreach ($existentesRack as $r) {
    $cod = (string)$r['cod_maquina_mant'];
    if (isset($nombresValidos[$cod])) continue;  // está en la lista, no borrar
    if ($apply) {
        $rComp = Db::pgExec("DELETE FROM mant_completions WHERE cod_maquina_mant = :c", [':c' => $cod]);
        $borradasComp += (int)$rComp;
        $rPlan = Db::pgExec("DELETE FROM mant_plan WHERE cod_maquina_mant = :c", [':c' => $cod]);
        $borradasPlan += (int)$rPlan;
        $rMaq = Db::pgExec("DELETE FROM mant_maquinas WHERE cod_maquina_mant = :c", [':c' => $cod]);
        $borradasMaq  += (int)$rMaq;
    } else {
        $borradasMaq++;
    }
}
echo "Máquinas RACK% no listadas a borrar: " . $borradasMaq . PHP_EOL;
echo "  · Filas mant_plan borradas: $borradasPlan\n";
echo "  · Filas mant_completions borradas: $borradasComp\n";

// ── 2. Helper genFechas trimestrales ──
function genFechasTrimestrales(string $desde, string $hasta): array {
    $fechas = [];
    $cursor = $desde;
    while ($cursor <= $hasta) {
        $fechas[] = $cursor;
        $cursor = date('Y-m-d', strtotime($cursor . ' +' . mt_rand(87, 93) . ' days'));
    }
    return $fechas;
}

// ── 3. Resolver tareas de cada familia por nombre ──
function familiaDeNombre(string $nombre): string {
    if (stripos($nombre, 'RACK CUSTODIAS') === 0) return 'CUSTODIAS';
    if (stripos($nombre, 'RACK LUNETAS')   === 0) return 'LUNETAS';
    if (stripos($nombre, 'RACK PARABRISAS')=== 0) return 'PARABRISAS';
    if (stripos($nombre, 'RACK PUERTAS')   === 0) return 'PUERTAS';
    return 'RACKS';
}

$tareasPorFamilia = [
    'CUSTODIAS'  => $tareasCustodias,
    'LUNETAS'    => $tareasLunetas,
    'PARABRISAS' => $tareasParabrisas,
    'PUERTAS'    => $tareasPuertas,
];

// ── 4. INSERTAR/actualizar máquinas + tareas + histórico ──
$hoy    = date('Y-m-d');
$inicio = '2025-09-01';

$insMaq = 0; $upMaq = 0;
$insTarea = 0;
$insInter = 0;

foreach ($maquinas as $orden => [$cod, $grupo]) {
    $desc      = $cod;
    $descGrupo = $descGrupoPorCodigo[$grupo] ?? 'RACKS';
    $fam       = familiaDeNombre($cod);
    $tareas    = $tareasPorFamilia[$fam] ?? [];

    // 4.1 mant_maquinas
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

    // 4.2 Generar visitas trimestrales: misma fecha, hora, operario para todas las tareas
    $fechasFpo = genFechasTrimestrales($inicio, $hoy);
    $visitas = [];
    foreach ($fechasFpo as $fpo) {
        $offset = mt_rand(-2, 2);
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

    // 4.3 Insertar tareas + marcas
    foreach ($tareas as $codTarea => $descTarea) {
        $teMin = mt_rand(20, 30);

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
                    activa          = EXCLUDED.activa,
                    alta_baja       = EXCLUDED.alta_baja,
                    tiempo_estimado = EXCLUDED.tiempo_estimado
            ", [
                ':o' => (string)$orden, ':t' => $codTarea,
                ':cm' => $cod, ':dm' => $desc,
                ':g'  => $grupo, ':dg' => 'RACKS',
                ':dt' => $descTarea,
                ':te' => $teMin,
            ]);
        }
        $insTarea++;

        // Marcas históricas
        $ultIntFecha = null;
        foreach ($visitas as $v) {
            $ultIntFecha = $v['fechaInt'];
            if ($apply) {
                try {
                    MaintenanceCompletionStore::add([
                        'tipo'                   => 'completada',
                        'orden'                  => (string)$orden,
                        'tarea'                  => $codTarea,
                        'cod_maquina_mant'       => $cod,
                        'desc_maquina'           => $desc,
                        'grupo'                  => $grupo,
                        'desc_grupo'             => 'RACKS',
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
                        'marcada_por'            => 'seed_reset_racks',
                        'tiempo_real_segundos'   => MaintenanceCompletionStore::aplicarDecalajeAleatorio($teMin * 60),
                    ]);
                    $insInter++;
                } catch (Throwable $e) { /* skip dup */ }
            } else {
                $insInter++;
            }
        }

        // Avanzar plan
        if ($apply && $ultIntFecha) {
            $proxima = date('Y-m-d', strtotime($ultIntFecha . ' +90 days'));
            Db::pgExec(
                "UPDATE mant_plan SET ultima_revision = :u, proxima_revision = :p
                  WHERE orden = :o AND tarea = :t",
                [':u' => $ultIntFecha, ':p' => $proxima, ':o' => (string)$orden, ':t' => $codTarea]
            );
        }
    }
}

echo str_repeat('─', 70) . PHP_EOL;
echo "Máquinas insertadas (nuevas): $insMaq\n";
echo "Máquinas ya existentes (actualizadas): $upMaq\n";
echo "Tareas insertadas o actualizadas: $insTarea\n";
echo "Intervenciones generadas: $insInter\n";
if (!$apply) {
    echo "\nPara aplicarlo:\n";
    echo "  php tools/mant_reset_racks_definitivo.php --apply\n";
}
