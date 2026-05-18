<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Drill temporal asociado al panel "Máquina/Referencia → Motivos" (paso intermedio
 * de Disponibilidad). Soporta tres modos para un motivo concreto filtrado por una
 * máquina (cod_maquina) o por una referencia (cod_referencia, según `por`):
 *
 *  - mode=por_dia        : serie temporal con total de horas por día del rango.
 *  - mode=por_hora_dia   : para un día concreto (param `dia`), 24 buckets horarios
 *                          con horas de paro de ese motivo en ese día.
 *  - mode=paros          : para un día (`dia`) y una hora (`hora`, 0–23), los paros
 *                          individuales (Fecha_ini, duración) que ocurrieron en
 *                          esa franja. En modo referencia incluye la máquina.
 *
 * Parámetros comunes:
 *   fecha_desde, fecha_hasta (YYYY-MM-DD)
 *   turnos (CSV: M,T,N)
 *   motivo  (string, Desc_paro)
 *   por     (maquina|referencia)
 *   cod_maquina    (requerido si por=maquina)
 *   cod_referencia (requerido si por=referencia)
 */

/**
 * Construye los fragmentos comunes de SQL (joins + AND filtros) para filtrar
 * paros por motivo + entidad (máquina o referencia) + turnos.
 *
 * Devuelve [joinsSQL, filtrosExtraSQL, paramsExtra] que el llamador concatena
 * con su WHERE base (rango de fechas) y sus propios params en el orden correcto.
 */
function _maqMotFragmentos(string $por, string $cod, string $motivo, array $turnos): array {
    $joins = '';
    $filtros = [
        "cp.Cod_paro <> 11",
        "hpp.Fecha_fin IS NOT NULL",
        "cp.Desc_paro = ?",
    ];
    $params = [$motivo];

    if ($por === 'referencia') {
        $joins = "
            LEFT JOIN his_fase     fa   ON fa.Id_his_fase  = hp.Id_his_fase
            LEFT JOIN his_of       o    ON o.Id_his_of     = fa.Id_his_of
            LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        ";
        $filtros[] = "prod.Cod_producto = ?";
    } else {
        $filtros[] = "mq.Cod_maquina = ?";
    }
    $params[] = $cod;

    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $filtros[] = "ct.Cod_turno IN ($ph)";
        foreach ($turnos as $t) $params[] = $t;
    }
    return [$joins, implode(' AND ', $filtros), $params];
}

function _maqMotPorDia(string $fdesde, string $fhasta, array $turnos, string $por, string $cod, string $motivo): array {
    [$joins, $filtrosSQL, $extraParams] = _maqMotFragmentos($por, $cod, $motivo, $turnos);
    $params = array_merge([$fdesde, $fhasta], $extraParams);
    $sql = "
        SELECT
            CAST(hp.Dia_productivo AS DATE) AS dia,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos,
            COUNT(*) AS num_paros
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        $joins
        WHERE CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?
          AND $filtrosSQL
        GROUP BY CAST(hp.Dia_productivo AS DATE)
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY dia
    ";
    $rows = fetchAll('mapex', $sql, $params);

    // Serie continua (rellena días sin datos con 0 para no inducir saltos en el chart).
    $byDia = [];
    foreach ($rows as $r) {
        $d = substr((string)$r['dia'], 0, 10);
        $byDia[$d] = [
            'horas'     => round(((int)$r['segundos']) / 3600, 2),
            'num_paros' => (int) $r['num_paros'],
        ];
    }
    $out = [];
    $cur = new DateTime($fdesde);
    $fin = new DateTime($fhasta);
    while ($cur <= $fin) {
        $d = $cur->format('Y-m-d');
        $out[] = [
            'dia'       => $d,
            'horas'     => $byDia[$d]['horas']     ?? 0,
            'num_paros' => $byDia[$d]['num_paros'] ?? 0,
        ];
        $cur->modify('+1 day');
    }
    return $out;
}

function _maqMotPorHoraDia(string $dia, array $turnos, string $por, string $cod, string $motivo): array {
    [$joins, $filtrosSQL, $extraParams] = _maqMotFragmentos($por, $cod, $motivo, $turnos);
    $params = array_merge([$dia, $dia], $extraParams);
    $sql = "
        WITH paros AS (
            SELECT hpp.Fecha_ini, hpp.Fecha_fin
            FROM his_prod_paro hpp
            INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
            INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
            INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
            INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
            $joins
            WHERE CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?
              AND $filtrosSQL
        ),
        hour_slots AS (
            SELECT
                p.Fecha_ini, p.Fecha_fin,
                DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) AS slot_ini
            FROM paros p
            CROSS JOIN (VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),
                               (12),(13),(14),(15),(16),(17),(18),(19),(20),(21),(22),(23)) n(h)
            WHERE DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) < p.Fecha_fin
        )
        SELECT
            DATEPART(HOUR, slot_ini) AS hora,
            SUM(DATEDIFF(SECOND,
                CASE WHEN Fecha_ini > slot_ini                   THEN Fecha_ini ELSE slot_ini END,
                CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini) THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
            )) AS segundos
        FROM hour_slots
        GROUP BY DATEPART(HOUR, slot_ini)
    ";
    $rows = fetchAll('mapex', $sql, $params);
    $bySlot = [];
    foreach ($rows as $r) {
        $h = (int) $r['hora'];
        if ($h < 0 || $h > 23) continue;
        $bySlot[$h] = (int) $r['segundos'];
    }
    $out = [];
    for ($h = 0; $h < 24; $h++) {
        $out[] = [
            'hora'  => $h,
            'horas' => round(($bySlot[$h] ?? 0) / 3600, 2),
        ];
    }
    return $out;
}

