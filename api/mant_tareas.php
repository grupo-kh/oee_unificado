<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';

Auth::requireLoginApi();

/**
 * Lista de tareas (PROXIMAS REV.) que cumplen con una periodicidad concreta.
 * Aplica overrides → la periodicidad mostrada es la efectiva (override si existe).
 * Excluye tareas ya marcadas como hechas desde la web (su próxima ya quedó atendida).
 *
 * Parámetros:
 *   - periodicidad     (req): VARILLAS = no aplica aquí, sólo periodicidades válidas.
 *   - cod_maquina_mant (opc): filtro adicional por máquina.
 */
try {
    $per = strtoupper(trim((string)getParam('periodicidad', '')));
    if ($per === '') jsonError('periodicidad requerida');

    $cm  = getParam('cod_maquina_mant');

    $data = MaintenancePlanStore::load();
    $proximas = $data['proximas'];
    $marcadasIdx = MaintenanceCompletionStore::loadIndexed();
    $perOverrideIdx = MaintenancePeriodicidadStore::loadIndexed();

    $hoy = date('Y-m-d');
    $rows = [];
    $maquinasSet = [];

    foreach ($proximas as $p) {
        // Saltar las ya marcadas como hechas
        if (!empty($p['proxima_revision'])) {
            $idMark = MaintenanceCompletionStore::buildId(
                (string)$p['orden'], (string)$p['tarea'], (string)$p['proxima_revision']
            );
            if (isset($marcadasIdx[$idMark])) continue;
        }

        // Aplicar override de periodicidad si existe
        $idOverride = MaintenancePeriodicidadStore::buildId(
            (string)$p['orden'], (string)$p['tarea']
        );
        $row = MaintenancePeriodicidadStore::applyOverride(
            $p,
            $perOverrideIdx[$idOverride] ?? null
        );

        $perEfectiva = strtoupper(trim((string)($row['periodicidad'] ?? '')));
        if ($perEfectiva !== $per) continue;
        if ($cm && $row['cod_maquina_mant'] !== $cm) continue;

        if (!empty($row['cod_maquina_mant'])) {
            $maquinasSet[$row['cod_maquina_mant']] = $row['desc_maquina'];
        }

        $px = $row['proxima_revision'] ?? null;
        if ($px) {
            $diff = (int)round((strtotime($px) - strtotime($hoy)) / 86400);
            $row['dias_restantes'] = $diff;
            $row['estado'] = $diff < 0 ? 'vencida' : ($diff <= 7 ? 'urgente' : 'en_plazo');
        } else {
            $row['dias_restantes'] = null;
            $row['estado'] = 'sin_fecha';
        }
        $rows[] = $row;
    }

    // Orden por próxima revisión asc, sin fecha al final
    usort($rows, function($a, $b) {
        $av = $a['proxima_revision'] ?? '9999-99-99';
        $bv = $b['proxima_revision'] ?? '9999-99-99';
        return strcmp($av, $bv);
    });

    $maquinas = [];
    foreach ($maquinasSet as $cod => $desc) {
        $maquinas[] = ['cod_maquina_mant' => $cod, 'desc_maquina' => $desc];
    }
    usort($maquinas, fn($a, $b) => strcmp($a['desc_maquina'], $b['desc_maquina']));

    jsonOk([
        'periodicidad'        => $per,
        'cod_maquina_mant'    => $cm ?: null,
        'hoy'                 => $hoy,
        'total'               => count($rows),
        'rows'                => $rows,
        'maquinas'            => $maquinas,
        'periodicidades_soportadas' => MaintenancePeriodicidadStore::periodicidadesSoportadas(),
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
