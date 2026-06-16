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
    $umbralDesde = (new DateTime('-1 year'))->format('Y-m-d');

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
          AND CAST(hp.Dia_productivo AS DATE) >= ?
        GROUP BY pr.Cod_producto, pr.Desc_producto
        ORDER BY desc_producto
    ";
    $rows = fetchAll('mapex', $sql, [$umbralDesde]);

    $multi = 0;
    foreach ($rows as $r) if ((int)$r['num_maquinas'] > 1) $multi++;

    jsonOk([
        'refs'        => $rows,
        'desde'       => $umbralDesde,
        'multi_count' => $multi,
    ]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
