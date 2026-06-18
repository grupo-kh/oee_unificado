<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Lista de referencias activas (cfg_producto) con producción en el último año.
 *
 * Solo incluye referencias con al menos un registro en his_prod en los últimos
 * 12 meses. Devuelve además el número de máquinas distintas que la han
 * fabricado en ese mismo periodo (útil para destacar las multi-máquina).
 *
 * Devuelve: { refs: [{ cod_producto, desc_producto, num_maquinas, primera, ultima }, ...],
 *             desde: 'YYYY-MM-DD' (umbral aplicado),
 *             multi_count: int (referencias con num_maquinas > 1) }
 *   primera/ultima = primer y último día de fabricación dentro de la ventana de 12
 *   meses (para auto-ajustar el rango al seleccionar la referencia).
 */

try {
    // Si llegan fecha_desde/fecha_hasta (rango del filtro principal), solo se
    // listan las referencias con OF/producción dentro de ese rango. Si no, se
    // mantiene el comportamiento previo: referencias del último año.
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');
    $usaRango = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta);

    if ($usaRango) {
        $fechaWhere = "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?";
        $params     = [$fdesde, $fhasta];
        $umbral     = $fdesde;
    } else {
        $umbral     = (new DateTime('-1 year'))->format('Y-m-d');
        $fechaWhere = "CAST(hp.Dia_productivo AS DATE) >= ?";
        $params     = [$umbral];
    }

    $sql = "
        SELECT pr.Cod_producto AS cod_producto,
               COALESCE(NULLIF(LTRIM(RTRIM(pr.Desc_producto)), ''), pr.Cod_producto) AS desc_producto,
               COUNT(DISTINCT hp.Id_maquina) AS num_maquinas,
               CONVERT(varchar(10), MIN(CAST(hp.Dia_productivo AS DATE)), 23) AS primera,
               CONVERT(varchar(10), MAX(CAST(hp.Dia_productivo AS DATE)), 23) AS ultima
        FROM cfg_producto pr
        INNER JOIN his_of    o  ON o.Id_producto  = pr.Id_producto
        INNER JOIN his_fase  fa ON fa.Id_his_of   = o.Id_his_of
        INNER JOIN his_prod  hp ON hp.Id_his_fase = fa.Id_his_fase
        WHERE pr.Cod_producto IS NOT NULL
          AND LTRIM(RTRIM(pr.Cod_producto)) <> ''
          AND LTRIM(RTRIM(pr.Cod_producto)) <> '--'
          AND $fechaWhere
        GROUP BY pr.Cod_producto, pr.Desc_producto
        ORDER BY desc_producto
    ";
    $rows = fetchAll('mapex', $sql, $params);

    $multi = 0;
    foreach ($rows as $r) if ((int)$r['num_maquinas'] > 1) $multi++;

    jsonOk([
        'refs'        => $rows,
        'desde'       => $umbral,
        'multi_count' => $multi,
    ]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
