<?php
/**
 * Mueve cualquier proxima_revision de mant_plan que caiga en fin de semana
 * o festivo (calendario CV) al día hábil ANTERIOR más próximo. Aplica solo a
 * tareas activas (alta_baja='ALTA', activa='A') con proxima_revision no nula.
 *
 * Política: "anterior" — preferimos adelantar la revisión a último día hábil
 * antes que retrasarla, para no acumular trabajo más allá del vencimiento.
 *
 * Modos:
 *   php tools/mant_fix_proxima_revision_dias_no_habiles.php
 *     → DRY-RUN
 *   php tools/mant_fix_proxima_revision_dias_no_habiles.php --apply
 *     → ESCRITURA
 *   php tools/mant_fix_proxima_revision_dias_no_habiles.php --apply --posterior
 *     → Mueve al siguiente día hábil en lugar del anterior.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply     = in_array('--apply',     $argv, true);
$posterior = in_array('--posterior', $argv, true);
$direccion = $posterior ? 'posterior' : 'anterior';

echo "Fix proxima_revision en días no hábiles · dir=$direccion · "
   . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// Carga todas las tareas con proxima_revision NO NULL
$rows = Db::pgFetchAll("
    SELECT orden, tarea, cod_maquina_mant, desc_maquina, periodicidad,
           proxima_revision
      FROM mant_plan
     WHERE proxima_revision IS NOT NULL
       AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
       AND COALESCE(activa,    'A')    = 'A'
");
echo "Tareas con proxima_revision: " . count($rows) . PHP_EOL;

$noHabiles = [];
foreach ($rows as $r) {
    $px = (string)$r['proxima_revision'];
    if ($px === '') continue;
    if (CalendarioLaboral::esDiaHabil($px)) continue;
    $noHabiles[] = $r;
}
echo "  · proxima_revision en día NO hábil: " . count($noHabiles) . PHP_EOL;

if (!$noHabiles) {
    echo PHP_EOL . "Nada que corregir." . PHP_EOL;
    exit(0);
}

// Muestra de hasta 10 ejemplos
echo PHP_EOL . "Ejemplos (hasta 10):" . PHP_EOL;
foreach (array_slice($noHabiles, 0, 10) as $r) {
    $px = (string)$r['proxima_revision'];
    $nueva = CalendarioLaboral::ajustarADiaHabil($px, $direccion);
    printf("  · %s · orden=%s · tarea=%s · %s (%s) → %s\n",
        substr((string)$r['desc_maquina'], 0, 40),
        $r['orden'], $r['tarea'], $px,
        strtoupper(substr(date('D', strtotime($px)),0,3)),
        $nueva);
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:\n  php tools/mant_fix_proxima_revision_dias_no_habiles.php --apply\n";
    exit(0);
}

// Aplicar
echo PHP_EOL . "Aplicando..." . PHP_EOL;
$actualizadas = 0;
foreach ($noHabiles as $r) {
    $px = (string)$r['proxima_revision'];
    $nueva = CalendarioLaboral::ajustarADiaHabil($px, $direccion);
    if ($nueva === $px) continue;
    Db::pgExec(
        "UPDATE mant_plan SET proxima_revision = ?
          WHERE orden = ? AND tarea = ?",
        [$nueva, (string)$r['orden'], (string)$r['tarea']]
    );
    $actualizadas++;
}
echo "✓ Tareas actualizadas: $actualizadas" . PHP_EOL;

// Verificación
$residual = 0;
foreach (Db::pgFetchAll("
    SELECT proxima_revision FROM mant_plan
     WHERE proxima_revision IS NOT NULL
       AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
       AND COALESCE(activa,    'A')    = 'A'
") as $r) {
    if (!CalendarioLaboral::esDiaHabil((string)$r['proxima_revision'])) $residual++;
}
echo "Residual (px en día no hábil): $residual (esperado 0)" . PHP_EOL;
