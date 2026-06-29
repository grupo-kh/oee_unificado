<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Drill-down inverso: dado un motivo concreto, devuelve el desglose por máquina.
 *
 * Parámetros:
 *   - fecha_desde, fecha_hasta (YYYY-MM-DD)
 *   - turnos (CSV: M,T,N)
 *   - seccion (VARILLAS | TROQUELADOS)
 *   - metrica (disponibilidad | rendimiento | calidad | oee)
 *   - motivo (string) — nombre del motivo/artículo a desglosar
 *
 * Devuelve:
 *   - detalle: [{ cod_maquina, maquina, valor (horas|unidades), pct }, ...] ordenado DESC
 */

function _seccion(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

try {
    $fdesde     = (string) getParam('fecha_desde');
    $fhasta     = (string) getParam('fecha_hasta');
    // Multi-sección: parseSecciones() ya sanitiza contra la lista permitida.
    // Set vacío = sin filtro = TODAS (comportamiento histórico).
    $secciones  = parseSecciones(['VARILLAS', 'TROQUELADOS']);
    $todasSec   = empty($secciones);
    $metrica    = (string) getParam('metrica');
    $motivo     = (string) ($_GET['motivo'] ?? '');
    $codMaqHora = isset($_GET['cod_maquina']) ? trim((string)$_GET['cod_maquina']) : '';
    $codRefHora = isset($_GET['cod_referencia']) ? trim((string)$_GET['cod_referencia']) : '';
    // Segmentación: 'maquina' (por defecto) | 'referencia'. Solo aplica a disponibilidad/oee.
    $por = (string) ($_GET['por'] ?? 'maquina');
    if (!in_array($por, ['maquina', 'referencia'], true)) $por = 'maquina';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    // parseSecciones() ya sanitiza; set vacío = todas (válido). Sin validación escalar.
    if (!in_array($metrica, ['disponibilidad', 'rendimiento', 'calidad', 'oee'], true)) jsonError('metrica inválida');
    if ($motivo === '') jsonError('motivo requerido');

    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));
    $excl   = getListParam('excl');

    // Resolver cod_maquinas de la(s) sección(es) (ya filtradas por exclusión global)
    $codMaqsSeccion = _resolverMaqsSeccion($fdesde, $fhasta, $turnos, $secciones, $todasSec, $excl);

    // Si viene cod_maquina, filtramos SOLO el por_hora a esa máquina;
    // el "detalle" (por máquina) sigue mostrando todas para contexto.
    $codMaqsHora = $codMaqsSeccion;
    if ($codMaqHora !== '' && isset($codMaqsSeccion[$codMaqHora])) {
        $codMaqsHora = [$codMaqHora => $codMaqsSeccion[$codMaqHora]];
    }

    $detalle  = [];
    $porHora  = null;
    if (in_array($metrica, ['disponibilidad', 'oee'], true)) {
        if ($por === 'referencia') {
            // Segmentación por referencia (Cod_producto). Filtra hora por referencia si se ha clicado.
            $codRefHoraFilter = ($codRefHora !== '') ? $codRefHora : null;
            $detalle = _parosPorReferencia($fdesde, $fhasta, $turnos, $codMaqsSeccion, $motivo);
            $porHora = _parosPorHoraReferencia($fdesde, $fhasta, $turnos, $codMaqsSeccion, $motivo, $codRefHoraFilter);
        } else {
            $detalle = _parosPorMaquina($fdesde, $fhasta, $turnos, $codMaqsSeccion, $motivo);
            $porHora = _parosPorHora($fdesde, $fhasta, $turnos, $codMaqsHora, $motivo);
        }
    } elseif ($metrica === 'calidad') {
        $detalle = _rechazosPorMaquina($fdesde, $fhasta, $turnos, $codMaqsSeccion, $motivo);
        $porHora = _rechazosPorHora($fdesde, $fhasta, $turnos, $codMaqsHora, $motivo);
    } elseif ($metrica === 'rendimiento') {
        $detalle = _perdidaRendPorMaquina($fdesde, $fhasta, $turnos, $codMaqsSeccion, $motivo);
        $porHora = _perdidaRendPorHora($fdesde, $fhasta, $turnos, $codMaqsHora, $motivo);
    }

    jsonOk([
        'seccion'  => $todasSec ? 'TODAS' : implode(',', $secciones),
        'metrica'  => $metrica,
        'motivo'   => $motivo,
        'por'      => $por,
        'detalle'  => $detalle,
        'por_hora' => $porHora,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}

// ───── Helpers ─────

function _resolverMaqsSeccion(string $fdesde, string $fhasta, array $turnos, array $secciones, bool $todasSec, array $excl = []): array
{
    $where = [
        "CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?",
        "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')",
    ];
    $params = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "oee.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    if (!empty($excl)) {
        $ph = implode(',', array_fill(0, count($excl), '?'));
        $where[] = "oee.WorkGroup NOT IN ($ph)";
        $params = array_merge($params, $excl);
    }
    $whereSQL = implode(' AND ', $where);
    $sql = "
        SELECT oee.WorkGroup AS cod_maquina, mq.Desc_maquina AS maquina
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE $whereSQL
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
    ";
    $rows = fetchAll('mapex', $sql, array_merge([$fdesde, $fhasta], $params));
    $out = [];
    foreach ($rows as $r) {
        if ($todasSec || in_array(_seccion($r['maquina']), $secciones, true)) {
            $out[$r['cod_maquina']] = $r['maquina'] ?: $r['cod_maquina'];
        }
    }
    return $out; // [cod_maquina => desc_maquina]
}

