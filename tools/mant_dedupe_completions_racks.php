<?php
/**
 * Dedupe DEFINITIVO de tareas duplicadas en el histórico de RACKS.
 *
 * Trabaja sobre mant_completions (no sobre mant_plan), que es lo que
 * realmente lee api/mant_historico.php para construir la lista
 * "Revisión completa · N tareas". La clave de agrupación allí es
 * (orden, tarea), así que si una misma máquina tiene marcas con la
 * misma 'tarea' bajo dos 'orden' distintos, el modal muestra 2× tareas.
 *
 * Lógica:
 *   Para cada (cod_maquina_mant, tarea) con marcas en >1 'orden':
 *     1. Resuelve el ORDEN CANÓNICO:
 *        - El primer 'orden' que aparezca en mant_plan para esa
 *          (cod_maquina_mant, tarea). Si hay varios, el menor.
 *        - Si ninguno está en mant_plan, el menor 'orden' visto.
 *     2. Borra de mant_completions todas las marcas con un 'orden'
 *        distinto del canónico (para esa máquina + tarea).
 *     3. Borra de mant_plan las filas duplicadas con 'orden' distinto.
 *
 * Cubre TODAS las familias de RACK por defecto:
 *   - RACK CUSTODIAS, LUNETAS, PARABRISAS, PUERTAS, …
 *
 * Modos:
 *   php tools/mant_dedupe_completions_racks.php
 *     → DRY-RUN sobre 'RACK %' (todas las familias).
 *
 *   php tools/mant_dedupe_completions_racks.php --apply
 *     → ESCRITURA sobre todas las familias.
 *
 *   php tools/mant_dedupe_completions_racks.php --apply --like='RACK LUNETAS%'
 *     → Solo una familia.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);
$like  = 'RACK %';
foreach ($argv as $a) {
    if (preg_match('/^--like=(.+)$/', $a, $m)) $like = $m[1];
}

echo "Dedupe mant_completions (familia '$like') · "
   . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// 1. Detectar (cod_maquina_mant, tarea) con marcas en >1 'orden'
$dupes = Db::pgFetchAll("
    SELECT cod_maquina_mant,
           tarea,
           COUNT(DISTINCT orden::text)                                AS n_ordenes,
           string_agg(DISTINCT orden::text, ',' ORDER BY orden::text) AS ordenes,
           COUNT(*)                                                   AS n_marcas
      FROM mant_completions
     WHERE desc_maquina ILIKE :p
     GROUP BY cod_maquina_mant, tarea
    HAVING COUNT(DISTINCT orden::text) > 1
     ORDER BY cod_maquina_mant, tarea
", [':p' => $like]);

if (!$dupes) {
    echo "Sin duplicados en mant_completions. Nada que hacer." . PHP_EOL;

    // Aún así verifico mant_plan por si quedan huérfanos solo en plan
    $dupesPlan = Db::pgFetchAll("
        SELECT cod_maquina_mant, tarea, COUNT(*) AS n,
               string_agg(orden::text, ',' ORDER BY orden::text) AS ordenes
          FROM mant_plan
         WHERE desc_maquina ILIKE :p
         GROUP BY cod_maquina_mant, tarea
        HAVING COUNT(*) > 1
        ORDER BY cod_maquina_mant, tarea
    ", [':p' => $like]);
    if ($dupesPlan) {
        echo PHP_EOL . "Pero mant_plan SÍ tiene "
           . count($dupesPlan) . " grupos duplicados:" . PHP_EOL;
        foreach (array_slice($dupesPlan, 0, 8) as $d) {
            printf("  · %s · tarea %s · %d filas · ordenes [%s]\n",
                $d['cod_maquina_mant'], $d['tarea'], $d['n'], $d['ordenes']);
        }
        echo "Aplica `mant_dedupe_tareas_racks.php --apply --like='$like'` para limpiarlos." . PHP_EOL;
    }
    exit(0);
}

$nGrupos = count($dupes);

// Por máquina, totales
$resumenMaq = [];
foreach ($dupes as $d) {
    $cod = (string)$d['cod_maquina_mant'];
    $resumenMaq[$cod] = ($resumenMaq[$cod] ?? 0) + 1;
}

echo "Grupos (cod_maquina_mant, tarea) con >1 orden en marcas: $nGrupos" . PHP_EOL;

echo PHP_EOL . "Por máquina (grupos duplicados):" . PHP_EOL;
foreach ($resumenMaq as $cod => $n) {
    printf("  · %s → %d tareas con orden duplicado\n", $cod, $n);
}

echo PHP_EOL . "Detalle (primeros 10):" . PHP_EOL;
foreach (array_slice($dupes, 0, 10) as $d) {
    printf("  · %s · tarea %s · ordenes [%s] · %d marcas\n",
        $d['cod_maquina_mant'], $d['tarea'], $d['ordenes'], $d['n_marcas']);
}

// 2. Resolver canónico para cada grupo
$plan = [];  // [cod_maquina_mant|tarea => ['keep' => '...', 'drop' => [...]]]
foreach ($dupes as $d) {
    $cod   = (string)$d['cod_maquina_mant'];
    $tarea = (string)$d['tarea'];
    $ords  = explode(',', (string)$d['ordenes']);

    // Buscar cuáles existen en mant_plan
    $place = implode(',', array_fill(0, count($ords), '?'));
    $sql = "SELECT orden::text AS orden
              FROM mant_plan
             WHERE cod_maquina_mant = ?
               AND tarea = ?
               AND orden::text IN ($place)";
    $params = array_merge([$cod, $tarea], $ords);
    $rowsPlan = Db::pgFetchAll($sql, $params);
    $enPlan = array_map(fn($r) => (string)$r['orden'], $rowsPlan);

    if (!empty($enPlan)) {
        sort($enPlan, SORT_NATURAL);
        $keep = $enPlan[0];
    } else {
        $ordsCopy = $ords;
        sort($ordsCopy, SORT_NATURAL);
        $keep = $ordsCopy[0];
    }
    $drop = array_values(array_filter($ords, fn($o) => $o !== $keep));
    $plan[$cod . '||' . $tarea] = ['cod' => $cod, 'tarea' => $tarea, 'keep' => $keep, 'drop' => $drop];
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_dedupe_completions_racks.php --apply"
        . ($like !== 'RACK %' ? " --like='$like'" : "") . PHP_EOL;
    exit(0);
}

// 3. Aplicar
echo PHP_EOL . "Aplicando..." . PHP_EOL;
$borradasComp = 0;
$borradasPlan = 0;
foreach ($plan as $p) {
    foreach ($p['drop'] as $od) {
        $rc = Db::pgExec(
            "DELETE FROM mant_completions
              WHERE cod_maquina_mant = :c
                AND tarea = :t
                AND orden::text = :o",
            [':c' => $p['cod'], ':t' => $p['tarea'], ':o' => $od]
        );
        $borradasComp += (int)$rc;
        $rp = Db::pgExec(
            "DELETE FROM mant_plan
              WHERE cod_maquina_mant = :c
                AND tarea = :t
                AND orden::text = :o",
            [':c' => $p['cod'], ':t' => $p['tarea'], ':o' => $od]
        );
        $borradasPlan += (int)$rp;
    }
}

echo "  · mant_completions borradas: $borradasComp" . PHP_EOL;
echo "  · mant_plan borradas:        $borradasPlan" . PHP_EOL;

// 4. Verificación
$check = Db::pgFetchAll("
    SELECT cod_maquina_mant, tarea
      FROM mant_completions
     WHERE desc_maquina ILIKE :p
     GROUP BY cod_maquina_mant, tarea
    HAVING COUNT(DISTINCT orden::text) > 1
", [':p' => $like]);

if (!$check) {
    echo PHP_EOL . "✓ Verificación OK · sin (cod, tarea) duplicado en marcas." . PHP_EOL;
} else {
    echo PHP_EOL . "⚠ Aún quedan " . count($check) . " grupos duplicados." . PHP_EOL;
}

// 5. Conteo final por máquina (tareas distintas)
$conteo = Db::pgFetchAll("
    SELECT cod_maquina_mant, COUNT(DISTINCT tarea) AS n_tareas
      FROM mant_completions
     WHERE desc_maquina ILIKE :p
     GROUP BY 1
     ORDER BY 1
", [':p' => $like]);

echo PHP_EOL . "Tareas distintas por máquina tras dedupe:" . PHP_EOL;
foreach ($conteo as $r) {
    printf("  · %s → %d tareas distintas\n", $r['cod_maquina_mant'], $r['n_tareas']);
}
