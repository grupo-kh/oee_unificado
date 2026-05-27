<?php
/**
 * Analiza el Excel "RACKS - E66.xlsx" para enseñar:
 *   - Hojas y nº de filas
 *   - Familias únicas (col C)
 *   - Máquinas únicas (col A) por familia
 *   - Periodicidades únicas (col D)
 *   - Operarios si hay Hoja2 / Hoja de operarios
 *
 * Uso:
 *   php tools/mant_analyze_racks_e66.php "C:\tmp\RACKS - E66.xlsx"
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? null;
if (!$path || !is_file($path)) {
    fwrite(STDERR, "Uso: php tools/mant_analyze_racks_e66.php \"<ruta xlsx>\"\n");
    exit(1);
}

$book = IOFactory::load($path);
echo "Analizando: $path\n" . str_repeat('═', 70) . "\n";

echo "\nHojas del libro:\n";
foreach ($book->getAllSheets() as $s) {
    printf("  - %s (filas: %d, col máx: %s)\n",
        $s->getTitle(), $s->getHighestRow(), $s->getHighestColumn());
}

// Hoja principal de tareas (asumimos primera hoja)
$sheet = $book->getSheet(0);
$rows  = $sheet->getHighestRow();

$familias = []; $maquinasPorFamilia = []; $periodicidades = [];
$tareasPorMaqFam = []; // [familia → [maquina → nTareas]]

for ($r = 2; $r <= $rows; $r++) {
    $maq = trim((string)$sheet->getCell('A' . $r)->getValue());
    $fam = trim((string)$sheet->getCell('C' . $r)->getValue());
    $per = trim((string)$sheet->getCell('D' . $r)->getValue());
    if ($maq === '' && $fam === '') continue;

    $familias[$fam] = ($familias[$fam] ?? 0) + 1;
    $periodicidades[$per] = ($periodicidades[$per] ?? 0) + 1;
    if (!isset($maquinasPorFamilia[$fam])) $maquinasPorFamilia[$fam] = [];
    $maquinasPorFamilia[$fam][$maq] = ($maquinasPorFamilia[$fam][$maq] ?? 0) + 1;
}

echo "\nFamilias detectadas (col C) y nº de filas:\n";
ksort($familias);
foreach ($familias as $f => $n) printf("  - %-20s %4d filas\n", $f === '' ? '(vacía)' : $f, $n);

echo "\nPeriodicidades detectadas (col D):\n";
ksort($periodicidades);
foreach ($periodicidades as $p => $n) printf("  - %-20s %4d filas\n", $p === '' ? '(vacía)' : $p, $n);

echo "\nMáquinas por familia:\n";
foreach ($maquinasPorFamilia as $fam => $maqs) {
    ksort($maqs);
    printf("  ── %s · %d máquinas distintas:\n", $fam === '' ? '(sin familia)' : $fam, count($maqs));
    foreach ($maqs as $m => $nT) {
        printf("       %s  · %d tareas\n", $m, $nT);
    }
}

// Si hay segunda hoja, también la enseñamos brevemente
$nHojas = count($book->getAllSheets());
if ($nHojas > 1) {
    echo "\nContenido de la segunda hoja (primeras 20 filas):\n";
    $s2 = $book->getSheet(1);
    $r2max = min($s2->getHighestRow(), 20);
    $c2max = $s2->getHighestColumn();
    for ($r = 1; $r <= $r2max; $r++) {
        $line = [];
        for ($c = 'A'; $c <= $c2max; $c++) {
            $v = trim((string)$s2->getCell($c . $r)->getValue());
            $line[] = $v;
            if ($c === 'F') break;
        }
        echo sprintf("    %3d │ %s\n", $r, implode(' │ ', $line));
    }
}
