<?php
/**
 * tools/mant_prev_list_machines.php
 * Diagnostico: lista las maquinas distintas del .xlsx de entrada
 * y de la JSON legacy data/maintenance_completed.json. Sin tocar BD.
 */
declare(strict_types=1);
ini_set('memory_limit', '4G');
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=UTF-8');

$XLSX = __DIR__ . '/../input/mant_prev_input.xlsx';
$JSON = __DIR__ . '/../data/maintenance_completed.json';

echo "=== MAQUINAS DEL .xlsx ===\n";
if (is_file($XLSX) && extension_loaded('zip')) {
    $r = IOFactory::createReaderForFile($XLSX);
    $r->setReadDataOnly(true);
    $r->setLoadSheetsOnly(['MAQUINAS']);
    $ss = $r->load($XLSX);
    $sh = $ss->getSheetByName('MAQUINAS');
    $high = (int)$sh->getHighestRow();
    $maq = [];
    for ($i = 2; $i <= $high; $i++) {
        $v = $sh->getCell('A' . $i, false)?->getValue();
        if ($v === null || trim((string)$v) === '') continue;
        $key = trim((string)$v);
        $maq[$key] = ($maq[$key] ?? 0) + 1;
    }
    ksort($maq);
    echo "Total distintas: " . count($maq) . "\n";
    foreach ($maq as $k => $n) echo sprintf("  %-40s  %d tareas\n", $k, $n);
} else {
    echo "(.xlsx no encontrado o ext zip no cargada)\n";
}
echo "\n";

echo "=== MAQUINAS QUE FIGURABAN EN data/maintenance_completed.json (legacy) ===\n";
if (is_file($JSON)) {
    $size = filesize($JSON);
    echo "(json: {$size} bytes)\n";
    $raw = file_get_contents($JSON);
    if ($raw !== false) {
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j['items'])) {
            $maq = [];
            foreach ($j['items'] as $it) {
                $k = trim((string)($it['cod_maquina_mant'] ?? ''));
                if ($k === '') continue;
                $maq[$k] = ($maq[$k] ?? 0) + 1;
            }
            ksort($maq);
            echo "Total distintas: " . count($maq) . "\n";
            foreach ($maq as $k => $n) echo sprintf("  %-40s  %d intervenciones\n", $k, $n);
        }
    }
}
echo "\n=== FIN ===\n";
