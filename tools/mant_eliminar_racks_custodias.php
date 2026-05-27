<?php
/**
 * Elimina TODAS las máquinas RACK CUSTODIAS% de SECUENCIA/RACKS:
 *
 *   - 8 RACK CUSTODIAS TA LH (969..977 sin 970)
 *   - 8 RACK CUSTODIAS TA RH (984..993 sin 989, 990)
 *   - 3 RACK CUSTODIAS TB (1209 LH-12, 1210 RH-11, 1211 LH-11)
 *
 * Total esperado: 19 máquinas.
 *
 * Borra en cascada:
 *   1. mant_completions (histórico de intervenciones)
 *   2. mant_plan        (catálogo de tareas)
 *   3. mant_maquinas    (registro maestro)
 *
 * Modos:
 *   php tools/mant_eliminar_racks_custodias.php
 *     → DRY-RUN. Cuenta cuántas filas tocaría.
 *
 *   php tools/mant_eliminar_racks_custodias.php --apply
 *     → ESCRITURA. Borra definitivamente.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

echo "Eliminar RACK CUSTODIAS% de SECUENCIA/RACKS · "
   . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// Patrón de selección (compatible con mant_plan, mant_completions, mant_maquinas)
$patronMaq  = "desc_maquina ILIKE 'RACK CUSTODIAS%'";
$patronComp = "desc_maquina ILIKE 'RACK CUSTODIAS%'";

// ── 1. Listado previo ──
$maquinas = Db::pgFetchAll("
    SELECT cod_maquina_mant, desc_maquina
      FROM mant_maquinas
     WHERE $patronMaq
     ORDER BY desc_maquina, cod_maquina_mant
");
$nMaq = count($maquinas);

$nTareas = (int) (Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_plan WHERE $patronMaq
")['n'] ?? 0);

$nMarcas = (int) (Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_completions WHERE $patronComp
")['n'] ?? 0);

echo "Máquinas RACK CUSTODIAS% encontradas: $nMaq\n";
foreach ($maquinas as $m) {
    printf("  · %s\n", $m['desc_maquina']);
}
echo "Tareas en mant_plan         : $nTareas\n";
echo "Marcas en mant_completions  : $nMarcas\n";

if ($nMaq === 0 && $nTareas === 0 && $nMarcas === 0) {
    echo "\nNada que borrar. Ya no quedan RACK CUSTODIAS.\n";
    exit(0);
}

// ── 2. Aplicar ──
if ($apply) {
    echo PHP_EOL . "Aplicando..." . PHP_EOL;

    // 1) Histórico
    $rComp = Db::pgExec("DELETE FROM mant_completions WHERE $patronComp");
    echo "  · mant_completions borradas: " . (int)$rComp . PHP_EOL;

    // 2) Catálogo de tareas
    $rPlan = Db::pgExec("DELETE FROM mant_plan WHERE $patronMaq");
    echo "  · mant_plan borradas       : " . (int)$rPlan . PHP_EOL;

    // 3) Maestro de máquinas
    $rMaq = Db::pgExec("DELETE FROM mant_maquinas WHERE $patronMaq");
    echo "  · mant_maquinas borradas   : " . (int)$rMaq . PHP_EOL;

    // Verificación
    $check = (int) (Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_maquinas WHERE $patronMaq
    ")['n'] ?? 0);
    echo PHP_EOL . "Quedan en mant_maquinas: $check (esperado 0)\n";
} else {
    echo PHP_EOL . "Para aplicar definitivamente:" . PHP_EOL;
    echo "  php tools/mant_eliminar_racks_custodias.php --apply" . PHP_EOL;
}
