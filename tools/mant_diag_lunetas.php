<?php
/**
 * Diagnóstico crudo de RACK LUNETAS TA - 01 (o la máquina indicada).
 *
 * Lista TODAS las combinaciones (orden, tarea, desc_tarea) presentes
 * en mant_completions y mant_plan para esa máquina, junto con el
 * número de marcas. Sirve para identificar de dónde vienen las 28
 * entradas que muestra "Revisión completa".
 *
 * Modo:
 *   php tools/mant_diag_lunetas.php
 *     → RACK LUNETAS TA - 01
 *
 *   php tools/mant_diag_lunetas.php "RACK LUNETAS TA - 02"
 *     → otra máquina
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$cod = $argv[1] ?? 'RACK LUNETAS TA - 01';

echo "Diagnóstico de máquina · '$cod'" . PHP_EOL;
echo str_repeat('═', 90) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// 1. mant_maquinas (columnas dinámicas)
echo PHP_EOL . "[mant_maquinas] entradas con esta descripción:" . PHP_EOL;
$maqCols = array_column(Db::pgFetchAll("
    SELECT column_name FROM information_schema.columns WHERE table_name = 'mant_maquinas'
"), 'column_name');
$sel = ['cod_maquina_mant', 'desc_maquina'];
foreach (['grupo', 'desc_grupo'] as $c) {
    if (in_array($c, $maqCols, true)) $sel[] = $c;
}
$rows = Db::pgFetchAll("
    SELECT " . implode(', ', $sel) . "
      FROM mant_maquinas
     WHERE desc_maquina = :c OR cod_maquina_mant = :c
", [':c' => $cod]);
if (!$rows) echo "  · (ninguna)" . PHP_EOL;
foreach ($rows as $r) {
    $parts = [];
    foreach ($sel as $c) $parts[] = "$c=" . ($r[$c] ?? '?');
    echo "  · " . implode(' · ', $parts) . PHP_EOL;
}

// 2. mant_plan: todas las tareas
echo PHP_EOL . "[mant_plan] tareas registradas:" . PHP_EOL;
$rows = Db::pgFetchAll("
    SELECT orden, tarea, desc_tarea, periodicidad, activa, alta_baja, tiempo_estimado
      FROM mant_plan
     WHERE cod_maquina_mant = :c OR desc_maquina = :c
     ORDER BY orden, tarea
", [':c' => $cod]);
echo "  Total filas: " . count($rows) . PHP_EOL;
foreach ($rows as $r) {
    printf("  · orden=%s · tarea=%s · per=%s · ACT=%s · ALTA=%s · TE=%s\n     desc=%s\n",
        $r['orden'], $r['tarea'], $r['periodicidad'],
        $r['activa'], $r['alta_baja'], $r['tiempo_estimado'] ?? '?',
        substr((string)$r['desc_tarea'], 0, 80));
}

// 3. Conteo de pares (orden, tarea) en mant_completions
echo PHP_EOL . "[mant_completions] combinaciones (orden, tarea) y nº marcas:" . PHP_EOL;
$rows = Db::pgFetchAll("
    SELECT orden, tarea, MIN(desc_tarea) AS desc_tarea, COUNT(*) AS n
      FROM mant_completions
     WHERE cod_maquina_mant = :c OR desc_maquina = :c
     GROUP BY orden, tarea
     ORDER BY orden, tarea
", [':c' => $cod]);
echo "  Total combinaciones distintas: " . count($rows) . PHP_EOL;
foreach ($rows as $r) {
    printf("  · orden=%s · tarea=%s · marcas=%d\n     desc=%s\n",
        $r['orden'], $r['tarea'], $r['n'],
        substr((string)$r['desc_tarea'], 0, 80));
}

// 4. Duplicados por descripción (misma desc_tarea con distinto orden/tarea)
echo PHP_EOL . "[mant_completions] descripciones repetidas con (orden, tarea) distinto:" . PHP_EOL;
$rows = Db::pgFetchAll("
    WITH base AS (
        SELECT desc_tarea, orden, tarea, COUNT(*) AS marcas
          FROM mant_completions
         WHERE cod_maquina_mant = :c OR desc_maquina = :c
         GROUP BY desc_tarea, orden, tarea
    )
    SELECT desc_tarea,
           COUNT(*) AS variantes,
           string_agg(orden::text || '|' || tarea::text, ' · ' ORDER BY orden, tarea) AS pares
      FROM base
     GROUP BY desc_tarea
    HAVING COUNT(*) > 1
     ORDER BY desc_tarea
", [':c' => $cod]);
if (!$rows) {
    echo "  · (ninguno)" . PHP_EOL;
} else {
    foreach ($rows as $r) {
        printf("  · variantes=%d · desc=%s\n      pares: %s\n",
            $r['variantes'],
            substr((string)$r['desc_tarea'], 0, 70),
            $r['pares']);
    }
}

// 5. Posibles cod_maquina_mant alternativos (variantes de casing/whitespace)
echo PHP_EOL . "[mant_completions] valores DISTINTOS de cod_maquina_mant + desc_maquina:" . PHP_EOL;
$rows = Db::pgFetchAll("
    SELECT cod_maquina_mant, desc_maquina, COUNT(*) AS marcas
      FROM mant_completions
     WHERE cod_maquina_mant ILIKE :c OR desc_maquina ILIKE :c
     GROUP BY cod_maquina_mant, desc_maquina
     ORDER BY cod_maquina_mant
", [':c' => $cod]);
foreach ($rows as $r) {
    printf("  · cod='%s' · desc='%s' · marcas=%d\n",
        $r['cod_maquina_mant'], $r['desc_maquina'], $r['marcas']);
}
