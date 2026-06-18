<?php
/**
 * Importación puntual del mapeo paro -> Tipo Paro 1 desde ArbolParosMapex.xlsx
 * (hoja «Consulta Paros Ricardo») a la tabla PostgreSQL cfg_paro_categoria, que
 * usa Matriz 2 para agrupar los paros en categorías legibles.
 *
 * Reejecutar cuando el árbol de paros del Excel cambie. Solo escritura en la
 * tabla de mapeo; no toca datos de MAPEX.
 *
 * Uso (CLI o navegador):  php tools/importar_categoria_paros.php
 */
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../lib/Db.php';

header('Content-Type: text/plain; charset=utf-8');

$f = __DIR__ . '/../load/ArbolParosMapex.xlsx';
if (!is_file($f)) { echo "No se encuentra el Excel: $f\n"; exit; }

$r = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($f);
$r->setReadDataOnly(true);
$r->setLoadSheetsOnly(['Consulta Paros Ricardo']);
$s = $r->load($f)->getActiveSheet();

$map = [];
for ($row = 2; $row <= $s->getHighestRow(); $row++) {
    $paro = trim((string) $s->getCell("D$row")->getValue());
    $t1   = trim((string) $s->getCell("F$row")->getValue());
    if ($paro !== '' && $paro !== '--' && $t1 !== '' && $t1 !== '--' && strtoupper($t1) !== 'NULL') {
        $map[mb_strtoupper($paro, 'UTF-8')] = $t1;
    }
}
echo "Mapeos leídos del Excel: " . count($map) . "\n";

// Conexión PostgreSQL vía el helper de la app (mismo .env que el dashboard).
$pdo = Db::pg();
$pdo->exec('TRUNCATE cfg_paro_categoria');
$st = $pdo->prepare(
    'INSERT INTO cfg_paro_categoria(cod_paro, tipo_paro_1) VALUES(?, ?)
     ON CONFLICT(cod_paro) DO UPDATE SET tipo_paro_1 = EXCLUDED.tipo_paro_1'
);
$n = 0;
foreach ($map as $paro => $t1) { $st->execute([$paro, $t1]); $n++; }
echo "Insertados en PostgreSQL: $n\n";

foreach ($pdo->query('SELECT tipo_paro_1, count(*) n FROM cfg_paro_categoria GROUP BY tipo_paro_1 ORDER BY n DESC') as $row) {
    echo "  {$row['tipo_paro_1']}: {$row['n']} paros\n";
}
echo "OK\n";
