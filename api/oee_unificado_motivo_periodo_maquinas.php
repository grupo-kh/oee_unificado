<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Reparto por máquina de un motivo de paro dentro de un bucket temporal concreto.
 * Alimenta el popup que se abre al clicar un punto del gráfico de evolución de
 * motivos. El rango efectivo del reparto es la intersección entre el bucket
 * (1 día / 7 días / 1 mes desde `bucket`) y el intervalo global [fecha_desde,
 * fecha_hasta], para no contar días fuera del intervalo en buckets de borde.
 *
 * Filtros idénticos a Matriz 2 (excluye paro 11 y actividad 1) + el motivo dado.
 *
 * GET: fecha_desde, fecha_hasta (req), seccion, turnos (CSV),
 *      motivo (req, Desc_paro), bucket (req, YYYY-MM-DD inicio del bucket),
 *      granularidad (day|week|month, req).
 */
function motivoPeriodoMaquinasData(): array
{
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');
    $seccion = strtoupper((string) getParam('seccion', ''));
    $gran = (string) getParam('granularidad', 'day');
    $motivo = (string) getParam('motivo', '');
    $bucket = (string) getParam('bucket', '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) throw new Exception('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) throw new Exception('fecha_hasta inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bucket)) throw new Exception('bucket inválido');
    if (!in_array($gran, ['day','week','month'], true)) throw new Exception('granularidad inválida');
    if ($seccion !== '' && !in_array($seccion, ['VARILLAS','TROQUELADOS'], true)) throw new Exception('seccion inválida');
    if ($motivo === '') throw new Exception('motivo requerido');
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    // Ancho del bucket → fin (inclusive). El reparto se acota a la intersección
    // con el intervalo global.
    $ini = new DateTime($bucket);
    $fin = clone $ini;
    if ($gran === 'day')       $fin->modify('+0 day');
    elseif ($gran === 'week')  $fin->modify('+6 days');
    else                       $fin->modify('last day of this month');
    $desde = max($bucket, $fdesde);
    $hasta = min($fin->format('Y-m-d'), $fhasta);

    $where  = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "cp.Id_actividad <> 1",
        "hpp.Fecha_fin IS NOT NULL",
        "cp.Desc_paro = ?",
    ];
    $params = [$desde, $hasta, $motivo];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    $sql = "
        SELECT
            mq.Cod_maquina  AS cod_maquina,
            mq.Desc_maquina AS maquina,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY mq.Cod_maquina, mq.Desc_maquina
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
    ";
    $rows = fetchAll('mapex', $sql, $params);

    $maquinas = [];
    $total = 0.0;
    foreach ($rows as $r) {
        $maq = (string) $r['maquina'];
        if ($seccion !== '' && (PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$maq] ?? null) !== $seccion) continue;
        $h = (int) $r['segundos'] / 3600.0;
        $maquinas[] = ['cod_maquina' => (string) $r['cod_maquina'], 'maquina' => $maq ?: (string) $r['cod_maquina'], 'horas' => $h];
        $total += $h;
    }
    usort($maquinas, fn($a, $b) => $b['horas'] <=> $a['horas']);
    foreach ($maquinas as &$m) {
        $m['pct'] = $total > 0 ? round($m['horas'] / $total * 100, 1) : 0;
        $m['horas'] = round($m['horas'], 2);
    }
    unset($m);

    return [
        'motivo'       => $motivo,
        'bucket'       => $bucket,
        'granularidad' => $gran,
        'rango'        => ['desde' => $desde, 'hasta' => $hasta],
        'total_horas'  => round($total, 2),
        'maquinas'     => $maquinas,
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        jsonOk(motivoPeriodoMaquinasData());
    } catch (Exception $e) {
        jsonError('Error: ' . $e->getMessage(), 500);
    }
}
