<?php
/**
 * Funciones de motivos reutilizables por el export.
 * Prefijo _export para evitar colisiones de nombres.
 */

function _exportMotivosParos(string $fdesde, string $fhasta, array $turnos, array $codMaqs, ?string $codMaq): array
{
    if (empty($codMaqs)) return [];
    $where = ["CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?", "cp.Cod_paro <> 11", "hpp.Fecha_fin IS NOT NULL"];
    $params = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    if ($codMaq && in_array($codMaq, $codMaqs, true)) {
        $where[] = "mq.Cod_maquina = ?";
        $params[] = $codMaq;
    } else {
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "mq.Cod_maquina IN ($ph)";
        $params = array_merge($params, $codMaqs);
    }
    $sql = "SELECT cp.Desc_paro AS motivo, SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro cp ON cp.Id_paro = hpp.Id_paro
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY cp.Desc_paro HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0 ORDER BY segundos DESC";
    $rows = fetchAll('mapex', $sql, $params);
    $totSeg = 0; foreach ($rows as $r) $totSeg += (int)$r['segundos'];
    $out = []; $acum = 0;
    foreach ($rows as $r) {
        $seg = (int)$r['segundos']; $pct = $totSeg > 0 ? $seg / $totSeg * 100 : 0; $acum += $pct;
        $out[] = ['motivo'=>$r['motivo']?:'(sin nombre)','horas'=>round($seg/3600,2),'pct'=>round($pct,2),'pct_acum'=>round(min($acum,100),2)];
    }
    return $out;
}

/**
 * Devuelve el cruce (motivo, máquina) → segundos de paro.
 * Se usa para construir tablas pivot en el XLSX:
 *   - Hoja "Motivo × Máquina"  (fila=motivo, col=máquina)
 *   - Hoja "Máquina × Motivo"  (fila=máquina, col=motivo)
 *
 * Retorna:
 *   [
 *     'motivos'    => [string, ...],   // motivos únicos ordenados por total DESC
 *     'maquinas'   => [string, ...],   // máquinas únicas ordenadas alfabéticamente
 *     'matriz'     => [motivo => [maquina => horas, ...], ...],
 *     'tot_motivo' => [motivo  => horas_totales],
 *     'tot_maq'    => [maquina => horas_totales],
 *     'tot_global' => horas_totales_global,
 *   ]
 */
function _exportMotivosParosCruzados(string $fdesde, string $fhasta, array $turnos, array $codMaqs): array
{
    $vacio = ['motivos'=>[], 'maquinas'=>[], 'matriz'=>[],
              'tot_motivo'=>[], 'tot_maq'=>[], 'tot_global'=>0,
              'nombres'=>[]];
    if (empty($codMaqs)) return $vacio;
    $where = ["CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?", "cp.Cod_paro <> 11", "hpp.Fecha_fin IS NOT NULL"];
    $params = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    $ph = implode(',', array_fill(0, count($codMaqs), '?'));
    $where[] = "mq.Cod_maquina IN ($ph)";
    $params = array_merge($params, $codMaqs);

    $sql = "SELECT cp.Desc_paro AS motivo,
                   mq.Cod_maquina AS cod_maquina,
                   mq.Desc_maquina AS desc_maquina,
                   SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
            FROM his_prod_paro hpp
            INNER JOIN cfg_paro cp     ON cp.Id_paro     = hpp.Id_paro
            INNER JOIN his_prod hp     ON hp.Id_his_prod = hpp.Id_his_prod
            INNER JOIN cfg_maquina mq  ON mq.Id_maquina  = hp.Id_maquina
            INNER JOIN cfg_turno   ct  ON ct.Id_turno    = hp.Id_turno
            WHERE " . implode(' AND ', $where) . "
            GROUP BY cp.Desc_paro, mq.Cod_maquina, mq.Desc_maquina
            HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0";

    $rows = fetchAll('mapex', $sql, $params);

    // Inicializamos tot_maq con TODAS las máquinas solicitadas a 0 para que
    // aparezcan como columna/fila aunque no hayan tenido ningún paro en el
    // periodo. Sin esto sólo verías las máquinas que sí registraron paros y
    // la matriz quedaba incompleta respecto al listado de la sección.
    $matriz = []; $totMotivo = [];
    $totMaq = array_fill_keys($codMaqs, 0);
    $totGlobal = 0;
    // Mapa Cod_maquina → nombre visible (Desc_maquina). Lo poblamos primero
    // con lo que devuelva la consulta y al final completamos las máquinas
    // que no tuvieron paros consultando cfg_maquina aparte.
    $nombres = [];
    foreach ($rows as $r) {
        $mot = (string)($r['motivo'] ?: '(sin nombre)');
        $maq = (string)$r['cod_maquina'];
        $h   = round(((int)$r['segundos']) / 3600, 2);
        $nombres[$maq] = trim((string)($r['desc_maquina'] ?? '')) ?: $maq;
        if (!isset($matriz[$mot])) $matriz[$mot] = [];
        $matriz[$mot][$maq] = round(($matriz[$mot][$maq] ?? 0) + $h, 2);
        $totMotivo[$mot] = round(($totMotivo[$mot] ?? 0) + $h, 2);
        $totMaq[$maq]    = round(($totMaq[$maq]    ?? 0) + $h, 2);
        $totGlobal       = round($totGlobal + $h, 2);
    }
    // Si no hubo NINGÚN paro en ninguna máquina, no merece la pena la hoja
    if (empty($totMotivo)) return $vacio;

    // Resolvemos nombre visible para las máquinas que no tuvieron paros
    // (no salieron en la consulta principal) consultando cfg_maquina.
    $faltan = array_values(array_filter($codMaqs, fn($c) => !isset($nombres[$c])));
    if (!empty($faltan)) {
        $phN = implode(',', array_fill(0, count($faltan), '?'));
        $rowsN = fetchAll('mapex',
            "SELECT Cod_maquina, Desc_maquina FROM cfg_maquina WHERE Cod_maquina IN ($phN)",
            $faltan
        );
        foreach ($rowsN as $rN) {
            $c = (string)($rN['Cod_maquina'] ?? '');
            if ($c === '') continue;
            $nombres[$c] = trim((string)($rN['Desc_maquina'] ?? '')) ?: $c;
        }
        // Fallback final: si alguna máquina sigue sin nombre (no existe en
        // cfg_maquina), usamos el propio código como etiqueta.
        foreach ($faltan as $c) {
            if (!isset($nombres[$c])) $nombres[$c] = $c;
        }
    }

    // Orden motivos: por total DESC.
    // Máquinas: ahora ordenamos por el NOMBRE VISIBLE para que aparezcan
    // alfabéticamente como las ve el usuario en pantalla.
    $motivos = array_keys($totMotivo);
    usort($motivos, fn($a, $b) => ($totMotivo[$b] ?? 0) <=> ($totMotivo[$a] ?? 0));
    $maquinas = array_keys($totMaq);
    usort($maquinas, fn($a, $b) =>
        strcasecmp($nombres[$a] ?? $a, $nombres[$b] ?? $b));
    return [
        'motivos'    => $motivos,
        'maquinas'   => $maquinas,
        'matriz'     => $matriz,
        'tot_motivo' => $totMotivo,
        'tot_maq'    => $totMaq,
        'nombres'    => $nombres,
        'tot_global' => $totGlobal,
    ];
}

