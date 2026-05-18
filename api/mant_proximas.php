<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';

Auth::requireLoginApi();

/**
 * Próximas revisiones.
 * Parámetros:
 *   - dias              (int, default 30): ventana hacia el futuro.
 *   - cod_maquina_mant  (opcional): filtrar por máquina.
 *   - periodicidad      (opcional): filtrar por periodicidad.
 *   - solo_vencidas     (0/1): si 1, devuelve solo las que ya pasaron.
 */
try {
    $diasRaw = getParam('dias', '30');
    $dias    = max(1, min(365, (int)$diasRaw));

    $cm = getParam('cod_maquina_mant');
    $pe = getParam('periodicidad');
    $solo = (int)getParam('solo_vencidas', '0') === 1;

    $data = MaintenancePlanStore::load();
    $proximas = $data['proximas'];
    $marcadasIdx = MaintenanceCompletionStore::loadIndexed();
    $perOverrideIdx = MaintenancePeriodicidadStore::loadIndexed();

    $hoy = date('Y-m-d');
    $limite = date('Y-m-d', strtotime("+$dias days"));

    $rows = [];
    $maquinasSet = [];
    $periodicidadesSet = [];
    // Operarios desde el almacén web (el histórico de Excel ya no se usa).
    $operarios = MaintenanceCompletionStore::loadOperarios();

    foreach ($proximas as $p) {
        // Aplicar override de periodicidad si existe (afecta a periodicidad
        // efectiva y a próxima_revision recalculada).
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

        $px = $eff['proxima_revision'] ?? null;
        if ($px === null) continue;

        // Si la revisión ya fue marcada como hecha desde la web, omitirla.
        // Para identificar la marca usamos la fecha próxima ORIGINAL del Excel,
        // no la recalculada (la marca se hizo sobre la programada en su día).
        $idMark = MaintenanceCompletionStore::buildId(
            (string)$p['orden'], (string)$p['tarea'], (string)$p['proxima_revision']
        );
        if (isset($marcadasIdx[$idMark])) continue;

        if ($cm && $eff['cod_maquina_mant'] !== $cm) continue;
        if ($pe && $eff['periodicidad']     !== $pe) continue;

        $diff = (int)round((strtotime($px) - strtotime($hoy)) / 86400);

        if ($solo) {
            if ($diff >= 0) continue;
        } else {
            if ($diff > $dias) continue;
        }

        $rows[] = $eff + [
            'dias_restantes' => $diff,
            'estado'         => $diff < 0 ? 'vencida' : ($diff <= 7 ? 'urgente' : 'en_plazo'),
        ];
    }

    usort($rows, fn($a, $b) => strcmp((string)$a['proxima_revision'], (string)$b['proxima_revision']));

    $vencidas = 0; $enPlazo = 0; $urgentes = 0;
    $countByMaq = [];
    foreach ($rows as $r) {
        if ($r['estado'] === 'vencida') $vencidas++;
        elseif ($r['estado'] === 'urgente') $urgentes++;
        else $enPlazo++;

        $cm2 = $r['cod_maquina_mant'];
        if ($cm2 === '') continue;
        if (!isset($countByMaq[$cm2])) {
            $countByMaq[$cm2] = [
                'cod_maquina_mant' => $cm2,
                'desc_maquina'     => $r['desc_maquina'],
                'total'            => 0,
                'vencidas'         => 0,
                'urgentes'         => 0,
            ];
        }
        $countByMaq[$cm2]['total']++;
        if ($r['estado'] === 'vencida') $countByMaq[$cm2]['vencidas']++;
        elseif ($r['estado'] === 'urgente') $countByMaq[$cm2]['urgentes']++;
    }
    $total = count($rows);
    $pctEnPlazo = $total > 0 ? round(($enPlazo + $urgentes) / $total * 100, 2) : 0;

    // Top 10 máquinas, ordenadas por (vencidas desc, urgentes desc, total desc).
    $topMaquinas = array_values($countByMaq);
    usort($topMaquinas, function($a, $b) {
        if ($a['vencidas'] !== $b['vencidas']) return $b['vencidas'] - $a['vencidas'];
        if ($a['urgentes'] !== $b['urgentes']) return $b['urgentes'] - $a['urgentes'];
        return $b['total'] - $a['total'];
    });
    $topMaquinas = array_slice($topMaquinas, 0, 10);

    $maquinas = [];
    foreach ($maquinasSet as $cod => $desc) {
        $maquinas[] = ['cod_maquina_mant' => $cod, 'desc_maquina' => $desc];
    }
    usort($maquinas, fn($a, $b) => strcmp($a['desc_maquina'], $b['desc_maquina']));

    $periodicidades = array_keys($periodicidadesSet);
    sort($periodicidades);

    jsonOk([
        'dias'             => $dias,
        'hoy'              => $hoy,
        'limite'           => $limite,
        'cod_maquina_mant' => $cm ?: null,
        'periodicidad'     => $pe ?: null,
        'solo_vencidas'    => $solo,
        'total'            => $total,
        'vencidas'         => $vencidas,
        'urgentes'         => $urgentes,
        'en_plazo'         => $enPlazo,
        'pct_en_plazo'     => $pctEnPlazo,
        'rows'             => $rows,
        'top_maquinas'     => $topMaquinas,
        'maquinas'         => $maquinas,
        'periodicidades'   => $periodicidades,
        'operarios'        => $operarios,
        'total_marcadas'   => count($marcadasIdx),
        'fichero_actualizado' => date('Y-m-d H:i:s', $data['file_mtime']),
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
