<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Vista OEE unificada con filtros en cascada.
 *
 * Parámetros:
 *   - fecha_desde (YYYY-MM-DD)  default: hoy
 *   - fecha_hasta (YYYY-MM-DD)  default: hoy
 *   - turnos[] (M|T|N)          opcional; si vacío → todos los turnos
 *
 * Fórmulas (replican F_his_ct/QlikView):
 *   D = M / (M + PNP)
 *   R = (M_OKNOK_TEO + PCALIDAD) / (M + PPERF + PCALIDAD)
 *   C = M_OK_TEO / (M_OKNOK_TEO + PCALIDAD)
 *   OEE = D × R × C
 *
 * Devuelve:
 *   - global: { disponibilidad, rendimiento, calidad, oee, M_Teo, maquinas }
 *   - secciones: [{ seccion, D, R, C, OEE, maquinas }, …]  (VARILLAS, TROQUELADOS, OTROS si aplica)
 */

function _seccionDeDesc(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

function _calcDRC(float $M, float $MT, float $MOT, float $MOKT, float $PP, float $PC, float $PNP): array {
    $d = ($M + $PNP)      > 0 ? $M / ($M + $PNP) * 100              : 0;
    $r = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
    $c = ($MOT + $PC)     > 0 ? $MOKT / ($MOT + $PC) * 100           : 0;
    $oee = $d * $r * $c / 10000;
    return [
        'disponibilidad' => round($d, 2),
        'rendimiento'    => round($r, 2),
        'calidad'        => round($c, 2),
        'oee'            => round($oee, 2),
        'M_Teo'          => (int)$MT,
    ];
}

try {
    $hoy   = date('Y-m-d');
    $fdesde = (string) getParam('fecha_desde', $hoy);
    $fhasta = (string) getParam('fecha_hasta', $hoy);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    if ($fdesde > $fhasta) jsonError('fecha_desde no puede ser posterior a fecha_hasta');

    // turnos[]: si llega como string CSV o como array, normalizamos a array
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    // excl: máquinas (cod_maquina) excluidas del análisis (filtro global)
    $excl = getListParam('excl');

    // ───── WHERE base ─────
    $where  = [
        "CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?",
        "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')",
    ];
    $params = [$fdesde, $fhasta];

    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "oee.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    if (!empty($excl)) {
        $ph = implode(',', array_fill(0, count($excl), '?'));
        $where[] = "oee.WorkGroup NOT IN ($ph)";
        $params = array_merge($params, $excl);
    }
    $whereSQL = implode(' AND ', $where);

    // Query agregada por máquina (luego separamos por sección en PHP)
    $sql = "
        SELECT
            oee.WorkGroup        AS cod_maquina,
            mq.Desc_maquina      AS maquina,
            SUM(oee.M)           AS M,
            SUM(oee.M_Teo)       AS M_Teo,
            SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO,
            SUM(oee.M_OK_TEO)    AS M_OK_TEO,
            SUM(oee.PPERF)       AS PPERF,
            SUM(oee.PCALIDAD)    AS PCALIDAD,
            SUM(oee.PNP)         AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE $whereSQL
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
    ";
    // Los dos primeros params del F_his_ct son fdesde/fhasta también
    $allParams = array_merge([$fdesde, $fhasta], $params);
    $rows = fetchAll('mapex', $sql, $allParams);

    $globalAcc = ['M'=>0,'MT'=>0,'MOT'=>0,'MOKT'=>0,'PP'=>0,'PC'=>0,'PNP'=>0,'maquinas'=>0];
    $secAcc = [];
    $maqList = []; // máquinas con actividad en el rango (post-exclusión): {cod_maquina, maquina, seccion}

    foreach ($rows as $r) {
        $M    = (float)$r['M'];
        $MT   = (float)$r['M_Teo'];
        $MOT  = (float)$r['M_OKNOK_TEO'];
        $MOKT = (float)$r['M_OK_TEO'];
        $PP   = (float)$r['PPERF'];
        $PC   = (float)$r['PCALIDAD'];
        $PNP  = (float)$r['PNP'];
        $sec  = _seccionDeDesc($r['maquina']);

        $globalAcc['M']   += $M;   $globalAcc['MT']  += $MT;
        $globalAcc['MOT'] += $MOT; $globalAcc['MOKT']+= $MOKT;
        $globalAcc['PP']  += $PP;  $globalAcc['PC']  += $PC;
        $globalAcc['PNP'] += $PNP; $globalAcc['maquinas']++;

        $sKey = $sec ?: 'OTROS';
        if (!isset($secAcc[$sKey])) {
            $secAcc[$sKey] = ['M'=>0,'MT'=>0,'MOT'=>0,'MOKT'=>0,'PP'=>0,'PC'=>0,'PNP'=>0,'maquinas'=>0];
        }
        $secAcc[$sKey]['M']   += $M;   $secAcc[$sKey]['MT']  += $MT;
        $secAcc[$sKey]['MOT'] += $MOT; $secAcc[$sKey]['MOKT']+= $MOKT;
        $secAcc[$sKey]['PP']  += $PP;  $secAcc[$sKey]['PC']  += $PC;
        $secAcc[$sKey]['PNP'] += $PNP; $secAcc[$sKey]['maquinas']++;

        $maqList[] = [
            'cod_maquina' => $r['cod_maquina'],
            'maquina'     => $r['maquina'] ?: $r['cod_maquina'],
            'seccion'     => $sec ?: 'OTROS',
        ];
    }

    // Ordenar: sección (VARILLAS, TROQUELADOS, OTROS) + nombre máquina
    $secOrder = ['VARILLAS' => 0, 'TROQUELADOS' => 1, 'OTROS' => 2];
    usort($maqList, function($a, $b) use ($secOrder) {
        $oa = $secOrder[$a['seccion']] ?? 9;
        $ob = $secOrder[$b['seccion']] ?? 9;
        if ($oa !== $ob) return $oa <=> $ob;
        return strcasecmp($a['maquina'], $b['maquina']);
    });

    $global = _calcDRC(
        $globalAcc['M'], $globalAcc['MT'], $globalAcc['MOT'],
        $globalAcc['MOKT'], $globalAcc['PP'], $globalAcc['PC'], $globalAcc['PNP']
    );
    $global['maquinas'] = $globalAcc['maquinas'];
    $global['maquinas_activas'] = $maqList;

    // ───── KPI: cantidad de OFs distintas con actividad en el rango ─────
    $whereOf  = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "mq.Cod_maquina NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')",
    ];
    $paramsOf = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $whereOf[] = "ct.Cod_turno IN ($ph)";
        $paramsOf = array_merge($paramsOf, $turnos);
    }
    if (!empty($excl)) {
        $ph = implode(',', array_fill(0, count($excl), '?'));
        $whereOf[] = "mq.Cod_maquina NOT IN ($ph)";
        $paramsOf = array_merge($paramsOf, $excl);
    }
    $sqlOf = "
        SELECT COUNT(DISTINCT fa.Id_his_of) AS num_ofs
        FROM his_prod hp
        INNER JOIN his_fase    fa ON fa.Id_his_fase = hp.Id_his_fase
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $whereOf);
    $rowsOf = fetchAll('mapex', $sqlOf, $paramsOf);
    $global['num_ofs'] = isset($rowsOf[0]['num_ofs']) ? (int)$rowsOf[0]['num_ofs'] : 0;

    // Desglose OFs por máquina (mismo filtro, agrupado por máquina)
    $sqlOfMaq = "
        SELECT
            mq.Cod_maquina  AS cod_maquina,
            mq.Desc_maquina AS maquina,
            COUNT(DISTINCT fa.Id_his_of) AS num_ofs
        FROM his_prod hp
        INNER JOIN his_fase    fa ON fa.Id_his_fase = hp.Id_his_fase
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $whereOf) . "
        GROUP BY mq.Cod_maquina, mq.Desc_maquina
        HAVING COUNT(DISTINCT fa.Id_his_of) > 0
        ORDER BY num_ofs DESC, mq.Desc_maquina ASC
    ";
    $rowsOfMaq = fetchAll('mapex', $sqlOfMaq, $paramsOf);
    $global['ofs_por_maquina'] = array_map(fn($r) => [
        'cod_maquina' => $r['cod_maquina'],
        'maquina'     => $r['maquina'] ?: $r['cod_maquina'],
        'num_ofs'     => (int)$r['num_ofs'],
    ], $rowsOfMaq);

    // ───── KPI: cantidad de referencias (cfg_producto) distintas ─────
    $sqlRef = "
        SELECT COUNT(DISTINCT o.Id_producto) AS num_refs
        FROM his_prod hp
        INNER JOIN his_fase    fa ON fa.Id_his_fase = hp.Id_his_fase
        INNER JOIN his_of      o  ON o.Id_his_of    = fa.Id_his_of
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $whereOf) . "
          AND o.Id_producto IS NOT NULL";
    $rowsRef = fetchAll('mapex', $sqlRef, $paramsOf);
    $global['num_refs'] = isset($rowsRef[0]['num_refs']) ? (int)$rowsRef[0]['num_refs'] : 0;

    $sqlRefMaq = "
        SELECT
            mq.Cod_maquina  AS cod_maquina,
            mq.Desc_maquina AS maquina,
            COUNT(DISTINCT o.Id_producto) AS num_refs
        FROM his_prod hp
        INNER JOIN his_fase    fa ON fa.Id_his_fase = hp.Id_his_fase
        INNER JOIN his_of      o  ON o.Id_his_of    = fa.Id_his_of
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $whereOf) . "
          AND o.Id_producto IS NOT NULL
        GROUP BY mq.Cod_maquina, mq.Desc_maquina
        HAVING COUNT(DISTINCT o.Id_producto) > 0
        ORDER BY num_refs DESC, mq.Desc_maquina ASC
    ";
    $rowsRefMaq = fetchAll('mapex', $sqlRefMaq, $paramsOf);
    $global['refs_por_maquina'] = array_map(fn($r) => [
        'cod_maquina' => $r['cod_maquina'],
        'maquina'     => $r['maquina'] ?: $r['cod_maquina'],
        'num_refs'    => (int)$r['num_refs'],
    ], $rowsRefMaq);

    // Secciones: garantizamos VARILLAS + TROQUELADOS siempre presentes; OTROS solo si tiene datos
    $secciones = [];
    foreach (['VARILLAS','TROQUELADOS'] as $sec) {
        if (!isset($secAcc[$sec])) {
            $secciones[] = [
                'seccion'        => $sec,
                'disponibilidad' => 0, 'rendimiento' => 0, 'calidad' => 0, 'oee' => 0,
                'M_Teo'          => 0, 'maquinas' => 0,
            ];
            continue;
        }
        $a = $secAcc[$sec];
        $drc = _calcDRC($a['M'],$a['MT'],$a['MOT'],$a['MOKT'],$a['PP'],$a['PC'],$a['PNP']);
        $drc['seccion']  = $sec;
        $drc['maquinas'] = $a['maquinas'];
        $secciones[] = $drc;
    }
    if (isset($secAcc['OTROS']) && $secAcc['OTROS']['maquinas'] > 0) {
        $a = $secAcc['OTROS'];
        $drc = _calcDRC($a['M'],$a['MT'],$a['MOT'],$a['MOKT'],$a['PP'],$a['PC'],$a['PNP']);
        $drc['seccion']  = 'OTROS';
        $drc['maquinas'] = $a['maquinas'];
        $secciones[] = $drc;
    }

    jsonOk([
        'fecha_desde' => $fdesde,
        'fecha_hasta' => $fhasta,
        'turnos'      => $turnos,
        'global'      => $global,
        'secciones'   => $secciones,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
