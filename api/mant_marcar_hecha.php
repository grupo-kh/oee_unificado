<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePendienteStore.php';

// Marcar como hecha es la única acción de escritura permitida al operario.
Auth::requireLoginApi();
Auth::requireCsrfApi();

/**
 * Marca una revisión preventiva como hecha desde la web.
 *
 * POST application/json | application/x-www-form-urlencoded
 * Campos:
 *   - orden                  (req)
 *   - tarea                  (req)
 *   - fecha_proxima_original (req, Y-m-d)
 *   - operario               (opc)
 *   - observaciones          (opc)
 *   - fecha_intervencion     (opc, default hoy)
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
    $fechaInt = $payload['fecha_intervencion'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$fechaInt)) {
        jsonError('fecha_intervencion inválida');
    }

    // Enriquecer datos desde el plan para tener info coherente
    // (descripciones, periodicidad, máquina). La búsqueda es por (orden,
    // tarea) — clave única en mant_plan. No exigimos que la fecha próxima
    // coincida literalmente con el plan: tras marcar una tarea como hecha,
    // la vista la auto-reprograma (fecha_intervencion + días periodicidad)
    // y el cliente envía esa fecha proyectada, que no figura en mant_plan.
    $data = MaintenancePlanStore::load();
    $found = null;
    foreach ($data['proximas'] as $p) {
        if ((string)$p['orden'] === (string)$payload['orden']
            && (string)$p['tarea'] === (string)$payload['tarea']) {
            $found = $p;
            break;
        }
    }
    if (!$found) {
        jsonError('La tarea indicada no existe en el plan de mantenimiento actual', 404);
    }

    $item = MaintenanceCompletionStore::add([
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
        'fecha_intervencion'     => $fechaInt,
        'operario'               => (string)($payload['operario']      ?? ''),
        'observaciones'          => (string)($payload['observaciones'] ?? ''),
        'marcada_por'            => (string)($payload['marcada_por']   ?? ''),
    ]);

    // Si esta revisión estaba marcada como pendiente, retiramos la bandera.
    // Probamos con la fecha del cliente y con la fecha original del plan:
    // suelen coincidir; difieren cuando hubo auto-reprogramación previa.
    $fechasPend = array_unique([
        (string)$payload['fecha_proxima_original'],
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
        'item'           => $item,
        'total_marcadas' => count(MaintenanceCompletionStore::loadAll()),
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
