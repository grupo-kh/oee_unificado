<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Referencias fabricadas en una máquina en el rango (sección Rendimiento).
 * Por referencia: piezas/hora OK y NOK (Unidades / (M/3600)).
 *
 * GET: cod_maquina (req), fecha_desde, fecha_hasta (req), turnos (CSV M,T,N opt)
 * Devuelve: { cod_maquina, referencias: [{ cod, desc, nom_sage, uds_ok, uds_nok,
 *             uds_total, horas, ok_h, nok_h }] }
 */
try {
    $cod    = trim((string)($_GET['cod_maquina'] ?? ''));
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');
    if ($cod === '') jsonError('cod_maquina requerido');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    $where  = ["CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?", "oee.WorkGroup = ?"];
    $params = [$fdesde, $fhasta, $cod];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "oee.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    $sql = "
        SELECT LTRIM(RTRIM(oee.Cod_producto)) AS cod_ref, MAX(oee.Desc_producto) AS desc_ref,
               SUM(oee.Unidades_OK) AS ok, SUM(oee.Unidades_NOK) AS nok,
               SUM(oee.Unidades_Total) AS total, SUM(oee.M) AS mseg
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, WO, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE " . implode(' AND ', $where) . "
        GROUP BY LTRIM(RTRIM(oee.Cod_producto))
        HAVING SUM(oee.Unidades_Total) > 0
    ";
    $rows = fetchAll('mapex', $sql, array_merge([$fdesde, $fhasta], $params));

    // Nomenclatura SAGE (ReferenciaEdi_) por código.
    $cods = array_values(array_filter(array_map(fn($r) => (string)$r['cod_ref'], $rows), fn($c) => $c !== ''));
    $sageNom = [];
    if (!empty($cods)) {
        try {
            foreach (array_chunk($cods, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                foreach (fetchAll('sage',
                    "SELECT LTRIM(RTRIM(CodigoArticulo)) AS c, ReferenciaEdi_ AS edi
                     FROM Articulos WHERE LTRIM(RTRIM(CodigoArticulo)) IN ($ph)", $chunk) as $a)
                    $sageNom[(string)$a['c']] = trim((string)$a['edi']);
            }
        } catch (\Throwable $e) { /* SAGE no disponible */ }
    }

    $refs = [];
    foreach ($rows as $r) {
        $cr = (string)$r['cod_ref']; if ($cr === '') continue;
        $horas = ((float)$r['mseg']) / 3600;
        $refs[] = [
            'cod'        => $cr,
            'desc'       => trim((string)($r['desc_ref'] ?: $cr)),
            'nom_sage'   => $sageNom[$cr] ?? '',
            'uds_ok'     => (int)$r['ok'],
            'uds_nok'    => (int)$r['nok'],
            'uds_total'  => (int)$r['total'],
            'horas'      => round($horas, 2),
            'ok_h'       => $horas > 0 ? round($r['ok']  / $horas, 1) : 0,
            'nok_h'      => $horas > 0 ? round($r['nok'] / $horas, 1) : 0,
        ];
    }
    usort($refs, fn($a, $b) => $b['uds_total'] <=> $a['uds_total']);

    jsonOk(['cod_maquina' => $cod, 'referencias' => $refs]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
