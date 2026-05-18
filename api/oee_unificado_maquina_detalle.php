<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Detalle por máquina: lista de OFs y de referencias distintas que han
 * tenido actividad productiva en el rango/turnos para una máquina.
 *
 * Parámetros:
 *   - fecha_desde, fecha_hasta (YYYY-MM-DD)
 *   - turnos (CSV: M,T,N) — vacío = todos
 *   - cod_maquina (string)
 *
 * Devuelve:
 *   - cod_maquina, maquina
 *   - ofs:  [{ cod_of }, ...] ordenado por cod_of
 *   - refs: [{ cod_producto, desc_producto, unidades_ok, unidades_nok }, ...] ordenado por desc_producto
 *           unidades_ok  = Σ his_prod.Unidades_ok  en el rango/turnos/máquina
 *           unidades_nok = Σ his_prod.Unidades_nok en el rango/turnos/máquina
 */

try {
    $fdesde   = (string) getParam('fecha_desde');
    $fhasta   = (string) getParam('fecha_hasta');
    $codMaq   = (string) ($_GET['cod_maquina'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    if ($codMaq === '') jsonError('cod_maquina requerido');

    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));
    $excl   = getListParam('excl');

    // Si la máquina pedida está excluida por el filtro global, devolvemos vacío.
    if (in_array($codMaq, $excl, true)) {
        jsonOk([
            'cod_maquina' => $codMaq,
            'maquina'     => $codMaq,
            'ofs'         => [],
            'refs'        => [],
        ]);
    }

    $whereBase  = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "mq.Cod_maquina = ?",
    ];
    $paramsBase = [$fdesde, $fhasta, $codMaq];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $whereBase[] = "ct.Cod_turno IN ($ph)";
        $paramsBase = array_merge($paramsBase, $turnos);
    }
    $whereSQL = implode(' AND ', $whereBase);

    // Nombre de la máquina (puede ser null si Cod_maquina no existe)
    $maqRow = fetchAll('mapex',
        "SELECT TOP 1 Desc_maquina FROM cfg_maquina WHERE Cod_maquina = ?",
        [$codMaq]
    );
    $maqNombre = $maqRow[0]['Desc_maquina'] ?? $codMaq;

    // OFs distintas con actividad en el rango
    $sqlOfs = "
        SELECT DISTINCT o.Cod_of AS cod_of
        FROM his_prod hp
        INNER JOIN his_fase    fa ON fa.Id_his_fase = hp.Id_his_fase
        INNER JOIN his_of      o  ON o.Id_his_of    = fa.Id_his_of
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE $whereSQL
          AND o.Cod_of IS NOT NULL
          AND o.Cod_of <> '--'
        ORDER BY o.Cod_of
    ";
    $rowsOfs = fetchAll('mapex', $sqlOfs, $paramsBase);

    // Referencias distintas con actividad en el rango + Σ Unidades_ok / Σ Unidades_nok
    $sqlRefs = "
        SELECT pr.Cod_producto AS cod_producto,
               pr.Desc_producto AS desc_producto,
               SUM(CAST(ISNULL(hp.Unidades_ok,  0) AS FLOAT)) AS unidades_ok,
               SUM(CAST(ISNULL(hp.Unidades_nok, 0) AS FLOAT)) AS unidades_nok
        FROM his_prod hp
        INNER JOIN his_fase     fa ON fa.Id_his_fase = hp.Id_his_fase
        INNER JOIN his_of       o  ON o.Id_his_of    = fa.Id_his_of
        INNER JOIN cfg_producto pr ON pr.Id_producto = o.Id_producto
        INNER JOIN cfg_maquina  mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno    ct ON ct.Id_turno    = hp.Id_turno
        WHERE $whereSQL
          AND pr.Cod_producto IS NOT NULL
          AND pr.Cod_producto <> '--'
        GROUP BY pr.Cod_producto, pr.Desc_producto
        ORDER BY pr.Desc_producto
    ";
    $rowsRefs = fetchAll('mapex', $sqlRefs, $paramsBase);

    jsonOk([
        'cod_maquina' => $codMaq,
        'maquina'     => $maqNombre,
        'ofs'  => array_map(fn($r) => ['cod_of' => $r['cod_of']], $rowsOfs),
        'refs' => array_map(fn($r) => [
            'cod_producto'  => $r['cod_producto'],
            'desc_producto' => $r['desc_producto'] ?: $r['cod_producto'],
            'unidades_ok'   => (int) round((float)($r['unidades_ok']  ?? 0)),
            'unidades_nok'  => (int) round((float)($r['unidades_nok'] ?? 0)),
        ], $rowsRefs),
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
