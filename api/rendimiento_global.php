<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Rendimiento global + por sección.
 * Acepta filtros opcionales:
 *   - cod_maquina (WorkGroup)
 *   - cod_articulo (Cod_producto)
 *
 * Fórmula:
 *   R = (M_OKNOK_TEO + PCALIDAD) / (M + PPERF + PCALIDAD)
 */

function seccionDeDesc(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

function calcR(float $MOT, float $PC, float $M, float $PP): float {
    $den = $M + $PP + $PC;
    return $den > 0 ? ($MOT + $PC) / $den * 100 : 0;
}

try {
    $fecha        = getParam('fecha', date('Y-m-d'));
    $turno        = getParam('turno');
    $cod_maquina  = getParam('cod_maquina');
    $cod_articulo = getParam('cod_articulo');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');

    $whereCommon = ["CAST(oee.TimePeriod AS DATE) = ?"];
    $paramsCommon = [$fecha];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $whereCommon[] = "oee.Cod_turno = ?";
        $paramsCommon[] = $turno;
    }
    $whereCommon[] = "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";

    // --- Datos filtrados ---
    $whereData = $whereCommon;
    $paramsData = $paramsCommon;
    if ($cod_maquina)  { $whereData[] = "oee.WorkGroup = ?";    $paramsData[] = $cod_maquina; }
    if ($cod_articulo) { $whereData[] = "oee.Cod_producto = ?"; $paramsData[] = $cod_articulo; }
    $whereDataSQL = implode(' AND ', $whereData);

    $sqlData = "
        SELECT
            oee.WorkGroup AS cod_maquina,
            mq.Desc_maquina AS maquina,
            SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO,
            SUM(oee.PPERF) AS PPERF, SUM(oee.PCALIDAD) AS PCALIDAD
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE $whereDataSQL
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PPERF) + SUM(oee.PCALIDAD) > 0
    ";
    $rowsData = fetchAll('mapex', $sqlData, array_merge([$fecha, $fecha], $paramsData));

    $globalAcc = ['M' => 0.0, 'MOT' => 0.0, 'PP' => 0.0, 'PC' => 0.0, 'maquinas' => 0];
    $secAcc = [];
    $maqInfo = null;
    foreach ($rowsData as $r) {
        $M = (float)$r['M']; $MOT = (float)$r['M_OKNOK_TEO'];
        $PP = (float)$r['PPERF']; $PC = (float)$r['PCALIDAD'];
        $sec = seccionDeDesc($r['maquina']);
        $globalAcc['M'] += $M; $globalAcc['MOT'] += $MOT;
        $globalAcc['PP'] += $PP; $globalAcc['PC'] += $PC; $globalAcc['maquinas']++;
        if ($sec) {
            if (!isset($secAcc[$sec])) $secAcc[$sec] = ['M'=>0.0,'MOT'=>0.0,'PP'=>0.0,'PC'=>0.0,'maquinas'=>0];
            $secAcc[$sec]['M'] += $M; $secAcc[$sec]['MOT'] += $MOT;
            $secAcc[$sec]['PP'] += $PP; $secAcc[$sec]['PC'] += $PC; $secAcc[$sec]['maquinas']++;
        }
        if ($cod_maquina && $r['cod_maquina'] === $cod_maquina) {
            $maqInfo = ['cod_maquina' => $r['cod_maquina'], 'maquina' => $r['maquina'], 'seccion' => $sec];
        }
    }

    $global = [
        'rendimiento'    => round(calcR($globalAcc['MOT'], $globalAcc['PC'], $globalAcc['M'], $globalAcc['PP']), 2),
        'M_min'          => round($globalAcc['M']),
        'PPERF_min'      => round($globalAcc['PP']),
        'maquinas'       => $globalAcc['maquinas'],
    ];
    $secciones = [];
    foreach (['VARILLAS','TROQUELADOS'] as $sec) {
        if (!isset($secAcc[$sec])) {
            $secciones[] = ['seccion' => $sec, 'rendimiento' => 0, 'M_min' => 0, 'PPERF_min' => 0, 'maquinas' => 0];
            continue;
        }
        $a = $secAcc[$sec];
        $secciones[] = [
            'seccion'     => $sec,
            'rendimiento' => round(calcR($a['MOT'], $a['PC'], $a['M'], $a['PP']), 2),
            'M_min'       => round($a['M']),
            'PPERF_min'   => round($a['PP']),
            'maquinas'    => $a['maquinas'],
        ];
    }

    // --- Lista de máquinas (respeta cod_articulo) ---
    $whereM  = $whereCommon;
    $paramsM = $paramsCommon;
    if ($cod_articulo) { $whereM[] = "oee.Cod_producto = ?"; $paramsM[] = $cod_articulo; }
    $sqlM = "
        SELECT oee.WorkGroup AS cod_maquina, mq.Desc_maquina AS maquina
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE " . implode(' AND ', $whereM) . "
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PPERF) + SUM(oee.PCALIDAD) > 0
        ORDER BY mq.Desc_maquina, oee.WorkGroup
    ";
    $rowsM = fetchAll('mapex', $sqlM, array_merge([$fecha, $fecha], $paramsM));
    $machines = [];
    foreach ($rowsM as $r) {
        $desc = $r['maquina'] ?: $r['cod_maquina'];
        $machines[] = ['cod_maquina' => $r['cod_maquina'], 'maquina' => $desc, 'seccion' => seccionDeDesc($desc)];
    }

    // --- Lista de artículos (respeta cod_maquina) ---
    $whereA  = $whereCommon;
    $paramsA = $paramsCommon;
    if ($cod_maquina) { $whereA[] = "oee.WorkGroup = ?"; $paramsA[] = $cod_maquina; }
    $whereA[] = "oee.Cod_producto IS NOT NULL";
    $whereA[] = "oee.Cod_producto <> '--'";
    $sqlA = "
        SELECT oee.Cod_producto AS cod_articulo, MAX(oee.Desc_producto) AS desc_articulo
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE " . implode(' AND ', $whereA) . "
        GROUP BY oee.Cod_producto
        HAVING SUM(oee.M) + SUM(oee.PPERF) + SUM(oee.PCALIDAD) > 0
        ORDER BY oee.Cod_producto
    ";
    $rowsA = fetchAll('mapex', $sqlA, array_merge([$fecha, $fecha], $paramsA));
    $articles = [];
    $artInfo = null;
    foreach ($rowsA as $r) {
        $articles[] = ['cod_articulo' => $r['cod_articulo'], 'desc_articulo' => $r['desc_articulo'] ?? ''];
        if ($cod_articulo && $r['cod_articulo'] === $cod_articulo) {
            $artInfo = ['cod_articulo' => $r['cod_articulo'], 'desc_articulo' => $r['desc_articulo'] ?? ''];
        }
    }

    jsonOk([
        'fecha'         => $fecha,
        'turno'         => $turno ?: null,
        'cod_maquina'   => $cod_maquina ?: null,
        'cod_articulo'  => $cod_articulo ?: null,
        'maquina_info'  => $maqInfo,
        'articulo_info' => $artInfo,
        'global'        => $global,
        'secciones'     => $secciones,
        'machines'      => $machines,
        'articles'      => $articles,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
