<?php
/**
 * Mueve cualquier fecha_intervencion de mant_completions que caiga en
 * fin de semana o festivo (calendario CV) al día hábil ANTERIOR más
 * próximo. Aplica a marcas tipo 'completada' y 'recuperacion' (las
 * 'no_realizada' tienen fi NULL).
 *
 * Modos:
 *   php tools/mant_fix_dias_no_habiles_v2.php
 *     → DRY-RUN
 *   php tools/mant_fix_dias_no_habiles_v2.php --apply
 *     → ESCRITURA
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply = in_array('--apply', $argv, true);

echo "Fix fechas no hábiles en mant_completions · "
   . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// Carga todas las marcas con fi NO NULL
$rows = Db::pgFetchAll("
    SELECT id, fecha_intervencion, tipo, cod_maquina_mant
      FROM mant_completions
     WHERE fecha_intervencion IS NOT NULL
");
echo "Marcas con fecha_intervencion: " . count($rows) . PHP_EOL;

$noHabiles = [];
foreach ($rows as $r) {
    $fi = (string)$r['fecha_intervencion'];
    if ($fi === '') continue;
    if (CalendarioLaboral::esDiaHabil($fi)) continue;
    $noHabiles[] = $r;
}
echo "  · de las cuales en día NO hábil: " . count($noHabiles) . PHP_EOL;

if (!$noHabiles) {
    echo PHP_EOL . "Nada que corregir." . PHP_EOL;
    exit(0);
}

// Muestra hasta 10 ejemplos
echo PHP_EOL . "Ejemplos (hasta 10):" . PHP_EOL;
foreach (array_slice($noHabiles, 0, 10) as $r) {
    $fi = (string)$r['fecha_intervencion'];
    $nueva = CalendarioLaboral::ajustarADiaHabil($fi, 'anterior');
    printf("  · id=%s · %s (%s) → %s · cod=%s\n",
        $r['id'], $fi, date('D', strtotime($fi)), $nueva, $r['cod_maquina_mant']);
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:\n  php tools/mant_fix_dias_no_habiles_v2.php --apply\n";
    exit(0);
}

// Aplicar
echo PHP_EOL . "Aplicando..." . PHP_EOL;
$actualizadas = 0;
foreach ($noHabiles as $r) {
    $fi = (string)$r['fecha_intervencion'];
    $nueva = CalendarioLaboral::ajustarADiaHabil($fi, 'anterior');
    if ($nueva === $fi) continue;
    Db::pgExec("UPDATE mant_completions SET fecha_intervencion = ? WHERE id = ?",
        [$nueva, $r['id']]);
    $actualizadas++;
}
echo "✓ $actualizadas marcas actualizadas a día hábil anterior" . PHP_EOL;

// Verificación
$residual = 0;
foreach (Db::pgFetchAll("SELECT id, fecha_intervencion FROM mant_completions WHERE fecha_intervencion IS NOT NULL") as $r) {
    if (!CalendarioLaboral::esDiaHabil((string)$r['fecha_intervencion'])) $residual++;
}
echo "Residual (fi en día no hábil): $residual (esperado 0)" . PHP_EOL;
