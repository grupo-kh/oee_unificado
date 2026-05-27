<?php
/**
 * Pausa los racks RACK PUERTAS TB TRA (LH + RH) y BORRA su histórico.
 *
 * Familias afectadas:
 *   - RACK PUERTAS TB TRA LH%
 *   - RACK PUERTAS TB TRA RH%
 *
 * Misma estrategia robusta que mant_pausar_racks_ta.php:
 *   1. Busca los cod_maquina_mant candidatos en mant_maquinas mirando
 *      AMBOS campos (desc_maquina OR cod_maquina_mant).
 *   2. UPDATE mant_plan SET fecha_pausado = CURRENT_DATE
 *      DELETE FROM mant_completions
 *      …usando cod_maquina_mant como clave (no depende de desc_maquina
 *      en mant_plan).
 *
 * Lo que NO toca:
 *   - PUERTAS TA (DEL / TRA), PUERTAS TB DEL → siguen activos
 *   - El resto de familias (CUSTODIAS, LUNETAS, PARABRISAS, …)
 *
 * Modos:
 *   php tools/mant_pausar_racks_puertas_tb_tra.php
 *     → DRY-RUN
 *   php tools/mant_pausar_racks_puertas_tb_tra.php --apply
 *     → ESCRITURA
 *   php tools/mant_pausar_racks_puertas_tb_tra.php --verbose
 *     → Imprime cada cod_maquina_mant detectado
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply   = in_array('--apply',   $argv, true);
$verbose = in_array('--verbose', $argv, true);

$patrones = [
    'RACK PUERTAS TB TRA LH%',
    'RACK PUERTAS TB TRA RH%',
];

echo "Pausar RACK PUERTAS TB TRA · " . ($apply ? "ESCRITURA" : "DRY-RUN")
   . ($verbose ? " · verbose" : "") . PHP_EOL;
echo "Patrones: " . implode(' · ', $patrones) . PHP_EOL;
echo str_repeat('═', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// ── 1. Verificar columna fecha_pausado ──
$colExists = (bool)Db::pgFetchOne("
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'mant_plan' AND column_name = 'fecha_pausado'
     LIMIT 1
");
if (!$colExists) {
    fwrite(STDERR, "ERROR: la columna mant_plan.fecha_pausado NO existe.\n");
    exit(3);
}
echo "✓ mant_plan.fecha_pausado existe" . PHP_EOL;

$hoy = date('Y-m-d');

// ── 2. Recolectar cod_maquina_mant ──
$codsTotales = [];
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
    printf("  Patrón '%-30s' → %d máquinas en mant_maquinas\n", $pat, count($codsPat));
    if ($verbose) {
        foreach ($codsPat as $c => $_) echo "      · $c\n";
    }
}

if (!$codsTotales) {
    echo PHP_EOL . "❌ Ninguna máquina coincide. Nada que hacer." . PHP_EOL;
    echo "   Verifica: SELECT cod_maquina_mant FROM mant_maquinas WHERE cod_maquina_mant ILIKE 'RACK PUERTAS TB TRA%';" . PHP_EOL;
    exit(0);
}

$totalCods = count($codsTotales);
echo PHP_EOL . "Total cod_maquina_mant únicos a procesar: $totalCods" . PHP_EOL;

// ── 3. Conteo previo ──
$placeholders = implode(',', array_fill(0, count($codsTotales), '?'));
$paramsList   = array_keys($codsTotales);

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
    echo "  php tools/mant_pausar_racks_puertas_tb_tra.php --apply" . PHP_EOL;
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

// ── 5. Verificación ──
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
    echo PHP_EOL . "✓ TODO CORRECTO. Recarga mant_acciones.php (Ctrl+F5)." . PHP_EOL;
} else {
    echo PHP_EOL . "⚠ Algo se quedó sin tocar. Lanza con --verbose para depurar." . PHP_EOL;
}
