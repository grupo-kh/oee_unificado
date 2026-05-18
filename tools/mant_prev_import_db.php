<?php
/**
 * tools/mant_prev_import_db.php
 * --------------------------------------------------------------
 * IMPORTA el .xlsx nuevo de Mantenimiento Preventivo a la BD
 * PostgreSQL del proyecto, sustituyendo todo lo que NO sea Secuencia
 * (E66 / RACKS / PLATAFORMAS) por las maquinas y tareas del archivo.
 *
 * Comportamiento:
 *   - Lee input/mant_prev_input.xlsx (hojas MAQUINAS y TURNOS)
 *   - Detecta filas de Secuencia en la BD por cod_maquina_mant, grupo
 *     o desc_grupo coincidiendo con E66 / RACKS / PLATAFORMAS (case-
 *     insensitive). Esas filas NO se tocan.
 *   - Para todo lo demas:
 *       · borra mant_completions, mant_pendientes,
 *         mant_periodicidad_overrides asociados
 *       · borra mant_plan (las tareas)
 *       · borra mant_maquinas que no aparezcan en el .xlsx nuevo
 *   - Inserta las maquinas del .xlsx en mant_maquinas (UPSERT)
 *   - Para cada tarea del .xlsx genera planificacion (primera fecha
 *     aleatoria 26/08-16/09 2025, operario aleatorio, siguientes con
 *     delays por periodicidad hasta 03/07/2026) e inserta en mant_plan
 *     con proxima_revision = primera fecha futura
 *   - Inserta las fechas pasadas (anteriores a hoy) en mant_completions
 *     como tipo='completada' con su operario
 *
 * Modo de uso:
 *   - DRY-RUN (por defecto):    /views/mant_prev_import_db.php
 *     Hace toda la operacion en una transaccion y la deshace al final.
 *     Imprime el resumen para inspeccion.
 *   - REAL (commit):            /views/mant_prev_import_db.php?commit=1
 *     Confirma los cambios. ATENCION: destructivo para no-Secuencia.
 *   - SEMILLA fija (reproducible): ?seed=12345
 * --------------------------------------------------------------
 */

declare(strict_types=1);
ini_set('memory_limit', '4G');
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) header('Content-Type: text/plain; charset=UTF-8');

// ─────────────────────────────────────────────────────────────
// Configuracion
// ─────────────────────────────────────────────────────────────
$INPUT_FILE  = __DIR__ . '/../input/mant_prev_input.xlsx';

$WIN_START   = '2025-08-26';
$WIN_END     = '2025-09-16';
$LIMITE      = '2026-07-03';
$TODAY       = date('Y-m-d');

$IGNORE_KEYS = ['E66','RACKS','PLATAFORMAS'];

$PERIODS = [
    'SEMANAL'    => ['months' => 0,  'days_extra' => 7,  'delay_min' => 0,  'delay_max' => 1],
    'QUINCENAL'  => ['months' => 0,  'days_extra' => 15, 'delay_min' => 0,  'delay_max' => 2],
    'MENSUAL'    => ['months' => 1,  'days_extra' => 0,  'delay_min' => 1,  'delay_max' => 3],
    'BIMENSUAL'  => ['months' => 2,  'days_extra' => 0,  'delay_min' => 1,  'delay_max' => 3],
    'TRIMESTRAL' => ['months' => 3,  'days_extra' => 0,  'delay_min' => 3,  'delay_max' => 5],
    'SEMESTRAL'  => ['months' => 6,  'days_extra' => 0,  'delay_min' => 1,  'delay_max' => 9],
    'ANUAL'      => ['months' => 12, 'days_extra' => 0,  'delay_min' => 10, 'delay_max' => 15],
    'TRIANUAL'   => ['months' => 36, 'days_extra' => 0,  'delay_min' => 15, 'delay_max' => 20],
];

// Normalizaciones de typos de la columna H
$PERIOD_ALIASES = [
    'MENUSAL' => 'MENSUAL',  // typo
];

$COMMIT  = !empty($_GET['commit']);
$seed    = (int)($_GET['seed'] ?? time());
mt_srand($seed);

