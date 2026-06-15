<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Referencias que causaron un MOTIVO de paro en una MÁQUINA dentro de una franja
 * horaria (reparto por solape horario, como en oee_unificado_motivo_drill por_hora).
 *
 * Sirve al drill: general → motivo → máquina → distribución horaria → (clic slot)
 * → "¿qué referencia se fabricaba durante ese paro?".
 *
 * GET:
 *   fecha_desde, fecha_hasta (YYYY-MM-DD)
 *   turnos       (CSV M,T,N)         opcional
 *   motivo       (Desc_paro)         obligatorio
 *   cod_maquina  (Cod_maquina)       obligatorio
 *   hora_desde, hora_hasta (0-23)    franja inclusiva; o `hora` para una sola
 *
 * Devuelve:
 *   { cod_maquina, motivo, hora_desde, hora_hasta,
 *     referencias: [ { cod_referencia, referencia, horas, minutos, num_paros }, ... ] }
 */
try {
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');
    $motivo = trim((string) ($_GET['motivo'] ?? ''));
    $cod    = trim((string) ($_GET['cod_maquina'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    if ($motivo === '') jsonError('motivo requerido');
    if ($cod === '')    jsonError('cod_maquina requerido');

    $hd = isset($_GET['hora_desde']) ? (int)$_GET['hora_desde'] : (isset($_GET['hora']) ? (int)$_GET['hora'] : 0);
    $hh = isset($_GET['hora_hasta']) ? (int)$_GET['hora_hasta'] : (isset($_GET['hora']) ? (int)$_GET['hora'] : 23);
    $hd = max(0, min(23, $hd));
    $hh = max(0, min(23, $hh));
    if ($hd > $hh) { $t = $hd; $hd = $hh; $hh = $t; }

    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    $where = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "hpp.Fecha_fin IS NOT NULL",
        "cp.Desc_paro = ?",
        "mq.Cod_maquina = ?",
    ];
    $params = [$fdesde, $fhasta, $motivo, $cod];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    // CTE de paros → buckets horarios (solape) → filtro de franja → agregado por referencia.
    $sql = "
        WITH paros AS (
            SELECT prod.Cod_producto AS cod_ref,
                   prod.Desc_producto AS ref,
                   hpp.Fecha_ini, hpp.Fecha_fin
            FROM his_prod_paro hpp
            INNER JOIN cfg_paro     cp   ON cp.Id_paro      = hpp.Id_paro
            INNER JOIN his_prod     hp   ON hp.Id_his_prod  = hpp.Id_his_prod
            INNER JOIN cfg_maquina  mq   ON mq.Id_maquina   = hp.Id_maquina
            INNER JOIN cfg_turno    ct   ON ct.Id_turno     = hp.Id_turno
            LEFT  JOIN his_fase     fa   ON fa.Id_his_fase  = hp.Id_his_fase
            LEFT  JOIN his_of       o    ON o.Id_his_of     = fa.Id_his_of
            LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
            WHERE " . implode(' AND ', $where) . "
        ),
        hour_slots AS (
            SELECT p.cod_ref, p.ref, p.Fecha_ini, p.Fecha_fin,
                   DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) AS slot_ini
            FROM paros p
            CROSS JOIN (VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),
                               (12),(13),(14),(15),(16),(17),(18),(19),(20),(21),(22),(23)) n(h)
            WHERE DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) < p.Fecha_fin
        )
        SELECT cod_ref,
               MAX(ref) AS ref,
               SUM(DATEDIFF(SECOND,
                   CASE WHEN Fecha_ini > slot_ini                   THEN Fecha_ini ELSE slot_ini END,
                   CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini) THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
               )) AS segundos,
               COUNT(DISTINCT Fecha_ini) AS num_paros
        FROM hour_slots
        WHERE DATEPART(HOUR, slot_ini) BETWEEN ? AND ?
        GROUP BY cod_ref
        HAVING SUM(DATEDIFF(SECOND,
                   CASE WHEN Fecha_ini > slot_ini                   THEN Fecha_ini ELSE slot_ini END,
                   CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini) THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
               )) > 0
        ORDER BY segundos DESC
    ";
    $params[] = $hd;
    $params[] = $hh;

    $rows = fetchAll('mapex', $sql, $params);
    $refs = [];
    foreach ($rows as $r) {
        $seg = (int) $r['segundos'];
        $cr  = (string) ($r['cod_ref'] ?? '');
        $refs[] = [
            'cod_referencia' => $cr,
            'referencia'     => (string) ($r['ref'] ?: ($cr !== '' ? $cr : '(sin referencia)')),
            'horas'          => round($seg / 3600, 2),
            'minutos'        => round($seg / 60, 1),
            'num_paros'      => (int) $r['num_paros'],
        ];
    }

    jsonOk([
        'cod_maquina' => $cod,
        'motivo'      => $motivo,
        'hora_desde'  => $hd,
        'hora_hasta'  => $hh,
        'referencias' => $refs,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
