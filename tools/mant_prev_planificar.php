<?php
/**
 * tools/mant_prev_planificar.php
 * --------------------------------------------------------------
 * Genera la planificacion preventiva a partir del .xlsx de entrada.
 *
 * Lee:
 *   input/mant_prev_input.xlsx
 *     - Hoja MAQUINAS (col A=Maquina, B=Id Tarea, C=Estado, D=Descripcion,
 *                      E=IP Interna, F=Realizacion (Interno/Externo),
 *                      G=Tipo (Preventivo/Predictivo), H=Periodicidad)
 *     - Hoja TURNOS  (fila 1 D-K = nº operario, fila 2 D-K = nombre,
 *                      filas 3+ A=semana, B=inicio, C=fin, D-K=turno)
 *
 * Para cada tarea (excepto las del grupo "Secuencia": E66, RACKS,
 * PLATAFORMAS - se ignoran porque ya tienen su planificacion previa):
 *   - asigna primera fecha aleatoria entre 26/08/2025 y 16/09/2025,
 *     sobre un dia que este cubierto por una semana de TURNOS
 *   - asigna operario aleatorio de los disponibles (no Vac./Vacaciones)
 *     en esa semana, usando su NUMERO (no nombre)
 *   - calcula las siguientes fechas hasta 03/07/2026 sumando el periodo
 *     base + un delay aleatorio segun periodicidad:
 *       QUINCENAL  +15d  +rand(0,2)
 *       MENSUAL    +1m   +rand(1,3)
 *       TRIMESTRAL +3m   +rand(3,5)
 *       SEMESTRAL  +6m   +rand(1,9)
 *       ANUAL      +1y   +rand(10,15)
 *   - para cada fecha posterior asigna tambien operario disponible esa
 *     semana (si TURNOS la cubre; si no, queda vacio)
 *
 * Escribe:
 *   output/mant_prev_planificado.xlsx
 *     - Hoja TAREAS         (1 fila por tarea, todos los campos +
 *                            Alta/Baja, IP Interna, Interno/Externo,
 *                            Preventivo/Predictivo)
 *     - Hoja PLANIFICACION  (1 fila por revision programada)
 *     - Hoja LOG_ASUNCIONES (resumen de filas saltadas, sin periodicidad,
 *                            o sin operario asignado)
 *
 * Ejecucion (recomendada por navegador, asi Apache carga zip):
 *   http://localhost/PLAN_ATTAINMENT/views/mant_prev_planificar.php
 *
 * Si quieres relanzar con la MISMA semilla aleatoria (resultado
 * reproducible), pasa ?seed=12345 en la URL.
 * --------------------------------------------------------------
 */

declare(strict_types=1);
ini_set('memory_limit', '4G');
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) header('Content-Type: text/plain; charset=UTF-8');

// ─────────────────────────────────────────────────────────────
// Configuracion
// ─────────────────────────────────────────────────────────────
$INPUT_FILE  = __DIR__ . '/../input/mant_prev_input.xlsx';
$OUTPUT_DIR  = __DIR__ . '/../output';
$OUTPUT_FILE = $OUTPUT_DIR . '/mant_prev_planificado.xlsx';

// Ventana para la primera fecha aleatoria (inclusive)
$WIN_START = '2025-08-26';
$WIN_END   = '2025-09-16';

// Limite hasta donde calcular siguientes revisiones
$LIMITE = '2026-07-03';

// Grupos a ignorar (ya planificados previamente)
$IGNORE_MAQUINAS = ['E66','RACKS','PLATAFORMAS'];

// Semilla aleatoria (opcional, para reproducibilidad). Por defecto
// time() = no determinista. Pasa ?seed=N por URL para fijarla.
$seed = (int)($_GET['seed'] ?? time());
mt_srand($seed);

