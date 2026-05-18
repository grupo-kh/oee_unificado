<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
require_once __DIR__ . '/../lib/PanelMetaBuilder.php';

try {
    $fechaDesde = getParam('fecha_desde', date('Y-m-d', strtotime('-6 days')));
    $fechaHasta = getParam('fecha_hasta', date('Y-m-d'));
    $turno      = getParam('turno');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) jsonError('fecha_hasta inválida');

    $turnos = ($turno && in_array($turno, ['M','T','N']))
        ? [$turno]
        : ['M','T','N'];

    $rows = [];
    $d = new DateTime($fechaDesde);
    $dHasta = new DateTime($fechaHasta);
    while ($d <= $dHasta) {
        $ymd = $d->format('Y-m-d');
        $r = PlanAttainmentAgg::rangeTotals($ymd, $ymd, $turnos);
        $plan = (float)($r['plan'] ?? 0);
        if ($plan > 0) {
            $rows[] = [
                'fecha'           => $ymd,
                'plan_attainment' => round(((float)($r['attain_pct'] ?? 0)) * 100, 2),
                'plan'            => $plan,
                'producido'       => (float)($r['prod'] ?? 0),
            ];
        }
        $d->modify('+1 day');
    }

    $sumPlan = 0; $sumProd = 0;
    foreach ($rows as $r) {
        $sumPlan += (float)($r['plan']      ?? 0);
        $sumProd += (float)($r['producido'] ?? 0);
    }
    $meta = PanelMetaBuilder::buildPlanProdMeta([
        'panel'      => 'Evolución del Plan Attainment',
        'fechaDesde' => $fechaDesde,
        'fechaHasta' => $fechaHasta,
        'turnos'     => $turnos,
        'whitelist'  => 'Map_FiltroMaquina (DOBL1-11, SOLD1/3/6, TROQ3) — el whitelist oficial de QV.',
        'valores'    => ['plan' => $sumPlan, 'prod' => $sumProd],
        'extras' => [
            [
                'titulo' => 'Notas específicas de este panel',
                'notas'  => [
                    'Cada punto del gráfico es el PA agregado de ese día y los turnos seleccionados.',
                    'Los días sin plan (sábados/domingos sin Excel, festivos) se omiten del eje temporal.',
                    'Para cada día se aplica la misma lógica de selección de fichero Excel que en Cumplimiento Global.',
                ],
            ],
        ],
    ]);

    jsonOk(['rows' => $rows, 'meta' => $meta]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
