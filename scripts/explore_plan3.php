<?php
ini_set('memory_limit', '2G');
require __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$file = $argv[1] ?? __DIR__ . '/../cache/plan_excel/22.04.2026.xlsm';
$reader = IOFactory::createReaderForFile($file);
$reader->setReadDataOnly(true);
$reader->setLoadSheetsOnly(['PLANIFICACIÓN']);
$wb = $reader->load($file);
$s = $wb->getSheetByName('PLANIFICACIÓN');

// Buscar cross-table header (valor 0.59375 cerca en cualquier fila)
echo "Buscando 0.59375 en toda la hoja (barre A-Z filas 1-518):\n";
for ($r = 1; $r <= $s->getHighestRow(); $r++) {
    for ($i = 0; $i < 30; $i++) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        $v = $s->getCell("$col$r")->getValue();
        if (is_numeric($v) && abs((float)$v - 0.59375) < 0.0001) {
            echo "  Encontrado 0.59375 en $col$r\n";
        }
    }
}

// También mira si hay fila header con texto tipo "HORA" o "GANT" o "Máquina"
echo "\nBuscando header del cross-table (palabras GANT, HORA, MÁQUINA en col A-C, filas 300-500):\n";
for ($r = 300; $r <= 500; $r++) {
    foreach (['A','B','C','D'] as $c) {
        $v = $s->getCell("$c$r")->getValue();
        if ($v && is_string($v) && preg_match('/GANT|HORA|M[ÁA]QUINA|PLANIFI/i', $v)) {
            echo "  R$r $c: " . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}

// Scan filas 360-375 enteras
echo "\nFilas 360-380 primeras 10 cols:\n";
for ($r = 360; $r <= 380; $r++) {
    $cells = [];
    foreach (range('A', 'J') as $c) {
        $v = $s->getCell("$c$r")->getValue();
        if ($v !== null && $v !== '') {
            if (is_float($v) && $v < 2) $cells[$c] = sprintf("%.6f", $v);
            else $cells[$c] = is_string($v) ? mb_substr($v, 0, 15) : $v;
        }
    }
    if (!empty($cells)) echo "R$r: " . json_encode($cells, JSON_UNESCAPED_UNICODE) . "\n";
}
