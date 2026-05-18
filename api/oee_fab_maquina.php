<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * OEE FAB por Máquina (Night Letter – panel derecho).
 * Fuente: F_his_ct. WorkGroup = Cod_maquina; traducimos a Desc_maquina para mostrar.
 *
 * Fórmulas (transformacion.qvs):
 *   D = M / (M + PNP)
 *   R = (M_OKNOK_TEO + PCALIDAD) / (M + PPERF + PCALIDAD)
 *   C = M_OK_TEO / (M_OKNOK_TEO + PCALIDAD)
 *   OEE = D × R × C
 *
 * Filtro: turno (M/T/N) opcional; día único (fecha productiva).
 */

try {
    $fecha = getParam('fecha', date('Y-m-d'));
    $turno = getParam('turno');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');

    $where  = ["CAST(oee.TimePeriod AS DATE) = ?"];
    $params = [$fecha];

    if ($turno && in_array($turno, ['M','T','N'])) {
        $where[] = "oee.Cod_turno = ?";
        $params[] = $turno;
    }
    // Excluir workgroups administrativos
    $where[] = "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";
    $whereSQL = implode(' AND ', $where);

    $sql = "
        WITH agg AS (
            SELECT
                oee.WorkGroup AS cod_maquina,
                SUM(oee.M)            AS M,
                SUM(oee.M_Teo)        AS M_Teo,
                SUM(oee.M_OKNOK_TEO)  AS M_OKNOK_TEO,
                SUM(oee.M_OK_TEO)     AS M_OK_TEO,
                SUM(oee.PPERF)        AS PPERF,
                SUM(oee.PCALIDAD)     AS PCALIDAD,
                SUM(oee.PNP)          AS PNP
            FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                          ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
            WHERE $whereSQL
            GROUP BY oee.WorkGroup
        )
        SELECT
            a.cod_maquina,
            mq.Desc_maquina AS maquina,
            a.M, a.M_Teo, a.M_OKNOK_TEO, a.M_OK_TEO,
            a.PPERF, a.PCALIDAD, a.PNP
        FROM agg a
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = a.cod_maquina
        WHERE a.M + a.PNP > 0   -- solo máquinas con tiempo programado
    ";

    $allParams = array_merge([$fecha, $fecha], $params);
    $rows = fetchAll('mapex', $sql, $allParams);

    $out = [];
    foreach ($rows as $r) {
        $M    = (float)$r['M'];
        $MT   = (float)$r['M_Teo'];
        $MOT  = (float)$r['M_OKNOK_TEO'];
        $MOKT = (float)$r['M_OK_TEO'];
        $PP   = (float)$r['PPERF'];
        $PC   = (float)$r['PCALIDAD'];
        $PNP  = (float)$r['PNP'];

        $d = ($M + $PNP) > 0         ? $M / ($M + $PNP) * 100       : 0;
        $p = ($M + $PP + $PC) > 0    ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
        $c = ($MOT + $PC) > 0        ? $MOKT / ($MOT + $PC) * 100   : 0;
        $oee = $d * $p * $c / 10000;

        $out[] = [
            'cod_maquina'   => $r['cod_maquina'],
            'maquina'       => $r['maquina'] ?: $r['cod_maquina'],
            'disponibilidad'=> round($d, 2),
            'rendimiento'   => round($p, 2),
            'calidad'       => round($c, 2),
            'oee'           => round($oee, 2),
            'M_Teo'         => (int)$MT,
        ];
    }

    // Orden por OEE ascendente (igual que QV: peores a la izquierda, mejores a la derecha)
    usort($out, fn($a, $b) => $a['oee'] <=> $b['oee']);

    jsonOk($out);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
