<?php
/**
 * Fusiona las dos máquinas TICE K0 duplicadas en una sola.
 *
 * Origen : '418 - Tice K0'  (donde están las 2 tareas + histórico)
 * Destino: 'TICE K0'         (la que el usuario ve en la UI, está vacía)
 *
 * Mueve tareas (mant_plan) y marcas (mant_completions) del origen al destino
 * y luego borra la fila duplicada en mant_maquinas.
 *
 * Modos:
 *   php tools/mant_fusionar_tice_k0.php          → DRY-RUN
 *   php tools/mant_fusionar_tice_k0.php --apply  → ESCRITURA
 *
 *   --destino="418 - Tice K0"   → para invertir la dirección de la fusión
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);
$destinoArg = null;
foreach ($argv as $a) {
    if (preg_match('/^--destino=(.+)$/', $a, $m)) $destinoArg = trim($m[1]);
}

$DEST = $destinoArg ?? 'TICE K0';
// Si el destino lo indicas tú, asume que el origen es el OTRO (autodetect):
//   - si destino = 'TICE K0'     → origen = '418 - Tice K0'
//   - si destino = '418 - Tice K0' → origen = 'TICE K0'
//   - si destino es otro nombre  → origen lo buscamos como cualquier otro
//                                   cod que coincida con %TICE% (uno solo).
if ($destinoArg !== null && !in_array($DEST, ['TICE K0', '418 - Tice K0'], true)) {
    // Caso libre: el usuario indicó un destino custom (ej. "10238"). Buscamos
    // todos los cod que parezcan TICE distintos del destino y fusionamos.
    $cands = Db::pgFetchAll("
        SELECT cod_maquina_mant FROM mant_maquinas
         WHERE (cod_maquina_mant ILIKE '%TICE%' OR desc_maquina ILIKE '%TICE%'
             OR cod_maquina_mant ILIKE '%10238%' OR desc_maquina ILIKE '%10238%')
           AND cod_maquina_mant <> ?
    ", [$DEST]);
    if (count($cands) === 1) {
        $ORIG = (string)$cands[0]['cod_maquina_mant'];
    } else {
        echo "Hay " . count($cands) . " candidatos a origen distintos del destino '$DEST'." . PHP_EOL;
        foreach ($cands as $c) echo "  - " . $c['cod_maquina_mant'] . PHP_EOL;
        echo "Indica el origen explícitamente o asegúrate de que solo haya uno." . PHP_EOL;
        exit(5);
    }
} else {
    $ORIG = ($DEST === 'TICE K0') ? '418 - Tice K0' : 'TICE K0';
}

echo "===========================================" . PHP_EOL;
echo " Fusión TICE K0" . PHP_EOL;
echo "   origen  : '$ORIG'" . PHP_EOL;
echo "   destino : '$DEST'" . PHP_EOL;
echo "   modo    : " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo "===========================================" . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// Conteo inicial
$origenExiste = (bool) Db::pgFetchOne("SELECT 1 FROM mant_maquinas WHERE cod_maquina_mant = ?", [$ORIG]);
$destinoExiste = (bool) Db::pgFetchOne("SELECT 1 FROM mant_maquinas WHERE cod_maquina_mant = ?", [$DEST]);

echo PHP_EOL . "Estado inicial:" . PHP_EOL;
echo "  '$ORIG' en mant_maquinas : " . ($origenExiste ? "SÍ" : "NO") . PHP_EOL;
echo "  '$DEST' en mant_maquinas : " . ($destinoExiste ? "SÍ" : "NO") . PHP_EOL;

$tareasOrig = (int)(Db::pgFetchOne("SELECT COUNT(*) AS n FROM mant_plan WHERE cod_maquina_mant = ?", [$ORIG])['n'] ?? 0);
$tareasDest = (int)(Db::pgFetchOne("SELECT COUNT(*) AS n FROM mant_plan WHERE cod_maquina_mant = ?", [$DEST])['n'] ?? 0);
$marcasOrig = (int)(Db::pgFetchOne("SELECT COUNT(*) AS n FROM mant_completions WHERE cod_maquina_mant = ?", [$ORIG])['n'] ?? 0);
$marcasDest = (int)(Db::pgFetchOne("SELECT COUNT(*) AS n FROM mant_completions WHERE cod_maquina_mant = ?", [$DEST])['n'] ?? 0);

echo PHP_EOL . "  mant_plan        · origen='$ORIG': $tareasOrig · destino='$DEST': $tareasDest" . PHP_EOL;
echo "  mant_completions · origen='$ORIG': $marcasOrig · destino='$DEST': $marcasDest" . PHP_EOL;

if (!$origenExiste && $tareasOrig === 0 && $marcasOrig === 0) {
    echo PHP_EOL . "Nada que fusionar — el origen no existe o está vacío." . PHP_EOL;
    exit(0);
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:\n  php tools/mant_fusionar_tice_k0.php --apply\n";
    echo "Para invertir dirección:\n  php tools/mant_fusionar_tice_k0.php --apply --destino=\"418 - Tice K0\"\n";
    exit(0);
}

// ─── APPLY ───
echo PHP_EOL . "Aplicando..." . PHP_EOL;

try {
    Db::pgExec("BEGIN");

    // 1. mant_plan: mover cod_maquina_mant y desc_maquina
    $r1 = Db::pgExec("
        UPDATE mant_plan
           SET cod_maquina_mant = ?,
               desc_maquina     = ?
         WHERE cod_maquina_mant = ?
    ", [$DEST, $DEST, $ORIG]);
    echo "  ✓ mant_plan: " . (int)$r1 . " filas actualizadas" . PHP_EOL;

    // 2. mant_completions: mover cod_maquina_mant y desc_maquina
    $r2 = Db::pgExec("
        UPDATE mant_completions
           SET cod_maquina_mant = ?,
               desc_maquina     = ?
         WHERE cod_maquina_mant = ?
    ", [$DEST, $DEST, $ORIG]);
    echo "  ✓ mant_completions: " . (int)$r2 . " filas actualizadas" . PHP_EOL;

    // 3. Borrar máquina duplicada de mant_maquinas
    $r3 = Db::pgExec("DELETE FROM mant_maquinas WHERE cod_maquina_mant = ?", [$ORIG]);
    echo "  ✓ mant_maquinas: " . (int)$r3 . " fila duplicada borrada" . PHP_EOL;

    // 4. Asegurar que destino existe en mant_maquinas
    if (!$destinoExiste) {
        Db::pgExec("INSERT INTO mant_maquinas (cod_maquina_mant, desc_maquina) VALUES (?, ?) ON CONFLICT DO NOTHING",
            [$DEST, $DEST]);
        echo "  ✓ mant_maquinas: creado '$DEST'" . PHP_EOL;
    }

    Db::pgExec("COMMIT");
    echo PHP_EOL . "✅ COMMIT" . PHP_EOL;
} catch (Throwable $e) {
    Db::pgExec("ROLLBACK");
    fwrite(STDERR, "❌ ERROR · ROLLBACK · " . $e->getMessage() . PHP_EOL);
    exit(3);
}

// Verificación
echo PHP_EOL . "Verificación final:" . PHP_EOL;
$tFinal = (int)(Db::pgFetchOne("SELECT COUNT(*) AS n FROM mant_plan WHERE cod_maquina_mant = ?", [$DEST])['n'] ?? 0);
$mFinal = (int)(Db::pgFetchOne("SELECT COUNT(*) AS n FROM mant_completions WHERE cod_maquina_mant = ?", [$DEST])['n'] ?? 0);
$origRest = (bool) Db::pgFetchOne("SELECT 1 FROM mant_maquinas WHERE cod_maquina_mant = ?", [$ORIG]);
echo "  '$DEST' · mant_plan: $tFinal tareas · mant_completions: $mFinal marcas" . PHP_EOL;
echo "  '$ORIG' aún existe: " . ($origRest ? "❌ SÍ" : "✓ NO") . PHP_EOL;

echo PHP_EOL . "Recarga mant_acciones.php con Ctrl+F5 y entra en '$DEST'." . PHP_EOL;