function _maqMotParos(string $dia, int $hora, array $turnos, string $por, string $cod, string $motivo): array {
    [$joins, $filtrosSQL, $extraParams] = _maqMotFragmentos($por, $cod, $motivo, $turnos);

    // En modo Máquina los joins a cfg_producto NO se añaden en _maqMotFragmentos;
    // los añadimos aquí como LEFT JOIN para poder devolver la referencia que se
    // estaba produciendo durante el paro. En modo Referencia ya están en $joins.
    $prodJoins = ($por === 'maquina') ? "
        LEFT JOIN his_fase     fa   ON fa.Id_his_fase  = hp.Id_his_fase
        LEFT JOIN his_of       o    ON o.Id_his_of     = fa.Id_his_of
        LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
    " : '';

    // Filtro por SOLAPE con la franja [dia hora:00, dia hora+1:00) — alineado con
    // el chart 24h que reparte segundos por superposición. Antes filtrábamos por
    // DATEPART(HOUR, Fecha_ini), lo que dejaba fuera paros que cruzaban a la hora
    // desde la anterior y producía el mensaje engañoso "sin paros".
    $params = array_merge([$hora, $dia, $hora, $dia, $dia], $extraParams);
    $sql = "
        SELECT
            mq.Cod_maquina  AS cod_maquina,
            mq.Desc_maquina AS maquina,
            prod.Cod_producto  AS cod_referencia,
            prod.Desc_producto AS referencia,
            CONVERT(varchar(19), hpp.Fecha_ini, 120) AS fecha_ini,
            CONVERT(varchar(19), hpp.Fecha_fin, 120) AS fecha_fin,
            DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        $joins
        $prodJoins
        WHERE hpp.Fecha_ini < DATEADD(HOUR, CAST(? AS INT) + 1, CAST(? AS DATETIME))
          AND hpp.Fecha_fin > DATEADD(HOUR, CAST(? AS INT),     CAST(? AS DATETIME))
          AND CAST(hp.Dia_productivo AS DATE) = ?
          AND $filtrosSQL
        ORDER BY hpp.Fecha_ini
    ";
    $rows = fetchAll('mapex', $sql, $params);
    $out = [];
    foreach ($rows as $r) {
        $segs = (int) $r['segundos'];
        $fi = (string) $r['fecha_ini'];
        $codRef = isset($r['cod_referencia']) ? (string) $r['cod_referencia'] : '';
        $refDes = isset($r['referencia'])     ? (string) $r['referencia']     : '';
        $out[] = [
            'cod_maquina'    => (string) $r['cod_maquina'],
            'maquina'        => (string) ($r['maquina'] ?: $r['cod_maquina']),
            'cod_referencia' => $codRef,
            'referencia'     => $refDes ?: $codRef,
            'fecha_ini'      => $fi,
            'fecha_fin'      => (string) $r['fecha_fin'],
            'hora_ini'       => substr($fi, 11, 8), // HH:MM:SS
            'segundos'       => $segs,
            'minutos'        => round($segs / 60, 1),
            'horas'          => round($segs / 3600, 2),
        ];
    }
    return $out;
}

try {
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');
    $motivo = (string) ($_GET['motivo'] ?? '');
    $por    = (string) ($_GET['por']    ?? 'maquina');
    if (!in_array($por, ['maquina', 'referencia'], true)) $por = 'maquina';
    $cod    = $por === 'referencia'
              ? (string) ($_GET['cod_referencia'] ?? '')
              : (string) ($_GET['cod_maquina']    ?? '');
    $mode   = (string) ($_GET['mode'] ?? 'por_dia');
    if (!in_array($mode, ['por_dia', 'por_hora_dia', 'paros'], true)) {
        jsonError('mode inválido (por_dia | por_hora_dia | paros)');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    if ($motivo === '') jsonError('motivo requerido');
    if ($cod    === '') jsonError(($por === 'referencia' ? 'cod_referencia' : 'cod_maquina') . ' requerido');

    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    if ($mode === 'por_dia') {
        $dias = _maqMotPorDia($fdesde, $fhasta, $turnos, $por, $cod, $motivo);
        jsonOk(['mode' => 'por_dia', 'dias' => $dias]);
    } elseif ($mode === 'por_hora_dia') {
        $dia = (string) ($_GET['dia'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) jsonError('dia inválido');
        $horas = _maqMotPorHoraDia($dia, $turnos, $por, $cod, $motivo);
        jsonOk(['mode' => 'por_hora_dia', 'dia' => $dia, 'horas' => $horas]);
    } else {
        $dia  = (string) ($_GET['dia']  ?? '');
        $hora = (int)    ($_GET['hora'] ?? -1);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) jsonError('dia inválido');
        if ($hora < 0 || $hora > 23) jsonError('hora inválida (0-23)');
        $paros = _maqMotParos($dia, $hora, $turnos, $por, $cod, $motivo);
        jsonOk(['mode' => 'paros', 'dia' => $dia, 'hora' => $hora, 'paros' => $paros]);
    }

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
