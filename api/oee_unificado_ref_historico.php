<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/oee_unificado_ref_historico.php_data.php';

/**
 * Histórico de fabricación por referencia · agrupado por OF (sin fecha).
 *
 * Parámetros:
 *   - cod_producto (required)
 *   - fecha_desde, fecha_hasta (required, máximo 1 año)
 *
 * Devuelve:
 *   - cod_producto, desc_producto
 *   - rango: { desde, hasta }
 *   - ofs:   [{ cod_of, unidades_ok, unidades_nok, horas, uds_h, nok_pct, num_dias,
 *               maquinas: [{ cod_maquina, maquina, unidades_ok, unidades_nok, horas, uds_h, nok_pct }] }]
 *   - totales: { unidades_ok, unidades_nok, num_ofs, num_maquinas, dias, uds_h_medio }
 */

try {
    $codProd = (string) ($_GET['cod_producto'] ?? '');
    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');

    if ($codProd === '') jsonError('cod_producto requerido');
    try {
        refHistValidarRango($fdesde, $fhasta);
    } catch (Throwable $e) {
        jsonError($e->getMessage());
    }

    $prod = refHistFetchProducto($codProd);
    $ofs  = refHistFetchOfsConMaquinas($codProd, $fdesde, $fhasta);
    $tot  = refHistTotalesOfs($ofs);

    jsonOk([
        'cod_producto'  => $prod['cod_producto'],
        'desc_producto' => $prod['desc_producto'],
        'rango'         => ['desde' => $fdesde, 'hasta' => $fhasta],
        'ofs'           => $ofs,
        'totales'       => $tot,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