// Periodicidades: [meses_base, dias_base_extra, delay_min, delay_max]
$PERIODS = [
    'QUINCENAL'  => ['months' => 0, 'days_extra' => 15, 'delay_min' => 0,  'delay_max' => 2],
    'MENSUAL'    => ['months' => 1, 'days_extra' => 0,  'delay_min' => 1,  'delay_max' => 3],
    'TRIMESTRAL' => ['months' => 3, 'days_extra' => 0,  'delay_min' => 3,  'delay_max' => 5],
    'SEMESTRAL'  => ['months' => 6, 'days_extra' => 0,  'delay_min' => 1,  'delay_max' => 9],
    'ANUAL'      => ['months' => 12,'days_extra' => 0,  'delay_min' => 10, 'delay_max' => 15],
];

// ─────────────────────────────────────────────────────────────
// Verificaciones previas
// ─────────────────────────────────────────────────────────────
if (!is_file($INPUT_FILE)) {
    echo "[ERR] No encuentro {$INPUT_FILE}\n";
    echo "Copia el .xlsx alli (con ese nombre) y vuelve a lanzar.\n";
    exit(1);
}
if (!extension_loaded('zip')) {
    echo "[ERR] La extension PHP 'zip' no esta cargada (la necesita PhpSpreadsheet).\n";
    exit(1);
}
if (!is_dir($OUTPUT_DIR)) @mkdir($OUTPUT_DIR, 0777, true);

echo "=== PLANIFICAR MANTENIMIENTO PREVENTIVO ===\n";
echo "Entrada : {$INPUT_FILE}\n";
echo "Salida  : {$OUTPUT_FILE}\n";
echo "Ventana : {$WIN_START} a {$WIN_END}\n";
echo "Limite  : {$LIMITE}\n";
echo "Seed    : {$seed}\n\n";

// ─────────────────────────────────────────────────────────────
// 1) LEER MAQUINAS Y TURNOS
// ─────────────────────────────────────────────────────────────
$reader = IOFactory::createReaderForFile($INPUT_FILE);
$reader->setReadDataOnly(true);
$reader->setLoadSheetsOnly(['MAQUINAS','TURNOS']);
$ss = $reader->load($INPUT_FILE);

$shMaq = $ss->getSheetByName('MAQUINAS');
$shTur = $ss->getSheetByName('TURNOS');
if (!$shMaq || !$shTur) {
    echo "[ERR] No encuentro las hojas MAQUINAS y/o TURNOS en el .xlsx.\n";
    exit(1);
}

// 1.a) TURNOS: operarios fila 1 (D..K), schedule filas 3+ (B,C, D..K)
$turColMin = 4;       // D
$turColMax = Coordinate::columnIndexFromString($shTur->getHighestColumn());
$operarioPorCol = []; // colIdx => numero (string)
for ($c = $turColMin; $c <= $turColMax; $c++) {
    $L = Coordinate::stringFromColumnIndex($c);
    $num = $shTur->getCell($L . '1', false)?->getValue();
    if ($num !== null && $num !== '') {
        $operarioPorCol[$c] = trim((string)$num);
    }
}
$nombrePorOp = []; // numero => nombre (de la fila 2)
foreach ($operarioPorCol as $c => $num) {
    $L = Coordinate::stringFromColumnIndex($c);
    $nm = $shTur->getCell($L . '2', false)?->getValue();
    $nombrePorOp[$num] = $nm !== null ? trim((string)$nm) : '';
}
echo "TURNOS: operarios detectados (" . count($operarioPorCol) . "): "
    . implode(', ', array_map(fn($n) => "{$n} ({$nombrePorOp[$n]})", $operarioPorCol)) . "\n";

// Helper: parsea valor de celda como Y-m-d (acepta serial Excel o string)
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

// Detecta si un valor de turno significa "no disponible" (vacaciones)
function isAvailableShift($val): bool {
    if ($val === null) return false;
    $s = trim((string)$val);
    if ($s === '') return false;
    $u = mb_strtoupper($s);
    // Excluimos cualquier celda que contenga VAC (Vac., Vacaciones)
    if (mb_strpos($u, 'VAC') !== false) return false;
    return true;
}

