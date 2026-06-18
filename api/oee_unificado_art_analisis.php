<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Análisis histórico de OFs de un artículo (réplica del cuadro QlikView).
 * Por OF (×máquina): Año, Mes, Cod_OF, Desc_maquina, Rend_C, Horas perdidas,
 * Horas dedicadas y Pzas/Hora real. Totales: unidades, horas dedicadas y perdidas.
 *
 * Mapeo QlikView (F_his_ct):
 *   HORAS_DEDICADAS = M / 3600
 *   HORAS_PERDIDAS  = (M_Teo - M) / 3600   (negativo cuando el real supera al teórico)
 *   Pzas_Hora_Real  = Unidades_Total / (M / 3600)
 *   Rend_C          = (M_OKNOK_TEO + PCALIDAD) / (M + PPERF + PCALIDAD) · 100
 *
 * GET: cod_producto (req), fecha_desde, fecha_hasta (req), turnos (CSV M,T,N opt)
 * Devuelve: { cod_producto, desc_producto,
 *             totales: { unidades, horas_dedicadas, horas_perdidas },
 *             ofs: [{ anio, mes, cod_of, cod_maquina, desc_maquina, cod_articulo,
 *                     rend_c, horas_perdidas, horas_dedicadas, pzas_hora_real,
 *                     unidades, ok, nok }] }
 */
