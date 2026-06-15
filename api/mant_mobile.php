<?php
// Garantizar SIEMPRE JSON limpio, incluso ante errores fatales. Sin esto
// PHP podría devolver respuesta vacía o página HTML de error y el cliente
// móvil mostraría "respuesta inválida del servidor".
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode([
        'ok' => false,
        'error' => 'PHP error: ' . $message . ' (' . basename($file) . ':' . $line . ')',
    ]);
    exit;
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'ok' => false,
            'error' => 'Fatal: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')',
        ]);
    }
});

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';
require_once __DIR__ . '/../lib/MaintenancePendienteStore.php';

Auth::requireLoginApi();

/**
 * API simple para la webapp móvil de operarios.
 *
 * Acciones:
 *   - action=machines
 *       Devuelve la lista de máquinas con tareas pendientes de realizar
 *       (vencidas + urgentes hasta hoy + dias_horizonte, o marcadas como
 *       pendientes manualmente). Cada máquina trae el contador.
 *
 *   - action=tasks&cod_maquina_mant=X
 *       Devuelve las tareas pendientes de esa máquina con toda la info
 *       necesaria para mostrarlas y poder marcarlas hechas.
 *
 *   - action=operarios
 *       Devuelve la lista de operarios conocidos (del histórico).
 *
 * Parámetros adicionales:
 *   - dias_horizonte (default 7)
 *   - q (búsqueda case-insensitive sobre nombre/cod de máquina)
 *
 * Aplica auto-reprogramación: si una tarea ya fue marcada como hecha,
 * su próxima revisión = fecha_intervencion + días(periodicidad).
 */
