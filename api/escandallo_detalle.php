<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Detalle de escandallo de una referencia (SAGE KHITT_estructuras), con centro y
 * máquina. El nombre legible de la máquina se resuelve por el código de centro de
 * trabajo contra MAPEX (cfg_maquina).
 *
 * GET: cod_producto
 * Devuelve:
 *   { referencia: { cod, desc, referencia_edi },
 *     componentes: [{ cod, desc, centro, maquina, unidades, nivel }],   (articulocomponente <> codigoarticulo)
 *     maquinas:    [{ centro, maquina }] }                              (centros del propio artículo)
 */
try {
    $cod = trim((string)($_GET['cod_producto'] ?? ''));
    if ($cod === '') jsonError('cod_producto requerido');

    // Cabecera (SAGE Articulos)
    $a = fetchAll('sage',
        "SELECT TOP 1 LTRIM(RTRIM(CodigoArticulo)) AS cod, DescripcionArticulo AS d, ReferenciaEdi_ AS edi
         FROM Articulos WHERE LTRIM(RTRIM(CodigoArticulo)) = ?", [$cod]);
    $ref = $a
        ? ['cod' => $a[0]['cod'], 'desc' => trim((string)$a[0]['d']), 'referencia_edi' => trim((string)$a[0]['edi'])]
        : ['cod' => $cod, 'desc' => $cod, 'referencia_edi' => ''];

    // Escandallo (SAGE KHITT_estructuras). DISTINCT por las filas duplicadas de la vista.
    $rows = fetchAll('sage', "
        SELECT DISTINCT e.nivel AS nivel, e.ordenn AS ordenn,
               LTRIM(RTRIM(e.articulocomponente)) AS comp,
               (SELECT TOP 1 a.DescripcionArticulo FROM Articulos a
                  WHERE LTRIM(RTRIM(a.CodigoArticulo)) = LTRIM(RTRIM(e.articulocomponente))) AS compdesc,
               LTRIM(RTRIM(e.centrotrabajo)) AS centro,
               e.unidades AS unidades
        FROM KHITT_estructuras e
        WHERE LTRIM(RTRIM(e.codigoarticulo)) = ?
    ", [$cod]);
    usort($rows, fn($x, $y) => (int)$x['ordenn'] <=> (int)$y['ordenn']);

    // Resolver nombre de máquina por centro contra MAPEX cfg_maquina.
    $centros = [];
    foreach ($rows as $r) { $c = (string)$r['centro']; if ($c !== '') $centros[$c] = true; }
    $maqName = [];
    if (!empty($centros)) {
        $list = array_keys($centros);
        $ph = implode(',', array_fill(0, count($list), '?'));
        try {
            foreach (fetchAll('mapex',
                "SELECT LTRIM(RTRIM(Cod_maquina)) AS c, Desc_maquina AS d FROM cfg_maquina WHERE LTRIM(RTRIM(Cod_maquina)) IN ($ph)", $list) as $m)
                $maqName[(string)$m['c']] = trim((string)$m['d']);
        } catch (\Throwable $e) { /* sin MAPEX: solo código de centro */ }
    }

    $componentes = [];
    $maqProd = [];   // centros del propio artículo (máquinas de fabricación)
    foreach ($rows as $r) {
        $comp   = (string)$r['comp'];
        $centro = (string)$r['centro'];
        $maq    = $centro !== '' ? ($maqName[$centro] ?? $centro) : '';
        if ($comp === $cod) {                       // fila del propio artículo → máquina de fabricación
            if ($centro !== '' && !isset($maqProd[$centro])) $maqProd[$centro] = ['centro' => $centro, 'maquina' => $maq];
            continue;
        }
        $componentes[] = [
            'cod'      => $comp,
            'desc'     => trim((string)($r['compdesc'] ?: $comp)),
            'centro'   => $centro,
            'maquina'  => $maq,
            'unidades' => round((float)$r['unidades'], 6),
            'nivel'    => (int)$r['nivel'],
        ];
    }

    jsonOk([
        'referencia'  => $ref,
        'componentes' => $componentes,
        'maquinas'    => array_values($maqProd),
    ]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
