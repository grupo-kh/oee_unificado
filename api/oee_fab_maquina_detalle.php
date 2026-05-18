<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Detalle OEE de una máquina concreta.
 * Devuelve:
 *   - current: D/R/C/OEE para el día+turno seleccionado
 *   - evolucion: serie de los últimos 7 días (solo el turno seleccionado o todos)
 */

function calcDRC($r) {
    $M    = (float)$r['M'];
    $MT   = (float)$r['M_Teo'];
    $MOT  = (float)$r['M_OKNOK_TEO'];
    $MOKT = (float)$r['M_OK_TEO'];
    $PP   = (float)$r['PPERF'];
    $PC   = (float)$r['PCALIDAD'];
    $PNP  = (float)$r['PNP'];
    $d = ($M + $PNP) > 0 ? $M / ($M + $PNP) * 100 : 0;
    $p = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
    $c = ($MOT + $PC) > 0 ? $MOKT / ($MOT + $PC) * 100 : 0;
    $oee = $d * $p * $c / 10000;
    return [
        'disponibilidad' => round($d, 2),
        'rendimiento'    => round($p, 2),
        'calidad'        => round($c, 2),
        'oee'            => round($oee, 2),
        'M_Teo'          => (int)$MT,
    ];
}

try {
    $cod_maquina = getParam('cod_maquina');
    $fecha       = getParam('fecha', date('Y-m-d'));
    $turno       = getParam('turno');

    if (!$cod_maquina) jsonError('cod_maquina requerido');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');

    $turnoWhere = '';
    $turnoParams = [];
    if ($turno && in_array($turno, ['M','T','N'])) {
        $turnoWhere = " AND oee.Cod_turno = ?";
        $turnoParams[] = $turno;
    }

    // Desc_maquina
    $metaRows = fetchAll('mapex', "SELECT Desc_maquina FROM cfg_maquina WHERE Cod_maquina = ?", [$cod_maquina]);
    $desc_maquina = $metaRows[0]['Desc_maquina'] ?? $cod_maquina;

    // 1) Métricas del día seleccionado (el turno o el total del día si no hay turno)
    $sqlCur = "
        SELECT
            SUM(oee.M) AS M, SUM(oee.M_Teo) AS M_Teo,
            SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO, SUM(oee.M_OK_TEO) AS M_OK_TEO,
            SUM(oee.PPERF) AS PPERF, SUM(oee.PCALIDAD) AS PCALIDAD, SUM(oee.PNP) AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE oee.WorkGroup = ?
          AND CAST(oee.TimePeriod AS DATE) = ?
          $turnoWhere
    ";
    $paramsCur = array_merge([$fecha, $fecha, $cod_maquina, $fecha], $turnoParams);
    $curRows = fetchAll('mapex', $sqlCur, $paramsCur);
    $curRow = $curRows[0] ?? [
        'M'=>0,'M_Teo'=>0,'M_OKNOK_TEO'=>0,'M_OK_TEO'=>0,'PPERF'=>0,'PCALIDAD'=>0,'PNP'=>0
    ];
    $current = calcDRC($curRow);

    // 2) Serie 7 días: día a día, mismo turno (o todos los turnos si null)
    $fechaDesde = date('Y-m-d', strtotime($fecha . ' -6 days'));
    $sqlEvo = "
        SELECT
            CAST(oee.TimePeriod AS DATE) AS fecha,
            SUM(oee.M) AS M, SUM(oee.M_Teo) AS M_Teo,
            SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO, SUM(oee.M_OK_TEO) AS M_OK_TEO,
            SUM(oee.PPERF) AS PPERF, SUM(oee.PCALIDAD) AS PCALIDAD, SUM(oee.PNP) AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE oee.WorkGroup = ?
          $turnoWhere
        GROUP BY CAST(oee.TimePeriod AS DATE)
        ORDER BY fecha
    ";
    $paramsEvo = array_merge([$fechaDesde, $fecha, $cod_maquina], $turnoParams);
    $evoRows = fetchAll('mapex', $sqlEvo, $paramsEvo);

    $evolucion = [];
    foreach ($evoRows as $r) {
        $fechaStr = $r['fecha'] instanceof DateTime ? $r['fecha']->format('Y-m-d') : $r['fecha'];
        // Solo incluir días con algo programado
        if ((float)$r['M'] + (float)$r['PNP'] <= 0) continue;
        $drc = calcDRC($r);
        $evolucion[] = array_merge(['fecha' => $fechaStr], $drc);
    }

    // 3) Producción por hora del día/turno seleccionado (prorrateo temporal)
    //    Ventana según turno: M 06-14:15, T 14:15-22:30, N 22:30-06:00(+1d);
    //    sin turno: todo el día 00:00-23:59.
    $ventana = [];
    if (!$turno) {
        $ventana[] = ['ini' => "$fecha 00:00:00", 'fin' => "$fecha 23:59:59"];
    } else {
        $shifts = [
            'M' => ['06:00:00','14:15:00', false],
            'T' => ['14:15:00','22:30:00', false],
            'N' => ['22:30:00','06:00:00', true ],
        ];
        $s = $shifts[$turno];
        if ($s[2]) {
            $fin = new DateTime($fecha); $fin->modify('+1 day');
            $ventana[] = ['ini' => "$fecha {$s[0]}", 'fin' => $fin->format('Y-m-d') . " {$s[1]}"];
        } else {
            $ventana[] = ['ini' => "$fecha {$s[0]}", 'fin' => "$fecha {$s[1]}"];
        }
    }
    $winIni = $ventana[0]['ini']; $winFin = $ventana[0]['fin'];

    // Slots de 1h entre winIni y winFin
    $slots = [];
    $cur = new DateTime($winIni);
    $end = new DateTime($winFin);
    while ($cur < $end) {
        $nxt = clone $cur; $nxt->modify('+1 hour');
        if ($nxt > $end) $nxt = clone $end;
        $slots[] = [
            'label' => $cur->format('H:i'),
            'ini'   => $cur->format('Y-m-d H:i:s'),
            'fin'   => $nxt->format('Y-m-d H:i:s'),
        ];
        $cur = $nxt;
    }

    $horario = [];
    if (count($slots) > 0) {
        $valuesSQL = [];
        $paramsH = [];
        foreach ($slots as $s) {
            $valuesSQL[] = "(?, ?, ?)";
            array_push($paramsH, $s['label'], $s['ini'], $s['fin']);
        }
        $paramsH[] = $cod_maquina;
        $sqlH = "
            WITH slots(label, ini, fin) AS (
                SELECT V.c1, CAST(V.c2 AS DATETIME), CAST(V.c3 AS DATETIME)
                FROM (VALUES " . implode(',', $valuesSQL) . ") AS V(c1, c2, c3)
            )
            SELECT
                s.label AS hora,
                SUM(CAST(ISNULL(p.Unidades_ok,0) AS FLOAT) *
                    DATEDIFF(SECOND,
                        CASE WHEN p.Fecha_ini > s.ini THEN p.Fecha_ini ELSE s.ini END,
                        CASE WHEN ISNULL(p.Fecha_fin,p.Fecha_ini) < s.fin THEN ISNULL(p.Fecha_fin,p.Fecha_ini) ELSE s.fin END
                    ) / NULLIF(DATEDIFF(SECOND, p.Fecha_ini, ISNULL(p.Fecha_fin,p.Fecha_ini)), 0)
                ) AS prod_ok
            FROM slots s
            LEFT JOIN his_prod p
                   ON p.Fecha_ini < s.fin
                  AND ISNULL(p.Fecha_fin, p.Fecha_ini) > s.ini
            LEFT JOIN cfg_maquina mq ON mq.Id_maquina = p.Id_maquina
            WHERE mq.Cod_maquina = ?
            GROUP BY s.label
        ";
        $rowsH = fetchAll('mapex', $sqlH, $paramsH);
        $byHora = [];
        foreach ($rowsH as $r) $byHora[$r['hora']] = (float)($r['prod_ok'] ?? 0);
        foreach ($slots as $s) {
            $horario[] = ['hora' => $s['label'], 'prod' => round($byHora[$s['label']] ?? 0)];
        }
    }

    jsonOk([
        'cod_maquina' => $cod_maquina,
        'maquina'     => $desc_maquina,
        'fecha'       => $fecha,
        'turno'       => $turno ?: null,
        'current'     => $current,
        'evolucion'   => $evolucion,
        'horario'     => $horario,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