if (!is_file($INPUT_FILE)) {
    echo "[ERR] No encuentro {$INPUT_FILE}\n";
    exit(1);
}
if (!extension_loaded('zip')) {
    echo "[ERR] La extension PHP 'zip' no esta cargada (PhpSpreadsheet la necesita).\n";
    exit(1);
}

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────
function cellToYmd($v): ?string {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
        $n = (float)$v;
        if ($n <= 0) return null;
        try { return ExcelDate::excelToDateTimeObject($n)->format('Y-m-d'); }
        catch (\Throwable $e) { return null; }
    }
    $ts = strtotime((string)$v);
    return $ts ? date('Y-m-d', $ts) : null;
}
function isAvailableShift($val): bool {
    if ($val === null) return false;
    $s = trim((string)$val);
    if ($s === '') return false;
    return mb_strpos(mb_strtoupper($s), 'VAC') === false;
}
function nextDate(string $prev, string $period, array $PERIODS): ?string {
    if (!isset($PERIODS[$period])) return null;
    $cfg = $PERIODS[$period];
    $dt = new DateTime($prev);
    if ($cfg['months']     > 0) $dt->modify('+' . $cfg['months']     . ' months');
    if ($cfg['days_extra'] > 0) $dt->modify('+' . $cfg['days_extra'] . ' days');
    $delay = mt_rand($cfg['delay_min'], $cfg['delay_max']);
    if ($delay > 0) $dt->modify('+' . $delay . ' days');
    return $dt->format('Y-m-d');
}
function normInternoExterno(string $v): ?string {
    $u = mb_strtoupper(trim($v));
    if ($u === '') return null;
    if (mb_strpos($u, 'EXTERN') !== false) return 'Externo';
    if (mb_strpos($u, 'INTERN') !== false) return 'Interno';
    return null;
}
function normTipoMant(string $v): ?string {
    $u = mb_strtoupper(trim($v));
    if ($u === '') return null;
    if (mb_strpos($u, 'PREDICT') !== false) return 'Predictivo';
    if (mb_strpos($u, 'PREVENT') !== false) return 'Preventivo';
    return null;
}

// ─────────────────────────────────────────────────────────────
// 0) Verificar que la migracion 006 esta aplicada
// ─────────────────────────────────────────────────────────────
echo "=== IMPORT MANTENIMIENTO PREVENTIVO -> BD ===\n";
echo "Modo  : " . ($COMMIT ? "COMMIT (cambios reales)" : "DRY-RUN (rollback al final)") . "\n";
echo "Seed  : {$seed}\n";
echo "Hoy   : {$TODAY}\n\n";

$mig = Db::pgFetchOne("SELECT 1 FROM schema_migrations WHERE version = '006'");
if (!$mig) {
    echo "[ERR] Falta aplicar la migracion 006_mant_new_fields.sql.\n";
    echo "  Ejecuta:  php tools\\install_postgres.php\n";
    echo "  (o copia el SQL de migrations/006_mant_new_fields.sql y aplicalo a mano).\n";
    exit(1);
}
echo "✓ Migracion 006 detectada.\n";

// ─────────────────────────────────────────────────────────────
// 1) LEER XLSX (MAQUINAS + TURNOS)
// ─────────────────────────────────────────────────────────────
$reader = IOFactory::createReaderForFile($INPUT_FILE);
$reader->setReadDataOnly(true);
$reader->setLoadSheetsOnly(['MAQUINAS','TURNOS']);
$ss = $reader->load($INPUT_FILE);

$shMaq = $ss->getSheetByName('MAQUINAS');
$shTur = $ss->getSheetByName('TURNOS');
if (!$shMaq || !$shTur) {
    echo "[ERR] No encuentro las hojas MAQUINAS / TURNOS en {$INPUT_FILE}\n";
    exit(1);
}

// TURNOS: numero operarios fila 1 D+
$turColMin = 4;
$turColMax = Coordinate::columnIndexFromString($shTur->getHighestColumn());
$operarioPorCol = []; $nombrePorOp = [];
for ($c = $turColMin; $c <= $turColMax; $c++) {
    $L = Coordinate::stringFromColumnIndex($c);
    $num = $shTur->getCell($L . '1', false)?->getValue();
    if ($num !== null && $num !== '') {
        $operarioPorCol[$c] = trim((string)$num);
        $nm = $shTur->getCell($L . '2', false)?->getValue();
        $nombrePorOp[trim((string)$num)] = $nm !== null ? trim((string)$nm) : '';
    }
}
echo "TURNOS: " . count($operarioPorCol) . " operarios.\n";

