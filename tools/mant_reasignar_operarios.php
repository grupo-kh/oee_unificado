<?php
/**
 * Reasigna el operario y la hora de inicio de las marcas existentes en
 * mant_completions usando SOLO el catálogo definitivo de 8 operarios.
 *
 * Reglas:
 *   - RACK %         → Juan Navarro (881) es el principal (70%); el 30% se
 *                      reparte entre los otros 7. Si la marca queda en Juan,
 *                      la hora se regenera SIEMPRE en turno de mañana (06:00-13:59).
 *   - PLATAFORMA %   → reparto uniforme entre los 8.
 *   - Resto          → reparto uniforme entre los 8 (incluyendo Juan).
 *
 * IMPORTANTE: este script TOCA datos del histórico. Está pensado para datos
 * sintéticos de auditoría. Solo afecta a la columna `operario` y, cuando el
 * elegido es Juan en un RACK, también a `hora_inicio`. NO toca fechas, NI
 * tiempos reales, NI external_id.
 *
 * Filtros:
 *   --solo-no-validos   Solo toca marcas cuyo operario actual no está entre
 *                       los 8 (preserva las que ya cumplen).
 *   --since=YYYY-MM-DD  Solo marcas con fecha_intervencion >= esa fecha.
 *   --like='RACK %'     Filtra por desc_maquina.
 *
 * Modos:
 *   php tools/mant_reasignar_operarios.php
 *     → DRY-RUN
 *   php tools/mant_reasignar_operarios.php --apply
 *     → ESCRITURA
 *   php tools/mant_reasignar_operarios.php --apply --solo-no-validos
 *     → solo reasigna cuando el operario actual no es uno de los 8
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply         = in_array('--apply', $argv, true);
$soloNoValidos = in_array('--solo-no-validos', $argv, true);
$since = null;
$like  = null;
foreach ($argv as $a) {
    if (preg_match('/^--since=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $since = $m[1];
    if (preg_match('/^--like=(.+)$/',                  $a, $m)) $like  = $m[1];
}

echo "Reasignar operarios · " . ($apply ? "ESCRITURA" : "DRY-RUN")
   . ($soloNoValidos ? " · solo no-válidos" : "")
   . ($since ? " · desde $since" : "")
   . ($like  ? " · LIKE '$like'"  : "")
   . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$VALIDOS = ['2394','1004','1374','1886','2417','2338','2898','881'];
$JUAN    = '881';
$OTROS   = array_values(array_diff($VALIDOS, [$JUAN])); // 7

// Helpers
function pickWeightedRack(string $juan, array $otros): string {
    // 70% Juan, 30% repartido equiprobablemente entre los otros
    if (mt_rand(1, 100) <= 70) return $juan;
    return $otros[mt_rand(0, count($otros) - 1)];
}
function pickUniform(array $arr): string {
    return $arr[mt_rand(0, count($arr) - 1)];
}
function horaMananaJuan(): string {
    // Turno mañana: 06:00-13:59. Minutos en 5/10 para que parezca natural.
    $h = mt_rand(6, 13);
    $m = mt_rand(0, 11) * 5;
    return sprintf('%02d:%02d:00', $h, $m);
}

// 1. Recuento previo
$wExtra = $since ? " AND fecha_intervencion >= '" . addslashes($since) . "'" : "";
$wLike  = $like  ? " AND desc_maquina ILIKE :pl" : "";
$params = $like  ? [':pl' => $like] : [];

$totalRows = (int)(Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_completions
     WHERE 1=1 $wExtra $wLike
", $params)['n'] ?? 0);

$inValidos = "('" . implode("','", $VALIDOS) . "')";
$rowsNoVal = (int)(Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_completions
     WHERE 1=1 $wExtra $wLike
       AND (operario IS NULL OR operario = '' OR operario NOT IN $inValidos)
", $params)['n'] ?? 0);

echo "Marcas en alcance               : $totalRows" . PHP_EOL;
echo "  · con operario NO válido      : $rowsNoVal" . PHP_EOL;
echo "  · con operario válido         : " . ($totalRows - $rowsNoVal) . PHP_EOL;

// 2. Cargar IDs a procesar
$cond = "1=1 $wExtra $wLike";
if ($soloNoValidos) $cond .= " AND (operario IS NULL OR operario = '' OR operario NOT IN $inValidos)";
$ids = Db::pgFetchAll("
    SELECT id, cod_maquina_mant, desc_maquina, operario
      FROM mant_completions
     WHERE $cond
     ORDER BY desc_maquina, fecha_intervencion
", $params);

$nProc = count($ids);
echo PHP_EOL . "Marcas que se procesarán: $nProc" . PHP_EOL;

if (!$apply) {
    // Conteo previsto del reparto
    $previstoJuan = 0; $previstoPlataforma = 0; $previstoOtros = 0;
    foreach ($ids as $r) {
        $desc = strtoupper((string)$r['desc_maquina']);
        if (str_starts_with(ltrim($desc), 'RACK '))          $previstoJuan++;
        elseif (str_starts_with(ltrim($desc), 'PLATAFORMA')) $previstoPlataforma++;
        else                                                 $previstoOtros++;
    }
    echo "  · racks    : $previstoJuan (Juan principal 70%)" . PHP_EOL;
    echo "  · platafs  : $previstoPlataforma (reparto 1/8)" . PHP_EOL;
    echo "  · otros    : $previstoOtros (reparto 1/8)" . PHP_EOL;
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_reasignar_operarios.php --apply"
        . ($soloNoValidos ? " --solo-no-validos" : "")
        . PHP_EOL;
    exit(0);
}

// 3. Aplicar
echo PHP_EOL . "Aplicando..." . PHP_EOL;
$cnt = ['juan' => 0, 'otro_rack' => 0, 'plat' => 0, 'otros' => 0, 'hora_juan' => 0];

foreach ($ids as $r) {
    $desc = ltrim(strtoupper((string)$r['desc_maquina']));
    $esRack       = str_starts_with($desc, 'RACK ');
    $esPlataforma = str_starts_with($desc, 'PLATAFORMA');

    if ($esRack) {
        $nuevo = pickWeightedRack($JUAN, $OTROS);
        if ($nuevo === $JUAN) {
            $hora = horaMananaJuan();
            Db::pgExec(
                "UPDATE mant_completions SET operario = :o, hora_inicio = :h WHERE id = :id",
                [':o' => $nuevo, ':h' => $hora, ':id' => $r['id']]
            );
            $cnt['juan']++;
            $cnt['hora_juan']++;
        } else {
            Db::pgExec(
                "UPDATE mant_completions SET operario = :o WHERE id = :id",
                [':o' => $nuevo, ':id' => $r['id']]
            );
            $cnt['otro_rack']++;
        }
    } elseif ($esPlataforma) {
        $nuevo = pickUniform($VALIDOS);
        Db::pgExec(
            "UPDATE mant_completions SET operario = :o WHERE id = :id",
            [':o' => $nuevo, ':id' => $r['id']]
        );
        $cnt['plat']++;
    } else {
        $nuevo = pickUniform($VALIDOS);
        Db::pgExec(
            "UPDATE mant_completions SET operario = :o WHERE id = :id",
            [':o' => $nuevo, ':id' => $r['id']]
        );
        $cnt['otros']++;
    }
}

echo "  · Racks → Juan (mañana)      : " . $cnt['juan'] . PHP_EOL;
echo "  · Racks → otros 7            : " . $cnt['otro_rack'] . PHP_EOL;
echo "  · Plataformas → 8 uniformes  : " . $cnt['plat'] . PHP_EOL;
echo "  · Otros → 8 uniformes        : " . $cnt['otros'] . PHP_EOL;
echo "  · hora_inicio Juan regenerada: " . $cnt['hora_juan'] . PHP_EOL;

// 4. Verificación
$op2 = Db::pgFetchAll("
    SELECT operario, COUNT(*) AS n
      FROM mant_completions
     WHERE 1=1 $wExtra $wLike
     GROUP BY operario ORDER BY n DESC
", $params);
echo PHP_EOL . "Reparto FINAL en mant_completions:" . PHP_EOL;
foreach ($op2 as $o) {
    printf("  · %-8s → %d\n", $o['operario'] ?? '(null)', $o['n']);
}
