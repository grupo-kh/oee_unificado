<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Detalle de un motivo de paro: desglose por máquina.
 * Acepta:
 *   - cod_paro (obligatorio): código del motivo
 *   - fecha, turno
 *   - cod_articulo (opcional)
 *
 * Devuelve {motivo, total_horas, breakdown: [{cod_maquina, maquina, seccion, horas, num_paros, pct}]}
 */

function seccionDeDesc(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

try {
    $cod_paro     = getParam('cod_paro');
    $fecha        = getParam('fecha', date('Y-m-d'));
    $turno        = getParam('turno');
    $cod_articulo = getParam('cod_articulo');

    if ($cod_paro === null || $cod_paro === '') jsonError('cod_paro requerido');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');

    $where  = ["CAST(hp.Dia_productivo AS DATE) = ?", "cp.Cod_paro = ?"];
    $params = [$fecha, (int)$cod_paro];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $where[] = "ct.Cod_turno = ?";
        $params[] = $turno;
    }
    if ($cod_articulo) {
        $where[] = "prod.Cod_producto = ?";
        $params[] = $cod_articulo;
    }
    $where[] = "mq.Cod_maquina NOT IN ('AUX000','AUXI1','SOLD4','SOLD5')";

    $sql = "
        SELECT
            mq.Cod_maquina AS cod_maquina,
            mq.Desc_maquina AS maquina,
            MAX(cp.Desc_paro) AS motivo,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, ISNULL(hpp.Fecha_fin, hpp.Fecha_ini))) AS segundos,
            COUNT(*) AS num_paros
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro cp ON cp.Id_paro = hpp.Id_paro
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        LEFT JOIN his_fase fa ON fa.Id_his_fase = hp.Id_his_fase
        LEFT JOIN his_of o ON o.Id_his_of = fa.Id_his_of
        LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        WHERE " . implode(' AND ', $where) . "
          AND hpp.Fecha_fin IS NOT NULL
        GROUP BY mq.Cod_maquina, mq.Desc_maquina
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY segundos DESC
    ";

    $rows = fetchAll('mapex', $sql, $params);

    $totSeg = 0;
    foreach ($rows as $r) $totSeg += (int)$r['segundos'];

    $motivo = $rows[0]['motivo'] ?? '';
    $breakdown = [];
    foreach ($rows as $r) {
        $seg = (int)$r['segundos'];
        $desc = $r['maquina'] ?: $r['cod_maquina'];
        $breakdown[] = [
            'cod_maquina' => $r['cod_maquina'],
            'maquina'     => $desc,
            'seccion'     => seccionDeDesc($desc),
            'horas'       => round($seg / 3600, 2),
            'minutos'     => round($seg / 60, 1),
            'num_paros'   => (int)$r['num_paros'],
            'pct'         => $totSeg > 0 ? round($seg / $totSeg * 100, 2) : 0,
        ];
    }

    jsonOk([
        'cod_paro'      => (int)$cod_paro,
        'motivo'        => $motivo,
        'fecha'         => $fecha,
        'turno'         => $turno ?: null,
        'cod_articulo'  => $cod_articulo ?: null,
        'total_segundos'=> $totSeg,
        'total_horas'   => round($totSeg / 3600, 2),
        'breakdown'     => $breakdown,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
