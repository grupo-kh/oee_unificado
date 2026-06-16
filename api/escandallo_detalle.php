<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Detalle de una referencia: escandallo (componentes, SAGE) + centro/máquina (MAPEX).
 *
 * GET: cod_producto (= CodigoArticulo SAGE = Cod_producto MAPEX)
 *
 * Devuelve:
 *   { referencia: { cod, desc, referencia_edi },
 *     componentes: [{ cod, desc, unidades, um }],            (SAGE Estructura_Escandallo)
 *     fases:       [{ cod_fase, desc_fase, orden,
 *                     maquinas:[{ cod_maquina, desc_maquina, rend_nominal, seg_ciclo }] }] }  (MAPEX)
 */
try {
    $cod = trim((string)($_GET['cod_producto'] ?? ''));
    if ($cod === '') jsonError('cod_producto requerido');

    // 1) Cabecera de referencia (SAGE Articulos)
    $a = fetchAll('sage',
        "SELECT TOP 1 LTRIM(RTRIM(CodigoArticulo)) AS cod, DescripcionArticulo AS d, ReferenciaEdi_ AS edi
         FROM Articulos WHERE LTRIM(RTRIM(CodigoArticulo)) = ?", [$cod]);
    $ref = $a
        ? ['cod' => $a[0]['cod'], 'desc' => trim((string)$a[0]['d']), 'referencia_edi' => trim((string)$a[0]['edi'])]
        : ['cod' => $cod, 'desc' => $cod, 'referencia_edi' => ''];

    // 2) Componentes (SAGE): procesos cuyo padre (menor Orden) es esta referencia.
    $rows = fetchAll('sage', "
        SELECT CONVERT(varchar(40), e.CodigoProceso) AS pr, e.Orden AS orden,
               LTRIM(RTRIM(e.CodigoArticulo)) AS c, a.DescripcionArticulo AS d,
               e.UnidadesBrutas AS u, e.UnidadMedida1_ AS um
        FROM Estructura_Escandallo e
        LEFT JOIN Articulos a ON a.CodigoArticulo = e.CodigoArticulo
    ");
    $byProc = [];
    foreach ($rows as $r) $byProc[$r['pr']][] = $r;
    $componentes = [];
    foreach ($byProc as $nodes) {
        usort($nodes, fn($x, $y) => (int)$x['orden'] <=> (int)$y['orden']);
        if ((string)$nodes[0]['c'] !== $cod) continue;        // este proceso no es de esta referencia
        foreach (array_slice($nodes, 1) as $n) {
            if ((string)$n['c'] === $cod) continue;
            $componentes[] = [
                'cod'      => (string)$n['c'],
                'desc'     => trim((string)($n['d'] ?: $n['c'])),
                'unidades' => round((float)$n['u'], 4),
                'um'       => trim((string)$n['um']),
            ];
        }
    }

    // 3) Fases + máquinas (MAPEX)
    $fases = [];
    $p = fetchAll('mapex', "SELECT TOP 1 Id_producto FROM cfg_producto WHERE LTRIM(RTRIM(Cod_producto)) = ?", [$cod]);
    if ($p) {
        $idp = (int)$p[0]['Id_producto'];
        $fs = fetchAll('mapex',
            "SELECT Id_fase, Cod_fase, Desc_fase, Orden FROM cfg_fase WHERE Id_producto = ? AND Activo = 1 ORDER BY Orden", [$idp]);
        foreach ($fs as $f) {
            $maqs = fetchAll('mapex', "
                SELECT mq.Cod_maquina AS cod, mq.Desc_maquina AS d,
                       fm.Rendimientonominal1 AS rend, fm.SegCicloNominal AS seg
                FROM cfg_fase_maquina fm
                INNER JOIN cfg_maquina mq ON mq.Id_maquina = fm.Id_maquina
                WHERE fm.Id_fase = ? AND fm.Activo = 1
                ORDER BY mq.Desc_maquina", [(int)$f['Id_fase']]);
            $fases[] = [
                'cod_fase'  => trim((string)$f['Cod_fase']),
                'desc_fase' => trim((string)$f['Desc_fase']),
                'orden'     => (int)$f['Orden'],
                'maquinas'  => array_map(fn($m) => [
                    'cod_maquina'  => trim((string)$m['cod']),
                    'desc_maquina' => trim((string)$m['d']),
                    'rend_nominal' => round((float)$m['rend'], 2),
                    'seg_ciclo'    => (int)$m['seg'],
                ], $maqs),
            ];
        }
    }

    jsonOk(['referencia' => $ref, 'componentes' => $componentes, 'fases' => $fases]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
