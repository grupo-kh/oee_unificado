<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Evolución temporal de motivos de paro (disponibilidad).
 *
 * Para el rango/sección/turnos dados, agrega las horas de paro por MOTIVO
 * (cp.Desc_paro) y por BUCKET temporal (día/semana/mes, elegido por el usuario).
 * Devuelve, en una sola llamada, la lista de motivos (ordenada por peso = horas
 * totales desc) y la serie temporal de cada motivo (un punto por bucket, con 0
 * en los buckets sin datos para que la línea sea continua).
 *
 * Filtros idénticos a Matriz 2: excluye paro 11 (CERRADA) y actividad 1 (CERRADA).
 *
 * GET: fecha_desde, fecha_hasta (req), seccion (VARILLAS|TROQUELADOS|''),
 *      turnos (CSV M,T,N), granularidad (day|week|month, req).
 */
function motivosEvolucionData(): array
{
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');
    $seccion = strtoupper((string) getParam('seccion', ''));
    $gran = (string) getParam('granularidad', 'day');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) throw new Exception('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) throw new Exception('fecha_hasta inválida');
    if ($fdesde > $fhasta) throw new Exception('fecha_desde no puede ser posterior a fecha_hasta');
    if (!in_array($gran, ['day','week','month'], true)) throw new Exception('granularidad inválida (day|week|month)');
    if ($seccion !== '' && !in_array($seccion, ['VARILLAS','TROQUELADOS'], true)) throw new Exception('seccion inválida');
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    // Bucket SQL según granularidad (mismo patrón que oee_unificado_evolucion.php),
    // pero sobre hp.Dia_productivo (el campo de los paros).
    if ($gran === 'day') {
        $bucketSQL = "CAST(hp.Dia_productivo AS DATE)";
    } elseif ($gran === 'week') {
        $bucketSQL = "DATEADD(WEEK, DATEDIFF(WEEK, 0, hp.Dia_productivo), 0)";
    } else {
        $bucketSQL = "DATEADD(MONTH, DATEDIFF(MONTH, 0, hp.Dia_productivo), 0)";
    }

    $where  = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "cp.Id_actividad <> 1",
        "hpp.Fecha_fin IS NOT NULL",
    ];
    $params = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    $sql = "
        SELECT
            $bucketSQL AS bucket_start,
            COALESCE(NULLIF(LTRIM(RTRIM(cp.Desc_paro)), ''), '--') AS motivo,
            mq.Desc_maquina AS maquina,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY $bucketSQL, cp.Desc_paro, mq.Desc_maquina
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
    ";
    $rows = fetchAll('mapex', $sql, $params);

    // Acumular por motivo y bucket, filtrando por sección en PHP (igual que Matriz 2).
    $porMotivo = [];   // motivo => [bucketKey => horas]
    $pesoMotivo = [];  // motivo => horas totales
    foreach ($rows as $r) {
        $maq = (string) $r['maquina'];
        if ($seccion !== '' && (PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$maq] ?? null) !== $seccion) continue;
        $motivo = (string) $r['motivo'];
        $bk = substr((string) $r['bucket_start'], 0, 10);
        $h = (int) $r['segundos'] / 3600.0;
        $porMotivo[$motivo][$bk] = ($porMotivo[$motivo][$bk] ?? 0) + $h;
        $pesoMotivo[$motivo] = ($pesoMotivo[$motivo] ?? 0) + $h;
    }

    // Eje X: buckets continuos desde fdesde a fhasta con el paso de la granularidad.
    $buckets = motivosEvolucionBuckets($fdesde, $fhasta, $gran);

    // Motivos ordenados por peso desc (desempate alfabético para estabilidad).
    $motivosOrden = array_keys($pesoMotivo);
    usort($motivosOrden, function ($a, $b) use ($pesoMotivo) {
        $pa = $pesoMotivo[$a]; $pb = $pesoMotivo[$b];
        return $pa === $pb ? strcmp($a, $b) : $pb <=> $pa;
    });

    $motivos = [];
    foreach ($motivosOrden as $m) {
        $serie = [];
        $totalSerie = 0.0;
        foreach ($buckets as $b) {
            $h = round($porMotivo[$m][$b['key']] ?? 0, 2);
            $serie[] = ['key' => $b['key'], 'horas' => $h];
            $totalSerie += $h;
        }
        // total_horas se calcula como Σ(serie redondeada) para garantizar la
        // invariante total_horas == Σ serie sin acumulación de errores de redondeo.
        $motivos[] = ['motivo' => $m, 'total_horas' => round($totalSerie, 2), 'serie' => $serie];
    }

    return [
        'granularidad' => $gran,
        'seccion'      => $seccion ?: null,
        'buckets'      => $buckets,
        'motivos'      => $motivos,
    ];
}

