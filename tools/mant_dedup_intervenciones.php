<?php
/**
 * Deduplica intervenciones en mant_completions cuando la misma tarea
 * aparece varias veces con la MISMA fecha_intervencion. Esto puede pasar
 * si un seed se ejecutó dos veces y las fechas aleatorias coincidieron.
 *
 * Detecta grupos por (orden, tarea, fecha_intervencion). Conserva la
 * más antigua (menor marcada_at) y borra las demás.
 *
 * Modos:
 *   php tools/mant_dedup_intervenciones.php
 *     → DRY-RUN. Lista grupos con duplicados.
 *
 *   php tools/mant_dedup_intervenciones.php --apply
 *     → ESCRITURA. Borra las duplicadas.
 *
 *   php tools/mant_dedup_intervenciones.php --apply --maquina-like='RACK PARABRISAS%'
 *     → Solo procesa máquinas que coincidan con el patrón ILIKE.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);
$mLike = null;
foreach ($argv as $a) {
    if (preg_match('/^--maquina-like=(.+)$/', $a, $m)) $mLike = $m[1];
}

echo "Deduplicar intervenciones · " . ($apply ? "ESCRITURA" : "DRY-RUN");
if ($mLike) echo " · maquinas ILIKE '$mLike'";
echo PHP_EOL . str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// Encontrar grupos duplicados por (orden, tarea, fecha_intervencion)
$where = "fecha_intervencion IS NOT NULL";
$params = [];
if ($mLike) {
    $where .= " AND desc_maquina ILIKE :mlike";
    $params[':mlike'] = $mLike;
}

$dups = Db::pgFetchAll("
    SELECT orden, tarea, to_char(fecha_intervencion, 'YYYY-MM-DD') AS fi, COUNT(*) AS n
      FROM mant_completions
     WHERE $where
     GROUP BY orden, tarea, fecha_intervencion
    HAVING COUNT(*) > 1
     ORDER BY n DESC, orden, tarea, fi
", $params);

echo "Grupos con duplicados (mismo orden+tarea+fecha): " . count($dups) . PHP_EOL;
if (empty($dups)) {
    echo "Nada que deduplicar." . PHP_EOL;
    exit(0);
}

$totalABorrar = 0;
foreach ($dups as $d) $totalABorrar += ((int)$d['n']) - 1;
echo "Filas a borrar (todas menos una por grupo): $totalABorrar" . PHP_EOL;

// Sample
echo PHP_EOL . "Sample (top 10 grupos):" . PHP_EOL;
foreach (array_slice($dups, 0, 10) as $d) {
    printf("  · orden=%s tarea=%s fecha=%s → %d copias\n",
        $d['orden'], $d['tarea'], $d['fi'], $d['n']);
}

if ($apply) {
    echo PHP_EOL . "Aplicando borrado..." . PHP_EOL;
    $totalDel = 0;
    foreach ($dups as $d) {
        // Conservamos la fila con menor marcada_at de cada grupo.
        // Borramos las demás.
        $rows = Db::pgFetchAll("
            SELECT external_id, marcada_at
              FROM mant_completions
             WHERE orden = :o AND tarea = :t AND fecha_intervencion = :f
             ORDER BY marcada_at ASC, external_id ASC
        ", [':o' => $d['orden'], ':t' => $d['tarea'], ':f' => $d['fi']]);
        // Saltar la primera, borrar el resto
        for ($i = 1; $i < count($rows); $i++) {
            Db::pgExec(
                "DELETE FROM mant_completions WHERE external_id = :id",
                [':id' => $rows[$i]['external_id']]
            );
            $totalDel++;
        }
    }
    echo "  · Filas borradas: $totalDel" . PHP_EOL;
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_dedup_intervenciones.php --apply"
        . ($mLike ? " --maquina-like='$mLike'" : "") . PHP_EOL;
}
