<?php
/**
 * tools/mant_prev_inspect_listado.php
 * --------------------------------------------------------------
 * INSPECCION del segundo .xlsx de Mantenimiento (tiempos estimados
 * y tareas pausadas).
 *
 * Coloca el archivo en:
 *   C:\xampp\htdocs\PLAN_ATTAINMENT\input\listado_maquinas.xlsx
 *
 * URL (Apache, requiere sesion tecnica via su wrapper en views/):
 *   http://localhost/PLAN_ATTAINMENT/views/mant_prev_inspect_listado.php
 * --------------------------------------------------------------
 */

declare(strict_types=1);
ini_set('memory_limit', '4G');
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=UTF-8');

$CANDIDATES = [
    __DIR__ . '/../input/listado_maquinas.xlsx',
    __DIR__ . '/../input/Copia de Copia de Listado_Maquinas_Mantenimiento_20260519_103414.xlsx',
    __DIR__ . '/../input/Listado_Maquinas_Mantenimiento.xlsx',
];
$INPUT = null;
foreach ($CANDIDATES as $p) { if (is_file($p)) { $INPUT = $p; break; } }
if (!$INPUT) {
    echo "[ERR] No encuentro el archivo. Buscado en:\n";
    foreach ($CANDIDATES as $p) echo "  - {$p}\n";
    echo "\nCopia el archivo a C:\\xampp\\htdocs\\PLAN_ATTAINMENT\\input\\listado_maquinas.xlsx\n";
    exit(1);
}
if (!extension_loaded('zip')) {
    echo "[ERR] Extension PHP 'zip' no cargada.\n";
    exit(1);
}

echo "=== INSPECCION LISTADO MAQUINAS ===\n";
echo "Archivo: {$INPUT}\n";
echo "Tamano : " . filesize($INPUT) . " bytes\n";
echo "Mtime  : " . date('Y-m-d H:i:s', filemtime($INPUT)) . "\n\n";

$r = IOFactory::createReaderForFile($INPUT);
$r->setReadDataOnly(true);
$ss = $r->load($INPUT);

$names = $ss->getSheetNames();
echo "--- HOJAS ---\n";
foreach ($names as $i => $n) {
    $s = $ss->getSheetByName($n);
    echo sprintf("  [%d] %-30s %d filas x %s cols\n",
        $i, $n, $s->getHighestRow(), $s->getHighestColumn());
}
echo "\n";

function dumpSheet($sheet, string $label, int $maxRows = 35, int $maxCol = 18): void {
    if (!$sheet) { echo "--- '{$label}' (no existe) ---\n\n"; return; }
    $highRow = (int)$sheet->getHighestRow();
    $highCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    $useCols = min($highCol, $maxCol);

    echo "--- HOJA '{$label}'  ({$highRow}x{$highCol}) ---\n";
    echo "  (mostrando " . min($maxRows, $highRow) . " filas x {$useCols} cols)\n\n";

    $hdr = "Fila | ";
    for ($c = 1; $c <= $useCols; $c++) {
        $L = Coordinate::stringFromColumnIndex($c);
        $hdr .= sprintf("%-22s | ", $L);
    }
    echo $hdr . "\n" . str_repeat('-', strlen($hdr)) . "\n";

    for ($rr = 1; $rr <= min($maxRows, $highRow); $rr++) {
        $line = sprintf("%4d | ", $rr);
        for ($c = 1; $c <= $useCols; $c++) {
            $L = Coordinate::stringFromColumnIndex($c);
            $v = $sheet->getCell($L . $rr, false)?->getValue();
            if (is_numeric($v) && $v > 30000 && $v < 80000 && $rr > 1) {
                try {
                    $d = ExcelDate::excelToDateTimeObject((float)$v);
                    $disp = '[F]' . $d->format('Y-m-d');
                } catch (\Throwable $e) { $disp = (string)$v; }
            } else {
                $disp = is_null($v) ? '' : (string)$v;
            }
            $disp = str_replace(["\n","\r","\t"], ' ', $disp);
            if (mb_strlen($disp) > 22) $disp = mb_substr($disp, 0, 19) . '...';
            $line .= sprintf("%-22s | ", $disp);
        }
        echo $line . "\n";
    }
    echo "\n";
}

foreach (array_slice($names, 0, 4) as $n) {
    dumpSheet($ss->getSheetByName($n), $n, 35, 18);
}

echo "=== FIN ===\n";
