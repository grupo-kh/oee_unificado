<?php
/**
 * Histograma de motivos de disponibilidad de UNA máquina en un rango.
 *
 * Datos directos desde MAPEX (his_prod_paro + cfg_paro). Devuelve los
 * motivos con sus horas totales y % sobre el total, ordenados de mayor
 * a menor (pareto). Pensado para un gráfico de barras horizontales.
 *
 * GET parámetros:
 *   cod_maquina   (obligatorio)   Cod_maquina de cfg_maquina
 *   fecha_desde   (Y-m-d)         Default: hoy - 30 días
 *   fecha_hasta   (Y-m-d)         Default: hoy
 *   turnos        (CSV M,T,N)     Default: todos
 *   hora_desde    (HH:MM, opc.)   Inicio del intervalo horario (00-23:59)
 *   hora_hasta    (HH:MM, opc.)   Fin del intervalo (excluido)
 *                                 Si hora_desde > hora_hasta, el rango cruza
 *                                 medianoche (p.ej. 22:00 → 06:00 = noche).
 *
 * Salida:
 *   {
 *     "cod_maquina": "DOBL3",
 *     "desc_maquina": "BUCH GRANDE",
 *     "fecha_desde": "2026-05-09",
 *     "fecha_hasta": "2026-06-08",
 *     "total_horas": 124.5,
 *     "motivos": [
 *       {"motivo": "Avería mecánica", "horas": 45.3, "pct": 36.4},
 *       ...
 *     ]
 *   }
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

set_error_handler(function ($s, $m, $f, $l) {
    if (!(error_reporting() & $s)) return false;
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); http_response_code(500); }
    echo json_encode(['ok' => false, 'error' => 'PHP: ' . $m . ' (' . basename($f) . ':' . $l . ')']);
    exit;
});

try {
    // Acepta cod_maquina o desc_maquina opcionales. Si NO se pasa ninguno,
    // el histograma agrega todas las máquinas (Cod_maquina != excluidos);
    // el frontend usa esto como "vista global por máquina".
    $cod  = trim((string)getParam('cod_maquina', ''));
    $desc = trim((string)getParam('desc_maquina', ''));
    // Selección múltiple opcional (separador '||', que evita choques con
    // descripciones que contengan comas). Solo se aplica cuando NO se pasó
    // cod_maquina ni desc_maquina (vista "global").
    //   - desc_maquinas_in   → filtra a SOLO esas máquinas.
    //   - desc_maquinas_excl → filtra EXCLUYENDO esas máquinas.
    $maqIn   = [];
    $maqExcl = [];
    $rawIn   = trim((string)getParam('desc_maquinas_in', ''));
    $rawExcl = trim((string)getParam('desc_maquinas_excl', ''));
    if ($rawIn !== '') {
        $maqIn = array_values(array_filter(array_map('trim', explode('||', $rawIn))));
    }
    if ($rawExcl !== '') {
        $maqExcl = array_values(array_filter(array_map('trim', explode('||', $rawExcl))));
    }
    // "Todas las máquinas" desde el punto de vista del render: vista global
    // (con o sin recorte por maqIn/maqExcl). Una máquina específica con
    // cod/desc se trata como vista mono-máquina.
    $todasLasMaquinas = ($cod === '' && $desc === '');

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

    // Filtro horario opcional: hora_desde y hora_hasta en formato HH:MM.
    // Si solo viene uno de los dos, no se aplica nada (necesitamos ambos).
    // Soporta rangos que cruzan medianoche (ej. 22:00 → 06:00 = noche).
    $horaDesde = (string)getParam('hora_desde', '');
    $horaHasta = (string)getParam('hora_hasta', '');
    $horaFiltroActivo = false;
    $horaCruzaMedia   = false;
    $horaIni = $horaFin = null;
    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaDesde)
     && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaHasta)
     && $horaDesde !== $horaHasta) {
        $horaFiltroActivo = true;
        $horaCruzaMedia   = ($horaDesde > $horaHasta);
        $horaIni = $horaDesde;
        $horaFin = $horaHasta;
    }

    // Resolver cod/desc para mostrar título completo. Si llegó solo uno,
    // sacamos el otro de cfg_maquina (si existe en MAPEX).
    if (!$todasLasMaquinas) {
        if ($cod !== '' && $desc === '') {
            $r = fetchAll('mapex',
                "SELECT Desc_maquina FROM cfg_maquina WHERE Cod_maquina = ?", [$cod]);
            $desc = $r ? (string)($r[0]['Desc_maquina'] ?? $cod) : $cod;
        } elseif ($desc !== '' && $cod === '') {
            $r = fetchAll('mapex',
                "SELECT Cod_maquina FROM cfg_maquina WHERE Desc_maquina = ?", [$desc]);
            $cod = $r ? (string)($r[0]['Cod_maquina'] ?? $desc) : $desc;
        }
    }

    // IMPORTANTE: filtramos por la fecha REAL de inicio del paro
    // (hpp.Fecha_ini) en lugar de hp.Dia_productivo. Dia_productivo agrupa
    // turnos a su día productivo (el turno de noche del 02/06 puede acabar
    // a las 06:00 del 03/06 y aún así pertenecer al "día productivo" 02/06),
    // y eso hacía que el cronograma mostrase fechas/horas fuera del filtro.
    $where = [
        "cp.Cod_paro <> 11",
        "hpp.Fecha_fin IS NOT NULL",
    ];
    $params = [];

    if ($todasLasMaquinas) {
        $where[] = "mq.Cod_maquina NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";
    } elseif (!empty($maqIn)) {
        // Selección múltiple: solo estas máquinas (siempre excluye auxiliares).
        $ph = implode(',', array_fill(0, count($maqIn), '?'));
        $where[] = "mq.Desc_maquina IN ($ph)";
        $where[] = "mq.Cod_maquina NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";
        $params  = array_merge($params, $maqIn);
    } elseif (!empty($maqExcl)) {
        // Selección múltiple: todas EXCEPTO estas + las auxiliares.
        $ph = implode(',', array_fill(0, count($maqExcl), '?'));
        $where[] = "mq.Desc_maquina NOT IN ($ph)";
        $where[] = "mq.Cod_maquina NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";
        $params  = array_merge($params, $maqExcl);
    } elseif ($cod !== '' && $desc !== '' && $cod !== $desc) {
        // Tenemos ambos resueltos: usamos código (más fiable)
        $where[] = "mq.Cod_maquina = ?";
        $params[] = $cod;
    } elseif ($cod !== '') {
        $where[] = "mq.Cod_maquina = ?";
        $params[] = $cod;
    } else {
        $where[] = "mq.Desc_maquina = ?";
        $params[] = $desc;
    }
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    // ── Filtro combinado fecha + hora sobre Fecha_ini ────────────────
    // Cuatro casos posibles:
    //   1) Sin filtro horario: ventana = [fecha_desde 00:00, fecha_hasta+1 00:00)
    //   2) Horario normal (no cruza): cada día del rango, hora ∈ [hora_ini, hora_fin)
    //   3) Horario cruza medianoche: cada "noche" = hora_ini..23:59 del día X
    //      O 00:00..hora_fin del día X+1, para X ∈ [fecha_desde, fecha_hasta]
    if (!$horaFiltroActivo) {
        $where[] = "CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?";
        $params[] = $fdesde;
        $params[] = $fhasta;
    } elseif (!$horaCruzaMedia) {
        // Caso típico (p.ej. 06:00 → 18:00): mismo día, hora entre los dos.
        $where[] = "CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?";
        $where[] = "CONVERT(varchar(5), hpp.Fecha_ini, 108) >= ?";
        $where[] = "CONVERT(varchar(5), hpp.Fecha_ini, 108) < ?";
        $params[] = $fdesde;
        $params[] = $fhasta;
        $params[] = $horaIni;
        $params[] = $horaFin;
    } else {
        // Cruza medianoche (p.ej. 22:00 → 06:00 = turno de noche).
        // Aceptamos eventos que arrancan:
        //   - El día X del rango con hora >= horaIni
        //   - El día X+1 (X ∈ rango) con hora < horaFin
        $fdesdePlus1 = date('Y-m-d', strtotime($fdesde . ' +1 day'));
        $fhastaPlus1 = date('Y-m-d', strtotime($fhasta . ' +1 day'));
        $where[] = "("
            . " (CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?"
            . "  AND CONVERT(varchar(5), hpp.Fecha_ini, 108) >= ?)"
            . " OR"
            . " (CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?"
            . "  AND CONVERT(varchar(5), hpp.Fecha_ini, 108) < ?)"
            . ")";
        $params[] = $fdesde;
        $params[] = $fhasta;
        $params[] = $horaIni;
        $params[] = $fdesdePlus1;
        $params[] = $fhastaPlus1;
        $params[] = $horaFin;
    }

    // Para el cronograma necesitamos los EVENTOS individuales (un paro =
    // una fila), no un agregado por motivo. Cada evento se pintará como un
    // rectángulo en el timeline de su máquina, coloreado por motivo. TOP
    // 5000 protege contra rangos demasiado grandes — si se alcanza, el
    // frontend muestra un aviso pidiendo afinar filtros.
    $LIMITE_EVENTOS = 5000;
    $sql = "SELECT TOP $LIMITE_EVENTOS
                   cp.Desc_paro    AS motivo,
                   mq.Cod_maquina  AS cod_maquina,
                   mq.Desc_maquina AS desc_maquina,
                   prod.Cod_producto  AS cod_referencia,
                   prod.Desc_producto AS referencia,
                   ac.Desc_actividad  AS actividad,
                   hpp.Fecha_ini   AS fecha_ini,
                   hpp.Fecha_fin   AS fecha_fin,
                   DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) AS segundos
            FROM his_prod_paro hpp
            INNER JOIN cfg_paro    cp  ON cp.Id_paro     = hpp.Id_paro
            INNER JOIN his_prod    hp  ON hp.Id_his_prod = hpp.Id_his_prod
            INNER JOIN cfg_maquina mq  ON mq.Id_maquina  = hp.Id_maquina
            INNER JOIN cfg_turno   ct  ON ct.Id_turno    = hp.Id_turno
            LEFT  JOIN cfg_actividad ac ON ac.Id_actividad = hp.Id_actividad
            LEFT  JOIN his_fase    fa  ON fa.Id_his_fase = hp.Id_his_fase
            LEFT  JOIN his_of      o   ON o.Id_his_of    = fa.Id_his_of
            LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
            WHERE " . implode(' AND ', $where) . "
              AND DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) > 0
            ORDER BY hpp.Fecha_ini";

    $rows = fetchAll('mapex', $sql, $params);

    // Construcción de la respuesta:
    //   - $eventos: lista plana de paros con ini/fin/motivo/máquina.
    //   - $totMot:  acumulado de segundos por motivo (para leyenda + Pareto).
    //   - $totMaq:  acumulado por máquina (para ordenar filas del timeline).
    // El "nombre_maquina" usa Desc_maquina (fallback Cod_maquina) para
    // coincidir con lo que el usuario ve en pantalla.
    $eventos = [];
    $totMot  = [];
    $totMaq  = [];
    $totSeg  = 0;
    foreach ($rows as $r) {
        $mot  = (string)($r['motivo'] ?: '(sin nombre)');
        $nMaq = trim((string)($r['desc_maquina'] ?? '')) ?: (string)$r['cod_maquina'];
        $seg  = (int)$r['segundos'];
        // Las fechas vienen como strings 'YYYY-MM-DD HH:MM:SS[.fff]'.
        // Normalizamos a 'YYYY-MM-DD HH:MM:SS' para que Date() del navegador
        // las parsee bien (sin zona horaria — se interpretan como locales).
        $ini = substr((string)$r['fecha_ini'], 0, 19);
        $fin = substr((string)$r['fecha_fin'], 0, 19);
        $ref = trim((string)($r['referencia'] ?? '')) ?: (trim((string)($r['cod_referencia'] ?? '')) ?: '(sin referencia)');
        $eventos[] = [
            'maquina'     => $nMaq,
            'motivo'      => $mot,
            'referencia'  => $ref,
            'actividad'   => trim((string)($r['actividad'] ?? '')),
            'inicio'      => $ini,
            'fin'         => $fin,
            'segundos'    => $seg,
        ];
        $totMot[$mot]  = ($totMot[$mot]  ?? 0) + $seg;
        $totMaq[$nMaq] = ($totMaq[$nMaq] ?? 0) + $seg;
        $totSeg += $seg;
    }

    // Listas auxiliares (motivos ordenados por total DESC — el orden se
    // usa también para asignar el color/posición de cada motivo en el
    // chart frontend). Máquinas también DESC por horas acumuladas.
    arsort($totMot);
    arsort($totMaq);

    $motivos = [];
    foreach ($totMot as $mot => $seg) {
        $motivos[] = [
            'motivo' => $mot,
            'horas'  => round($seg / 3600, 2),
            'pct'    => $totSeg > 0 ? round($seg / $totSeg * 100, 2) : 0,
        ];
    }
    $maquinas = [];
    foreach ($totMaq as $nMaq => $seg) {
        $maquinas[] = [
            'maquina' => $nMaq,
            'horas'   => round($seg / 3600, 2),
        ];
    }

    jsonOk([
        'cod_maquina'           => $cod,
        'desc_maquina'          => $desc,
        'todas_las_maquinas'    => $todasLasMaquinas,
        'fecha_desde'           => $fdesde,
        'fecha_hasta'           => $fhasta,
        'turnos'                => $turnos ?: ['M','T','N'],
        'hora_desde'            => $horaFiltroActivo ? $horaIni : null,
        'hora_hasta'            => $horaFiltroActivo ? $horaFin : null,
        'hora_cruza_medianoche' => $horaCruzaMedia,
        'total_horas'           => round($totSeg / 3600, 2),
        'motivos'               => $motivos,
        'maquinas'              => $maquinas,
        'eventos'               => $eventos,
        'eventos_limitados'     => count($rows) >= $LIMITE_EVENTOS,
    ]);

} catch (\Throwable $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
