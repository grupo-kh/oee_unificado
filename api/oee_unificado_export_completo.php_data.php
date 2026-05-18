<?php
/**
 * Datos compartidos para el "informe completo" (XLSX + PDF).
 *
 * Para una sección (VARILLAS|TROQUELADOS) + rango + turnos + exclusiones,
 * devuelve para cada métrica (Disponibilidad / Rendimiento / Calidad)
 * un agregado por motivo × máquina × hora del día (00-23).
 *
 * Estructura devuelta: [motivo => [cod_maquina => [hora => valor]]]
 *   - Disponibilidad y Rendimiento → valor en HORAS (decimal)
 *   - Calidad                       → valor en UNIDADES (entero)
 */

if (!function_exists('_completoSeccionDeDesc')) {
    function _completoSeccionDeDesc(?string $desc): ?string {
        if ($desc === null) return null;
        return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
    }
}

if (!function_exists('_completoMaqsSeccion')) {
    /**
     * Lista de máquinas con actividad productiva en la sección, post-exclusión.
     * @return array<int, array{cod_maquina:string, maquina:string}> ordenadas por nombre.
     */
    function _completoMaqsSeccion(string $fdesde, string $fhasta, array $turnos, array $excl, string $seccion): array {
        $where = [
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
        $sql = "
            SELECT oee.WorkGroup AS cod_maquina, mq.Desc_maquina AS maquina
            FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                          ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
            LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
            WHERE $whereSQL
            GROUP BY oee.WorkGroup, mq.Desc_maquina
            HAVING SUM(oee.M) + SUM(oee.PNP) > 0
        ";
        $rows = fetchAll('mapex', $sql, array_merge([$fdesde, $fhasta], $params));
        $out = [];
        foreach ($rows as $r) {
            if (_completoSeccionDeDesc($r['maquina']) === $seccion) {
                $out[] = [
                    'cod_maquina' => $r['cod_maquina'],
                    'maquina'     => $r['maquina'] ?: $r['cod_maquina'],
                ];
            }
        }
        usort($out, fn($a, $b) => strcasecmp($a['maquina'], $b['maquina']));
        return $out;
    }
}

if (!function_exists('_completoRefsDisponibilidad')) {
    /**
     * Lista de referencias (Cod_producto) que generaron paros en el rango/turnos
     * para las máquinas dadas. Cada fila simula la firma de "máquinas" para que
     * los renderers de XLSX/PDF puedan reusarse sin cambios estructurales:
     * cod_maquina ← cod_referencia, maquina ← descripción.
     *
     * @return array<int, array{cod_maquina:string, maquina:string}>
     */
    function _completoRefsDisponibilidad(string $fdesde, string $fhasta, array $turnos, array $codMaqs): array {
        if (empty($codMaqs)) return [];

        $where = [
            "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
            "cp.Cod_paro <> 11",
            "hpp.Fecha_fin IS NOT NULL",
            "prod.Cod_producto IS NOT NULL",
            "prod.Cod_producto <> '--'",
        ];
        $params = [$fdesde, $fhasta];
        if (!empty($turnos)) {
            $ph = implode(',', array_fill(0, count($turnos), '?'));
            $where[] = "ct.Cod_turno IN ($ph)";
            $params = array_merge($params, $turnos);
        }
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "mq.Cod_maquina IN ($ph)";
        $params = array_merge($params, $codMaqs);

        $sql = "
            SELECT
                prod.Cod_producto       AS cod_referencia,
                MAX(prod.Desc_producto) AS referencia
            FROM his_prod_paro hpp
            INNER JOIN cfg_paro     cp   ON cp.Id_paro      = hpp.Id_paro
            INNER JOIN his_prod     hp   ON hp.Id_his_prod  = hpp.Id_his_prod
            INNER JOIN cfg_maquina  mq   ON mq.Id_maquina   = hp.Id_maquina
            INNER JOIN cfg_turno    ct   ON ct.Id_turno     = hp.Id_turno
            LEFT  JOIN his_fase     fa   ON fa.Id_his_fase  = hp.Id_his_fase
            LEFT  JOIN his_of       o    ON o.Id_his_of     = fa.Id_his_of
            LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
            WHERE " . implode(' AND ', $where) . "
            GROUP BY prod.Cod_producto
            ORDER BY prod.Cod_producto
        ";
        $rows = fetchAll('mapex', $sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $cod = (string) $r['cod_referencia'];
            $des = (string) ($r['referencia'] ?: $cod);
            $out[] = ['cod_maquina' => $cod, 'maquina' => $des];
        }
        return $out;
    }
}

if (!function_exists('_completoDisponibilidadPorReferencia')) {
    /**
     * Versión "por referencia" de _completoDisponibilidad: agrupa paros por
     * motivo × día × Cod_producto × hora. Reusa la clave cod_maquina del dataset
     * resultante (= cod_referencia) para que los renderers no necesiten cambios.
     *
     * @return array{data: array<string, array<string, array<string, array<int, float>>>>, hourly: bool}
     */
    function _completoDisponibilidadPorReferencia(string $fdesde, string $fhasta, array $turnos, array $codMaqs): array {
        if (empty($codMaqs)) return ['data' => [], 'hourly' => true];

        $where  = [
            "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
            "cp.Cod_paro <> 11",
            "hpp.Fecha_fin IS NOT NULL",
            "prod.Cod_producto IS NOT NULL",
            "prod.Cod_producto <> '--'",
        ];
        $params = [$fdesde, $fhasta];
        if (!empty($turnos)) {
            $ph = implode(',', array_fill(0, count($turnos), '?'));
            $where[] = "ct.Cod_turno IN ($ph)";
            $params = array_merge($params, $turnos);
        }
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "mq.Cod_maquina IN ($ph)";
        $params = array_merge($params, $codMaqs);

        $sql = "
            WITH paros AS (
                SELECT prod.Cod_producto AS cod_referencia, cp.Desc_paro AS motivo,
                       hp.Dia_productivo AS dia,
                       hpp.Fecha_ini, hpp.Fecha_fin
                FROM his_prod_paro hpp
                INNER JOIN cfg_paro     cp   ON cp.Id_paro      = hpp.Id_paro
                INNER JOIN his_prod     hp   ON hp.Id_his_prod  = hpp.Id_his_prod
                INNER JOIN cfg_maquina  mq   ON mq.Id_maquina   = hp.Id_maquina
                INNER JOIN cfg_turno    ct   ON ct.Id_turno     = hp.Id_turno
                LEFT  JOIN his_fase     fa   ON fa.Id_his_fase  = hp.Id_his_fase
                LEFT  JOIN his_of       o    ON o.Id_his_of     = fa.Id_his_of
                LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
                WHERE " . implode(' AND ', $where) . "
            ),
            hour_slots AS (
                SELECT p.cod_referencia, p.motivo, p.dia, p.Fecha_ini, p.Fecha_fin,
                       DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) AS slot_ini
                FROM paros p
                CROSS JOIN (VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),
                                   (12),(13),(14),(15),(16),(17),(18),(19),(20),(21),(22),(23)) n(h)
                WHERE DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) < p.Fecha_fin
            )
            SELECT
                motivo, cod_referencia,
                CAST(dia AS DATE) AS dia,
                DATEPART(HOUR, slot_ini) AS hora,
                SUM(DATEDIFF(SECOND,
                    CASE WHEN Fecha_ini > slot_ini                   THEN Fecha_ini ELSE slot_ini END,
                    CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini) THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
                )) AS segundos
            FROM hour_slots
            GROUP BY motivo, cod_referencia, CAST(dia AS DATE), DATEPART(HOUR, slot_ini)
            HAVING SUM(DATEDIFF(SECOND,
                CASE WHEN Fecha_ini > slot_ini                   THEN Fecha_ini ELSE slot_ini END,
                CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini) THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
            )) > 0
        ";
        $rows = fetchAll('mapex', $sql, $params);

        $out = [];
        foreach ($rows as $r) {
            $m   = $r['motivo'] ?: '(sin nombre)';
            $cod = (string) $r['cod_referencia'];
            $dia = substr((string)$r['dia'], 0, 10);
            $h   = (int) $r['hora'];
            $v   = round(((int)$r['segundos']) / 3600, 2);
            if ($v <= 0) continue;
            $out[$m][$dia][$cod][$h] = ($out[$m][$dia][$cod][$h] ?? 0) + $v;
        }
        return ['data' => $out, 'hourly' => true];
    }
}

