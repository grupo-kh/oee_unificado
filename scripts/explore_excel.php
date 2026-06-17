<?php
/**
 * Script de exploración: inspecciona estructura de hoja "REV P.A." y "PLANIFICACIÓN"
 * de un Excel de planificación diaria, para confirmar columnas y cabeceras antes
 * de integrar en el API.
 */
ini_set('memory_limit', '2G');
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$file = $argv[1] ?? (getenv('EXCEL_BASE_PATH') ? getenv('EXCEL_BASE_PATH') . '\\fichero.xlsm' : 'ruta\\al\\fichero.xlsm');
if (!is_file($file)) { fwrite(STDERR, "Uso: php explore_excel.php <ruta_al_xlsm> [hoja]\n"); exit(1); }
$sheetToLoad = $argv[2] ?? null;
echo "File: $file\n";
if ($sheetToLoad) echo "Sheet filter: $sheetToLoad\n";

$reader = IOFactory::createReaderForFile($file);
$reader->setReadDataOnly(true);
if ($sheetToLoad) $reader->setLoadSheetsOnly([$sheetToLoad]);
$wb = $reader->load($file);

echo "Sheets:\n";
foreach ($wb->getSheetNames() as $n) echo "  - $n\n";

// Hoja REV P.A.
if ($wb->sheetNameExists('REV P.A.')) {
    $s = $wb->getSheetByName('REV P.A.');
    echo "\n=== 'REV P.A.' ({$s->getHighestRow()} filas x {$s->getHighestColumn()} cols) ===\n";
    // Primeras 3 filas completas + primeras 10 de datos
    for ($r = 1; $r <= min(13, $s->getHighestRow()); $r++) {
        $row = [];
        foreach (range('A', $s->getHighestColumn()) as $c) {
            $v = $s->getCell("$c$r")->getValue();
            if ($v !== null && $v !== '') $row[$c] = is_string($v) ? mb_substr($v, 0, 30) : $v;
        }
        if (!empty($row)) echo "Row $r: " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// Hoja PLANIFICACIÓN: header is 367 lines según QV. Inspeccionar línea 367-370
if ($wb->sheetNameExists('PLANIFICACIÓN')) {
    $s = $wb->getSheetByName('PLANIFICACIÓN');
    echo "\n=== 'PLANIFICACIÓN' ({$s->getHighestRow()} filas x {$s->getHighestColumn()} cols) ===\n";
    echo "Primeros 5 valores no nulos de cols A-G en fila 367 (header QV):\n";
    for ($c = 'A'; $c <= 'G'; $c++) {
        $v = $s->getCell("{$c}367")->getValue();
        echo "  $c 367: " . json_encode($v) . "\n";
    }
    // Primeras filas de datos tras header
    echo "Filas 368-370 (datos):\n";
    for ($r = 368; $r <= 370; $r++) {
        for ($c = 'A'; $c <= 'H'; $c++) {
            $v = $s->getCell("$c$r")->getValue();
            if ($v !== null && $v !== '') echo "  $c$r: " . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}
