<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenanceOperariosStore.php';

Auth::requireLoginApi();
// Toda esta API es del rol técnico — no exponemos NINGUNA acción al operario.
Auth::requireTecnicoApi();

/**
 * API CRUD de Gestión de operarios.
 *
 * GET  ?action=list                         Listado completo
 * GET  ?action=catalog                      Catálogos (puestos, capacitaciones)
 * GET  ?action=get&numero=N                 Detalle de un operario
 *
 * POST ?action=create                       Body JSON con todos los campos
 * POST ?action=update&numero=N              Body JSON con campos a actualizar
 * POST ?action=delete&numero=N              Solo si no tiene intervenciones
 */

function readJsonBody(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
    return $_POST ?: [];
}

try {
    $action = (string)getParam('action', 'list');

    // Acciones de escritura requieren CSRF
    if (in_array($action, ['create', 'update', 'delete'], true)) {
        Auth::requireCsrfApi();
    }

    switch ($action) {
        case 'list': {
            $soloAct = (string)getParam('solo_activos', '') === '1';
            $rows = MaintenanceOperariosStore::listAll($soloAct);
            jsonOk(['operarios' => $rows, 'total' => count($rows)]);
            break;
        }
        case 'catalog': {
            // Catálogos para el frontend (puestos + capacitaciones) en el
            // orden en que deben mostrarse.
            $puestos = [];
            foreach (MaintenanceOperariosStore::PUESTOS as $k => $label) {
                $puestos[] = ['key' => $k, 'label' => $label];
            }
            $caps = [];
            foreach (MaintenanceOperariosStore::CAPACITACION_LABELS as $k => $label) {
                $caps[] = ['key' => $k, 'label' => $label];
            }
            jsonOk(['puestos' => $puestos, 'capacitaciones' => $caps]);
            break;
        }
        case 'get': {
            $num = trim((string)getParam('numero', ''));
            if ($num === '') jsonError('Falta parámetro numero');
            $op = MaintenanceOperariosStore::get($num);
            if (!$op) jsonError('Operario no encontrado', 404);
            jsonOk(['operario' => $op]);
            break;
        }
        case 'create': {
            $body = readJsonBody();
            $op = MaintenanceOperariosStore::create($body);
            jsonOk(['operario' => $op]);
            break;
        }
        case 'update': {
            $num = trim((string)getParam('numero', ''));
            if ($num === '') jsonError('Falta parámetro numero');
            $body = readJsonBody();
            $op = MaintenanceOperariosStore::update($num, $body);
            jsonOk(['operario' => $op]);
            break;
        }
        case 'delete': {
            $num = trim((string)getParam('numero', ''));
            if ($num === '') jsonError('Falta parámetro numero');
            // No borramos si tiene intervenciones — el operario es referenciado
            // en mant_completions y romperíamos el histórico. Sugerimos dar de baja.
            if (MaintenanceOperariosStore::tieneIntervenciones($num)) {
                jsonError(
                    'No se puede borrar: el operario tiene intervenciones registradas. '
                    . 'Dale de baja (fecha_baja) en su lugar.',
                    409
                );
            }
            MaintenanceOperariosStore::delete($num);
            jsonOk(['ok' => true, 'numero' => $num]);
            break;
        }
        default:
            jsonError('action desconocida: ' . $action, 400);
    }
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (Throwable $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
