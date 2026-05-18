<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenancePendienteStore.php';

// Marcar pendiente es una operación de gestión → solo técnico.
Auth::requireTecnicoApi();
Auth::requireCsrfApi();

/**
 * Marca / desmarca una revisión como "pendiente de revisar".
 *
 * POST application/json | application/x-www-form-urlencoded
 * Campos:
 *   - orden                  (req)
 *   - tarea                  (req)
 *   - fecha_proxima_original (req, Y-m-d)
 *   - pendiente              (1 = marcar, 0 = quitar)
 *   - nota                   (opc)
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
    $pendiente = isset($payload['pendiente']) ? (int)$payload['pendiente'] === 1 : true;

    $id = MaintenancePendienteStore::buildId(
        (string)$payload['orden'],
        (string)$payload['tarea'],
        (string)$payload['fecha_proxima_original']
    );

    if (!$pendiente) {
        $removed = MaintenancePendienteStore::remove($id);
        jsonOk([
            'action'  => 'removed',
            'removed' => $removed,
        ]);
    }

    // Marcar como pendiente: enriquecer con datos del Excel.
    $data = MaintenancePlanStore::load();
    $found = null;
    foreach ($data['proximas'] as $p) {
        if ((string)$p['orden'] === (string)$payload['orden']
            && (string)$p['tarea'] === (string)$payload['tarea']
            && (string)$p['proxima_revision'] === (string)$payload['fecha_proxima_original']) {
            $found = $p;
            break;
        }
    }
    if (!$found) {
        jsonError('La revisión indicada no existe en PROXIMAS REV. del Excel actual', 404);
    }

    $item = MaintenancePendienteStore::set([
        'orden'                  => $found['orden'],
        'tarea'                  => $found['tarea'],
        'fecha_proxima_original' => $found['proxima_revision'],
        'cod_maquina_mant'       => $found['cod_maquina_mant'],
        'desc_maquina'           => $found['desc_maquina'],
        'desc_grupo'             => $found['desc_grupo'],
        'desc_tarea'             => $found['desc_tarea'],
        'periodicidad'           => $found['periodicidad'],
        'nota'                   => (string)($payload['nota'] ?? ''),
        'set_por'                => (string)($payload['set_por'] ?? ''),
    ]);

    jsonOk([
        'action' => 'set',
        'item'   => $item,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
