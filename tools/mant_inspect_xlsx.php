<?php
/**
 * Inspector de un fichero XLSX. Lista las hojas, columnas y las primeras
 * filas para confirmar la estructura antes de hacer un import real.
 *
 * Uso:
 *   php tools/mant_inspect_xlsx.php "C:\ruta\al\fichero.xlsx"
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? null;
if (!$path) {
    fwrite(STDERR, "Uso: php tools/mant_inspect_xlsx.php \"<ruta xlsx>\"\n");
    exit(1);
}
if (!is_file($path)) {
    fwrite(STDERR, "No existe: $path\n"); exit(2);
}

echo "Inspeccionando: $path\n";
echo str_repeat('═', 70) . "\n";

try {
    $book = IOFactory::load($path);
} catch (Throwable $e) {
    fwrite(STDERR, "Error abriendo: " . $e->getMessage() . "\n"); exit(3);
}

foreach ($book->getAllSheets() as $idx => $sheet) {
    $title = $sheet->getTitle();
    $rows  = $sheet->getHighestRow();
    $cols  = $sheet->getHighestColumn();
    echo "\n── Hoja " . ($idx + 1) . ": " . $title . " ──\n";
    echo "    Tamaño: $rows filas × hasta columna $cols\n";

    // Muestra las primeras 12 filas (o menos)
    $max = min($rows, 12);
    for ($r = 1; $r <= $max; $r++) {
        $line = [];
        for ($c = 'A'; $c <= $cols; $c++) {
            $v = $sheet->getCell($c . $r)->getValue();
            if ($v instanceof DateTimeInterface) $v = $v->format('Y-m-d');
            $v = trim((string)$v);
            if (mb_strlen($v) > 30) $v = mb_substr($v, 0, 28) . '…';
            $line[] = $v;
            if ($c === 'L') break;  // máx 12 columnas en el preview
        }
        echo sprintf("    %3d │ %s\n", $r, implode(' │ ', $line));
    }
    if ($rows > $max) echo "    … (+" . ($rows - $max) . " filas más)\n";
}

echo "\n" . str_repeat('═', 70) . "\n";
echo "Pásame esta salida para que ajuste el importador a la estructura real.\n";
