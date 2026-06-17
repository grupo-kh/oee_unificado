<?php
/**
 * Parser de los Excels de Planificación diaria.
 *
 * Replica la lógica de QlikView (transformacion.qvs → Trans_Planificacion):
 *  - Fichero "dd.MM.yyyy.xlsm" contiene plan desde 14:15 del día X a 13:45 del día X+1.
 *  - Hoja "PLANIFICACIÓN":
 *      * Sección superior (rows 4..~350): una fila por (MAQUINA, ORDEN, REFERENCIA, UNIDADES, HORAS)
 *        - Columna A: MAQUINA (solo en la primera fila de cada bloque)
 *        - Columna D: ORDEN (1,2,3... reinicia por máquina)
 *        - Columna F: REFERENCIA (código de artículo)
 *        - Columna I: CANTIDAD DIARIA PREVISTA (unidades planificadas)
 *        - Columna N: HORAS PREVISTAS
 *      * Cross-table (rows ~370+): filas pares = máquina; columnas C..AZ = 48 slots 30 min.
 *        - Valor de celda = ORDEN programado en ese slot para esa máquina (0 o vacío = sin plan).
 *
 * Salida: plan por hora para (fecha_productiva, turno).
 * Convenciones:
 *   - fecha_productiva = día de fin del turno.
 *   - TARDE X   → leer fichero X
 *   - NOCHE X   → leer fichero X-1
 *   - MAÑANA X  → leer fichero X-1
 *   - CENTRAL X → leer fichero X (8:00-17:00 cae en TARDE mayormente; tentativo)
 */

class PlanExcelReader
{
    /**
     * Carpeta de los Excel de Planificación diaria.
     * Se configura por entorno (EXCEL_BASE_PATH en `.env`); el repositorio
     * no contiene ninguna ruta real.
     */
    public static function excelBase(): string
    {
        if (function_exists('env')) return (string) env('EXCEL_BASE_PATH', '');
        return (string) (getenv('EXCEL_BASE_PATH') ?: '');
    }
    const CACHE_DIR = __DIR__ . '/../cache/plan_excel';
    const CACHE_PARSED_DIR = __DIR__ . '/../cache/plan_parsed';

    /** Mapeo nombre Excel (schedule o pedidos) → Desc_maquina de cfg_maquina */
    const MAP_EXCEL_TO_DESC = [
        // Schedule names
        'BM30' => 'BM30',
        'BMS31' => 'BMS31',
        'BT 3.2' => 'BT 3.2',
        'BT3.4' => 'BT',
        'BT 3.4' => 'BT',
        'LARGOIKO' => 'LARGOIKO',
        'LECTRA' => 'LECTRA',
        'PRENSA 3D N1' => 'PRENSA 3D N1',
        'PRENSA 3D N2' => 'PRENSA 3D N2',
        'PRENSA 3D N2B' => 'PRENSA 3D N2B',
        'PROEMISA' => 'PROEMISA',
        'PROEMISA B' => 'PROEMISA B',
        'R2105' => 'R2105',
        'R2108' => 'R2108',
        'RAPIDFORM' => 'TBE RAPIDFORM',
        'TBE RAPIDFORM' => 'TBE RAPIDFORM',
        'SYSCO' => 'SYSCO',
        'TB' => 'TURBOBENDER',
        'TBE' => 'TBE30',
        'TBE30' => 'TBE30',
        'TBE35' => 'TBE35',
        'TICE' => 'CELDA K0 TICE',
        'CELDA SOLDADURA' => 'CELDA K0 TICE',
        'CELDA SOLDADURA TICE' => 'CELDA K0 TICE',
        'CELDA F175' => 'F175.FERRARI',
        'CELDA FERRARI' => 'F175.FERRARI',
        'TURBOBENDER' => 'TURBOBENDER',
        'MONTAJE AUTOMATICO' => 'MONTAJE AUTOMATICO',
        'MONTAJE AUTOMÁTICO' => 'MONTAJE AUTOMATICO',
        'GRID K0' => 'GRID K0 K9',
        'KISS' => 'KISS',
        'TRASVASE' => 'AUXI2',
        'FRAME' => 'LARGOIKO',
        'ADHESIVADO' => 'ADHE1',
        'ADHESIVADORA' => 'ADHE1',
        'ROBOT ACAS' => 'ROBOT ACAS',
        'CORTE DE SIERRA' => 'SIVE1',
        'SIERRA VERTICAL' => 'SIVE1',
        'ESCOPETA' => 'ESCOPETA',
    ];

