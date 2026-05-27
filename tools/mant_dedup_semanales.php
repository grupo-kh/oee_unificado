<?php
/**
 * Deduplica intervenciones de tareas SEMANAL que caen dos o más veces
 * en la misma semana ISO. Conserva la PRIMERA (la de fecha menor) y
 * borra las demás.
 *
 * Solo procesa marcas con periodicidad = 'SEMANAL' (las otras periodicidades
 * no deberían tener duplicaciones en una misma semana).
 *
 * Modo:
 *   php tools/mant_dedup_semanales.php
 *     → DRY-RUN. Lista grupos con duplicados.
 *
 *   php tools/mant_dedup_semanales.php --apply
 *     → ESCRITURA. Borra los duplicados.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply = in_array('--apply', $argv, true);

echo "Deduplicar tareas SEMANAL con >1 intervención en misma semana · "
   . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$rows = Db::pgFetchAll("
    SELECT external_id, orden, tarea, periodicidad,
           to_char(fecha_intervencion, 'YYYY-MM-DD') AS fi
      FROM mant_completions
     WHERE UPPER(periodicidad) = 'SEMANAL'
       AND fecha_intervencion IS NOT NULL
     ORDER BY orden, tarea, fecha_intervencion
");
echo "Marcas SEMANAL con fecha: " . count($rows) . PHP_EOL;

// Agrupar por (orden, tarea, semana ISO)
$grupos = [];
foreach ($rows as $r) {
    $sem = CalendarioLaboral::semanaIso($r['fi']);
    $key = $r['orden'] . '|' . $r['tarea'] . '|' . $sem;
    $grupos[$key][] = $r;
}

// Detectar grupos con >1 marca
$dup = []; $aBorrar = [];
foreach ($grupos as $k => $g) {
    if (count($g) < 2) continue;
    $dup[$k] = $g;
    // Conservamos la primera (ya están ordenadas por fi), borramos las demás
    for ($i = 1; $i < count($g); $i++) {
        $aBorrar[] = $g[$i]['external_id'];
    }
}
echo "Grupos (orden|tarea|semana) con duplicados: " . count($dup) . PHP_EOL;
echo "Marcas a borrar (las extra): " . count($aBorrar) . PHP_EOL;

$shown = 0;
foreach ($dup as $k => $g) {
    if ($shown >= 10) break;
    echo "  · $k:" . PHP_EOL;
    foreach ($g as $i => $r) {
        $tag = $i === 0 ? '[KEEP]' : '[DELETE]';
        echo "      $tag {$r['fi']}  id={$r['external_id']}" . PHP_EOL;
    }
    $shown++;
}
if (count($dup) > 10) echo "  · … y " . (count($dup) - 10) . " grupos más" . PHP_EOL;

if ($apply && $aBorrar) {
    echo PHP_EOL . "Borrando duplicados..." . PHP_EOL;
    $n = 0;
    foreach ($aBorrar as $id) {
        Db::pgExec("DELETE FROM mant_completions WHERE external_id = :id", [':id' => $id]);
        $n++;
    }
    echo "  · Borradas: $n" . PHP_EOL;
}

if (!$apply && $aBorrar) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_dedup_semanales.php --apply" . PHP_EOL;
}