if (!function_exists('_completoDisponibilidad')) {
    /**
     * Paros (Disponibilidad) por motivo × día (Dia_productivo) × máquina × hora-del-día.
     * Valores en horas decimales.
     *
     * @return array{data: array<string, array<string, array<string, array<int, float>>>>, hourly: bool}
     */
    function _completoDisponibilidad(string $fdesde, string $fhasta, array $turnos, array $codMaqs): array {
        if (empty($codMaqs)) return ['data' => [], 'hourly' => true];

        $where  = [
            "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
            "cp.Cod_paro <> 11",
            "hpp.Fecha_fin IS NOT NULL",
        ];
        $params = [$fdesde, $fhasta];
        if (!empty($turnos)) {
            $ph = implode(',', array_fill(0, count($turnos), '?'));
            $where[] = "ct.Cod_turno IN ($ph)";
            $params = array_merge($params, $turnos);
        }
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "mq.Cod_maquina IN ($ph)";
        $params = array_merge($params, $codMaqs);

        $sql = "
            WITH paros AS (
                SELECT mq.Cod_maquina AS cod_maquina, cp.Desc_paro AS motivo,
                       hp.Dia_productivo AS dia,
                       hpp.Fecha_ini, hpp.Fecha_fin
                FROM his_prod_paro hpp
                INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
                INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
                INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
                INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
                WHERE " . implode(' AND ', $where) . "
            ),
            hour_slots AS (
                SELECT p.cod_maquina, p.motivo, p.dia, p.Fecha_ini, p.Fecha_fin,
                       DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) AS slot_ini
                FROM paros p
                CROSS JOIN (VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),
                                   (12),(13),(14),(15),(16),(17),(18),(19),(20),(21),(22),(23)) n(h)
                WHERE DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) < p.Fecha_fin
            )
            SELECT
                motivo, cod_maquina,
                CAST(dia AS DATE) AS dia,
                DATEPART(HOUR, slot_ini) AS hora,
                SUM(DATEDIFF(SECOND,
                    CASE WHEN Fecha_ini > slot_ini                   THEN Fecha_ini ELSE slot_ini END,
                    CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini) THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
                )) AS segundos
            FROM hour_slots
            GROUP BY motivo, cod_maquina, CAST(dia AS DATE), DATEPART(HOUR, slot_ini)
            HAVING SUM(DATEDIFF(SECOND,
                CASE WHEN Fecha_ini > slot_ini                   THEN Fecha_ini ELSE slot_ini END,
                CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini) THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
            )) > 0
        ";
        $rows = fetchAll('mapex', $sql, $params);

        $out = [];
        foreach ($rows as $r) {
            $m   = $r['motivo'] ?: '(sin nombre)';
            $cod = (string)$r['cod_maquina'];
            $dia = substr((string)$r['dia'], 0, 10);
            $h   = (int)$r['hora'];
            $v   = round(((int)$r['segundos']) / 3600, 2);
            if ($v <= 0) continue;
            $out[$m][$dia][$cod][$h] = ($out[$m][$dia][$cod][$h] ?? 0) + $v;
        }
        return ['data' => $out, 'hourly' => true];
    }
}

