<?php
/**
 * Activa todas las tareas de RACK PARABRISAS:
 *
 *   UPDATE mant_plan
 *      SET activa = 'A', alta_baja = 'ALTA'
 *    WHERE desc_maquina ILIKE 'RACK PARABRISAS%'
 *
 * Esto hace que esas máquinas aparezcan no solo en Acciones por Máquina
 * (donde ya las hace visibles el cambio en listMaquinasConContador),
 * sino también en Próximas Revisiones, Cumplimiento y todos los paneles
 * que filtran por activa='A' AND alta_baja='ALTA'.
 *
 * Modos:
 *   php tools/mant_activar_parabrisas.php
 *     → DRY-RUN. Cuenta cuántas filas afectaría.
 *
 *   php tools/mant_activar_parabrisas.php --apply
 *     → ESCRITURA.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

echo "Activar RACK PARABRISAS · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// 1. Conteo previo
$resumen = Db::pgFetchAll("
    SELECT COALESCE(activa, 'A')      AS activa,
           COALESCE(alta_baja, 'ALTA') AS alta_baja,
           COUNT(*)                   AS n
      FROM mant_plan
     WHERE desc_maquina ILIKE 'RACK PARABRISAS%'
     GROUP BY 1, 2
     ORDER BY 1, 2
");
echo "Reparto actual (activa | alta_baja → nº tareas):" . PHP_EOL;
$total = 0;
foreach ($resumen as $r) {
    printf("  %s | %s → %d\n", $r['activa'], $r['alta_baja'], $r['n']);
    $total += (int)$r['n'];
}
echo "Total filas RACK PARABRISAS: $total\n";

// 2. Cuántas hay que tocar (las que no están A + ALTA)
$pendientes = (int) (Db::pgFetchOne("
    SELECT COUNT(*) AS n
      FROM mant_plan
     WHERE desc_maquina ILIKE 'RACK PARABRISAS%'
       AND (COALESCE(activa, 'A') <> 'A' OR COALESCE(alta_baja, 'ALTA') <> 'ALTA')
")['n'] ?? 0);
echo "Tareas a activar: $pendientes\n";

if ($pendientes === 0) {
    echo "Todo ya está en A/ALTA. Nada que hacer.\n";
    exit(0);
}

// 3. UPDATE si se aplica
if ($apply) {
    Db::pgExec("
        UPDATE mant_plan
           SET activa = 'A', alta_baja = 'ALTA'
         WHERE desc_maquina ILIKE 'RACK PARABRISAS%'
           AND (COALESCE(activa, 'A') <> 'A' OR COALESCE(alta_baja, 'ALTA') <> 'ALTA')
    ");
    echo "  · Tareas actualizadas a A/ALTA: $pendientes\n";

    // 4. Mostrar el nuevo reparto
    $resumen2 = Db::pgFetchAll("
        SELECT COALESCE(activa, 'A')      AS activa,
               COALESCE(alta_baja, 'ALTA') AS alta_baja,
               COUNT(*)                   AS n
          FROM mant_plan
         WHERE desc_maquina ILIKE 'RACK PARABRISAS%'
         GROUP BY 1, 2
         ORDER BY 1, 2
    ");
    echo PHP_EOL . "Reparto resultante:" . PHP_EOL;
    foreach ($resumen2 as $r) {
        printf("  %s | %s → %d\n", $r['activa'], $r['alta_baja'], $r['n']);
    }
} else {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_activar_parabrisas.php --apply" . PHP_EOL;
}