// Ventanas de TURNOS
$turRows = [];
$highTurRow = (int)$shTur->getHighestRow();
for ($r = 3; $r <= $highTurRow; $r++) {
    $b = $shTur->getCell('B' . $r, false)?->getValue();
    $c = $shTur->getCell('C' . $r, false)?->getValue();
    $bd = cellToYmd($b); $cd = cellToYmd($c);
    if (!$bd || !$cd) continue;
    $avail = []; $shifts = [];
    foreach ($operarioPorCol as $cIdx => $num) {
        $L = Coordinate::stringFromColumnIndex($cIdx);
        $v = $shTur->getCell($L . $r, false)?->getValue();
        $shifts[$num] = $v !== null ? trim((string)$v) : '';
        if (isAvailableShift($v)) $avail[] = $num;
    }
    $turRows[] = ['row' => $r, 'start' => $bd, 'end' => $cd,
                  'available' => $avail, 'shifts' => $shifts];
}
echo "TURNOS: " . count($turRows) . " ventanas con fechas validas.\n";

// MAQUINAS: filas
$rows = [];
$highMaqRow = (int)$shMaq->getHighestRow();
for ($r = 2; $r <= $highMaqRow; $r++) {
    $maq = $shMaq->getCell('A' . $r, false)?->getValue();
    $idt = $shMaq->getCell('B' . $r, false)?->getValue();
    if ($maq === null || trim((string)$maq) === '') continue;
    if ($idt === null || trim((string)$idt) === '') continue;
    $maqUp = mb_strtoupper(trim((string)$maq));
    $isSeq = false;
    foreach ($IGNORE_KEYS as $needle) {
        if ($maqUp === $needle || mb_strpos($maqUp, mb_strtoupper($needle)) === 0) {
            $isSeq = true; break;
        }
    }
    $perRaw = mb_strtoupper(trim((string)($shMaq->getCell('H' . $r, false)?->getValue() ?? '')));
    $perNorm = $PERIOD_ALIASES[$perRaw] ?? $perRaw;
    $rows[] = [
        'maquina'         => trim((string)$maq),
        'id_tarea'        => trim((string)$idt),
        'estado'          => trim((string)($shMaq->getCell('C' . $r, false)?->getValue() ?? '')),
        'descripcion'     => trim((string)($shMaq->getCell('D' . $r, false)?->getValue() ?? '')),
        'ip_interna'      => trim((string)($shMaq->getCell('E' . $r, false)?->getValue() ?? '')),
        'realizacion'     => trim((string)($shMaq->getCell('F' . $r, false)?->getValue() ?? '')),
        'tipo_mant'       => trim((string)($shMaq->getCell('G' . $r, false)?->getValue() ?? '')),
        'periodicidad'    => $perNorm,
        'periodicidad_raw'=> $perRaw,
        'es_secuencia'    => $isSeq,
    ];
}
echo "MAQUINAS: " . count($rows) . " filas leidas (";
$cntSeq = count(array_filter($rows, fn($x) => $x['es_secuencia']));
echo "{$cntSeq} de Secuencia ignoradas, " . (count($rows)-$cntSeq) . " a importar).\n";

// Dedup por (maquina, id_tarea) -> conserva la PRIMERA, loguea duplicados
$seenKeys = []; $dedRows = []; $duplicados = [];
foreach ($rows as $row) {
    $k = $row['maquina'] . '|' . $row['id_tarea'];
    if (isset($seenKeys[$k])) {
        $duplicados[] = $row;
        continue;
    }
    $seenKeys[$k] = true;
    $dedRows[] = $row;
}
if (!empty($duplicados)) {
    echo "  ! Duplicados (maquina,id_tarea) ignorados: " . count($duplicados) . "\n";
    $sample = array_slice($duplicados, 0, 8);
    foreach ($sample as $d) {
        echo "      · {$d['maquina']} / {$d['id_tarea']}  " .
             "(per='{$d['periodicidad']}', desc='" . mb_substr($d['descripcion'], 0, 40) . "...')\n";
    }
    if (count($duplicados) > 8) echo "      · ... y " . (count($duplicados) - 8) . " mas\n";
}
$rows = $dedRows;

