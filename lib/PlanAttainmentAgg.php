<?php
/**
 * Agregador de Plan Attainment: suma plan (Excel) y producción real (MAPEX)
 * por (fecha, turno) y cachea el resultado en JSON para llamadas subsiguientes.
 *
 * Definición (equivalente QW, Produccion_Planificacion):
 *   Plan Attainment = SUM(Unidades_OK producidas) / SUM(Unidades planificadas)
 *
 * Convenciones de turno iguales a api/grid.php + lib/PlanExcelReader.php.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/PlanExcelReader.php';

class PlanAttainmentAgg
{
    const CACHE_DIR = __DIR__ . '/../cache/plan_attain';

    const SHIFT_WINDOWS = [
        'M' => ['start' => '06:00', 'end' => '14:15', 'night' => false],
        'T' => ['start' => '14:15', 'end' => '22:30', 'night' => false],
        'N' => ['start' => '22:30', 'end' => '06:00', 'night' => true],
        'C' => ['start' => '08:00', 'end' => '17:00', 'night' => false],
    ];

    const EXCLUIDAS = ['Improductivos','AUX000','SOLD5','AUXI1','SOLD4'];

    // QW Map_FiltroMaquina (Incluir=1) — máquinas que entran en el Plan Attainment.
    // Cod_maquina en MAPEX:
    const WHITELIST_COD_MAQUINA = [
        'DOBL1','DOBL2','DOBL3','DOBL4','DOBL5','DOBL6','DOBL7','DOBL9','DOBL10','DOBL11',
        'SOLD1','SOLD3','SOLD6','TROQ3',
    ];
    // Desc_maquina (tras agrupar DOBL6/DOBL7 → 'BT') — para filtrar el plan de Excel:
    const WHITELIST_MAQUINA_NAME = [
        'BUCH GRANDE','BUCH PEQUEÑA','TURBOBENDER','BM30','BMS31',
        'BT','R2105','R2108','BT 3.2',
        'LARGOIKO','PROEMISA','CELDA K0 TICE','ESCOPETA',
    ];

    // Mapeo Desc_maquina → Sección (cfg_area) para desglose por sección.
    // Solo incluye las máquinas del whitelist QV Map_FiltroMaquina.
    const MAQUINA_TO_SECCION = [
        'BUCH GRANDE'     => 'VARILLAS',
        'BUCH PEQUEÑA'    => 'VARILLAS',
        'TURBOBENDER'     => 'VARILLAS',
        'BM30'            => 'VARILLAS',
        'BMS31'           => 'VARILLAS',
        'BT'              => 'VARILLAS',
        'R2105'           => 'VARILLAS',
        'R2108'           => 'VARILLAS',
        'BT 3.2'          => 'VARILLAS',
        'LARGOIKO'        => 'VARILLAS',
        'PROEMISA'        => 'VARILLAS',
        'CELDA K0 TICE'   => 'VARILLAS',
        'ESCOPETA'        => 'TROQUELADOS',
    ];

    // Mapeo extendido usado por rangeByMaquina: todas las máquinas activas de
    // VARILLAS/TROQUELADOS en cfg_maquina (no solo las de Map_FiltroMaquina).
    // Replica lo que QV muestra en "Por Máquina" (incluye TBE30, TBE35,
    // TBE RAPIDFORM, F175.FERRARI, etc.).
    const MAQUINA_TO_SECCION_EXT = [
        // VARILLAS
        'BUCH GRANDE'     => 'VARILLAS',
        'BUCH PEQUEÑA'    => 'VARILLAS',
        'TURBOBENDER'     => 'VARILLAS',
        'BM30'            => 'VARILLAS',
        'BMS31'           => 'VARILLAS',
        'BT'              => 'VARILLAS',
        'BT 3.4 DCHA'     => 'VARILLAS',
        'BT 3.4 IZQDA'    => 'VARILLAS',
        'R2105'           => 'VARILLAS',
        'R2108'           => 'VARILLAS',
        'BT 3.2'          => 'VARILLAS',
        'LARGOIKO'        => 'VARILLAS',
        'PROEMISA'        => 'VARILLAS',
        'PROEMISA B'      => 'VARILLAS',
        'CELDA K0 TICE'   => 'VARILLAS',
        'TBE30'           => 'VARILLAS',
        'TBE35'           => 'VARILLAS',
        'TBE RAPIDFORM'   => 'VARILLAS',
        'F175.FERRARI'    => 'VARILLAS',
        'GRID K0 K9'      => 'VARILLAS',
        // TROQUELADOS
        'ESCOPETA'            => 'TROQUELADOS',
        'MONTAJE AUTOMATICO'  => 'TROQUELADOS',
        'PRENSA 3D N1'        => 'TROQUELADOS',
        'PRENSA 3D N2'        => 'TROQUELADOS',
        'PRENSA 3D N2B'       => 'TROQUELADOS',
    ];

    // Cod_maquina extendidos (para el SQL de prod). Todos los activos de VARILLAS/TROQUELADOS.
    const WHITELIST_COD_MAQUINA_EXT = [
        'DOBL1','DOBL2','DOBL3','DOBL4','DOBL5','DOBL6','DOBL7','DOBL8','DOBL9','DOBL10','DOBL11','DOBL12','DOBL13',
        'SOLD1','SOLD3','SOLD3B','SOLD6','SOLD8','SOLD9',
        'AUX2','TERM1','TERM2','TERM2B',
        'TROQ3',
    ];

    /**
     * Devuelve para un día+turno el detalle plan/prod por (maquina, articulo).
     * Cachea salvo el día en curso. Claves: "MAQUINA|ARTICULO".
     */
    public static function dayShiftDetail(string $fechaYMD, string $turno): array
    {
        if (!isset(self::SHIFT_WINDOWS[$turno])) {
            return ['plan' => [], 'prod' => []];
        }
        $hoy = date('Y-m-d');
        $isPast = ($fechaYMD < $hoy);
        $maxAge = $isPast ? PHP_INT_MAX : 120; // 2 min para hoy, permanente para pasado
        if (!is_dir(self::CACHE_DIR)) @mkdir(self::CACHE_DIR, 0777, true);
        $cacheFile = self::CACHE_DIR . "/{$fechaYMD}_{$turno}.json";

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $maxAge) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['plan'], $cached['prod'])
                && is_array($cached['plan'])) {
                return $cached;
            }
        }

        [$dtStart, $dtEnd] = self::shiftRange($fechaYMD, $turno);
        $realSlots = self::buildSlots($dtStart, $dtEnd);
        $whitelist = array_flip(self::WHITELIST_MAQUINA_NAME);

        // Plan por (maquina|articulo) — replica Map_Dias de QW.
        // Para MAÑANA/NOCHE: busca el fichero Excel existente más reciente antes
        // de fechaYMD y aplica el "days shift" apropiado. Esto permite que MAÑANA
        // de un lunes se lea del fichero del viernes (saltando sáb/dom sin fichero).
        $plan = [];
        try {
            [$virtualYMD, $daysShift] = self::resolveFileAndShift($fechaYMD, $turno);
            if ($virtualYMD !== null) {
                [$vS, $vE] = self::shiftRange($virtualYMD, $turno);
                $virtualSlots = self::buildSlots($vS, $vE);
                $planRows = PlanExcelReader::getPlanPorHora($virtualYMD, $turno, $virtualSlots);
                foreach ($planRows as $r) {
                    $m = $r['maquina'];
                    if (!isset($whitelist[$m])) continue;
                    $k = $m . '|' . $r['cod_articulo'];
                    $plan[$k] = ($plan[$k] ?? 0) + (float)$r['ud'];
                }
            }
        } catch (\Throwable $e) {
            error_log("PlanAttainmentAgg plan: " . $e->getMessage());
        }

        // Prod por (maquina|articulo) desde MAPEX — siempre con los slots REALES.
        $prod = self::prodByMaqArt($realSlots);

        $result = ['plan' => $plan, 'prod' => $prod];
        @file_put_contents($cacheFile, json_encode($result));
        return $result;
    }

    /**
     * Agrega plan/prod sobre un rango y devuelve métricas:
     *   plan_total : sum(plan por (maq,art) del whitelist)
     *   prod_total : sum(prod por (maq,art) del whitelist) — para debug
     *   attain_num : sum( min(prod, plan) por (maq,art) con plan>0 )  — numerador PA estricto
     *   attain_pa  : attain_num / plan_total × 100
     */
    public static function rangeTotals(string $fechaDesde, string $fechaHasta, array $turnos): array
    {
        $sumPlan = 0.0;
        $sumProd = 0.0;
        $sumAtt  = 0.0;

        $d  = new DateTime($fechaDesde);
        $fh = new DateTime($fechaHasta);
        while ($d <= $fh) {
            $ymd = $d->format('Y-m-d');
            foreach ($turnos as $t) {
                $r = self::dayShiftDetail($ymd, $t);
                $plan = $r['plan']; $prod = $r['prod'];
                $attain = self::attainWithFuzzyMatch($plan, $prod);

                foreach ($plan as $k => $pl) $sumPlan += (float)$pl;
                foreach ($prod as $pr) $sumProd += (float)$pr;
                foreach ($attain as $att) $sumAtt += (float)$att;
            }
            $d->modify('+1 day');
        }
        $pa = $sumPlan > 0 ? ($sumAtt / $sumPlan) : 0;
        return [
            'plan'     => $sumPlan,
            'prod'     => $sumProd,
            'attain'   => $sumAtt,
            'attain_pct' => $pa,
        ];
    }

    /**
     * Resuelve qué fichero Excel usar para (fechaYMD, turno) considerando fines
     * de semana/festivos donde el fichero X-1 puede no existir.
     *
     * Devuelve [$virtualYMD, $daysShift]:
     *   - $virtualYMD: fechaYMD a pasar a PlanExcelReader::getPlanPorHora para
     *     que éste resuelva correctamente el fichero y calcule slots alineados.
     *   - $daysShift: días que el Excel "salta" (Dias en QW Map_Dias).
     *
     * TARDE/CENTRAL: fichero = fechaYMD (no hay shift posible).
     * MAÑANA/NOCHE: fichero = fecha_existente_más_reciente < fechaYMD;
     *               daysShift = fechaYMD - fichero - 1.
     */
    private static function resolveFileAndShift(string $fechaYMD, string $turno): array
    {
        if ($turno === 'T' || $turno === 'C') {
            return [$fechaYMD, 0];
        }

        $target = new DateTime($fechaYMD);
        $files = self::listExcelFileDates();
        $fileDate = null;
        foreach ($files as $d) { // sorted DESC
            if ($d < $target) { $fileDate = $d; break; }
        }
        if (!$fileDate) return [null, 0];

        $daysDiff  = (int)$target->diff($fileDate)->days;
        $daysShift = max(0, $daysDiff - 1);

        $virtual = clone $target;
        if ($daysShift > 0) $virtual->modify("-{$daysShift} day");
        return [$virtual->format('Y-m-d'), $daysShift];
    }

    /** Lista las fechas de los ficheros Excel presentes, ordenadas DESC. Cacheada en memoria. */
    private static $fileDatesCache = null;
    private static function listExcelFileDates(): array
    {
        if (self::$fileDatesCache !== null) return self::$fileDatesCache;

        $basePath = PlanExcelReader::EXCEL_BASE_PATH;
        $files = glob($basePath . '\\*.xlsm') ?: [];
        // Deduplicar por fecha: si hay varios ficheros para el mismo día
        // (p. ej. "F13057... 27.04.2026.xlsm" y "Copia de ... 27.04.2026.xlsm")
        // queremos UNA sola entrada — ensureLocalCopy() decidirá cuál leer.
        $byYmd = [];
        foreach ($files as $f) {
            if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})\.xlsm$/', $f, $m)) {
                $ymd = $m[3] . '-' . $m[2] . '-' . $m[1];
                if (isset($byYmd[$ymd])) continue;
                try { $byYmd[$ymd] = new DateTime($ymd); }
                catch (\Throwable $_) {}
            }
        }
        $dates = array_values($byYmd);
        usort($dates, fn($a, $b) => $b <=> $a);
        self::$fileDatesCache = $dates;
        return $dates;
    }

    /**
     * Agrega Plan Attainment por SECCIÓN sobre un rango de fechas/turnos.
     * Devuelve [ ['seccion' => 'VARILLAS', 'plan' => ..., 'prod' => ..., 'attain' => ..., 'pa' => %], ... ]
     * ordenado por PA desc.
     */
    public static function rangeBySeccion(string $fechaDesde, string $fechaHasta, array $turnos): array
    {
        $byS = []; // [seccion] = ['plan'=>,'prod'=>,'attain'=>]
        $d  = new DateTime($fechaDesde);
        $fh = new DateTime($fechaHasta);
        while ($d <= $fh) {
            $ymd = $d->format('Y-m-d');
            foreach ($turnos as $t) {
                $r = self::dayShiftDetail($ymd, $t);
                $plan = $r['plan']; $prod = $r['prod'];
                $attain = self::attainWithFuzzyMatch($plan, $prod);
                $keys = array_unique(array_merge(array_keys($plan), array_keys($prod)));
                foreach ($keys as $k) {
                    [$maq, $_] = explode('|', $k, 2);
                    $sec = self::MAQUINA_TO_SECCION[$maq] ?? null;
                    if (!$sec) continue;
                    $p = (float)($plan[$k] ?? 0);
                    $q = (float)($prod[$k] ?? 0);
                    $a = (float)($attain[$k] ?? 0);
                    if (!isset($byS[$sec])) $byS[$sec] = ['plan'=>0,'prod'=>0,'attain'=>0];
                    $byS[$sec]['plan']   += $p;
                    $byS[$sec]['prod']   += $q;
                    $byS[$sec]['attain'] += $a;
                }
            }
            $d->modify('+1 day');
        }
        // Asegurar que todas las secciones esperadas estén presentes (aunque sin datos).
        foreach (array_unique(array_values(self::MAQUINA_TO_SECCION)) as $sec) {
            if (!isset($byS[$sec])) $byS[$sec] = ['plan'=>0,'prod'=>0,'attain'=>0];
        }
        $out = [];
        foreach ($byS as $sec => $v) {
            $pa = $v['plan'] > 0 ? ($v['attain'] / $v['plan']) * 100 : 0;
            $out[] = [
                'seccion' => $sec,
                'plan_total' => round($v['plan'], 0),
                'prod_total' => round($v['prod'], 0),
                'attain'     => round($v['attain'], 0),
                'plan_attainment' => round($pa, 2),
            ];
        }
        usort($out, fn($a, $b) => $b['plan_attainment'] <=> $a['plan_attainment']);
        return $out;
    }

    /**
     * Agrega Plan Attainment por MÁQUINA sobre un rango de fechas/turnos.
     * Usa la whitelist EXTENDIDA: incluye todas las máquinas activas de
     * VARILLAS/TROQUELADOS (no solo Map_FiltroMaquina). Replica la vista QV
     * "Por Máquina" que muestra TBE30, TBE35, TBE RAPIDFORM, PRENSA 3D, etc.
     * Devuelve solo máquinas con plan o prod > 0.
     */
    public static function rangeByMaquina(string $fechaDesde, string $fechaHasta, array $turnos): array
    {
        $byM = [];
        $d  = new DateTime($fechaDesde);
        $fh = new DateTime($fechaHasta);
        while ($d <= $fh) {
            $ymd = $d->format('Y-m-d');
            foreach ($turnos as $t) {
                $r = self::dayShiftDetailExt($ymd, $t);
                $plan = $r['plan']; $prod = $r['prod'];
                $attain = self::attainWithFuzzyMatch($plan, $prod);
                $keys = array_unique(array_merge(array_keys($plan), array_keys($prod)));
                foreach ($keys as $k) {
                    [$maq, $_] = explode('|', $k, 2);
                    if (!isset(self::MAQUINA_TO_SECCION_EXT[$maq])) continue;
                    $p = (float)($plan[$k] ?? 0);
                    $q = (float)($prod[$k] ?? 0);
                    $a = (float)($attain[$k] ?? 0);
                    if (!isset($byM[$maq])) $byM[$maq] = ['plan'=>0,'prod'=>0,'attain'=>0];
                    $byM[$maq]['plan']   += $p;
                    $byM[$maq]['prod']   += $q;
                    $byM[$maq]['attain'] += $a;
                }
            }
            $d->modify('+1 day');
        }
        $out = [];
        foreach ($byM as $maq => $v) {
            if ($v['plan'] == 0 && $v['prod'] == 0) continue;
            $pa = $v['plan'] > 0 ? ($v['attain'] / $v['plan']) * 100 : 0;
            $out[] = [
                'maquina'        => $maq,
                'seccion'        => self::MAQUINA_TO_SECCION_EXT[$maq] ?? '',
                'plan_total'     => round($v['plan'], 0),
                'prod_total'     => round($v['prod'], 0),
                'attain'         => round($v['attain'], 0),
                'plan_attainment'=> round($pa, 2),
            ];
        }
        usort($out, fn($a, $b) => $b['plan_attainment'] <=> $a['plan_attainment']);
        return $out;
    }

    /**
     * Desglose Plan vs Producido por artículo para una máquina concreta.
     * Recorre día×turno con dayShiftDetailExt() y suma plan/prod por cod_articulo
     * filtrando las claves "maquina|cod_articulo" cuyo prefijo coincida.
     *
     * @return array<int, array{cod_articulo:string, plan:float, prod:float, attain:float, plan_attainment:float}>
     */
    public static function rangeByMaquinaArticulo(
        string $fechaDesde,
        string $fechaHasta,
        array $turnos,
        string $maquinaName
    ): array {
        $byArt = [];
        $d  = new DateTime($fechaDesde);
        $fh = new DateTime($fechaHasta);
        while ($d <= $fh) {
            $ymd = $d->format('Y-m-d');
            foreach ($turnos as $t) {
                $r = self::dayShiftDetailExt($ymd, $t);
                $plan = $r['plan']; $prod = $r['prod'];
                $attain = self::attainWithFuzzyMatch($plan, $prod);
                $keys = array_unique(array_merge(array_keys($plan), array_keys($prod)));
                foreach ($keys as $k) {
                    [$maq, $art] = explode('|', $k, 2);
                    if ($maq !== $maquinaName) continue;
                    if (!isset($byArt[$art])) $byArt[$art] = ['plan'=>0,'prod'=>0,'attain'=>0];
                    $byArt[$art]['plan']   += (float)($plan[$k]   ?? 0);
                    $byArt[$art]['prod']   += (float)($prod[$k]   ?? 0);
                    $byArt[$art]['attain'] += (float)($attain[$k] ?? 0);
                }
            }
            $d->modify('+1 day');
        }
        $out = [];
        foreach ($byArt as $art => $v) {
            if ($v['plan'] == 0 && $v['prod'] == 0) continue;
            $pa = $v['plan'] > 0 ? ($v['attain'] / $v['plan']) * 100 : 0;
            $out[] = [
                'cod_articulo'    => $art,
                'plan'            => round($v['plan'], 0),
                'prod'            => round($v['prod'], 0),
                'attain'          => round($v['attain'], 0),
                'plan_attainment' => round($pa, 2),
            ];
        }
        usort($out, fn($a, $b) => $b['plan'] <=> $a['plan']);
        return $out;
    }

    /**
     * Variante extendida de dayShiftDetail: no filtra por Map_FiltroMaquina,
     * devuelve todas las máquinas en MAQUINA_TO_SECCION_EXT. Cachea por separado
     * en {fecha}_{turno}_ext.json para no colisionar con dayShiftDetail.
     */
    public static function dayShiftDetailExt(string $fechaYMD, string $turno): array
    {
        if (!isset(self::SHIFT_WINDOWS[$turno])) {
            return ['plan' => [], 'prod' => []];
        }
        $hoy = date('Y-m-d');
        $isPast = ($fechaYMD < $hoy);
        $maxAge = $isPast ? PHP_INT_MAX : 120; // 2 min para hoy
        if (!is_dir(self::CACHE_DIR)) @mkdir(self::CACHE_DIR, 0777, true);
        $cacheFile = self::CACHE_DIR . "/{$fechaYMD}_{$turno}_ext.json";

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $maxAge) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['plan'], $cached['prod']) && is_array($cached['plan'])) {
                return $cached;
            }
        }

        [$dtStart, $dtEnd] = self::shiftRange($fechaYMD, $turno);
        $realSlots = self::buildSlots($dtStart, $dtEnd);
        $whitelist = array_flip(array_keys(self::MAQUINA_TO_SECCION_EXT));

        $plan = [];
        try {
            [$virtualYMD, $daysShift] = self::resolveFileAndShift($fechaYMD, $turno);
            if ($virtualYMD !== null) {
                [$vS, $vE] = self::shiftRange($virtualYMD, $turno);
                $virtualSlots = self::buildSlots($vS, $vE);
                $planRows = PlanExcelReader::getPlanPorHora($virtualYMD, $turno, $virtualSlots);
                foreach ($planRows as $r) {
                    $m = $r['maquina'];
                    if (!isset($whitelist[$m])) continue;
                    $k = $m . '|' . $r['cod_articulo'];
                    $plan[$k] = ($plan[$k] ?? 0) + (float)$r['ud'];
                }
            }
        } catch (\Throwable $e) {
            error_log("PlanAttainmentAgg plan ext: " . $e->getMessage());
        }

        $prod = self::prodByMaqArtExt($realSlots);

        $result = ['plan' => $plan, 'prod' => $prod];
        @file_put_contents($cacheFile, json_encode($result));
        return $result;
    }

    /** Variante extendida de prodByMaqArt: usa WHITELIST_COD_MAQUINA_EXT. */
    private static function prodByMaqArtExt(array $slots): array
    {
        if (!$slots) return [];
        $valuesSlots = [];
        $params = [];
        foreach ($slots as $s) {
            $valuesSlots[] = "(?, ?)";
            array_push($params, $s['ini'], $s['fin']);
        }
        $valuesSql = implode(',', $valuesSlots);
        $whitelistList = "('" . implode("','", self::WHITELIST_COD_MAQUINA_EXT) . "')";

        $sql = "
            WITH slots(ini, fin) AS (
                SELECT CAST(V.c1 AS DATETIME), CAST(V.c2 AS DATETIME)
                FROM (VALUES $valuesSql) AS V(c1, c2)
            )
            SELECT
                CASE WHEN mq.Cod_maquina IN ('DOBL6','DOBL7') THEN 'BT'
                     ELSE mq.Desc_maquina END AS maquina,
                pr.Cod_producto AS cod_articulo,
                SUM(CAST(ISNULL(p.Unidades_ok, 0) AS FLOAT) *
                    DATEDIFF(SECOND,
                        CASE WHEN p.Fecha_ini > s.ini THEN p.Fecha_ini ELSE s.ini END,
                        CASE WHEN ISNULL(p.Fecha_fin, p.Fecha_ini) < s.fin THEN ISNULL(p.Fecha_fin, p.Fecha_ini) ELSE s.fin END
                    ) / NULLIF(DATEDIFF(SECOND, p.Fecha_ini, ISNULL(p.Fecha_fin, p.Fecha_ini)), 0)
                ) AS prod_ok
            FROM his_prod p
            INNER JOIN slots s
                  ON p.Fecha_ini < s.fin
                 AND ISNULL(p.Fecha_fin, p.Fecha_ini) > s.ini
            INNER JOIN cfg_maquina mq ON mq.Id_maquina = p.Id_maquina
            LEFT JOIN his_fase     fa ON fa.Id_his_fase = p.Id_his_fase
            LEFT JOIN his_of       o  ON o.Id_his_of    = fa.Id_his_of
            LEFT JOIN cfg_producto pr ON pr.Id_producto = o.Id_producto
            WHERE mq.Cod_maquina IN $whitelistList
              AND pr.Cod_producto IS NOT NULL
            GROUP BY
                CASE WHEN mq.Cod_maquina IN ('DOBL6','DOBL7') THEN 'BT'
                     ELSE mq.Desc_maquina END,
                pr.Cod_producto
        ";
        $rows = fetchAll('mapex', $sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['maquina'] . '|' . $r['cod_articulo']] = (float)$r['prod_ok'];
        }
        return $out;
    }

    /**
     * Calcula el attain con fuzzy-matching por máquina:
     *   - Empieza con el attain estricto: min(prod[art], plan[art]) por artículo.
     *   - Para cada (maq, art) con plan no cumplido (shortfall > 0), busca dentro
     *     de la misma máquina prod "off-plan" de artículos MUY similares
     *     (misma longitud, Levenshtein=1 — ej. 151011470 ↔ 151011480) y transfiere
     *     el crédito hasta cubrir el shortfall.
     *   - Una unidad de prod off-plan no puede acreditarse dos veces.
     *
     * Devuelve un map [maq|art] => attained_units con el attain ajustado.
     */
    public static function attainWithFuzzyMatch(array $plan, array $prod): array
    {
        // Agrupar por máquina
        $byMaq = [];
        foreach ($plan as $k => $v) {
            [$m, $a] = explode('|', $k, 2);
            $byMaq[$m]['plan'][$a] = (float)$v;
        }
        foreach ($prod as $k => $v) {
            [$m, $a] = explode('|', $k, 2);
            $byMaq[$m]['prod'][$a] = (float)$v;
        }

        $out = [];
        foreach ($byMaq as $maq => $data) {
            $planArts = $data['plan'] ?? [];
            $prodArts = $data['prod'] ?? [];

            // Attain estricto por artículo. Track de prod "sobrante" por cada artículo.
            $attain = [];
            $prodRemaining = [];
            foreach ($prodArts as $art => $q) $prodRemaining[$art] = $q;
            foreach ($planArts as $art => $p) {
                $q = $prodArts[$art] ?? 0;
                $match = min($q, $p);
                $attain[$art] = $match;
                // Consumimos $match de $prodRemaining[$art] (lo atribuido al plan propio).
                $prodRemaining[$art] = max(0, ($prodRemaining[$art] ?? 0) - $match);
            }

            // Fuzzy transfer: para cada plan con shortfall, busca prod similar sin plan propio.
            foreach ($planArts as $art => $p) {
                $shortfall = $p - ($attain[$art] ?? 0);
                if ($shortfall <= 0) continue;

                foreach ($prodRemaining as $prodArt => $available) {
                    if ($available <= 0) continue;
                    if ($prodArt === $art) continue;
                    // Solo transfiere si el artículo productivo NO es otro plan
                    // ACTIVO de la máquina (con ud>0). Si es otro plan ud>0 ya
                    // tiene su propio contador. Si es un plan "phantom" ud=0
                    // (ref pegada en col F del Excel pero sin cantidad/horas
                    // todavía), tratarlo como si no estuviese y permitir la
                    // transferencia — antes así sucedía porque el parser
                    // descartaba esas filas.
                    if (($planArts[$prodArt] ?? 0) > 0) continue;
                    if (!self::articlesSimilar($art, $prodArt)) continue;

                    $transfer = min($shortfall, $available);
                    $attain[$art] += $transfer;
                    $prodRemaining[$prodArt] -= $transfer;
                    $shortfall -= $transfer;
                    if ($shortfall <= 0) break;
                }
            }

            foreach ($attain as $art => $n) {
                $out[$maq . '|' . $art] = $n;
            }
        }
        return $out;
    }

    /**
     * Artículos "similares": misma longitud, ≥ 8 caracteres y Levenshtein = 1.
     * La condición de longitud mínima evita falsos positivos en códigos cortos
     * (ej. 302735 ↔ 302737) donde 1 dígito distinto puede ser producto distinto.
     * En códigos largos (9+ caracteres), Lev=1 casi siempre indica variante del
     * mismo producto base (ej. 151011470 ↔ 151011480, 193034420001 ↔ 193034520001).
     */
    private static function articlesSimilar(string $a, string $b): bool
    {
        if ($a === $b) return true;
        if (strlen($a) !== strlen($b)) return false;
        if (strlen($a) < 8) return false;
        return levenshtein($a, $b) === 1;
    }

    private static function shiftRange(string $fechaYMD, string $turno): array
    {
        $cfg = self::SHIFT_WINDOWS[$turno];
        if ($cfg['night']) {
            $dtStart = new DateTime($fechaYMD . ' ' . $cfg['start'] . ':00');
            $dtStart->modify('-1 day');
            $dtEnd = new DateTime($fechaYMD . ' ' . $cfg['end'] . ':00');
        } else {
            $dtStart = new DateTime($fechaYMD . ' ' . $cfg['start'] . ':00');
            $dtEnd   = new DateTime($fechaYMD . ' ' . $cfg['end'] . ':00');
        }
        return [$dtStart, $dtEnd];
    }

    private static function buildSlots(DateTime $dtStart, DateTime $dtEnd): array
    {
        $slots = [];
        $cursor = clone $dtStart;
        while ($cursor < $dtEnd) {
            $next = new DateTime($cursor->format('Y-m-d H:00:00'));
            $next->modify('+1 hour');
            if ($next > $dtEnd) $next = clone $dtEnd;
            $slots[] = [
                'hora'  => (int)$cursor->format('G'),
                'ini'   => $cursor->format('Y-m-d H:i:s'),
                'fin'   => $next->format('Y-m-d H:i:s'),
                'label' => $cursor->format('H:i'),
                'fecha' => $cursor->format('d/m/Y'),
            ];
            $cursor = $next;
        }
        return $slots;
    }

    /**
     * Unidades_ok prorrateadas por (maquina, cod_articulo) — map con clave "M|A".
     * Agrupa DOBL6+DOBL7 bajo 'BT'. Aplica whitelist Map_FiltroMaquina.
     */
    private static function prodByMaqArt(array $slots): array
    {
        if (!$slots) return [];

        $valuesSlots = [];
        $params = [];
        foreach ($slots as $s) {
            $valuesSlots[] = "(?, ?)";
            array_push($params, $s['ini'], $s['fin']);
        }
        $valuesSql = implode(',', $valuesSlots);

        $whitelistList = "('" . implode("','", self::WHITELIST_COD_MAQUINA) . "')";

        $sql = "
            WITH slots(ini, fin) AS (
                SELECT CAST(V.c1 AS DATETIME), CAST(V.c2 AS DATETIME)
                FROM (VALUES $valuesSql) AS V(c1, c2)
            )
            SELECT
                CASE WHEN mq.Cod_maquina IN ('DOBL6','DOBL7') THEN 'BT'
                     ELSE mq.Desc_maquina END AS maquina,
                pr.Cod_producto AS cod_articulo,
                SUM(CAST(ISNULL(p.Unidades_ok, 0) AS FLOAT) *
                    DATEDIFF(SECOND,
                        CASE WHEN p.Fecha_ini > s.ini THEN p.Fecha_ini ELSE s.ini END,
                        CASE WHEN ISNULL(p.Fecha_fin, p.Fecha_ini) < s.fin THEN ISNULL(p.Fecha_fin, p.Fecha_ini) ELSE s.fin END
                    ) / NULLIF(DATEDIFF(SECOND, p.Fecha_ini, ISNULL(p.Fecha_fin, p.Fecha_ini)), 0)
                ) AS prod_ok
            FROM his_prod p
            INNER JOIN slots s
                  ON p.Fecha_ini < s.fin
                 AND ISNULL(p.Fecha_fin, p.Fecha_ini) > s.ini
            INNER JOIN cfg_maquina mq ON mq.Id_maquina = p.Id_maquina
            LEFT JOIN his_fase     fa ON fa.Id_his_fase = p.Id_his_fase
            LEFT JOIN his_of       o  ON o.Id_his_of    = fa.Id_his_of
            LEFT JOIN cfg_producto pr ON pr.Id_producto = o.Id_producto
            WHERE mq.Cod_maquina IN $whitelistList
              AND pr.Cod_producto IS NOT NULL
            GROUP BY
                CASE WHEN mq.Cod_maquina IN ('DOBL6','DOBL7') THEN 'BT'
                     ELSE mq.Desc_maquina END,
                pr.Cod_producto
        ";
        $rows = fetchAll('mapex', $sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['maquina'] . '|' . $r['cod_articulo']] = (float)$r['prod_ok'];
        }
        return $out;
    }
}