// Construir lista de "ventanas" (filas con B y C validos)
$turRows = []; // cada elem: ['row'=>r,'start'=>'Y-m-d','end'=>'Y-m-d','available'=>[num,...],'shifts'=>[num=>texto]]
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

// 1.b) MAQUINAS: filas 2..N
$rows = [];
$highMaqRow = (int)$shMaq->getHighestRow();
for ($r = 2; $r <= $highMaqRow; $r++) {
    $maq = $shMaq->getCell('A' . $r, false)?->getValue();
    $idt = $shMaq->getCell('B' . $r, false)?->getValue();
    if ($maq === null || trim((string)$maq) === '') continue;
    if ($idt === null || trim((string)$idt) === '') continue;

    $maqUp = mb_strtoupper(trim((string)$maq));
    $isSeq = false;
    foreach ($IGNORE_MAQUINAS as $needle) {
        if ($maqUp === $needle || mb_strpos($maqUp, mb_strtoupper($needle)) === 0) {
            $isSeq = true; break;
        }
    }

    $rows[] = [
        'row'         => $r,
        'maquina'     => trim((string)$maq),
        'id_tarea'    => trim((string)$idt),
        'estado'      => trim((string)($shMaq->getCell('C' . $r, false)?->getValue() ?? '')),
        'descripcion' => trim((string)($shMaq->getCell('D' . $r, false)?->getValue() ?? '')),
        'ip_interna'  => trim((string)($shMaq->getCell('E' . $r, false)?->getValue() ?? '')),
        'realizacion' => trim((string)($shMaq->getCell('F' . $r, false)?->getValue() ?? '')),
        'tipo_mant'   => trim((string)($shMaq->getCell('G' . $r, false)?->getValue() ?? '')),
        'periodicidad'=> mb_strtoupper(trim((string)($shMaq->getCell('H' . $r, false)?->getValue() ?? ''))),
        'es_secuencia'=> $isSeq,
    ];
}
echo "MAQUINAS: " . count($rows) . " filas leidas.\n";
$cntSeq = count(array_filter($rows, fn($x) => $x['es_secuencia']));
echo "  - de las cuales {$cntSeq} pertenecen a Secuencia (E66/RACKS/PLATAFORMAS) y se IGNORAN.\n";

// ─────────────────────────────────────────────────────────────
// 2) HELPERS DE FECHA / OPERARIO
// ─────────────────────────────────────────────────────────────
function pickRandomFirstDate(string $winStart, string $winEnd, array $turRows): array {
    // Devuelve [fecha 'Y-m-d', turRowIndex] - usa SOLO dias cubiertos por TURNOS
    $w0 = strtotime($winStart); $w1 = strtotime($winEnd);
    // Filtrar ventanas que cortan con la window
    $candidatas = [];
    foreach ($turRows as $i => $tr) {
        $s = max($w0, strtotime($tr['start']));
        $e = min($w1, strtotime($tr['end']));
        if ($e < $s) continue;
        if (empty($tr['available'])) continue; // ninguna ventana sin operario
        $days = (int)(($e - $s) / 86400) + 1;
        for ($d = 0; $d < $days; $d++) {
            $candidatas[] = ['date' => date('Y-m-d', $s + $d * 86400), 'row' => $i];
        }
    }
    if (empty($candidatas)) {
        return [null, null];
    }
    $pick = $candidatas[mt_rand(0, count($candidatas) - 1)];
    return [$pick['date'], $pick['row']];
}

function findTurRowFor(string $ymd, array $turRows): ?int {
    $t = strtotime($ymd);
    foreach ($turRows as $i => $tr) {
        $s = strtotime($tr['start']); $e = strtotime($tr['end']);
        if ($t >= $s && $t <= $e) return $i;
    }
    return null;
}

