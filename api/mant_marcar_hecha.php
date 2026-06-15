<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePendienteStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';

// Marcar como hecha es la única acción de escritura permitida al operario.
Auth::requireLoginApi();
Auth::requireCsrfApi();

/**
 * Marca una revisión preventiva como hecha (o como "no realizada") desde la web.
 *
 * POST application/json | application/x-www-form-urlencoded
 * Campos:
 *   - orden                  (req)
 *   - tarea                  (req)
 *   - fecha_proxima_original (req, Y-m-d)
 *   - tipo                   (opc) 'completada' (default) | 'no_realizada'
 *   - operario               (opc)
 *   - observaciones          (opc)
 *
 * Si tipo='completada':
 *   - fecha_intervencion (opc, default hoy)
 *   - hora_inicio        (opc, HH:MM)
 *
 * Si tipo='no_realizada':
 *   - motivo_no_realizada (req) — uno de:
 *       'disponibilidad_maquina', 'disponibilidad_operario', 'falta_material'
 *   - fecha_intervencion NO se envía (se almacena NULL)
 *
 * Respuesta:
 *   { ok: true, data: { item: <stored>, total_marcadas: N } }
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido', 405);
}

$payload = [];
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = [];
} else {
    $payload = $_POST;
}

try {
    $required = ['orden', 'tarea', 'fecha_proxima_original'];
    foreach ($required as $k) {
        if (!isset($payload[$k]) || $payload[$k] === '') jsonError("Campo requerido: $k");
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$payload['fecha_proxima_original'])) {
        jsonError('fecha_proxima_original inválida');
    }

    // Tipo de marcado: 'completada' (default) o 'no_realizada'
    $tipo = (string)($payload['tipo'] ?? 'completada');
    if (!in_array($tipo, ['completada', 'no_realizada'], true)) {
        jsonError("tipo debe ser 'completada' o 'no_realizada'");
    }

    $isOperario = Auth::isOperario();
    $sessionUser = (string)(Auth::user() ?? '');

    $fechaInt   = null;
    $horaInicio = null;
    $motivoNR   = '';

    if ($tipo === 'completada') {
        if ($isOperario) {
            $fechaInt = date('Y-m-d');
            $horaInicio = date('H:i');
        } else {
            $fechaInt = $payload['fecha_intervencion'] ?? date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fechaInt)) {
                jsonError('fecha_intervencion inválida');
            }
            // Hora de inicio opcional (HH:MM)
            if (!empty($payload['hora_inicio'])) {
                $h = trim((string)$payload['hora_inicio']);
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h)) {
                    jsonError('hora_inicio inválida (formato HH:MM)');
                }
                $horaInicio = $h;
            }
        }
    } else { // no_realizada
        $motivoNR = trim((string)($payload['motivo_no_realizada'] ?? ''));
        $motivosValidos = ['disponibilidad_maquina', 'disponibilidad_operario', 'falta_material'];
        if (!in_array($motivoNR, $motivosValidos, true)) {
            jsonError("motivo_no_realizada debe ser uno de: " . implode(', ', $motivosValidos));
        }
    }

    // Enriquecer datos desde el plan para tener info coherente
    // (descripciones, periodicidad, máquina). La búsqueda es por (orden,
    // tarea) — clave única en mant_plan. No exigimos que la fecha próxima
    // coincida literalmente con el plan: el servidor la reprograma tras
    // guardar la marca como completada.
    //
    // Caso especial: las filas consolidadas del listado tienen orden
    // "CONSOL:<cod_maquina_mant>" y tarea "CONSOL". Esa es una fila
    // virtual que NO existe en mant_plan — el frontend debería haber
    // expandido sus sub_tareas y mandado un payload por cada una. Si
    // llegamos aquí con CONSOL es que algo falló en el JS y debemos dar
    // un mensaje útil para diagnosticar.
    $ordenIn = (string)$payload['orden'];
    $tareaIn = (string)$payload['tarea'];
    if (strpos($ordenIn, 'CONSOL:') === 0 || strtoupper($tareaIn) === 'CONSOL') {
        error_log('[mant_marcar_hecha] payload virtual CONSOL recibido: '
            . json_encode($payload, JSON_UNESCAPED_UNICODE));
        jsonError('Esta es una fila consolidada (RACK/PLATAFORMA/TROLEY): '
            . 'el frontend no ha expandido las sub-tareas. Refresca la página '
            . '(Ctrl+F5) y vuelve a intentarlo.', 422);
    }

    // PRIMER PASO: buscar en proximas (filtradas: ALTA, activa, no pausada,
    // no bloqueada). Es el caso 99% normal.
    $data = MaintenancePlanStore::load();
    $found = null;
    foreach ($data['proximas'] as $p) {
        if ((string)$p['orden'] === $ordenIn
            && (string)$p['tarea'] === $tareaIn) {
            $found = $p;
            break;
        }
    }
    // SEGUNDO PASO: si no la hemos encontrado en el listado activo, vamos
    // directos a mant_plan SIN filtros. Una tarea puede haber quedado
    // pausada, bloqueada o marcada como baja entre el momento en que el
    // operario vio la fila en pantalla y el momento en que pulsa Guardar
    // — eso NO debe impedir registrar que se hizo el trabajo. Sólo se
    // niega el marcado si la fila realmente no existe en BD (orden/tarea
    // inventados).
    if (!$found) {
        try {
            $row = Db::pgFetchOne(
                "SELECT orden, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                        periodicidad, tarea, desc_tarea, activa,
                        to_char(ultima_revision,  'YYYY-MM-DD') AS ultima_revision,
                        to_char(proxima_revision, 'YYYY-MM-DD') AS proxima_revision,
                        tiempo_estimado
                   FROM mant_plan
                  WHERE orden = :o AND tarea = :t
                  LIMIT 1",
                [':o' => $ordenIn, ':t' => $tareaIn]
            );
            if ($row) $found = $row;
        } catch (\Throwable $_e) {
            // si la consulta directa falla, dejamos $found = null y
            // caemos al jsonError de abajo.
        }
    }
    if (!$found) {
        error_log('[mant_marcar_hecha] tarea no encontrada en mant_plan. payload='
            . json_encode($payload, JSON_UNESCAPED_UNICODE));
        jsonError(
            "La tarea indicada no existe en el plan de mantenimiento actual "
            . "(orden=\"$ordenIn\", tarea=\"$tareaIn\"). "
            . "Refresca la página (Ctrl+F5) — pudiera haber sido borrada o renombrada.",
            404
        );
    }

    $foundPlanProxima = (string)($found['proxima_revision'] ?? '');
    $perOverrideIdx = MaintenancePeriodicidadStore::loadIndexed();
    $idOverride = MaintenancePeriodicidadStore::buildId(
        (string)$found['orden'], (string)$found['tarea']
    );
    $found = MaintenancePeriodicidadStore::applyOverride(
        $found, $perOverrideIdx[$idOverride] ?? null
    );

    // Tiempo real en segundos: si el cliente lo envia, lo guardamos tal cual.
    // En marcado automatico de operario usamos el tiempo estimado exacto.
    $tiempoReal = null;
    if (isset($payload['tiempo_real_segundos']) && $payload['tiempo_real_segundos'] !== '') {
        $t = (int)$payload['tiempo_real_segundos'];
        if ($t < 0) $t = 0;
        if ($t > 36000) $t = 36000; // tope 10 horas
        $tiempoReal = $t;
    }
    if ($tiempoReal === null && $isOperario && $tipo === 'completada') {
        $estimadoMin = isset($found['tiempo_estimado']) ? (int)$found['tiempo_estimado'] : 0;
        if ($estimadoMin > 0) {
            $tiempoReal = min(36000, $estimadoMin * 60);
        }
    }

    $datosMarca = [
        'tipo'                   => $tipo,
        'orden'                  => $found['orden'],
        'cod_maquina_mant'       => $found['cod_maquina_mant'],
        'desc_maquina'           => $found['desc_maquina'],
        'grupo'                  => $found['grupo'],
        'desc_grupo'             => $found['desc_grupo'],
        'periodicidad'           => $found['periodicidad'],
        'tarea'                  => $found['tarea'],
        'desc_tarea'             => $found['desc_tarea'],
        'activa'                 => $found['activa'],
        'fecha_proxima_original' => (string)$payload['fecha_proxima_original'],
        'fecha_intervencion'     => $fechaInt, // null si tipo='no_realizada'
        'hora_inicio'            => $horaInicio,
        'motivo_no_realizada'    => $motivoNR,
        'operario'               => $isOperario ? $sessionUser : (string)($payload['operario'] ?? ''),
        'observaciones'          => (string)($payload['observaciones'] ?? ''),
        'marcada_por'            => $sessionUser !== '' ? $sessionUser : (string)($payload['marcada_por'] ?? ''),
        // Etiqueta "visita incompleta": cuando se marca un subset de las
        // sub-tareas de una consolidada, cada marca creada lleva este flag.
        'visita_incompleta'      => !empty($payload['visita_incompleta']),
    ];
    if ($tiempoReal !== null) {
        $datosMarca['tiempo_real_segundos'] = $tiempoReal;
    }
    $item = MaintenanceCompletionStore::add($datosMarca);
    $reprogramacion = null;
    if ($tipo === 'completada' && $fechaInt) {
        $reprogramacion = MaintenancePlanStore::avanzarRevisionTrasCompletada(
            (string)$found['orden'],
            (string)$found['tarea'],
            (string)$fechaInt,
            (string)$found['periodicidad']
        );
    }

    // Si esta revisión estaba marcada como pendiente, retiramos la bandera.
    // Probamos con la fecha del cliente y con la fecha original del plan:
    // suelen coincidir; difieren cuando hubo auto-reprogramación previa.
    $fechasPend = array_unique([
        (string)$payload['fecha_proxima_original'],
        $foundPlanProxima,
        (string)$found['proxima_revision'],
    ]);
    foreach ($fechasPend as $fpo) {
        if ($fpo === '') continue;
        $pendId = MaintenancePendienteStore::buildId(
            (string)$found['orden'], (string)$found['tarea'], $fpo
        );
        MaintenancePendienteStore::remove($pendId);
    }

    jsonOk([
        'item'            => $item,
        'reprogramacion'  => $reprogramacion,
        'total_marcadas'  => count(MaintenanceCompletionStore::loadAll()),
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
