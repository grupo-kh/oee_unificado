<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Detalle de pérdidas de rendimiento de una máquina: desglose por artículo.
 * Acepta:
 *   - cod_maquina (obligatorio)
 *   - fecha, turno
 *   - cod_articulo (opcional, sin uso aquí)
 */

function seccionDeDesc(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

try {
    $cod_maquina = getParam('cod_maquina');
    $fecha       = getParam('fecha', date('Y-m-d'));
    $turno       = getParam('turno');

    if (!$cod_maquina) jsonError('cod_maquina requerido');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');

    $where  = ["CAST(oee.TimePeriod AS DATE) = ?", "oee.WorkGroup = ?"];
    $params = [$fecha, $cod_maquina];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $where[] = "oee.Cod_turno = ?";
        $params[] = $turno;
    }

    // Pérdida de rendimiento = M − M_OKNOK_TEO
    $sql = "
        SELECT
            oee.Cod_producto AS cod_articulo,
            MAX(oee.Desc_producto) AS desc_articulo,
            SUM(oee.M) - SUM(oee.M_OKNOK_TEO) AS PPERF_min,
            SUM(oee.M) AS M_min,
            SUM(oee.M_OKNOK_TEO) AS MOT_min,
            SUM(oee.PCALIDAD) AS PC_min,
            SUM(oee.PPERF) AS PPERF_orig
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE " . implode(' AND ', $where) . "
        GROUP BY oee.Cod_producto
        HAVING SUM(oee.M) - SUM(oee.M_OKNOK_TEO) > 0
        ORDER BY PPERF_min DESC
    ";

    $rows = fetchAll('mapex', $sql, array_merge([$fecha, $fecha], $params));

    $totMin = 0;
    foreach ($rows as $r) $totMin += (float)$r['PPERF_min'];

    $metaRows = fetchAll('mapex', "SELECT Desc_maquina FROM cfg_maquina WHERE Cod_maquina = ?", [$cod_maquina]);
    $maquina = $metaRows[0]['Desc_maquina'] ?? $cod_maquina;

    $breakdown = [];
    foreach ($rows as $r) {
        // F_his_ct devuelve M y M_OKNOK_TEO en SEGUNDOS
        $pp_seg = (float)$r['PPERF_min'];              // M − MOT en segundos
        $ppOrig = (float)($r['PPERF_orig'] ?? 0);
        $M  = (float)$r['M_min']; $MOT = (float)$r['MOT_min']; $PC = (float)$r['PC_min'];
        $den = $M + $ppOrig + $PC;
        $rend = $den > 0 ? ($MOT + $PC) / $den * 100 : 0;
        $breakdown[] = [
            'cod_articulo'  => $r['cod_articulo'] ?? '--',
            'desc_articulo' => $r['desc_articulo'] ?? '',
            'minutos'       => round($pp_seg / 60, 1),
            'horas'         => round($pp_seg / 3600, 2),
            'rendimiento'   => round($rend, 2),
            'pct'           => $totMin > 0 ? round($pp_seg / $totMin * 100, 2) : 0,
        ];
    }

    jsonOk([
        'cod_maquina'   => $cod_maquina,
        'maquina'       => $maquina,
        'seccion'       => seccionDeDesc($maquina),
        'fecha'         => $fecha,
        'turno'         => $turno ?: null,
        'total_minutos' => round($totMin / 60, 1),
        'total_horas'   => round($totMin / 3600, 2),
        'breakdown'     => $breakdown,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
