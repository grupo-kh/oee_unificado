<?php
/**
 * API: Plan Attainment global (gauge grande + 4 métricas OEE).
 *
 * Plan Attainment (gauge principal) — réplica de QW (Produccion_Planificacion):
 *   PA = SUM(Unidades_OK producidas) / SUM(Unidades_Planificadas del Excel)
 *   en el rango (fecha_desde..fecha_hasta) × turnos seleccionados.
 *
 * Las 4 métricas secundarias (Disponibilidad / Rendimiento / Calidad / OEE)
 * siguen calculándose desde F_his_ct (OEE clásico de MAPEX).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
require_once __DIR__ . '/../lib/PanelMetaBuilder.php';

try {
    // Plan Attainment se mira por día concreto (como QW).
    // Si viene "fecha" (single day), úsalo como rango [fecha, fecha].
    // Compatibilidad hacia atrás: acepta fecha_desde/fecha_hasta si alguien los envía.
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

    // ========= Plan Attainment real (prod_ok / plan) =========
    ini_set('memory_limit', '2G');
    $turnosAgg = $turno && in_array($turno, ['M','T','N','C'], true)
        ? [$turno]
        : ['M','T','N'];          // TODOS = M+T+N (QW no tenía CENTRAL)
    $agg = PlanAttainmentAgg::rangeTotals($fechaDesde, $fechaHasta, $turnosAgg);
    // Plan Attainment estricto: sum(min(prod, plan) por artículo) / sum(plan)
    // — la extraproducción no compensa artículos no cumplidos.
    $planAttainment = $agg['attain_pct'];

    // ========= Métricas OEE (sin cambios) =========
    $where  = ["CAST(TimePeriod AS DATE) BETWEEN ? AND ?"];
    $params = [$fechaDesde, $fechaHasta];

    if ($turno && in_array($turno, ['M', 'T', 'N'])) {
        $where[] = "Cod_turno = ?";
        $params[] = $turno;
    }
    $where[] = "WorkGroup NOT IN ('Improductivos','AUX000','SOLD5','AUXI1','SOLD4')";
    $whereSQL = implode(' AND ', $where);

    // F_his_ct alinea TimePeriod a la fecha de inicio de la ventana (param 16).
    // Usamos día calendario completo [fecha 00:00, fecha 23:59] para que
    // TimePeriod caiga en el rango [fechaDesde, fechaHasta] esperado por el WHERE.
    $startDT = $fechaDesde . ' 00:00:00';
    $endDT   = $fechaHasta . ' 23:59:59';

    // Fórmulas OEE del modelo MAPEX (según transformacion.qvs / OEE docs):
    //   DISPONIBILIDAD = M / (M + PNP)
    //   RENDIMIENTO    = (M_OKNOK_TEO + PCALIDAD) / (M + PPERF + PCALIDAD)
    //   CALIDAD        = M_OK_TEO / (M_OKNOK_TEO + PCALIDAD)
    //   OEE            = D × R × C
    $sql = "
        SELECT
            SUM(M)           AS M,
            SUM(PNP)         AS PNP,
            SUM(PPERF)       AS PPERF,
            SUM(PCALIDAD)    AS PCALIDAD,
            SUM(M_OK_TEO)    AS M_OK_TEO,
            SUM(M_OKNOK_TEO) AS M_OKNOK_TEO
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS', ?, ?, 16)
        WHERE $whereSQL
    ";

    $finalParams = array_merge([$startDT, $endDT], $params);
    $rows = fetchAll('mapex', $sql, $finalParams);
    $row  = $rows[0] ?? [];

    $M        = (float)($row['M']           ?? 0);
    $PNP      = (float)($row['PNP']         ?? 0);
    $PPERF    = (float)($row['PPERF']       ?? 0);
    $PCALIDAD = (float)($row['PCALIDAD']    ?? 0);
    $MOK      = (float)($row['M_OK_TEO']    ?? 0);
    $MP       = (float)($row['M_OKNOK_TEO'] ?? 0);

    $denDisp = $M + $PNP;
    $denRend = $M + $PPERF + $PCALIDAD;
    $denCal  = $MP + $PCALIDAD;

    $disp = $denDisp > 0 ? $M / $denDisp : 0;
    $rend = $denRend > 0 ? ($MP + $PCALIDAD) / $denRend : 0;
    $cal  = $denCal  > 0 ? $MOK / $denCal : 0;
    $oee  = $disp * $rend * $cal;

    $meta = PanelMetaBuilder::buildPlanProdMeta([
        'panel'      => 'Cumplimiento Global',
        'fechaDesde' => $fechaDesde,
        'fechaHasta' => $fechaHasta,
        'turnos'     => $turnosAgg,
        'whitelist'  => 'Map_FiltroMaquina (DOBL1-11, SOLD1/3/6, TROQ3) — el whitelist oficial de QV',
        'valores'    => [
            'plan'   => $agg['plan'],
            'prod'   => $agg['prod'],
            'attain' => $agg['attain'],
        ],
        'extras' => [
            [
                'titulo' => 'Métricas OEE secundarias (no cruzan Excel)',
                'items'  => [
                    ['label' => 'Fuente',  'value' => 'F_his_ct(\'WORKCENTER\',\'DAY\',\'TURNOS, PRODUCTOS\', …) en MAPEX'],
                    ['label' => 'Fórmula', 'value' => 'Disponibilidad = M/(M+PNP) · Rendimiento = (M_OKNOK_TEO+PCALIDAD)/(M+PPERF+PCALIDAD) · Calidad = M_OK_TEO/(M_OKNOK_TEO+PCALIDAD) · OEE = D×R×C'],
                ],
            ],
        ],
    ]);

    jsonOk([
        'plan_attainment' => round($planAttainment * 100, 2),
        'disponibilidad'  => round($disp * 100, 2),
        'calidad'         => round($cal  * 100, 2),
        'rendimiento'     => round($rend * 100, 2),
        'oee'             => round($oee  * 100, 2),
        'meta'  => $meta,
        'debug' => [
            'plan_total' => round($agg['plan'], 0),
            'prod_total' => round($agg['prod'], 0),
            'attain'     => round($agg['attain'], 0),
            'turnos'     => $turnosAgg,
            'desde'      => $fechaDesde,
            'hasta'      => $fechaHasta,
        ]
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
