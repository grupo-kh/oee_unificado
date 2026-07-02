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
              AND cm.Cod_maquina <> '--'
            ORDER BY cm.Cod_maquina";
        return fetchAll('mapex', $sql);
    }

    /**
     * Turno en curso según la hora del servidor MAPEX y las franjas predefinidas
     * de la planta KH (M 06:00-14:15, T 14:15-22:30, N 22:30-06:00). Devuelve
     * ['cod'=>'M|T|N', 'dia'=>'YYYY-MM-DD'] donde 'dia' es el día productivo del
     * turno (para la noche antes de 06:00 el día productivo es el de hoy, aunque
     * la franja arrancara ayer 22:30 — F_his_ct usa Dia_productivo y lo resuelve).
     */
    public static function turnoActual(): array
    {
        $now  = fetchAll('mapex', "SELECT CONVERT(varchar, GETDATE(), 120) AS ahora")[0]['ahora'];
        $hhmm = substr($now, 11, 5);
        $hoy  = substr($now, 0, 10);
        if ($hhmm >= '06:00' && $hhmm < '14:15') return ['cod' => 'M', 'dia' => $hoy];
        if ($hhmm >= '14:15' && $hhmm < '22:30') return ['cod' => 'T', 'dia' => $hoy];
        // Noche (22:30-06:00): pertenece al día productivo de hoy. Si es de
        // madrugada (< 06:00) también es la noche del día productivo de hoy.
        return ['cod' => 'N', 'dia' => $hoy];
    }

    /**
     * KPI D/R/C/OEE del TURNO EN CURSO para todas las máquinas en UNA consulta.
     * Usa F_his_ct con granularidad 'TURNO' filtrada por el turno actual (franjas
     * predefinidas), para ser coherente con oee_unificado y con MAPEX (antes se
     * usaba 'DAY' y mostraba el día completo, no el turno). Devuelve
     * [cod_maquina => calcDRC(...)].
     */
    public static function kpiTurnoTodas(): array
    {
        $t   = self::turnoActual();
        $dia = $t['dia'];
        $sql = "
            SELECT oee.WorkGroup AS cod_maquina,
                   SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO,
                   SUM(oee.M_OK_TEO) AS M_OK_TEO, SUM(oee.PPERF) AS PPERF,
                   SUM(oee.PCALIDAD) AS PCALIDAD, SUM(oee.PNP) AS PNP
            FROM F_his_ct('WORKCENTER','TURNO','TURNOS, WO, PRODUCTOS',
                          ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
            WHERE oee.Cod_turno = ?
            GROUP BY oee.WorkGroup";
        return self::indexarDRC(fetchAll('mapex', $sql, [$dia, $dia, $t['cod']]), 'cod_maquina');
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

    /**
     * Histórico de turnos anteriores de UNA máquina en los últimos $dias días.
     * Una fila por (día productivo, turno) con D/R/C/OEE (mismo cálculo que el
     * KPI de turno, F_his_ct por TURNO) más unidades OK/NOK. Ordenado de más
     * reciente a más antiguo. Excluye el turno en curso incompleto no se filtra:
     * se muestran todos los turnos con producción en el rango.
     *
     * @param string $cod  Cod_maquina
     * @param int    $dias 1 | 3 | 7 | 15
     * @return array lista de ['dia','turno','disponibilidad','rendimiento','calidad','oee','ok','nok']
     */
    public static function turnosAnteriores(string $cod, int $dias): array
    {
        $dias = in_array($dias, [1, 3, 7, 15], true) ? $dias : 7;
        $t    = self::turnoActual();
        $fin  = $t['dia'];                                            // hoy (día productivo)
        $ini  = date('Y-m-d', strtotime($fin . ' -' . ($dias - 1) . ' days'));
        // Granularidad DAY: F_his_ct devuelve una fila por (día, turno) con
        // TimePeriod = fecha del día productivo (la noche ya se asigna a su día).
        $sql  = "
            SELECT oee.TimePeriod AS dia,
                   oee.Cod_turno AS turno,
                   SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO,
                   SUM(oee.M_OK_TEO) AS M_OK_TEO, SUM(oee.PPERF) AS PPERF,
                   SUM(oee.PCALIDAD) AS PCALIDAD, SUM(oee.PNP) AS PNP,
                   SUM(oee.Unidades_OK) AS ok, SUM(oee.Unidades_NOK) AS nok
            FROM F_his_ct('WORKCENTER','DAY','TURNOS, WO, PRODUCTOS',
                          ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
            WHERE oee.WorkGroup = ?
            GROUP BY oee.TimePeriod, oee.Cod_turno
            HAVING SUM(oee.M) + SUM(oee.PNP) > 0
            ORDER BY oee.TimePeriod DESC, oee.Cod_turno DESC";
        $filas = fetchAll('mapex', $sql, [$ini, $fin, $cod]);
        $out   = [];
        foreach ($filas as $r) {
            $drc = self::calcDRC((float)$r['M'], (float)$r['M_OKNOK_TEO'], (float)$r['M_OK_TEO'],
                (float)$r['PPERF'], (float)$r['PCALIDAD'], (float)$r['PNP']);
            $out[] = [
                'dia'            => $r['dia'],
                'turno'          => trim((string)$r['turno']),
                'disponibilidad' => $drc['disponibilidad'],
                'rendimiento'    => $drc['rendimiento'],
                'calidad'        => $drc['calidad'],
                'oee'            => $drc['oee'],
                'ok'             => (int)$r['ok'],
                'nok'            => (int)$r['nok'],
            ];
        }
        return ['cod' => $cod, 'dias' => $dias, 'desde' => $ini, 'hasta' => $fin, 'turnos' => $out];
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

        // Categoría de paro (nivel Matriz2) desde PostgreSQL, clave por descripción
        // de paro en mayúsculas. Si PostgreSQL falla, categorías vacías (no rompe).
        $catParo = [];
        try {
            require_once __DIR__ . '/Db.php';
            foreach (Db::pgFetchAll('SELECT cod_paro, tipo_paro_1 FROM cfg_paro_categoria') as $c) {
                $catParo[strtoupper(trim((string)$c['cod_paro']))] = (string)$c['tipo_paro_1'];
            }
        } catch (\Throwable $e) { /* sin categorías */ }

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

            // Clasificación de estado para contadores/filtros del menú.
            $idAct  = (int)$r['Rt_Id_actividad'];
            $idParo = (int)$r['Rt_Id_paro'];
            if     ($idAct === 1)                    $estadoCat = 'cerrada';
            elseif ($idParo > 0)                     $estadoCat = 'parada';
            elseif (in_array($idAct, [2, 20], true)) $estadoCat = 'produccion';
            elseif (in_array($idAct, [3, 5],  true)) $estadoCat = 'preparacion';
            else                                     $estadoCat = 'otra';

            $motivoParo    = trim((string)$r['Rt_Desc_paro']);
            $paroCategoria = ($idParo > 0) ? ($catParo[strtoupper($motivoParo)] ?? '') : '';
            $segParoActual = self::segDesde($r['Rt_Hora_inicio_paro'], $ahora);

            $out[] = [
                'cod_maquina'  => $cod,
                'desc_maquina' => trim((string)$r['Desc_maquina']),
                'estado'       => trim((string)$r['Rt_Desc_actividad']),
                'id_actividad' => (int)$r['Rt_Id_actividad'],
                'estado_cat'    => $estadoCat,
                'paro_categoria'=> $paroCategoria,
                'seg_paro'      => $segParoActual,
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
        // Contadores por estado para la barra de menú del SCADA.
        $contadores = ['total' => count($out), 'produccion' => 0, 'preparacion' => 0, 'parada' => 0, 'cerrada' => 0];
        foreach ($out as $m) { $k = $m['estado_cat']; if (isset($contadores[$k])) $contadores[$k]++; }
        return ['ahora' => $ahora, 'maquinas' => $out, 'contadores' => $contadores];
    }

    // ───────── Modal de detalle por máquina (pestañas RESUMEN / PAROS / OFS) ─────────

    /** Datos de la pestaña RESUMEN del modal para una máquina. */
    public static function resumenMaquina(string $cod): array
    {
        $sql = "
            SELECT cm.Cod_maquina, cm.Rt_Cod_of,
                   cm.Rt_Rendimientonominal1,
                   cm.Rt_Unidades_ok_turno, cm.Rt_Unidades_nok_turno, cm.Rt_Unidades_repro_turno,
                   cm.Rt_Unidades_ok_of, cm.Rt_Seg_produccion_turno,
                   fa.Unidades_planning AS plan_of
            FROM cfg_maquina cm
            LEFT JOIN his_fase fa ON fa.Id_his_fase = cm.Rt_Id_his_fase
            WHERE cm.Cod_maquina = ?";
        $r = fetchAll('mapex', $sql, [$cod])[0] ?? null;
        if (!$r) return [
            'ritmo'    => ['real'=>0,'teorico'=>0,'desvio'=>0],
            'oee'      => ['turno'=>0,'of'=>0,'rend_turno'=>0],
            'orden'    => ['ok'=>0,'plan'=>0,'pct'=>0,'faltan'=>0],
            'unidades' => ['ok'=>0,'nok'=>0,'rwk'=>0],
        ];

        $uh      = (float)$r['Rt_Rendimientonominal1'];
        $segProd = (int)$r['Rt_Seg_produccion_turno'];
        $okT     = (int)$r['Rt_Unidades_ok_turno'];
        $teorico = $uh > 0 ? (int)round($segProd * $uh / 3600) : 0;

        $kt = self::kpiTurnoTodas()[$cod] ?? self::calcDRC(0,0,0,0,0,0);
        $of = trim((string)$r['Rt_Cod_of']);
        $ko = self::kpiOfTodas([$of])["$cod|$of"] ?? self::calcDRC(0,0,0,0,0,0);

        $okOf = (int)$r['Rt_Unidades_ok_of'];
        $plan = (int)($r['plan_of'] ?? 0);

        return [
            'ritmo' => ['real'=>$okT, 'teorico'=>$teorico, 'desvio'=>$okT-$teorico],
            'oee'   => ['turno'=>$kt['oee'], 'of'=>$ko['oee'], 'rend_turno'=>$kt['rendimiento']],
            'orden' => ['ok'=>$okOf, 'plan'=>$plan,
                        'pct'=>$plan>0?(int)round($okOf*100/$plan):0,
                        'faltan'=>max(0,$plan-$okOf)],
            'unidades' => ['ok'=>$okT, 'nok'=>(int)$r['Rt_Unidades_nok_turno'],
                           'rwk'=>(int)$r['Rt_Unidades_repro_turno']],
        ];
    }

    /** Paros de una máquina en un día (o rango de $dias hacia atrás). */
    public static function parosMaquina(string $cod, string $fecha, int $dias = 1): array
    {
        $dias = max(1, $dias);
        $ini = date('Y-m-d', strtotime("$fecha -" . ($dias - 1) . " days"));
        $sql = "
            SELECT hpp.Fecha_ini, hpp.Fecha_fin,
                   DATEDIFF(SECOND, hpp.Fecha_ini, ISNULL(hpp.Fecha_fin, GETDATE())) AS seg,
                   cp.Desc_paro AS motivo, o.Cod_of AS ofx
            FROM his_prod_paro hpp
            INNER JOIN his_prod hp     ON hp.Id_his_prod = hpp.Id_his_prod
            INNER JOIN cfg_maquina mq  ON mq.Id_maquina  = hp.Id_maquina
            LEFT  JOIN cfg_paro cp     ON cp.Id_paro     = hpp.Id_paro
            LEFT  JOIN his_fase fa     ON fa.Id_his_fase = hp.Id_his_fase
            LEFT  JOIN his_of o        ON o.Id_his_of    = fa.Id_his_of
            WHERE mq.Cod_maquina = ?
              AND CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?
            ORDER BY hpp.Fecha_ini DESC";
        $rows = fetchAll('mapex', $sql, [$cod, $ini, $fecha]);
        $paros = []; $tot = 0;
        foreach ($rows as $r) {
            $seg = (int)$r['seg']; $tot += $seg;
            $paros[] = [
                'ini'    => date('d/m H:i', strtotime((string)$r['Fecha_ini'])),
                'fin'    => $r['Fecha_fin'] ? date('d/m H:i', strtotime((string)$r['Fecha_fin'])) : '—',
                'seg'    => $seg,
                'motivo' => trim((string)($r['motivo'] ?? 'DESCONOCIDO')),
                'of'     => trim((string)($r['ofx'] ?? '')),
            ];
        }
        return ['fecha'=>$fecha, 'total_seg'=>$tot, 'paros'=>$paros];
    }

    /** OFs producidas por una máquina en un día. */
    public static function ofsMaquina(string $cod, string $fecha): array
    {
        $sql = "
            SELECT o.Cod_of, prod.Desc_producto AS producto,
                   MAX(fa.Unidades_planning)        AS plan_of,
                   SUM(ISNULL(hp.Unidades_ok,0))    AS ok,
                   SUM(ISNULL(hp.Unidades_repro,0)) AS rwk
            FROM his_prod hp
            INNER JOIN cfg_maquina mq  ON mq.Id_maquina  = hp.Id_maquina
            INNER JOIN his_fase fa     ON fa.Id_his_fase = hp.Id_his_fase
            INNER JOIN his_of o        ON o.Id_his_of    = fa.Id_his_of
            LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
            WHERE mq.Cod_maquina = ?
              AND CAST(hp.Dia_productivo AS DATE) = ?
              AND o.Cod_of <> '--'
            GROUP BY o.Cod_of, prod.Desc_producto
            ORDER BY o.Cod_of";
        $ofs = [];
        foreach (fetchAll('mapex', $sql, [$cod, $fecha]) as $r) {
            $plan = (int)$r['plan_of']; $ok = (int)$r['ok'];
            $ofs[] = [
                'of'       => trim((string)$r['Cod_of']),
                'producto' => trim((string)($r['producto'] ?? '')),
                'plan'     => $plan, 'ok' => $ok, 'rwk' => (int)$r['rwk'],
                'pct'      => $plan > 0 ? (int)round($ok*100/$plan) : 0,
            ];
        }
        return ['fecha'=>$fecha, 'ofs'=>$ofs];
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
