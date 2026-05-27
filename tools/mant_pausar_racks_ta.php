<?php
/**
 * Pausa los racks TA (CUSTODIAS, LUNETAS, PARABRISAS) y borra su histórico.
 *
 * Estrategia robusta (corrige el bug de la versión anterior que dependía
 * de que mant_plan.desc_maquina coincidiera con mant_maquinas.desc_maquina):
 *
 *   1. Buscamos las máquinas candidatas en mant_maquinas mirando AMBOS
 *      campos (desc_maquina OR cod_maquina_mant) por si una BD tiene los
 *      datos en uno u otro.
 *   2. Con la lista de cod_maquina_mant resultante (clave estable),
 *      hacemos el UPDATE/DELETE en mant_plan y mant_completions JOIN-ando
 *      por cod_maquina_mant — no depende de que las descripciones cuadren.
 *
 * Patrones por defecto (LH + RH automáticamente porque acaban en ' TA ' +
 * cualquier sufijo o sin sufijo):
 *   - RACK CUSTODIAS TA%
 *   - RACK LUNETAS TA%
 *   - RACK PARABRISAS TA%
 *
 * Lo que NO toca:
 *   - RACK CUSTODIAS / LUNETAS / PARABRISAS  *TB*  (LH+RH)
 *   - RACK PUERTAS *
 *   - Cualquier máquina no-rack
 *
 * Modos:
 *   php tools/mant_pausar_racks_ta.php
 *     → DRY-RUN
 *   php tools/mant_pausar_racks_ta.php --apply
 *     → ESCRITURA
 *   php tools/mant_pausar_racks_ta.php --verbose
 *     → Imprime CADA máquina/cod_maquina_mant que detecta
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply   = in_array('--apply',   $argv, true);
$verbose = in_array('--verbose', $argv, true);

$patrones = [
    'RACK CUSTODIAS TA%',
    'RACK LUNETAS TA%',
    'RACK PARABRISAS TA%',
];
foreach ($argv as $a) {
    if (preg_match('/^--like=(.+)$/', $a, $m)) $patrones = [$m[1]];
}

echo "Pausar racks TA · " . ($apply ? "ESCRITURA" : "DRY-RUN")
   . ($verbose ? " · verbose" : "") . PHP_EOL;
echo "Patrones: " . implode(' · ', $patrones) . PHP_EOL;
echo str_repeat('═', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// ── 1. Verificar que la columna fecha_pausado existe ──
$colExists = (bool)Db::pgFetchOne("
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'mant_plan' AND column_name = 'fecha_pausado'
     LIMIT 1
");
if (!$colExists) {
    fwrite(STDERR, "ERROR: la columna mant_plan.fecha_pausado NO existe. "
        . "Aplica primero la migración correspondiente.\n");
    exit(3);
}
echo "✓ mant_plan.fecha_pausado existe" . PHP_EOL;

$hoy = date('Y-m-d');

// ── 2. Recolectar codigos de máquina afectados ──
$codsTotales = []; // cod_maquina_mant => desc_maquina
foreach ($patrones as $pat) {
    $rows = Db::pgFetchAll("
        SELECT cod_maquina_mant, desc_maquina
          FROM mant_maquinas
         WHERE desc_maquina ILIKE :p OR cod_maquina_mant ILIKE :p
         ORDER BY desc_maquina, cod_maquina_mant
    ", [':p' => $pat]);
    $codsPat = [];
    foreach ($rows as $r) {
        $cod = (string)$r['cod_maquina_mant'];
        $codsTotales[$cod] = (string)$r['desc_maquina'];
        $codsPat[$cod] = true;
    }
    printf("  Patrón '%-25s' → %d máquinas en mant_maquinas\n", $pat, count($codsPat));
    if ($verbose) {
        foreach ($codsPat as $c => $_) {
            echo "      · $c\n";
        }
    }
}

if (!$codsTotales) {
    echo PHP_EOL . "❌ Ninguna máquina coincide con los patrones. Nada que hacer." . PHP_EOL;
    echo "   Comprueba en tu BD: SELECT cod_maquina_mant FROM mant_maquinas WHERE cod_maquina_mant ILIKE 'RACK CUSTODIAS TA%';" . PHP_EOL;
    exit(0);
}

$totalCods = count($codsTotales);
echo PHP_EOL . "Total cod_maquina_mant únicos a procesar: $totalCods" . PHP_EOL;

// ── 3. Conteo previo en mant_plan y mant_completions usando cod_maquina_mant ──
// Usamos un IN (...) con placeholders posicionales: PDO lo soporta bien.
$placeholders = implode(',', array_fill(0, count($codsTotales), '?'));
$paramsList = array_keys($codsTotales);

$tareasTotal = (int)(Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_plan
     WHERE cod_maquina_mant IN ($placeholders)
", $paramsList)['n'] ?? 0);

$tareasAPausar = (int)(Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_plan
     WHERE cod_maquina_mant IN ($placeholders)
       AND fecha_pausado IS NULL
", $paramsList)['n'] ?? 0);

$marcasTotal = (int)(Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_completions
     WHERE cod_maquina_mant IN ($placeholders)
", $paramsList)['n'] ?? 0);

echo str_repeat('─', 75) . PHP_EOL;
echo "Tareas en mant_plan de estas máquinas:        $tareasTotal" . PHP_EOL;
echo "  · ya pausadas (no se tocan):                " . ($tareasTotal - $tareasAPausar) . PHP_EOL;
echo "  · pendientes de pausar:                     $tareasAPausar" . PHP_EOL;
echo "Marcas en mant_completions a BORRAR:          $marcasTotal" . PHP_EOL;

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_pausar_racks_ta.php --apply" . PHP_EOL;
    exit(0);
}

// ── 4. APPLY ──
echo PHP_EOL . "Aplicando..." . PHP_EOL;

$nPaused = (int)Db::pgExec("
    UPDATE mant_plan
       SET fecha_pausado = ?
     WHERE cod_maquina_mant IN ($placeholders)
       AND fecha_pausado IS NULL
", array_merge([$hoy], $paramsList));
echo "  · mant_plan tareas pausadas (fecha_pausado=$hoy): $nPaused" . PHP_EOL;

$nDeleted = (int)Db::pgExec("
    DELETE FROM mant_completions
     WHERE cod_maquina_mant IN ($placeholders)
", $paramsList);
echo "  · mant_completions marcas borradas: $nDeleted" . PHP_EOL;

// ── 5. Verificación final ──
$pendientes = (int)(Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_plan
     WHERE cod_maquina_mant IN ($placeholders)
       AND fecha_pausado IS NULL
", $paramsList)['n'] ?? 0);

$marcasRest = (int)(Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_completions
     WHERE cod_maquina_mant IN ($placeholders)
", $paramsList)['n'] ?? 0);

echo PHP_EOL . "Verificación post-apply:" . PHP_EOL;
echo "  · Tareas sin pausar:           $pendientes (esperado 0)" . PHP_EOL;
echo "  · Marcas restantes en hist.:   $marcasRest (esperado 0)" . PHP_EOL;

if ($pendientes === 0 && $marcasRest === 0) {
    echo PHP_EOL . "✓ TODO CORRECTO. Recarga mant_acciones.php (Ctrl+F5) y comprueba." . PHP_EOL;
} else {
    echo PHP_EOL . "⚠ Algo se quedó sin tocar. Lanza con --verbose para depurar." . PHP_EOL;
}
