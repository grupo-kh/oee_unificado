<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Datos de disponibilidad de una OF: D/R/C/OEE, piezas y motivos de paro.
 *
 * GET: cod_of (req), fecha_desde, fecha_hasta (req), cod_maquina (opt), turnos (CSV opt)
 * Devuelve: { cod_of, producto, desc_producto, fecha_ini, fecha_fin, disp, rend, cal, oee,
 *             piezas, ok, nok, total_horas_paro, motivos: [{ motivo, horas, pct }] }
 */
try {
    $codOf  = trim((string)($_GET['cod_of'] ?? ''));
    $cod    = trim((string)($_GET['cod_maquina'] ?? ''));
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');
    if ($codOf === '') jsonError('cod_of requerido');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    // 1) D/R/C/OEE + piezas de la OF (F_his_ct).
    $w = ["CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?", "oee.Cod_OF = ?"];
    $p = [$fdesde, $fhasta, $codOf];
    if ($cod !== '') { $w[] = "oee.WorkGroup = ?"; $p[] = $cod; }
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $w[] = "oee.Cod_turno IN ($ph)"; $p = array_merge($p, $turnos);
    }
    $sql = "
        SELECT MAX(oee.Cod_producto) AS prod, MAX(oee.Desc_producto) AS dprod,
               MIN(oee.Fecha_inicio) AS ini, MAX(oee.Fecha_fin) AS fin,
               SUM(oee.Unidades_OK) AS ok, SUM(oee.Unidades_NOK) AS nok, SUM(oee.Unidades_Total) AS total,
               SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS MOT, SUM(oee.M_OK_TEO) AS MOKT,
               SUM(oee.PPERF) AS PP, SUM(oee.PCALIDAD) AS PC, SUM(oee.PNP) AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, WO, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE " . implode(' AND ', $w) . "
    ";
    $r = fetchAll('mapex', $sql, array_merge([$fdesde, $fhasta], $p));
    $h = $r[0] ?? [];
    $M = (float)($h['M'] ?? 0); $MOT = (float)($h['MOT'] ?? 0); $MOKT = (float)($h['MOKT'] ?? 0);
    $PP = (float)($h['PP'] ?? 0); $PC = (float)($h['PC'] ?? 0); $PNP = (float)($h['PNP'] ?? 0);
    $disp = ($M + $PNP)      > 0 ? $M / ($M + $PNP) * 100               : 0;
    $rend = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
    $cal  = ($MOT + $PC)     > 0 ? $MOKT / ($MOT + $PC) * 100            : 0;
    $oee  = $disp * $rend * $cal / 10000;

    // 2) Motivos de paro de la OF (his_prod_paro → his_of.Cod_of).
    $w2 = ["CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?", "o.Cod_of = ?", "cp.Cod_paro <> 11", "hpp.Fecha_fin IS NOT NULL"];
    $p2 = [$fdesde, $fhasta, $codOf];
    // Filtro horario opcional sobre la hora real del paro (hpp.Fecha_ini).
    $hDesde = (string) getParam('hora_desde', '');
    $hHasta = (string) getParam('hora_hasta', '');
    if ($hDesde !== '' && $hHasta !== '' && $hDesde !== $hHasta) {
        [$hSql, $hParams] = filtroFechaHora('hpp.Fecha_ini', $fdesde, $fhasta, $hDesde, $hHasta);
        $w2[] = $hSql; $p2 = array_merge($p2, $hParams);
    }
    if ($cod !== '') { $w2[] = "mq.Cod_maquina = ?"; $p2[] = $cod; }
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $w2[] = "ct.Cod_turno IN ($ph)"; $p2 = array_merge($p2, $turnos);
    }
    $sql2 = "
        SELECT cp.Desc_paro AS motivo, SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS seg
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        LEFT  JOIN his_fase    fa ON fa.Id_his_fase = hp.Id_his_fase
        LEFT  JOIN his_of      o  ON o.Id_his_of    = fa.Id_his_of
        WHERE " . implode(' AND ', $w2) . "
        GROUP BY cp.Desc_paro
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY seg DESC
    ";
    $rows = fetchAll('mapex', $sql2, $p2);
    $totSeg = 0; foreach ($rows as $x) $totSeg += (int)$x['seg'];
    $motivos = [];
    foreach ($rows as $x) {
        $seg = (int)$x['seg'];
        $motivos[] = [
            'motivo' => (string)$x['motivo'],
            'horas'  => round($seg / 3600, 2),
            'pct'    => $totSeg > 0 ? round($seg / $totSeg * 100, 1) : 0,
        ];
    }

    jsonOk([
        'cod_of'           => $codOf,
        'producto'         => trim((string)($h['prod'] ?? '')),
        'desc_producto'    => trim((string)($h['dprod'] ?? '')),
        'fecha_ini'        => substr((string)($h['ini'] ?? ''), 0, 19),
        'fecha_fin'        => substr((string)($h['fin'] ?? ''), 0, 19),
        'disp'             => round($disp, 1),
        'rend'             => round($rend, 1),
        'cal'              => round($cal, 1),
        'oee'              => round($oee, 1),
        'piezas'           => (int)($h['total'] ?? 0),
        'ok'               => (int)($h['ok'] ?? 0),
        'nok'              => (int)($h['nok'] ?? 0),
        'total_horas_paro' => round($totSeg / 3600, 2),
        'motivos'          => $motivos,
    ]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
