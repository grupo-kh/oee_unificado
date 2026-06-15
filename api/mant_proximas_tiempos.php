<?php
/**
 * API: Tiempos estimados por máquina.
 *
 * Para cada máquina activa devuelve:
 *   - Tiempo del "ámbito" (PLAN COMPLETO o solo las tareas cuyo
 *     proxima_revision cae en el intervalo [fecha_desde, fecha_hasta] si se
 *     pasan esos parámetros).
 *   - Tiempo VENCIDO DENTRO DEL ÁMBITO (subconjunto: las del ámbito cuya
 *     proxima_revision <= hoy).
 *   - Desglose por periodicidad (DIARIO, SEMANAL, ...).
 *   - Lista detallada de tareas para ver el desglose.
 *
 * Parámetros (todos opcionales):
 *   - cod_maquina_mant : limita la respuesta a una sola máquina (popup
 *                        de detalle).
 *   - q                : búsqueda case-insensitive sobre desc/cod máquina.
 *   - fecha_desde      : YYYY-MM-DD. Si viene junto con fecha_hasta,
 *                        sólo se cuentan las tareas con
 *                        proxima_revision >= fecha_desde.
 *   - fecha_hasta      : YYYY-MM-DD. Idem, <= fecha_hasta. Cuando ambas
 *                        están presentes, "plan" pasa a significar
 *                        "tareas del intervalo" y se devuelven los
 *                        campos extra rango_desde/rango_hasta en la
 *                        respuesta para que el frontend etiquete bien.
 *
 * Salida:
 *   {
 *     "maquinas": [
 *       {
 *         "cod_maquina_mant": "DOBL3",
 *         "desc_maquina": "BUCH GRANDE",
 *         "plan_total_tareas": 8,
 *         "plan_total_minutos": 124,
 *         "pend_total_tareas": 3,
 *         "pend_total_minutos": 71,
 *         "por_periodicidad": {
 *           "MENSUAL": {"plan_n": 3, "plan_min": 45, "pend_n": 1, "pend_min": 15},
 *           "TRIMESTRAL": {"plan_n": 2, "plan_min": 64, "pend_n": 1, "pend_min": 32},
 *           ...
 *         },
 *         "tareas": [
 *           {tarea, periodicidad, desc_tarea, tiempo_min,
 *            proxima_revision, es_pendiente},
 *           ...
 *         ]
 *       }, ...
 *     ],
 *     "total_global_plan_min": 1820,
 *     "total_global_pend_min": 480,
 *     "hoy": "2026-06-08"
 *   }
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';

Auth::requireLoginApi();

// Garantizar siempre JSON ante errores fatales.
header('Content-Type: application/json; charset=utf-8');
set_error_handler(function ($s, $m, $f, $l) {
    if (!(error_reporting() & $s)) return false;
    if (!headers_sent()) { header('Content-Type: application/json'); http_response_code(500); }
    echo json_encode(['ok' => false, 'error' => 'PHP: ' . $m . ' (' . basename($f) . ':' . $l . ')']);
    exit;
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) { header('Content-Type: application/json'); http_response_code(500); }
        echo json_encode(['ok' => false, 'error' => 'Fatal: ' . $e['message']]);
    }
});

try {
    ini_set('memory_limit', '512M');
    $hoy = date('Y-m-d');
    $codFiltro = (string)getParam('cod_maquina_mant', '');
    $qFiltro   = mb_strtolower(trim((string)getParam('q', '')));

    // Intervalo opcional: cuando viene, sólo se cuentan las tareas con
    // proxima_revision dentro de ese rango (inclusive en ambos extremos).
    // El frontend lo usa para responder a la pregunta:
    // "¿cuánto tiempo me llevarán las revisiones de esta máquina entre
    //  la fecha X y la fecha Y?"
    $fDesde = (string)getParam('fecha_desde', '');
    $fHasta = (string)getParam('fecha_hasta', '');
    $usaIntervalo = false;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDesde)
     && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fHasta)
     && $fDesde <= $fHasta) {
        $usaIntervalo = true;
    } else {
        $fDesde = '';
        $fHasta = '';
    }

    $data = MaintenancePlanStore::load();
    $proximas = $data['proximas'] ?? [];
    $perIdx   = MaintenancePeriodicidadStore::loadIndexed();

    // Agrupar por máquina solo las activas (no pausadas, no baja, no
    // bloqueadas) — son las que tienen tiempo "vivo" que invertir.
    $byMaq = [];
    foreach ($proximas as $p) {
        // Filtros básicos: tarea viva en el plan
        if (!empty($p['fecha_pausado']))            continue;
        if (($p['alta_baja'] ?? 'ALTA') === 'BAJA') continue;
        if (($p['activa']    ?? 'A')    === 'B')    continue;
        $bi = (string)($p['fecha_bloqueo_ini'] ?? '');
        $bf = (string)($p['fecha_bloqueo_fin'] ?? '');
        if ($bi && $bf && $hoy >= $bi && $hoy <= $bf) continue;

        $cod  = (string)($p['cod_maquina_mant'] ?? '');
        if ($codFiltro !== '' && $cod !== $codFiltro) continue;

        $desc = (string)($p['desc_maquina'] ?? $cod);

        if ($qFiltro !== '') {
            $hay = mb_strtolower($desc . ' ' . $cod);
            if (mb_strpos($hay, $qFiltro) === false) continue;
        }

        // Aplicar override de periodicidad si existe (para que el tiempo
        // se contabilice en la periodicidad EFECTIVA, no la original).
        $idOv = MaintenancePeriodicidadStore::buildId(
            (string)($p['orden'] ?? ''), (string)($p['tarea'] ?? '')
        );
        $eff = MaintenancePeriodicidadStore::applyOverride(
            $p, $perIdx[$idOv] ?? null
        );

        $per   = strtoupper((string)($eff['periodicidad'] ?? ''));
        $te    = isset($eff['tiempo_estimado']) ? (int)$eff['tiempo_estimado'] : 0;
        $px    = (string)($eff['proxima_revision'] ?? '');
        $pend  = ($px !== '' && $px <= $hoy);

        // Si el cliente pidió un intervalo, las tareas SIN proxima_revision
        // o fuera del rango no se contabilizan en este ámbito.
        if ($usaIntervalo) {
            if ($px === '' || $px < $fDesde || $px > $fHasta) continue;
        }

        if (!isset($byMaq[$cod])) {
            $byMaq[$cod] = [
                'cod_maquina_mant'   => $cod,
                'desc_maquina'       => $desc,
                'plan_total_tareas'  => 0,
                'plan_total_minutos' => 0,
                'pend_total_tareas'  => 0,
                'pend_total_minutos' => 0,
                'por_periodicidad'   => [],
                'tareas'             => [],
            ];
        }
        $byMaq[$cod]['plan_total_tareas']++;
        $byMaq[$cod]['plan_total_minutos'] += $te;
        if ($pend) {
            $byMaq[$cod]['pend_total_tareas']++;
            $byMaq[$cod]['pend_total_minutos'] += $te;
        }
        // Desglose por periodicidad
        if (!isset($byMaq[$cod]['por_periodicidad'][$per])) {
            $byMaq[$cod]['por_periodicidad'][$per] = [
                'plan_n' => 0, 'plan_min' => 0, 'pend_n' => 0, 'pend_min' => 0,
            ];
        }
        $byMaq[$cod]['por_periodicidad'][$per]['plan_n']++;
        $byMaq[$cod]['por_periodicidad'][$per]['plan_min'] += $te;
        if ($pend) {
            $byMaq[$cod]['por_periodicidad'][$per]['pend_n']++;
            $byMaq[$cod]['por_periodicidad'][$per]['pend_min'] += $te;
        }
        // Lista detallada
        $byMaq[$cod]['tareas'][] = [
            'tarea'            => (string)($eff['tarea'] ?? ''),
            'periodicidad'     => $per,
            'desc_tarea'       => (string)($eff['desc_tarea'] ?? ''),
            'tiempo_min'       => $te,
            'proxima_revision' => $px,
            'es_pendiente'     => $pend,
        ];
    }

    // Ordenar máquinas por tiempo total pendiente DESC (las más urgentes
    // primero); en caso de empate, por tiempo plan total DESC.
    $maquinas = array_values($byMaq);
    usort($maquinas, function ($a, $b) {
        if ($b['pend_total_minutos'] !== $a['pend_total_minutos']) {
            return $b['pend_total_minutos'] - $a['pend_total_minutos'];
        }
        if ($b['plan_total_minutos'] !== $a['plan_total_minutos']) {
            return $b['plan_total_minutos'] - $a['plan_total_minutos'];
        }
        return strcmp((string)$a['desc_maquina'], (string)$b['desc_maquina']);
    });

    // Dentro de cada máquina, ordenar tareas: primero pendientes por
    // próxima_revision ASC, después no-pendientes por próxima_revision ASC.
    foreach ($maquinas as &$m) {
        usort($m['tareas'], function ($a, $b) {
            if ($a['es_pendiente'] !== $b['es_pendiente']) {
                return $a['es_pendiente'] ? -1 : 1;
            }
            return strcmp((string)$a['proxima_revision'], (string)$b['proxima_revision']);
        });
        // Convertir por_periodicidad a array ordenado de menor a mayor cadencia.
        $ordenPer = ['DIARIO','DIARIA','SEMANAL','QUINCENAL','MENSUAL','BIMESTRAL','BIMENSUAL','TRIMESTRAL','CUATRIMESTRAL','SEMESTRAL','ANUAL'];
        $ordered = [];
        foreach ($ordenPer as $pName) {
            if (isset($m['por_periodicidad'][$pName])) {
                $ordered[$pName] = $m['por_periodicidad'][$pName];
            }
        }
        // Añadir cualquier periodicidad no estándar al final
        foreach ($m['por_periodicidad'] as $k => $v) {
            if (!isset($ordered[$k])) $ordered[$k] = $v;
        }
        $m['por_periodicidad'] = $ordered;
    }
    unset($m);

    $totGlobalPlan = 0; $totGlobalPend = 0;
    foreach ($maquinas as $m) {
        $totGlobalPlan += $m['plan_total_minutos'];
        $totGlobalPend += $m['pend_total_minutos'];
    }

    jsonOk([
        'maquinas'              => $maquinas,
        'total_global_plan_min' => $totGlobalPlan,
        'total_global_pend_min' => $totGlobalPend,
        'hoy'                   => $hoy,
        'usa_intervalo'         => $usaIntervalo,
        'rango_desde'           => $usaIntervalo ? $fDesde : null,
        'rango_hasta'           => $usaIntervalo ? $fHasta : null,
    ]);

} catch (\Throwable $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
