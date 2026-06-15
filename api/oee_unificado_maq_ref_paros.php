<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Paros temporales para una combinación máquina + referencia.
 *
 * Devuelve los paros individuales mientras se fabricaba esa referencia en
 * esa máquina, ordenados cronológicamente. Pensado para pintar un chart
 * "horas acumuladas a lo largo del tiempo" — el frontend acumula las
 * horas para mostrar la curva de cómo se han ido acumulando los problemas.
 *
 * GET:
 *   - fecha_desde, fecha_hasta   (YYYY-MM-DD, obligatorio)
 *   - cod_maquina                (obligatorio)
 *   - cod_referencia             (obligatorio)
 *   - turnos                     (CSV M,T,N — opc.)
 *
 * Devuelve:
 *   {
 *     "ok": true,
 *     "cod_maquina": "...",
 *     "cod_referencia": "...",
 *     "paros": [
 *       { "fecha_ini": "2026-06-01 08:13:00", "fecha_fin": "...",
 *         "motivo": "Avería mecánica", "horas": 0.42 },
 *       ...
 *     ],
 *     "por_dia": [
 *       { "dia": "2026-06-01", "horas": 1.83 },
 *       ...
 *     ]
 *   }
 */

try {
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');
    $codMaq = trim((string) getParam('cod_maquina', ''));
    $codRef = trim((string) getParam('cod_referencia', ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    if ($codMaq === '') jsonError('cod_maquina obligatorio');
    if ($codRef === '') jsonError('cod_referencia obligatorio');

    $turnos = array_values(array_filter(getListParam('turnos'),
        fn($t) => in_array($t, ['M','T','N'], true)));

    $where = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "hpp.Fecha_fin IS NOT NULL",
        "mq.Cod_maquina = ?",
        "prod.Cod_producto = ?",
    ];
    $params = [$fdesde, $fhasta, $codMaq, $codRef];

    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params  = array_merge($params, $turnos);
    }

    // Tope de paros individuales para evitar payloads enormes.
    $TOP = 5000;
    $sql = "
        SELECT TOP $TOP
               hpp.Fecha_ini AS fecha_ini,
               hpp.Fecha_fin AS fecha_fin,
               cp.Desc_paro  AS motivo,
               DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro     cp   ON cp.Id_paro      = hpp.Id_paro
        INNER JOIN his_prod     hp   ON hp.Id_his_prod  = hpp.Id_his_prod
        INNER JOIN cfg_maquina  mq   ON mq.Id_maquina   = hp.Id_maquina
        INNER JOIN cfg_turno    ct   ON ct.Id_turno     = hp.Id_turno
        LEFT  JOIN his_fase     fa   ON fa.Id_his_fase  = hp.Id_his_fase
        LEFT  JOIN his_of       o    ON o.Id_his_of     = fa.Id_his_of
        LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        WHERE " . implode(' AND ', $where) . "
          AND DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) > 0
        ORDER BY hpp.Fecha_ini
    ";
    $rows = fetchAll('mapex', $sql, $params);

    // Lista plana de paros (para tooltip / detalle).
    $paros = [];
    // Agrupación por día (para el chart temporal acumulado).
    $porDiaMap = [];
    foreach ($rows as $r) {
        $seg   = (int) $r['segundos'];
        $horas = round($seg / 3600, 4);
        $paros[] = [
            'fecha_ini' => (string) $r['fecha_ini'],
            'fecha_fin' => (string) $r['fecha_fin'],
            'motivo'    => (string) ($r['motivo'] ?: '(sin nombre)'),
            'horas'     => $horas,
        ];
        $dia = substr((string) $r['fecha_ini'], 0, 10); // YYYY-MM-DD
        $porDiaMap[$dia] = ($porDiaMap[$dia] ?? 0) + $horas;
    }
    ksort($porDiaMap);
    $porDia = [];
    foreach ($porDiaMap as $d => $h) {
        $porDia[] = ['dia' => $d, 'horas' => round($h, 4)];
    }

    jsonOk([
        'cod_maquina'    => $codMaq,
        'cod_referencia' => $codRef,
        'paros'          => $paros,
        'por_dia'        => $porDia,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
