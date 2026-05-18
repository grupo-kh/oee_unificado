<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Drill-down de rendimiento por sección.
 * Devuelve dos listados (artículos y máquinas) para una sección concreta
 * (VARILLAS o TROQUELADOS), aplicando los mismos filtros que la vista
 * principal (fecha, turno, cod_maquina, cod_articulo).
 *
 * Fórmula:
 *   R = (M_OKNOK_TEO + PCALIDAD) / (M + PPERF + PCALIDAD)
 */

function seccionDeDescDetalle(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

function calcRDetalle(float $MOT, float $PC, float $M, float $PP): float {
    $den = $M + $PP + $PC;
    return $den > 0 ? ($MOT + $PC) / $den * 100 : 0;
}

try {
    $fecha        = getParam('fecha', date('Y-m-d'));
    $turno        = getParam('turno');
    $seccion      = getParam('seccion');
    $cod_maquina  = getParam('cod_maquina');
    $cod_articulo = getParam('cod_articulo');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');
    if (!in_array($seccion, ['VARILLAS', 'TROQUELADOS'], true)) jsonError('seccion inválida');

    $whereCommon = ["CAST(oee.TimePeriod AS DATE) = ?"];
    $paramsCommon = [$fecha];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $whereCommon[] = "oee.Cod_turno = ?";
        $paramsCommon[] = $turno;
    }
    $whereCommon[] = "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";
    if ($cod_maquina)  { $whereCommon[] = "oee.WorkGroup = ?";    $paramsCommon[] = $cod_maquina; }
    if ($cod_articulo) { $whereCommon[] = "oee.Cod_producto = ?"; $paramsCommon[] = $cod_articulo; }

    // --- Por máquina dentro de la sección ---
    $sqlMaq = "
        SELECT
            oee.WorkGroup AS cod_maquina,
            mq.Desc_maquina AS maquina,
            SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO,
            SUM(oee.PPERF) AS PPERF, SUM(oee.PCALIDAD) AS PCALIDAD
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE " . implode(' AND ', $whereCommon) . "
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PPERF) + SUM(oee.PCALIDAD) > 0
    ";
    $rowsMaq = fetchAll('mapex', $sqlMaq, array_merge([$fecha, $fecha], $paramsCommon));

    $maquinas = [];
    foreach ($rowsMaq as $r) {
        $sec = seccionDeDescDetalle($r['maquina']);
        if ($sec !== $seccion) continue;
        $M   = (float)$r['M'];
        $MOT = (float)$r['M_OKNOK_TEO'];
        $PP  = (float)$r['PPERF'];
        $PC  = (float)$r['PCALIDAD'];
        $maquinas[] = [
            'cod_maquina' => $r['cod_maquina'],
            'maquina'     => $r['maquina'] ?: $r['cod_maquina'],
            'rendimiento' => round(calcRDetalle($MOT, $PC, $M, $PP), 2),
            'M_min'       => round($M),
            'PPERF_min'   => round($PP),
        ];
    }
    usort($maquinas, fn($a, $b) => strcmp($a['maquina'], $b['maquina']));

    // --- Por artículo dentro de la sección ---
    $whereArt = $whereCommon;
    $whereArt[] = "oee.Cod_producto IS NOT NULL";
    $whereArt[] = "oee.Cod_producto <> '--'";
    $sqlArt = "
        SELECT
            oee.Cod_producto AS cod_articulo,
            MAX(oee.Desc_producto) AS desc_articulo,
            mq.Desc_maquina AS maquina,
            SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO,
            SUM(oee.PPERF) AS PPERF, SUM(oee.PCALIDAD) AS PCALIDAD
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE " . implode(' AND ', $whereArt) . "
        GROUP BY oee.Cod_producto, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PPERF) + SUM(oee.PCALIDAD) > 0
    ";
    $rowsArt = fetchAll('mapex', $sqlArt, array_merge([$fecha, $fecha], $paramsCommon));

    // Agregar por artículo restringido a la sección
    $artAcc = [];
    foreach ($rowsArt as $r) {
        $sec = seccionDeDescDetalle($r['maquina']);
        if ($sec !== $seccion) continue;
        $k = $r['cod_articulo'];
        if (!isset($artAcc[$k])) {
            $artAcc[$k] = [
                'cod_articulo'  => $r['cod_articulo'],
                'desc_articulo' => $r['desc_articulo'] ?? '',
                'M' => 0.0, 'MOT' => 0.0, 'PP' => 0.0, 'PC' => 0.0,
            ];
        }
        $artAcc[$k]['M']   += (float)$r['M'];
        $artAcc[$k]['MOT'] += (float)$r['M_OKNOK_TEO'];
        $artAcc[$k]['PP']  += (float)$r['PPERF'];
        $artAcc[$k]['PC']  += (float)$r['PCALIDAD'];
        if (empty($artAcc[$k]['desc_articulo']) && !empty($r['desc_articulo'])) {
            $artAcc[$k]['desc_articulo'] = $r['desc_articulo'];
        }
    }

    $articulos = [];
    foreach ($artAcc as $a) {
        $articulos[] = [
            'cod_articulo'  => $a['cod_articulo'],
            'desc_articulo' => $a['desc_articulo'],
            'rendimiento'   => round(calcRDetalle($a['MOT'], $a['PC'], $a['M'], $a['PP']), 2),
            'M_min'         => round($a['M']),
            'PPERF_min'     => round($a['PP']),
        ];
    }
    usort($articulos, fn($a, $b) => strcmp((string)$a['cod_articulo'], (string)$b['cod_articulo']));

    jsonOk([
        'fecha'        => $fecha,
        'turno'        => $turno ?: null,
        'seccion'      => $seccion,
        'cod_maquina'  => $cod_maquina ?: null,
        'cod_articulo' => $cod_articulo ?: null,
        'articulos'    => $articulos,
        'maquinas'     => $maquinas,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
