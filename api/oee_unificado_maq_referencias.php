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
               SUM(oee.Unidades_Total) AS total, SUM(oee.M) AS mseg,
               SUM(oee.M_OKNOK_TEO) AS MOT, SUM(oee.PPERF) AS PP, SUM(oee.PCALIDAD) AS PC
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

    // Productividad nominal (uds/h objetivo) por referencia en esta máquina, desde
    // Logicclass. Con fallback por tipo de máquina (gemelas comparten nominal).
    $nomCt = [];   // [articulo] = uds/hora nominal en este centro
    $nomTipo = []; // [articulo] = uds/hora nominal en máquinas del mismo tipo
    if (DB_LOGIC_HOST !== '' && !empty($cods)) {
        try {
            // Tipo de este centro y de todos (para el fallback).
            $tipoDe = []; $tipoCm = null;
            foreach (fetchAll('mapex', "SELECT LTRIM(RTRIM(Cod_maquina)) c, Id_tipomaquina t FROM cfg_maquina WHERE Id_tipomaquina IS NOT NULL") as $t) {
                $tipoDe[(string)$t['c']] = (int)$t['t'];
            }
            $tipoCm = $tipoDe[$cod] ?? null;
            // Nominal de todos los artículos producidos, por centro.
            foreach (array_chunk($cods, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $sqlN = "SELECT LTRIM(RTRIM(CentroTrabajo)) ct, LTRIM(RTRIM(codigoArticulo)) art,
                                MAX(CAST(UnidadesHora AS DECIMAL(10,0))) uh
                         FROM Oper_Formula
                         WHERE Operacion LIKE 'PRD%' AND UnidadesHora <> '0' AND codigoempresa = 1
                           AND LTRIM(RTRIM(codigoArticulo)) IN ($ph)
                         GROUP BY LTRIM(RTRIM(CentroTrabajo)), LTRIM(RTRIM(codigoArticulo))";
                foreach (fetchAll('logicclass', $sqlN, $chunk) as $n) {
                    $art = (string)$n['art']; $ct = (string)$n['ct']; $uh = (float)$n['uh'];
                    if ($ct === $cod) $nomCt[$art] = $uh;
                    $tp = $tipoDe[$ct] ?? null;
                    if ($tp !== null && $tp === $tipoCm) {
                        if (!isset($nomTipo[$art]) || $uh > $nomTipo[$art]) $nomTipo[$art] = $uh;
                    }
                }
            }
        } catch (\Throwable $e) { error_log('maq_referencias logicclass: ' . $e->getMessage()); }
    }

    $refs = [];
    foreach ($rows as $r) {
        $cr = (string)$r['cod_ref']; if ($cr === '') continue;
        $horas = ((float)$r['mseg']) / 3600;
        $M = (float)$r['mseg']; $MOT = (float)$r['MOT']; $PP = (float)$r['PP']; $PC = (float)$r['PC'];
        $rend = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
        $nom = $nomCt[$cr] ?? $nomTipo[$cr] ?? null;   // objetivo uds/h (centro o tipo)
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
            'rend'       => round($rend, 1),
            'objetivo'   => $nom !== null ? round($nom, 0) : null,
        ];
    }
    usort($refs, fn($a, $b) => $b['uds_total'] <=> $a['uds_total']);

    jsonOk(['cod_maquina' => $cod, 'referencias' => $refs]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
