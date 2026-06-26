<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Reparto por MOTIVO de una máquina dentro de un bucket temporal concreto.
 * Espejo de oee_unificado_motivo_periodo_maquinas.php (que reparte un motivo por
 * máquinas): aquí se fija la MÁQUINA y se reparte su paro por motivos. Alimenta el
 * popup que se abre al clicar un punto del gráfico en la VISTA POR MÁQUINAS.
 *
 * El rango efectivo es la intersección entre el bucket (1 día / 7 días / 1 mes
 * desde `bucket`) y el intervalo global [fecha_desde, fecha_hasta].
 *
 * Filtros idénticos a la DISPONIBILIDAD de oee_unificado_v2 (solo excluye paro 11),
 * para que las horas cuadren con la pantalla principal.
 *
 * GET: fecha_desde, fecha_hasta (req), seccion, turnos (CSV),
 *      cod_maquina (req), bucket (req, YYYY-MM-DD inicio del bucket),
 *      granularidad (day|week|month, req).
 */
function maquinaPeriodoMotivosData(): array
{
    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');
    $seccion = strtoupper((string) getParam('seccion', ''));
    $gran    = (string) getParam('granularidad', 'day');
    $codMaq  = (string) getParam('cod_maquina', '');
    $bucket  = (string) getParam('bucket', '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) throw new Exception('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) throw new Exception('fecha_hasta inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bucket)) throw new Exception('bucket inválido');
    if (!in_array($gran, ['day','week','month'], true)) throw new Exception('granularidad inválida');
    if ($seccion !== '' && !in_array($seccion, ['VARILLAS','TROQUELADOS'], true)) throw new Exception('seccion inválida');
    if ($codMaq === '') throw new Exception('cod_maquina requerido');
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    // Ancho del bucket → fin (inclusive); reparto acotado a la intersección con el rango.
    $ini = new DateTime($bucket);
    $fin = clone $ini;
    if ($gran === 'day')       $fin->modify('+0 day');
    elseif ($gran === 'week')  $fin->modify('+6 days');
    else                       $fin->modify('last day of this month');
    $desde = max($bucket, $fdesde);
    $hasta = min($fin->format('Y-m-d'), $fhasta);

    // Filtros como la disponibilidad: solo excluye paro 11 (NO la actividad CERRADA).
    $where  = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "hpp.Fecha_fin IS NOT NULL",
        "mq.Cod_maquina = ?",
    ];
    $params = [$desde, $hasta, $codMaq];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    $sql = "
        SELECT
            COALESCE(NULLIF(LTRIM(RTRIM(cp.Desc_paro)), ''), '--') AS motivo,
            MAX(mq.Desc_maquina) AS maquina,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY cp.Desc_paro
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
    ";
    $rows = fetchAll('mapex', $sql, $params);

    $maquina = '';
    $motivos = [];
    $total = 0.0;
    foreach ($rows as $r) {
        $h = (int) $r['segundos'] / 3600.0;
        $maquina = (string) $r['maquina'] ?: $codMaq;
        $motivos[] = ['motivo' => (string) $r['motivo'], 'horas' => $h];
        $total += $h;
    }
    usort($motivos, fn($a, $b) => $b['horas'] <=> $a['horas']);
    foreach ($motivos as &$m) {
        $m['pct'] = $total > 0 ? round($m['horas'] / $total * 100, 1) : 0;
        $m['horas'] = round($m['horas'], 2);
    }
    unset($m);

    return [
        'cod_maquina'  => $codMaq,
        'maquina'      => $maquina ?: $codMaq,
        'bucket'       => $bucket,
        'granularidad' => $gran,
        'rango'        => ['desde' => $desde, 'hasta' => $hasta],
        'total_horas'  => round($total, 2),
        'motivos'      => $motivos,
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        jsonOk(maquinaPeriodoMotivosData());
    } catch (Exception $e) {
        jsonError('Error: ' . $e->getMessage(), 500);
    }
}
