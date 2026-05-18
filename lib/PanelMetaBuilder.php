<?php
/**
 * Genera el bloque "meta" que devuelven los APIs que cruzan MAPEX + Excel,
 * para que la UI pueda mostrar al usuario qué fichero se ha leído, qué
 * ventana temporal, qué fórmula se aplica y por qué los números pueden
 * diferir de QlikView.
 *
 * Lo consumen:
 *   - api/plan_attainment.php
 *   - api/por_seccion.php
 *   - api/por_maquina.php
 *   - api/grid.php
 *   - api/evolucion.php
 */

require_once __DIR__ . '/PlanExcelReader.php';

class PanelMetaBuilder
{
    /**
     * Construye el bloque meta para un panel que cruza MAPEX (his_prod) y Excel.
     *
     * @param array $args  Claves admitidas:
     *   - panel        (str)   Etiqueta del panel ("Cumplimiento Global", etc.)
     *   - fechaDesde   (Y-m-d)
     *   - fechaHasta   (Y-m-d)
     *   - turnos       (array) ['M','T','N','C']
     *   - whitelist    (str)   Descripción del whitelist aplicado
     *   - extras       (array) Notas/campos extra a añadir como sección final
     *   - valores      (array) ['plan' => float, 'prod' => float, 'attain' => float] opcionales
     *   - includeFormula (bool) default true
     *   - includeFuzzy   (bool) default true
     *   - includeQvNotes (bool) default true
     */
    public static function buildPlanProdMeta(array $args): array
    {
        $panel       = $args['panel']     ?? 'Panel';
        $fechaDesde  = $args['fechaDesde'];
        $fechaHasta  = $args['fechaHasta'] ?? $fechaDesde;
        $turnos      = $args['turnos']    ?? ['M','T','N'];
        $whitelist   = $args['whitelist'] ?? 'Whitelist Map_FiltroMaquina (DOBL1-11, SOLD1/3/6, TROQ3)';

        $secciones = [];

        // ─────── Plan (Excel) ───────
        $excelInfo = self::resolveExcelFilesUsados($fechaDesde, $fechaHasta, $turnos);
        $itemsPlan = [];
        if (empty($excelInfo)) {
            $itemsPlan[] = ['label' => 'Ficheros', 'value' => '(no se encontró ningún Excel para el rango/turnos seleccionados)'];
        } else {
            foreach ($excelInfo as $info) {
                $itemsPlan[] = [
                    'label' => 'Excel para ' . $info['fecha_uso'] . ' · ' . $info['turno'],
                    'value' => $info['fichero'] . '   (mtime ' . $info['mtime'] . ')',
                ];
            }
        }
        $itemsPlan[] = ['label' => 'Hoja', 'value' => 'PLANIFICACIÓN'];
        $itemsPlan[] = ['label' => 'Sección leída', 'value' => 'Top section (rows 4-360, cols A,D,F,I,N) + Cross-table (header con valor 0,59375 en col C)'];
        $itemsPlan[] = ['label' => 'Carpeta origen', 'value' => PlanExcelReader::EXCEL_BASE_PATH];

        $secciones[] = [
            'titulo' => 'Plan (Excel de planificación diaria)',
            'items'  => $itemsPlan,
            'notas'  => [
                'Si hay varios .xlsm para la misma fecha (p. ej. "Copia de…" + original) se elige el más reciente por mtime.',
                'TARDE/CENTRAL del día X lee el Excel de X. MAÑANA/NOCHE del día X intenta X-1; si no existe (fines de semana / festivos) se usa el Excel disponible más reciente anterior a X y se proyecta el plan al rango horario.',
                'Pedidos con referencia válida en col F se incluyen aunque ud=0 / h=0 (placeholders). Solo se descartan los sin referencia o con valores negativos.',
            ],
        ];

        // ─────── Producción (MAPEX) ───────
        $secciones[] = [
            'titulo' => 'Producción real (MAPEX SQL Server)',
            'items'  => [
                ['label' => 'Servidor',     'value' => DB_MAPEX_HOST . ' / ' . DB_MAPEX_NAME],
                ['label' => 'Tabla',        'value' => 'his_prod (con prorrateo de Unidades_ok por solape de cada fase con el slot horario)'],
                ['label' => 'Joins',        'value' => 'his_fase → his_of → cfg_producto · cfg_maquina'],
                ['label' => 'Ventana',      'value' => self::describeTurnos($turnos)],
                ['label' => 'Fechas',       'value' => $fechaDesde === $fechaHasta ? $fechaDesde : "$fechaDesde → $fechaHasta"],
                ['label' => 'Filtro máq.',  'value' => $whitelist],
                ['label' => 'Excluidos',    'value' => "WorkGroup IN ('Improductivos','AUX000','SOLD5','AUXI1','SOLD4')"],
            ],
            'notas' => [
                'DOBL6 + DOBL7 se consolidan como "BT" (mismo criterio que QV).',
                'Si una fase abarca varios slots/turno, sus Unidades_ok se reparten proporcionalmente al solape de tiempo con cada slot.',
            ],
        ];

        // ─────── Fórmula ───────
        if ($args['includeFormula'] ?? true) {
            $items = [
                ['label' => 'Definición', 'value' => 'PA = Σ min(prod_artículo, plan_artículo)  ÷  Σ plan_artículo'],
                ['label' => 'Cap',        'value' => 'Por artículo y por máquina (la sobreproducción de una ref no compensa el déficit de otra)'],
            ];
            if ($args['includeFuzzy'] ?? true) {
                $items[] = [
                    'label' => 'Fuzzy match',
                    'value' => 'Activo: prod off-plan se acredita a planes con artículos similares (mismo largo ≥ 8 chars, Levenshtein = 1; ej. 151011470 ↔ 151011480). Una unidad off-plan no se acredita dos veces.',
                ];
            }
            $secciones[] = [
                'titulo' => 'Fórmula',
                'items'  => $items,
            ];
        }

        // ─────── Diferencias previstas vs QV ───────
        if ($args['includeQvNotes'] ?? true) {
            $secciones[] = [
                'titulo' => 'Diferencias previstas con QlikView',
                'notas'  => [
                    'QV cap por artículo SOLO en sus horas planificadas — penaliza producir el artículo correcto en hora equivocada. Esta app cuenta el match con independencia de la hora exacta. Diferencia importante en máquinas con cruce de planes intra-turno.',
                    'QV no aplica fuzzy match entre artículos similares; esta app sí. En máquinas que producen variantes "hermanas" del SKU planificado, esta app reporta PA más alto.',
                    'En el panel "Por Máquina" la app aplica una whitelist EXTENDIDA (incluye TBE30, TBE35, TBE RAPIDFORM, PRENSA 3D, MONTAJE AUTOMATICO…). El cumplimiento Global y Por Sección sí se ciñen al whitelist oficial de QV.',
                    'Si en Z:\\…\\Planificaciones diarias hay un "Copia de…" más reciente, esta app lo prefiere; QV puede tener otra preferencia.',
                ],
            ];
        }

        // ─────── Valores brutos ───────
        if (!empty($args['valores'])) {
            $itemsVal = [];
            foreach (['plan','prod','attain'] as $k) {
                if (isset($args['valores'][$k])) {
                    $label = ['plan' => 'Plan total', 'prod' => 'Prod total', 'attain' => 'Attain total'][$k];
                    $itemsVal[] = ['label' => $label, 'value' => number_format((float)$args['valores'][$k], 0, ',', '.') . ' ud'];
                }
            }
            if ($itemsVal) {
                $secciones[] = [
                    'titulo' => 'Valores brutos en este cálculo',
                    'items'  => $itemsVal,
                ];
            }
        }

        // ─────── Sección extra del propio panel ───────
        if (!empty($args['extras']) && is_array($args['extras'])) {
            foreach ($args['extras'] as $extra) {
                if (is_array($extra) && isset($extra['titulo'])) {
                    $secciones[] = $extra;
                }
            }
        }

        return [
            'panel'      => $panel,
            'generado'   => date('Y-m-d H:i:s'),
            'secciones'  => $secciones,
        ];
    }

