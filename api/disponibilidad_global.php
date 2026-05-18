<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Disponibilidad global + por sección.
 * Acepta filtros opcionales:
 *   - cod_maquina (WorkGroup)
 *   - cod_articulo (Cod_producto)
 *
 * Devuelve también:
 *   - machines: lista de máquinas disponibles para el día/turno
 *   - articles: lista de artículos disponibles para el día/turno
 */

function seccionDeDesc(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

function calcD(float $M, float $PNP): float {
    return ($M + $PNP) > 0 ? $M / ($M + $PNP) * 100 : 0;
}

try {
    $fecha        = getParam('fecha', date('Y-m-d'));
    $turno        = getParam('turno');
    $cod_maquina  = getParam('cod_maquina');
    $cod_articulo = getParam('cod_articulo');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');

    // Filtros comunes
    $whereCommon = ["CAST(oee.TimePeriod AS DATE) = ?"];
    $paramsCommon = [$fecha];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $whereCommon[] = "oee.Cod_turno = ?";
        $paramsCommon[] = $turno;
    }
    $whereCommon[] = "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";

    // --- Datos filtrados (máquina y/o artículo) para gauge + secciones ---
    $whereData = $whereCommon;
    $paramsData = $paramsCommon;
    if ($cod_maquina)  { $whereData[] = "oee.WorkGroup = ?";    $paramsData[] = $cod_maquina; }
    if ($cod_articulo) { $whereData[] = "oee.Cod_producto = ?"; $paramsData[] = $cod_articulo; }
    $whereDataSQL = implode(' AND ', $whereData);

    // Agregar por máquina (para luego separar por sección)
    $sqlData = "
        SELECT
            oee.WorkGroup AS cod_maquina,
            mq.Desc_maquina AS maquina,
            SUM(oee.M) AS M, SUM(oee.PNP) AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE $whereDataSQL
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
    ";
    $rowsData = fetchAll('mapex', $sqlData, array_merge([$fecha, $fecha], $paramsData));

    $globalAcc = ['M' => 0.0, 'PNP' => 0.0, 'maquinas' => 0];
    $secAcc = [];
    $maqInfo = null;
    foreach ($rowsData as $r) {
        $M = (float)$r['M']; $PNP = (float)$r['PNP'];
        $sec = seccionDeDesc($r['maquina']);
        $globalAcc['M'] += $M; $globalAcc['PNP'] += $PNP; $globalAcc['maquinas']++;
        if ($sec) {
            if (!isset($secAcc[$sec])) $secAcc[$sec] = ['M' => 0.0, 'PNP' => 0.0, 'maquinas' => 0];
            $secAcc[$sec]['M'] += $M; $secAcc[$sec]['PNP'] += $PNP; $secAcc[$sec]['maquinas']++;
        }
        if ($cod_maquina && $r['cod_maquina'] === $cod_maquina) {
            $maqInfo = ['cod_maquina' => $r['cod_maquina'], 'maquina' => $r['maquina'], 'seccion' => $sec];
        }
    }

    $global = [
        'disponibilidad' => round(calcD($globalAcc['M'], $globalAcc['PNP']), 2),
        'M_min'          => round($globalAcc['M']),
        'PNP_min'        => round($globalAcc['PNP']),
        'maquinas'       => $globalAcc['maquinas'],
    ];
    $secciones = [];
    foreach (['VARILLAS','TROQUELADOS'] as $sec) {
        if (!isset($secAcc[$sec])) {
            $secciones[] = ['seccion' => $sec, 'disponibilidad' => 0, 'M_min' => 0, 'PNP_min' => 0, 'maquinas' => 0];
            continue;
        }
        $a = $secAcc[$sec];
        $secciones[] = [
            'seccion'        => $sec,
            'disponibilidad' => round(calcD($a['M'], $a['PNP']), 2),
            'M_min'          => round($a['M']),
            'PNP_min'        => round($a['PNP']),
            'maquinas'       => $a['maquinas'],
        ];
    }

    // --- Lista de máquinas (sin filtro de cod_maquina, sí respeta cod_articulo si está) ---
    $whereM = $whereCommon;
    $paramsM = $paramsCommon;
    if ($cod_articulo) { $whereM[] = "oee.Cod_producto = ?"; $paramsM[] = $cod_articulo; }
    $sqlM = "
        SELECT
            oee.WorkGroup AS cod_maquina,
            mq.Desc_maquina AS maquina
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE " . implode(' AND ', $whereM) . "
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
        ORDER BY mq.Desc_maquina, oee.WorkGroup
    ";
    $rowsM = fetchAll('mapex', $sqlM, array_merge([$fecha, $fecha], $paramsM));
    $machines = [];
    foreach ($rowsM as $r) {
        $desc = $r['maquina'] ?: $r['cod_maquina'];
        $machines[] = [
            'cod_maquina' => $r['cod_maquina'],
            'maquina'     => $desc,
            'seccion'     => seccionDeDesc($desc),
        ];
    }

    // --- Lista de artículos (sin filtro de cod_articulo, sí respeta cod_maquina si está) ---
    $whereA = $whereCommon;
    $paramsA = $paramsCommon;
    if ($cod_maquina) { $whereA[] = "oee.WorkGroup = ?"; $paramsA[] = $cod_maquina; }
    $whereA[] = "oee.Cod_producto IS NOT NULL";
    $whereA[] = "oee.Cod_producto <> '--'";
    $sqlA = "
        SELECT
            oee.Cod_producto AS cod_articulo,
            MAX(oee.Desc_producto) AS desc_articulo,
            COUNT(DISTINCT oee.WorkGroup) AS num_maquinas
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE " . implode(' AND ', $whereA) . "
        GROUP BY oee.Cod_producto
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
        ORDER BY oee.Cod_producto
    ";
    $rowsA = fetchAll('mapex', $sqlA, array_merge([$fecha, $fecha], $paramsA));
    $articles = [];
    $artInfo = null;
    foreach ($rowsA as $r) {
        $articles[] = [
            'cod_articulo'  => $r['cod_articulo'],
            'desc_articulo' => $r['desc_articulo'] ?? '',
            'num_maquinas'  => (int)$r['num_maquinas'],
        ];
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
