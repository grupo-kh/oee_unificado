<?php
/**
 * Asignación aleatoria de operario a intervenciones preventivas que no
 * tienen operario asignado (auditoría).
 *
 * Por defecto va en modo "dry-run": muestra qué cambiaría pero no escribe
 * en BD. Para aplicarlo: añade el flag --apply.
 *
 * Uso (CLI):
 *   php tools/mant_assign_random_operario.php             # dry-run
 *   php tools/mant_assign_random_operario.php --apply     # escribe en BD
 *
 * Pool de operarios: los operarios distintos que ya han hecho alguna
 * intervención en mant_completions. Si quieres usar otro pool, edita la
 * función getPoolOperarios() más abajo.
 *
 * Trazabilidad: cada fila modificada se marca con
 *   marcada_por = 'auto-random:' || marcada_por_anterior
 * para que sea reversible y se pueda saber qué fue asignado por la auditoría.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv ?? [], true);

if (!defined('MANT_USE_PG') || !MANT_USE_PG) {
    fwrite(STDERR, "Este script solo funciona en modo PostgreSQL (MANT_USE_PG=true)\n");
    exit(1);
}

/**
 * Pool de operarios para la asignación aleatoria.
 * Tomamos los distintos operarios con al menos una intervención registrada.
 */
function getPoolOperarios(): array
{
    $rows = Db::pgFetchAll("
        SELECT DISTINCT operario FROM mant_completions
         WHERE operario IS NOT NULL AND operario <> ''
         ORDER BY operario
    ");
    return array_column($rows, 'operario');
}

$pool = getPoolOperarios();
if (count($pool) === 0) {
    fwrite(STDERR, "No hay operarios en mant_completions para usar como pool. Aborta.\n");
    exit(1);
}

echo "Pool de operarios (" . count($pool) . "):\n";
foreach ($pool as $op) echo "  · $op\n";
echo "\n";

// Buscar filas sin operario (excluir no_realizadas: las "no hechas" no tienen
// operario por definición y no procede asignar uno).
$rows = Db::pgFetchAll("
    SELECT id, external_id, cod_maquina_mant, desc_maquina, tarea, fecha_intervencion, tipo, marcada_por
      FROM mant_completions
     WHERE COALESCE(tipo, '') IN ('completada', 'recuperacion')
       AND (operario IS NULL OR TRIM(operario) = '')
     ORDER BY fecha_intervencion ASC, id ASC
");

echo "Filas candidatas (completada/recuperacion sin operario): " . count($rows) . "\n";

if (count($rows) === 0) {
    echo "Nada que asignar. Salgo.\n";
    exit(0);
}

mt_srand((int)(microtime(true) * 1000)); // semilla aleatoria

$asignados = 0;
foreach ($rows as $r) {
    $op = $pool[mt_rand(0, count($pool) - 1)];
    $newMarcadaPor = 'auto-random:' . trim((string)$r['marcada_por']);

    echo sprintf(
        " %s · %s · %s · %s → %s\n",
        $r['fecha_intervencion'] ?? '          ',
        str_pad((string)$r['cod_maquina_mant'], 12),
        str_pad((string)$r['desc_maquina'], 30),
        str_pad((string)$r['tarea'], 8),
        $op
    );

    if ($apply) {
        Db::pgExec(
            "UPDATE mant_completions SET operario = ?, marcada_por = ? WHERE id = ?",
            [$op, $newMarcadaPor, $r['id']]
        );
    }
    $asignados++;
}

echo "\n";
echo "Filas " . ($apply ? "actualizadas" : "que se actualizarían") . ": $asignados\n";
if (!$apply) {
    echo "DRY-RUN. Para aplicar los cambios, vuelve a lanzarlo con --apply\n";
} else {
    echo "Cambios aplicados. marcada_por queda como 'auto-random:<anterior>' para trazabilidad.\n";
}