function pickOperario(int $turIdx, array $turRows): ?string {
    $tr = $turRows[$turIdx] ?? null;
    if (!$tr || empty($tr['available'])) return null;
    return $tr['available'][mt_rand(0, count($tr['available']) - 1)];
}

function nextDate(string $prev, string $period, array $PERIODS): ?string {
    if (!isset($PERIODS[$period])) return null;
    $cfg = $PERIODS[$period];
    $dt = new DateTime($prev);
    if ($cfg['months'] > 0) $dt->modify('+' . $cfg['months'] . ' months');
    if ($cfg['days_extra'] > 0) $dt->modify('+' . $cfg['days_extra'] . ' days');
    $delay = mt_rand($cfg['delay_min'], $cfg['delay_max']);
    if ($delay > 0) $dt->modify('+' . $delay . ' days');
    return $dt->format('Y-m-d');
}

// Normaliza el campo "Realizacion en..." (col F) a 'Interno' / 'Externo'
function normInternoExterno(string $v): string {
    $u = mb_strtoupper(trim($v));
    if ($u === '') return '';
    if (mb_strpos($u, 'EXTERN') !== false) return 'Externo';
    if (mb_strpos($u, 'INTERN') !== false) return 'Interno';
    return $v; // dejar tal cual si no reconocemos
}

// Normaliza el tipo (col G) a 'Preventivo' / 'Predictivo'
function normTipoMant(string $v): string {
    $u = mb_strtoupper(trim($v));
    if ($u === '') return '';
    if (mb_strpos($u, 'PREDICT') !== false) return 'Predictivo';
    if (mb_strpos($u, 'PREVENT') !== false) return 'Preventivo';
    return $v;
}

// ─────────────────────────────────────────────────────────────
// 3) GENERAR PLANIFICACION
// ─────────────────────────────────────────────────────────────
$tareas      = [];   // 1 fila por tarea
$planning    = [];   // 1 fila por revision
$asunciones  = [];   // notas/saltos

$cntOk = 0; $cntSkipPer = 0; $cntSkipNoOp = 0; $cntSkipSeq = 0;
foreach ($rows as $row) {
    if ($row['es_secuencia']) {
        $cntSkipSeq++;
        $asunciones[] = ['fila' => $row['row'], 'maquina' => $row['maquina'], 'id_tarea' => $row['id_tarea'],
                         'motivo' => 'Pertenece a Secuencia (E66/RACKS/PLATAFORMAS) - se ignora'];
        continue;
    }
    $per = $row['periodicidad'];
    if ($per === '' || !isset($PERIODS[$per])) {
        $cntSkipPer++;
        $asunciones[] = ['fila' => $row['row'], 'maquina' => $row['maquina'], 'id_tarea' => $row['id_tarea'],
                         'motivo' => "Periodicidad desconocida o vacia: '{$per}'"];
        continue;
    }

    [$firstDate, $firstTurIdx] = pickRandomFirstDate($WIN_START, $WIN_END, $turRows);
    if (!$firstDate) {
        $cntSkipNoOp++;
        $asunciones[] = ['fila' => $row['row'], 'maquina' => $row['maquina'], 'id_tarea' => $row['id_tarea'],
                         'motivo' => 'No hay ventana de TURNOS con operarios disponibles en la semilla'];
        continue;
    }
    $firstOp = pickOperario($firstTurIdx, $turRows);

    // Construir tarea (master)
    $tareas[] = [
        'id_tarea'   => $row['id_tarea'],
        'maquina'    => $row['maquina'],
        'descripcion'=> $row['descripcion'],
        'ip_interna' => $row['ip_interna'],
        'tipo_mant'  => normTipoMant($row['tipo_mant']),
        'realizacion'=> normInternoExterno($row['realizacion']),
        'periodicidad'=> $per,
        'estado'     => $row['estado'],
        'alta_baja'  => 'ALTA',  // por defecto; modificable a mano luego
    ];

    // Construir revisiones
    $cur = $firstDate; $turIdx = $firstTurIdx; $op = $firstOp;
    $iter = 0;
    while ($cur <= $LIMITE && $iter < 200) {
        $opName = $op !== null ? ($nombrePorOp[$op] ?? '') : '';
        $shift  = $op !== null && isset($turRows[$turIdx]['shifts'][$op]) ? $turRows[$turIdx]['shifts'][$op] : '';
        $semana = $turIdx !== null ? ($shTur->getCell('A' . $turRows[$turIdx]['row'], false)?->getValue() ?? '') : '';

        $planning[] = [
            'id_tarea'    => $row['id_tarea'],
            'maquina'     => $row['maquina'],
            'descripcion' => $row['descripcion'],
            'periodicidad'=> $per,
            'fecha'       => $cur,
            'semana'      => trim((string)$semana),
            'operario_num'=> $op ?? '',
            'operario_nom'=> $opName,
            'turno'       => $shift,
            'tipo_mant'   => normTipoMant($row['tipo_mant']),
            'realizacion' => normInternoExterno($row['realizacion']),
            'ip_interna'  => $row['ip_interna'],
        ];

        $next = nextDate($cur, $per, $PERIODS);
        if (!$next || $next > $LIMITE) break;
        $cur = $next;
        $turIdx = findTurRowFor($cur, $turRows);
        $op = ($turIdx !== null) ? pickOperario($turIdx, $turRows) : null;
        $iter++;
    }

    $cntOk++;
}

