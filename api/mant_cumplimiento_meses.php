<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

Auth::requireLoginApi();

/**
 * Cumplimiento por mes filtrable.
 *
 * Parámetros:
 *   - cod_maquina_mant (opcional)
 *   - periodicidad     (opcional)
 *
 * Devuelve la misma estructura `meses_data` que mant_cumplimiento.php pero
 * como endpoint independiente, para el drill-down "ver el mismo gráfico
 * filtrado por periodicidad" sin afectar al gráfico principal.
 *
 * Métrica:
 *   denom_M = registros 'completada' o 'no_realizada' con fpo en M
 *   numer_M = registros 'completada' (fpo en M, fi no nulo)
 *             + registros 'recuperacion' con fi en M
 */
try {
    $cm = getParam('cod_maquina_mant');
    $pe = getParam('periodicidad');
    $hoy = date('Y-m-d');
    $fdesde = (string)getParam('fecha_desde', date('Y-m-d', strtotime('-12 months')));
    $fhasta = (string)getParam('fecha_hasta', $hoy);

    $isSecuencia = function(string $desc): bool {
        $s = trim($desc);
        return preg_match('/^E66($|[^A-Za-z0-9])/i', $s)
            || preg_match('/^RACK[\s\-_]/i', $s)
            || preg_match('/^PLATAFORMA/i', $s)
            || preg_match('/^TROLEY[\s\-_]/i', $s);
    };

    $items = MaintenanceCompletionStore::loadAll();
    $perMesAcc = [];

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

        if ($tipo === 'recuperacion') {
            $fi = (string)($rec['fecha_intervencion'] ?? '');
            if ($fi === '' || $fi < $fdesde || $fi > $fhasta) continue;
            $m = substr($fi, 0, 7);
            if (!isset($perMesAcc[$m])) {
                $perMesAcc[$m] = ['denom'=>0,'numer'=>0,'completadas'=>0,'no_realizadas'=>0,'recuperaciones'=>0];
            }
            $perMesAcc[$m]['numer']++;
            $perMesAcc[$m]['recuperaciones']++;
        } else {
            $fpo = (string)($rec['fecha_proxima_original'] ?? '');
            if ($fpo === '' || $fpo < $fdesde || $fpo > $fhasta) continue;
            $m = substr($fpo, 0, 7);
            if (!isset($perMesAcc[$m])) {
                $perMesAcc[$m] = ['denom'=>0,'numer'=>0,'completadas'=>0,'no_realizadas'=>0,'recuperaciones'=>0];
            }
            $perMesAcc[$m]['denom']++;
            if ($tipo === 'completada' || !empty($rec['fecha_intervencion'])) {
                $perMesAcc[$m]['numer']++;
                $perMesAcc[$m]['completadas']++;
            } else {
                $perMesAcc[$m]['no_realizadas']++;
            }
        }
    }
    ksort($perMesAcc);

    $out = [];
    foreach ($perMesAcc as $mes => $v) {
        $pct = $v['denom'] > 0 ? round($v['numer'] / $v['denom'] * 100, 2) : null;
        $out[] = [
            'mes'              => $mes,
            'cumplimiento'     => $pct,
            'denom'            => $v['denom'],
            'numer'            => $v['numer'],
            'completadas'      => $v['completadas'],
            'no_realizadas'    => $v['no_realizadas'],
            'recuperaciones'   => $v['recuperaciones'],
        ];
    }

    jsonOk([
        'cod_maquina_mant' => $cm ?: null,
        'periodicidad'     => $pe ?: null,
        'meses_data'       => $out,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
