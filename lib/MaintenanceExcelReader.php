<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Lector del Excel de mantenimiento preventivo con caché en disco.
 *
 * Hojas relevantes:
 *   - "PROXIMAS REV." → plan vigente (10 col):
 *       Orden, Maquina, Grupo, DescripcionGrupo, Periodicidad,
 *       Tarea, DescripcionTarea, Activa A o B, Última revisión, Próxima revisión
 *   - "Hoja3"          → histórico de intervenciones (11 col):
 *       Orden, Maquina, Grupo, DescripcionGrupo, Periodicidad,
 *       Tarea, DescripcionTarea, Activa A o B, FECHA INI., Fecha intervención, Operario
 *
 * Las fechas vienen en formato serial Excel y se convierten a 'Y-m-d'.
 *
 * El campo "Maquina" viene como "<cod> - <descripcion>" (ej. "602 - ADHESIVADORA TRANSFER").
 * Lo partimos en {cod_maquina_mant, desc_maquina}.
 */
class MaintenanceExcelReader
{
    const CACHE_DIR = __DIR__ . '/../cache/maintenance';

    /**
     * @return array{
     *   proximas: array<int, array{
     *     orden:string, cod_maquina_mant:string, desc_maquina:string,
     *     grupo:string, desc_grupo:string, periodicidad:string,
     *     tarea:string, desc_tarea:string, activa:string,
     *     ultima_revision:?string, proxima_revision:?string
     *   }>,
     *   historico: array<int, array{
     *     orden:string, cod_maquina_mant:string, desc_maquina:string,
     *     grupo:string, desc_grupo:string, periodicidad:string,
     *     tarea:string, desc_tarea:string, activa:string,
     *     fecha_inicio:?string, fecha_intervencion:?string, operario:string
     *   }>,
     *   file_mtime:int, generated_at:int
     * }
     */
    public static function load(): array
    {
        if (!defined('MANT_XLSX_PATH')) {
            throw new RuntimeException('MANT_XLSX_PATH no definido en config/database.php');
        }
        $path = MANT_XLSX_PATH;
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Excel de mantenimiento no accesible: $path");
        }

        $mtime = filemtime($path);
        if (!is_dir(self::CACHE_DIR)) @mkdir(self::CACHE_DIR, 0777, true);
        $cacheFile = self::CACHE_DIR . '/data_' . substr(md5($path), 0, 10) . '.json';

        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)
                && isset($cached['file_mtime'], $cached['proximas'], $cached['historico'])
                && (int)$cached['file_mtime'] === $mtime) {
                return $cached;
            }
        }

        $data = self::parse($path);
        $data['file_mtime']   = $mtime;
        $data['generated_at'] = time();
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $data;
    }

    private static function parse(string $path): array
    {
        ini_set('memory_limit', '4G');

        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        // Solo cargar las hojas que nos interesan (Hoja2 con sus 435 columnas
        // de fórmulas se ignora por completo).
        $reader->setLoadSheetsOnly(['PROXIMAS REV.', 'Hoja3']);

        $ss = $reader->load($path);

        $proximas  = self::parseProximas($ss->getSheetByName('PROXIMAS REV.'));
        $historico = self::parseHistorico($ss->getSheetByName('Hoja3'));

        return ['proximas' => $proximas, 'historico' => $historico];
    }

    private static function parseProximas($sheet): array
    {
        if (!$sheet) return [];
        $out = [];
        $highestRow = $sheet->getHighestDataRow();
        for ($r = 2; $r <= $highestRow; $r++) {
            $orden = self::cell($sheet, 'A', $r);
            if ($orden === null || $orden === '') continue;
            // Filtro: solo máquinas activas (col H = 'A'); se descartan las 'B'.
            $activa = strtoupper(trim((string)self::cell($sheet, 'H', $r)));
            if ($activa !== 'A') continue;
            $maq = self::cell($sheet, 'B', $r);
            [$codM, $descM] = self::splitMaquina($maq);
            $out[] = [
                'orden'             => (string)$orden,
                'cod_maquina_mant'  => $codM,
                'desc_maquina'      => $descM,
                'grupo'             => (string)self::cell($sheet, 'C', $r),
                'desc_grupo'        => (string)self::cell($sheet, 'D', $r),
                'periodicidad'      => self::normPeriodicidad((string)self::cell($sheet, 'E', $r)),
                'tarea'             => (string)self::cell($sheet, 'F', $r),
                'desc_tarea'        => (string)self::cell($sheet, 'G', $r),
                'activa'            => $activa,
                'ultima_revision'   => self::serialToYmd(self::cell($sheet, 'I', $r)),
                'proxima_revision'  => self::serialToYmd(self::cell($sheet, 'J', $r)),
            ];
        }
        return $out;
    }

    private static function parseHistorico($sheet): array
    {
        if (!$sheet) return [];
        $out = [];
        $highestRow = $sheet->getHighestDataRow();
        for ($r = 2; $r <= $highestRow; $r++) {
            $orden = self::cell($sheet, 'A', $r);
            if ($orden === null || $orden === '') continue;
            // Filtro: solo intervenciones de máquinas activas (col H = 'A').
            $activa = strtoupper(trim((string)self::cell($sheet, 'H', $r)));
            if ($activa !== 'A') continue;
            $maq = self::cell($sheet, 'B', $r);
            [$codM, $descM] = self::splitMaquina($maq);
            $out[] = [
                'orden'              => (string)$orden,
                'cod_maquina_mant'   => $codM,
                'desc_maquina'       => $descM,
                'grupo'              => (string)self::cell($sheet, 'C', $r),
                'desc_grupo'         => (string)self::cell($sheet, 'D', $r),
                'periodicidad'       => self::normPeriodicidad((string)self::cell($sheet, 'E', $r)),
                'tarea'              => (string)self::cell($sheet, 'F', $r),
                'desc_tarea'         => (string)self::cell($sheet, 'G', $r),
                'activa'             => $activa,
                'fecha_inicio'       => self::serialToYmd(self::cell($sheet, 'I', $r)),
                'fecha_intervencion' => self::serialToYmd(self::cell($sheet, 'J', $r)),
                'operario'           => trim((string)self::cell($sheet, 'K', $r)),
            ];
        }
        return $out;
    }

    private static function cell($sheet, string $col, int $row)
    {
        $cell = $sheet->getCell($col . $row, false);
        if ($cell === null) return null;
        $v = $cell->getValue();
        return $v;
    }

    private static function splitMaquina($raw): array
    {
        if ($raw === null) return ['', ''];
        $s = trim((string)$raw);
        if ($s === '') return ['', ''];
        // Formato esperado: "602 - ADHESIVADORA TRANSFER"
        if (preg_match('/^(\S+)\s*[-–]\s*(.+)$/u', $s, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return [$s, $s];
    }

    private static function normPeriodicidad(string $s): string
    {
        return strtoupper(trim($s));
    }

    /** Convierte serial Excel (entero o float) a 'Y-m-d', null si no es válido. */
    private static function serialToYmd($v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) {
            $n = (float)$v;
            if ($n <= 0) return null;
            try {
                $dt = ExcelDate::excelToDateTimeObject($n);
                return $dt->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }
        // Si por alguna razón viene ya como string fecha, intentar parsearla.
        $ts = strtotime((string)$v);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
