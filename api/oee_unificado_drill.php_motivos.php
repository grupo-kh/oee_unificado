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
