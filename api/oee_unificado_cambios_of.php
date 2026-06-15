<?php
/**
 * Cambios de OF por máquina (módulo "Cambios en OF" de OEE Unificado).
 *
 * Lee de MAPEX (his_prod_paro + cfg_paro) los paros cuya descripción
 * indica un cambio de orden de fabricación / referencia / formato /
 * utillaje. Por defecto detecta por LIKE '%CAMBIO%' sobre Desc_paro;
 * el patrón es ajustable con el parámetro `motivo`.
 *
 * Dos modos en el mismo endpoint:
 *
 *  1) Resumen por máquina (sin cod_maquina):
 *     Devuelve `por_maquina[]` con (cod, desc, n_cambios, horas_total,
 *     minutos_medio). Ordenado de mayor a menor número de cambios.
 *
 *  2) Detalle cronológico (con cod_maquina):
 *     Además del resumen, devuelve `detalle[]` con cada cambio individual
 *     (motivo, inicio, fin, segundos) ordenado por fecha de inicio
 *     ascendente — es lo que pinta el segundo módulo (Gantt-like) al
 *     pulsar una barra del primero.
 *
 * GET params:
 *   fecha_desde, fecha_hasta (YYYY-MM-DD)
 *   turnos        (CSV M,T,N)             opcional
 *   hora_desde, hora_hasta (HH:MM)        opcional, soporta cruza-medianoche
 *   motivo        (LIKE)                  default '%CAMBIO%'
 *   cod_maquina                           opcional → detalle de esa máquina
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

set_error_handler(function ($s, $m, $f, $l) {
    if (!(error_reporting() & $s)) return false;
    if (!headers_sent()) { header('Content-Type: application/json'); http_response_code(500); }
    echo json_encode(['ok' => false, 'error' => 'PHP: ' . $m . ' (' . basename($f) . ':' . $l . ')']);
    exit;
});

try {
    $fdesde = (string)getParam('fecha_desde', date('Y-m-d', strtotime('-30 days')));
    $fhasta = (string)getParam('fecha_hasta', date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida', 400);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida', 400);

    $turnosStr = (string)getParam('turnos', '');
    $turnos = [];
    if ($turnosStr !== '') {
        foreach (explode(',', $turnosStr) as $t) {
            $t = strtoupper(trim($t));
            if (in_array($t, ['M','T','N'], true)) $turnos[] = $t;
        }
    }

    // Patrones para detectar "cambio de OF o de referencia". El campo
    // admite varios patrones LIKE separados por '|' — se evalúan en OR.
    // Por defecto se incluyen los términos típicos que indican que la
    // máquina ha parado para cambiar la pieza que se está fabricando.
    // NO se incluye "UTILLAJE", "TURNO", etc., porque son otros motivos
    // de paro que no implican cambio de OF/referencia.
    $motivoStr = (string)getParam('motivo', '');
    if ($motivoStr === '') {
        $motivoStr = '%CAMBIO DE OF%|%CAMBIO DE REFERENCIA%|%CAMBIO DE FORMATO%|%CAMBIO DE PRODUCTO%|%CAMBIO REF%';
    }
    // Lista de patrones limpia: separamos por '|' y descartamos vacíos.
    $motivoPats = array_values(array_filter(array_map('trim', explode('|', $motivoStr)),
                                           fn($s) => $s !== ''));
    if (empty($motivoPats)) $motivoPats = ['%CAMBIO DE OF%'];

    $cod = trim((string)getParam('cod_maquina', ''));

    // Filtro horario opcional (igual semántica que el histograma)
    $horaDesde = (string)getParam('hora_desde', '');
    $horaHasta = (string)getParam('hora_hasta', '');
    $horaActiva = false; $horaCruza = false; $hIni = null; $hFin = null;
    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaDesde)
     && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaHasta)
     && $horaDesde !== $horaHasta) {
        $horaActiva = true;
        $horaCruza  = ($horaDesde > $horaHasta);
        $hIni = $horaDesde; $hFin = $horaHasta;
    }

    // Construcción del WHERE. La cláusula de motivo es un OR de todos
    // los patrones que vengan, así podemos incluir varios términos a la
    // vez sin engordar el listado con cambios técnicos no deseados.
    $orMotivos = implode(' OR ', array_fill(0, count($motivoPats), 'cp.Desc_paro LIKE ?'));
    $where = [
        "hpp.Fecha_fin IS NOT NULL",
        "($orMotivos)",
        "mq.Cod_maquina NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')",
    ];
    $params = $motivoPats;

    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    // Filtro fecha+hora (mismo esquema que oee_unificado_hist_maquina.php)
    if (!$horaActiva) {
        $where[] = "CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?";
        $params[] = $fdesde; $params[] = $fhasta;
    } elseif (!$horaCruza) {
        $where[] = "CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?";
        $where[] = "CONVERT(varchar(5), hpp.Fecha_ini, 108) >= ?";
        $where[] = "CONVERT(varchar(5), hpp.Fecha_ini, 108) < ?";
        $params[] = $fdesde; $params[] = $fhasta; $params[] = $hIni; $params[] = $hFin;
    } else {
        $fdesdePlus1 = date('Y-m-d', strtotime($fdesde . ' +1 day'));
        $fhastaPlus1 = date('Y-m-d', strtotime($fhasta . ' +1 day'));
        $where[] = "("
            . " (CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?"
            . "  AND CONVERT(varchar(5), hpp.Fecha_ini, 108) >= ?)"
            . " OR"
            . " (CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?"
            . "  AND CONVERT(varchar(5), hpp.Fecha_ini, 108) < ?)"
            . ")";
        $params[] = $fdesde; $params[] = $fhasta; $params[] = $hIni;
        $params[] = $fdesdePlus1; $params[] = $fhastaPlus1; $params[] = $hFin;
    }

    $whereSQL  = implode(' AND ', $where);
    $whereAgg  = $whereSQL;
    $paramsAgg = $params;

    // ── 1) Resumen agregado por máquina ─────────────────────────────
    $sqlAgg = "SELECT mq.Cod_maquina  AS cod_maquina,
                      mq.Desc_maquina AS desc_maquina,
                      COUNT(*)         AS n_cambios,
                      SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS seg_total,
                      AVG(CAST(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) AS BIGINT)) AS seg_medio
                 FROM his_prod_paro hpp
                 INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
                 INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
                 INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
                 INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
                 WHERE $whereAgg
                  AND DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) > 0
                 GROUP BY mq.Cod_maquina, mq.Desc_maquina
                 ORDER BY n_cambios DESC, seg_total DESC";
    $aggRows = fetchAll('mapex', $sqlAgg, $paramsAgg);

    $porMaquina = [];
    $totalCambios = 0; $totalSeg = 0;
    foreach ($aggRows as $r) {
        $nombre = trim((string)($r['desc_maquina'] ?? '')) ?: (string)$r['cod_maquina'];
        $n     = (int)$r['n_cambios'];
        $sec   = (int)$r['seg_total'];
        $secM  = (int)$r['seg_medio'];
        $porMaquina[] = [
            'cod_maquina'   => (string)$r['cod_maquina'],
            'desc_maquina'  => $nombre,
            'n_cambios'     => $n,
            'horas_total'   => round($sec / 3600, 2),
            'minutos_total' => round($sec / 60, 1),
            'minutos_medio' => round($secM / 60, 1),
        ];
        $totalCambios += $n;
        $totalSeg     += $sec;
    }

    // ── 2) Detalle cronológico de UNA máquina (si se pidió) ─────────
    $detalle = null;
    if ($cod !== '') {
        $whereDet  = $where;
        $paramsDet = $params;
        $whereDet[] = "mq.Cod_maquina = ?";
        $paramsDet[] = $cod;
        $whereDetSQL = implode(' AND ', $whereDet);

        // El SELECT del detalle también trae la OF / referencia asociada
        // al periodo productivo (his_prod → his_fase → his_of → cfg_producto).
        // En MAPEX, una vez completado el cambio, el siguiente his_prod ya
        // está asignado a la nueva OF, así que esta info es típicamente
        // la "OF de destino" del cambio.
        $sqlDet = "SELECT TOP 1000
                          cp.Desc_paro    AS motivo,
                          hpp.Fecha_ini   AS fecha_ini,
                          hpp.Fecha_fin   AS fecha_fin,
                          DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) AS segundos,
                          o.Cod_of        AS cod_of,
                          pr.Cod_producto AS cod_producto,
                          pr.Desc_producto AS desc_producto
                     FROM his_prod_paro hpp
                     INNER JOIN cfg_paro    cp  ON cp.Id_paro      = hpp.Id_paro
                     INNER JOIN his_prod    hp  ON hp.Id_his_prod  = hpp.Id_his_prod
                     INNER JOIN cfg_maquina mq  ON mq.Id_maquina   = hp.Id_maquina
                     INNER JOIN cfg_turno   ct  ON ct.Id_turno     = hp.Id_turno
                     LEFT  JOIN his_fase    fa  ON fa.Id_his_fase  = hp.Id_his_fase
                     LEFT  JOIN his_of      o   ON o.Id_his_of     = fa.Id_his_of
                     LEFT  JOIN cfg_producto pr ON pr.Id_producto  = o.Id_producto
                     WHERE $whereDetSQL
                       AND DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) > 0
                     ORDER BY hpp.Fecha_ini";
        $detRows = fetchAll('mapex', $sqlDet, $paramsDet);
        $detalle = [];
        foreach ($detRows as $r) {
            $cof  = trim((string)($r['cod_of'] ?? ''));
            $cpro = trim((string)($r['cod_producto'] ?? ''));
            $dpro = trim((string)($r['desc_producto'] ?? ''));
            $detalle[] = [
                'motivo'        => (string)($r['motivo'] ?: '(sin nombre)'),
                'inicio'        => substr((string)$r['fecha_ini'], 0, 19),
                'fin'           => substr((string)$r['fecha_fin'], 0, 19),
                'segundos'      => (int)$r['segundos'],
                // Info de la OF/referencia a la que se hace el cambio
                'cod_of'        => ($cof === '' || $cof === '--') ? null : $cof,
                'cod_producto'  => ($cpro === '' || $cpro === '--') ? null : $cpro,
                'desc_producto' => $dpro !== '' ? $dpro : null,
            ];
        }
    }

    jsonOk([
        'fecha_desde'   => $fdesde,
        'fecha_hasta'   => $fhasta,
        'turnos'        => $turnos ?: ['M','T','N'],
        'hora_desde'    => $horaActiva ? $hIni : null,
        'hora_hasta'    => $horaActiva ? $hFin : null,
        'hora_cruza_medianoche' => $horaCruza,
        'motivo_patron' => implode(' | ', $motivoPats),
        'total_cambios' => $totalCambios,
        'total_horas'   => round($totalSeg / 3600, 2),
        'por_maquina'   => $porMaquina,
        'detalle'       => $detalle,
        'cod_maquina'   => $cod ?: null,
    ]);

} catch (\Throwable $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