function _parosPorMaquina(string $fdesde, string $fhasta, array $turnos, array $maqMap, string $motivo): array
{
    if (empty($maqMap)) return [];
    $codMaqs = array_keys($maqMap);

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
        SELECT
            mq.Cod_maquina AS cod_maquina,
            mq.Desc_maquina AS maquina,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro cp ON cp.Id_paro = hpp.Id_paro
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
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
            'minutos'     => round($seg / 60, 1),
            'pct'         => round($pct, 2),
        ];
    }
    return $out;
}

/**
 * Variante "por referencia": atribuye los segundos de paro al Cod_producto que la
 * máquina estaba produciendo cuando ocurrió el paro (cadena his_prod → his_fase →
 * his_of → cfg_producto).  Solo se contabilizan paros con producto identificado.
 */
function _parosPorReferencia(string $fdesde, string $fhasta, array $turnos, array $maqMap, string $motivo): array
{
    if (empty($maqMap)) return [];
    $codMaqs = array_keys($maqMap);

    $where = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "hpp.Fecha_fin IS NOT NULL",
        "cp.Desc_paro = ?",
        "prod.Cod_producto IS NOT NULL",
        "prod.Cod_producto <> '--'",
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
        SELECT
            prod.Cod_producto  AS cod_referencia,
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

    $totSeg = 0;
    foreach ($rows as $r) $totSeg += (int)$r['segundos'];

    $out = [];
    foreach ($rows as $r) {
        $seg = (int)$r['segundos'];
        $pct = $totSeg > 0 ? $seg / $totSeg * 100 : 0;
        $cod = (string) $r['cod_referencia'];
        $des = (string) ($r['referencia'] ?: $cod);
        $out[] = [
            'cod_referencia' => $cod,
            'referencia'     => $des,
            // Campos "compatibles" con el render de máquina: cod_maquina/maquina
            // permiten reusar el front sin duplicar lógica.
            'cod_maquina'    => $cod,
            'maquina'        => $des,
            'horas'          => round($seg / 3600, 2),
            'minutos'        => round($seg / 60, 1),
            'pct'            => round($pct, 2),
        ];
    }
    return $out;
}

function _rechazosPorMaquina(string $fdesde, string $fhasta, array $turnos, array $maqMap, string $motivo): array
{
    if (empty($maqMap)) return [];
    $codMaqs = array_keys($maqMap);

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
        SELECT
            mq.Cod_maquina AS cod_maquina,
            mq.Desc_maquina AS maquina,
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
            'cod_maquina' => $r['cod_maquina'],
            'maquina'     => $r['maquina'] ?: $r['cod_maquina'],
            'unidades'    => $u,
            'pct'         => round($pct, 2),
        ];
    }
    return $out;
}