    private static function normalize(string $name): string
    {
        return self::normalizeDesc($name);
    }

    /**
     * Versión pública del normalizador de descripciones de máquina, para
     * que otros stores (p. ej. OfsStore) puedan traducir Cod/Desc_maquina
     * de MAPEX al mismo formato que se usa como clave en `pedidos[]` y
     * `schedule[]` del Excel.
     */
    public static function normalizeDesc(string $name): string
    {
        $key = trim($name);
        $up = mb_strtoupper($key, 'UTF-8');
        return self::MAP_EXCEL_TO_DESC[$up] ?? self::MAP_EXCEL_TO_DESC[$key] ?? $key;
    }

    /**
     * Sincroniza el Excel de la fecha indicada desde la carpeta configurada
     * (EXCEL_BASE_PATH) al cache local.
     *
     * Si el planificador deja varios ficheros para la misma fecha (p. ej.
     * "F13057... 27.04.2026.xlsm" y "Copia de F13057... 27.04.2026.xlsm"),
     * elegimos siempre el más reciente por mtime para no servir datos
     * obsoletos. La recopia se dispara si el cache local no existe o si
     * (mtime, tamaño) del origen elegido no coincide con el cache — así
     * detectamos también el caso de "el fichero origen ha cambiado de
     * identidad" (p. ej. se borró la 'Copia de' y queda solo el original).
     */
    public static function ensureLocalCopy(string $fechaDMY): ?string
    {
        if (!is_dir(self::CACHE_DIR)) @mkdir(self::CACHE_DIR, 0777, true);
        $local = self::CACHE_DIR . '/' . $fechaDMY . '.xlsm';
        $candidates = glob(self::excelBase() . '\\*' . $fechaDMY . '.xlsm') ?: [];
        if (!$candidates) return file_exists($local) ? $local : null;

        usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $src = $candidates[0];

        $needsCopy = !file_exists($local)
                  || filesize($src)  !== filesize($local)
                  || filemtime($src) >  filemtime($local);
        if ($needsCopy) {
            @copy($src, $local);
            @touch($local, filemtime($src));
        }
        return file_exists($local) ? $local : null;
    }

    /**
     * Devuelve la fecha DMY (dd.MM.yyyy) del Excel a leer para un (fecha_productiva, turno).
     * TARDE X → X, NOCHE X → X-1, MAÑANA X → X-1, CENTRAL X → X-1.
     */
    public static function excelDateForShift(string $fechaYMD, string $turno): string
    {
        $d = new DateTime($fechaYMD);
        if ($turno === 'N' || $turno === 'M' || $turno === 'C') {
            $d->modify('-1 day');
        }
        return $d->format('d.m.Y');
    }

    /** Parsea un Excel local y devuelve array plano de planes. Cachea el resultado en JSON. */
    public static function parseExcel(string $localPath, string $fechaDMY): array
    {
        if (!is_dir(self::CACHE_PARSED_DIR)) @mkdir(self::CACHE_PARSED_DIR, 0777, true);
        $cacheJson = self::CACHE_PARSED_DIR . '/' . $fechaDMY . '.json';
        if (file_exists($cacheJson) && filemtime($cacheJson) >= filemtime($localPath)) {
            return json_decode(file_get_contents($cacheJson), true);
        }

        require_once __DIR__ . '/../vendor/autoload.php';
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($localPath);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['PLANIFICACIÓN']);
        $wb = $reader->load($localPath);
        $s = $wb->getSheetByName('PLANIFICACIÓN');

