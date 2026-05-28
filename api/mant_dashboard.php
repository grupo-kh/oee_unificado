<?php
/**
 * API: dashboard agregado para la app móvil del operario.
 * Devuelve en una sola llamada tres listas: tareas de hoy, vencidas,
 * y marcadas-pendientes manualmente.
 *
 * Reutiliza la lógica de auto-reprogramación de mant_mobile.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';
require_once __DIR__ . '/../lib/MaintenancePendienteStore.php';

Auth::requireLoginApi();

try {
    $hoy = date('Y-m-d');
    $data = MaintenancePlanStore::load();
    $proximas = $data['proximas'];
    $latestByTask   = MaintenanceCompletionStore::loadLatestByTask();
    $perOverrideIdx = MaintenancePeriodicidadStore::loadIndexed();
    $pendientesIdx  = MaintenancePendienteStore::loadIndexed();

    $hoyArr = [];
    $vencidasArr = [];
    $marcadasArr = [];

    foreach ($proximas as $p) {
        $idOverride = MaintenancePeriodicidadStore::buildId((string)$p['orden'], (string)$p['tarea']);
        $eff = MaintenancePeriodicidadStore::applyOverride($p, $perOverrideIdx[$idOverride] ?? null);

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

        $idPend = MaintenancePendienteStore::buildId(
            (string)$p['orden'], (string)$p['tarea'], (string)$p['proxima_revision']
        );
        $isPendiente = isset($pendientesIdx[$idPend]);

        $diff = (int)round((strtotime($px) - strtotime($hoy)) / 86400);

        $row = $eff + ['is_pendiente' => $isPendiente, 'dias_restantes' => $diff];

        if ($px === $hoy) {
            $row['estado'] = 'urgente';
            $hoyArr[] = $row;
        } elseif ($px < $hoy) {
            $row['estado'] = 'vencida';
            $vencidasArr[] = $row;
        }
        if ($isPendiente && $px !== $hoy) {
            // Las marcadas se muestran aparte (pueden ser pasadas o futuras).
            $row['estado'] = $row['estado'] ?? 'urgente';
            $marcadasArr[] = $row;
        }
    }

    // Ordenar: hoy por máquina, vencidas más antiguas primero, marcadas por fecha asc.
    usort($hoyArr,      fn($a, $b) => strcmp((string)$a['desc_maquina'], (string)$b['desc_maquina']));
    usort($vencidasArr, fn($a, $b) => strcmp((string)$a['proxima_revision'], (string)$b['proxima_revision']));
    usort($marcadasArr, fn($a, $b) => strcmp((string)$a['proxima_revision'], (string)$b['proxima_revision']));

    jsonOk([
        'hoy'       => $hoyArr,
        'vencidas'  => $vencidasArr,
        'marcadas'  => $marcadasArr,
        'fecha_hoy' => $hoy,
    ]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
