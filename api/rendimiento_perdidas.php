<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Pareto de Horas perdidas por falta de Rendimiento (PPERF) por máquina.
 * Filtros opcionales: cod_maquina, cod_articulo.
 *
 * Campo F_his_ct usado: PPERF (minutos perdidos por bajo rendimiento).
 */

function seccionDeDesc(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

try {
    $fecha        = getParam('fecha', date('Y-m-d'));
    $turno        = getParam('turno');
    $cod_maquina  = getParam('cod_maquina');
    $cod_articulo = getParam('cod_articulo');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');

    $whereCommon = ["CAST(oee.TimePeriod AS DATE) = ?"];
    $paramsCommon = [$fecha];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $whereCommon[] = "oee.Cod_turno = ?";
        $paramsCommon[] = $turno;
    }
    $whereCommon[] = "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";

    // --- Pareto por máquina ---
    $whereData = $whereCommon;
    $paramsData = $paramsCommon;
    if ($cod_maquina)  { $whereData[] = "oee.WorkGroup = ?";    $paramsData[] = $cod_maquina; }
    if ($cod_articulo) { $whereData[] = "oee.Cod_producto = ?"; $paramsData[] = $cod_articulo; }

    // Pérdida de rendimiento = M - M_OKNOK_TEO (tiempo real en marcha menos
    // tiempo teórico equivalente a velocidad nominal para lo producido).
    $sqlData = "
        SELECT
            oee.WorkGroup AS cod_maquina,
            mq.Desc_maquina AS maquina,
            SUM(oee.M) - SUM(oee.M_OKNOK_TEO) AS PPERF_min,
            SUM(oee.M) AS M_min,
            SUM(oee.M_OKNOK_TEO) AS MOT_min,
            SUM(oee.PCALIDAD) AS PC_min,
            SUM(oee.PPERF) AS PPERF_orig
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE " . implode(' AND ', $whereData) . "
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) - SUM(oee.M_OKNOK_TEO) > 0
        ORDER BY PPERF_min DESC
    ";
    $rowsData = fetchAll('mapex', $sqlData, array_merge([$fecha, $fecha], $paramsData));

    $totMin = 0;
    foreach ($rowsData as $r) $totMin += (float)$r['PPERF_min'];

    $perdidas = [];
    $acum = 0;
    foreach ($rowsData as $r) {
        // F_his_ct devuelve M y M_OKNOK_TEO en SEGUNDOS.
        $pp_seg = (float)$r['PPERF_min'];                 // M − M_OKNOK_TEO en segundos
        $ppOrig = (float)($r['PPERF_orig'] ?? 0);
        $M  = (float)$r['M_min']; $MOT = (float)$r['MOT_min']; $PC = (float)$r['PC_min'];
        $den = $M + $ppOrig + $PC;
        $rend = $den > 0 ? ($MOT + $PC) / $den * 100 : 0;
        $pct = $totMin > 0 ? $pp_seg / $totMin * 100 : 0;
        $acum += $pct;
        $desc = $r['maquina'] ?: $r['cod_maquina'];
        $perdidas[] = [
            'cod_maquina' => $r['cod_maquina'],
            'maquina'     => $desc,
            'seccion'     => seccionDeDesc($desc),
            'minutos'     => round($pp_seg / 60, 1),
            'horas'       => round($pp_seg / 3600, 2),
            'rendimiento' => round($rend, 2),
            'pct'         => round($pct, 2),
            'pct_acum'    => round(min($acum, 100), 2),
        ];
    }

    // --- Listas para selectores ---
    $whereM = $whereCommon; $paramsM = $paramsCommon;
    if ($cod_articulo) { $whereM[] = "oee.Cod_producto = ?"; $paramsM[] = $cod_articulo; }
    $sqlM = "
        SELECT oee.WorkGroup AS cod_maquina, mq.Desc_maquina AS maquina
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE " . implode(' AND ', $whereM) . "
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) - SUM(oee.M_OKNOK_TEO) > 0
        ORDER BY mq.Desc_maquina, oee.WorkGroup
    ";
    $rowsM = fetchAll('mapex', $sqlM, array_merge([$fecha, $fecha], $paramsM));
    $machines = [];
    $maqInfo = null;
    foreach ($rowsM as $r) {
        $desc = $r['maquina'] ?: $r['cod_maquina'];
        $sec = seccionDeDesc($desc);
        $machines[] = ['cod_maquina' => $r['cod_maquina'], 'maquina' => $desc, 'seccion' => $sec];
        if ($cod_maquina && $r['cod_maquina'] === $cod_maquina) {
            $maqInfo = ['cod_maquina' => $r['cod_maquina'], 'maquina' => $desc, 'seccion' => $sec];
        }
    }

    $whereA = $whereCommon; $paramsA = $paramsCommon;
    if ($cod_maquina) { $whereA[] = "oee.WorkGroup = ?"; $paramsA[] = $cod_maquina; }
    $whereA[] = "oee.Cod_producto IS NOT NULL";
    $whereA[] = "oee.Cod_producto <> '--'";
    $sqlA = "
        SELECT oee.Cod_producto AS cod_articulo, MAX(oee.Desc_producto) AS desc_articulo
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE " . implode(' AND ', $whereA) . "
        GROUP BY oee.Cod_producto
        HAVING SUM(oee.M) - SUM(oee.M_OKNOK_TEO) > 0
        ORDER BY oee.Cod_producto
    ";
    $rowsA = fetchAll('mapex', $sqlA, array_merge([$fecha, $fecha], $paramsA));
    $articles = [];
    $artInfo = null;
    foreach ($rowsA as $r) {
        $articles[] = ['cod_articulo' => $r['cod_articulo'], 'desc_articulo' => $r['desc_articulo'] ?? ''];
        if ($cod_articulo && $r['cod_articulo'] === $cod_articulo) {
            $artInfo = ['cod_articulo' => $r['cod_articulo'], 'desc_articulo' => $r['desc_articulo'] ?? ''];
        }
    }

    jsonOk([
        'fecha'         => $fecha,
        'turno'         => $turno ?: null,
        'cod_maquina'   => $cod_maquina ?: null,
        'cod_articulo'  => $cod_articulo ?: null,
        'maquina_info'  => $maqInfo,
        'articulo_info' => $artInfo,
        'total_minutos' => round($totMin / 60, 1),
        'total_horas'   => round($totMin / 3600, 2),
        'perdidas'      => $perdidas,
        'machines'      => $machines,
        'articles'      => $articles,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
