<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * "Análisis Top" — Disponibilidad. Modal independiente con dos paneles
 * (máquinas y motivos) cada uno con su propio rango y top N. Al clicar
 * una máquina/motivo se solicita un histograma por fecha.
 *
 * Modos (parámetro mode):
 *   - maquinas              → top N máquinas con más horas de paro
 *   - motivos               → top N motivos con más horas de paro
 *   - detalle_fecha_maquina → horas de paro por DÍA para una cod_maquina
 *   - detalle_fecha_motivo  → horas de paro por DÍA para un motivo
 *
 * Comunes: fecha_desde, fecha_hasta (YYYY-MM-DD), seccion, turnos, excl.
 * Top N (modos top): top_n (1..20, default 5).
 * Filtros del detalle: cod_maquina (detalle_fecha_maquina) | motivo (detalle_fecha_motivo).
 */

function _topSeccion(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

function _topResolverMaqsSeccion(string $fdesde, string $fhasta, array $turnos, string $seccion, array $excl): array
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
    $sql = "
        SELECT oee.WorkGroup AS cod_maquina, mq.Desc_maquina AS maquina
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE " . implode(' AND ', $where) . "
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
    ";
    $rows = fetchAll('mapex', $sql, array_merge([$fdesde, $fhasta], $params));
    $out = [];
    foreach ($rows as $r) {
        if (_topSeccion($r['maquina']) === $seccion) {
            $out[$r['cod_maquina']] = $r['maquina'] ?: $r['cod_maquina'];
        }
    }
    return $out; // [cod_maquina => desc_maquina]
}

/** Cláusula base WHERE para his_prod_paro (filtros de fecha + turnos + máquinas). */
function _topWherePar(string $fdesde, string $fhasta, array $turnos, array $codMaqs, array &$params): array
{
    $where = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "hpp.Fecha_fin IS NOT NULL",
    ];
    $params = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    $codMaqs = array_values($codMaqs);
    $ph = implode(',', array_fill(0, count($codMaqs), '?'));
    $where[] = "mq.Cod_maquina IN ($ph)";
    $params = array_merge($params, $codMaqs);
    return $where;
}

function _topMaquinas(string $fdesde, string $fhasta, array $turnos, array $codMaqs, int $topN): array
{
    if (empty($codMaqs)) return [];
    $params = [];
    $where = _topWherePar($fdesde, $fhasta, $turnos, $codMaqs, $params);
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
    $rows = array_slice($rows, 0, $topN);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'cod_maquina' => $r['cod_maquina'],
            'maquina'     => $r['maquina'] ?: $r['cod_maquina'],
            'horas'       => round(((int)$r['segundos']) / 3600, 2),
        ];
    }
    return $out;
}

function _topMotivos(string $fdesde, string $fhasta, array $turnos, array $codMaqs, int $topN): array
{
    if (empty($codMaqs)) return [];
    $params = [];
    $where = _topWherePar($fdesde, $fhasta, $turnos, $codMaqs, $params);
    $sql = "
        SELECT cp.Desc_paro AS motivo,
               SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY cp.Desc_paro
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY segundos DESC
    ";
    $rows = fetchAll('mapex', $sql, $params);
    $rows = array_slice($rows, 0, $topN);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'motivo' => (string)$r['motivo'],
            'horas'  => round(((int)$r['segundos']) / 3600, 2),
        ];
    }
    return $out;
}

function _topDetalleFechaMaquina(string $fdesde, string $fhasta, array $turnos, array $codMaqs, string $codMaqFiltro): array
{
    if (empty($codMaqs)) return [];
    if (!in_array($codMaqFiltro, $codMaqs, true)) return [];
    $params = [];
    $where = _topWherePar($fdesde, $fhasta, $turnos, [$codMaqFiltro], $params);
    $sql = "
        SELECT CAST(hp.Dia_productivo AS DATE) AS fecha,
               SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY CAST(hp.Dia_productivo AS DATE)
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY fecha
    ";
    $rows = fetchAll('mapex', $sql, $params);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'fecha' => substr((string)$r['fecha'], 0, 10),
            'horas' => round(((int)$r['segundos']) / 3600, 2),
        ];
    }
    return $out;
}

