<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';

Auth::requireLoginApi();

/**
 * API CRUD para "Acciones preventivas por máquina".
 *
 * Acciones (parámetro action):
 *   GET  ?action=maquinas               → lista de máquinas del catálogo + contadores
 *   GET  ?action=tareas&cod=X           → tareas de una máquina (con nº intervenciones)
 *   GET  ?action=periodicidades         → lista soportada para el dropdown
 *
 *   POST ?action=create_maquina         → crea una máquina nueva (cod, desc)
 *   POST ?action=update_maquina         → renombra/edita (cod, desc, notas)
 *   POST ?action=delete_maquina         → borra una máquina (solo si no tiene tareas)
 *
 *   POST ?action=create                 → crea una tarea
 *   POST ?action=update                 → actualiza tarea (requiere id)
 *   POST ?action=delete                 → borra tarea (requiere id)
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
    $action = (string)getParam('action', '');

    // Acciones de escritura: solo técnico. Las lecturas (maquinas, tareas,
    // periodicidades) están abiertas a ambos roles.
    $writeActions = [
        'create_maquina', 'update_maquina', 'delete_maquina', 'delete_maquina_impact',
        'create', 'update', 'delete',
    ];
    if (in_array($action, $writeActions, true)) {
        Auth::requireTecnicoApi();
        Auth::requireCsrfApi();
    }

    switch ($action) {
        case 'maquinas': {
            // Modo: 'activas' (default) | 'pausadas' | 'todas'
            // En el panel normal solo aparecen las activas. Las pausadas se
            // listan en views/mant_acciones_pausadas.php (solo técnico).
            $modo = (string)getParam('modo', 'activas');
            if (!in_array($modo, ['activas','pausadas','todas'], true)) $modo = 'activas';
            $rows = MaintenancePlanStore::listMaquinasConContador($modo);
            jsonOk([
                'maquinas' => $rows,
                'total'    => count($rows),
                'modo'     => $modo,
            ]);
            break;
        }

        case 'tareas': {
            $cod = (string)getParam('cod', '');
            if ($cod === '') jsonError('Falta parámetro cod (cod_maquina_mant)');
            // consolidar=0 fuerza vista detallada incluso para racks/plataformas
            $consolidar = (string)getParam('consolidar', '1') !== '0';
            // modo='pausadas' → solo tareas con fecha_pausado IS NOT NULL.
            // Cualquier otro valor (default 'todas') no filtra.
            $modoTareas = (string)getParam('modo', 'todas');
            $rows = MaintenancePlanStore::listTareasByMaquina($cod, $consolidar);
            if ($modoTareas === 'pausadas') {
                $rows = array_values(array_filter($rows, function ($t) {
                    // Tarea pausada directa, o "consolidada-virtual" con
                    // TODAS sus sub-tareas pausadas.
                    if (!empty($t['fecha_pausado'])) return true;
                    if (!empty($t['consolidada']) && !empty($t['sub_tareas'])) {
                        foreach ($t['sub_tareas'] as $s) {
                            if (empty($s['fecha_pausado'])) return false;
                        }
                        return true;
                    }
                    return false;
                }));
            }
            $descMaq = $rows ? (string)$rows[0]['desc_maquina'] : '';
            jsonOk([
                'cod_maquina_mant' => $cod,
                'desc_maquina'     => $descMaq,
                'total'            => count($rows),
                'tareas'           => $rows,
                'periodicidades'   => MaintenancePeriodicidadStore::periodicidadesSoportadas(),
            ]);
            break;
        }

        case 'periodicidades': {
            jsonOk(['periodicidades' => MaintenancePeriodicidadStore::periodicidadesSoportadas()]);
            break;
        }

        case 'create_maquina': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Solo POST', 405);
            $body = readJsonBody();
            $maq = MaintenancePlanStore::createMaquina($body);
            jsonOk(['maquina' => $maq]);
            break;
        }

        case 'update_maquina': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Solo POST', 405);
            $body = readJsonBody();
            $cod = trim((string)($body['cod_maquina_mant'] ?? ''));
            if ($cod === '') jsonError('Falta cod_maquina_mant');
            unset($body['cod_maquina_mant']);
            $maq = MaintenancePlanStore::updateMaquina($cod, $body);
            jsonOk(['maquina' => $maq]);
            break;
        }

        case 'delete_maquina_impact': {
            $cod = trim((string)getParam('cod', ''));
            if ($cod === '') jsonError('Falta parámetro cod');
            jsonOk(['impact' => MaintenancePlanStore::getDeleteImpact($cod)]);
            break;
        }

        case 'delete_maquina': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Solo POST', 405);
            $body = readJsonBody();
            $cod = trim((string)($body['cod_maquina_mant'] ?? ''));
            if ($cod === '') jsonError('Falta cod_maquina_mant');
            $cascade = !empty($body['cascade']);
            if ($cascade) {
                $impact = MaintenancePlanStore::deleteMaquinaCascade($cod);
                jsonOk(['ok' => true, 'cod_maquina_mant' => $cod, 'cascade' => true, 'deleted' => $impact]);
            } else {
                $ok = MaintenancePlanStore::deleteMaquina($cod);
                jsonOk(['ok' => $ok, 'cod_maquina_mant' => $cod, 'cascade' => false]);
            }
            break;
        }

        case 'create': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Solo POST', 405);
            $body = readJsonBody();
            $created = MaintenancePlanStore::createTarea($body);
            jsonOk(['tarea' => $created]);
            break;
        }

        case 'update': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Solo POST', 405);
            $body = readJsonBody();
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) jsonError('Falta id');
            unset($body['id'], $body['cod_maquina_mant']); // immutables
            $updated = MaintenancePlanStore::updateTarea($id, $body);
            jsonOk(['tarea' => $updated]);
            break;
        }

        case 'delete': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Solo POST', 405);
            $body = readJsonBody();
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) jsonError('Falta id');
            $ok = MaintenancePlanStore::deleteTarea($id);
            jsonOk(['ok' => $ok, 'id' => $id]);
            break;
        }

        default:
            jsonError("Acción no soportada: '$action'");
    }

} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (Throwable $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
