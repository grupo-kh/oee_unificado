<?php
/**
 * Revierte RACK PARABRISAS% y RACK PUERTAS% a activa='B':
 *
 *   UPDATE mant_plan
 *      SET activa = 'B'
 *    WHERE desc_maquina ILIKE 'RACK PARABRISAS%'
 *       OR desc_maquina ILIKE 'RACK PUERTAS%'
 *
 * Esto las saca de SECUENCIA/RACKS dejando solo las RACK LUNETAS
 * originales del Excel maestro.
 *
 * Las marcas (mant_completions) ya creadas se mantienen — solo se
 * oculta la máquina del catálogo activo. Si quieres también borrarlas,
 * pasa --borrar-marcas.
 *
 * Modos:
 *   php tools/mant_revertir_racks_extra.php
 *     → DRY-RUN. Cuenta cuántas filas tocaría.
 *
 *   php tools/mant_revertir_racks_extra.php --apply
 *     → ESCRITURA. Solo cambia activa a 'B'.
 *
 *   php tools/mant_revertir_racks_extra.php --apply --borrar-marcas
 *     → ESCRITURA. También borra las marcas de mant_completions de
 *       esas máquinas (cuidado, es destructivo).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply         = in_array('--apply', $argv, true);
$borrarMarcas  = in_array('--borrar-marcas', $argv, true);

echo "Revertir RACK PARABRISAS% y RACK PUERTAS% a activa='B' · "
   . ($apply ? "ESCRITURA" : "DRY-RUN")
   . ($borrarMarcas ? " · BORRA marcas" : " · conserva marcas") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$patron = "desc_maquina ILIKE 'RACK PARABRISAS%' OR desc_maquina ILIKE 'RACK PUERTAS%'";

// 1. Conteo previo
$resumen = Db::pgFetchAll("
    SELECT COALESCE(activa, 'A') AS activa, COUNT(*) AS n
      FROM mant_plan
     WHERE $patron
     GROUP BY 1 ORDER BY 1
");
echo "Reparto actual (activa → nº tareas):" . PHP_EOL;
$total = 0;
foreach ($resumen as $r) {
    printf("  %s → %d\n", $r['activa'], $r['n']);
    $total += (int)$r['n'];
}
echo "Total tareas afectadas (PARABRISAS + PUERTAS): $total\n";

$nMaq = (int) (Db::pgFetchOne("
    SELECT COUNT(DISTINCT cod_maquina_mant) AS n
      FROM mant_plan WHERE $patron
")['n'] ?? 0);
echo "Máquinas distintas: $nMaq\n";

$nMarcas = (int) (Db::pgFetchOne("
    SELECT COUNT(*) AS n
      FROM mant_completions WHERE $patron
")['n'] ?? 0);
echo "Marcas en histórico de esas máquinas: $nMarcas\n";

if ($apply) {
    echo PHP_EOL . "Aplicando..." . PHP_EOL;

    // 1. UPDATE activa=B
    Db::pgExec("UPDATE mant_plan SET activa = 'B' WHERE $patron");
    echo "  · Tareas marcadas como activa='B'\n";

    // 2. Borrar marcas si se pide
    if ($borrarMarcas && $nMarcas > 0) {
        Db::pgExec("DELETE FROM mant_completions WHERE $patron");
        echo "  · Marcas borradas: $nMarcas\n";
    } elseif ($nMarcas > 0) {
        echo "  · Marcas conservadas: $nMarcas (usa --borrar-marcas si quieres también borrarlas)\n";
    }
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_revertir_racks_extra.php --apply"
        . ($borrarMarcas ? " --borrar-marcas" : "")
        . PHP_EOL;
}