function _perdidaRendPorMaquina(string $fdesde, string $fhasta, array $turnos, array $maqMap, string $motivo): array
{
    if (empty($maqMap)) return [];
    $codMaqs = array_keys($maqMap);

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

    // Filtrar por el artículo (motivo puede ser Desc_producto o Cod_producto)
    $where[] = "(oee.Desc_producto = ? OR oee.Cod_producto = ?)";
    $params[] = $motivo;
    $params[] = $motivo;

    $whereSQL = implode(' AND ', $where);
    $sql = "
        SELECT
            oee.WorkGroup AS cod_maquina,
            mq.Desc_maquina AS maquina,
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
            'cod_maquina' => $r['cod_maquina'],
            'maquina'     => $r['maquina'] ?: $r['cod_maquina'],
            'horas'       => round($seg / 3600, 2),
            'minutos'     => round($seg / 60, 1),
            'pct'         => round($pct, 2),
        ];
    }
    return $out;
}

// ───── por_hora: top 5 máquinas + "Otras", 24 buckets siempre ─────

/**
 * Transforma filas [{cod_maquina, maquina, hora, valor}, ...] en la
 * estructura por_hora del JSON: top 5 máquinas + "Otras" si hay ≥6, y
 * matriz horas[0..23] con un campo por máquina.
 *
 * @param string $unidad "h" (segundos→horas) o "uds" (entero)
 */
function _shapePorHora(array $rows, string $unidad): ?array
{
    if (empty($rows)) return null;

    $totMaq = []; // cod_maquina => [maquina, total]
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
    foreach ($codTop as $cod) {
        // (string) fuerza que codigos numéricos (referencias tipo 193033650001)
        // no se serialicen como JSON number, lo que rompía la lookup en el cliente.
        $maquinasOut[] = ['cod_maquina' => (string)$cod, 'maquina' => $totMaq[$cod]['maquina']];
    }
    if ($hayOtras) {
        $maquinasOut[] = ['cod_maquina' => '__OTRAS__', 'maquina' => 'Otras'];
    }

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
            $cod = $m['cod_maquina'];
            $raw = $horasMat[$h][$cod] ?? 0;
            $row[$cod] = ($unidad === 'h') ? round($raw / 3600, 2) : (int) round($raw);
        }
        $horasOut[] = $row;
    }

    return [
        'unidad'   => $unidad,
        'maquinas' => $maquinasOut,
        'horas'    => $horasOut,
    ];
}

function _parosPorHora(string $fdesde, string $fhasta, array $turnos, array $maqMap, string $motivo): ?array
{
    if (empty($maqMap)) return null;
    $codMaqs = array_keys($maqMap);

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

    // CTE de paros + slots por hora (anclados a la hora truncada de Fecha_ini,
    // extendidos hasta 24 buckets). Maneja correctamente paros que cruzan medianoche.
    $sql = "
        WITH paros AS (
            SELECT
                mq.Cod_maquina  AS cod_maquina,
                mq.Desc_maquina AS maquina,
                hpp.Fecha_ini, hpp.Fecha_fin
            FROM his_prod_paro hpp
            INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
            INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
            INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
            INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
            WHERE " . implode(' AND ', $where) . "
        ),
        hour_slots AS (
            SELECT
                p.cod_maquina, p.maquina, p.Fecha_ini, p.Fecha_fin,
                DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) AS slot_ini
            FROM paros p
            CROSS JOIN (VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),
                               (12),(13),(14),(15),(16),(17),(18),(19),(20),(21),(22),(23)) n(h)
            WHERE DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) < p.Fecha_fin
        )
        SELECT
            cod_maquina, maquina,
            DATEPART(HOUR, slot_ini) AS hora,
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
    return _shapePorHora($rows, 'h');
}

