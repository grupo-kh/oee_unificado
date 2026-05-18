<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Pareto de Unidades rechazadas por Motivo (defecto).
 * Filtros opcionales: cod_maquina, cod_articulo.
 *
 * Origen de datos: his_prod_defecto + cfg_defecto + his_prod
 *  - Sólo defectos NOK (cfg_defecto.esNOK = 1)
 *  - Sólo registros activos (his_prod_defecto.Activo = 1)
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

    $whereCommon  = ["CAST(hp.Dia_productivo AS DATE) = ?", "hpd.Activo = 1", "df.esNOK = 1"];
    $paramsCommon = [$fecha];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $whereCommon[] = "ct.Cod_turno = ?";
        $paramsCommon[] = $turno;
    }
    $whereCommon[] = "mq.Cod_maquina NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";

    // --- Pareto principal por motivo ---
    $whereData  = $whereCommon;
    $paramsData = $paramsCommon;
    if ($cod_maquina)  { $whereData[] = "mq.Cod_maquina = ?";    $paramsData[] = $cod_maquina; }
    if ($cod_articulo) { $whereData[] = "prod.Cod_producto = ?"; $paramsData[] = $cod_articulo; }

    $sqlData = "
        SELECT
            df.Cod_defecto AS cod_defecto,
            df.Desc_defecto AS motivo,
            SUM(hpd.Unidades) AS unidades,
            COUNT(*) AS num_registros
        FROM his_prod_defecto hpd
        INNER JOIN cfg_defecto df ON df.Id_defecto = hpd.Id_defecto
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpd.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        LEFT JOIN his_fase fa ON fa.Id_his_fase = hp.Id_his_fase
        LEFT JOIN his_of o ON o.Id_his_of = fa.Id_his_of
        LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        WHERE " . implode(' AND ', $whereData) . "
        GROUP BY df.Cod_defecto, df.Desc_defecto
        HAVING SUM(hpd.Unidades) > 0
        ORDER BY unidades DESC
    ";
    $rowsData = fetchAll('mapex', $sqlData, $paramsData);

    $totU = 0;
    foreach ($rowsData as $r) $totU += (int)$r['unidades'];

    $rechazos = [];
    $acum = 0;
    foreach ($rowsData as $r) {
        $u = (int)$r['unidades'];
        $pct = $totU > 0 ? $u / $totU * 100 : 0;
        $acum += $pct;
        $rechazos[] = [
            'cod_defecto'   => $r['cod_defecto'],
            'motivo'        => $r['motivo'] ?: '(sin nombre)',
            'unidades'      => $u,
            'num_registros' => (int)$r['num_registros'],
            'pct'           => round($pct, 2),
            'pct_acum'      => round(min($acum, 100), 2),
        ];
    }

    // --- Lista de máquinas (respeta cod_articulo) ---
    $whereM  = $whereCommon;
    $paramsM = $paramsCommon;
    if ($cod_articulo) { $whereM[] = "prod.Cod_producto = ?"; $paramsM[] = $cod_articulo; }
    $sqlM = "
        SELECT DISTINCT mq.Cod_maquina AS cod_maquina, mq.Desc_maquina AS maquina
        FROM his_prod_defecto hpd
        INNER JOIN cfg_defecto df ON df.Id_defecto = hpd.Id_defecto
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpd.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        LEFT JOIN his_fase fa ON fa.Id_his_fase = hp.Id_his_fase
        LEFT JOIN his_of o ON o.Id_his_of = fa.Id_his_of
        LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        WHERE " . implode(' AND ', $whereM) . "
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
        FROM his_prod_defecto hpd
        INNER JOIN cfg_defecto df ON df.Id_defecto = hpd.Id_defecto
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpd.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        LEFT JOIN his_fase fa ON fa.Id_his_fase = hp.Id_his_fase
        LEFT JOIN his_of o ON o.Id_his_of = fa.Id_his_of
        LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        WHERE " . implode(' AND ', $whereA) . "
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
        'total_unidades'=> $totU,
        'rechazos'      => $rechazos,
        'machines'      => $machines,
        'articles'      => $articles,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