if (!function_exists('_completoCalidad')) {
    /**
     * Rechazos (Calidad) por defecto × día (Dia_productivo) × máquina × hora-del-día.
     * Valores en unidades.
     *
     * @return array{data: array<string, array<string, array<string, array<int, int>>>>, hourly: bool}
     */
    function _completoCalidad(string $fdesde, string $fhasta, array $turnos, array $codMaqs): array {
        if (empty($codMaqs)) return ['data' => [], 'hourly' => true];

        $where  = [
            "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
            "hpd.Activo = 1",
            "df.esNOK = 1",
        ];
        $params = [$fdesde, $fhasta];
        if (!empty($turnos)) {
            $ph = implode(',', array_fill(0, count($turnos), '?'));
            $where[] = "ct.Cod_turno IN ($ph)";
            $params = array_merge($params, $turnos);
        }
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "mq.Cod_maquina IN ($ph)";
        $params = array_merge($params, $codMaqs);

        $sql = "
            SELECT
                df.Desc_defecto AS motivo,
                mq.Cod_maquina  AS cod_maquina,
                CAST(hp.Dia_productivo AS DATE) AS dia,
                DATEPART(HOUR, hp.Fecha_ini) AS hora,
                SUM(hpd.Unidades) AS unidades
            FROM his_prod_defecto hpd
            INNER JOIN cfg_defecto df ON df.Id_defecto    = hpd.Id_defecto
            INNER JOIN his_prod    hp ON hp.Id_his_prod   = hpd.Id_his_prod
            INNER JOIN cfg_maquina mq ON mq.Id_maquina    = hp.Id_maquina
            INNER JOIN cfg_turno   ct ON ct.Id_turno      = hp.Id_turno
            WHERE " . implode(' AND ', $where) . "
            GROUP BY df.Desc_defecto, mq.Cod_maquina, CAST(hp.Dia_productivo AS DATE), DATEPART(HOUR, hp.Fecha_ini)
            HAVING SUM(hpd.Unidades) > 0
        ";
        $rows = fetchAll('mapex', $sql, $params);

        $out = [];
        foreach ($rows as $r) {
            $m   = $r['motivo'] ?: '(sin nombre)';
            $cod = (string)$r['cod_maquina'];
            $dia = substr((string)$r['dia'], 0, 10);
            $h   = (int)$r['hora'];
            $v   = (int)$r['unidades'];
            if ($v <= 0) continue;
            $out[$m][$dia][$cod][$h] = ($out[$m][$dia][$cod][$h] ?? 0) + $v;
        }
        return ['data' => $out, 'hourly' => true];
    }
}

