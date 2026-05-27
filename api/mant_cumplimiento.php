<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';

Auth::requireLoginApi();

/**
 * Cumplimiento preventivo (snapshot del estado actual del plan).
 * Para cada tarea de "PROXIMAS REV." se mira si su próxima revisión ya está
 * vencida (no_cumple) o todavía no (cumple).
 *
 *   % cumplimiento = en_plazo / total
 *
 * Devuelve global + agregado por periodicidad.
 *
 * Parámetros:
 *   - cod_maquina_mant (opcional)
 *   - periodicidad     (opcional)
 */
try {
    $cm = getParam('cod_maquina_mant');
    $pe = getParam('periodicidad');
    $hoy = date('Y-m-d');

    // Rango de fechas para el gauge (default: últimos 12 meses).
    $defDesde = date('Y-m-d', strtotime('-12 months'));
    $fdesde   = (string)getParam('fecha_desde', $defDesde);
    $fhasta   = (string)getParam('fecha_hasta', $hoy);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');

    $data = MaintenancePlanStore::load();
    $proximas = $data['proximas'];
    $marcadasIdx = MaintenanceCompletionStore::loadIndexed();
    $perOverrideIdx = MaintenancePeriodicidadStore::loadIndexed();

    $perAcc = [];
    $maquinasSet = [];
    $periodicidadesSet = [];

    // Catálogo de máquinas y periodicidades (para los selectores).
    foreach ($proximas as $p) {
        if ($p['cod_maquina_mant'] !== '') $maquinasSet[$p['cod_maquina_mant']] = $p['desc_maquina'];
        if ($p['periodicidad']     !== '') $periodicidadesSet[$p['periodicidad']] = true;
    }

    // Detección de máquinas del grupo SECUENCIA (E66, RACKS, PLATAFORMAS, TROLEYS):
    // se excluyen del cómputo de "no realizadas" y "recuperaciones" tanto
    // en el gauge como en el detalle por mes.
    $isSecuencia = function(string $desc): bool {
        $s = trim($desc);
        return preg_match('/^E66($|[^A-Za-z0-9])/i', $s)
            || preg_match('/^RACK[\s\-_]/i', $s)
            || preg_match('/^PLATAFORMA/i', $s)
            || preg_match('/^TROLEY[\s\-_]/i', $s);
    };

    // ───────── Métrica del gauge: realizadas / (realizadas + previstas + atrasadas) ─────────
    //
    // El cuadro muestra el porcentaje de tareas realizadas frente a las que
    // quedan por hacer dentro del rango filtrado. Se desglosa así:
    //
    //   realizadas = intervenciones con tipo 'completada' o 'recuperacion' y
    //                fecha_intervencion en [fdesde, fhasta]
    //   previstas  = tareas pendientes (en proximas) con proxima_revision
    //                dentro del rango
    //   atrasadas  = tareas pendientes (en proximas) con proxima_revision
    //                anterior a fdesde (vienen vencidas de antes del rango)
    //
    //   cumpl = realizadas / (realizadas + previstas + atrasadas) * 100
    //
    // Conforme avanza el mes en curso (rango = mes actual), realizadas crece
    // y el porcentaje se acerca al 100%. Las máquinas SECUENCIA quedan fuera
    // del cómputo de previstas / atrasadas (no aparecen como pendientes).
    $realizadas = 0;
    foreach ($marcadasIdx as $rec) {
        $tipo = (string)($rec['tipo'] ?? '');
        if ($tipo !== 'completada' && $tipo !== 'recuperacion') continue;
        if ($cm && ($rec['cod_maquina_mant'] ?? '') !== $cm) continue;
        $fi = (string)($rec['fecha_intervencion'] ?? '');
        if ($fi === '' || $fi < $fdesde || $fi > $fhasta) continue;
        $descRec = (string)($rec['desc_maquina'] ?? '');
        if ($tipo === 'recuperacion' && $isSecuencia($descRec)) continue;
        $realizadas++;
    }

    // previstas / atrasadas vienen del plan vigente (mant_plan vía proximas).
    $previstas = 0;
    $atrasadas = 0;
    foreach ($proximas as $p) {
        $px = $p['proxima_revision'] ?? null;
        if ($px === null || $px === '') continue;
        if ($cm && $p['cod_maquina_mant'] !== $cm) continue;
        if ($isSecuencia((string)$p['desc_maquina'])) continue;

        if ($px >= $fdesde && $px <= $fhasta) {
            $previstas++;
        } elseif ($px < $fdesde) {
            $atrasadas++;
        }
        // Si $px > $fhasta: tarea programada para después del rango → no cuenta.
    }

    $denomTotal = $realizadas + $previstas + $atrasadas;
    $cumpl = $denomTotal > 0 ? round($realizadas / $denomTotal * 100, 2) : 0;

    $global = [
        'cumplimiento' => $cumpl,
        'realizadas'   => $realizadas,
        'previstas'    => $previstas,
        'atrasadas'    => $atrasadas,
        'cumple'       => $realizadas,
        'no_cumple'    => $previstas + $atrasadas,
        'total'        => $denomTotal,
        'fecha_desde'  => $fdesde,
        'fecha_hasta'  => $fhasta,
    ];

    // perAcc se mantiene como esqueleto vacío (compatibilidad con el endpoint).
    foreach ($proximas as $p) {
        $per = $p['periodicidad'] ?: 'SIN PERIODICIDAD';
        if (!isset($perAcc[$per])) $perAcc[$per] = ['total' => 0, 'cumple' => 0];
    }

    $periodicidadesArr = [];
    foreach ($perAcc as $per => $v) {
        $periodicidadesArr[] = [
            'periodicidad' => $per,
            'cumplimiento' => $v['total'] > 0 ? round($v['cumple'] / $v['total'] * 100, 2) : 0,
            'cumple'       => $v['cumple'],
            'no_cumple'    => $v['total'] - $v['cumple'],
            'total'        => $v['total'],
        ];
    }
    usort($periodicidadesArr, fn($a, $b) => strcmp($a['periodicidad'], $b['periodicidad']));

    $maquinas = [];
    foreach ($maquinasSet as $cod => $desc) {
        $maquinas[] = ['cod_maquina_mant' => $cod, 'desc_maquina' => $desc];
    }
    usort($maquinas, fn($a, $b) => strcmp($a['desc_maquina'], $b['desc_maquina']));

    $periodicidades = array_keys($periodicidadesSet);
    sort($periodicidades);

    // ───────── Cumplimiento por mes ─────────
    //
    // Métrica por mes M:
    //   denom_M = registros 'completada' o 'no_realizada' con fpo en M
    //             + tareas vivas en el plan con proxima_revision en M y
    //               proxima_revision < hoy ("vencidas sin marcar")
    //   numer_M = registros 'completada' (fpo en M)
    //             + registros 'recuperacion' con fecha_intervencion en M
    // Las "vencidas sin marcar" son tareas cuya fecha pasó pero nadie las
    // marcó: se cuentan como pendientes para que el mes en curso no aparezca
    // engañosamente al 100% (bug previo). Solo se incluyen meses cuyas
    // fechas (fpo, fi o proxima_revision) caen dentro de [fdesde, fhasta].
    // Las máquinas SECUENCIA están excluidas de no_realizada/recuperacion
    // y también de "vencidas sin marcar".

    $perMesAcc = [];

    // Set de pares (orden, tarea, fpo) ya cubiertos por una marca — evita
    // doble conteo cuando la próxima_revision viva coincide con la fpo de
    // una marca (caso poco frecuente pero posible).
    $marcasClaves = [];

    foreach ($marcadasIdx as $rec) {
        $tipo = (string)($rec['tipo'] ?? '');
        if ($tipo === '') {
            $tipo = empty($rec['fecha_intervencion']) ? 'no_realizada' : 'completada';
        }
        $cmRec = (string)($rec['cod_maquina_mant'] ?? '');
        $peRec = (string)($rec['periodicidad'] ?? '');
        $descRec = (string)($rec['desc_maquina'] ?? '');
        $ordenRec = (string)($rec['orden'] ?? '');
        $tareaRec = (string)($rec['tarea'] ?? '');
        $fpoRec   = (string)($rec['fecha_proxima_original'] ?? '');

        if ($cm && $cmRec !== $cm) continue;
        if ($pe && $peRec !== $pe) continue;

        if (($tipo === 'no_realizada' || $tipo === 'recuperacion') && $isSecuencia($descRec)) {
            continue;
        }

        if ($tipo === 'recuperacion') {
            $fi = (string)($rec['fecha_intervencion'] ?? '');
            if ($fi === '' || $fi < $fdesde || $fi > $fhasta) continue;
            $m = substr($fi, 0, 7);
            if (!isset($perMesAcc[$m])) $perMesAcc[$m] = ['denom'=>0,'numer'=>0,'completadas'=>0,'no_realizadas'=>0,'recuperaciones'=>0,'vencidas_sin_marcar'=>0,'pendientes_futuras'=>0,'anticipadas'=>0];
            $perMesAcc[$m]['numer']++;
            $perMesAcc[$m]['recuperaciones']++;
        } else {
            if ($fpoRec === '' || $fpoRec < $fdesde || $fpoRec > $fhasta) continue;
            $m = substr($fpoRec, 0, 7);
            if (!isset($perMesAcc[$m])) $perMesAcc[$m] = ['denom'=>0,'numer'=>0,'completadas'=>0,'no_realizadas'=>0,'recuperaciones'=>0,'vencidas_sin_marcar'=>0,'pendientes_futuras'=>0,'anticipadas'=>0];

            // Las marcas anticipadas (fpo > hoy) NO cuentan en el cumplimiento
            // del mes futuro: no se puede "hacer" una tarea antes de que llegue
            // su fecha programada. Se guardan en 'anticipadas' como dato
            // informativo, pero el cumplimiento futuro queda en 0% mientras no
            // haya intervenciones reales con fecha en ese mes.
            if ($fpoRec > $hoy) {
                $perMesAcc[$m]['anticipadas']++;
                // Registramos la clave igualmente para evitar doble conteo
                // contra pendientes_futuras del bucle de abajo.
                $marcasClaves[$ordenRec . '||' . $tareaRec . '||' . $fpoRec] = true;
                continue;
            }

            $perMesAcc[$m]['denom']++;
            if ($tipo === 'completada' || !empty($rec['fecha_intervencion'])) {
                $perMesAcc[$m]['numer']++;
                $perMesAcc[$m]['completadas']++;
            } else {
                $perMesAcc[$m]['no_realizadas']++;
            }
            $marcasClaves[$ordenRec . '||' . $tareaRec . '||' . $fpoRec] = true;
        }
    }

    // ── Añadir tareas vivas del plan al denominador del mes que toque ──
    //   - Si proxima_revision está en el pasado y nadie la marcó →
    //     'vencidas_sin_marcar' (cuenta como pendiente).
    //   - Si proxima_revision está en el futuro → 'pendientes_futuras'
    //     (suma al denom para que el mes futuro tenga un 0% honesto, no
    //     un 100% engañoso ni un "—" vacío).
    foreach ($proximas as $p) {
        $px = (string)($p['proxima_revision'] ?? '');
        if ($px === '' || $px < $fdesde || $px > $fhasta) continue;

        $cmP = (string)($p['cod_maquina_mant'] ?? '');
        $peP = (string)($p['periodicidad'] ?? '');
        if ($cm && $cmP !== $cm) continue;
        if ($pe && $peP !== $pe) continue;
        if ($isSecuencia((string)($p['desc_maquina'] ?? ''))) continue;

        $clave = (string)($p['orden'] ?? '') . '||' . (string)($p['tarea'] ?? '') . '||' . $px;
        if (isset($marcasClaves[$clave])) continue;  // ya contada por marca

        $m = substr($px, 0, 7);
        if (!isset($perMesAcc[$m])) $perMesAcc[$m] = ['denom'=>0,'numer'=>0,'completadas'=>0,'no_realizadas'=>0,'recuperaciones'=>0,'vencidas_sin_marcar'=>0,'pendientes_futuras'=>0,'anticipadas'=>0];
        $perMesAcc[$m]['denom']++;
        if ($px < $hoy) {
            $perMesAcc[$m]['vencidas_sin_marcar']++;
        } else {
            $perMesAcc[$m]['pendientes_futuras']++;
        }
    }

    ksort($perMesAcc);
    $perMesArr = [];
    // Acumulador global recalculado: sumamos los numer/denom de todos los meses
    // del rango para que el gauge central y la barra del mes muestren EL MISMO
    // número (antes el gauge usaba realizadas/(realizadas+previstas+atrasadas)
    // y daba un valor distinto al de la barra).
    $sumDenom = 0; $sumNumer = 0;
    $sumCompletadas = 0; $sumNoRealizadas = 0; $sumRecuperaciones = 0;
    $sumVencSinMarcar = 0; $sumPendFuturas = 0; $sumAnticipadas = 0;
    foreach ($perMesAcc as $mes => $v) {
        $pct = $v['denom'] > 0 ? round($v['numer'] / $v['denom'] * 100, 2) : null;
        $perMesArr[] = [
            'mes'                 => $mes,
            'cumplimiento'        => $pct,
            'denom'               => $v['denom'],
            'numer'               => $v['numer'],
            'completadas'         => $v['completadas'],
            'no_realizadas'       => $v['no_realizadas'],
            'recuperaciones'      => $v['recuperaciones'],
            'vencidas_sin_marcar' => $v['vencidas_sin_marcar'],
            'pendientes_futuras'  => $v['pendientes_futuras'] ?? 0,
            'anticipadas'         => $v['anticipadas']        ?? 0,
        ];
        $sumDenom         += $v['denom'];
        $sumNumer         += $v['numer'];
        $sumCompletadas   += $v['completadas'];
        $sumNoRealizadas  += $v['no_realizadas'];
        $sumRecuperaciones+= $v['recuperaciones'];
        $sumVencSinMarcar += $v['vencidas_sin_marcar'];
        $sumPendFuturas   += $v['pendientes_futuras'] ?? 0;
        $sumAnticipadas   += $v['anticipadas']        ?? 0;
    }

    // Sobrescribir el global con la métrica unificada (la misma que las barras
    // y la misma que el detalle del mes). Mantenemos también las claves
    // antiguas (realizadas/previstas/atrasadas) por compatibilidad — pero
    // 'cumplimiento' ya viene de aquí.
    $cumplGlobalUnificado = $sumDenom > 0
        ? round($sumNumer / $sumDenom * 100, 2) : 0;
    $global['cumplimiento']        = $cumplGlobalUnificado;
    $global['numer']               = $sumNumer;
    $global['denom']               = $sumDenom;
    $global['completadas']         = $sumCompletadas;
    $global['no_realizadas']       = $sumNoRealizadas;
    $global['recuperaciones']      = $sumRecuperaciones;
    $global['vencidas_sin_marcar'] = $sumVencSinMarcar;
    $global['pendientes_futuras']  = $sumPendFuturas;
    $global['anticipadas']         = $sumAnticipadas;

    jsonOk([
        'hoy'              => $hoy,
        'cod_maquina_mant' => $cm ?: null,
        'periodicidad'     => $pe ?: null,
        'global'           => $global,
        'periodicidades_data' => $periodicidadesArr,
        'meses_data'       => $perMesArr,
        'maquinas'         => $maquinas,
        'periodicidades'   => $periodicidades,
        'fichero_actualizado' => date('Y-m-d H:i:s', $data['file_mtime']),
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
