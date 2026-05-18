<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Pareto de Horas de paro por Motivo.
 * Filtros opcionales: cod_maquina, cod_articulo.
 *
 * Devuelve:
 *   - paros: [{motivo, segundos, horas, pct, pct_acum}, ...]  ordenado desc
 *   - machines / articles: listas cruzadas para los selectores
 */

function seccionDeDesc(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

try {
    $fecha        = getParam('fecha', date('Y-m-d'));
    $turno        = getParam('turno');
    $cod_maquina  = getParam('cod_maquina');
    $cod_articulo = getParam('cod_articulo');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');

    // Filtros aplicados a la consulta principal y a las dos listas auxiliares
    $whereCommon  = ["CAST(hp.Dia_productivo AS DATE) = ?"];
    $paramsCommon = [$fecha];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $whereCommon[] = "ct.Cod_turno = ?";
        $paramsCommon[] = $turno;
    }
    $whereCommon[] = "mq.Cod_maquina NOT IN ('AUX000','AUXI1','SOLD4','SOLD5')";
    // Excluir motivo CERRADA (Cod_paro=11) en TODAS las queries (Pareto + listas)
    $excludeCerrada = "cp.Cod_paro <> 11";

    // --- Pareto principal (con filtros de máquina/artículo aplicados) ---
    $whereData  = $whereCommon;
    $paramsData = $paramsCommon;
    if ($cod_maquina)  { $whereData[] = "mq.Cod_maquina = ?";   $paramsData[] = $cod_maquina; }
    if ($cod_articulo) { $whereData[] = "prod.Cod_producto = ?"; $paramsData[] = $cod_articulo; }
    $whereData[] = $excludeCerrada;

    $sqlData = "
        SELECT
            cp.Desc_paro AS motivo,
            cp.Cod_paro AS cod_paro,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, ISNULL(hpp.Fecha_fin, hpp.Fecha_ini))) AS segundos,
            COUNT(*) AS num_paros
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro cp ON cp.Id_paro = hpp.Id_paro
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        LEFT JOIN his_fase fa ON fa.Id_his_fase = hp.Id_his_fase
        LEFT JOIN his_of o ON o.Id_his_of = fa.Id_his_of
        LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        WHERE " . implode(' AND ', $whereData) . "
          AND hpp.Fecha_fin IS NOT NULL
        GROUP BY cp.Desc_paro, cp.Cod_paro
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY segundos DESC
    ";
    $rowsData = fetchAll('mapex', $sqlData, $paramsData);

    $totSeg = 0;
    foreach ($rowsData as $r) $totSeg += (int)$r['segundos'];

    $paros = [];
    $acum = 0;
    foreach ($rowsData as $r) {
        $seg = (int)$r['segundos'];
        $pct = $totSeg > 0 ? $seg / $totSeg * 100 : 0;
        $acum += $pct;
        $paros[] = [
            'motivo'    => $r['motivo'] ?: '(sin nombre)',
            'cod_paro'  => (int)$r['cod_paro'],
            'segundos'  => $seg,
            'horas'     => round($seg / 3600, 2),
            'minutos'   => round($seg / 60, 1),
            'num_paros' => (int)$r['num_paros'],
            'pct'       => round($pct, 2),
            'pct_acum'  => round(min($acum, 100), 2),
        ];
    }

    // --- Lista de máquinas (respeta cod_articulo) ---
    $whereM  = $whereCommon;
    $paramsM = $paramsCommon;
    if ($cod_articulo) { $whereM[] = "prod.Cod_producto = ?"; $paramsM[] = $cod_articulo; }
    $sqlM = "
        SELECT DISTINCT mq.Cod_maquina AS cod_maquina, mq.Desc_maquina AS maquina
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro cp ON cp.Id_paro = hpp.Id_paro
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        LEFT JOIN his_fase fa ON fa.Id_his_fase = hp.Id_his_fase
        LEFT JOIN his_of o ON o.Id_his_of = fa.Id_his_of
        LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        WHERE " . implode(' AND ', $whereM) . "
          AND $excludeCerrada
          AND hpp.Fecha_fin IS NOT NULL
        ORDER BY mq.Desc_maquina, mq.Cod_maquina
    ";
    $rowsM = fetchAll('mapex', $sqlM, $paramsM);
    $machines = [];
    $maqInfo = null;
    foreach ($rowsM as $r) {
        $desc = $r['maquina'] ?: $r['cod_maquina'];
        $sec = seccionDeDesc($desc);
        $machines[] = ['cod_maquina' => $r['cod_maquina'], 'maquina' => $desc, 'seccion' => $sec];
        if ($cod_maquina && $r['cod_maquina'] === $cod_maquina) {
            $maqInfo = ['cod_maquina' => $r['cod_maquina'], 'maquina' => $desc, 'seccion' => $sec];
        }
    }

    // --- Lista de artículos (respeta cod_maquina) ---
    $whereA  = $whereCommon;
    $paramsA = $paramsCommon;
    if ($cod_maquina) { $whereA[] = "mq.Cod_maquina = ?"; $paramsA[] = $cod_maquina; }
    $whereA[] = "prod.Cod_producto IS NOT NULL";
    $whereA[] = "prod.Cod_producto <> '--'";
    $sqlA = "
        SELECT
            prod.Cod_producto AS cod_articulo,
            MAX(prod.Desc_producto) AS desc_articulo
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro cp ON cp.Id_paro = hpp.Id_paro
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        LEFT JOIN his_fase fa ON fa.Id_his_fase = hp.Id_his_fase
        LEFT JOIN his_of o ON o.Id_his_of = fa.Id_his_of
        LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        WHERE " . implode(' AND ', $whereA) . "
          AND $excludeCerrada
          AND hpp.Fecha_fin IS NOT NULL
        GROUP BY prod.Cod_producto
        ORDER BY prod.Cod_producto
    ";
    $rowsA = fetchAll('mapex', $sqlA, $paramsA);
    $articles = [];
    $artInfo = null;
    foreach ($rowsA as $r) {
        $articles[] = [
            'cod_articulo'  => $r['cod_articulo'],
            'desc_articulo' => $r['desc_articulo'] ?? '',
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
        'total_segundos'=> $totSeg,
        'total_horas'   => round($totSeg / 3600, 2),
        'paros'         => $paros,
        'machines'      => $machines,
        'articles'      => $articles,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
