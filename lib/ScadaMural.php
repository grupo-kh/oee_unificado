<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Lógica de datos del mural SCADA por máquina (tiempo real desde MAPEX).
 *
 * Réplica del panel scada.png: una tarjeta por máquina operativa con su estado,
 * OF, operario, paro, y métricas D/R/C/OEE de turno y de OF. Toda la información
 * proviene de la conexión a MAPEX:
 *   - cfg_maquina (campos Rt_* de tiempo real): estado, OF, operario, contadores
 *   - his_fase (Unidades_planning): plan total de la OF
 *   - F_his_ct: magnitudes para D/R/C/OEE (misma fórmula que oee_unificado)
 *
 * Diseño: este módulo es autocontenido y NO modifica ningún archivo existente.
 * Incluye su propia copia de _calcDRC (idéntica a la de api/oee_unificado.php)
 * para no acoplarse a ese endpoint.
 */
class ScadaMural
{
    /**
     * Calcula Disponibilidad / Rendimiento / Calidad / OEE a partir de las
     * magnitudes de F_his_ct. Misma fórmula que api/oee_unificado.php::_calcDRC,
     * replicada aquí para mantener el módulo SCADA independiente.
     */
    private static function calcDRC(float $M, float $MOT, float $MOKT, float $PP, float $PC, float $PNP): array
    {
        $d = ($M + $PNP)      > 0 ? $M / ($M + $PNP) * 100                : 0;
        $r = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
        $c = ($MOT + $PC)     > 0 ? $MOKT / ($MOT + $PC) * 100            : 0;
        $oee = $d * $r * $c / 10000;
        return [
            'disponibilidad' => round($d, 2),
            'rendimiento'    => round($r, 2),
            'calidad'        => round($c, 2),
            'oee'            => round($oee, 2),
        ];
    }

    /**
     * Filas crudas de las máquinas que están trabajando ahora mismo.
     * Criterio (verificado contra MAPEX real): activo=1, actividad >= 2
     * (excluye CERRADA y la fila '--') y con OF en curso.
     */
    public static function maquinasOperativas(): array
    {
        $sql = "
            SELECT cm.Cod_maquina, cm.Desc_maquina,
                   cm.Rt_Id_actividad, cm.Rt_Desc_actividad,
                   cm.Rt_Id_paro, cm.Rt_Desc_paro, cm.Rt_Hora_inicio_paro,
                   cm.Rt_Cod_of, cm.Rt_Desc_producto, cm.Rt_Desc_operario,
                   cm.Rt_Desc_turno, cm.Rt_Id_turno,
                   cm.Rt_Fecha_ini, cm.Rt_Rendimientonominal1,
                   cm.Rt_Unidades_ok_turno, cm.Rt_Unidades_nok_turno, cm.Rt_Unidades_repro_turno,
                   cm.Rt_Unidades_ok_of, cm.Rt_Unidades_nok_of, cm.Rt_Unidades_repro_of,
                   cm.Rt_Seg_produccion_turno, cm.Rt_Seg_preparacion, cm.Rt_Seg_paro_turno,
                   fa.Unidades_planning AS plan_of,
                   LTRIM(RTRIM(prod.Cod_producto)) AS cod_articulo,
                   GETDATE() AS ahora
            FROM cfg_maquina cm
            LEFT JOIN his_fase fa ON fa.Id_his_fase = cm.Rt_Id_his_fase
            LEFT JOIN his_of      o    ON o.Cod_of      = cm.Rt_Cod_of
            LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
            WHERE cm.activo = 1
              AND cm.Rt_Id_actividad >= 2
              AND cm.Rt_Cod_of NOT IN ('', '--')
            ORDER BY cm.Cod_maquina";
        return fetchAll('mapex', $sql);
    }

