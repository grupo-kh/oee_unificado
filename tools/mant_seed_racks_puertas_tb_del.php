<?php
/**
 * Inserta 16 RACK PUERTAS TB DEL (8 LH + 8 RH) en SECUENCIA/RACKS
 * MARCÁNDOLAS DESDE EL INICIO COMO PAUSADAS:
 *
 *   - activa='A', alta_baja='ALTA'        → aparecen en SECUENCIA/RACKS
 *   - fecha_pausado = hoy                 → no se planifican ni computan
 *   - tiempo_estimado 20..30 min          → coherente con el resto de PUERTAS
 *   - SIN histórico (mant_completions)    → "no se les ha hecho nada"
 *
 * El JS las pinta con badge naranja "⏸ PAUSADA" gracias al regex extendido
 *   /^RACK\s+PUERTAS\s+TB\s+(TRA|DEL)\s/
 *
 * Modos:
 *   php tools/mant_seed_racks_puertas_tb_del.php
 *     → DRY-RUN
 *   php tools/mant_seed_racks_puertas_tb_del.php --apply
 *     → ESCRITURA. IDEMPOTENTE: skip de máquinas/tareas ya presentes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

echo "Seed RACK PUERTAS TB DEL (pausadas) · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$hoy = date('Y-m-d');

// ── 16 máquinas con su orden ──
$maquinas = [
    // TB DEL LH (8)
    1146 => 'RACK PUERTAS TB DEL LH - 01',
    1147 => 'RACK PUERTAS TB DEL LH - 02',
    1148 => 'RACK PUERTAS TB DEL LH - 03',
    1149 => 'RACK PUERTAS TB DEL LH - 04',
    1150 => 'RACK PUERTAS TB DEL LH - 05',
    1152 => 'RACK PUERTAS TB DEL LH - 07',
    1153 => 'RACK PUERTAS TB DEL LH - 08',
    1155 => 'RACK PUERTAS TB DEL LH - 11',
    // TB DEL RH (8)
    1156 => 'RACK PUERTAS TB DEL RH - 01',
    1157 => 'RACK PUERTAS TB DEL RH - 02',
    1159 => 'RACK PUERTAS TB DEL RH - 04',
    1160 => 'RACK PUERTAS TB DEL RH - 05',
    1161 => 'RACK PUERTAS TB DEL RH - 06',
    1162 => 'RACK PUERTAS TB DEL RH - 07',
    1163 => 'RACK PUERTAS TB DEL RH - 08',
    1164 => 'RACK PUERTAS TB DEL RH - 09',
];

// ── 12 tareas TRIMESTRAL comunes a la familia PUERTAS ──
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

$grupo  = '10151';
$descGr = 'RACK PUERTAS TRIMESTRAL';

// ── Detectar columnas opcionales de mant_maquinas ──
$colNames = array_column(Db::pgFetchAll("
    SELECT column_name FROM information_schema.columns WHERE table_name = 'mant_maquinas'
"), 'column_name');
$hasGrupo  = in_array('grupo',      $colNames, true);
$hasDescGr = in_array('desc_grupo', $colNames, true);

$insMaq = 0; $upMaq = 0;
$insTarea = 0; $upTarea = 0;

foreach ($maquinas as $orden => $cod) {
    $desc = $cod;

    // 1. mant_maquinas (idempotente)
    $existe = (bool) Db::pgFetchOne(
        "SELECT 1 FROM mant_maquinas WHERE cod_maquina_mant = :c LIMIT 1",
        [':c' => $cod]
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

    // 2. mant_plan: 12 tareas TRIMESTRAL con fecha_pausado = hoy
    foreach ($tareasComunes as [$tarea, $descTarea]) {
        $teMin = mt_rand(20, 30);
        // Comprobamos existencia previa para contar correctamente.
        $tareaExiste = (bool) Db::pgFetchOne(
            "SELECT 1 FROM mant_plan WHERE orden = :o AND tarea = :t LIMIT 1",
            [':o' => (string)$orden, ':t' => $tarea]
        );

        if ($apply) {
            Db::pgExec("
                INSERT INTO mant_plan (
                    orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                    periodicidad, desc_tarea, activa, alta_baja,
                    tiempo_estimado, tipo_mantenimiento, fecha_pausado
                ) VALUES (
                    :o, :t, :cm, :dm, :g, :dg,
                    'TRIMESTRAL', :dt, 'A', 'ALTA',
                    :te, 'Preventivo', :fp
                )
                ON CONFLICT (orden, tarea) DO UPDATE SET
                    cod_maquina_mant = EXCLUDED.cod_maquina_mant,
                    desc_maquina     = EXCLUDED.desc_maquina,
                    grupo            = EXCLUDED.grupo,
                    desc_grupo       = EXCLUDED.desc_grupo,
                    desc_tarea       = EXCLUDED.desc_tarea,
                    periodicidad     = EXCLUDED.periodicidad,
                    activa           = 'A',
                    alta_baja        = 'ALTA',
                    tiempo_estimado  = EXCLUDED.tiempo_estimado,
                    fecha_pausado    = EXCLUDED.fecha_pausado
            ", [
                ':o'  => (string)$orden, ':t' => $tarea,
                ':cm' => $cod, ':dm' => $desc,
                ':g'  => $grupo, ':dg' => $descGr,
                ':dt' => $descTarea,
                ':te' => $teMin,
                ':fp' => $hoy,
            ]);
        }
        if ($tareaExiste) $upTarea++; else $insTarea++;
    }

    printf("  · %s (orden=%d) %s\n", $cod, $orden, $apply ? 'OK · pausada' : '[dry · pausada]');
}

echo str_repeat('═', 70) . PHP_EOL;
echo "Máquinas insertadas      : $insMaq" . PHP_EOL;
echo "Máquinas ya existían     : $upMaq" . PHP_EOL;
echo "Tareas insertadas        : $insTarea" . PHP_EOL;
echo "Tareas ya existían       : $upTarea" . PHP_EOL;
echo "(Sin marcas en mant_completions — máquinas pausadas desde el inicio)" . PHP_EOL;

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_seed_racks_puertas_tb_del.php --apply" . PHP_EOL;
}
