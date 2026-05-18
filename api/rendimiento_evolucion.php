<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Evolución del Rendimiento: serie diaria de los últimos 8 días.
 * Filtros opcionales: cod_maquina, cod_articulo.
 */

function seccionDeDesc(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

try {
    $fechaHasta   = getParam('fecha', date('Y-m-d'));
    $turno        = getParam('turno');
    $cod_maquina  = getParam('cod_maquina');
    $cod_articulo = getParam('cod_articulo');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) jsonError('fecha inválida');
    $fechaDesde = date('Y-m-d', strtotime($fechaHasta . ' -7 days'));

    $whereCommon = [];
    $paramsCommon = [];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $whereCommon[] = "oee.Cod_turno = ?";
        $paramsCommon[] = $turno;
    }
    $whereCommon[] = "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";

    $whereEvo = $whereCommon;
    $paramsEvo = $paramsCommon;
    if ($cod_maquina)  { $whereEvo[] = "oee.WorkGroup = ?";    $paramsEvo[] = $cod_maquina; }
    if ($cod_articulo) { $whereEvo[] = "oee.Cod_producto = ?"; $paramsEvo[] = $cod_articulo; }

    $sqlEvo = "
        SELECT
            CAST(oee.TimePeriod AS DATE) AS fecha,
            SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO,
            SUM(oee.PPERF) AS PPERF, SUM(oee.PCALIDAD) AS PCALIDAD
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE " . implode(' AND ', $whereEvo) . "
        GROUP BY CAST(oee.TimePeriod AS DATE)
        ORDER BY fecha
    ";
    $rowsEvo = fetchAll('mapex', $sqlEvo, array_merge([$fechaDesde, $fechaHasta], $paramsEvo));

    $evolucion = [];
    foreach ($rowsEvo as $r) {
        $M = (float)$r['M']; $MOT = (float)$r['M_OKNOK_TEO'];
        $PP = (float)$r['PPERF']; $PC = (float)$r['PCALIDAD'];
        if ($M + $PP + $PC <= 0) continue;
        $fechaStr = $r['fecha'] instanceof DateTime ? $r['fecha']->format('Y-m-d') : $r['fecha'];
        $evolucion[] = [
            'fecha'       => $fechaStr,
            'rendimiento' => round(($MOT + $PC) / ($M + $PP + $PC) * 100, 2),
            'M_min'       => round($M),
            'PPERF_min'   => round($PP),
        ];
    }

    // Lista de máquinas
    $whereM = $whereCommon; $paramsM = $paramsCommon;
    if ($cod_articulo) { $whereM[] = "oee.Cod_producto = ?"; $paramsM[] = $cod_articulo; }
    $sqlM = "
        SELECT oee.WorkGroup AS cod_maquina, mq.Desc_maquina AS maquina
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE " . implode(' AND ', $whereM) . "
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PPERF) + SUM(oee.PCALIDAD) > 0
        ORDER BY mq.Desc_maquina, oee.WorkGroup
    ";
    $rowsM = fetchAll('mapex', $sqlM, array_merge([$fechaDesde, $fechaHasta], $paramsM));
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

    // Lista de artículos
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
        HAVING SUM(oee.M) + SUM(oee.PPERF) + SUM(oee.PCALIDAD) > 0
        ORDER BY oee.Cod_producto
    ";
    $rowsA = fetchAll('mapex', $sqlA, array_merge([$fechaDesde, $fechaHasta], $paramsA));
    $articles = [];
    $artInfo = null;
    foreach ($rowsA as $r) {
        $articles[] = ['cod_articulo' => $r['cod_articulo'], 'desc_articulo' => $r['desc_articulo'] ?? ''];
        if ($cod_articulo && $r['cod_articulo'] === $cod_articulo) {
            $artInfo = ['cod_articulo' => $r['cod_articulo'], 'desc_articulo' => $r['desc_articulo'] ?? ''];
        }
    }

    jsonOk([
        'fecha_desde'   => $fechaDesde,
        'fecha_hasta'   => $fechaHasta,
        'turno'         => $turno ?: null,
        'cod_maquina'   => $cod_maquina ?: null,
        'cod_articulo'  => $cod_articulo ?: null,
        'maquina_info'  => $maqInfo,
        'articulo_info' => $artInfo,
        'evolucion'     => $evolucion,
        'machines'      => $machines,
        'articles'      => $articles,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
