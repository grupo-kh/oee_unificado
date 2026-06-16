<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Lista de referencias FABRICABLES (MAPEX: cfg_producto con fase activa), con su
 * nº de máquinas, su nomenclatura SAGE (ReferenciaEdi_) y un flag de cuántos
 * componentes tiene su escandallo en SAGE (hoy casi todas 0).
 *
 * Devuelve: { refs: [{ cod_producto, desc, referencia_edi, num_maquinas, num_componentes }] }
 */
try {
    // 1) MAPEX: referencias con configuración de fabricación (fase activa) + nº máquinas.
    $mapex = fetchAll('mapex', "
        SELECT LTRIM(RTRIM(p.Cod_producto)) AS cod, MAX(p.Desc_producto) AS d,
               COUNT(DISTINCT fm.Id_maquina) AS nmaq
        FROM cfg_producto p
        INNER JOIN cfg_fase f ON f.Id_producto = p.Id_producto AND f.Activo = 1
        LEFT  JOIN cfg_fase_maquina fm ON fm.Id_fase = f.Id_fase AND fm.Activo = 1
        WHERE p.Cod_producto IS NOT NULL AND LTRIM(RTRIM(p.Cod_producto)) <> ''
        GROUP BY LTRIM(RTRIM(p.Cod_producto))
    ");

    $codes = array_values(array_unique(array_map(fn($r) => (string)$r['cod'], $mapex)));

    // 2) SAGE: componentes de escandallo por referencia (padre con ≥1 componente real).
    $escSet = [];   // cod => num_componentes
    try {
        $rows = fetchAll('sage', "
            SELECT CONVERT(varchar(40), e.CodigoProceso) AS pr, e.Orden AS orden,
                   LTRIM(RTRIM(e.CodigoArticulo)) AS cod
            FROM Estructura_Escandallo e
        ");
        $byProc = [];
        foreach ($rows as $r) $byProc[$r['pr']][] = $r;
        foreach ($byProc as $nodes) {
            usort($nodes, fn($a, $b) => (int)$a['orden'] <=> (int)$b['orden']);
            $padre = (string)$nodes[0]['cod'];
            $n = 0;
            foreach (array_slice($nodes, 1) as $x) if ((string)$x['cod'] !== $padre) $n++;
            if ($n > 0) $escSet[$padre] = $n;
        }
    } catch (\Throwable $e) { /* SAGE no disponible: sin escandallo, no rompe la lista */ }

    // 3) SAGE: nomenclatura ReferenciaEdi_ por código (batch, troceado).
    $ediMap = [];   // cod => ReferenciaEdi_
    if (!empty($codes)) {
        try {
            foreach (array_chunk($codes, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $art = fetchAll('sage',
                    "SELECT LTRIM(RTRIM(CodigoArticulo)) AS cod, ReferenciaEdi_ AS edi
                     FROM Articulos WHERE LTRIM(RTRIM(CodigoArticulo)) IN ($ph)", $chunk);
                foreach ($art as $a) $ediMap[(string)$a['cod']] = trim((string)$a['edi']);
            }
        } catch (\Throwable $e) { /* SAGE no disponible */ }
    }

    $refs = [];
    foreach ($mapex as $r) {
        $cod = (string)$r['cod'];
        $refs[] = [
            'cod_producto'    => $cod,
            'desc'            => trim((string)($r['d'] ?: $cod)),
            'referencia_edi'  => $ediMap[$cod] ?? '',
            'num_maquinas'    => (int)$r['nmaq'],
            'num_componentes' => $escSet[$cod] ?? 0,
        ];
    }
    usort($refs, fn($a, $b) => strcasecmp($a['desc'], $b['desc']));

    jsonOk(['refs' => $refs]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
