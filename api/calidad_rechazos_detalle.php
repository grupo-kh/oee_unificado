<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Detalle de un motivo de rechazo: desglose por máquina.
 * Acepta:
 *   - cod_defecto (obligatorio)
 *   - fecha, turno
 *   - cod_articulo (opcional)
 *
 * Devuelve {motivo, total_unidades, breakdown: [{cod_maquina, maquina, seccion, unidades, num_registros, pct}]}
 */

function seccionDeDesc(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

try {
    $cod_defecto  = getParam('cod_defecto');
    $fecha        = getParam('fecha', date('Y-m-d'));
    $turno        = getParam('turno');
    $cod_articulo = getParam('cod_articulo');

    if ($cod_defecto === null || $cod_defecto === '') jsonError('cod_defecto requerido');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');

    $where  = ["CAST(hp.Dia_productivo AS DATE) = ?", "df.Cod_defecto = ?", "hpd.Activo = 1", "df.esNOK = 1"];
    $params = [$fecha, $cod_defecto];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $where[] = "ct.Cod_turno = ?";
        $params[] = $turno;
    }
    if ($cod_articulo) {
        $where[] = "prod.Cod_producto = ?";
        $params[] = $cod_articulo;
    }
    $where[] = "mq.Cod_maquina NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";

    $sql = "
        SELECT
            mq.Cod_maquina AS cod_maquina,
            mq.Desc_maquina AS maquina,
            MAX(df.Desc_defecto) AS motivo,
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
        WHERE " . implode(' AND ', $where) . "
        GROUP BY mq.Cod_maquina, mq.Desc_maquina
        HAVING SUM(hpd.Unidades) > 0
        ORDER BY unidades DESC
    ";

    $rows = fetchAll('mapex', $sql, $params);

    $totU = 0;
    foreach ($rows as $r) $totU += (int)$r['unidades'];

    $motivo = $rows[0]['motivo'] ?? '';
    $breakdown = [];
    foreach ($rows as $r) {
        $u = (int)$r['unidades'];
        $desc = $r['maquina'] ?: $r['cod_maquina'];
        $breakdown[] = [
            'cod_maquina'   => $r['cod_maquina'],
            'maquina'       => $desc,
            'seccion'       => seccionDeDesc($desc),
            'unidades'      => $u,
            'num_registros' => (int)$r['num_registros'],
            'pct'           => $totU > 0 ? round($u / $totU * 100, 2) : 0,
        ];
    }

    jsonOk([
        'cod_defecto'    => $cod_defecto,
        'motivo'         => $motivo,
        'fecha'          => $fecha,
        'turno'          => $turno ?: null,
        'cod_articulo'   => $cod_articulo ?: null,
        'total_unidades' => $totU,
        'breakdown'      => $breakdown,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
