<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * OFs de una referencia fabricada en una máquina (sección Rendimiento).
 * Por OF: fecha, OEE %, piezas fabricadas, OK, NOK.
 *
 * GET: cod_maquina, cod_producto, fecha_desde, fecha_hasta (req), turnos (CSV opt)
 * Devuelve: { ofs: [{ cod_of, fecha, oee, piezas, ok, nok }] }
 */
try {
    $cod     = trim((string)($_GET['cod_maquina'] ?? ''));
    $codProd = trim((string)($_GET['cod_producto'] ?? ''));
    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');
    if ($cod === '' || $codProd === '') jsonError('cod_maquina y cod_producto requeridos');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    $where  = ["CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?", "oee.WorkGroup = ?", "LTRIM(RTRIM(oee.Cod_producto)) = ?"];
    $params = [$fdesde, $fhasta, $cod, $codProd];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "oee.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    $sql = "
        SELECT oee.Cod_OF AS cod_of, MIN(oee.Fecha_inicio) AS fecha,
               SUM(oee.Unidades_OK) AS ok, SUM(oee.Unidades_NOK) AS nok, SUM(oee.Unidades_Total) AS total,
               SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS MOT, SUM(oee.M_OK_TEO) AS MOKT,
               SUM(oee.PPERF) AS PP, SUM(oee.PCALIDAD) AS PC, SUM(oee.PNP) AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, WO, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE " . implode(' AND ', $where) . "
        GROUP BY oee.Cod_OF
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
    ";
    $rows = fetchAll('mapex', $sql, array_merge([$fdesde, $fhasta], $params));

    $ofs = [];
    foreach ($rows as $r) {
        $M = (float)$r['M']; $MOT = (float)$r['MOT']; $MOKT = (float)$r['MOKT'];
        $PP = (float)$r['PP']; $PC = (float)$r['PC']; $PNP = (float)$r['PNP'];
        $d = ($M + $PNP)      > 0 ? $M / ($M + $PNP) * 100               : 0;
        $rd= ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
        $c = ($MOT + $PC)     > 0 ? $MOKT / ($MOT + $PC) * 100            : 0;
        $oee = $d * $rd * $c / 10000;
        $ofs[] = [
            'cod_of' => (string)$r['cod_of'],
            'fecha'  => substr((string)$r['fecha'], 0, 19),
            'oee'    => round($oee, 1),
            'piezas' => (int)$r['total'],
            'ok'     => (int)$r['ok'],
            'nok'    => (int)$r['nok'],
        ];
    }
    usort($ofs, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));   // más reciente primero

    jsonOk(['ofs' => $ofs]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