        $get = function ($s, $c, $r) {
            $cell = $s->getCell("$c$r");
            return $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
        };

        // === 1) Top section: (MAQUINA, ORDEN, REFERENCIA, UNIDADES, HORAS) ===
        // Claves normalizadas a Desc_maquina de cfg_maquina.
        // Aceptamos entradas con ud=0 y/o h=0 si traen referencia: el
        // planificador a veces deja la referencia ya pegada en col F antes
        // de cuantificar la cantidad/horas, y QV las muestra. Si descartamos
        // estos pedidos, las refs programadas en el cross-table desaparecen
        // de la app porque el cruce orden→pedido no encuentra entrada.
        $pedidos = []; // [maqDesc][orden] = ['ref','ud','h','ud_hora']
        $currentMaq = null;
        for ($r = 4; $r <= 360; $r++) {
            $a = $get($s, 'A', $r);
            $d = $get($s, 'D', $r);
            $f = $get($s, 'F', $r);
            if ($a && is_string($a) && strlen(trim($a)) > 0) {
                $currentMaq = self::normalize($a);
            }
            if (!$currentMaq || !is_numeric($d) || !$f) continue;
            $ref = trim((string)$f);
            if ($ref === '' || $ref === '-') continue; // placeholders sin ref real
            $ud = (float)($get($s, 'I', $r) ?? 0);
            $h  = (float)($get($s, 'N', $r) ?? 0);
            if ($ud < 0 || $h < 0) continue;
            $orden = (int)$d;
            $pedidos[$currentMaq][$orden] = [
                'ref' => $ref,
                'ud' => $ud,
                'h' => $h,
                'ud_hora' => $h > 0 ? $ud / $h : 0,
            ];
        }