    /**
     * KPI D/R/C/OEE de TURNO para todas las máquinas en UNA consulta.
     * F_his_ct del día de hoy agrupado por WorkGroup. Devuelve
     * [cod_maquina => calcDRC(...)]. (Antes se hacía 1 consulta por máquina, lo
     * que disparaba 16+ llamadas a F_his_ct y colgaba el endpoint.)
     */
    public static function kpiTurnoTodas(): array
    {
        $hoy = date('Y-m-d');
        $sql = "
            SELECT oee.WorkGroup AS cod_maquina,
                   SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO,
                   SUM(oee.M_OK_TEO) AS M_OK_TEO, SUM(oee.PPERF) AS PPERF,
                   SUM(oee.PCALIDAD) AS PCALIDAD, SUM(oee.PNP) AS PNP
            FROM F_his_ct('WORKCENTER','DAY','TURNOS, WO, PRODUCTOS',
                          ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
            GROUP BY oee.WorkGroup";
        return self::indexarDRC(fetchAll('mapex', $sql, [$hoy, $hoy]), 'cod_maquina');
    }

    /**
     * KPI D/R/C/OEE de OF para las OFs activas dadas, en UNA consulta.
     * F_his_ct sobre una ventana ajustada (15 días) y FILTRADO por las OFs en
     * curso, para no procesar toda la planta × 60 días (eso tardaba ~3 s y
     * colgaba el endpoint). Devuelve ["{cod_maquina}|{cod_of}" => calcDRC(...)].
     *
     * @param array $ofs lista de códigos de OF activos (Rt_Cod_of)
     */
    public static function kpiOfTodas(array $ofs): array
    {
        $ofs = array_values(array_unique(array_filter(array_map('trim', $ofs))));
        if (!$ofs) return [];
        $ini = date('Y-m-d', strtotime('-15 days'));
        $fin = date('Y-m-d');
        $ph  = implode(',', array_fill(0, count($ofs), '?'));
        $sql = "
            SELECT oee.WorkGroup AS cod_maquina, oee.Cod_of AS cod_of,
                   SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO,
                   SUM(oee.M_OK_TEO) AS M_OK_TEO, SUM(oee.PPERF) AS PPERF,
                   SUM(oee.PCALIDAD) AS PCALIDAD, SUM(oee.PNP) AS PNP
            FROM F_his_ct('WORKCENTER','DAY','TURNOS, WO, PRODUCTOS',
                          ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
            WHERE oee.Cod_of IN ($ph)
            GROUP BY oee.WorkGroup, oee.Cod_of";
        $out = [];
        foreach (fetchAll('mapex', $sql, array_merge([$ini, $fin], $ofs)) as $r) {
            $k = trim((string)$r['cod_maquina']) . '|' . trim((string)$r['cod_of']);
            $out[$k] = self::calcDRC(
                (float)$r['M'], (float)$r['M_OKNOK_TEO'], (float)$r['M_OK_TEO'],
                (float)$r['PPERF'], (float)$r['PCALIDAD'], (float)$r['PNP']);
        }
        return $out;
    }

    /** Convierte filas con magnitudes F_his_ct en [clave => calcDRC(...)]. */
    private static function indexarDRC(array $filas, string $claveCol): array
    {
        $out = [];
        foreach ($filas as $r) {
            $out[trim((string)$r[$claveCol])] = self::calcDRC(
                (float)$r['M'], (float)$r['M_OKNOK_TEO'], (float)$r['M_OK_TEO'],
                (float)$r['PPERF'], (float)$r['PCALIDAD'], (float)$r['PNP']);
        }
        return $out;
    }

    /** Construye el array completo del mural (una llamada = todas las máquinas). */
    public static function mural(): array
    {
        $filas = self::maquinasOperativas();
        $ahora = $filas[0]['ahora'] ?? date('Y-m-d H:i:s');

        // KPI de todas las máquinas en 2 consultas (turno + OF), no 2 por máquina.
        $ofsActivas = array_map(fn($r) => trim((string)$r['Rt_Cod_of']), $filas);
        $kpiTurno = self::kpiTurnoTodas();          // [cod_maquina => drc]
        $kpiOf    = self::kpiOfTodas($ofsActivas);  // ["cod|of" => drc]
        $cero = self::calcDRC(0, 0, 0, 0, 0, 0);

        $out = [];
        foreach ($filas as $r) {
            $cod = trim((string)$r['Cod_maquina']);
            $of  = trim((string)$r['Rt_Cod_of']);
            $kpi = [
                'turno' => $kpiTurno[$cod] ?? $cero,
                'of'    => $kpiOf["$cod|$of"] ?? $cero,
            ];

            $segProd = (int)$r['Rt_Seg_produccion_turno'];
            $segPrep = (int)$r['Rt_Seg_preparacion'];
            $segParo = (int)$r['Rt_Seg_paro_turno'];
            $segTot  = max(1, $segProd + $segPrep + $segParo);

            $okOf   = (int)$r['Rt_Unidades_ok_of'];
            $planOf = (int)($r['plan_of'] ?? 0);
            $uh     = (float)$r['Rt_Rendimientonominal1'];

            $out[] = [
                'cod_maquina'  => $cod,
                'desc_maquina' => trim((string)$r['Desc_maquina']),
                'estado'       => trim((string)$r['Rt_Desc_actividad']),
                'id_actividad' => (int)$r['Rt_Id_actividad'],
                'of'           => $of,
                // Referencia mostrada = código de artículo de Sage (cfg_producto.Cod_producto),
                // obtenido vía la OF. Fallback a la descripción técnica si no hay código.
                'producto'     => trim((string)($r['cod_articulo'] ?? '')) ?: trim((string)$r['Rt_Desc_producto']),
                'operario'     => trim((string)$r['Rt_Desc_operario']),
                'turno'        => trim((string)$r['Rt_Desc_turno']),
                'paro' => [
                    'motivo' => ((int)$r['Rt_Id_paro'] > 0) ? trim((string)$r['Rt_Desc_paro']) : null,
                    'seg'    => self::segDesde($r['Rt_Hora_inicio_paro'], $ahora),
                ],
                'turno_kpi' => [
                    'oee'  => $kpi['turno']['oee'],          'disp' => $kpi['turno']['disponibilidad'],
                    'rend' => $kpi['turno']['rendimiento'],  'cal'  => $kpi['turno']['calidad'],
                    'ok'   => (int)$r['Rt_Unidades_ok_turno'],
                    'nok'  => (int)$r['Rt_Unidades_nok_turno'],
                    'rwk'  => (int)$r['Rt_Unidades_repro_turno'],
                    'uh'   => round($uh),
                    'rep_prod' => (int)round($segProd * 100 / $segTot),
                    'rep_prep' => (int)round($segPrep * 100 / $segTot),
                    'rep_paro' => (int)round($segParo * 100 / $segTot),
                ],
                'of_kpi' => [
                    'oee'  => $kpi['of']['oee'],          'disp' => $kpi['of']['disponibilidad'],
                    'rend' => $kpi['of']['rendimiento'],  'cal'  => $kpi['of']['calidad'],
                    'plan' => $planOf, 'ok' => $okOf,
                    'nok'  => (int)$r['Rt_Unidades_nok_of'],
                    'rwk'  => (int)$r['Rt_Unidades_repro_of'],
                    'progreso_pct' => $planOf > 0 ? (int)round($okOf * 100 / $planOf) : 0,
                    'inicio'   => self::fechaCorta($r['Rt_Fecha_ini']),
                    'fin_est'  => self::finEstimado($okOf, $planOf, $uh, $r['Rt_Fecha_ini']),
                    'restante' => self::restante($okOf, $planOf, $uh),
                ],
            ];
        }
        return ['ahora' => $ahora, 'maquinas' => $out];
    }

    /** Segundos entre una fecha MAPEX y "ahora"; 0 si nula/inválida. */
    private static function segDesde($fecha, string $ahora): int
    {
        if (!$fecha) return 0;
        $t = strtotime((string)$fecha);
        if ($t === false) return 0;
        return max(0, strtotime($ahora) - $t);
    }

    /** "dd/mm HH:MM" o '—'. */
    private static function fechaCorta($fecha): string
    {
        if (!$fecha) return '—';
        $t = strtotime((string)$fecha);
        return $t ? date('d/m H:i', $t) : '—';
    }

    /** Fin estimado = inicio + (plan/uh) h. "Completada" si ok>=plan. '—' si no calculable. */
    private static function finEstimado(int $ok, int $plan, float $uh, $inicio): string
    {
        if ($plan > 0 && $ok >= $plan) return 'Completada';
        if ($uh <= 0 || $plan <= 0 || !$inicio) return '—';
        $tIni = strtotime((string)$inicio);
        if ($tIni === false) return '—';
        $horas = $plan / $uh;
        return date('d/m H:i', $tIni + (int)round($horas * 3600));
    }

    /** Tiempo restante "Xd Yh" estimado por cadencia, o '—'/'Completada'. */
    private static function restante(int $ok, int $plan, float $uh): string
    {
        if ($plan > 0 && $ok >= $plan) return 'Completada';
        if ($uh <= 0 || $plan <= 0) return '—';
        $segRest = (int)round(($plan - $ok) / $uh * 3600);
        if ($segRest <= 0) return 'Completada';
        $dias  = intdiv($segRest, 86400);
        $horas = intdiv($segRest % 86400, 3600);
        return $dias > 0 ? "{$dias}d {$horas}h" : "{$horas}h";
    }
}
