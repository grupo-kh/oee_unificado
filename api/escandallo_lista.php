<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Referencias del escandallo (SAGE, vista KHITT_estructuras). Incluye TODAS las
 * referencias presentes en la vista (con o sin componentes), para poder buscarlas
 * por código SAGE / nombre / ReferenciaEdi_. num_componentes = componentes reales
 * (articulocomponente <> codigoarticulo).
 *
 * Devuelve: { refs: [{ cod_producto, desc, referencia_edi, num_componentes }] }
 */
try {
    $rows = fetchAll('sage', "
        SELECT LTRIM(RTRIM(e.codigoarticulo)) AS cod,
               MAX(a.DescripcionArticulo) AS d,
               MAX(a.ReferenciaEdi_) AS edi,
               COUNT(DISTINCT CASE WHEN LTRIM(RTRIM(e.articulocomponente)) <> LTRIM(RTRIM(e.codigoarticulo))
                                   THEN LTRIM(RTRIM(e.articulocomponente)) END) AS ncomp
        FROM KHITT_estructuras e
        LEFT JOIN Articulos a ON a.CodigoArticulo = e.codigoarticulo
        GROUP BY LTRIM(RTRIM(e.codigoarticulo))
    ");

    $refs = [];
    foreach ($rows as $r) {
        $cod = (string)$r['cod'];
        $refs[] = [
            'cod_producto'    => $cod,
            'desc'            => trim((string)($r['d'] ?: $cod)),
            'referencia_edi'  => trim((string)$r['edi']),
            'num_componentes' => (int)$r['ncomp'],
        ];
    }
    usort($refs, fn($a, $b) => strcasecmp($a['desc'], $b['desc']));

    jsonOk(['refs' => $refs]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
