<?php
/**
 * Corrige las marcas de mant_completions cuya fecha_intervencion cae en
 * sábado, domingo o festivo de la Comunidad Valenciana. Mueve cada una
 * al día hábil ANTERIOR más cercano (operario llegó antes de su festivo,
 * más realista que "llegó el lunes siguiente").
 *
 * Modo:
 *   php tools/mant_fix_dias_no_habiles.php
 *     → DRY-RUN. Lista cuántas tocaría y un sample.
 *
 *   php tools/mant_fix_dias_no_habiles.php --apply
 *     → ESCRITURA.
 *
 *   php tools/mant_fix_dias_no_habiles.php --apply --dir=posterior
 *     → Mueve al día hábil POSTERIOR (en lugar de anterior).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply = in_array('--apply', $argv, true);
$dir   = 'anterior';
foreach ($argv as $a) {
    if (preg_match('/^--dir=(anterior|posterior|cercano)$/', $a, $m)) $dir = $m[1];
}

echo "Mover marcas en día NO hábil a día hábil $dir · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// Cargamos todas las marcas con fecha_intervencion
$rows = Db::pgFetchAll("
    SELECT external_id, to_char(fecha_intervencion, 'YYYY-MM-DD') AS fi
      FROM mant_completions
     WHERE fecha_intervencion IS NOT NULL
");
echo "Marcas con fecha_intervencion: " . count($rows) . PHP_EOL;

$malas = []; $samples = [];
foreach ($rows as $r) {
    $fi = $r['fi'];
    if (!CalendarioLaboral::esDiaHabil($fi)) {
        $nuevo = CalendarioLaboral::ajustarADiaHabil($fi, $dir);
        $malas[$r['external_id']] = ['ori' => $fi, 'nuevo' => $nuevo];
        if (count($samples) < 10) {
            $dow = ['','lun','mar','mié','jue','vie','sáb','dom'][(int)date('N', strtotime($fi))];
            $samples[] = "$fi ($dow) → $nuevo · " . $r['external_id'];
        }
    }
}
echo "Marcas a mover (fin semana o festivo CV): " . count($malas) . PHP_EOL;

if ($samples) {
    echo PHP_EOL . "Sample:" . PHP_EOL;
    foreach ($samples as $s) echo "  · $s" . PHP_EOL;
}

if ($apply && $malas) {
    echo PHP_EOL . "Aplicando UPDATEs..." . PHP_EOL;
    $n = 0;
    foreach ($malas as $id => $par) {
        Db::pgExec(
            "UPDATE mant_completions SET fecha_intervencion = :f WHERE external_id = :id",
            [':f' => $par['nuevo'], ':id' => $id]
        );
        $n++;
    }
    echo "  · Movidas: $n" . PHP_EOL;
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_fix_dias_no_habiles.php --apply" . ($dir !== 'anterior' ? " --dir=$dir" : "") . PHP_EOL;
}