echo "\n--- RESUMEN ---\n";
echo "  Tareas planificadas        : {$cntOk}\n";
echo "  Tareas Secuencia ignoradas : {$cntSkipSeq}\n";
echo "  Saltadas por periodicidad  : {$cntSkipPer}\n";
echo "  Saltadas sin operario      : {$cntSkipNoOp}\n";
echo "  Total revisiones generadas : " . count($planning) . "\n";

// ─────────────────────────────────────────────────────────────
// 4) ESCRIBIR XLSX DE SALIDA
// ─────────────────────────────────────────────────────────────
$out = new Spreadsheet();

// Hoja 1: TAREAS
$s1 = $out->getActiveSheet();
$s1->setTitle('TAREAS');
$hdr1 = ['ID Tarea','Maquina','Descripcion','IP Interna','Tipo (Preventivo/Predictivo)',
        'Realizacion (Interno/Externo)','Periodicidad','Estado','Alta/Baja'];
$s1->fromArray($hdr1, null, 'A1');
$r = 2;
foreach ($tareas as $t) {
    $s1->fromArray([
        $t['id_tarea'], $t['maquina'], $t['descripcion'], $t['ip_interna'],
        $t['tipo_mant'], $t['realizacion'], $t['periodicidad'], $t['estado'], $t['alta_baja'],
    ], null, 'A' . $r);
    $r++;
}
foreach (range('A','I') as $col) $s1->getColumnDimension($col)->setAutoSize(true);
$s1->getStyle('A1:I1')->getFont()->setBold(true);
$s1->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');
$s1->freezePane('A2');

// Validacion: lista desplegable Alta/Baja en col I
$valAB = $s1->getCell('I2')->getDataValidation();
$valAB->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
$valAB->setAllowBlank(false);
$valAB->setShowDropDown(true);
$valAB->setFormula1('"ALTA,BAJA"');
$valAB->setShowErrorMessage(true);
for ($i = 2; $i < $r; $i++) {
    $s1->getCell('I' . $i)->setDataValidation(clone $valAB);
}
// Lista desplegable Tipo Preventivo/Predictivo (col E)
$valTP = $s1->getCell('E2')->getDataValidation();
$valTP->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
$valTP->setAllowBlank(true);
$valTP->setShowDropDown(true);
$valTP->setFormula1('"Preventivo,Predictivo"');
for ($i = 2; $i < $r; $i++) $s1->getCell('E' . $i)->setDataValidation(clone $valTP);
// Lista desplegable Interno/Externo (col F)
$valIE = $s1->getCell('F2')->getDataValidation();
$valIE->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
$valIE->setAllowBlank(true);
$valIE->setShowDropDown(true);
$valIE->setFormula1('"Interno,Externo"');
for ($i = 2; $i < $r; $i++) $s1->getCell('F' . $i)->setDataValidation(clone $valIE);

