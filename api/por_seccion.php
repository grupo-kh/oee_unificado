<?php
/**
 * API: Cumplimiento por Sección (VARILLAS, TROQUELADOS).
 *
 * Misma definición de Plan Attainment que api/plan_attainment.php:
 *   PA = SUM(min(prod_ok, plan) por artículo) / SUM(plan)
 * con whitelist Map_FiltroMaquina + weekend handling (Map_Dias) + BT agrupada.
 *
 * Agregación por día productivo + turno (como QW), no por rango 7d.
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
    $turno = getParam('turno');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) jsonError('fecha_hasta inválida');

    ini_set('memory_limit', '2G');
    $turnosAgg = parseTurnos();

    $rows = PlanAttainmentAgg::rangeBySeccion($fechaDesde, $fechaHasta, $turnosAgg);

    $sumPlan = 0; $sumProd = 0; $sumAttain = 0;
    foreach ($rows as $r) {
        $sumPlan   += (float)($r['plan_total'] ?? 0);
        $sumProd   += (float)($r['prod_total'] ?? 0);
        $sumAttain += (float)($r['attain']     ?? 0);
    }
    $meta = PanelMetaBuilder::buildPlanProdMeta([
        'panel'      => 'Cumplimiento por Sección',
        'fechaDesde' => $fechaDesde,
        'fechaHasta' => $fechaHasta,
        'turnos'     => $turnosAgg,
        'whitelist'  => 'Map_FiltroMaquina (DOBL1-11, SOLD1/3/6, TROQ3) — sólo máquinas del plan oficial. Sección VARILLAS o TROQUELADOS según MAQUINA_TO_SECCION.',
        'valores'    => ['plan' => $sumPlan, 'prod' => $sumProd, 'attain' => $sumAttain],
    ]);

    jsonOk(['rows' => $rows, 'meta' => $meta]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
