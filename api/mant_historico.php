<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

Auth::requireLoginApi();

/**
 * Histórico de intervenciones de mantenimiento.
 *
 * Parámetros:
 *   - fecha_desde (Y-m-d, default hoy-90d)
 *   - fecha_hasta (Y-m-d, default hoy)
 *   - cod_maquina_mant (opcional)
 *   - operario        (opcional)
 *   - periodicidad    (opcional)
 *   - limit           (default 1000, máx 5000)
 */
try {
    $hoy = date('Y-m-d');
    $fdesde = getParam('fecha_desde', date('Y-m-d', strtotime('-90 days')));
    $fhasta = getParam('fecha_hasta', $hoy);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');

    $cm = getParam('cod_maquina_mant');
    $op = getParam('operario');
    $pe = getParam('periodicidad');
    $limit = max(1, min(20000, (int)getParam('limit', '20000')));

    // Histórico unificado: el plan de mantenimiento ahora se persiste sólo
    // en el almacén web (data/maintenance_completed.json). El histórico del
    // Excel queda como semilla histórica y deliberadamente no se mezcla.
    $data = MaintenancePlanStore::load();
    $marcadasWeb = MaintenanceCompletionStore::loadAll();
    $undoWindow  = MaintenanceCompletionStore::UNDO_WINDOW_SECONDS;
    $now = time();

    // Construimos los catálogos para los selectores:
    //   - maquinasSet / periodicidadesSet: globales (no se restringen por
    //     filtro, así el usuario puede cambiar de máquina libremente).
    //   - operariosSet: si hay máquina filtrada, solo operarios de esa
    //     máquina; si no, todos.
    $maquinasSet = [];
    $operariosSet = [];
    $periodicidadesSet = [];

    foreach ($marcadasWeb as $m) {
        if (!empty($m['cod_maquina_mant'])) {
            $maquinasSet[$m['cod_maquina_mant']] = $m['desc_maquina'] ?? $m['cod_maquina_mant'];
        }
        if (!empty($m['periodicidad'])) {
            $periodicidadesSet[$m['periodicidad']] = true;
        }
        if (!empty($m['operario'])) {
            if (!$cm || ($m['cod_maquina_mant'] ?? '') === $cm) {
                $operariosSet[$m['operario']] = true;
            }
        }
    }

    $rows = [];
    foreach ($marcadasWeb as $m) {
        $f = $m['fecha_intervencion'] ?? null;
        $motivo = trim((string)($m['motivo_no_realizada'] ?? ''));
        $isMissed = ($f === null || $f === '') && $motivo !== '';

        // Filtro temporal:
        //   - registros realizados: por fecha_intervencion
        //   - registros no realizados: por fecha_proxima_original
        $fechaRef = $isMissed ? (string)($m['fecha_proxima_original'] ?? '') : (string)$f;
        if ($fechaRef === '') continue;
        if ($fechaRef < $fdesde) continue;
        if ($fechaRef > $fhasta) continue;

        if ($cm && ($m['cod_maquina_mant'] ?? '') !== $cm) continue;
        if ($op && ($m['operario']         ?? '') !== $op) continue;
        if ($pe && ($m['periodicidad']     ?? '') !== $pe) continue;

        $age = $now - (int)($m['marcada_at'] ?? 0);
        $rows[] = [
            'orden'              => $m['orden']              ?? '',
            'cod_maquina_mant'   => $m['cod_maquina_mant']   ?? '',
            'desc_maquina'       => $m['desc_maquina']       ?? '',
            'grupo'              => $m['grupo']              ?? '',
            'desc_grupo'         => $m['desc_grupo']         ?? '',
            'periodicidad'       => $m['periodicidad']       ?? '',
            'tarea'              => $m['tarea']              ?? '',
            'desc_tarea'         => $m['desc_tarea']         ?? '',
            'activa'             => $m['activa']             ?? '',
            'fecha_inicio'       => null,
            'fecha_proxima_original' => $m['fecha_proxima_original'] ?? null,
            'fecha_intervencion' => $f,
            'operario'           => $m['operario']           ?? '',
            'observaciones'      => $m['observaciones']      ?? '',
            'motivo_no_realizada' => $motivo,
            'no_realizada'       => $isMissed,
            'tipo'               => (string)($m['tipo'] ?? ($isMissed ? 'no_realizada' : 'completada')),
            'source'             => 'web',
            'id'                 => $m['id'] ?? null,
            'undoable'           => !$isMissed && $age <= $undoWindow,
            'marcada_at'         => $m['marcada_at']         ?? null,
            'marcada_por'        => $m['marcada_por']        ?? '',
            'hora_inicio'        => $m['hora_inicio']        ?? null,
            'tiempo_real_segundos' => $m['tiempo_real_segundos'] ?? null,
            'visita_incompleta'  => !empty($m['visita_incompleta']),
        ];
    }

    // Ordenar por fecha de evento descendente (más recientes primero).
    // Para registros no realizados se usa la fecha programada original.
    usort($rows, function($a, $b) {
        $fa = $a['fecha_intervencion'] ?: ($a['fecha_proxima_original'] ?? '');
        $fb = $b['fecha_intervencion'] ?: ($b['fecha_proxima_original'] ?? '');
        return strcmp((string)$fb, (string)$fa);
    });

    $totalAll = count($rows);

    // ───────── Agrupar por máquina → tarea → intervenciones ─────────
    //
    // El histórico ahora se entrega anidado: cada máquina expone su lista
    // de tareas preventivas, y cada tarea su historial de intervenciones
    // dentro del rango de fechas del filtro. La vista lo despliega como
    // un acordeón (clic en máquina → muestra tareas; clic en tarea →
    // muestra fechas/operarios).
    $rackFamily = function(string $desc): ?string {
        $s = trim($desc);
        if (!preg_match('/^RACK[\s\-]/i', $s)) return null;
        return strtoupper(trim(preg_replace('/\s*-\s*\d+\s*$/', '', $s)));
    };

    $machinesIdx = [];
    $maqKeys = []; // claves canónicas (con racks por familia) para conteo distinto
    $opKeys  = [];

    // Precargamos un mapa [orden|tarea → tiempo_estimado] de mant_plan para
    // mostrarlo en la cabecera de cada bloque de tarea del histórico.
    $tiempoEstimadoIdx = [];
    try {
        $teRows = Db::pgFetchAll("SELECT orden, tarea, tiempo_estimado FROM mant_plan WHERE tiempo_estimado IS NOT NULL");
        foreach ($teRows as $te) {
            $tiempoEstimadoIdx[((string)$te['orden']) . '|' . ((string)$te['tarea'])] = (int)$te['tiempo_estimado'];
        }
    } catch (Throwable $e) {
        // Si la query falla (modo JSON, BD no disponible) seguimos sin ese dato.
        $tiempoEstimadoIdx = [];
    }

    foreach ($rows as $r) {
        $codM = (string)$r['cod_maquina_mant'];
        if (!isset($machinesIdx[$codM])) {
            $machinesIdx[$codM] = [
                'cod_maquina_mant'    => $codM,
                'desc_maquina'        => (string)$r['desc_maquina'],
                'tasks'               => [],
                'total_intervenciones' => 0,
            ];
        }
        $tareaKey = ((string)$r['orden']) . '|' . ((string)$r['tarea']);
        if (!isset($machinesIdx[$codM]['tasks'][$tareaKey])) {
            $machinesIdx[$codM]['tasks'][$tareaKey] = [
                'orden'           => (string)$r['orden'],
                'tarea'           => (string)$r['tarea'],
                'desc_tarea'      => (string)$r['desc_tarea'],
                'desc_grupo'      => (string)$r['desc_grupo'],
                'periodicidad'    => (string)$r['periodicidad'],
                'tiempo_estimado' => $tiempoEstimadoIdx[$tareaKey] ?? null,
                'interventions'   => [],
            ];
        }
        $machinesIdx[$codM]['tasks'][$tareaKey]['interventions'][] = [
            'fecha_intervencion'     => $r['fecha_intervencion'],
            'fecha_proxima_original' => $r['fecha_proxima_original'],
            'operario'               => (string)$r['operario'],
            'tipo'                   => (string)$r['tipo'],
            'no_realizada'           => (bool)$r['no_realizada'],
            'motivo_no_realizada'    => (string)$r['motivo_no_realizada'],
            'observaciones'          => (string)$r['observaciones'],
            'id'                     => $r['id'],
            'hora_inicio'            => $r['hora_inicio'] ?? null,
            'tiempo_real_segundos'   => $r['tiempo_real_segundos'] ?? null,
            'visita_incompleta'      => !empty($r['visita_incompleta']),
        ];
        $machinesIdx[$codM]['total_intervenciones']++;

        // Conteos para los stats globales (racks agrupados por familia).
        $fam = $rackFamily((string)$r['desc_maquina']);
        $kMaq = $fam !== null ? 'RACK::' . $fam : $codM;
        $maqKeys[$kMaq] = true;
        if ((string)$r['operario'] !== '') $opKeys[(string)$r['operario']] = true;
    }

    // Convertimos tasks de assoc-array a list y ordenamos.
    $machines = [];
    foreach ($machinesIdx as $codM => $m) {
        $tasks = array_values($m['tasks']);

        // Consolidación SECUENCIA (racks / plataformas): todas las tareas de
        // la máquina se funden en UNA tarea virtual. En cada visita se hacen
        // a la vez, así que las intervenciones se deduplican por (fecha,
        // operario, tipo) — N filas en BD = 1 visita.
        if (MaintenancePlanStore::esConsolidable($m['desc_maquina']) && count($tasks) > 1) {
            $rankFn = ['MaintenancePlanStore', 'periodicidadRank'];
            $bestPer = $tasks[0]['periodicidad'];
            $persSet = [];
            $mergedDescs = [];
            $mergedInts = [];
            $seenVisit = [];
            foreach ($tasks as $t) {
                $per = (string)$t['periodicidad'];
                if ($per !== '') {
                    $persSet[$per] = true;
                    if ($rankFn($per) < $rankFn($bestPer)) $bestPer = $per;
                }
                $descPart = trim((string)($t['desc_tarea'] ?: $t['tarea']));
                if ($descPart !== '') $mergedDescs[] = '• ' . $descPart;
                foreach ($t['interventions'] as $iv) {
                    $fecha = (string)($iv['fecha_intervencion'] ?? '');
                    $fechaRef = $fecha !== '' ? $fecha : (string)($iv['fecha_proxima_original'] ?? '');
                    $vk = $fechaRef . '|' . (string)$iv['operario'] . '|' . (string)$iv['tipo'];
                    if (isset($seenVisit[$vk])) continue;
                    $seenVisit[$vk] = true;
                    $mergedInts[] = $iv;
                }
            }
            usort($mergedInts, function($a, $b) {
                $fa = $a['fecha_intervencion'] ?: ($a['fecha_proxima_original'] ?? '');
                $fb = $b['fecha_intervencion'] ?: ($b['fecha_proxima_original'] ?? '');
                return strcmp((string)$fb, (string)$fa);
            });
            $nSub = count($tasks);
            $persStr = count($persSet) > 1 ? ' · ' . implode(', ', array_keys($persSet)) : '';
            // Tiempo estimado consolidado = suma de los tiempos de las sub-tareas
            // (la visita las hace todas a la vez, así que es la duración total).
            $teConsol = 0; $teCount = 0;
            foreach ($tasks as $t) {
                if (isset($t['tiempo_estimado']) && $t['tiempo_estimado'] !== null) {
                    $teConsol += (int)$t['tiempo_estimado'];
                    $teCount++;
                }
            }
            $tasks = [[
                'orden'              => 'CONSOL:' . $codM,
                'tarea'              => 'CONSOL',
                'desc_tarea'         => 'Revisión completa · ' . $nSub . ' tareas' . $persStr . "\n" . implode("\n", $mergedDescs),
                'desc_grupo'         => $tasks[0]['desc_grupo'],
                'periodicidad'       => $bestPer,
                'tiempo_estimado'    => $teCount > 0 ? $teConsol : null,
                'consolidada'        => true,
                'subtareas_total'    => $nSub,
                'interventions'      => $mergedInts,
                'total_intervenciones' => count($mergedInts),
            ]];
            $totalInter = count($mergedInts);
        } else {
            // Tareas: por (periodicidad, tarea) ascendente para lectura natural.
            usort($tasks, function($a, $b) {
                $cp = strcmp($a['periodicidad'], $b['periodicidad']);
                if ($cp !== 0) return $cp;
                return strcmp($a['tarea'], $b['tarea']);
            });
            // Intervenciones: por fecha desc.
            foreach ($tasks as &$t) {
                usort($t['interventions'], function($a, $b) {
                    $fa = $a['fecha_intervencion'] ?: ($a['fecha_proxima_original'] ?? '');
                    $fb = $b['fecha_intervencion'] ?: ($b['fecha_proxima_original'] ?? '');
                    return strcmp((string)$fb, (string)$fa);
                });
                $t['total_intervenciones'] = count($t['interventions']);
            }
            unset($t);
            $totalInter = $m['total_intervenciones'];
        }

        $machines[] = [
            'cod_maquina_mant'    => $m['cod_maquina_mant'],
            'desc_maquina'        => $m['desc_maquina'],
            'total_intervenciones' => $totalInter,
            'total_tareas'        => count($tasks),
            'tasks'               => $tasks,
        ];
    }
    usort($machines, fn($a, $b) => strcmp($a['desc_maquina'], $b['desc_maquina']));

    // ───────── Agrupación por familia de racks ─────────
    // Todos los racks de la misma familia (p.ej. "RACK CUSTODIAS TA LH - 01..N")
    // se colapsan bajo una entrada-familia en el listado principal. Al
    // desplegar la familia, la vista muestra cada rack individual con sus
    // tareas consolidadas.
    $familyGroups = [];
    $finalMachines = [];
    foreach ($machines as $m) {
        $fam = $rackFamily((string)$m['desc_maquina']);
        if ($fam === null) {
            $finalMachines[] = $m;
            continue;
        }
        if (!isset($familyGroups[$fam])) {
            $familyGroups[$fam] = [
                'is_family'           => true,
                'family_key'          => $fam,
                'cod_maquina_mant'    => 'FAM::' . $fam,
                'desc_maquina'        => $fam,
                'children'            => [],
                'total_intervenciones' => 0,
                'total_tareas'        => 0,
                'total_maquinas'      => 0,
            ];
        }
        $familyGroups[$fam]['children'][] = $m;
        $familyGroups[$fam]['total_intervenciones'] += (int)$m['total_intervenciones'];
        $familyGroups[$fam]['total_tareas']        += (int)$m['total_tareas'];
        $familyGroups[$fam]['total_maquinas']++;
    }
    foreach ($familyGroups as $g) {
        usort($g['children'], fn($a, $b) => strcmp($a['desc_maquina'], $b['desc_maquina']));
        $finalMachines[] = $g;
    }
    usort($finalMachines, fn($a, $b) => strcmp($a['desc_maquina'], $b['desc_maquina']));
    $machines = $finalMachines;

    // Limit aplicado al nº de filas (intervenciones), no al nº de máquinas:
    // si superamos el límite, recortamos intervenciones por la cola hasta caber.
    $totalShown = 0; $truncado = false;
    $trimTasks = function(array &$tasksRef) use (&$totalShown, $limit, &$truncado) {
        $newTasks = [];
        foreach ($tasksRef as $t) {
            $available = $limit - $totalShown;
            if ($available <= 0) { $truncado = true; break; }
            if (count($t['interventions']) > $available) {
                $t['interventions'] = array_slice($t['interventions'], 0, $available);
                $totalShown += $available;
                $truncado = true;
            } else {
                $totalShown += count($t['interventions']);
            }
            $newTasks[] = $t;
            if ($truncado) break;
        }
        $tasksRef = $newTasks;
    };
    foreach ($machines as $i => &$mch) {
        if (!empty($mch['is_family'])) {
            $newChildren = [];
            foreach ($mch['children'] as $child) {
                $trimTasks($child['tasks']);
                if (!empty($child['tasks'])) $newChildren[] = $child;
                if ($truncado) break;
            }
            $mch['children'] = $newChildren;
        } else {
            $trimTasks($mch['tasks']);
        }
        if ($truncado) {
            $machines = array_slice($machines, 0, $i + 1);
            break;
        }
    }
    unset($mch);

    $maquinasDistintas  = count($maqKeys);
    $operariosDistintos = count($opKeys);

    $maquinas = [];
    foreach ($maquinasSet as $cod => $desc) {
        $maquinas[] = ['cod_maquina_mant' => $cod, 'desc_maquina' => $desc];
    }
    usort($maquinas, fn($a, $b) => strcmp($a['desc_maquina'], $b['desc_maquina']));

    $operarios = array_keys($operariosSet);
    sort($operarios);

    $periodicidades = array_keys($periodicidadesSet);
    sort($periodicidades);

    jsonOk([
        'fecha_desde'      => $fdesde,
        'fecha_hasta'      => $fhasta,
        'cod_maquina_mant' => $cm ?: null,
        'operario'         => $op ?: null,
        'periodicidad'     => $pe ?: null,
        'total'            => $totalAll,
        'mostrados'        => $totalShown,
        'truncado'         => $truncado,
        'maquinas_distintas' => $maquinasDistintas,
        'operarios_distintos' => $operariosDistintos,
        'machines'         => $machines,
        'maquinas'         => $maquinas,
        'operarios'        => $operarios,
        'periodicidades'   => $periodicidades,
        'fichero_actualizado' => date('Y-m-d H:i:s', $data['file_mtime']),
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
