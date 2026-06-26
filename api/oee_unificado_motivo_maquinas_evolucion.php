<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
// Reutiliza motivosEvolucionBuckets() (genera el eje X continuo con su 'tipo' de día).
// El require NO dispara la salida del otro endpoint (su guard comprueba SCRIPT_FILENAME).
require_once __DIR__ . '/oee_unificado_motivos_evolucion.php';

/**
 * Evolución temporal de un MOTIVO de paro repartido por MÁQUINA.
 *
 * Para el motivo, rango, sección y turnos dados, agrega las horas de paro por
 * MÁQUINA (cfg_maquina.Desc_maquina) y por BUCKET temporal (día/semana/mes).
 * Es la "vista por máquinas" del formulario de evolución: cada serie es una
 * máquina afectada por ese motivo a lo largo del tiempo.
 *
 * Mismos filtros que el resto del formulario (excluye paro 11 y actividad 1) más
 * el motivo concreto. El eje de buckets es idéntico al de la vista por motivos
 * (misma función), de modo que ambas vistas comparten escala y cuadran.
 *
 * GET: fecha_desde, fecha_hasta (req), seccion (VARILLAS|TROQUELADOS|''),
 *      turnos (CSV M,T,N), granularidad (day|week|month, req), motivo (req).
 * Devuelve: {
 *   granularidad, seccion, motivo,
 *   buckets:  [{key, label, tipo}],
 *   maquinas: [{cod_maquina, maquina, total_horas, serie:[{key, horas}]}]   // peso desc
 * }
 */
function motivoMaquinasEvolucionData(): array
{
    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');
    $seccion = strtoupper((string) getParam('seccion', ''));
    $gran    = (string) getParam('granularidad', 'day');
    $motivo  = (string) getParam('motivo', '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) throw new Exception('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) throw new Exception('fecha_hasta inválida');
    if ($fdesde > $fhasta) throw new Exception('fecha_desde no puede ser posterior a fecha_hasta');
    if (!in_array($gran, ['day','week','month'], true)) throw new Exception('granularidad inválida (day|week|month)');
    if ($seccion !== '' && !in_array($seccion, ['VARILLAS','TROQUELADOS'], true)) throw new Exception('seccion inválida');
    if ($motivo === '') throw new Exception('motivo requerido');
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    // Bucket SQL según granularidad (idéntico a la vista por motivos), sobre Dia_productivo.
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
        "cp.Desc_paro = ?",
    ];
    $params = [$fdesde, $fhasta, $motivo];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    $sql = "
        SELECT
            $bucketSQL AS bucket_start,
            mq.Cod_maquina  AS cod_maquina,
            mq.Desc_maquina AS maquina,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY $bucketSQL, mq.Cod_maquina, mq.Desc_maquina
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
    ";
    $rows = fetchAll('mapex', $sql, $params);

    // Acumular por máquina y bucket, filtrando por sección en PHP (igual que el resto).
    $porMaq  = [];   // cod => [bucketKey => horas]
    $pesoMaq = [];   // cod => horas totales
    $nombre  = [];   // cod => Desc_maquina
    foreach ($rows as $r) {
        $maq = (string) $r['maquina'];
        if ($seccion !== '' && (PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$maq] ?? null) !== $seccion) continue;
        $cod = (string) $r['cod_maquina'];
        $bk  = substr((string) $r['bucket_start'], 0, 10);
        $h   = (int) $r['segundos'] / 3600.0;
        $porMaq[$cod][$bk] = ($porMaq[$cod][$bk] ?? 0) + $h;
        $pesoMaq[$cod]     = ($pesoMaq[$cod] ?? 0) + $h;
        $nombre[$cod]      = $maq ?: $cod;
    }

    // Eje X: buckets continuos (misma función y forma que la vista por motivos).
    $buckets = motivosEvolucionBuckets($fdesde, $fhasta, $gran);

    // Máquinas ordenadas por peso desc (desempate por nombre para estabilidad).
    $codsOrden = array_keys($pesoMaq);
    usort($codsOrden, function ($a, $b) use ($pesoMaq, $nombre) {
        $pa = $pesoMaq[$a]; $pb = $pesoMaq[$b];
        return $pa === $pb ? strcmp($nombre[$a] ?? $a, $nombre[$b] ?? $b) : $pb <=> $pa;
    });

    $maquinas = [];
    foreach ($codsOrden as $cod) {
        $serie = [];
        $totalSerie = 0.0;
        foreach ($buckets as $b) {
            $h = round($porMaq[$cod][$b['key']] ?? 0, 2);
            $serie[] = ['key' => $b['key'], 'horas' => $h];
            $totalSerie += $h;
        }
        $maquinas[] = [
            'cod_maquina' => $cod,
            'maquina'     => $nombre[$cod] ?? $cod,
            'total_horas' => round($totalSerie, 2),
            'serie'       => $serie,
        ];
    }

    return [
        'granularidad' => $gran,
        'seccion'      => $seccion ?: null,
        'motivo'       => $motivo,
        'buckets'      => $buckets,
        'maquinas'     => $maquinas,
    ];
}

// Endpoint JSON (no se dispara si el archivo se incluye desde una sonda/otro script).
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        jsonOk(motivoMaquinasEvolucionData());
    } catch (Exception $e) {
        jsonError('Error: ' . $e->getMessage(), 500);
    }
}
