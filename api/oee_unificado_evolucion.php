<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Evolución OEE para OEE Unificado.
 *
 * Granularidad automática:
 *   - rango ≤  90 días → día
 *   - rango  91-365 días → semana (lunes inicio)
 *   - rango > 365 días  → mes
 *
 * Parámetros:
 *   - fecha_desde, fecha_hasta (YYYY-MM-DD)
 *   - turnos  (CSV: M,T,N) — vacío = todos
 *   - excl    (CSV cod_maquina excluidas)
 *   - seccion (CSV: VARILLAS,TROQUELADOS | 'TODAS' | '' = todas) — filtra máquinas
 *
 * Devuelve:
 *   - granularidad: "DAY" | "WEEK" | "MONTH"
 *   - seccion: "TODAS" o el CSV de secciones elegidas
 *   - periodos: [{ bucket_start, label, oee, disponibilidad, rendimiento, calidad,
 *                  tipo_dia ('normal'|'weekend'|'holiday'  ·  solo DAY) }, ...]
 *   - festivos: [YYYY-MM-DD, ...] (festivos del rango, info para el cliente)
 */

/**
 * Festivos relevantes para la Comunidad Valenciana dentro del rango.
 * Combina:
 *   - Nacionales fijos (9 fechas).
 *   - Móviles nacionales: Jueves Santo y Viernes Santo.
 *   - Autonómicos CV: 19-mar (San José), Lunes de Pascua (Easter+1),
 *     San Vicente Ferrer (Easter+8) y 9-oct (Día Comunitat Valenciana).
 *
 * Si un festivo cae en domingo, según calendario laboral oficial puede
 * trasladarse al lunes siguiente; ese matiz no se contempla aquí —
 * marcamos la fecha original como festiva.
 */
function _evolFestivosCV(string $desde, string $hasta): array
{
    $set = [];
    $aIni = (int) substr($desde, 0, 4);
    $aFin = (int) substr($hasta, 0, 4);
    for ($y = $aIni; $y <= $aFin; $y++) {
        // Nacionales fijos
        foreach (['01-01','01-06','05-01','08-15','10-12','11-01','12-06','12-08','12-25'] as $md) {
            $set["$y-$md"] = true;
        }
        // Autonómicos CV (fijos)
        foreach (['03-19','10-09'] as $md) {
            $set["$y-$md"] = true;
        }
        // Móviles (dependen de la Pascua)
        if (function_exists('easter_date')) {
            $easterTs = easter_date($y);
            $set[date('Y-m-d', strtotime('-3 days', $easterTs))] = true; // Jueves Santo
            $set[date('Y-m-d', strtotime('-2 days', $easterTs))] = true; // Viernes Santo
            $set[date('Y-m-d', strtotime('+1 day',  $easterTs))] = true; // Lunes de Pascua (CV)
            $set[date('Y-m-d', strtotime('+8 days', $easterTs))] = true; // San Vicente Ferrer (CV)
        }
    }
    // Filtra al rango pedido
    $out = [];
    foreach (array_keys($set) as $d) {
        if ($d >= $desde && $d <= $hasta) $out[] = $d;
    }
    sort($out);
    return $out;
}

function _calcDRCEvol(float $M, float $MOT, float $MOKT, float $PP, float $PC, float $PNP): array {
    $d = ($M + $PNP)      > 0 ? $M / ($M + $PNP) * 100              : 0;
    $r = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
    $c = ($MOT + $PC)     > 0 ? $MOKT / ($MOT + $PC) * 100           : 0;
    $oee = $d * $r * $c / 10000;
    return [
        'disponibilidad' => round($d, 2),
        'rendimiento'    => round($r, 2),
        'calidad'        => round($c, 2),
        'oee'            => round($oee, 2),
    ];
}

