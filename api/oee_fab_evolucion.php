<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Evolución OEE de Fabricación: serie diaria de los últimos 7 días
 * con D / R / C / OEE agregados.
 *
 * Acepta cod_maquina opcional para filtrar a una sola máquina.
 * Devuelve también la lista de máquinas disponibles en la ventana
 * para poblar el selector.
 */

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
    $fechaHasta  = getParam('fecha', date('Y-m-d'));
    $turno       = getParam('turno');
    $cod_maquina = getParam('cod_maquina');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) jsonError('fecha inválida');
    $fechaDesde = date('Y-m-d', strtotime($fechaHasta . ' -6 days'));

    // --- Serie temporal (7 días) ---
    $whereEvo = [];
    $paramsEvo = [];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $whereEvo[] = "oee.Cod_turno = ?";
        $paramsEvo[] = $turno;
    }
    if ($cod_maquina) {
        $whereEvo[] = "oee.WorkGroup = ?";
        $paramsEvo[] = $cod_maquina;
    }
    $whereEvo[] = "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";
    $whereEvoSQL = implode(' AND ', $whereEvo);

    $sqlEvo = "
        SELECT
            CAST(oee.TimePeriod AS DATE) AS fecha,
            SUM(oee.M) AS M, SUM(oee.M_Teo) AS M_Teo,
            SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO, SUM(oee.M_OK_TEO) AS M_OK_TEO,
            SUM(oee.PPERF) AS PPERF, SUM(oee.PCALIDAD) AS PCALIDAD, SUM(oee.PNP) AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE $whereEvoSQL
        GROUP BY CAST(oee.TimePeriod AS DATE)
        ORDER BY fecha
    ";
    $allParamsEvo = array_merge([$fechaDesde, $fechaHasta], $paramsEvo);
    $evoRows = fetchAll('mapex', $sqlEvo, $allParamsEvo);

    $evolucion = [];
    foreach ($evoRows as $r) {
        $M = (float)$r['M']; $PNP = (float)$r['PNP'];
        if ($M + $PNP <= 0) continue; // solo días con tiempo programado
        $fechaStr = $r['fecha'] instanceof DateTime ? $r['fecha']->format('Y-m-d') : $r['fecha'];
        $drc = calcDRC($M, (float)$r['M_Teo'], (float)$r['M_OKNOK_TEO'],
                       (float)$r['M_OK_TEO'], (float)$r['PPERF'],
                       (float)$r['PCALIDAD'], $PNP);
        $evolucion[] = array_merge(['fecha' => $fechaStr], $drc);
    }

    // --- Lista de máquinas disponibles en la ventana (sin filtro cod_maquina) ---
    $whereList = [];
    $paramsList = [];
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
    $listRows = fetchAll('mapex', $sqlList, array_merge([$fechaDesde, $fechaHasta], $paramsList));
    $machines = [];
    $maquinaInfo = null;
    foreach ($listRows as $r) {
        $desc = $r['maquina'] ?: $r['cod_maquina'];
        $sec = seccionDeDesc($desc);
        $machines[] = ['cod_maquina' => $r['cod_maquina'], 'maquina' => $desc, 'seccion' => $sec];
        if ($cod_maquina && $r['cod_maquina'] === $cod_maquina) {
            $maquinaInfo = ['cod_maquina' => $r['cod_maquina'], 'maquina' => $desc, 'seccion' => $sec];
        }
    }

    jsonOk([
        'fecha_desde'  => $fechaDesde,
        'fecha_hasta'  => $fechaHasta,
        'turno'        => $turno ?: null,
        'cod_maquina'  => $cod_maquina ?: null,
        'maquina_info' => $maquinaInfo,
        'evolucion'    => $evolucion,
        'machines'     => $machines,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