/**
 * Versión "por referencia": apila los segundos de paro por Cod_producto en buckets
 * horarios 0..23. Reusa _shapePorHora compactando referencias al cod_maquina key.
 * Si $codRefFilter está informado, restringe los paros a esa referencia (para que el
 * histograma horario muestre solo esa).
 */
function _parosPorHoraReferencia(string $fdesde, string $fhasta, array $turnos, array $maqMap, string $motivo, ?string $codRefFilter = null): ?array
{
    if (empty($maqMap)) return null;
    $codMaqs = array_keys($maqMap);

    $where = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "hpp.Fecha_fin IS NOT NULL",
        "cp.Desc_paro = ?",
        "prod.Cod_producto IS NOT NULL",
        "prod.Cod_producto <> '--'",
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

    if ($codRefFilter !== null && $codRefFilter !== '') {
        $where[] = "prod.Cod_producto = ?";
        $params[] = $codRefFilter;
    }

    $sql = "
        WITH paros AS (
            SELECT
                prod.Cod_producto  AS cod_referencia,
                prod.Desc_producto AS referencia,
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
            SELECT
                p.cod_referencia, p.referencia, p.Fecha_ini, p.Fecha_fin,
                DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) AS slot_ini
            FROM paros p
            CROSS JOIN (VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),
                               (12),(13),(14),(15),(16),(17),(18),(19),(20),(21),(22),(23)) n(h)
            WHERE DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) < p.Fecha_fin
        )
        SELECT
            cod_referencia AS cod_maquina,
            ISNULL(referencia, cod_referencia) AS maquina,
            DATEPART(HOUR, slot_ini) AS hora,
            SUM(DATEDIFF(SECOND,
                CASE WHEN Fecha_ini > slot_ini                   THEN Fecha_ini ELSE slot_ini END,
                CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini) THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
            )) AS valor
        FROM hour_slots
        GROUP BY cod_referencia, referencia, DATEPART(HOUR, slot_ini)
        HAVING SUM(DATEDIFF(SECOND,
                CASE WHEN Fecha_ini > slot_ini                   THEN Fecha_ini ELSE slot_ini END,
                CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini) THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
            )) > 0
    ";
    $rows = fetchAll('mapex', $sql, $params);
    return _shapePorHora($rows, 'h');
}

function _rechazosPorHora(string $fdesde, string $fhasta, array $turnos, array $maqMap, string $motivo): ?array
{
    if (empty($maqMap)) return null;
    $codMaqs = array_keys($maqMap);

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
        SELECT
            mq.Cod_maquina  AS cod_maquina,
            mq.Desc_maquina AS maquina,
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
    return _shapePorHora($rows, 'uds');
}

function _perdidaRendPorHora(string $fdesde, string $fhasta, array $turnos, array $maqMap, string $motivo): ?array
{
    if (empty($maqMap)) return null;
    $codMaqs = array_keys($maqMap);

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
        SELECT
            oee.WorkGroup   AS cod_maquina,
            mq.Desc_maquina AS maquina,
            DATEPART(HOUR, oee.TimePeriod) AS hora,
            SUM(oee.M) - SUM(oee.M_OKNOK_TEO) AS valor
        FROM F_his_ct('WORKCENTER','HOUR','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE $whereSQL
        GROUP BY oee.WorkGroup, mq.Desc_maquina, DATEPART(HOUR, oee.TimePeriod)
        HAVING SUM(oee.M) - SUM(oee.M_OKNOK_TEO) > 0
    ";
    $allParams = array_merge([$fdesde, $fhasta], $params);

    // Si F_his_ct('HOUR') no está soportado en esta instalación, la query
    // lanza PDOException → devolvemos null (UI mostrará mensaje "sin desglose").
    try {
        $rows = fetchAll('mapex', $sql, $allParams);
    } catch (Exception $e) {
        error_log("oee_unificado_motivo_drill: F_his_ct('HOUR') no disponible: " . $e->getMessage());
        return null;
    }
    return _shapePorHora($rows, 'h');
}