/**
 * Festivos de la Comunidad Valenciana dentro del rango (mismo criterio que
 * oee_unificado_evolucion.php): nacionales fijos + autonómicos CV (fijos y móviles
 * dependientes de la Pascua). Devuelve un set [YYYY-MM-DD => true] para consulta O(1).
 */
function motivosEvolucionFestivosCV(string $desde, string $hasta): array
{
    $set = [];
    $aIni = (int) substr($desde, 0, 4);
    $aFin = (int) substr($hasta, 0, 4);
    for ($y = $aIni; $y <= $aFin; $y++) {
        foreach (['01-01','01-06','05-01','08-15','10-12','11-01','12-06','12-08','12-25'] as $md) $set["$y-$md"] = true;
        foreach (['03-19','10-09'] as $md) $set["$y-$md"] = true;
        if (function_exists('easter_date')) {
            $easterTs = easter_date($y);
            $set[date('Y-m-d', strtotime('-3 days', $easterTs))] = true; // Jueves Santo
            $set[date('Y-m-d', strtotime('-2 days', $easterTs))] = true; // Viernes Santo
            $set[date('Y-m-d', strtotime('+1 day',  $easterTs))] = true; // Lunes de Pascua (CV)
            $set[date('Y-m-d', strtotime('+8 days', $easterTs))] = true; // San Vicente Ferrer (CV)
        }
    }
    return $set;
}

/**
 * Genera la lista continua de buckets [{key:YYYY-MM-DD, label, tipo}] desde $fdesde a
 * $fhasta con el paso de la granularidad. El primer bucket de semana/mes se ancla
 * al inicio del periodo que contiene $fdesde (lunes / día 1), igual que el bucket SQL.
 *
 * `tipo` (solo en granularidad DÍA, donde 1 bucket = 1 día): 'festivo' | 'finde' |
 * 'normal'. En semana/mes no aplica (un bucket mezcla días laborables y no), va 'normal'.
 */
function motivosEvolucionBuckets(string $fdesde, string $fhasta, string $gran): array
{
    $meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    // Festivos solo se necesitan en granularidad día.
    $festivos = ($gran === 'day') ? motivosEvolucionFestivosCV($fdesde, $fhasta) : [];
    $ini = new DateTime($fdesde);
    if ($gran === 'week') {
        // Anclar al lunes de la semana de fdesde (N: 1=Lun..7=Dom).
        $ini->modify('-' . ((int)$ini->format('N') - 1) . ' days');
    } elseif ($gran === 'month') {
        $ini->modify('first day of this month');
    }
    $fin = new DateTime($fhasta);
    $out = [];
    $cur = clone $ini;
    while ($cur <= $fin) {
        $key = $cur->format('Y-m-d');
        $tipo = 'normal';
        if ($gran === 'day') {
            $label = $cur->format('d/m');
            if (isset($festivos[$key]))           $tipo = 'festivo';
            elseif ((int)$cur->format('N') >= 6)  $tipo = 'finde';   // 6=Sáb, 7=Dom
        } elseif ($gran === 'week') {
            $label = 'S' . $cur->format('W') . ' (' . $cur->format('d/m') . ')';
        } else {
            $label = $meses[(int)$cur->format('n') - 1] . ' ' . $cur->format('Y');
        }
        $out[] = ['key' => $key, 'label' => $label, 'tipo' => $tipo];
        $cur->modify($gran === 'day' ? '+1 day' : ($gran === 'week' ? '+7 days' : '+1 month'));
    }
    return $out;
}

// Endpoint JSON (no se dispara si el archivo se incluye desde una sonda/otro script).
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        jsonOk(motivosEvolucionData());
    } catch (Exception $e) {
        jsonError('Error: ' . $e->getMessage(), 500);
    }
}