        // === 2) Cross-table: localizar fila de encabezado (con valor 0.59375 en col C) ===
        $headerRow = null;
        for ($r = 300; $r <= 420; $r++) {
            $v = $s->getCell("C$r")->getValue();
            if (is_numeric($v) && abs((float)$v - 0.59375) < 0.0001) {
                $headerRow = $r;
                break;
            }
        }
        // Slots 30 min: columnas C..? (hasta 48 columnas)
        $slotCols = [];
        if ($headerRow) {
            for ($i = 0; $i < 48; $i++) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 3); // C=3
                $v = $s->getCell("{$col}$headerRow")->getValue();
                if (!is_numeric($v)) break;
                $slotCols[] = ['col' => $col, 'hora_frac' => (float)$v];
            }
        }

        // === 3) Cross-table datos: escanear todas las filas con nombre en col A ===
        $schedule = []; // [maqDesc] = [ordenPor15min..., ...] (96 slots de 15 min)
        if ($headerRow) {
            for ($r = $headerRow + 1; $r <= $headerRow + 200; $r++) {
                $maq = $get($s, 'A', $r);
                if (!$maq || !is_string($maq)) continue;
                $maq = trim($maq);
                if ($maq === '') continue;
                // Parar en las secciones de resumen al final
                if (stripos($maq, 'operari') !== false || stripos($maq, 'máquinas plan') !== false
                    || stripos($maq, 'máquinas plan') !== false || stripos($maq, 'planificadas') !== false) break;
                $maqDesc = self::normalize($maq);
                $row30 = [];
                $any = false;
                foreach ($slotCols as $slot) {
                    $v = $get($s, $slot['col'], $r);
                    $orden = (is_numeric($v) && (int)$v > 0) ? (int)$v : 0;
                    if ($orden > 0) $any = true;
                    $row30[] = $orden;
                }
                if (!$any) continue; // sin programación en esa fila
                // Desdoblar cada 30 min en dos 15 min (duplicar cada entry)
                $row15 = [];
                foreach ($row30 as $o) { $row15[] = $o; $row15[] = $o; }
                // Si ya existía (porque hay filas gemelas para una misma máquina), fusionar
                if (isset($schedule[$maqDesc])) {
                    $combined = [];
                    for ($i = 0; $i < count($row15); $i++) {
                        $a = $schedule[$maqDesc][$i] ?? 0;
                        $b = $row15[$i];
                        $combined[] = $a !== 0 ? $a : $b;
                    }
                    $schedule[$maqDesc] = $combined;
                } else {
                    $schedule[$maqDesc] = $row15;
                }
            }
        }

        $out = [
            'fecha' => $fechaDMY,
            'pedidos' => $pedidos,
            'schedule' => $schedule,
            'slot_start_frac' => 0.59375, // 14:15
            'slot_size_min' => 15,
        ];
        file_put_contents($cacheJson, json_encode($out));
        return $out;
    }

    /**
     * Dado (fecha_productiva YYYY-MM-DD, turno), devuelve el plan por hora y por máquina/ref.
     * Salida: [ ['maquina'=>'BM30', 'cod_articulo'=>'...', 'hora'=>6, 'ud'=>123.4], ... ]
     */
    public static function getPlanPorHora(string $fechaYMD, string $turno, array $horasSlots): array
    {
        // Determinar fichero Excel
        $dmy = self::excelDateForShift($fechaYMD, $turno);
        $local = self::ensureLocalCopy($dmy);
        if (!$local) return [];
        $data = self::parseExcel($local, $dmy);
        if (!$data || empty($data['schedule'])) return [];

        // Fecha base del Excel (día X cuando empieza a las 14:15)
        $fechaBase = DateTime::createFromFormat('d.m.Y', $dmy);
        if (!$fechaBase) return [];
        $slotStart0 = clone $fechaBase;
        $slotStart0->setTime(14, 15, 0); // Slot 0 del Excel = 14:15 del día dmy

        // Para cada (maqDesc, orden), contar slots 15-min en TOTAL (para unidades/período)
        $numSlotsPorMaqOrden = [];
        foreach ($data['schedule'] as $maqDesc => $slots15) {
            foreach ($slots15 as $o) {
                if ($o > 0) $numSlotsPorMaqOrden[$maqDesc][$o] = ($numSlotsPorMaqOrden[$maqDesc][$o] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($horasSlots as $hs) {
            $slotIni = new DateTime($hs['ini']);
            $slotFin = new DateTime($hs['fin']);
            $hora = (int)$hs['hora'];

            foreach ($data['schedule'] as $maqDesc => $slots15) {
                foreach ($slots15 as $idx15 => $orden) {
                    if ($orden <= 0) continue;
                    $t = clone $slotStart0;
                    $t->modify('+' . ($idx15 * 15) . ' minutes');
                    $tEnd = clone $t;
                    $tEnd->modify('+15 minutes');
                    if ($tEnd <= $slotIni || $t >= $slotFin) continue;
                    $a = max($t->getTimestamp(), $slotIni->getTimestamp());
                    $b = min($tEnd->getTimestamp(), $slotFin->getTimestamp());
                    $overlap = max(0, $b - $a);
                    if ($overlap <= 0) continue;
                    if (!isset($data['pedidos'][$maqDesc][$orden])) continue;
                    $ped = $data['pedidos'][$maqDesc][$orden];
                    $numSlots = $numSlotsPorMaqOrden[$maqDesc][$orden] ?? 1;
                    $udPorSlot = $ped['ud'] / $numSlots;
                    $udEnSolape = $udPorSlot * ($overlap / 900);
                    $key = $maqDesc . '|' . $ped['ref'] . '|' . $hora;
                    if (!isset($out[$key])) {
                        $out[$key] = [
                            'maquina' => $maqDesc,
                            'cod_articulo' => $ped['ref'],
                            'hora' => $hora,
                            'ud' => 0.0,
                        ];
                    }
                    $out[$key]['ud'] += $udEnSolape;
                }
            }
        }
        return array_values($out);
    }
}
