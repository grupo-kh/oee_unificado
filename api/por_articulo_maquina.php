<?php
/**
 * API: Detalle Plan vs Producido por Artículo.
 *
 * Misma definición de PA que el resto del panel:
 *   PA = SUM(min(prod_ok, plan) por artículo) / SUM(plan)
 *
 * Tres modos según parámetros:
 *   - maquina set         → desglose de esa máquina (rangeByMaquinaArticulo)
 *   - seccion set         → agregado de los artículos de esa sección
 *   - ni maquina ni seccion → agregado global (todas las máquinas VARILLAS+TROQUELADOS)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
require_once __DIR__ . '/../lib/PanelMetaBuilder.php';

try {
    $fecha = getParam('fecha');
    if ($fecha && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $fechaDesde = $fecha;
        $fechaHasta = $fecha;
    } else {
        $fechaDesde = getParam('fecha_desde', date('Y-m-d', strtotime('-1 day')));
        $fechaHasta = getParam('fecha_hasta', date('Y-m-d', strtotime('-1 day')));
    }
    $turno   = getParam('turno');
    $maquina = trim((string)getParam('maquina', ''));
    $seccion = strtoupper(trim((string)getParam('seccion', '')));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) jsonError('fecha_hasta inválida');
    if ($maquina !== '' && !isset(PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$maquina])) {
        jsonError('máquina no válida: ' . $maquina);
    }
    if ($seccion !== '' && !in_array($seccion, ['VARILLAS','TROQUELADOS'], true)) {
        $seccion = '';
    }

    ini_set('memory_limit', '2G');
    $turnosAgg = $turno && in_array($turno, ['M','T','N','C'], true)
        ? [$turno]
        : ['M','T','N'];

    if ($maquina !== '') {
        $rows = PlanAttainmentAgg::rangeByMaquinaArticulo($fechaDesde, $fechaHasta, $turnosAgg, $maquina);
        $scope = $maquina;
        $whitelist = 'Detalle filtrado a una sola máquina (lista extendida MAQUINA_TO_SECCION_EXT).';
    } else {
        $rows = PlanAttainmentAgg::rangeByArticulo($fechaDesde, $fechaHasta, $turnosAgg, $seccion);
        $scope = $seccion !== '' ? $seccion : 'GLOBAL';
        $whitelist = $seccion !== ''
            ? 'Detalle agregado de los artículos producidos por máquinas de la sección ' . $seccion . '.'
            : 'Detalle agregado de los artículos producidos por todas las máquinas (lista extendida VARILLAS+TROQUELADOS).';
    }

    $sumPlan = 0; $sumProd = 0; $sumAttain = 0;
    foreach ($rows as $r) {
        $sumPlan   += (float)($r['plan']   ?? 0);
        $sumProd   += (float)($r['prod']   ?? 0);
        $sumAttain += (float)($r['attain'] ?? 0);
    }
    $totales = [
        'plan'            => round($sumPlan, 0),
        'prod'            => round($sumProd, 0),
        'attain'          => round($sumAttain, 0),
        'plan_attainment' => $sumPlan > 0 ? round(($sumAttain / $sumPlan) * 100, 2) : 0,
    ];

    $meta = PanelMetaBuilder::buildPlanProdMeta([
        'panel'      => 'Detalle Plan vs Producido · ' . $scope,
        'fechaDesde' => $fechaDesde,
        'fechaHasta' => $fechaHasta,
        'turnos'     => $turnosAgg,
        'whitelist'  => $whitelist,
        'valores'    => ['plan' => $sumPlan, 'prod' => $sumProd, 'attain' => $sumAttain],
    ]);

    jsonOk([
        'rows'    => $rows,
        'totales' => $totales,
        'scope'   => $scope,
        'meta'    => $meta,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