try {
    $cod    = trim((string)($_GET['cod_producto'] ?? ''));
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');
    if ($cod === '') jsonError('cod_producto requerido');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    $where  = ["CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?", "LTRIM(RTRIM(oee.Cod_producto)) = ?"];
    $params = [$fdesde, $fhasta, $cod];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "oee.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    $sql = "
        SELECT oee.Cod_OF AS cod_of, oee.WorkGroup AS cod_maquina,
               MAX(oee.Desc_producto) AS desc_prod,
               MIN(oee.Fecha_inicio) AS fecha_ini, MAX(oee.Fecha_fin) AS fecha_fin,
               SUM(oee.Unidades_OK) AS ok, SUM(oee.Unidades_NOK) AS nok, SUM(oee.Unidades_Total) AS total,
               SUM(oee.Unidades_planning) AS planif,
               SUM(oee.M) AS M, SUM(oee.M_Teo) AS MTEO,
               SUM(oee.M_OKNOK_TEO) AS MOT, SUM(oee.PPERF) AS PP, SUM(oee.PCALIDAD) AS PC
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, WO, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE " . implode(' AND ', $where) . "
        GROUP BY oee.Cod_OF, oee.WorkGroup
        HAVING SUM(oee.M) > 0
    ";
    $rows = fetchAll('mapex', $sql, array_merge([$fdesde, $fhasta], $params));

    // Descripción de máquina por código (F_his_ct no expone Desc_maquina con este breakdown).
    $maqDesc = [];
    try {
        foreach (fetchAll('mapex', "SELECT LTRIM(RTRIM(Cod_maquina)) AS c, Desc_maquina AS d FROM cfg_maquina") as $m)
            $maqDesc[(string)$m['c']] = trim((string)$m['d']);
    } catch (\Throwable $e) { /* fallback al código */ }

    // Productividad nominal (pzs/h) por (CentroTrabajo, codigoArticulo) desde
    // Logicclass — réplica de la 3ª conexión del QlikView (Oper_Formula.UnidadesHora).
    // Tolerante a fallo: si Logicclass no está configurado o no es alcanzable,
    // se continúa solo con datos de MAPEX (udsHora quedará null).
    $udsHoraNom = []; // [cod_maquina][cod_articulo] = uds/hora nominal
    if (DB_LOGIC_HOST !== '') {
        try {
            $sqlNom = "
                SELECT LTRIM(RTRIM(CentroTrabajo)) AS ct,
                       LTRIM(RTRIM(codigoArticulo)) AS art,
                       CAST(UnidadesHora AS DECIMAL(10,0)) AS uh
                FROM Oper_Formula
                WHERE CentroTrabajo <> ' '
                  AND (Operacion = 'PRD CNF' OR Operacion = 'PRD SLD' OR Operacion = 'PRD TRQ')
                  AND UnidadesHora <> '0'
                  AND codigoempresa = 1
                  AND LTRIM(RTRIM(codigoArticulo)) = ?
            ";
            foreach (fetchAll('logicclass', $sqlNom, [$cod]) as $n) {
                $udsHoraNom[(string)$n['ct']][(string)$n['art']] = (float)$n['uh'];
            }
        } catch (\Throwable $e) {
            error_log('art_analisis logicclass: ' . $e->getMessage());
        }
    }

    $ofs = [];
    $totUds = 0; $totMded = 0.0; $totMperd = 0.0; $desc = '';
    foreach ($rows as $r) {
        $M = (float)$r['M']; $MTEO = (float)$r['MTEO']; $MOT = (float)$r['MOT'];
        $PP = (float)$r['PP']; $PC = (float)$r['PC'];
        $horasDed = $M / 3600;
        $horasPerd = ($MTEO - $M) / 3600;
        $rend = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
        $total = (int)$r['total'];
        $pzasH = $horasDed > 0 ? $total / $horasDed : 0;
        // Rendimiento en piezas/hora (fórmula sobre M/3600): OK y NOK por hora.
        $okH  = $horasDed > 0 ? round((float)$r['ok']  / $horasDed, 1) : 0;
        $nokH = $horasDed > 0 ? round((float)$r['nok'] / $horasDed, 1) : 0;
        $ini = (string)$r['fecha_ini'];
        $fin = (string)$r['fecha_fin'];
        if ($desc === '') $desc = trim((string)$r['desc_prod']);
        $cm = trim((string)$r['cod_maquina']);
        // Productividad nominal de Logicclass para esta máquina+artículo (si existe).
        $nom = $udsHoraNom[$cm][$cod] ?? null;
        $pctNom = ($nom && $nom > 0) ? round($pzasH / $nom * 100, 1) : null;
        // Piezas que se "deberían" haber hecho:
        //  - plan      = Unidades_planning (planificadas para la OF).
        //  - teoricas  = piezas teóricas alcanzables en el tiempo dedicado al
        //                ritmo nominal (uds/h Logicclass × horas dedicadas).
        $plan     = (int)round((float)$r['planif']);
        $teoricas = ($nom && $nom > 0) ? (int)round($nom * $horasDed) : null;
        $pctVsTeo = ($teoricas && $teoricas > 0) ? round($total / $teoricas * 100, 1) : null;
        $pctVsPlan= ($plan > 0) ? round($total / $plan * 100, 1) : null;
        $totUds += $total; $totMded += $M; $totMperd += ($MTEO - $M);
        $ofs[] = [
            'anio'              => (int)substr($ini, 0, 4),
            'mes'               => (int)substr($ini, 5, 2),
            'cod_of'            => (string)$r['cod_of'],
            'cod_maquina'       => $cm,
            'desc_maquina'      => $maqDesc[$cm] ?? $cm,
            'cod_articulo'      => $cod,
            'fecha_ini'         => substr($ini, 0, 19),
            'fecha_fin'         => $fin !== '' ? substr($fin, 0, 19) : null,
            'rend_c'            => round($rend, 1),
            'horas_perdidas'    => round($horasPerd, 2),
            'horas_dedicadas'   => round($horasDed, 2),
            'pzas_hora_real'    => round($pzasH, 2),
            'pzas_hora_nominal' => $nom !== null ? round($nom, 0) : null,
            'pct_nominal'       => $pctNom,
            'unidades'          => $total,
            'plan'              => $plan,
            'uds_teoricas'      => $teoricas,
            'pct_vs_teoricas'   => $pctVsTeo,
            'pct_vs_plan'       => $pctVsPlan,
            'ok'                => (int)$r['ok'],
            'nok'               => (int)$r['nok'],
            'ok_h'              => $okH,
            'nok_h'             => $nokH,
        ];
    }
    // Orden cronológico (año, mes, fecha) como en el cuadro QlikView.
    usort($ofs, fn($a, $b) => strcmp($a['fecha_ini'], $b['fecha_ini']));

    // Media de uds fabricadas/hora del conjunto: total unidades / total horas
    // dedicadas (ponderada por horas, no media simple de ratios por OF).
    $totHorasDed = $totMded / 3600;
    $mediaUdsHora = $totHorasDed > 0 ? round($totUds / $totHorasDed, 1) : 0;

    jsonOk([
        'cod_producto'  => $cod,
        'desc_producto' => $desc,
        'totales'       => [
            'unidades'        => $totUds,
            'horas_dedicadas' => round($totMded / 3600, 1),
            'horas_perdidas'  => round($totMperd / 3600, 1),
            'media_uds_hora'  => $mediaUdsHora,
        ],
        'ofs' => $ofs,
    ]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
