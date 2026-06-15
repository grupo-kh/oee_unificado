<?php
/**
 * API: Dashboard "Cumplimiento Global Producción" combinado.
 *
 * Devuelve en UNA SOLA respuesta los 4 bloques del panel:
 *   - gauge       (Plan Attainment global + métricas OEE de MAPEX)
 *   - por_seccion (VARILLAS / TROQUELADOS · whitelist estricta Map_FiltroMaquina)
 *   - evolucion   (PA diario en el rango)
 *   - por_maquina (whitelist extendida)
 *
 * Sustituye a 4 endpoints separados (plan_attainment.php + por_seccion.php +
 * evolucion.php + por_maquina.php). Gracias a la memoización in-process en
 * PlanAttainmentAgg::dayShiftDetail{,Ext}, los 4 cálculos comparten los
 * mismos datos en RAM → 1 lectura de disco por (fecha, turno) en vez de 4.
 *
 * Parámetros:
 *   - fecha_desde, fecha_hasta (YYYY-MM-DD)
 *   - turnos (CSV) o turno (single) — M/T/N/C
 *   - seccion (opcional) — VARILLAS / TROQUELADOS
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
require_once __DIR__ . '/../lib/PanelMetaBuilder.php';

try {
    ini_set('memory_limit', '2G');

    $fecha = getParam('fecha');
    if ($fecha && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $fechaDesde = $fecha;
        $fechaHasta = $fecha;
    } else {
        $fechaDesde = getParam('fecha_desde', date('Y-m-d', strtotime('-1 day')));
        $fechaHasta = getParam('fecha_hasta', date('Y-m-d', strtotime('-1 day')));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) jsonError('fecha_hasta inválida');

    $turnos = parseTurnos();

    $seccion = strtoupper((string)getParam('seccion', ''));
    if ($seccion !== '' && !in_array($seccion, ['VARILLAS','TROQUELADOS'], true)) {
        $seccion = '';
    }

    // ─────────────────────────────────────────────────────────────────
    // 1) POR SECCIÓN — primera pasada, calienta la cache in-memory
    // ─────────────────────────────────────────────────────────────────
    $bySec = PlanAttainmentAgg::rangeBySeccion($fechaDesde, $fechaHasta, $turnos);

    // ─────────────────────────────────────────────────────────────────
    // 2) GAUGE GLOBAL — usa o totales globales o totales de la sección
    // ─────────────────────────────────────────────────────────────────
    if ($seccion === '') {
        $aggGlobal = PlanAttainmentAgg::rangeTotals($fechaDesde, $fechaHasta, $turnos);
        $planAttainment = $aggGlobal['attain_pct'];
        $aggExposed = $aggGlobal;
    } else {
        $row = null;
        foreach ($bySec as $r) {
            if (strtoupper((string)($r['seccion'] ?? '')) === $seccion) { $row = $r; break; }
        }
        $planTot = $row ? (float)$row['plan_total'] : 0.0;
        $prodTot = $row ? (float)$row['prod_total'] : 0.0;
        $attTot  = $row ? (float)$row['attain']     : 0.0;
        $aggExposed = [
            'plan'       => $planTot,
            'prod'       => $prodTot,
            'attain'     => $attTot,
            'attain_pct' => $planTot > 0 ? ($attTot / $planTot) : 0,
        ];
        $planAttainment = $aggExposed['attain_pct'];
    }

    // ─────────────────────────────────────────────────────────────────
    // 3) POR MÁQUINA — cache extendida (otra ruta in-memory)
    // ─────────────────────────────────────────────────────────────────
    $byMaq = PlanAttainmentAgg::rangeByMaquina($fechaDesde, $fechaHasta, $turnos);

    // ─────────────────────────────────────────────────────────────────
    // 4) EVOLUCIÓN — día a día, reutilizando la memoria caliente
    // ─────────────────────────────────────────────────────────────────
    $evol = [];
    $d  = new DateTime($fechaDesde);
    $fh = new DateTime($fechaHasta);
    while ($d <= $fh) {
        $ymd = $d->format('Y-m-d');
        if ($seccion === '') {
            $r = PlanAttainmentAgg::rangeTotals($ymd, $ymd, $turnos);
            $plan = (float)($r['plan'] ?? 0);
            $pa   = (float)($r['attain_pct'] ?? 0);
            $prod = (float)($r['prod'] ?? 0);
        } else {
            $bs = PlanAttainmentAgg::rangeBySeccion($ymd, $ymd, $turnos);
            $plan = 0.0; $prod = 0.0; $att = 0.0;
            foreach ($bs as $rrow) {
                if (strtoupper((string)($rrow['seccion'] ?? '')) === $seccion) {
                    $plan = (float)$rrow['plan_total'];
                    $prod = (float)$rrow['prod_total'];
                    $att  = (float)$rrow['attain'];
                    break;
                }
            }
            $pa = $plan > 0 ? ($att / $plan) : 0;
        }
        if ($plan > 0) {
            $evol[] = [
                'fecha'           => $ymd,
                'plan_attainment' => round($pa * 100, 2),
                'plan'            => $plan,
                'producido'       => $prod,
            ];
        }
        $d->modify('+1 day');
    }

    // ─────────────────────────────────────────────────────────────────
    // 5) MÉTRICAS OEE (MAPEX F_his_ct) — 1 sola consulta SQL
    // ─────────────────────────────────────────────────────────────────
    $where  = ["CAST(TimePeriod AS DATE) BETWEEN ? AND ?"];
    $params = [$fechaDesde, $fechaHasta];

    // F_his_ct espera el "turno" como un único Cod_turno o sin filtro.
    // Con multi-turno, pasamos sin filtro y filtramos por la unión usando IN.
    if (count($turnos) === 1 && in_array($turnos[0], ['M','T','N'], true)) {
        $where[] = "Cod_turno = ?";
        $params[] = $turnos[0];
    } elseif (count($turnos) > 1 && count($turnos) < 4) {
        $turnosVal = array_values(array_filter($turnos, fn($t) => in_array($t, ['M','T','N'], true)));
        if (count($turnosVal) > 0) {
            $ph = implode(',', array_fill(0, count($turnosVal), '?'));
            $where[] = "Cod_turno IN ($ph)";
            $params = array_merge($params, $turnosVal);
        }
    }
    $where[] = "WorkGroup NOT IN ('Improductivos','AUX000','SOLD5','AUXI1','SOLD4')";

    if ($seccion !== '') {
        $maquinasSec = [];
        foreach (PlanAttainmentAgg::MAQUINA_TO_SECCION as $maq => $sec) {
            if ($sec === $seccion) $maquinasSec[] = $maq;
        }
        if (count($maquinasSec) > 0) {
            $ph = implode(',', array_fill(0, count($maquinasSec), '?'));
            $where[] = "WorkGroup IN ($ph)";
            $params = array_merge($params, $maquinasSec);
        } else {
            $where[] = "1 = 0";
        }
    }
    $whereSQL = implode(' AND ', $where);
    $startDT = $fechaDesde . ' 00:00:00';
    $endDT   = $fechaHasta . ' 23:59:59';
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
    $oeeRows = fetchAll('mapex', $sql, $finalParams);
    $oeeRow  = $oeeRows[0] ?? [];

    $M        = (float)($oeeRow['M']           ?? 0);
    $PNP      = (float)($oeeRow['PNP']         ?? 0);
    $PPERF    = (float)($oeeRow['PPERF']       ?? 0);
    $PCALIDAD = (float)($oeeRow['PCALIDAD']    ?? 0);
    $MOK      = (float)($oeeRow['M_OK_TEO']    ?? 0);
    $MP       = (float)($oeeRow['M_OKNOK_TEO'] ?? 0);

    $denDisp = $M + $PNP;
    $denRend = $M + $PPERF + $PCALIDAD;
    $denCal  = $MP + $PCALIDAD;
    $disp = $denDisp > 0 ? $M / $denDisp : 0;
    $rend = $denRend > 0 ? ($MP + $PCALIDAD) / $denRend : 0;
    $cal  = $denCal  > 0 ? $MOK / $denCal : 0;
    $oee  = $disp * $rend * $cal;

    // ─────────────────────────────────────────────────────────────────
    // 6) META
    // ─────────────────────────────────────────────────────────────────
    $meta = PanelMetaBuilder::buildPlanProdMeta([
        'panel'      => 'Cumplimiento Global Producción',
        'fechaDesde' => $fechaDesde,
        'fechaHasta' => $fechaHasta,
        'turnos'     => $turnos,
        'whitelist'  => 'Map_FiltroMaquina (DOBL1-11, SOLD1/3/6, TROQ3) — whitelist oficial de QV. Sección VARILLAS/TROQUELADOS según MAQUINA_TO_SECCION.',
        'valores'    => [
            'plan'   => $aggExposed['plan'],
            'prod'   => $aggExposed['prod'],
            'attain' => $aggExposed['attain'],
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
        'gauge' => [
            'plan_attainment' => round($planAttainment * 100, 2),
            'disponibilidad'  => round($disp * 100, 2),
            'calidad'         => round($cal  * 100, 2),
            'rendimiento'     => round($rend * 100, 2),
            'oee'             => round($oee  * 100, 2),
            'meta'            => $meta,
        ],
        'por_seccion' => $bySec,
        'evolucion'   => $evol,
        'por_maquina' => $byMaq,
        'debug' => [
            'fecha_desde' => $fechaDesde,
            'fecha_hasta' => $fechaHasta,
            'turnos'      => $turnos,
            'seccion'     => $seccion ?: null,
        ],
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
