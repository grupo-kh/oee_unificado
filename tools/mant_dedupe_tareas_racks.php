<?php
/**
 * Deduplica tareas duplicadas en mant_plan dentro de una familia de RACK.
 *
 * Causa típica: una misma máquina (cod_maquina_mant) quedó cargada en dos
 * 'orden' distintos en mant_plan, así que cada (orden, tarea) es PK válida
 * pero la modal "Revisión completa" muestra las 14 tareas DUPLICADAS (28).
 *
 * Solución: para cada (cod_maquina_mant, tarea) con N > 1 filas:
 *   - Mantiene el 'orden' MENOR (asumimos = canónico).
 *   - Borra de mant_completions las marcas con los 'orden' descartados
 *     (manteniendo intactas las del orden conservado).
 *   - Borra de mant_plan las filas con los 'orden' descartados.
 *
 * Modos:
 *   php tools/mant_dedupe_tareas_racks.php
 *     → DRY-RUN sobre 'RACK LUNETAS%' (default)
 *
 *   php tools/mant_dedupe_tareas_racks.php --apply
 *     → ESCRITURA sobre 'RACK LUNETAS%'
 *
 *   php tools/mant_dedupe_tareas_racks.php --apply --like='RACK %'
 *     → ESCRITURA sobre TODOS los racks
 *
 *   php tools/mant_dedupe_tareas_racks.php --apply --like='RACK PARABRISAS%'
 *     → solo familia PARABRISAS
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);
$like  = 'RACK LUNETAS%';
foreach ($argv as $a) {
    if (preg_match('/^--like=(.+)$/', $a, $m)) $like = $m[1];
}

echo "Dedupe tareas en mant_plan (familia '$like') · "
   . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// 1. Detectar duplicados por (cod_maquina_mant, tarea)
$dupes = Db::pgFetchAll("
    SELECT cod_maquina_mant,
           tarea,
           COUNT(*)                                AS n,
           string_agg(orden::text, ',' ORDER BY orden) AS ordenes
      FROM mant_plan
     WHERE desc_maquina ILIKE :p
     GROUP BY cod_maquina_mant, tarea
    HAVING COUNT(*) > 1
     ORDER BY cod_maquina_mant, tarea
", [':p' => $like]);

if (!$dupes) {
    echo "Sin duplicados. Nada que hacer." . PHP_EOL;
    exit(0);
}

$nGrupos = count($dupes);
$nFilas  = 0;
$resumenMaq = [];
foreach ($dupes as $d) {
    $sobrantes = ((int)$d['n']) - 1;
    $nFilas   += $sobrantes;
    $cod = (string)$d['cod_maquina_mant'];
    $resumenMaq[$cod] = ($resumenMaq[$cod] ?? 0) + $sobrantes;
}

echo "Grupos (cod_maquina_mant, tarea) duplicados: $nGrupos" . PHP_EOL;
echo "Filas sobrantes a eliminar de mant_plan   : $nFilas" . PHP_EOL;

echo PHP_EOL . "Resumen por máquina (sobrantes):" . PHP_EOL;
foreach ($resumenMaq as $cod => $n) {
    printf("  · %s → %d\n", $cod, $n);
}

// Detalle de los primeros 8 grupos para ver órdenes implicados
echo PHP_EOL . "Detalle (primeros 8):" . PHP_EOL;
foreach (array_slice($dupes, 0, 8) as $d) {
    printf("  · %s · tarea %s · %d copias · ordenes [%s]\n",
        $d['cod_maquina_mant'], $d['tarea'], $d['n'], $d['ordenes']);
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_dedupe_tareas_racks.php --apply"
        . ($like !== 'RACK LUNETAS%' ? " --like='$like'" : "")
        . PHP_EOL;
    exit(0);
}

// 2. Aplicar: quedarse con menor orden, borrar el resto
echo PHP_EOL . "Aplicando..." . PHP_EOL;
$borradasPlan = 0;
$borradasComp = 0;

foreach ($dupes as $d) {
    $cod   = (string)$d['cod_maquina_mant'];
    $tarea = (string)$d['tarea'];
    $ords  = array_map('intval', explode(',', (string)$d['ordenes']));
    sort($ords);
    $keep  = (string)$ords[0];
    $drop  = array_slice($ords, 1);

    foreach ($drop as $od) {
        $od = (string)$od;

        // Borrar marcas con el orden descartado
        $rc = Db::pgExec(
            "DELETE FROM mant_completions
              WHERE cod_maquina_mant = :c
                AND tarea = :t
                AND orden = :o",
            [':c' => $cod, ':t' => $tarea, ':o' => $od]
        );
        $borradasComp += (int)$rc;

        // Borrar fila de mant_plan duplicada
        $rp = Db::pgExec(
            "DELETE FROM mant_plan
              WHERE cod_maquina_mant = :c
                AND tarea = :t
                AND orden = :o",
            [':c' => $cod, ':t' => $tarea, ':o' => $od]
        );
        $borradasPlan += (int)$rp;
    }
}

echo "  · mant_plan filas borradas:        $borradasPlan" . PHP_EOL;
echo "  · mant_completions filas borradas: $borradasComp" . PHP_EOL;

// 3. Verificación
$check = Db::pgFetchAll("
    SELECT cod_maquina_mant, tarea, COUNT(*) AS n
      FROM mant_plan
     WHERE desc_maquina ILIKE :p
     GROUP BY 1, 2
    HAVING COUNT(*) > 1
", [':p' => $like]);

if (!$check) {
    echo PHP_EOL . "✓ Verificación OK · sin duplicados (cod_maquina_mant, tarea)." . PHP_EOL;
} else {
    echo PHP_EOL . "⚠ Aún quedan " . count($check) . " grupos duplicados." . PHP_EOL;
}

// 4. Conteo final por máquina
$conteo = Db::pgFetchAll("
    SELECT cod_maquina_mant, COUNT(*) AS n
      FROM mant_plan
     WHERE desc_maquina ILIKE :p
     GROUP BY 1
     ORDER BY 1
", [':p' => $like]);
echo PHP_EOL . "Tareas por máquina tras dedupe:" . PHP_EOL;
foreach ($conteo as $r) {
    printf("  · %s → %d tareas\n", $r['cod_maquina_mant'], $r['n']);
}
