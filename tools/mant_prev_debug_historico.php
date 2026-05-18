<?php
/**
 * tools/mant_prev_debug_historico.php
 * Diagnostico para ver por que el filtro de fechas en mant_historico
 * no muestra las intervenciones esperadas.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=UTF-8');

$cod   = $_GET['cod']   ?? null;   // filtra por maquina (opcional)
$desde = $_GET['desde'] ?? '2025-08-01';
$hasta = $_GET['hasta'] ?? '2026-05-07';

echo "=== DEBUG HISTORICO ===\n";
echo "Rango: {$desde} -> {$hasta}\n";
if ($cod) echo "Maquina: {$cod}\n";
echo "\n";

// 1) Resumen global
$tot = Db::pgFetchOne("SELECT COUNT(*) c FROM mant_completions");
echo "Total filas en mant_completions: {$tot['c']}\n";

$sinFi = Db::pgFetchOne("SELECT COUNT(*) c FROM mant_completions WHERE fecha_intervencion IS NULL");
echo "  · sin fecha_intervencion: {$sinFi['c']}\n";

$sinFpo = Db::pgFetchOne("SELECT COUNT(*) c FROM mant_completions WHERE fecha_proxima_original IS NULL");
echo "  · sin fecha_proxima_original: {$sinFpo['c']}\n";

// 2) Distribucion por anio
echo "\n--- Distribucion por anio (fecha_intervencion) ---\n";
$dist = Db::pgFetchAll("
    SELECT EXTRACT(YEAR FROM fecha_intervencion)::int AS anio,
           COUNT(*) AS n,
           MIN(fecha_intervencion) AS minf,
           MAX(fecha_intervencion) AS maxf
      FROM mant_completions
     WHERE fecha_intervencion IS NOT NULL
     GROUP BY anio
     ORDER BY anio
");
foreach ($dist as $r) {
    echo sprintf("  %s: %d filas (min %s, max %s)\n", $r['anio'], $r['n'], $r['minf'], $r['maxf']);
}

// 3) Filas que cumplen el rango
echo "\n--- Filas en {$desde} -> {$hasta} (filtradas por fecha_intervencion) ---\n";
$where = "fecha_intervencion BETWEEN ? AND ?";
$params = [$desde, $hasta];
if ($cod) {
    $where .= " AND cod_maquina_mant = ?";
    $params[] = $cod;
}
$enRango = Db::pgFetchOne("SELECT COUNT(*) c FROM mant_completions WHERE {$where}", $params);
echo "Total: {$enRango['c']}\n";

// 4) ¿Pasa el filtro EXISTS de mant_maquinas?
echo "\n--- Filtradas por 'maquina existe en mant_maquinas' (loadAll real) ---\n";
$conMaq = Db::pgFetchOne("
    SELECT COUNT(*) c FROM mant_completions c
    WHERE fecha_intervencion BETWEEN ? AND ?
      AND EXISTS (SELECT 1 FROM mant_maquinas mm WHERE mm.cod_maquina_mant = c.cod_maquina_mant)
", [$desde, $hasta]);
echo "Total: {$conMaq['c']}\n";

$sinMaq = Db::pgFetchAll("
    SELECT c.cod_maquina_mant, COUNT(*) AS n
      FROM mant_completions c
     WHERE fecha_intervencion BETWEEN ? AND ?
       AND NOT EXISTS (SELECT 1 FROM mant_maquinas mm WHERE mm.cod_maquina_mant = c.cod_maquina_mant)
     GROUP BY c.cod_maquina_mant
     ORDER BY n DESC
     LIMIT 20
", [$desde, $hasta]);
if ($sinMaq) {
    echo "\n[!] Hay completions con fechas en rango cuyas maquinas NO estan en mant_maquinas:\n";
    foreach ($sinMaq as $r) echo "    · {$r['cod_maquina_mant']}: {$r['n']} completions\n";
}

// 5) Sample de filas que SI pasan
echo "\n--- 10 filas de muestra (en rango, con maquina activa) ---\n";
$sample = Db::pgFetchAll("
    SELECT c.id, c.tipo, c.cod_maquina_mant, c.tarea, c.periodicidad,
           c.fecha_proxima_original, c.fecha_intervencion, c.operario, c.marcada_por,
           SUBSTRING(COALESCE(c.observaciones,''), 1, 50) AS obs
      FROM mant_completions c
     WHERE c.fecha_intervencion BETWEEN ? AND ?
       AND EXISTS (SELECT 1 FROM mant_maquinas mm WHERE mm.cod_maquina_mant = c.cod_maquina_mant)
     ORDER BY c.fecha_intervencion DESC
     LIMIT 10
", [$desde, $hasta]);
foreach ($sample as $r) {
    echo sprintf("  id=%-10s %-12s %-25s tarea=%-8s %-12s fpo=%s fi=%s op=%-10s by=%-15s obs=%s\n",
        $r['id'], $r['tipo'], substr($r['cod_maquina_mant'],0,25),
        $r['tarea'], $r['periodicidad'],
        $r['fecha_proxima_original'], $r['fecha_intervencion'],
        $r['operario'], $r['marcada_por'], $r['obs']);
}

echo "\n=== FIN ===\n";
