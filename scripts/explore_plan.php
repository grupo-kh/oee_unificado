<?php
ini_set('memory_limit', '2G');
require __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$file = $argv[1] ?? (getenv('EXCEL_BASE_PATH') ? getenv('EXCEL_BASE_PATH') . '\\fichero.xlsm' : 'ruta\\al\\fichero.xlsm');
if (!is_file($file)) { fwrite(STDERR, "Uso: php explore_plan.php <ruta_al_xlsm>\n"); exit(1); }
$reader = IOFactory::createReaderForFile($file);
$reader->setReadDataOnly(true);
$reader->setLoadSheetsOnly(['PLANIFICACIÓN']);
$wb = $reader->load($file);
$s = $wb->getSheetByName('PLANIFICACIÓN');

echo "PLANIFICACIÓN dims: {$s->getHighestColumn()} cols x {$s->getHighestRow()} rows\n\n";

// Cabeceras candidatas (filas 1-4, cols A-T)
echo "=== Filas 1-4 (buscando cabeceras) ===\n";
for ($r = 1; $r <= 4; $r++) {
    echo "Row $r: ";
    foreach (range('A', 'T') as $c) {
        $v = $s->getCell("$c$r")->getValue();
        if ($v !== null && $v !== '') echo "[$c]=" . json_encode($v, JSON_UNESCAPED_UNICODE) . " ";
    }
    echo "\n";
}

// Filas 4-15 con valores para ver datos
echo "\n=== Filas 4-14 (datos) ===\n";
for ($r = 4; $r <= 14; $r++) {
    $row = [];
    foreach (range('A', 'T') as $c) {
        $v = $s->getCell("$c$r")->getCalculatedValue();
        if ($v !== null && $v !== '') $row[$c] = is_string($v) ? mb_substr($v, 0, 20) : $v;
    }
    if (!empty($row)) echo "Row $r: " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

// Inspección del cross-table cerca de fila 367
echo "\n=== Fila 367 (cabeceras del cross-table) primeras 10 cols ===\n";
foreach (range('A', 'J') as $c) {
    $v = $s->getCell("{$c}367")->getValue();
    echo "  $c 367: " . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Fila 367 columnas D-AZ (slots horarios) ===\n";
$cols = ['D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z','AA','AB','AC','AD','AE','AF','AG','AH','AI','AJ','AK','AL','AM','AN','AO','AP','AQ','AR','AS','AT','AU','AV','AW','AX','AY','AZ'];
foreach ($cols as $c) {
    $v = $s->getCell("{$c}367")->getValue();
    if ($v !== null) echo "  $c: " . json_encode($v) . " (hhmm=" . ($v ? date('H:i', (int)(((float)$v - 1) * 86400)) : '-') . ")\n";
}

echo "\n=== Filas 368-372 datos del cross-table (cols A-F) ===\n";
for ($r = 368; $r <= 372; $r++) {
    foreach (range('A', 'F') as $c) {
        $v = $s->getCell("$c$r")->getValue();
        if ($v !== null && $v !== '') echo "  $c$r: " . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "---\n";
}
