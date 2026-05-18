<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

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

    $items = MaintenanceCompletionStore::loadAll();
    $rows = [];
    $tot = ['total'=>0,'realizadas'=>0,'no_realizadas'=>0,'recuperaciones'=>0,'denom'=>0,'numer'=>0];

    // SECUENCIA (E66, RACKS, PLATAFORMAS) no se computa como atrasada ni
    // recuperada — ni siquiera aparece en el detalle del mes para esos casos.
    $isSecuencia = function(string $desc): bool {
        $s = trim($desc);
        return preg_match('/^E66\b/i', $s)
            || preg_match('/^RACK[\s\-]/i', $s)
            || preg_match('/^PLATAFORMA/i', $s);
    };

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

        // ¿pertenece al mes?
        $belongs = false;
        if ($tipo === 'recuperacion') {
            if ($fi !== '' && substr($fi, 0, 7) === $mes) $belongs = true;
        } else {
            if ($fpo !== '' && substr($fpo, 0, 7) === $mes) $belongs = true;
        }
        if (!$belongs) continue;

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
    }

    // Orden: no realizadas primero, luego recuperaciones, luego completadas;
    // dentro de cada grupo por máquina y fecha.
    $rank = ['no_realizada'=>0, 'recuperacion'=>1, 'completada'=>2];
    usort($rows, function($a, $b) use ($rank) {
        $ra = $rank[$a['tipo']] ?? 9;
        $rb = $rank[$b['tipo']] ?? 9;
        if ($ra !== $rb) return $ra <=> $rb;
        $fa = $a['fecha_intervencion'] ?: ($a['fecha_proxima_original'] ?? '');
        $fb = $b['fecha_intervencion'] ?: ($b['fecha_proxima_original'] ?? '');
        return strcmp((string)$fa, (string)$fb);
    });

    $cumpl = $tot['denom'] > 0 ? round($tot['numer'] / $tot['denom'] * 100, 2) : null;

    jsonOk([
        'mes'              => $mes,
        'cod_maquina_mant' => $cm ?: null,
        'periodicidad'     => $pe ?: null,
        'cumplimiento'     => $cumpl,
        'totales'          => $tot,
        'rows'             => $rows,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
