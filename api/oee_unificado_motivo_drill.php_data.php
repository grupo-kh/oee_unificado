<?php
/**
 * Helpers reutilizables por oee_unificado_export.php para extraer datos del
 * drill por motivo (detalle por máquina + distribución horaria) sin invocar
 * jsonOk/jsonError ni hacer fetch HTTP. Refleja la lógica de
 * oee_unificado_motivo_drill.php pero devolviendo arrays.
 */

if (!function_exists('_exportMotivoDrillDetalle')) {

function _exportMotivoDrillDetalle(string $fdesde, string $fhasta, array $turnos, string $metrica, array $codMaqsSeccion, string $motivo): array
{
    // Construye el filtro WHERE de la sección
    if (empty($codMaqsSeccion)) return [];

    if (in_array($metrica, ['disponibilidad','oee'], true)) {
        return _exportMotivoDetalleParos($fdesde, $fhasta, $turnos, $codMaqsSeccion, $motivo);
    }
    if ($metrica === 'calidad') {
        return _exportMotivoDetalleCalidad($fdesde, $fhasta, $turnos, $codMaqsSeccion, $motivo);
    }
    if ($metrica === 'rendimiento') {
        return _exportMotivoDetalleRendimiento($fdesde, $fhasta, $turnos, $codMaqsSeccion, $motivo);
    }
    return [];
}

function _exportMotivoDetalleParos(string $fdesde, string $fhasta, array $turnos, array $codMaqs, string $motivo): array
{
    $where = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "hpp.Fecha_fin IS NOT NULL",
        "cp.Desc_paro = ?",
    ];
    $params = [$fdesde, $fhasta, $motivo];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    $ph = implode(',', array_fill(0, count($codMaqs), '?'));
    $where[] = "mq.Cod_maquina IN ($ph)";
    $params = array_merge($params, $codMaqs);

    $sql = "
        SELECT mq.Cod_maquina AS cod_maquina, mq.Desc_maquina AS maquina,
               SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY mq.Cod_maquina, mq.Desc_maquina
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY segundos DESC
    ";
    $rows = fetchAll('mapex', $sql, $params);

    $totSeg = 0;
    foreach ($rows as $r) $totSeg += (int)$r['segundos'];

    $out = [];
    foreach ($rows as $r) {
        $seg = (int)$r['segundos'];
        $pct = $totSeg > 0 ? $seg / $totSeg * 100 : 0;
        $out[] = [
            'cod_maquina' => $r['cod_maquina'],
            'maquina'     => $r['maquina'] ?: $r['cod_maquina'],
            'horas'       => round($seg / 3600, 2),
            'pct'         => round($pct, 2),
        ];
    }
    return $out;
}

function _exportMotivoDetalleCalidad(string $fdesde, string $fhasta, array $turnos, array $codMaqs, string $motivo): array
{
    $where = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "hpd.Activo = 1",
        "df.esNOK = 1",
        "df.Desc_defecto = ?",
    ];
    $params = [$fdesde, $fhasta, $motivo];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    $ph = implode(',', array_fill(0, count($codMaqs), '?'));
    $where[] = "mq.Cod_maquina IN ($ph)";
    $params = array_merge($params, $codMaqs);

    $sql = "
        SELECT mq.Cod_maquina AS cod_maquina, mq.Desc_maquina AS maquina,
               SUM(hpd.Unidades) AS unidades
        FROM his_prod_defecto hpd
        INNER JOIN cfg_defecto df ON df.Id_defecto = hpd.Id_defecto
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpd.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY mq.Cod_maquina, mq.Desc_maquina
        HAVING SUM(hpd.Unidades) > 0
        ORDER BY unidades DESC
    ";
    $rows = fetchAll('mapex', $sql, $params);

    $totU = 0;
    foreach ($rows as $r) $totU += (int)$r['unidades'];

    $out = [];
    foreach ($rows as $r) {
        $u = (int)$r['unidades'];
        $pct = $totU > 0 ? $u / $totU * 100 : 0;
        $out[] = [
            'maquina'  => $r['maquina'] ?: $r['cod_maquina'],
            'unidades' => $u,
            'pct'      => round($pct, 2),
        ];
    }
    return $out;
}

