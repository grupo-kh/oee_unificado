<?php
/**
 * tools/mant_prev_inspect.php
 * --------------------------------------------------------------
 * INSPECCION del .xlsx nuevo de Mantenimiento Preventivo.
 * Vuelca por pantalla las hojas y las primeras filas de MAQUINAS y
 * TURNOS para confirmar la estructura antes de escribir la logica
 * principal de planificacion.
 *
 * Coloca el .xlsx (renombrado o no) en uno de estos sitios:
 *   1) input\mant_prev_input.xlsx   (dentro del proyecto)
 *   2) tools\mant_prev_input.xlsx
 *   3) la ruta configurada en `.env` (MANT_XLSX_PATH)
 *
 * Ejecucion (recomendada por navegador, asi Apache carga zip):
 *   http://localhost/PLAN_ATTAINMENT/tools/mant_prev_inspect.php
 *
 * o por CLI cargando zip a mano:
 *   "C:\xampp\php\php.exe" -d extension=zip tools\mant_prev_inspect.php > tools\mant_prev_inspect.txt
 * --------------------------------------------------------------
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) header('Content-Type: text/plain; charset=UTF-8');

// ---------------------------------------------------------------
// Localizar el archivo de entrada
// ---------------------------------------------------------------
$CANDIDATES = [
    __DIR__ . '/../input/mant_prev_input.xlsx',
    __DIR__ . '/mant_prev_input.xlsx',
];
// Ruta configurada por entorno (.env: MANT_XLSX_PATH), si está disponible.
$envXlsx = getenv('MANT_XLSX_PATH');
if ($envXlsx) { array_unshift($CANDIDATES, $envXlsx); }
$INPUT_FILE = null;
foreach ($CANDIDATES as $p) { if (is_file($p)) { $INPUT_FILE = $p; break; } }

if ($INPUT_FILE === null) {
    echo "[ERR] No encuentro el .xlsx. Probadas:\n";
    foreach ($CANDIDATES as $p) echo "  - {$p}\n";
    echo "\nCopia el archivo a: C:\\xampp\\htdocs\\PLAN_ATTAINMENT\\input\\mant_prev_input.xlsx\n";
    echo "y vuelve a lanzar el script.\n";
    exit(1);
}

if (!extension_loaded('zip')) {
    echo "[ERR] La extension PHP 'zip' no esta cargada (la necesita PhpSpreadsheet).\n";
    echo "  - Si lo lanzas por navegador: revisa que en el php.ini de Apache (C:\\xampp\\php\\php.ini)\n";
    echo "    este 'extension=zip' descomentado y reinicia Apache desde el panel de XAMPP.\n";
    echo "  - Si lo lanzas por CLI: usa  -d extension=zip  en la linea de comandos.\n";
    exit(1);
}

echo "=== INSPECCION ===\n";
echo "Archivo: {$INPUT_FILE}\n";
echo "Tamano:  " . filesize($INPUT_FILE) . " bytes\n";
echo "Mtime:   " . date('Y-m-d H:i:s', filemtime($INPUT_FILE)) . "\n\n";

$reader = IOFactory::createReaderForFile($INPUT_FILE);
$reader->setReadDataOnly(true);
$ss = $reader->load($INPUT_FILE);

$names = $ss->getSheetNames();

echo "--- HOJAS DISPONIBLES ---\n";
foreach ($names as $idx => $name) {
    $s = $ss->getSheetByName($name);
    echo sprintf("  [%d] %-25s  %d filas x %s columnas\n",
        $idx, $name, $s->getHighestRow(), $s->getHighestColumn());
}
echo "\n";

/**
 * Vuelca una hoja: cabecera de columnas (letra y, si parece, valor de fila 1)
 * y luego las primeras N filas de datos.
 */