try {
    $action = (string)getParam('action', 'machines');
    $dias = (int)getParam('dias_horizonte', '7');
    if ($dias < 0) $dias = 0;
    if ($dias > 60) $dias = 60;

    $hoy = date('Y-m-d');
    $limite = date('Y-m-d', strtotime($hoy) + $dias * 86400);

    $data = MaintenancePlanStore::load();
    $proximas = $data['proximas'];
    $latestByTask   = MaintenanceCompletionStore::loadLatestByTask();
    $perOverrideIdx = MaintenancePeriodicidadStore::loadIndexed();
    $pendientesIdx  = MaintenancePendienteStore::loadIndexed();

    $rows = [];
    foreach ($proximas as $p) {
        $idOverride = MaintenancePeriodicidadStore::buildId(
            (string)$p['orden'], (string)$p['tarea']
        );
        $eff = MaintenancePeriodicidadStore::applyOverride(
            $p, $perOverrideIdx[$idOverride] ?? null
        );

        $taskKey = (string)$p['orden'] . '|' . (string)$p['tarea'];
        $latest  = $latestByTask[$taskKey] ?? null;
        if ($latest && !empty($latest['fecha_intervencion'])) {
            $latestDate  = (string)$latest['fecha_intervencion'];
            $excelUltima = (string)($p['ultima_revision'] ?? '');
            if ($latestDate >= $excelUltima) {
                $diasPer = MaintenancePeriodicidadStore::diasPorPeriodicidad($eff['periodicidad']);
                if ($diasPer !== null) {
                    $eff['ultima_revision']  = $latestDate;
                    $eff['proxima_revision'] = date('Y-m-d', strtotime($latestDate) + $diasPer * 86400);
                    $eff['proxima_recalculada'] = true;
                }
            }
        }

        $px = $eff['proxima_revision'] ?? null;
        if ($px === null) continue;

        // Bandera de pendiente (sobre fecha original)
        $idPend = MaintenancePendienteStore::buildId(
            (string)$p['orden'], (string)$p['tarea'], (string)$p['proxima_revision']
        );
        $isPendiente = isset($pendientesIdx[$idPend]);

        // Solo nos quedamos con lo que el operario debería atender:
        //   - vencidas / urgentes (px <= hoy + dias_horizonte)
        //   - o explícitamente pendientes
        $isPending = ($px <= $limite) || $isPendiente;
        if (!$isPending) continue;

        $diff = (int)round((strtotime($px) - strtotime($hoy)) / 86400);
        $estado = $diff < 0 ? 'vencida' : ($diff <= 7 ? 'urgente' : 'en_plazo');

        $rows[] = $eff + [
            'dias_restantes' => $diff,
            'estado'         => $estado,
            'is_pendiente'   => $isPendiente,
        ];
    }

    if ($action === 'operarios') {
        // Operarios desde el almacén web (no usamos el histórico de Excel).
        $ops = MaintenanceCompletionStore::loadOperarios();
        jsonOk([
            'operarios' => $ops,
            'hoy'       => $hoy,
        ]);
    }

    if ($action === 'machines') {
        $q = trim((string)getParam('q', ''));
        $qLower = mb_strtolower($q);

        $byMaq = [];
        foreach ($rows as $r) {
            $cm = $r['cod_maquina_mant'];
            if ($cm === '') $cm = '__SIN_MAQ__';
            if (!isset($byMaq[$cm])) {
                $byMaq[$cm] = [
                    'cod_maquina_mant' => $r['cod_maquina_mant'],
                    'desc_maquina'     => $r['desc_maquina'] ?: 'Sin máquina',
                    'pending_count'    => 0,
                    'vencidas'         => 0,
                    'urgentes'         => 0,
                    'pendientes'       => 0,
                ];
            }
            $byMaq[$cm]['pending_count']++;
            if     ($r['estado'] === 'vencida') $byMaq[$cm]['vencidas']++;
            elseif ($r['estado'] === 'urgente') $byMaq[$cm]['urgentes']++;
            if ($r['is_pendiente']) $byMaq[$cm]['pendientes']++;
        }

        $machines = array_values($byMaq);
        if ($qLower !== '') {
            $machines = array_values(array_filter($machines, function($m) use ($qLower) {
                $hay = mb_strtolower($m['desc_maquina'] . ' ' . $m['cod_maquina_mant']);
                return mb_strpos($hay, $qLower) !== false;
            }));
        }
        usort($machines, fn($a, $b) => strcmp($a['desc_maquina'], $b['desc_maquina']));

        jsonOk([
            'machines'      => $machines,
            'total_pending' => count($rows),
            'dias_horizonte' => $dias,
            'hoy'            => $hoy,
        ]);
    }

    if ($action === 'tasks_due') {
        // Lista plana de tareas que el operario debería atender HOY:
        //   - estado 'vencida' (proxima_revision < hoy)
        //   - estado 'urgente' con dias_restantes <= dias_horizonte (default 0 = solo hoy)
        //   - pendientes marcadas manualmente
        // El prototipo móvil agrupa por estado (vencida / hoy), no por máquina.
        $qLower = mb_strtolower(trim((string)getParam('q', '')));
        $vencidas = [];
        $hoyArr   = [];
        foreach ($rows as $r) {
            if ($qLower !== '') {
                $hay = mb_strtolower(($r['desc_maquina'] ?? '') . ' ' . ($r['cod_maquina_mant'] ?? '') . ' ' . ($r['desc_tarea'] ?? ''));
                if (mb_strpos($hay, $qLower) === false) continue;
            }
            if ($r['estado'] === 'vencida') $vencidas[] = $r;
            else                            $hoyArr[]   = $r;
        }
        // Vencidas ordenadas por más antigua primero
        usort($vencidas, fn($a, $b) => strcmp((string)$a['proxima_revision'], (string)$b['proxima_revision']));
        // Caducan hoy: igual
        usort($hoyArr,   fn($a, $b) => strcmp((string)$a['proxima_revision'], (string)$b['proxima_revision']));

        jsonOk([
            'vencidas'       => $vencidas,
            'hoy'            => $hoyArr,
            'fecha_hoy'      => $hoy,
            'total_pending'  => count($rows),
            'dias_horizonte' => $dias,
        ]);
    }

    if ($action === 'tasks') {
        $cm = (string)getParam('cod_maquina_mant', '');
        if ($cm === '') jsonError('Falta cod_maquina_mant');

        $tasks = array_values(array_filter($rows, fn($r) => $r['cod_maquina_mant'] === $cm));
        usort($tasks, function($a, $b) {
            if ($a['is_pendiente'] !== $b['is_pendiente']) return $a['is_pendiente'] ? -1 : 1;
            return strcmp((string)$a['proxima_revision'], (string)$b['proxima_revision']);
        });

        $machineDesc = '';
        foreach ($tasks as $t) {
            if ($t['cod_maquina_mant'] === $cm) { $machineDesc = $t['desc_maquina']; break; }
        }

        jsonOk([
            'cod_maquina_mant' => $cm,
            'desc_maquina'     => $machineDesc,
            'tasks'            => $tasks,
            'hoy'              => $hoy,
        ]);
    }

    jsonError('Acción no soportada: ' . $action);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
