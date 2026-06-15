<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';

Auth::requireLoginApi();

/**
 * Detalle de tareas para un mes concreto.
 *
 * Parámetros:
 *   - mes              (req, formato YYYY-MM)
 *   - cod_maquina_mant (opcional)
 *   - periodicidad     (opcional)
 *
 * Devuelve las tareas cuyo evento "pertenece" al mes:
 *   - registros tipo 'completada' o 'no_realizada' con fpo en el mes
 *   - registros tipo 'recuperacion' con fi en el mes
 *
 * Cada fila trae:
 *   tipo, orden, tarea, máquina, descripción, periodicidad,
 *   fecha_proxima_original, fecha_intervencion, operario, observaciones,
 *   motivo_no_realizada, recuperada, recuperada_fecha
 *
 * También totales: total / realizadas / no_realizadas / recuperaciones
 * y % cumplimiento del mes.
 */
try {
    $mes = (string)getParam('mes', '');
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        jsonError('Parámetro "mes" inválido (formato YYYY-MM)');
    }
    $cm = getParam('cod_maquina_mant');
    $pe = getParam('periodicidad');
    // Rango opcional: si llega, recortamos el detalle a los días dentro de [fd, fh].
    // El frontend lo manda con los mismos filtros del panel principal.
    $fd = (string)getParam('fecha_desde', '');
    $fh = (string)getParam('fecha_hasta', '');
    if ($fd !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fd)) $fd = '';
    if ($fh !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fh)) $fh = '';

    $items = MaintenanceCompletionStore::loadAll();
    $rows = [];
    $tot = ['total'=>0,'realizadas'=>0,'no_realizadas'=>0,'recuperaciones'=>0,
            'vencidas_sin_marcar'=>0,'denom'=>0,'numer'=>0];

    // SECUENCIA (E66, RACKS, PLATAFORMAS) no se computa como atrasada ni
    // recuperada — ni siquiera aparece en el detalle del mes para esos casos.
    $isSecuencia = function(string $desc): bool {
        $s = trim($desc);
        return preg_match('/^E66\b/i', $s)
            || preg_match('/^RACK[\s\-]/i', $s)
            || preg_match('/^PLATAFORMA/i', $s);
    };

    // Set de marcas (orden|tarea|fpo) para evitar duplicación con vencidas sin marcar
    $marcasClaves = [];

    foreach ($items as $rec) {
        $cmRec = (string)($rec['cod_maquina_mant'] ?? '');
        $peRec = (string)($rec['periodicidad'] ?? '');
        $descRec = (string)($rec['desc_maquina'] ?? '');
        if ($cm && $cmRec !== $cm) continue;
        if ($pe && $peRec !== $pe) continue;

        $tipo = (string)($rec['tipo'] ?? '');
        if ($tipo === '') {
            $tipo = empty($rec['fecha_intervencion']) ? 'no_realizada' : 'completada';
        }
        if (($tipo === 'no_realizada' || $tipo === 'recuperacion') && $isSecuencia($descRec)) {
            continue;
        }

        $fpo = (string)($rec['fecha_proxima_original'] ?? '');
        $fi  = (string)($rec['fecha_intervencion']     ?? '');

        // Criterio coherente con el cálculo del cumplimiento global:
        //   - completada / no_realizada: pertenece al mes de su fpo.
        //   - recuperacion:              pertenece al mes de su fi.
        $belongs = false;
        if ($tipo === 'recuperacion') {
            if ($fi !== '' && substr($fi, 0, 7) === $mes) $belongs = true;
        } else {
            if ($fpo !== '' && substr($fpo, 0, 7) === $mes) $belongs = true;
        }
        if (!$belongs) continue;

        // Filtro de rango opcional (panel principal). Igual que el principal:
        // exigimos fpo en rango Y (si hay fi) fi también en rango. Para
        // recuperaciones (no tienen fpo) basta con fi en rango.
        if ($fd !== '' || $fh !== '') {
            if ($tipo === 'recuperacion') {
                if ($fi === ''
                    || ($fd !== '' && $fi < $fd)
                    || ($fh !== '' && $fi > $fh)) continue;
            } else {
                if ($fpo === ''
                    || ($fd !== '' && $fpo < $fd)
                    || ($fh !== '' && $fpo > $fh)) continue;
                if ($fi !== '') {
                    if (($fd !== '' && $fi < $fd) || ($fh !== '' && $fi > $fh)) continue;
                }
            }
        }

        $rows[] = [
            'id'                     => $rec['id']                     ?? null,
            'tipo'                   => $tipo,
            'orden'                  => $rec['orden']                  ?? '',
            'cod_maquina_mant'       => $rec['cod_maquina_mant']       ?? '',
            'desc_maquina'           => $rec['desc_maquina']           ?? '',
            'desc_grupo'             => $rec['desc_grupo']             ?? '',
            'periodicidad'           => $rec['periodicidad']           ?? '',
            'tarea'                  => $rec['tarea']                  ?? '',
            'desc_tarea'             => $rec['desc_tarea']             ?? '',
            'fecha_proxima_original' => $fpo,
            'fecha_intervencion'     => $fi,
            'operario'               => $rec['operario']               ?? '',
            'observaciones'          => $rec['observaciones']          ?? '',
            'motivo_no_realizada'    => $rec['motivo_no_realizada']    ?? '',
            'recuperada'             => !empty($rec['recuperada']),
            'recuperada_fecha'       => $rec['recuperada_fecha']       ?? null,
        ];
        $tot['total']++;
        if ($tipo === 'completada')      { $tot['realizadas']++;   $tot['denom']++; $tot['numer']++; }
        elseif ($tipo === 'no_realizada'){ $tot['no_realizadas']++; $tot['denom']++; }
        elseif ($tipo === 'recuperacion'){ $tot['recuperaciones']++; $tot['numer']++; }

        // Registrar la clave para evitar duplicados con vencidas-sin-marcar
        if ($tipo !== 'recuperacion' && $fpo !== '') {
            $marcasClaves[(string)($rec['orden'] ?? '') . '||' . (string)($rec['tarea'] ?? '') . '||' . $fpo] = true;
        }
    }

    // ── Añadir las "vencidas sin marcar" del mes (para coherencia con el
    //    cálculo del gauge y de la barra). Una tarea cuya próxima revisión
    //    cae en el mes, ya ha pasado (< hoy) y nadie la ha marcado todavía
    //    cuenta como pendiente, igual que una no_realizada. SECUENCIA fuera.
    $hoy = date('Y-m-d');
    $planData = MaintenancePlanStore::load();
    foreach ($planData['proximas'] as $p) {
        $px = (string)($p['proxima_revision'] ?? '');
        if ($px === '' || substr($px, 0, 7) !== $mes) continue;
        if ($px >= $hoy) continue;
        // Filtro de rango: solo cuentan las vencidas cuya proxima_revision
        // está dentro del rango filtrado por el panel principal. Sin esto,
        // el detalle mostraba vencidas que el cálculo global SÍ excluía,
        // dando "mes 100%" pero un grid con muchas tareas pendientes.
        if ($fd !== '' && $px < $fd) continue;
        if ($fh !== '' && $px > $fh) continue;
        $cmP = (string)($p['cod_maquina_mant'] ?? '');
        $peP = (string)($p['periodicidad'] ?? '');
        if ($cm && $cmP !== $cm) continue;
        if ($pe && $peP !== $pe) continue;
        if ($isSecuencia((string)($p['desc_maquina'] ?? ''))) continue;

        $clave = (string)($p['orden'] ?? '') . '||' . (string)($p['tarea'] ?? '') . '||' . $px;
        if (isset($marcasClaves[$clave])) continue;  // ya cubierta por una marca

        $rows[] = [
            'id'                     => null,
            'tipo'                   => 'vencida_sin_marcar',
            'orden'                  => $p['orden']            ?? '',
            'cod_maquina_mant'       => $p['cod_maquina_mant'] ?? '',
            'desc_maquina'           => $p['desc_maquina']     ?? '',
            'desc_grupo'             => $p['desc_grupo']       ?? '',
            'periodicidad'           => $p['periodicidad']     ?? '',
            'tarea'                  => $p['tarea']            ?? '',
            'desc_tarea'             => $p['desc_tarea']       ?? '',
            'fecha_proxima_original' => $px,
            'fecha_intervencion'     => '',
            'operario'               => '',
            'observaciones'          => '',
            'motivo_no_realizada'    => '',
            'recuperada'             => false,
            'recuperada_fecha'       => null,
        ];
        $tot['total']++;
        $tot['vencidas_sin_marcar']++;
        $tot['denom']++;
    }

    // Orden: vencidas sin marcar y no realizadas primero (urgencia visual),
    // luego recuperaciones, luego completadas; dentro de cada grupo por fecha.
    $rank = ['vencida_sin_marcar'=>0, 'no_realizada'=>1, 'recuperacion'=>2, 'completada'=>3];
    usort($rows, function($a, $b) use ($rank) {
        $ra = $rank[$a['tipo']] ?? 9;
        $rb = $rank[$b['tipo']] ?? 9;
        if ($ra !== $rb) return $ra <=> $rb;
        $fa = $a['fecha_intervencion'] ?: ($a['fecha_proxima_original'] ?? '');
        $fb = $b['fecha_intervencion'] ?: ($b['fecha_proxima_original'] ?? '');
        return strcmp((string)$fa, (string)$fb);
    });

    $cumpl = $tot['denom'] > 0 ? round($tot['numer'] / $tot['denom'] * 100, 2) : null;

    // ── Consolidación por (máquina + fecha efectiva + tipo) ────────────
    // Aplicada a TODAS las máquinas, no solo RACK/PLATAFORMA/TROLEY: si
    // varias tareas de la misma máquina se han realizado el mismo día y
    // son del mismo tipo (completada/no_realizada/recuperación/pendiente),
    // las agrupamos en una sola fila con un desplegable que detalla
    // cada sub-tarea hecha. Si la máquina solo tiene 1 tarea en ese día
    // queda como fila individual normal (no se "consolida" un grupo de uno).
    //
    // Convención: tras agrupar, "Revisión completa N tareas" se etiqueta
    // así cuando es máquina consolidable clásica (RACK/PLATAFORMA/TROLEY);
    // para el resto el frontend muestra "N tareas" sin la palabra "Revisión".
    $esClasicaConsolidable = function(string $desc): bool {
        $s = trim($desc);
        return preg_match('/^RACK[\s\-]/i', $s)
            || preg_match('/^PLATAFORMA/i', $s)
            || preg_match('/^TROLEY/i', $s);
    };

    // 1) Primer pase: agrupamos en bins por clave.
    $bins   = [];   // key → [filas]
    $orden  = [];   // mantiene el orden original de aparición de la clave
    foreach ($rows as $r) {
        $fEf = $r['fecha_intervencion'] ?: $r['fecha_proxima_original'];
        $key = ($r['cod_maquina_mant'] ?? '') . '||' . $fEf . '||' . $r['tipo'];
        if (!isset($bins[$key])) { $bins[$key] = []; $orden[] = $key; }
        $bins[$key][] = $r;
    }

    // 2) Segundo pase: emitir filas finales — consolidadas si bin>1, sino tal cual.
    $rowsOut = [];
    foreach ($orden as $key) {
        $bin = $bins[$key];
        if (count($bin) === 1) { $rowsOut[] = $bin[0]; continue; }

        // Construcción de la fila consolidada (toma datos comunes del primer
        // elemento; lista todas las sub-tareas)
        $first = $bin[0];
        $desc  = (string)($first['desc_maquina'] ?? '');
        $consol = [
            'id'                     => null,
            'consolidada'            => true,
            'consolidacion_clasica'  => $esClasicaConsolidable($desc), // RACK/PLATAFORMA/TROLEY
            'tipo'                   => $first['tipo'],
            'orden'                  => 'CONSOL:' . ($first['cod_maquina_mant'] ?? ''),
            'cod_maquina_mant'       => $first['cod_maquina_mant'] ?? '',
            'desc_maquina'           => $desc,
            'desc_grupo'             => $esClasicaConsolidable($desc) ? 'Revisión completa' : '',
            'periodicidad'           => $first['periodicidad'] ?? '',
            'tarea'                  => 'CONSOL',
            'desc_tarea'             => '',
            'fecha_proxima_original' => $first['fecha_proxima_original'] ?? '',
            'fecha_intervencion'     => $first['fecha_intervencion']     ?? '',
            'operario'               => '',
            'observaciones'          => '',
            'motivo_no_realizada'    => $first['motivo_no_realizada'] ?? '',
            'recuperada'             => !empty($first['recuperada']),
            'recuperada_fecha'       => $first['recuperada_fecha']       ?? null,
            'sub_tareas'             => [],
            'subtareas_total'        => 0,
            'periodicidades'         => [],
        ];
        foreach ($bin as $r) {
            $consol['sub_tareas'][] = [
                'tarea'        => $r['tarea']        ?? '',
                'desc_tarea'   => $r['desc_tarea']   ?? '',
                'periodicidad' => $r['periodicidad'] ?? '',
            ];
            $consol['subtareas_total']++;
            $perR = (string)($r['periodicidad'] ?? '');
            if ($perR !== '' && !in_array($perR, $consol['periodicidades'], true)) {
                $consol['periodicidades'][] = $perR;
            }
            // Primer operario no vacío adoptado por el grupo
            if (empty($consol['operario']) && !empty($r['operario'])) {
                $consol['operario'] = $r['operario'];
            }
        }
        $rowsOut[] = $consol;
    }

    jsonOk([
        'mes'              => $mes,
        'cod_maquina_mant' => $cm ?: null,
        'periodicidad'     => $pe ?: null,
        'cumplimiento'     => $cumpl,
        'totales'          => $tot,
        'rows'             => $rowsOut,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
