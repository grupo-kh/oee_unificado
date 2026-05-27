<?php
/**
 * Crea la familia TROLEYS dentro de SECUENCIA:
 *
 *   - TROLEY CUSTODIAS                 → nº 1, 2, 3, 6, 7, 8, 9, 10, 11, 12, 13, 14, 16, 17 (14 troleys)
 *     5 tareas SEMESTRAL: 10984, 10985, 10986, 10987, 10988
 *
 *   - TROLEY PUERTAS                   → nº 1..13 (13 troleys)
 *     5 tareas SEMESTRAL: 10984, 10985, 10986, 10987, 10988
 *
 *   - TROLEY PARABRISAS / LUNETAS      → nº 1, 2, 4, 5, 6, 7, 9, 10, 11, 12, 13, 15, 16 (13 troleys)
 *     7 tareas SEMESTRAL: 10984, 10985, 10986, 10987, 10988, 10989, 10990
 *
 * Familia / grupo: 1290 · "TROLEY SEMESTRAL"
 *
 * Histórico semestral desde 2025-09-01 hasta hoy. Visitas consolidadas:
 * todas las sub-tareas de un troley en la misma fecha+hora+operario.
 * Días no hábiles (CV) se desplazan al día hábil anterior.
 * tiempo_estimado 20..30 min. tiempo_real ±5..10 s.
 *
 * Operario: no es un rack pero sí SECUENCIA. Repartido entre los 8
 * activos sin preferencia (Juan no tiene prioridad aquí).
 *
 * Modos:
 *   php tools/mant_seed_troleys.php           → DRY-RUN
 *   php tools/mant_seed_troleys.php --apply   → ESCRITURA
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply = in_array('--apply', $argv, true);

echo "Seed TROLEYS · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// Operarios activos (todos los 8)
$activos = array_map(fn($r) => (string)$r['numero'],
    Db::pgFetchAll("SELECT numero FROM mant_operarios WHERE COALESCE(activo,TRUE)=TRUE"));
if (!$activos) { fwrite(STDERR, "Sin operarios activos.\n"); exit(3); }

// Helpers
function horaTurno(): string {
    $r = mt_rand(1,100);
    $h = $r <= 50 ? mt_rand(14,21) : ($r <= 85 ? mt_rand(6,13) : (mt_rand(0,1)===0 ? mt_rand(22,23) : mt_rand(0,5)));
    return sprintf('%02d:%02d', $h, mt_rand(0,59));
}

// ── Definición ──
$grupo  = '1290';
$descGr = 'TROLEY SEMESTRAL';

$tareasCustodias = [
    ['10984', 'Estado Horquilla de arrastre y sujeccion a troley'],
    ['10985', 'Comprobar buen estado de pletinas limitadoras de rack (Soldadura, daños, rotura)'],
    ['10986', 'Comprobar buen estado de las ruedas (Desgaste y rotura) y que rueda sin estable sin vibracion.'],
    ['10987', 'Revisar numeracion del troley'],
    ['10988', 'Comprobar que no está dañado por golpes.'],
];
$tareasPuertas = [
    ['10984', 'Comprobar que el gancho del troley se desplaza por la guia sin problema, estado del gancho, eje y giro.'],
    ['10985', 'Comprobar buen estado de pletinas limitadoras de rack (Soldadura, daños, rotura)'],
    ['10986', 'Comprobar buen estado de las ruedas (Desgaste y rotura) y que rueda sin estable sin vibracion.'],
    ['10987', 'Revisar numeracion del troley'],
    ['10988', 'Comprobar que no está dañado por golpes.'],
];
$tareasParabrisas = [
    ['10984', 'Comprobar buen estado de Horquilla de anclaje y sujeccion a troley'],
    ['10985', 'Comprobar buen estado de pletinas limitadoras de rack (Soldadura, daños, rotura)'],
    ['10986', 'Comprobar buen estado de las ruedas (Desgaste y rotura) y que rueda sin estable sin vibracion.'],
    ['10987', 'Revisar numeracion del troley'],
    ['10988', 'Comprobar que no está dañado por golpes.'],
    ['10989', 'Comprobar palanca accionamiento gatillo, barra blocante de giro, muelle recuperacion de posicion. (En estado normal de trabajo, sin roturas y golpes aparentes, observando que pueden cambiar de zona verde a roja, sin problema)'],
    ['10990', 'Comprobacion del giro del rack sobre la plataforma (Comprobar que en posicion Roja libera el giro, pudiendo rotar libremente y sin esfuerzo, y en posicion Verde el sistema queda bloqueado al pasar por la zona de bloqueo)'],
];

// Numeración indicada por el usuario
$nCustodias  = [1, 2, 3, 6, 7, 8, 9, 10, 11, 12, 13, 14, 16, 17];   // 14
$nPuertas    = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13];          // 13
$nParabrisas = [1, 2, 4, 5, 6, 7, 9, 10, 11, 12, 13, 15, 16];        // 13

// Asignamos órdenes únicos. Reservamos rangos:
//   CUSTODIAS  1224..1237   (14 órdenes)
//   PUERTAS    1238..1250   (13 órdenes)  ← evitando 1257 (Tice K0)
//   PARABRISAS 1260..1272   (13 órdenes)
//
// El user dijo 1224 / 1227 / 1229 como "número base" del troley, pero
// internamente necesitamos un orden único por máquina+tarea. Usamos los
// rangos anteriores para evitar colisiones con otros seeds.
$grupos = [
    [
        'familia'    => 'TROLEY CUSTODIAS',
        'numeros'    => $nCustodias,
        'baseOrden'  => 1224,   // 1224, 1225, ..., 1237
        'tareas'     => $tareasCustodias,
    ],
    [
        'familia'    => 'TROLEY PUERTAS',
        'numeros'    => $nPuertas,
        'baseOrden'  => 1238,   // 1238..1250
        'tareas'     => $tareasPuertas,
    ],
    [
        'familia'    => 'TROLEY PARABRISAS / LUNETAS',
        'numeros'    => $nParabrisas,
        'baseOrden'  => 1260,   // 1260..1272
        'tareas'     => $tareasParabrisas,
    ],
];

// Detectar columnas opcionales
$colNames = array_column(Db::pgFetchAll("
    SELECT column_name FROM information_schema.columns WHERE table_name='mant_maquinas'
"), 'column_name');
$hasGrupo  = in_array('grupo',      $colNames, true);
$hasDescGr = in_array('desc_grupo', $colNames, true);

$hasTiempo = (bool) Db::pgFetchOne("
    SELECT 1 FROM information_schema.columns
     WHERE table_name='mant_completions' AND column_name='tiempo_real_segundos'
");

$hoy    = date('Y-m-d');
$inicio = '2025-09-01';
$cad    = 180;  // SEMESTRAL: 180 días

$nMaqIns = 0; $nMaqUpd = 0;
$nTarea  = 0;
$nMarcas = 0;

foreach ($grupos as $G) {
    $familia    = $G['familia'];
    $numeros    = $G['numeros'];
    $baseOrden  = $G['baseOrden'];
    $tareas     = $G['tareas'];

    echo PHP_EOL . "── $familia (" . count($numeros) . " troleys, " . count($tareas) . " tareas) ──" . PHP_EOL;

    $i = 0;
    foreach ($numeros as $num) {
        $orden = (string)($baseOrden + $i++);
        $cod   = sprintf('%s - %02d', $familia, $num);
        $desc  = $cod;

        // 1. mant_maquinas
        $existe = (bool) Db::pgFetchOne(
            "SELECT 1 FROM mant_maquinas WHERE cod_maquina_mant=:c LIMIT 1",
            [':c' => $cod]
        );
        if (!$existe) {
            if ($apply) {
                $cols = ['cod_maquina_mant','desc_maquina'];
                $vals = [':cod',':desc'];
                $params = [':cod'=>$cod, ':desc'=>$desc];
                if ($hasGrupo)  { $cols[]='grupo';      $vals[]=':gr';  $params[':gr']  = $grupo; }
                if ($hasDescGr) { $cols[]='desc_grupo'; $vals[]=':dgr'; $params[':dgr'] = $descGr; }
                Db::pgExec("INSERT INTO mant_maquinas (".implode(',',$cols).") VALUES (".implode(',',$vals).")", $params);
            }
            $nMaqIns++;
        } else {
            $nMaqUpd++;
        }

        // 2. Visitas semestrales (1 visita = misma fecha/operario/hora para todas las sub-tareas)
        $visitas = [];
        $cursor = $inicio;
        $jitter = 7;  // semestral con jitter ±7
        while ($cursor <= $hoy) {
            $off = mt_rand(-3, 3);
            $fi = date('Y-m-d', strtotime($cursor . ' ' . sprintf('%+d', $off) . ' days'));
            if ($fi > $hoy) $fi = $hoy;
            $fi = CalendarioLaboral::ajustarADiaHabil($fi, 'anterior');
            $visitas[] = [
                'fpo' => $cursor,
                'fi'  => $fi,
                'op'  => $activos[mt_rand(0, count($activos)-1)],
                'hora'=> horaTurno(),
            ];
            $cursor = date('Y-m-d', strtotime($cursor . ' +' . ($cad + mt_rand(-$jitter, $jitter)) . ' days'));
        }

        $ultFi = null;

        foreach ($tareas as [$tarea, $descTarea]) {
            $teMin = mt_rand(20, 30);

            // mant_plan UPSERT
            if ($apply) {
                Db::pgExec("
                    INSERT INTO mant_plan (
                        orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                        periodicidad, desc_tarea, activa, alta_baja,
                        tiempo_estimado, tipo_mantenimiento
                    ) VALUES (
                        :o, :t, :cm, :dm, :g, :dg,
                        'SEMESTRAL', :dt, 'A', 'ALTA',
                        :te, 'Preventivo'
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
                        fecha_pausado    = NULL
                ", [
                    ':o'=>$orden, ':t'=>$tarea, ':cm'=>$cod, ':dm'=>$desc,
                    ':g'=>$grupo, ':dg'=>$descGr,
                    ':dt'=>$descTarea, ':te'=>$teMin,
                ]);
            }
            $nTarea++;

            // Marcas históricas
            foreach ($visitas as $v) {
                $extId = $orden . '|' . $tarea . '|' . $v['fpo'];
                $ya = (bool) Db::pgFetchOne(
                    "SELECT 1 FROM mant_completions WHERE external_id=:e LIMIT 1",
                    [':e' => $extId]
                );
                if ($ya) continue;

                $teSeg = $teMin * 60 + (mt_rand(0,1) === 0 ? -1 : 1) * mt_rand(5, 10);

                $cols = "external_id,tipo,orden,tarea,cod_maquina_mant,desc_maquina,grupo,desc_grupo,periodicidad,desc_tarea,fecha_proxima_original,fecha_intervencion,hora_inicio,operario,observaciones,motivo_no_realizada,recuperada,recuperada_fecha,marcada_at,marcada_por";
                $vals = ":ext,'completada',:ord,:tar,:cm,:dm,:g,:dg,:per,:dt,:fpo,:fi,:hi,:op,:obs,:mot,FALSE,NULL,now(),:mp";
                $params = [
                    ':ext'=>$extId, ':ord'=>$orden, ':tar'=>$tarea,
                    ':cm'=>$cod, ':dm'=>$desc,
                    ':g'=>$grupo, ':dg'=>$descGr,
                    ':per'=>'SEMESTRAL', ':dt'=>$descTarea,
                    ':fpo'=>$v['fpo'], ':fi'=>$v['fi'], ':hi'=>$v['hora'], ':op'=>$v['op'],
                    ':obs'=>'', ':mot'=>'',
                    ':mp'=>'seed_troleys',
                ];
                if ($hasTiempo) {
                    $cols .= ",tiempo_real_segundos";
                    $vals .= ",:te";
                    $params[':te'] = max(60, $teSeg);
                }
                if ($apply) {
                    try {
                        Db::pgExec("INSERT INTO mant_completions ($cols) VALUES ($vals)", $params);
                        $nMarcas++;
                        $ultFi = $v['fi'];
                    } catch (Throwable $e) { /* skip */ }
                } else {
                    $nMarcas++;
                    $ultFi = $v['fi'];
                }
            }

            // Avanzar plan
            if ($apply && $ultFi) {
                $px = date('Y-m-d', strtotime($ultFi . ' +' . $cad . ' days'));
                Db::pgExec(
                    "UPDATE mant_plan SET ultima_revision = ?, proxima_revision = ? WHERE orden = ? AND tarea = ?",
                    [$ultFi, $px, $orden, $tarea]
                );
            }
        }
        printf("  · %s · orden=%s · %d visitas · %s\n", $cod, $orden, count($visitas), $apply ? 'OK' : '[dry]');
    }
}

echo str_repeat('═', 75) . PHP_EOL;
echo "Resumen:" . PHP_EOL;
echo "  Máquinas insertadas : $nMaqIns" . PHP_EOL;
echo "  Máquinas ya existían: $nMaqUpd" . PHP_EOL;
echo "  Tareas upserted     : $nTarea" . PHP_EOL;
echo "  Marcas creadas      : $nMarcas" . PHP_EOL;
if (!$apply) echo PHP_EOL . "Para aplicar:\n  php tools/mant_seed_troleys.php --apply\n";
