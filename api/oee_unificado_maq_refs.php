<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Disponibilidad por referencia DENTRO de una máquina.
 *
 * Para la ventana "salmón" (drill-down de la métrica Disponibilidad por
 * máquina) lista todas las referencias que se han fabricado en ESA máquina
 * con las horas de paro acumuladas mientras se fabricaba cada una.
 *
 * GET:
 *   - fecha_desde, fecha_hasta   (YYYY-MM-DD, obligatorio)
 *   - cod_maquina                (obligatorio, WorkGroup/Cod_maquina)
 *   - turnos                     (CSV M,T,N — opc.)
 *
 * Devuelve:
 *   {
 *     "ok": true,
 *     "cod_maquina": "...",
 *     "referencias": [
 *       { "cod_referencia": "...", "referencia": "...", "horas_paro": 12.5 },
 *       ...
 *     ]
 *   }
 */

try {
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');
    $codMaq = trim((string) getParam('cod_maquina', ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    if ($codMaq === '') jsonError('cod_maquina obligatorio');

    $turnos = array_values(array_filter(getListParam('turnos'),
        fn($t) => in_array($t, ['M','T','N'], true)));

    $where = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",            // excluir CERRADA
        "hpp.Fecha_fin IS NOT NULL",
        "mq.Cod_maquina = ?",
        "prod.Cod_producto IS NOT NULL",
        "prod.Cod_producto <> '--'",
    ];
    $params = [$fdesde, $fhasta, $codMaq];

    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params  = array_merge($params, $turnos);
    }

    $sql = "
        SELECT
            prod.Cod_producto       AS cod_referencia,
            MAX(prod.Desc_producto) AS referencia,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro     cp   ON cp.Id_paro      = hpp.Id_paro
        INNER JOIN his_prod     hp   ON hp.Id_his_prod  = hpp.Id_his_prod
        INNER JOIN cfg_maquina  mq   ON mq.Id_maquina   = hp.Id_maquina
        INNER JOIN cfg_turno    ct   ON ct.Id_turno     = hp.Id_turno
        LEFT  JOIN his_fase     fa   ON fa.Id_his_fase  = hp.Id_his_fase
        LEFT  JOIN his_of       o    ON o.Id_his_of     = fa.Id_his_of
        LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        WHERE " . implode(' AND ', $where) . "
        GROUP BY prod.Cod_producto
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY segundos DESC
    ";
    $rows = fetchAll('mapex', $sql, $params);

    $referencias = [];
    foreach ($rows as $r) {
        $seg = (int) $r['segundos'];
        $cod = (string) $r['cod_referencia'];
        $des = (string) ($r['referencia'] ?: $cod);
        $referencias[] = [
            'cod_referencia' => $cod,
            'referencia'     => $des,
            'horas_paro'     => round($seg / 3600, 2),
        ];
    }

    jsonOk([
        'cod_maquina' => $codMaq,
        'referencias' => $referencias,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
