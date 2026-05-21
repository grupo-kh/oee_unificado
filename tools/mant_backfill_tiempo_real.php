<?php
/**
 * Backfill de tiempo_real_segundos en mant_completions.
 *
 * Recorre las intervenciones tipo 'completada' o 'recuperacion' y, si la
 * tarea tiene tiempo_estimado en mant_plan, genera un tiempo real en
 * segundos con un decalaje aleatorio ±5..10 segundos sobre el estimado.
 * El cálculo lo hace MaintenanceCompletionStore::aplicarDecalajeAleatorio()
 * para garantizar coherencia con la generación auto al marcar como hecha.
 *
 * Modos:
 *   php tools/mant_backfill_tiempo_real.php
 *     → DRY-RUN. Solo rellena las que están en NULL (no toca las que ya tienen valor).
 *
 *   php tools/mant_backfill_tiempo_real.php --apply
 *     → ESCRITURA. Rellena solo las NULL.
 *
 *   php tools/mant_backfill_tiempo_real.php --apply --force
 *     → ESCRITURA TOTAL. Regenera tiempo_real_segundos en TODAS las
 *       intervenciones completada/recuperacion (también las que ya
 *       tenían valor). Útil al cambiar la regla de generación.
 *
 *   php tools/mant_backfill_tiempo_real.php --force  (sin --apply)
 *     → DRY-RUN de la regeneración total. Muestra qué pasaría.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

$apply = in_array('--apply', $argv, true);
$force = in_array('--force', $argv, true);

echo "Backfill de tiempo_real_segundos" . PHP_EOL;
echo "  Modo: " . ($apply ? "ESCRITURA" : "DRY-RUN") . ($force ? " · FORCE (regenera todas)" : " · solo NULL") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try {
    $pdo = Db::pg();
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL);
    exit(2);
}

// Comprobar que la columna existe (la añade la migración 012)
$colExists = Db::pgFetchOne("
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'mant_completions'
       AND column_name = 'tiempo_real_segundos'
    LIMIT 1
");
if (!$colExists) {
    fwrite(STDERR, "ERROR: la columna tiempo_real_segundos no existe.\n"
        . "Aplica antes la migración: php tools/install_postgres.php\n");
    exit(3);
}

$whereExtra = $force
    ? ""
    : " AND c.tiempo_real_segundos IS NULL";

$rows = Db::pgFetchAll("
    SELECT c.external_id, c.orden, c.tarea, c.tipo, c.tiempo_real_segundos, c.hora_inicio,
           p.tiempo_estimado
      FROM mant_completions c
      LEFT JOIN mant_plan p ON p.orden = c.orden AND p.tarea = c.tarea
     WHERE c.tipo IN ('completada', 'recuperacion')
           $whereExtra
");
echo "Candidatas: " . count($rows) . PHP_EOL;

$updated = 0;
$skipped = 0;
$skippedReasons = ['sin_tiempo_estimado' => 0, 'tiempo_estimado_invalido' => 0];

/**
 * Genera una hora de inicio aleatoria entre 08:00 y 17:00 (jornada típica).
 * Formato "HH:MM" para coherencia con el campo time.
 */
function horaAleatoria(): string {
    $h = mt_rand(6, 20);
    $m = mt_rand(0, 59);
    return sprintf('%02d:%02d', $h, $m);
}

foreach ($rows as $r) {
    $te = $r['tiempo_estimado'] ?? null;
    if ($te === null || $te === '') { $skipped++; $skippedReasons['sin_tiempo_estimado']++; continue; }
    if (!is_numeric($te) || (int)$te <= 0) { $skipped++; $skippedReasons['tiempo_estimado_invalido']++; continue; }

    $teMin  = (int)$te;
    $base   = $teMin * 60;
    $tiempo = MaintenanceCompletionStore::aplicarDecalajeAleatorio($base);
    // Rellenar hora_inicio si está NULL o si forzamos
    $rellenarHora = $force || empty($r['hora_inicio']);
    $hora = $rellenarHora ? horaAleatoria() : null;

    if ($apply) {
        if ($hora !== null) {
            Db::pgExec(
                "UPDATE mant_completions SET tiempo_real_segundos = :t, hora_inicio = :h WHERE external_id = :id",
                [':t' => $tiempo, ':h' => $hora, ':id' => $r['external_id']]
            );
        } else {
            Db::pgExec(
                "UPDATE mant_completions SET tiempo_real_segundos = :t WHERE external_id = :id",
                [':t' => $tiempo, ':id' => $r['external_id']]
            );
        }
    }
    $updated++;
    if ($updated <= 10) {
        $delta = $tiempo - $base;
        $deltaTxt = ($delta >= 0 ? '+' : '') . $delta;
        $horaTxt = $hora !== null ? " · hora $hora" : '';
        printf("  · %s (orden=%s tarea=%s te=%d min) → %d s [base %d, %s]%s%s\n",
            $r['external_id'], $r['orden'], $r['tarea'], $teMin, $tiempo, $base, $deltaTxt, $horaTxt,
            $apply ? '' : ' [dry]');
    }
}

if ($updated > 10) printf("  · … y %d más\n", $updated - 10);

echo str_repeat('─', 70) . PHP_EOL;
echo "Actualizadas: $updated  · Saltadas: $skipped" . PHP_EOL;
if ($skipped > 0) {
    foreach ($skippedReasons as $k => $v) {
        if ($v > 0) echo "  - $k: $v" . PHP_EOL;
    }
}
if (!$apply && $updated > 0) {
    echo PHP_EOL . "Para aplicar los cambios:" . PHP_EOL;
    echo "  php tools/mant_backfill_tiempo_real.php --apply" . ($force ? " --force" : "") . PHP_EOL;
}