// Auditoria: listar valores unicos de periodicidad y como se mapean.
// Ahora se procesan TODAS las filas (incluidas Secuencia E66_* del .xlsx);
// las que no tengan periodicidad valida se insertan sin planning para que
// el usuario les asigne periodicidad luego desde la UI.
$perCounter = [];
foreach ($rows as $row) {
    $p = $row['periodicidad'];
    if (!isset($perCounter[$p])) $perCounter[$p] = 0;
    $perCounter[$p]++;
}
echo "  Periodicidades detectadas:\n";
ksort($perCounter);
foreach ($perCounter as $p => $cnt) {
    $known = isset($PERIODS[$p]) ? '✓' : '⚠ sin mapeo (se inserta SIN planning)';
    $label = $p === '' ? '(VACIA)' : "'{$p}'";
    echo "      {$known}  {$label}  -> {$cnt} tareas\n";
}
echo "\n";

// ─────────────────────────────────────────────────────────────
// 2) GENERAR PLANIFICACION POR TAREA
// ─────────────────────────────────────────────────────────────
function pickRandomFirstDate(string $winStart, string $winEnd, array $turRows): array {
    $w0 = strtotime($winStart); $w1 = strtotime($winEnd);
    $candidatas = [];
    foreach ($turRows as $i => $tr) {
        $s = max($w0, strtotime($tr['start']));
        $e = min($w1, strtotime($tr['end']));
        if ($e < $s) continue;
        if (empty($tr['available'])) continue;
        $days = (int)(($e - $s) / 86400) + 1;
        for ($d = 0; $d < $days; $d++) {
            $candidatas[] = ['date' => date('Y-m-d', $s + $d * 86400), 'row' => $i];
        }
    }
    if (empty($candidatas)) return [null, null];
    $pick = $candidatas[mt_rand(0, count($candidatas) - 1)];
    return [$pick['date'], $pick['row']];
}
function findTurRowFor(string $ymd, array $turRows): ?int {
    $t = strtotime($ymd);
    foreach ($turRows as $i => $tr) {
        if ($t >= strtotime($tr['start']) && $t <= strtotime($tr['end'])) return $i;
    }
    return null;
}
function pickOperario(int $turIdx, array $turRows): ?string {
    $tr = $turRows[$turIdx] ?? null;
    if (!$tr || empty($tr['available'])) return null;
    return $tr['available'][mt_rand(0, count($tr['available']) - 1)];
}

// Generamos planificacion para tareas con periodicidad mapeada.
// Las que no tengan periodicidad se IMPORTAN igualmente con periodicidad=NULL
// y proxima_revision=NULL (apareceran en la UI sin programacion para que el
// usuario les asigne la periodicidad a mano).
// Tampoco filtramos Secuencia: si la maquina viene en el .xlsx, la procesamos
// (asi E66_LWS, E66_Polipasto, etc. se importan/refrescan).
$tareas = []; $skip = ['no_op' => 0]; $sinPer = 0;
foreach ($rows as $row) {
    $per = $row['periodicidad'];
    if ($per === '' || !isset($PERIODS[$per])) {
        // Sin periodicidad valida -> insertar fila SIN planificacion
        $sinPer++;
        $tareas[] = ['row' => $row, 'dates' => []];
        continue;
    }

    [$firstDate, $firstTurIdx] = pickRandomFirstDate($WIN_START, $WIN_END, $turRows);
    if (!$firstDate) { $skip['no_op']++; $tareas[] = ['row' => $row, 'dates' => []]; continue; }
    $firstOp = pickOperario($firstTurIdx, $turRows);

    $dates = [['fecha' => $firstDate, 'turIdx' => $firstTurIdx, 'operario' => $firstOp]];
    $cur = $firstDate; $iter = 0;
    while ($iter < 200) {
        $next = nextDate($cur, $per, $PERIODS);
        if (!$next) break;
        if ($next > $LIMITE) break;
        $turIdx = findTurRowFor($next, $turRows);
        $op     = ($turIdx !== null) ? pickOperario($turIdx, $turRows) : null;
        $dates[] = ['fecha' => $next, 'turIdx' => $turIdx, 'operario' => $op];
        $cur = $next; $iter++;
    }
    // Asegurar que hay al menos una fecha futura para proxima_revision
    $haveFuture = false;
    foreach ($dates as $d) { if ($d['fecha'] >= $TODAY) { $haveFuture = true; break; } }
    if (!$haveFuture) {
        $next = nextDate($cur, $per, $PERIODS);
        if ($next) {
            $turIdx = findTurRowFor($next, $turRows);
            $op     = ($turIdx !== null) ? pickOperario($turIdx, $turRows) : null;
            $dates[] = ['fecha' => $next, 'turIdx' => $turIdx, 'operario' => $op];
        }
    }

    $tareas[] = ['row' => $row, 'dates' => $dates];
}
echo "Planificacion: tareas totales a importar = " . count($tareas) . "\n";
echo "  · con periodicidad y planificacion : " . (count($tareas) - $sinPer - $skip['no_op']) . "\n";
echo "  · sin periodicidad (insert plano)  : {$sinPer}\n";
echo "  · sin operarios (insert plano)     : {$skip['no_op']}\n\n";