function _exportMotivosCalidad(string $fdesde, string $fhasta, array $turnos, array $codMaqs, ?string $codMaq): array
{
    if (empty($codMaqs)) return [];
    $where = ["CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?", "hpd.Activo = 1", "df.esNOK = 1"];
    $params = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    if ($codMaq && in_array($codMaq, $codMaqs, true)) {
        $where[] = "mq.Cod_maquina = ?";
        $params[] = $codMaq;
    } else {
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "mq.Cod_maquina IN ($ph)";
        $params = array_merge($params, $codMaqs);
    }
    $sql = "SELECT df.Desc_defecto AS motivo, SUM(hpd.Unidades) AS unidades
        FROM his_prod_defecto hpd
        INNER JOIN cfg_defecto df ON df.Id_defecto = hpd.Id_defecto
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpd.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY df.Desc_defecto HAVING SUM(hpd.Unidades) > 0 ORDER BY unidades DESC";
    $rows = fetchAll('mapex', $sql, $params);
    $totU = 0; foreach ($rows as $r) $totU += (int)$r['unidades'];
    $out = []; $acum = 0;
    foreach ($rows as $r) {
        $u = (int)$r['unidades']; $pct = $totU > 0 ? $u / $totU * 100 : 0; $acum += $pct;
        $out[] = ['motivo'=>$r['motivo']?:'(sin nombre)','unidades'=>$u,'pct'=>round($pct,2),'pct_acum'=>round(min($acum,100),2)];
    }
    return $out;
}

function _exportMotivosRendimiento(string $fdesde, string $fhasta, array $turnos, array $codMaqs, ?string $codMaq, string $fd, string $fh): array
{
    if (empty($codMaqs)) return [];
    $where = ["CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?",
        "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')",
        "oee.Cod_producto IS NOT NULL", "oee.Cod_producto <> '--'"];
    $params = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "oee.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    if ($codMaq && in_array($codMaq, $codMaqs, true)) {
        $where[] = "oee.WorkGroup = ?";
        $params[] = $codMaq;
    } else {
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "oee.WorkGroup IN ($ph)";
        $params = array_merge($params, $codMaqs);
    }
    $sql = "SELECT oee.Cod_producto AS cod_articulo, MAX(oee.Desc_producto) AS motivo,
        SUM(oee.M) - SUM(oee.M_OKNOK_TEO) AS perdida_seg
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS', ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE " . implode(' AND ', $where) . "
        GROUP BY oee.Cod_producto HAVING SUM(oee.M) - SUM(oee.M_OKNOK_TEO) > 0 ORDER BY perdida_seg DESC";
    $rows = fetchAll('mapex', $sql, array_merge([$fd, $fh], $params));
    $totSeg = 0; foreach ($rows as $r) $totSeg += (float)$r['perdida_seg'];
    $out = []; $acum = 0;
    foreach ($rows as $r) {
        $seg = (float)$r['perdida_seg']; $pct = $totSeg > 0 ? $seg / $totSeg * 100 : 0; $acum += $pct;
        $out[] = ['motivo'=>$r['motivo']?:$r['cod_articulo'],'horas'=>round($seg/3600,2),'pct'=>round($pct,2),'pct_acum'=>round(min($acum,100),2)];
    }
    return $out;
}
