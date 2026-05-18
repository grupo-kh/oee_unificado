<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * OEE FAB Global + Por Sección (Night Letter – panel izquierdo).
 * Acepta opcionalmente cod_maquina para filtrar por una única máquina.
 *
 * Devuelve:
 *   - global: { D, R, C, OEE, M_Teo, maquinas }
 *   - secciones: [ {seccion, D, R, C, OEE, M_Teo, maquinas}, ... ]  (VARILLAS / TROQUELADOS)
 */

// Build Desc_maquina → Sección desde MAQUINA_TO_SECCION_EXT de PlanAttainmentAgg
function seccionDeDesc(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

function calcDRC(float $M, float $MT, float $MOT, float $MOKT, float $PP, float $PC, float $PNP): array {
    $d = ($M + $PNP) > 0         ? $M / ($M + $PNP) * 100       : 0;
    $p = ($M + $PP + $PC) > 0    ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
    $c = ($MOT + $PC) > 0        ? $MOKT / ($MOT + $PC) * 100   : 0;
    $oee = $d * $p * $c / 10000;
    return [
        'disponibilidad' => round($d, 2),
        'rendimiento'    => round($p, 2),
        'calidad'        => round($c, 2),
        'oee'            => round($oee, 2),
        'M_Teo'          => (int)$MT,
    ];
}

try {
    $fecha       = getParam('fecha', date('Y-m-d'));
    $turno       = getParam('turno');
    $cod_maquina = getParam('cod_maquina');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');

    $where = ["CAST(oee.TimePeriod AS DATE) = ?"];
    $params = [$fecha];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $where[] = "oee.Cod_turno = ?";
        $params[] = $turno;
    }
    if ($cod_maquina) {
        $where[] = "oee.WorkGroup = ?";
        $params[] = $cod_maquina;
    }
    $where[] = "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";
    $whereSQL = implode(' AND ', $where);

    // Agregar por máquina para luego poder filtrar/agrupar por sección en PHP
    $sql = "
        SELECT
            oee.WorkGroup AS cod_maquina,
            mq.Desc_maquina AS maquina,
            SUM(oee.M) AS M, SUM(oee.M_Teo) AS M_Teo,
            SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO, SUM(oee.M_OK_TEO) AS M_OK_TEO,
            SUM(oee.PPERF) AS PPERF, SUM(oee.PCALIDAD) AS PCALIDAD, SUM(oee.PNP) AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE $whereSQL
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
    ";
    $allParams = array_merge([$fecha, $fecha], $params);
    $rows = fetchAll('mapex', $sql, $allParams);

    // Lista completa de máquinas disponibles en la ventana (sin filtro de cod_maquina)
    // Se usa para el selector del frontend.
    $whereList = ["CAST(oee.TimePeriod AS DATE) = ?"];
    $paramsList = [$fecha];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $whereList[] = "oee.Cod_turno = ?";
        $paramsList[] = $turno;
    }
    $whereList[] = "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";
    $whereListSQL = implode(' AND ', $whereList);
    $sqlList = "
        SELECT
            oee.WorkGroup AS cod_maquina,
            mq.Desc_maquina AS maquina
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE $whereListSQL
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
        ORDER BY mq.Desc_maquina, oee.WorkGroup
    ";
    $listRows = fetchAll('mapex', $sqlList, array_merge([$fecha, $fecha], $paramsList));
    $machines = [];
    foreach ($listRows as $r) {
        $desc = $r['maquina'] ?: $r['cod_maquina'];
        $machines[] = [
            'cod_maquina' => $r['cod_maquina'],
            'maquina'     => $desc,
            'seccion'     => seccionDeDesc($desc),
        ];
    }

    // Agregador: global + por sección
    $globalAcc = ['M'=>0,'MT'=>0,'MOT'=>0,'MOKT'=>0,'PP'=>0,'PC'=>0,'PNP'=>0,'maquinas'=>0];
    $secAcc = []; // 'VARILLAS' => [...], 'TROQUELADOS' => [...]
    $maqSeleccionada = null;

    foreach ($rows as $r) {
        $M = (float)$r['M']; $MT = (float)$r['M_Teo'];
        $MOT = (float)$r['M_OKNOK_TEO']; $MOKT = (float)$r['M_OK_TEO'];
        $PP = (float)$r['PPERF']; $PC = (float)$r['PCALIDAD']; $PNP = (float)$r['PNP'];
        $sec = seccionDeDesc($r['maquina']);

        $globalAcc['M']+=$M; $globalAcc['MT']+=$MT; $globalAcc['MOT']+=$MOT;
        $globalAcc['MOKT']+=$MOKT; $globalAcc['PP']+=$PP; $globalAcc['PC']+=$PC;
        $globalAcc['PNP']+=$PNP; $globalAcc['maquinas']++;

        if ($sec) {
            if (!isset($secAcc[$sec])) {
                $secAcc[$sec] = ['M'=>0,'MT'=>0,'MOT'=>0,'MOKT'=>0,'PP'=>0,'PC'=>0,'PNP'=>0,'maquinas'=>0];
            }
            $secAcc[$sec]['M']+=$M; $secAcc[$sec]['MT']+=$MT; $secAcc[$sec]['MOT']+=$MOT;
            $secAcc[$sec]['MOKT']+=$MOKT; $secAcc[$sec]['PP']+=$PP; $secAcc[$sec]['PC']+=$PC;
            $secAcc[$sec]['PNP']+=$PNP; $secAcc[$sec]['maquinas']++;
        }
        if ($cod_maquina && $r['cod_maquina'] === $cod_maquina) {
            $maqSeleccionada = ['cod_maquina'=>$r['cod_maquina'], 'maquina'=>$r['maquina'], 'seccion'=>$sec];
        }
    }

    $global = calcDRC($globalAcc['M'],$globalAcc['MT'],$globalAcc['MOT'],
                      $globalAcc['MOKT'],$globalAcc['PP'],$globalAcc['PC'],$globalAcc['PNP']);
    $global['maquinas'] = $globalAcc['maquinas'];

    $secciones = [];
    foreach (['VARILLAS','TROQUELADOS'] as $sec) {
        if (!isset($secAcc[$sec])) {
            $secciones[] = ['seccion'=>$sec,'disponibilidad'=>0,'rendimiento'=>0,'calidad'=>0,'oee'=>0,'M_Teo'=>0,'maquinas'=>0];
            continue;
        }
        $a = $secAcc[$sec];
        $drc = calcDRC($a['M'],$a['MT'],$a['MOT'],$a['MOKT'],$a['PP'],$a['PC'],$a['PNP']);
        $drc['seccion'] = $sec;
        $drc['maquinas'] = $a['maquinas'];
        $secciones[] = $drc;
    }

    jsonOk([
        'fecha'             => $fecha,
        'turno'             => $turno ?: null,
        'cod_maquina'       => $cod_maquina ?: null,
        'maquina_info'      => $maqSeleccionada,
        'global'            => $global,
        'secciones'         => $secciones,
        'machines'          => $machines,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
