<?php
/**
 * Muestra el contenido bruto de un rango de filas del xlsx para diagnóstico.
 *
 * Uso:
 *   php tools/mant_inspect_rows.php "C:\tmp\RACKS - E66.xlsx" 380 394
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? null;
$ini  = isset($argv[2]) ? (int)$argv[2] : 1;
$fin  = isset($argv[3]) ? (int)$argv[3] : ($ini + 19);

if (!$path || !is_file($path)) {
    fwrite(STDERR, "Uso: php tools/mant_inspect_rows.php \"<xlsx>\" <ini> <fin>\n"); exit(1);
}
$book = IOFactory::load($path);
$sheet = $book->getSheet(0);
$cMax  = $sheet->getHighestColumn();

echo "Filas $ini..$fin · columnas A..$cMax\n";
echo str_repeat('═', 70) . "\n";

for ($r = $ini; $r <= $fin; $r++) {
    echo "Fila $r:\n";
    for ($c = 'A'; $c <= $cMax; $c++) {
        $v = $sheet->getCell($c . $r)->getValue();
        if ($v instanceof DateTimeInterface) $v = $v->format('Y-m-d');
        $v = (string)$v;
        if (strlen($v) > 60) $v = substr($v, 0, 58) . '…';
        printf("    %s: '%s'\n", $c, $v);
        if ($c === 'L') break;
    }
}