function _exportMotivoDetalleRendimiento(string $fdesde, string $fhasta, array $turnos, array $codMaqs, string $motivo): array
{
    $where = [
        "CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?",
        "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')",
        "oee.Cod_producto IS NOT NULL",
        "oee.Cod_producto <> '--'",
    ];
    $params = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "oee.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    $ph = implode(',', array_fill(0, count($codMaqs), '?'));
    $where[] = "oee.WorkGroup IN ($ph)";
    $params = array_merge($params, $codMaqs);
    $where[] = "(oee.Desc_producto = ? OR oee.Cod_producto = ?)";
    $params[] = $motivo;
    $params[] = $motivo;
    $whereSQL = implode(' AND ', $where);

    $sql = "
        SELECT oee.WorkGroup AS cod_maquina, mq.Desc_maquina AS maquina,
               SUM(oee.M) - SUM(oee.M_OKNOK_TEO) AS perdida_seg
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE $whereSQL
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) - SUM(oee.M_OKNOK_TEO) > 0
        ORDER BY perdida_seg DESC
    ";
    $allParams = array_merge([$fdesde, $fhasta], $params);
    $rows = fetchAll('mapex', $sql, $allParams);

    $totSeg = 0;
    foreach ($rows as $r) $totSeg += (float)$r['perdida_seg'];

    $out = [];
    foreach ($rows as $r) {
        $seg = (float)$r['perdida_seg'];
        $pct = $totSeg > 0 ? $seg / $totSeg * 100 : 0;
        $out[] = [
            'maquina' => $r['maquina'] ?: $r['cod_maquina'],
            'horas'   => round($seg / 3600, 2),
            'pct'     => round($pct, 2),
        ];
    }
    return $out;
}

/** Top-5 + Otras + matriz horas (replica _shapePorHora de motivo_drill). */
function _exportShapePorHora(array $rows, string $unidad): ?array
{
    if (empty($rows)) return null;
    $totMaq = [];
    foreach ($rows as $r) {
        $cod = (string) $r['cod_maquina'];
        $val = (float) $r['valor'];
        if (!isset($totMaq[$cod])) $totMaq[$cod] = ['maquina' => $r['maquina'] ?: $cod, 'total' => 0.0];
        $totMaq[$cod]['total'] += $val;
    }
    if (empty($totMaq)) return null;
    uasort($totMaq, fn($a, $b) => $b['total'] <=> $a['total']);

    $topN = 5;
    $codTop = array_slice(array_keys($totMaq), 0, $topN);
    $hayOtras = count($totMaq) > $topN;

    $maquinasOut = [];
    foreach ($codTop as $cod) $maquinasOut[] = ['cod_maquina' => $cod, 'maquina' => $totMaq[$cod]['maquina']];
    if ($hayOtras) $maquinasOut[] = ['cod_maquina' => '__OTRAS__', 'maquina' => 'Otras'];

    $horasMat = [];
    for ($h = 0; $h < 24; $h++) $horasMat[$h] = [];
    foreach ($rows as $r) {
        $cod = (string) $r['cod_maquina'];
        $h   = (int) $r['hora'];
        $val = (float) $r['valor'];
        if ($h < 0 || $h > 23) continue;
        $key = in_array($cod, $codTop, true) ? $cod : '__OTRAS__';
        $horasMat[$h][$key] = ($horasMat[$h][$key] ?? 0) + $val;
    }
    $horasOut = [];
    for ($h = 0; $h < 24; $h++) {
        $row = ['h' => $h];
        foreach ($maquinasOut as $m) {
            $raw = $horasMat[$h][$m['cod_maquina']] ?? 0;
            $row[$m['cod_maquina']] = ($unidad === 'h') ? round($raw / 3600, 2) : (int) round($raw);
        }
        $horasOut[] = $row;
    }
    return ['unidad' => $unidad, 'maquinas' => $maquinasOut, 'horas' => $horasOut];
}

