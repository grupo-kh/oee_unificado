<?php
/**
 * Limpia las observaciones automáticas generadas por los seeds:
 *   - "Seed auditoría YYYY-MM-DD"
 *   - "Recuperación seed YYYY-MM-DD"
 *   - "Histórico generado"
 *
 * Pone observaciones a NULL. No afecta a las observaciones reales de los
 * operarios (las que tienen otro texto).
 *
 * Modos:
 *   php tools/mant_clean_obs_seed.php
 *     → DRY-RUN. Cuenta cuántas tocaría.
 *
 *   php tools/mant_clean_obs_seed.php --apply
 *     → ESCRITURA.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

echo "Limpiar observaciones de seed · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$patrones = [
    'Seed auditoría%',
    'Recuperación seed%',
    'Histórico generado%',
];

foreach ($patrones as $p) {
    $n = (int) (Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE observaciones ILIKE :p
    ", [':p' => $p])['n'] ?? 0);
    echo "  · '$p' → $n filas" . PHP_EOL;
}

$totalAffected = 0;
if ($apply) {
    echo PHP_EOL . "Aplicando..." . PHP_EOL;
    foreach ($patrones as $p) {
        Db::pgExec("
            UPDATE mant_completions
               SET observaciones = NULL
             WHERE observaciones ILIKE :p
        ", [':p' => $p]);
    }
    // Verificación
    $left = (int) (Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE observaciones ILIKE 'Seed auditoría%'
            OR observaciones ILIKE 'Recuperación seed%'
            OR observaciones ILIKE 'Histórico generado%'
    ")['n'] ?? 0);
    echo "  · Filas con esos patrones tras la limpieza: $left (debería ser 0)" . PHP_EOL;
} else {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_clean_obs_seed.php --apply" . PHP_EOL;
}