// Lista unica de maquinas a importar
$maquinasNuevas = [];
foreach ($tareas as $t) {
    $cmm = $t['row']['maquina'];
    if (!isset($maquinasNuevas[$cmm])) {
        $maquinasNuevas[$cmm] = $cmm; // desc = cod por defecto, se puede cambiar despues
    }
}
echo "Maquinas distintas en el .xlsx (no-Secuencia): " . count($maquinasNuevas) . "\n\n";

// ─────────────────────────────────────────────────────────────
// 3) TRANSACCION: BORRAR NO-SECUENCIA + INSERT NUEVAS
// ─────────────────────────────────────────────────────────────
$pdo = Db::pg();
$pdo->beginTransaction();

try {
    // 3.a) Construir lista de maquinas (cod_maquina_mant) que vienen en el
    //      .xlsx. Solo BORRAREMOS lo que pertenezca a estas maquinas - el
    //      resto (RACKs, PLATAFORMAS recuperadas via mant_prev_recover_secuencia,
    //      maquinas user-added, etc.) se conserva intacto.
    $maquinasParams = [];
    $placeholders   = [];
    $i = 0;
    foreach (array_keys($maquinasNuevas) as $cmm) {
        $placeholders[] = ":m{$i}";
        $maquinasParams[":m{$i}"] = $cmm;
        $i++;
    }
    $inClause = !empty($placeholders) ? implode(',', $placeholders) : "''";

    // Resumen previo
    $cur = Db::pgFetchOne("SELECT COUNT(*) c FROM mant_plan");
    $curMaq = Db::pgFetchOne("SELECT COUNT(*) c FROM mant_maquinas");
    echo "BD antes: mant_plan = {$cur['c']}, mant_maquinas = {$curMaq['c']}.\n";
    echo "Maquinas del .xlsx que se SOBRESCRIBEN (borra+inserta): " . count($maquinasNuevas) . "\n\n";

    // 3.b) Borrar overrides asociados a las tareas de las maquinas del .xlsx
    $stDelOvr = $pdo->prepare("
        DELETE FROM mant_periodicidad_overrides ovr
         USING mant_plan p
         WHERE ovr.orden = p.orden AND ovr.tarea = p.tarea
           AND p.cod_maquina_mant IN ({$inClause})
    ");
    $stDelOvr->execute($maquinasParams);
    $delOvr = $stDelOvr->rowCount();

    // 3.c) Borrar pendientes asociados
    $stDelPend = $pdo->prepare("
        DELETE FROM mant_pendientes
         WHERE cod_maquina_mant IN ({$inClause})
    ");
    $stDelPend->execute($maquinasParams);
    $delPend = $stDelPend->rowCount();

    // 3.d) Borrar completions asociados
    $stDelCompl = $pdo->prepare("
        DELETE FROM mant_completions
         WHERE cod_maquina_mant IN ({$inClause})
    ");
    $stDelCompl->execute($maquinasParams);
    $delCompl = $stDelCompl->rowCount();

    // 3.e) Borrar tareas de mant_plan
    $stDelPlan = $pdo->prepare("
        DELETE FROM mant_plan
         WHERE cod_maquina_mant IN ({$inClause})
    ");
    $stDelPlan->execute($maquinasParams);
    $delPlan = $stDelPlan->rowCount();

    // 3.f) NO borramos maquinas. Las que vienen en el .xlsx se UPSERTean
    //      a continuacion; el resto se conserva (RACKs, PLATAFORMAS, etc.)
    $delMaq = 0;

    echo "Borrados:\n";
    echo "  · mant_periodicidad_overrides : {$delOvr}\n";
    echo "  · mant_pendientes             : {$delPend}\n";
    echo "  · mant_completions            : {$delCompl}\n";
    echo "  · mant_plan                   : {$delPlan}\n";
    echo "  · mant_maquinas (huerfanas)   : {$delMaq}\n\n";

    // 3.g) UPSERT maquinas del xlsx
    $stUpsertMaq = $pdo->prepare("
        INSERT INTO mant_maquinas (cod_maquina_mant, desc_maquina, is_user_added)
        VALUES (:cod, :desc, FALSE)
        ON CONFLICT (cod_maquina_mant) DO UPDATE SET
            desc_maquina = EXCLUDED.desc_maquina
    ");
    $cntMaqIns = 0;
    foreach ($maquinasNuevas as $cmm => $desc) {
        $stUpsertMaq->execute([':cod' => $cmm, ':desc' => $desc]);
        $cntMaqIns++;
    }

    // 3.h) Insert tareas en mant_plan + completions del pasado
    //      ON CONFLICT por seguridad: si por lo que sea queda un (orden,tarea)
    //      heredado de Secuencia que coincida, lo actualiza en lugar de fallar.
    $stInsPlan = $pdo->prepare("
        INSERT INTO mant_plan (
            orden, tarea, cod_maquina_mant, desc_maquina,
            grupo, desc_grupo, periodicidad, desc_tarea, activa,
            ultima_revision, proxima_revision,
            alta_baja, ip_interna, tipo_realizacion, tipo_mantenimiento
        ) VALUES (
            :orden, :tarea, :cmm, :desc_maq,
            NULL, NULL, :per, :desc_t, 'A',
            :ult, :prox,
            :alta, :ip, :tipo_real, :tipo_mant
        )
        ON CONFLICT (orden, tarea) DO UPDATE SET
            cod_maquina_mant   = EXCLUDED.cod_maquina_mant,
            desc_maquina       = EXCLUDED.desc_maquina,
            periodicidad       = EXCLUDED.periodicidad,
            desc_tarea         = EXCLUDED.desc_tarea,
            ultima_revision    = EXCLUDED.ultima_revision,
            proxima_revision   = EXCLUDED.proxima_revision,
            alta_baja          = EXCLUDED.alta_baja,
            ip_interna         = EXCLUDED.ip_interna,
            tipo_realizacion   = EXCLUDED.tipo_realizacion,
            tipo_mantenimiento = EXCLUDED.tipo_mantenimiento
    ");
    $stInsCompl = $pdo->prepare("
        INSERT INTO mant_completions (
            external_id, tipo, orden, tarea, cod_maquina_mant, desc_maquina,
            periodicidad, desc_tarea, activa,
            fecha_proxima_original, fecha_intervencion, operario,
            observaciones, marcada_por
        ) VALUES (
            :ext, 'completada', :orden, :tarea, :cmm, :desc_maq,
            :per, :desc_t, 'A',
            :fpo, :fi, :op,
            'Sembrado automatico (import .xlsx)', 'import_xlsx'
        )
        ON CONFLICT (external_id) DO NOTHING
    ");

    $cntPlan = 0; $cntCompl = 0; $cntFutSinOp = 0; $cntSinPer = 0;
    foreach ($tareas as $t) {
        $r = $t['row'];
        $cmm   = $r['maquina'];
        $idt   = $r['id_tarea'];
        $orden = $cmm;  // synthetic: orden = cod_maquina_mant (unique with tarea)
        $desc  = $r['descripcion'];
        $per   = $r['periodicidad'];
        $alta  = 'ALTA';
        $ip    = $r['ip_interna'] !== '' ? $r['ip_interna'] : null;
        $tReal = normInternoExterno($r['realizacion']);
        $tMant = normTipoMant($r['tipo_mant']);

        // Tareas sin periodicidad valida: dates=[] -> insertamos plan vacio,
        // sin completions ni proxima_revision
        $perValid = ($per !== '' && isset($PERIODS[$per]));
        if (!$perValid) {
            $cntSinPer++;
            $stInsPlan->execute([
                ':orden'     => $orden,
                ':tarea'     => $idt,
                ':cmm'       => $cmm,
                ':desc_maq'  => $cmm,
                ':per'       => null,
                ':desc_t'    => $desc !== '' ? $desc : null,
                ':ult'       => null,
                ':prox'      => null,
                ':alta'      => $alta,
                ':ip'        => $ip,
                ':tipo_real' => $tReal,
                ':tipo_mant' => $tMant,
            ]);
            $cntPlan++;
            continue;
        }

        // Separar pasadas y futuras
        $pastDates = []; $futDates = [];
        foreach ($t['dates'] as $d) {
            if ($d['fecha'] < $TODAY) $pastDates[] = $d;
            else                       $futDates[] = $d;
        }

        $ultima = !empty($pastDates) ? end($pastDates)['fecha'] : null;
        reset($pastDates);
        $proxima = !empty($futDates) ? $futDates[0]['fecha'] : null;

        // Insert mant_plan
        $stInsPlan->execute([
            ':orden'    => $orden,
            ':tarea'    => $idt,
            ':cmm'      => $cmm,
            ':desc_maq' => $cmm,
            ':per'      => $per,
            ':desc_t'   => $desc !== '' ? $desc : null,
            ':ult'      => $ultima,
            ':prox'     => $proxima,
            ':alta'     => $alta,
            ':ip'       => $ip,
            ':tipo_real'=> $tReal,
            ':tipo_mant'=> $tMant,
        ]);
        $cntPlan++;

        // Insert completions del pasado
        foreach ($pastDates as $i => $d) {
            $ext = "{$orden}|{$idt}|{$d['fecha']}";
            $stInsCompl->execute([
                ':ext'      => $ext,
                ':orden'    => $orden,
                ':tarea'    => $idt,
                ':cmm'      => $cmm,
                ':desc_maq' => $cmm,
                ':per'      => $per,
                ':desc_t'   => $desc !== '' ? $desc : null,
                ':fpo'      => $d['fecha'], // fecha proxima original = la programada
                ':fi'       => $d['fecha'], // fecha intervencion = se hizo en la fecha
                ':op'       => $d['operario'] ?? null,
            ]);
            $cntCompl++;
        }

        if ($proxima === null) $cntFutSinOp++;
    }

    echo "Insertadas:\n";
    echo "  · mant_maquinas              : {$cntMaqIns} (UPSERT)\n";
    echo "  · mant_plan                  : {$cntPlan}\n";
    echo "    - de las cuales sin period.: {$cntSinPer} (sin proxima_revision)\n";
    echo "  · mant_completions (pasadas) : {$cntCompl}\n";
    if ($cntFutSinOp > 0) echo "  · tareas SIN proxima futura  : {$cntFutSinOp}\n";

    // Resumen final
    $after = Db::pgFetchOne("SELECT COUNT(*) c FROM mant_plan");
    $afterMaq = Db::pgFetchOne("SELECT COUNT(*) c FROM mant_maquinas");
    $stXlsx = $pdo->prepare("SELECT COUNT(*) c FROM mant_plan WHERE cod_maquina_mant IN ({$inClause})");
    $stXlsx->execute($maquinasParams);
    $afterXlsx = $stXlsx->fetch();
    echo "\nBD despues:\n";
    echo "  · mant_plan total            : {$after['c']}\n";
    echo "      - de las maquinas .xlsx  : {$afterXlsx['c']}\n";
    echo "      - resto (RACKs, PLAT*, etc.) : " . ($after['c'] - $afterXlsx['c']) . "\n";
    echo "  · mant_maquinas              : {$afterMaq['c']}\n";

    if ($COMMIT) {
        $pdo->commit();
        echo "\n✓✓✓ COMMIT realizado. Cambios persistidos.\n";
    } else {
        $pdo->rollBack();
        echo "\n↺ ROLLBACK (modo dry-run). Para confirmar de verdad lanza con ?commit=1.\n";
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\n✗ ERROR (rollback automatico): " . $e->getMessage() . "\n";
    echo "  Trazado:\n" . $e->getTraceAsString() . "\n";
    exit(2);
}

echo "=== FIN ===\n";