/** Devuelve la estructura por_hora del JSON, o null si no aplica. */
function _exportMotivoDrillPorHora(string $fdesde, string $fhasta, array $turnos, string $metrica, array $codMaqsSeccion, string $motivo, ?string $codMaqFiltro): ?array
{
    if (empty($codMaqsSeccion)) return null;
    // Aceptamos $codMaqsSeccion como:
    //   - lista indexada de cod_maquina (legacy export_pdf/export_xlsx)
    //   - mapa asociativo [cod_maquina => desc_maquina] (top análisis)
    // En el segundo caso, lo que necesitamos para el WHERE son las CLAVES.
    $codMaqsList = array_is_list($codMaqsSeccion)
        ? array_values($codMaqsSeccion)
        : array_keys($codMaqsSeccion);

    if ($codMaqFiltro && in_array($codMaqFiltro, $codMaqsList, true)) {
        $codMaqsList = [$codMaqFiltro];
    }
    // Trabajamos siempre con array indexado (re-clave numérica) para que
    // array_merge con $params no introduzca claves string que confunden al
    // driver SQLSRV ("parameter number 65536").
    $codMaqs = array_values($codMaqsList);

    if (in_array($metrica, ['disponibilidad','oee'], true)) {
        $where = [
            "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
            "cp.Cod_paro <> 11",
            "hpp.Fecha_fin IS NOT NULL",
            "cp.Desc_paro = ?",
        ];
        $params = [$fdesde, $fhasta, $motivo];
        if (!empty($turnos)) {
            $ph = implode(',', array_fill(0, count($turnos), '?'));
            $where[] = "ct.Cod_turno IN ($ph)";
            $params = array_merge($params, $turnos);
        }
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "mq.Cod_maquina IN ($ph)";
        $params = array_merge($params, $codMaqs);

        $sql = "
            WITH paros AS (
                SELECT mq.Cod_maquina AS cod_maquina, mq.Desc_maquina AS maquina,
                       hpp.Fecha_ini, hpp.Fecha_fin
                FROM his_prod_paro hpp
                INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
                INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
                INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
                INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
                WHERE " . implode(' AND ', $where) . "
            ),
            hour_slots AS (
                SELECT p.cod_maquina, p.maquina, p.Fecha_ini, p.Fecha_fin,
                       DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) AS slot_ini
                FROM paros p
                CROSS JOIN (VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),
                                   (12),(13),(14),(15),(16),(17),(18),(19),(20),(21),(22),(23)) n(h)
                WHERE DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) < p.Fecha_fin
            )
            SELECT cod_maquina, maquina, DATEPART(HOUR, slot_ini) AS hora,
                   SUM(DATEDIFF(SECOND,
                       CASE WHEN Fecha_ini > slot_ini                   THEN Fecha_ini ELSE slot_ini END,
                       CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini) THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
                   )) AS valor
            FROM hour_slots
            GROUP BY cod_maquina, maquina, DATEPART(HOUR, slot_ini)
            HAVING SUM(DATEDIFF(SECOND,
                       CASE WHEN Fecha_ini > slot_ini                   THEN Fecha_ini ELSE slot_ini END,
                       CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini) THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
                   )) > 0
        ";
        $rows = fetchAll('mapex', $sql, $params);
        return _exportShapePorHora($rows, 'h');
    }

    if ($metrica === 'calidad') {
        $where = [
            "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
            "hpd.Activo = 1", "df.esNOK = 1", "df.Desc_defecto = ?",
        ];
        $params = [$fdesde, $fhasta, $motivo];
        if (!empty($turnos)) {
            $ph = implode(',', array_fill(0, count($turnos), '?'));
            $where[] = "ct.Cod_turno IN ($ph)";
            $params = array_merge($params, $turnos);
        }
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "mq.Cod_maquina IN ($ph)";
        $params = array_merge($params, $codMaqs);

        $sql = "
            SELECT mq.Cod_maquina AS cod_maquina, mq.Desc_maquina AS maquina,
                   DATEPART(HOUR, hp.Fecha_ini) AS hora,
                   SUM(hpd.Unidades) AS valor
            FROM his_prod_defecto hpd
            INNER JOIN cfg_defecto df ON df.Id_defecto    = hpd.Id_defecto
            INNER JOIN his_prod    hp ON hp.Id_his_prod   = hpd.Id_his_prod
            INNER JOIN cfg_maquina mq ON mq.Id_maquina    = hp.Id_maquina
            INNER JOIN cfg_turno   ct ON ct.Id_turno      = hp.Id_turno
            WHERE " . implode(' AND ', $where) . "
            GROUP BY mq.Cod_maquina, mq.Desc_maquina, DATEPART(HOUR, hp.Fecha_ini)
            HAVING SUM(hpd.Unidades) > 0
        ";
        $rows = fetchAll('mapex', $sql, $params);
        return _exportShapePorHora($rows, 'uds');
    }

    if ($metrica === 'rendimiento') {
        $where = [
            "CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?",
            "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')",
            "oee.Cod_producto IS NOT NULL",
            "oee.Cod_producto <> '--'",
        ];
        $params = [$fdesde, $fhasta];
        if (!empty($turnos)) {
            $ph = implode(',', array_fill(0, count($turnos), '?'));
            $where[] = "oee.Cod_turno IN ($ph)";
            $params = array_merge($params, $turnos);
        }
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "oee.WorkGroup IN ($ph)";
        $params = array_merge($params, $codMaqs);
        $where[] = "(oee.Desc_producto = ? OR oee.Cod_producto = ?)";
        $params[] = $motivo; $params[] = $motivo;

        $sql = "
            SELECT oee.WorkGroup AS cod_maquina, mq.Desc_maquina AS maquina,
                   DATEPART(HOUR, oee.TimePeriod) AS hora,
                   SUM(oee.M) - SUM(oee.M_OKNOK_TEO) AS valor
            FROM F_his_ct('WORKCENTER','HOUR','TURNOS, PRODUCTOS',
                          ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
            LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
            WHERE " . implode(' AND ', $where) . "
            GROUP BY oee.WorkGroup, mq.Desc_maquina, DATEPART(HOUR, oee.TimePeriod)
            HAVING SUM(oee.M) - SUM(oee.M_OKNOK_TEO) > 0
        ";
        $allParams = array_merge([$fdesde, $fhasta], $params);
        try {
            $rows = fetchAll('mapex', $sql, $allParams);
        } catch (Exception $e) {
            error_log("export por_hora rendimiento F_his_ct(HOUR): " . $e->getMessage());
            return null;
        }
        return _exportShapePorHora($rows, 'h');
    }

    return null;
}

} // end if !function_exists