    /**
     * Resuelve qué Excel se usa para cada (fecha, turno) en el rango — replica la
     * lógica de PlanAttainmentAgg::resolveFileAndShift y PlanExcelReader::ensureLocalCopy
     * pero sin tocar el cache (solo informativo).
     *
     * @return array<int, array{fecha_uso:string, turno:string, fichero:string, mtime:string}>
     */
    private static function resolveExcelFilesUsados(string $fechaDesde, string $fechaHasta, array $turnos): array
    {
        $availableDates = self::listAvailableDatesDesc(); // DateTime[] desc
        $out = [];
        $seen = [];

        $d = new DateTime($fechaDesde);
        $end = new DateTime($fechaHasta);
        while ($d <= $end) {
            foreach ($turnos as $t) {
                $info = self::fileForDateAndShift($d, $t, $availableDates);
                if (!$info) continue;
                $key = $info['path'] . '|' . $info['turno'];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $out[] = [
                    'fecha_uso' => $d->format('Y-m-d'),
                    'turno'     => $t,
                    'fichero'   => $info['fichero'],
                    'mtime'     => $info['mtime'],
                ];
            }
            $d->modify('+1 day');
        }
        return $out;
    }

    /** Lista las fechas (DateTime[]) de los .xlsm presentes, deduplicadas y DESC. */
    private static function listAvailableDatesDesc(): array
    {
        $files = glob(PlanExcelReader::EXCEL_BASE_PATH . '\\*.xlsm') ?: [];
        $byYmd = [];
        foreach ($files as $f) {
            if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})\.xlsm$/', $f, $m)) {
                $ymd = $m[3] . '-' . $m[2] . '-' . $m[1];
                if (isset($byYmd[$ymd])) continue;
                try { $byYmd[$ymd] = new DateTime($ymd); } catch (\Throwable $_) {}
            }
        }
        $arr = array_values($byYmd);
        usort($arr, fn($a, $b) => $b <=> $a);
        return $arr;
    }

    /** Para (fecha, turno) devuelve el fichero que se va a leer, o null. */
    private static function fileForDateAndShift(DateTime $target, string $turno, array $availableDates): ?array
    {
        $fileDate = null;
        if ($turno === 'T' || $turno === 'C') {
            $fileDate = $target;
        } else {
            // M / N: busca el más reciente < target
            foreach ($availableDates as $dT) {
                if ($dT < $target) { $fileDate = $dT; break; }
            }
        }
        if (!$fileDate) return null;
        $dmy = $fileDate->format('d.m.Y');
        $candidates = glob(PlanExcelReader::EXCEL_BASE_PATH . '\\*' . $dmy . '.xlsm') ?: [];
        if (!$candidates) return null;
        usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $path = $candidates[0];
        return [
            'turno'   => $turno,
            'path'    => $path,
            'fichero' => basename($path),
            'mtime'   => date('Y-m-d H:i', filemtime($path)),
        ];
    }

    private static function describeTurnos(array $turnos): string
    {
        $labels = [
            'M' => 'MAÑANA (06:00-14:15)',
            'T' => 'TARDE (14:15-22:30)',
            'N' => 'NOCHE (22:30 día anterior — 06:00 del día seleccionado)',
            'C' => 'CENTRAL (08:00-17:00)',
        ];
        $parts = array_map(fn($t) => $labels[$t] ?? $t, $turnos);
        return implode('  +  ', $parts);
    }
}
