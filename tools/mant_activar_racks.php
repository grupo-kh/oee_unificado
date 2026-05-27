<?php
/**
 * Activa todas las tareas RACK% (PUERTAS, PARABRISAS, LUNETAS, etc.):
 *
 *   UPDATE mant_plan
 *      SET activa = 'A', alta_baja = 'ALTA'
 *    WHERE desc_maquina ILIKE 'RACK %'
 *
 * Hace que aparezcan no solo en Acciones por Máquina (ya gestionado
 * por el filtro de listMaquinasConContador) sino también en Próximas
 * Revisiones, Cumplimiento y todos los paneles que filtran por
 * activa='A' AND alta_baja='ALTA'.
 *
 * Idempotente: solo toca las que no están ya en A/ALTA.
 *
 * Modos:
 *   php tools/mant_activar_racks.php
 *     → DRY-RUN. Cuenta cuántas filas afectaría.
 *
 *   php tools/mant_activar_racks.php --apply
 *     → ESCRITURA.
 *
 *   php tools/mant_activar_racks.php --apply --like='RACK PUERTAS%'
 *     → Solo el patrón indicado (default 'RACK %').
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);
$like  = 'RACK %';
foreach ($argv as $a) {
    if (preg_match('/^--like=(.+)$/', $a, $m)) $like = $m[1];
}

echo "Activar máquinas con ILIKE '$like' · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// 1. Reparto actual
$resumen = Db::pgFetchAll("
    SELECT COALESCE(activa, 'A')      AS activa,
           COALESCE(alta_baja, 'ALTA') AS alta_baja,
           COUNT(*)                    AS n
      FROM mant_plan
     WHERE desc_maquina ILIKE :p
     GROUP BY 1, 2
     ORDER BY 1, 2
", [':p' => $like]);
echo "Reparto actual (activa | alta_baja → nº tareas):" . PHP_EOL;
$total = 0;
foreach ($resumen as $r) {
    printf("  %s | %s → %d\n", $r['activa'], $r['alta_baja'], $r['n']);
    $total += (int)$r['n'];
}
echo "Total filas: $total\n";

$pendientes = (int) (Db::pgFetchOne("
    SELECT COUNT(*) AS n
      FROM mant_plan
     WHERE desc_maquina ILIKE :p
       AND (COALESCE(activa, 'A') <> 'A' OR COALESCE(alta_baja, 'ALTA') <> 'ALTA')
", [':p' => $like])['n'] ?? 0);
echo "Tareas a activar: $pendientes\n";

if ($pendientes === 0) {
    echo "Todo ya está en A/ALTA. Nada que hacer.\n";
    exit(0);
}

if ($apply) {
    Db::pgExec("
        UPDATE mant_plan
           SET activa = 'A', alta_baja = 'ALTA'
         WHERE desc_maquina ILIKE :p
           AND (COALESCE(activa, 'A') <> 'A' OR COALESCE(alta_baja, 'ALTA') <> 'ALTA')
    ", [':p' => $like]);
    echo "  · Tareas actualizadas a A/ALTA: $pendientes\n";

    // Reparto resultante
    $resumen2 = Db::pgFetchAll("
        SELECT COALESCE(activa, 'A')      AS activa,
               COALESCE(alta_baja, 'ALTA') AS alta_baja,
               COUNT(*)                    AS n
          FROM mant_plan
         WHERE desc_maquina ILIKE :p
         GROUP BY 1, 2
         ORDER BY 1, 2
    ", [':p' => $like]);
    echo PHP_EOL . "Reparto resultante:" . PHP_EOL;
    foreach ($resumen2 as $r) {
        printf("  %s | %s → %d\n", $r['activa'], $r['alta_baja'], $r['n']);
    }
} else {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_activar_racks.php --apply" . ($like !== 'RACK %' ? " --like='$like'" : "") . PHP_EOL;
}