function _topDetalleFechaMotivo(string $fdesde, string $fhasta, array $turnos, array $codMaqs, string $motivo): array
{
    if (empty($codMaqs) || $motivo === '') return [];
    $params = [];
    $where = _topWherePar($fdesde, $fhasta, $turnos, $codMaqs, $params);
    $where[] = "cp.Desc_paro = ?";
    $params[] = $motivo;
    $sql = "
        SELECT CAST(hp.Dia_productivo AS DATE) AS fecha,
               SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY CAST(hp.Dia_productivo AS DATE)
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY fecha
    ";
    $rows = fetchAll('mapex', $sql, $params);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'fecha' => substr((string)$r['fecha'], 0, 10),
            'horas' => round(((int)$r['segundos']) / 3600, 2),
        ];
    }
    return $out;
}

try {
    $mode    = (string) getParam('mode', 'maquinas');
    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');
    $seccion = (string) getParam('seccion');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    if ($fdesde > $fhasta) jsonError('fecha_desde no puede ser posterior a fecha_hasta');
    if (!in_array($seccion, ['VARILLAS', 'TROQUELADOS'], true)) jsonError('seccion inválida');

    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));
    $excl   = getListParam('excl');

    $maqMap  = _topResolverMaqsSeccion($fdesde, $fhasta, $turnos, $seccion, $excl);
    $codMaqs = array_keys($maqMap);

    switch ($mode) {
        case 'maquinas_seccion': {
            // Lista de máquinas de la sección para el selector de exclusión.
            // No aplica `excl` aquí: queremos que el selector muestre TODAS
            // (incluidas las ya excluidas, para poder reactivarlas).
            $maqMapFull = _topResolverMaqsSeccion($fdesde, $fhasta, $turnos, $seccion, []);
            $listado = [];
            foreach ($maqMapFull as $cod => $desc) {
                $listado[] = ['cod_maquina' => $cod, 'maquina' => $desc];
            }
            usort($listado, fn($a, $b) => strcmp((string)$a['maquina'], (string)$b['maquina']));
            jsonOk([
                'mode'     => 'maquinas_seccion',
                'seccion'  => $seccion,
                'maquinas' => $listado,
            ]);
        }
        case 'maquinas': {
            $n = (int) (getParam('top_n', 5) ?: 5);
            if ($n < 1)  $n = 1;
            if ($n > 20) $n = 20;
            jsonOk([
                'mode'     => 'maquinas',
                'seccion'  => $seccion,
                'top_n'    => $n,
                'maquinas' => _topMaquinas($fdesde, $fhasta, $turnos, $codMaqs, $n),
            ]);
        }
        case 'motivos': {
            $n = (int) (getParam('top_n', 5) ?: 5);
            if ($n < 1)  $n = 1;
            if ($n > 20) $n = 20;
            jsonOk([
                'mode'    => 'motivos',
                'seccion' => $seccion,
                'top_n'   => $n,
                'motivos' => _topMotivos($fdesde, $fhasta, $turnos, $codMaqs, $n),
            ]);
        }
        case 'detalle_fecha_maquina': {
            $cod = isset($_GET['cod_maquina']) ? trim((string)$_GET['cod_maquina']) : '';
            if ($cod === '') jsonError('cod_maquina requerido');
            jsonOk([
                'mode'        => 'detalle_fecha_maquina',
                'seccion'     => $seccion,
                'cod_maquina' => $cod,
                'maquina'     => $maqMap[$cod] ?? $cod,
                'fechas'      => _topDetalleFechaMaquina($fdesde, $fhasta, $turnos, $codMaqs, $cod),
            ]);
        }
        case 'detalle_fecha_motivo': {
            $motivo = isset($_GET['motivo']) ? trim((string)$_GET['motivo']) : '';
            if ($motivo === '') jsonError('motivo requerido');
            jsonOk([
                'mode'    => 'detalle_fecha_motivo',
                'seccion' => $seccion,
                'motivo'  => $motivo,
                'fechas'  => _topDetalleFechaMotivo($fdesde, $fhasta, $turnos, $codMaqs, $motivo),
            ]);
        }
        default:
            jsonError("mode no soportado: '$mode'", 400);
    }
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
