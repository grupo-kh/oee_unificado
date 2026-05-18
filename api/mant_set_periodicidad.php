<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';

// Cambio de periodicidad → solo técnico.
Auth::requireTecnicoApi();
Auth::requireCsrfApi();

/**
 * Crea o elimina un override de periodicidad para una tarea.
 *
 * POST application/json | application/x-www-form-urlencoded:
 *   - orden        (req)
 *   - tarea        (req)
 *   - periodicidad (req): nueva periodicidad o 'ORIGINAL' para eliminar override
 *   - nota         (opc)
 *   - set_por      (opc)
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
    foreach (['orden', 'tarea', 'periodicidad'] as $k) {
        if (!isset($payload[$k]) || $payload[$k] === '') jsonError("Campo requerido: $k");
    }
    $per = strtoupper(trim((string)$payload['periodicidad']));
    $orden = (string)$payload['orden'];
    $tarea = (string)$payload['tarea'];

    // Validar que la tarea existe en el Excel
    $data = MaintenancePlanStore::load();
    $found = null;
    foreach ($data['proximas'] as $p) {
        if ((string)$p['orden'] === $orden && (string)$p['tarea'] === $tarea) {
            $found = $p; break;
        }
    }
    if (!$found) jsonError('Tarea no encontrada en PROXIMAS REV.', 404);

    $id = MaintenancePeriodicidadStore::buildId($orden, $tarea);

    // 'ORIGINAL' o coincide con la del Excel → eliminar override
    if ($per === 'ORIGINAL' || $per === strtoupper(trim((string)$found['periodicidad']))) {
        $removed = MaintenancePeriodicidadStore::remove($id);
        jsonOk([
            'action'             => 'removed',
            'id'                 => $id,
            'removed'            => $removed,
            'periodicidad_actual'=> $found['periodicidad'],
        ]);
    }

    if (!in_array($per, MaintenancePeriodicidadStore::periodicidadesSoportadas(), true)) {
        jsonError("Periodicidad no soportada: $per (válidas: " .
            implode(',', MaintenancePeriodicidadStore::periodicidadesSoportadas()) . ')');
    }

    $item = MaintenancePeriodicidadStore::set([
        'orden'        => $orden,
        'tarea'        => $tarea,
        'periodicidad' => $per,
        'nota'         => (string)($payload['nota'] ?? ''),
        'set_por'      => (string)($payload['set_por'] ?? ''),
    ]);

    // Calcular nueva próxima_revision para devolverla al cliente
    $row = MaintenancePeriodicidadStore::applyOverride($found, $item);

    jsonOk([
        'action'           => 'set',
        'item'             => $item,
        'periodicidad_original' => $found['periodicidad'],
        'periodicidad_efectiva' => $item['periodicidad'],
        'proxima_revision' => $row['proxima_revision'] ?? null,
        'recalculada'      => $row['proxima_recalculada'] ?? false,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
