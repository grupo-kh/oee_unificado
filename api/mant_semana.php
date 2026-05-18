<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';
require_once __DIR__ . '/../lib/MaintenancePendienteStore.php';

Auth::requireLoginApi();

/**
 * Preventivos previstos en un rango de fechas, agrupados por máquina.
 *
 * Parámetros:
 *   - desde            (Y-m-d, opcional, default: hoy)
 *   - hasta            (Y-m-d, opcional, default: desde + 6 días)
 *   - cod_maquina_mant (opcional)
 *   - periodicidad     (opcional)
 *
 * Devuelve:
 *   - groups[]: { cod_maquina_mant, desc_maquina, tareas[], total, vencidas, urgentes, en_plazo, pendientes }
 *   - totales: { total, vencidas, urgentes, en_plazo, pendientes, maquinas }
 *   - maquinas[], periodicidades[], operarios[] (para selectores y modal)
 *
 * Nota: las tareas marcadas como "pendiente" se incluyen SIEMPRE, aunque su
 * próxima revisión esté fuera del rango. Los filtros de máquina/periodicidad
 * sí se aplican a las pendientes.
 */
try {
    $hoy = date('Y-m-d');

    $desde = getParam('desde');
    $hasta = getParam('hasta');

    if (!$desde || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $desde = $hoy;
    }
    if (!$hasta || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $hasta = date('Y-m-d', strtotime($desde) + 6 * 86400);
    }
    if ($hasta < $desde) { $hasta = $desde; }

    $cm = getParam('cod_maquina_mant');
    $pe = getParam('periodicidad');

    $data = MaintenancePlanStore::load();
    $proximas = $data['proximas'];
    $latestByTask   = MaintenanceCompletionStore::loadLatestByTask();
    $perOverrideIdx = MaintenancePeriodicidadStore::loadIndexed();
    $pendientesIdx  = MaintenancePendienteStore::loadIndexed();

    $groups = [];
    $maquinasSet = [];
    $periodicidadesSet = [];
    // Operarios desde el almacén web (el histórico de Excel ya no se usa).
    $operarios = MaintenanceCompletionStore::loadOperarios();

    foreach ($proximas as $p) {
        $idOverride = MaintenancePeriodicidadStore::buildId(
            (string)$p['orden'], (string)$p['tarea']
        );
        $eff = MaintenancePeriodicidadStore::applyOverride(
            $p, $perOverrideIdx[$idOverride] ?? null
        );

        if ($eff['cod_maquina_mant'] !== '') {
            $maquinasSet[$eff['cod_maquina_mant']] = $eff['desc_maquina'];
        }
        if ($eff['periodicidad'] !== '') {
            $periodicidadesSet[$eff['periodicidad']] = true;
        }

        // Auto-reprogramación: si esta tarea tiene un cumplimiento más
        // reciente que la última revisión del Excel, avanzamos la próxima
        // revisión a fecha_intervencion + días(periodicidad).
        $taskKey = (string)$p['orden'] . '|' . (string)$p['tarea'];
        $latest  = $latestByTask[$taskKey] ?? null;
        $isRescheduled = false;
        $rescheduledFromOriginal = null;
        if ($latest && !empty($latest['fecha_intervencion'])) {
            $latestDate  = (string)$latest['fecha_intervencion'];
            $excelUltima = (string)($p['ultima_revision'] ?? '');
            if ($latestDate >= $excelUltima) {
                $dias = MaintenancePeriodicidadStore::diasPorPeriodicidad($eff['periodicidad']);
                if ($dias !== null) {
                    $rescheduledFromOriginal = $eff['proxima_revision'] ?? null;
                    $eff['ultima_revision']  = $latestDate;
                    $eff['proxima_revision'] = date('Y-m-d', strtotime($latestDate) + $dias * 86400);
                    $eff['proxima_recalculada'] = true;
                    $isRescheduled = true;
                }
            }
        }

        $px = $eff['proxima_revision'] ?? null;
        if ($px === null) continue;

        // Bandera de pendiente (key por fecha próxima ORIGINAL del Excel,
        // no la recalculada — la marca se puso sobre esa programación).
        $idPend = MaintenancePendienteStore::buildId(
            (string)$p['orden'], (string)$p['tarea'], (string)$p['proxima_revision']
        );
        $pendienteRec = $pendientesIdx[$idPend] ?? null;
        $isPendiente  = $pendienteRec !== null;

        // Si la tarea fue reprogramada (ya está hecha y reescalada al futuro),
        // y no está marcada como pendiente, ocultamos la bandera roja heredada
        // de la programación anterior — ya no aplica.
        if ($isRescheduled && $isPendiente) {
            // El override de pendiente apunta a la fecha original; al
            // reprogramar la tarea, esa fecha ya está cerrada → desactivamos
            // la bandera silenciosamente para la nueva programación.
            $isPendiente = false;
            $pendienteRec = null;
        }

        if ($cm && $eff['cod_maquina_mant'] !== $cm) continue;
        if ($pe && $eff['periodicidad']     !== $pe) continue;

        // Filtro por rango: las pendientes se incluyen siempre.
        $inRange = ($px >= $desde && $px <= $hasta);
        if (!$inRange && !$isPendiente) continue;

        $diff = (int)round((strtotime($px) - strtotime($hoy)) / 86400);
        $estado = $diff < 0 ? 'vencida' : ($diff <= 7 ? 'urgente' : 'en_plazo');

        $cmKey = $eff['cod_maquina_mant'] !== '' ? $eff['cod_maquina_mant'] : '__SIN_MAQ__';
        if (!isset($groups[$cmKey])) {
            $groups[$cmKey] = [
                'cod_maquina_mant' => $eff['cod_maquina_mant'],
                'desc_maquina'     => $eff['desc_maquina'] ?: 'Sin máquina',
                'tareas'           => [],
                'total'            => 0,
                'vencidas'         => 0,
                'urgentes'         => 0,
                'en_plazo'         => 0,
                'pendientes'       => 0,
            ];
        }
        $row = $eff + [
            'dias_restantes' => $diff,
            'estado'         => $estado,
            'is_pendiente'   => $isPendiente,
            'fuera_de_rango' => !$inRange,
            'pendiente_set_at' => $isPendiente ? (int)($pendienteRec['set_at'] ?? 0) : 0,
            'pendiente_nota'   => $isPendiente ? (string)($pendienteRec['nota'] ?? '') : '',
            'is_rescheduled'   => $isRescheduled,
            'reschedule_from'  => $rescheduledFromOriginal,
        ];
        $groups[$cmKey]['tareas'][] = $row;
        $groups[$cmKey]['total']++;
        if     ($estado === 'vencida') $groups[$cmKey]['vencidas']++;
        elseif ($estado === 'urgente') $groups[$cmKey]['urgentes']++;
        else                            $groups[$cmKey]['en_plazo']++;
        if ($isPendiente) $groups[$cmKey]['pendientes']++;
    }

    // Ordenar tareas dentro de cada máquina:
    //   - Pendientes primero (las que el usuario debe revisar)
    //   - Luego por fecha próxima ascendente
    foreach ($groups as &$g) {
        usort($g['tareas'], function($a, $b) {
            if ($a['is_pendiente'] !== $b['is_pendiente']) {
                return $a['is_pendiente'] ? -1 : 1;
            }
            return strcmp((string)$a['proxima_revision'], (string)$b['proxima_revision']);
        });
    }
    unset($g);

    // Ordenar máquinas alfabéticamente
    $groupsArr = array_values($groups);
    usort($groupsArr, fn($a, $b) => strcmp($a['desc_maquina'], $b['desc_maquina']));

    // Totales globales
    $totVen = 0; $totUrg = 0; $totEnp = 0; $totAll = 0; $totPend = 0;
    foreach ($groupsArr as $g) {
        $totVen  += $g['vencidas'];
        $totUrg  += $g['urgentes'];
        $totEnp  += $g['en_plazo'];
        $totAll  += $g['total'];
        $totPend += $g['pendientes'];
    }

    $maquinas = [];
    foreach ($maquinasSet as $cod => $desc) {
        $maquinas[] = ['cod_maquina_mant' => $cod, 'desc_maquina' => $desc];
    }
    usort($maquinas, fn($a, $b) => strcmp($a['desc_maquina'], $b['desc_maquina']));

    $periodicidades = array_keys($periodicidadesSet);
    sort($periodicidades);

    $diasRango = (int)round((strtotime($hasta) - strtotime($desde)) / 86400) + 1;

    jsonOk([
        'hoy'                 => $hoy,
        'desde'               => $desde,
        'hasta'               => $hasta,
        'dias_rango'          => $diasRango,
        'cod_maquina_mant'    => $cm ?: null,
        'periodicidad'        => $pe ?: null,
        'groups'              => $groupsArr,
        'totales'             => [
            'total'      => $totAll,
            'vencidas'   => $totVen,
            'urgentes'   => $totUrg,
            'en_plazo'   => $totEnp,
            'pendientes' => $totPend,
            'maquinas'   => count($groupsArr),
        ],
        'maquinas'            => $maquinas,
        'periodicidades'      => $periodicidades,
        'operarios'           => $operarios,
        'fichero_actualizado' => date('Y-m-d H:i:s', $data['file_mtime']),
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
