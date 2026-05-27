<?php
/**
 * Renombra '10238' → 'TICE K0' en TODA la aplicación:
 *   - mant_completions.cod_maquina_mant / desc_maquina
 *   - mant_plan.cod_maquina_mant / desc_maquina
 *   - mant_maquinas.cod_maquina_mant / desc_maquina
 *
 * Si '10238' aparece en desc_maquina pero el cod ya es otro, también
 * normaliza el desc a 'TICE K0'.
 *
 * Modos:
 *   php tools/mant_renombrar_10238_tice_k0.php          → DRY-RUN
 *   php tools/mant_renombrar_10238_tice_k0.php --apply  → ESCRITURA
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

$VIEJO = '10238';
$NUEVO = 'TICE K0';

echo "===========================================" . PHP_EOL;
echo " Rename '$VIEJO' → '$NUEVO'" . PHP_EOL;
echo " Modo: " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo "===========================================" . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// Conteo previo
$cMaq  = (int)(Db::pgFetchOne("SELECT COUNT(*) AS n FROM mant_maquinas WHERE cod_maquina_mant=? OR desc_maquina=?", [$VIEJO,$VIEJO])['n']??0);
$cPlan = (int)(Db::pgFetchOne("SELECT COUNT(*) AS n FROM mant_plan       WHERE cod_maquina_mant=? OR desc_maquina=?", [$VIEJO,$VIEJO])['n']??0);
$cComp = (int)(Db::pgFetchOne("SELECT COUNT(*) AS n FROM mant_completions WHERE cod_maquina_mant=? OR desc_maquina=?", [$VIEJO,$VIEJO])['n']??0);

echo PHP_EOL . "Filas afectadas:" . PHP_EOL;
echo "  mant_maquinas    : $cMaq" . PHP_EOL;
echo "  mant_plan        : $cPlan" . PHP_EOL;
echo "  mant_completions : $cComp" . PHP_EOL;

// ¿Ya existe '$NUEVO' en mant_maquinas? Si sí, hay que fusionar
$destExiste = (bool) Db::pgFetchOne("SELECT 1 FROM mant_maquinas WHERE cod_maquina_mant=?", [$NUEVO]);
echo PHP_EOL . "'$NUEVO' ya existe en mant_maquinas: " . ($destExiste ? "SÍ (fusionar)" : "NO (renombrar simple)") . PHP_EOL;

if ($cMaq === 0 && $cPlan === 0 && $cComp === 0) {
    echo PHP_EOL . "Nada que renombrar — '$VIEJO' no aparece en BD." . PHP_EOL;
    exit(0);
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:\n  php tools/mant_renombrar_10238_tice_k0.php --apply\n";
    exit(0);
}

// ─── APPLY ───
echo PHP_EOL . "Aplicando..." . PHP_EOL;

try {
    Db::pgExec("BEGIN");

    // 1. UPDATE cod_maquina_mant + desc_maquina en mant_plan y mant_completions
    $r1 = Db::pgExec("UPDATE mant_plan SET cod_maquina_mant=?, desc_maquina=? WHERE cod_maquina_mant=?",
        [$NUEVO, $NUEVO, $VIEJO]);
    echo "  ✓ mant_plan        cod: " . (int)$r1 . " filas" . PHP_EOL;

    $r2 = Db::pgExec("UPDATE mant_plan SET desc_maquina=? WHERE desc_maquina=? AND cod_maquina_mant<>?",
        [$NUEVO, $VIEJO, $NUEVO]);
    echo "  ✓ mant_plan       desc: " . (int)$r2 . " filas (solo desc viejo)" . PHP_EOL;

    $r3 = Db::pgExec("UPDATE mant_completions SET cod_maquina_mant=?, desc_maquina=? WHERE cod_maquina_mant=?",
        [$NUEVO, $NUEVO, $VIEJO]);
    echo "  ✓ mant_completions cod: " . (int)$r3 . " filas" . PHP_EOL;

    $r4 = Db::pgExec("UPDATE mant_completions SET desc_maquina=? WHERE desc_maquina=? AND cod_maquina_mant<>?",
        [$NUEVO, $VIEJO, $NUEVO]);
    echo "  ✓ mant_completions desc: " . (int)$r4 . " filas (solo desc viejo)" . PHP_EOL;

    // 2. mant_maquinas: si destino NO existe, simple UPDATE.
    //    Si destino existe, hay que borrar la fila origen (lo demás ya migró).
    if ($destExiste) {
        $r5 = Db::pgExec("DELETE FROM mant_maquinas WHERE cod_maquina_mant=?", [$VIEJO]);
        echo "  ✓ mant_maquinas (DELETE duplicado): " . (int)$r5 . " filas" . PHP_EOL;
        // Y normalizar desc del destino
        Db::pgExec("UPDATE mant_maquinas SET desc_maquina=? WHERE cod_maquina_mant=? AND desc_maquina<>?",
            [$NUEVO, $NUEVO, $NUEVO]);
    } else {
        $r5 = Db::pgExec("UPDATE mant_maquinas SET cod_maquina_mant=?, desc_maquina=? WHERE cod_maquina_mant=?",
            [$NUEVO, $NUEVO, $VIEJO]);
        echo "  ✓ mant_maquinas (UPDATE): " . (int)$r5 . " filas" . PHP_EOL;
    }

    Db::pgExec("COMMIT");
    echo PHP_EOL . "✅ COMMIT" . PHP_EOL;
} catch (Throwable $e) {
    Db::pgExec("ROLLBACK");
    fwrite(STDERR, "❌ ROLLBACK · " . $e->getMessage() . PHP_EOL);
    exit(3);
}

// Verificación
echo PHP_EOL . "Verificación:" . PHP_EOL;
$rest = (int)(Db::pgFetchOne("SELECT
    (SELECT COUNT(*) FROM mant_maquinas    WHERE cod_maquina_mant=? OR desc_maquina=?) +
    (SELECT COUNT(*) FROM mant_plan        WHERE cod_maquina_mant=? OR desc_maquina=?) +
    (SELECT COUNT(*) FROM mant_completions WHERE cod_maquina_mant=? OR desc_maquina=?) AS n
", [$VIEJO,$VIEJO,$VIEJO,$VIEJO,$VIEJO,$VIEJO])['n']??0);
echo "  Referencias residuales a '$VIEJO': $rest (esperado 0)" . PHP_EOL;

$fin = (int)(Db::pgFetchOne("SELECT
    (SELECT COUNT(*) FROM mant_plan        WHERE cod_maquina_mant=?) AS n
", [$NUEVO])['n']??0);
$comp = (int)(Db::pgFetchOne("SELECT COUNT(*) AS n FROM mant_completions WHERE cod_maquina_mant=?", [$NUEVO])['n']??0);
echo "  '$NUEVO' tras la operación: $fin tareas en plan · $comp marcas" . PHP_EOL;
