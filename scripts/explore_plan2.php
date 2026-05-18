<?php
ini_set('memory_limit', '2G');
require __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$file = $argv[1] ?? __DIR__ . '/../cache/plan_excel/22.04.2026.xlsm';
$t0 = microtime(true);
$reader = IOFactory::createReaderForFile($file);
$reader->setReadDataOnly(true);
$reader->setLoadSheetsOnly(['PLANIFICACIÓN']);
$wb = $reader->load($file);
echo "Load: " . round(microtime(true)-$t0, 1) . "s\n";

$s = $wb->getSheetByName('PLANIFICACIÓN');
echo "Dims: {$s->getHighestColumn()} cols x {$s->getHighestRow()} rows\n\n";

// Helper: read cached value for formula cells, plain value otherwise
$cached = function($s, $c, $r) {
    $cell = $s->getCell("$c$r");
    if ($cell->isFormula()) {
        $v = $cell->getOldCalculatedValue();
    } else {
        $v = $cell->getValue();
    }
    return $v;
};

// --- 1) Datos "REV P.A." equivalente en PLANIFICACIÓN top section ---
echo "=== Datos top (A=Maquina, D=Orden, F=Refer, I=Ud_planif, N=Horas_prev) filas 4-60 ===\n";
$cuenta = 0;
for ($r = 4; $r <= 60 && $cuenta < 30; $r++) {
    $maq = $cached($s, 'A', $r);
    $orden = $cached($s, 'D', $r);
    $ref = $cached($s, 'F', $r);
    $ud  = $cached($s, 'I', $r);
    $h   = $cached($s, 'N', $r);
    if ($ref || $ud || $h) {
        echo "R$r: maq=" . json_encode($maq, JSON_UNESCAPED_UNICODE)
           . " ord=" . json_encode($orden)
           . " ref=" . json_encode($ref, JSON_UNESCAPED_UNICODE)
           . " ud=" . json_encode($ud)
           . " h=" . json_encode($h) . "\n";
        $cuenta++;
    }
}

// --- 2) Buscar la sección cross-table (fila header con valores 0.59375...) ---
echo "\n=== Buscando fila con '0.59375' en cols D-H (cross-table header) ===\n";
for ($r = 300; $r <= 400; $r++) {
    $v = $s->getCell("D$r")->getValue();
    if (is_numeric($v) && abs((float)$v - 0.59375) < 0.001) {
        echo "Found at row $r (col D = $v)\n";
        // Muestra cabecera de este row
        for ($i = 0; $i < 20; $i++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $val = $s->getCell("{$col}$r")->getValue();
            if ($val !== null && $val !== '') {
                $hhmm = '-';
                if (is_numeric($val)) {
                    $mins = round((float)$val * 24 * 60);
                    $hhmm = sprintf("%02d:%02d", intdiv($mins, 60) % 24, $mins % 60);
                }
                echo "  $col$r: " . json_encode($val) . "  ($hhmm)\n";
            }
        }
        break;
    }
}

echo "\nTotal time: " . round(microtime(true)-$t0, 1) . "s\n";
