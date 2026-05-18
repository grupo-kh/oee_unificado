<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Drill-down de disponibilidad por sección.
 * Devuelve dos listados (artículos y máquinas) para una sección concreta
 * (VARILLAS o TROQUELADOS), aplicando los mismos filtros que la vista
 * principal (fecha, turno, cod_maquina, cod_articulo).
 *
 * Fórmula:
 *   D = M / (M + PNP)
 */

function seccionDeDescDispDetalle(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

function calcDDetalle(float $M, float $PNP): float {
    return ($M + $PNP) > 0 ? $M / ($M + $PNP) * 100 : 0;
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
            SUM(oee.M) AS M, SUM(oee.PNP) AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE " . implode(' AND ', $whereCommon) . "
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
    ";
    $rowsMaq = fetchAll('mapex', $sqlMaq, array_merge([$fecha, $fecha], $paramsCommon));

    $maquinas = [];
    foreach ($rowsMaq as $r) {
        $sec = seccionDeDescDispDetalle($r['maquina']);
        if ($sec !== $seccion) continue;
        $M   = (float)$r['M'];
        $PNP = (float)$r['PNP'];
        $maquinas[] = [
            'cod_maquina'    => $r['cod_maquina'],
            'maquina'        => $r['maquina'] ?: $r['cod_maquina'],
            'disponibilidad' => round(calcDDetalle($M, $PNP), 2),
            'M_min'          => round($M),
            'PNP_min'        => round($PNP),
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
            SUM(oee.M) AS M, SUM(oee.PNP) AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE " . implode(' AND ', $whereArt) . "
        GROUP BY oee.Cod_producto, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
    ";
    $rowsArt = fetchAll('mapex', $sqlArt, array_merge([$fecha, $fecha], $paramsCommon));

    $artAcc = [];
    foreach ($rowsArt as $r) {
        $sec = seccionDeDescDispDetalle($r['maquina']);
        if ($sec !== $seccion) continue;
        $k = $r['cod_articulo'];
        if (!isset($artAcc[$k])) {
            $artAcc[$k] = [
                'cod_articulo'  => $r['cod_articulo'],
                'desc_articulo' => $r['desc_articulo'] ?? '',
                'M' => 0.0, 'PNP' => 0.0,
            ];
        }
        $artAcc[$k]['M']   += (float)$r['M'];
        $artAcc[$k]['PNP'] += (float)$r['PNP'];
        if (empty($artAcc[$k]['desc_articulo']) && !empty($r['desc_articulo'])) {
            $artAcc[$k]['desc_articulo'] = $r['desc_articulo'];
        }
    }

    $articulos = [];
    foreach ($artAcc as $a) {
        $articulos[] = [
            'cod_articulo'   => $a['cod_articulo'],
            'desc_articulo'  => $a['desc_articulo'],
            'disponibilidad' => round(calcDDetalle($a['M'], $a['PNP']), 2),
            'M_min'          => round($a['M']),
            'PNP_min'        => round($a['PNP']),
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
