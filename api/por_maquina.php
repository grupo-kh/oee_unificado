<?php
/**
 * API: Cumplimiento por Máquina.
 *
 * Misma definición de Plan Attainment que api/plan_attainment.php / por_seccion.php:
 *   PA = SUM(min(prod_ok, plan) por artículo) / SUM(plan)
 * con whitelist Map_FiltroMaquina + weekend handling + BT agrupada (DOBL6+DOBL7).
 * Agregación por día productivo + turno.
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

    $rows = PlanAttainmentAgg::rangeByMaquina($fechaDesde, $fechaHasta, $turnosAgg);

    $sumPlan = 0; $sumProd = 0; $sumAttain = 0;
    foreach ($rows as $r) {
        $sumPlan   += (float)($r['plan_total'] ?? 0);
        $sumProd   += (float)($r['prod_total'] ?? 0);
        $sumAttain += (float)($r['attain']     ?? 0);
    }
    $meta = PanelMetaBuilder::buildPlanProdMeta([
        'panel'      => 'Cumplimiento por Máquina',
        'fechaDesde' => $fechaDesde,
        'fechaHasta' => $fechaHasta,
        'turnos'     => $turnosAgg,
        'whitelist'  => 'Whitelist EXTENDIDA: VARILLAS + TROQUELADOS, incluye TBE30, TBE35, TBE RAPIDFORM, PRENSA 3D N1/N2, MONTAJE AUTOMATICO, etc. (más allá del Map_FiltroMaquina oficial de QV).',
        'valores'    => ['plan' => $sumPlan, 'prod' => $sumProd, 'attain' => $sumAttain],
    ]);

    jsonOk(['rows' => $rows, 'meta' => $meta]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