if (!function_exists('_completoRendimiento')) {
    /**
     * Pérdida de rendimiento por artículo × día × máquina × hora-del-día.
     * Intenta F_his_ct('HOUR'); si no está disponible cae a 'DAY' (sin hora,
     * marcando hora=-1 como sentinela "totales diarios sin desglose horario").
     *
     * @return array{data: array<string, array<string, array<string, array<int, float>>>>, hourly: bool}
     */
    function _completoRendimiento(string $fdesde, string $fhasta, array $turnos, array $codMaqs): array {
        if (empty($codMaqs)) return ['data' => [], 'hourly' => true];

        $where = [
            "CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?",
            "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')",
            "oee.Cod_producto IS NOT NULL",
            "oee.Cod_producto <> '--'",
        ];
        $params = [$fdesde, $fhasta];
        if (!empty($turnos)) {
            $ph = implode(',', array_fill(0, count($turnos), '?'));
            $where[] = "oee.Cod_turno IN ($ph)";
            $params = array_merge($params, $turnos);
        }
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "oee.WorkGroup IN ($ph)";
        $params = array_merge($params, $codMaqs);
        $whereSQL = implode(' AND ', $where);

        // Intento 1: HOUR (desglose horario completo)
        $sqlHour = "
            SELECT
                oee.Cod_producto AS cod_articulo,
                MAX(oee.Desc_producto) AS motivo,
                oee.WorkGroup AS cod_maquina,
                CAST(oee.TimePeriod AS DATE) AS dia,
                DATEPART(HOUR, oee.TimePeriod) AS hora,
                SUM(oee.M) - SUM(oee.M_OKNOK_TEO) AS perdida_seg
            FROM F_his_ct('WORKCENTER','HOUR','TURNOS, PRODUCTOS',
                          ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
            WHERE $whereSQL
            GROUP BY oee.Cod_producto, oee.WorkGroup, CAST(oee.TimePeriod AS DATE), DATEPART(HOUR, oee.TimePeriod)
            HAVING SUM(oee.M) - SUM(oee.M_OKNOK_TEO) > 0
        ";

        $hourly = true;
        $rows = null;
        try {
            $rows = fetchAll('mapex', $sqlHour, array_merge([$fdesde, $fhasta], $params));
        } catch (Exception $e) {
            error_log("oee_unificado_export_completo: F_his_ct('HOUR') no disponible, fallback a DAY: " . $e->getMessage());
            $rows = null;
        }

        // Fallback DAY si HOUR no funcionó (o no devolvió filas)
        if ($rows === null || empty($rows)) {
            $hourly = false;
            $sqlDay = "
                SELECT
                    oee.Cod_producto AS cod_articulo,
                    MAX(oee.Desc_producto) AS motivo,
                    oee.WorkGroup AS cod_maquina,
                    CAST(oee.TimePeriod AS DATE) AS dia,
                    SUM(oee.M) - SUM(oee.M_OKNOK_TEO) AS perdida_seg
                FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                              ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
                WHERE $whereSQL
                GROUP BY oee.Cod_producto, oee.WorkGroup, CAST(oee.TimePeriod AS DATE)
                HAVING SUM(oee.M) - SUM(oee.M_OKNOK_TEO) > 0
            ";
            try {
                $rows = fetchAll('mapex', $sqlDay, array_merge([$fdesde, $fhasta], $params));
            } catch (Exception $e) {
                error_log("oee_unificado_export_completo: F_his_ct('DAY') tampoco disponible: " . $e->getMessage());
                return ['data' => [], 'hourly' => false];
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $m   = $r['motivo'] ?: ($r['cod_articulo'] ?: '(sin artículo)');
            $cod = (string)$r['cod_maquina'];
            $dia = substr((string)$r['dia'], 0, 10);
            $h   = $hourly ? (int)$r['hora'] : -1;
            $v   = round(((float)$r['perdida_seg']) / 3600, 2);
            if ($v <= 0) continue;
            $out[$m][$dia][$cod][$h] = ($out[$m][$dia][$cod][$h] ?? 0) + $v;
        }
        return ['data' => $out, 'hourly' => $hourly];
    }
}

if (!function_exists('_completoExclLabel')) {
    /** Genera "Nombre1, Nombre2, …" a partir de cod_maquina[] excluidos. */
    function _completoExclLabel(array $excl): string {
        if (empty($excl)) return '';
        $ph = implode(',', array_fill(0, count($excl), '?'));
        $rows = fetchAll('mapex',
            "SELECT Cod_maquina, Desc_maquina FROM cfg_maquina WHERE Cod_maquina IN ($ph)",
            $excl
        );
        $map = [];
        foreach ($rows as $r) $map[$r['Cod_maquina']] = $r['Desc_maquina'] ?: $r['Cod_maquina'];
        return implode(', ', array_map(fn($c) => $map[$c] ?? $c, $excl));
    }
}
