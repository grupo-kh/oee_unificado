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
    // Compat: si llega `dias` antiguo, lo convertimos a rango (hoy → hoy+dias).
    $hoy = date('Y-m-d');
    $fdesde = (string) getParam('fecha_desde', '');
    $fhasta = (string) getParam('fecha_hasta', '');
    $diasRaw = getParam('dias', '');
    if ($fdesde === '' || $fhasta === '') {
        // Defaults: 90 días atrás → hoy+30. El "atrás" garantiza que las
        // tareas vencidas se vean al cargar; el "adelante" muestra próximas.
        $dias = max(1, min(365, (int)($diasRaw !== '' ? $diasRaw : 30)));
        if ($fdesde === '') $fdesde = date('Y-m-d', strtotime("-90 days"));
        if ($fhasta === '') $fhasta = date('Y-m-d', strtotime("+$dias days"));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    if ($fhasta < $fdesde) { $tmp = $fdesde; $fdesde = $fhasta; $fhasta = $tmp; }

    $cm = getParam('cod_maquina_mant');
    $pe = getParam('periodicidad');
    $solo = (int)getParam('solo_vencidas', '0') === 1;

    // Gap por periodicidad antes de declarar una tarea "vencida". Una tarea
    // semanal puede estar 3 días retrasada sin alarmar, una mensual una
    // semana, y así progresivamente.
    $gapVencida = function(string $per): int {
        switch (strtoupper(trim($per))) {
            case 'DIARIO': case 'DIARIA':      return 1;
            case 'SEMANAL':                    return 3;
            case 'QUINCENAL':                  return 5;
            case 'MENSUAL':                    return 7;
            case 'BIMESTRAL': case 'BIMENSUAL': return 10;
            case 'TRIMESTRAL':                 return 14;
            case 'CUATRIMESTRAL':              return 18;
            case 'SEMESTRAL':                  return 21;
            case 'ANUAL':                      return 30;
            default:                           return 0;
        }
    };

    $data = MaintenancePlanStore::load();
    $marcadasIdx = MaintenanceCompletionStore::loadIndexed();
    $perOverrideIdx = MaintenancePeriodicidadStore::loadIndexed();

    // Pre-filtrar las tareas YA marcadas antes de consolidar — así una
    // tarea de RACK ya marcada no se cuenta como sub-tarea pendiente
    // dentro de la fila consolidada.
    $proximasFiltradas = array_values(array_filter(
        $data['proximas'],
        function($p) use ($marcadasIdx) {
            $idMark = MaintenanceCompletionStore::buildId(
                (string)$p['orden'], (string)$p['tarea'], (string)($p['proxima_revision'] ?? '')
            );
            return !isset($marcadasIdx[$idMark]);
        }
    ));

    // Consolidar racks / plataformas: las N micro-tareas de un mismo rack o
    // plataforma con la misma periodicidad se funden en una sola fila
    // "Revisión completa". Útil para no generar muchas acciones pequeñas
    // separadas por días en una misma máquina (las del grupo SECUENCIA).
    $proximas = MaintenancePlanStore::consolidateSecuenciaProximas($proximasFiltradas);

    // El rango efectivo es [$fdesde, $fhasta]. Ya no usamos $dias.
    $rows = [];
    $maquinasSet = [];
    $periodicidadesSet = [];
    // Operarios desde el almacén web (el histórico de Excel ya no se usa).
    // Para filtros del listado: todos los que han intervenido (incluye históricos).
    $operarios = MaintenanceCompletionStore::loadOperarios();
    // Para el desplegable del popup "marcar como hecha": SOLO los activos del
    // catálogo (mant_operarios.activo=TRUE) con su nombre legible. El JS lo
    // usa para mostrar nombres en lugar de códigos.
    $operariosActivos = MaintenanceCompletionStore::loadOperariosActivos();

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
            // Filtramos por rango efectivo [fdesde, fhasta]. La proxima_revision
            // debe caer dentro del rango. Las vencidas (px < hoy) se incluyen
            // siempre si fdesde ≤ hoy.
            if ($px < $fdesde || $px > $fhasta) continue;
        }

        // Vencida con GAP por periodicidad. Una tarea solo se marca vencida
        // cuando lleva más de N días retrasada según su cadencia.
        $perEff = (string)($eff['periodicidad'] ?? '');
        $gap    = $gapVencida($perEff);
        // Estados:
        //   - vencida : diff < -gap (pasó el margen de tolerancia)
        //   - próxima : -gap ≤ diff ≤ 10 (a punto de vencer, aún en plazo)
        //   - en_plazo: diff > 10 (con holgura)
        // Internamente seguimos llamando 'urgente' al estado de "próxima" para
        // no romper el JS / exports / CSS legacy; las etiquetas visibles ya
        // dicen "Próxima".
        $estado = 'en_plazo';
        if ($diff < -$gap) {
            $estado = 'vencida';
        } elseif ($diff <= 10) {
            $estado = 'urgente';
        }
        $rows[] = $eff + [
            'dias_restantes' => $diff,
            'gap_vencida'    => $gap,
            'estado'         => $estado,
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
        'fecha_desde'      => $fdesde,
        'fecha_hasta'      => $fhasta,
        'hoy'              => $hoy,
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
        'operarios_activos'=> $operariosActivos,
        'total_marcadas'   => count($marcadasIdx),
        'fichero_actualizado' => date('Y-m-d H:i:s', $data['file_mtime']),
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
