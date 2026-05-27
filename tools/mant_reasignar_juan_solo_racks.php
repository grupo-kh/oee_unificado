<?php
/**
 * Reasigna las marcas de mant_completions donde operario='881' (Juan Navarro)
 * Y la máquina NO es un RACK, a otro operario activo aleatorio.
 *
 * Juan solo trabaja en racks. Cualquier intervención preventiva de Juan en
 * ETIQUETADORA, PLATAFORMA, E66 o cualquier otra máquina NO-RACK queda
 * reasignada a otro operario del catálogo activo (mant_operarios.activo=TRUE
 * excluyendo a Juan).
 *
 * NO toca las marcas de Juan en racks (esas se quedan como están).
 *
 * Modos:
 *   php tools/mant_reasignar_juan_solo_racks.php
 *     → DRY-RUN: cuenta cuántas marcas tocaría y muestra ejemplos.
 *
 *   php tools/mant_reasignar_juan_solo_racks.php --apply
 *     → ESCRITURA.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);
$JUAN  = '881';

echo "Reasignar marcas de Juan (881) que no son de RACK · "
   . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// Operarios activos distintos de Juan
$otros = array_map(fn($r) => (string)$r['numero'],
    Db::pgFetchAll("SELECT numero FROM mant_operarios
                     WHERE COALESCE(activo, TRUE) = TRUE AND numero <> ?
                     ORDER BY numero", [$JUAN]));
if (!$otros) {
    fwrite(STDERR, "No hay otros operarios activos a los que reasignar.\n");
    exit(3);
}
echo "Operarios activos (excluyendo Juan): " . count($otros) . " → "
   . implode(', ', $otros) . PHP_EOL;

// Filtro: marcas de Juan en máquinas que NO empiezan por 'RACK '
$where = "operario = ? AND (desc_maquina IS NULL OR (
    desc_maquina NOT ILIKE 'RACK %' AND desc_maquina NOT ILIKE 'RACK-%' AND desc_maquina NOT ILIKE 'RACK\\_%' ESCAPE '\\'
))";

// Conteo previo
$total = (int)(Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_completions WHERE $where
", [$JUAN])['n'] ?? 0);
echo PHP_EOL . "Marcas de Juan en máquinas NO-RACK: $total" . PHP_EOL;

if ($total === 0) {
    echo "Nada que reasignar." . PHP_EOL;
    exit(0);
}

// Desglose por máquina
$desglose = Db::pgFetchAll("
    SELECT desc_maquina, COUNT(*) AS n FROM mant_completions
     WHERE $where
     GROUP BY desc_maquina ORDER BY n DESC LIMIT 15
", [$JUAN]);
echo PHP_EOL . "Top 15 máquinas afectadas:" . PHP_EOL;
foreach ($desglose as $r) {
    printf("  · %-50s · %d marcas\n", substr((string)$r['desc_maquina'], 0, 50), $r['n']);
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:\n  php tools/mant_reasignar_juan_solo_racks.php --apply\n";
    exit(0);
}

// Apply
echo PHP_EOL . "Aplicando..." . PHP_EOL;

// Cargamos los ids y vamos uno a uno asignando operario aleatorio
$ids = Db::pgFetchAll("SELECT id FROM mant_completions WHERE $where", [$JUAN]);
$nUpd = 0;
foreach ($ids as $r) {
    $nuevo = $otros[mt_rand(0, count($otros) - 1)];
    Db::pgExec("UPDATE mant_completions SET operario = ? WHERE id = ?",
        [$nuevo, $r['id']]);
    $nUpd++;
}
echo "✓ Marcas reasignadas: $nUpd" . PHP_EOL;

// Verificación
$residual = (int)(Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_completions WHERE $where
", [$JUAN])['n'] ?? 0);
echo PHP_EOL . "Residual (Juan en máquinas NO-RACK): $residual (esperado 0)" . PHP_EOL;

// Reparto resultante de los otros operarios en máquinas NO-RACK
$reparto = Db::pgFetchAll("
    SELECT operario, COUNT(*) AS n FROM mant_completions
     WHERE (desc_maquina NOT ILIKE 'RACK %' AND desc_maquina NOT ILIKE 'RACK-%' AND desc_maquina NOT ILIKE 'RACK\\_%' ESCAPE '\\')
       AND operario IN ('" . implode("','", array_map(fn($x) => addslashes($x), $otros)) . "')
     GROUP BY operario ORDER BY operario
");
echo PHP_EOL . "Reparto de los otros 7 operarios en máquinas NO-RACK:" . PHP_EOL;
foreach ($reparto as $r) {
    printf("  · %s → %d marcas\n", $r['operario'], $r['n']);
}
