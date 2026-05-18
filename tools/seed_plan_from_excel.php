<?php
/**
 * Carga inicial del plan de mantenimiento (PROXIMAS REV.) desde Excel a
 * la tabla mant_plan de PostgreSQL.
 *
 * Una vez ejecutado, el Excel deja de ser fuente de verdad: la app lee
 * mant_plan de PG y el Excel queda como archivo histórico.
 *
 * Uso:
 *   php tools/seed_plan_from_excel.php [--truncate]
 *
 * Flags:
 *   --truncate  Vacía mant_plan antes de re-cargar (recomendado en re-imports
 *               para que no queden filas obsoletas).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/MaintenanceExcelReader.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';

if (!defined('MANT_USE_PG') || !MANT_USE_PG) {
    fwrite(STDERR, "MANT_USE_PG no está activo en config/database.php — abortando." . PHP_EOL);
    exit(1);
}

$opts = getopt('', ['truncate']);
$truncate = isset($opts['truncate']);

echo "Seed mant_plan desde Excel" . ($truncate ? ' [TRUNCATE PRIMERO]' : '') . PHP_EOL;
echo "Origen: " . MANT_XLSX_PATH . PHP_EOL;

if (!is_file(MANT_XLSX_PATH)) {
    fwrite(STDERR, "Excel no encontrado: " . MANT_XLSX_PATH . PHP_EOL);
    exit(2);
}

if ($truncate) {
    echo "  → vaciando mant_plan…" . PHP_EOL;
    MaintenancePlanStore::truncate();
}

$data = MaintenanceExcelReader::load();
$proximas = $data['proximas'];
echo "  · tareas en PROXIMAS REV.: " . count($proximas) . PHP_EOL;

$pdo = Db::pg();
$pdo->beginTransaction();
$count = 0;
foreach ($proximas as $p) {
    MaintenancePlanStore::upsert($p);
    $count++;
    if ($count % 500 === 0) echo "    · $count…" . PHP_EOL;
}
$pdo->commit();

$total = MaintenancePlanStore::count();
echo PHP_EOL . "Hecho. mant_plan tiene $total filas (importadas $count en esta ejecución)." . PHP_EOL;
