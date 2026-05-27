<?php
/**
 * Regenera SOLO la hora de inicio (hora_inicio) de las intervenciones
 * existentes con la distribución por turnos:
 *
 *   - Tarde   (14:00–21:59) → 50 %
 *   - Mañana  (06:00–13:59) → 35 %
 *   - Noche   (22:00–05:59) → 15 %
 *
 * NO toca tiempo_real_segundos ni ningún otro campo. Útil cuando solo
 * quieres redistribuir las horas sin perder los tiempos reales ya
 * generados.
 *
 * Modos:
 *   php tools/mant_regenerate_horas.php
 *     → DRY-RUN. Te dice cuántas tocaría y un sample.
 *
 *   php tools/mant_regenerate_horas.php --apply
 *     → Reescribe hora_inicio en todas las marcas tipo completada o
 *       recuperacion.
 *
 *   php tools/mant_regenerate_horas.php --apply --solo-nulos
 *     → Solo escribe en las que tengan hora_inicio NULL.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

$apply     = in_array('--apply', $argv, true);
$soloNulos = in_array('--solo-nulos', $argv, true);

echo "Regenerar hora_inicio · " . ($apply ? "ESCRITURA" : "DRY-RUN")
   . ($soloNulos ? " · solo NULL" : " · todas") . PHP_EOL;
echo "Distribución: tarde 50% · mañana 35% · noche 15%" . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$where = "tipo IN ('completada', 'recuperacion')";
if ($soloNulos) $where .= " AND hora_inicio IS NULL";

$rows = Db::pgFetchAll("SELECT external_id FROM mant_completions WHERE $where");
echo "Intervenciones afectadas: " . count($rows) . PHP_EOL;

$updated = 0;
$samples = [];
foreach ($rows as $r) {
    $hora = MaintenanceCompletionStore::horaTurnoAleatoria();
    if ($apply) {
        Db::pgExec(
            "UPDATE mant_completions SET hora_inicio = :h WHERE external_id = :id",
            [':h' => $hora, ':id' => $r['external_id']]
        );
    }
    $updated++;
    if (count($samples) < 10) $samples[] = $r['external_id'] . ' → ' . $hora;
}

echo "Actualizadas: $updated" . PHP_EOL;
if ($samples) {
    echo PHP_EOL . "Sample de horas asignadas:" . PHP_EOL;
    foreach ($samples as $s) echo "  · $s" . PHP_EOL;
}

// Distribución real comprobada en el sample
if ($apply && $updated > 0) {
    $counts = Db::pgFetchAll("
        SELECT CASE
                 WHEN EXTRACT(HOUR FROM hora_inicio) BETWEEN 14 AND 21 THEN 'tarde'
                 WHEN EXTRACT(HOUR FROM hora_inicio) BETWEEN 6  AND 13 THEN 'manana'
                 ELSE 'noche'
               END AS turno,
               COUNT(*) AS n
          FROM mant_completions
         WHERE $where AND hora_inicio IS NOT NULL
         GROUP BY 1
         ORDER BY 1
    ");
    echo PHP_EOL . "Reparto resultante:" . PHP_EOL;
    $tot = 0;
    foreach ($counts as $c) $tot += (int)$c['n'];
    foreach ($counts as $c) {
        $pct = $tot > 0 ? round($c['n'] / $tot * 100, 1) : 0;
        printf("  · %-7s %6d (%5.1f%%)\n", $c['turno'], $c['n'], $pct);
    }
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar de verdad:" . PHP_EOL;
    echo "  php tools/mant_regenerate_horas.php --apply" . ($soloNulos ? " --solo-nulos" : "") . PHP_EOL;
}