function dumpSheet($sheet, string $label, int $maxRows = 25, int $maxCol = 14): void {
    if ($sheet === null) {
        echo "--- HOJA '{$label}' (no existe) ---\n\n";
        return;
    }
    $highRow = (int)$sheet->getHighestRow();
    $highCol = $sheet->getHighestColumn();
    $highColIdx = Coordinate::columnIndexFromString($highCol);
    $useCols = min($highColIdx, $maxCol);

    echo "--- HOJA '{$label}'  ({$highRow} x {$highCol}, {$highColIdx} cols)  ---\n";
    echo "  (mostrando " . min($maxRows, $highRow) . " filas x {$useCols} cols)\n\n";

    // Cabecera con letras de columna
    $hdr = "Fila | ";
    for ($c = 1; $c <= $useCols; $c++) {
        $L = Coordinate::stringFromColumnIndex($c);
        $hdr .= sprintf("%-18s | ", $L);
    }
    echo $hdr . "\n" . str_repeat('-', strlen($hdr)) . "\n";

    for ($r = 1; $r <= min($maxRows, $highRow); $r++) {
        $line = sprintf("%4d | ", $r);
        for ($c = 1; $c <= $useCols; $c++) {
            $L = Coordinate::stringFromColumnIndex($c);
            $cell = $sheet->getCell($L . $r, false);
            $v = $cell ? $cell->getValue() : null;
            // Heuristica para detectar fechas tipo serial Excel
            if (is_numeric($v) && $v > 30000 && $v < 80000 && $r > 1) {
                try {
                    $d = ExcelDate::excelToDateTimeObject((float)$v);
                    $disp = '[F]' . $d->format('Y-m-d');
                } catch (\Throwable $e) { $disp = (string)$v; }
            } else {
                $disp = is_null($v) ? '' : (string)$v;
            }
            if (mb_strlen($disp) > 18) $disp = mb_substr($disp, 0, 15) . '...';
            $line .= sprintf("%-18s | ", $disp);
        }
        echo $line . "\n";
    }
    echo "\n";
}

// ---------------------------------------------------------------
// Volcar las hojas conocidas (o las primeras 4 si no encontramos)
// ---------------------------------------------------------------
$preferred = ['MAQUINAS', 'Maquinas', 'maquinas', 'Hoja2',
              'TURNOS',   'Turnos',   'turnos',
              'PROXIMAS REV.', 'Hoja3'];
$seen = [];
foreach ($preferred as $n) {
    if (in_array($n, $names, true) && !isset($seen[$n])) {
        dumpSheet($ss->getSheetByName($n), $n, 30, 18);
        $seen[$n] = true;
    }
}
if (empty($seen)) {
    echo "[!] No encontre hojas con nombres MAQUINAS / TURNOS. Vuelco las primeras 4 hojas:\n\n";
    foreach (array_slice($names, 0, 4) as $n) dumpSheet($ss->getSheetByName($n), $n, 30, 18);
}

// ---------------------------------------------------------------
// Extra: si existe TURNOS, listar operarios (fila 1 a partir de col D) y
// listar los rangos de fechas de columnas B y C para la primera ventana
// que cubra agosto-septiembre 2025.
// ---------------------------------------------------------------
foreach (['TURNOS','Turnos','turnos'] as $tn) {
    if (!in_array($tn, $names, true)) continue;
    $tsh = $ss->getSheetByName($tn);
    echo "--- ANALISIS RAPIDO 'TURNOS' ---\n";

    // 1) Operarios en fila 1 col D+
    $highCol = $tsh->getHighestColumn();
    $highColIdx = Coordinate::columnIndexFromString($highCol);
    echo "  Operarios (fila 1 desde col D):\n    ";
    $cnt = 0;
    for ($c = 4; $c <= $highColIdx; $c++) {
        $L = Coordinate::stringFromColumnIndex($c);
        $v = $tsh->getCell($L . '1', false)?->getValue();
        if ($v === null || $v === '') continue;
        echo "[{$L}] " . str_replace(["\n","\r"], ' ', (string)$v) . '   ';
        if (++$cnt % 6 === 0) echo "\n    ";
    }
    echo "\n";

    // 2) Rangos B/C: muestra los que se solapan con 26/08 - 16/09 2025
    echo "\n  Rangos B/C que se solapan con 26/08/2025 - 16/09/2025:\n";
    $highRow = (int)$tsh->getHighestRow();
    $win0 = strtotime('2025-08-26');
    $win1 = strtotime('2025-09-16');
    for ($r = 2; $r <= $highRow; $r++) {
        $b = $tsh->getCell('B' . $r, false)?->getValue();
        $c = $tsh->getCell('C' . $r, false)?->getValue();
        if ($b === null || $c === null || $b === '' || $c === '') continue;
        $bd = is_numeric($b) ? ExcelDate::excelToDateTimeObject((float)$b)->format('Y-m-d')
                              : (string)$b;
        $cd = is_numeric($c) ? ExcelDate::excelToDateTimeObject((float)$c)->format('Y-m-d')
                              : (string)$c;
        $bts = strtotime($bd); $cts = strtotime($cd);
        if ($bts !== false && $cts !== false && $cts >= $win0 && $bts <= $win1) {
            echo "    fila {$r}:  {$bd}  ->  {$cd}\n";
        }
    }
    echo "\n";
    break; // solo procesamos la primera hoja TURNOS encontrada
}

echo "=== FIN ===\n";