try {
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    if ($fdesde > $fhasta) jsonError('fecha_desde no puede ser posterior a fecha_hasta');

    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));
    $excl   = getListParam('excl');
    // Filtro opcional por máquina concreta: si llega, la evolución se calcula solo
    // para esa máquina (usado por el drill métrica al clicar una máquina).
    $codMaqFiltro = isset($_GET['cod_maquina']) ? trim((string)$_GET['cod_maquina']) : '';

    // Selección múltiple de secciones. Array vacío = SIN filtro = todas (histórico).
    // parseSecciones ya sanitiza contra la lista permitida; no hace falta validación escalar.
    $secciones = parseSecciones(['VARILLAS', 'TROQUELADOS']);
    $todasSec  = empty($secciones);
    // Si hay secciones concretas, lista de DESC de máquinas que pertenecen a ellas
    $descsSeccion = [];
    if (!$todasSec) {
        foreach (PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT as $desc => $sec) {
            if (in_array($sec, $secciones, true)) $descsSeccion[] = $desc;
        }
        if (empty($descsSeccion)) jsonError('Sección sin máquinas configuradas');
    }

    // Granularidad automática
    $dias = (new DateTime($fhasta))->diff(new DateTime($fdesde))->days;
    if ($dias <= 90)       $granularidad = 'DAY';
    elseif ($dias <= 365)  $granularidad = 'WEEK';
    else                   $granularidad = 'MONTH';

    if ($granularidad === 'DAY') {
        $bucketSQL = "CAST(oee.TimePeriod AS DATE)";
    } elseif ($granularidad === 'WEEK') {
        $bucketSQL = "DATEADD(WEEK,  DATEDIFF(WEEK,  0, oee.TimePeriod), 0)";
    } else {
        $bucketSQL = "DATEADD(MONTH, DATEDIFF(MONTH, 0, oee.TimePeriod), 0)";
    }

    $where  = [
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
    // Si llega cod_maquina, restringe la evolución a esa única máquina
    if ($codMaqFiltro !== '') {
        $where[] = "oee.WorkGroup = ?";
        $params[] = $codMaqFiltro;
    }
    // Filtro de sección: añade JOIN a cfg_maquina y limita Desc_maquina IN (...)
    $extraJoin = '';
    if (!empty($descsSeccion)) {
        $extraJoin = "LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup";
        $ph = implode(',', array_fill(0, count($descsSeccion), '?'));
        $where[] = "mq.Desc_maquina IN ($ph)";
        $params = array_merge($params, $descsSeccion);
    }
    $whereSQL = implode(' AND ', $where);

    $sql = "
        SELECT
            $bucketSQL          AS bucket_start,
            SUM(oee.M)           AS M,
            SUM(oee.M_OKNOK_TEO) AS MOT,
            SUM(oee.M_OK_TEO)    AS MOKT,
            SUM(oee.PPERF)       AS PPERF,
            SUM(oee.PCALIDAD)    AS PCALIDAD,
            SUM(oee.PNP)         AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        $extraJoin
        WHERE $whereSQL
        GROUP BY $bucketSQL
        ORDER BY $bucketSQL
    ";
    $allParams = array_merge([$fdesde, $fhasta], $params);
    $rows = fetchAll('mapex', $sql, $allParams);

    // Festivos del rango (solo aplican a granularidad DAY) — Comunidad Valenciana
    $festivos = ($granularidad === 'DAY')
        ? _evolFestivosCV($fdesde, $fhasta)
        : [];
    $festivosSet = array_flip($festivos);

    // Construir periodos con etiqueta + DRC/OEE
    $periodos = [];
    $meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    foreach ($rows as $r) {
        $bucketDate = substr((string)$r['bucket_start'], 0, 10); // YYYY-MM-DD
        $drc = _calcDRCEvol(
            (float)$r['M'], (float)$r['MOT'], (float)$r['MOKT'],
            (float)$r['PPERF'], (float)$r['PCALIDAD'], (float)$r['PNP']
        );
        $dt = new DateTime($bucketDate);
        if ($granularidad === 'DAY') {
            $label = $dt->format('d/m');
        } elseif ($granularidad === 'WEEK') {
            $label = 'S' . $dt->format('W') . ' (' . $dt->format('d/m') . ')';
        } else {
            $label = $meses[(int)$dt->format('n') - 1] . ' ' . $dt->format('Y');
        }
        // Tipo de día (solo tiene sentido para granularidad DAY)
        $tipoDia = 'normal';
        if ($granularidad === 'DAY') {
            $dow = (int)$dt->format('N'); // 1=Lun ... 7=Dom
            if (isset($festivosSet[$bucketDate])) $tipoDia = 'holiday';
            elseif ($dow >= 6)                    $tipoDia = 'weekend';
        }
        $periodos[] = array_merge([
            'bucket_start' => $bucketDate,
            'label'        => $label,
            'tipo_dia'     => $tipoDia,
        ], $drc);
    }

    jsonOk([
        'granularidad' => $granularidad,
        'seccion'      => $todasSec ? 'TODAS' : implode(',', $secciones),
        'periodos'     => $periodos,
        'festivos'     => $festivos,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