// Hoja 2: PLANIFICACION
$s2 = $out->createSheet();
$s2->setTitle('PLANIFICACION');
$hdr2 = ['ID Tarea','Maquina','Descripcion','Periodicidad','Fecha programada','Semana',
        'Operario nº','Operario nombre','Turno','Tipo','Realizacion','IP Interna'];
$s2->fromArray($hdr2, null, 'A1');
$r = 2;
foreach ($planning as $p) {
    $s2->fromArray([
        $p['id_tarea'], $p['maquina'], $p['descripcion'], $p['periodicidad'],
        $p['fecha'], $p['semana'], $p['operario_num'], $p['operario_nom'],
        $p['turno'], $p['tipo_mant'], $p['realizacion'], $p['ip_interna'],
    ], null, 'A' . $r);
    $r++;
}
foreach (range('A','L') as $col) $s2->getColumnDimension($col)->setAutoSize(true);
$s2->getStyle('A1:L1')->getFont()->setBold(true);
$s2->getStyle('A1:L1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F1');
$s2->freezePane('A2');

// Hoja 3: LOG_ASUNCIONES
$s3 = $out->createSheet();
$s3->setTitle('LOG_ASUNCIONES');
$s3->fromArray(['Fila origen','Maquina','ID Tarea','Motivo'], null, 'A1');
$r = 2;
foreach ($asunciones as $a) {
    $s3->fromArray([$a['fila'], $a['maquina'], $a['id_tarea'], $a['motivo']], null, 'A' . $r);
    $r++;
}
foreach (range('A','D') as $col) $s3->getColumnDimension($col)->setAutoSize(true);
$s3->getStyle('A1:D1')->getFont()->setBold(true);
$s3->getStyle('A1:D1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE4D6');
$s3->freezePane('A2');

// Hoja 4: META
$s4 = $out->createSheet();
$s4->setTitle('META');
$s4->fromArray([
    ['Generado',                date('Y-m-d H:i:s')],
    ['Origen',                  basename($INPUT_FILE)],
    ['Ventana primera fecha',   "{$WIN_START} → {$WIN_END}"],
    ['Limite calculo',          $LIMITE],
    ['Semilla aleatoria',       $seed],
    ['Tareas planificadas',     $cntOk],
    ['Tareas Secuencia ignoradas', $cntSkipSeq],
    ['Saltadas por periodicidad', $cntSkipPer],
    ['Saltadas sin operario',     $cntSkipNoOp],
    ['Total revisiones',          count($planning)],
    ['Reglas delay',           'QUINCENAL +15d +0..2 / MENSUAL +1m +1..3 / TRIMESTRAL +3m +3..5 / SEMESTRAL +6m +1..9 / ANUAL +1y +10..15'],
], null, 'A1');
$s4->getColumnDimension('A')->setAutoSize(true);
$s4->getColumnDimension('B')->setAutoSize(true);
$s4->getStyle('A1:A11')->getFont()->setBold(true);

$out->setActiveSheetIndex(0);

$writer = new XlsxWriter($out);
$writer->save($OUTPUT_FILE);

echo "\n✓ Archivo generado: {$OUTPUT_FILE}\n";
echo "  (" . filesize($OUTPUT_FILE) . " bytes)\n";
echo "=== FIN ===\n";
