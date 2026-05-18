<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

// Desmarcar es una operación de edición → solo técnico.
Auth::requireTecnicoApi();
Auth::requireCsrfApi();

/**
 * Desmarca una revisión previamente marcada como hecha desde la web.
 * Solo permitido dentro de la ventana de UNDO_WINDOW_SECONDS (24 h).
 *
 * POST con campo:
 *   - id  (req): el id devuelto al marcar (orden|tarea|fecha_proxima_original)
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
    $id = $payload['id'] ?? '';
    if ($id === '') jsonError('id requerido');

    $idx = MaintenanceCompletionStore::loadIndexed();
    if (!isset($idx[$id])) jsonError('Marca no encontrada', 404);

    if (!MaintenanceCompletionStore::isUndoable($idx[$id])) {
        jsonError('Esta marca tiene más de 24 h, ya no se puede desmarcar', 409);
    }

    $removed = MaintenanceCompletionStore::remove($id, false);
    if ($removed === null) {
        jsonError('No se pudo desmarcar (ventana caducada)', 409);
    }

    jsonOk([
        'removed_id'     => $id,
        'total_marcadas' => count(MaintenanceCompletionStore::loadAll()),
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
