<?php
/**
 * tools/mant_prev_import_tiempo_pausa.php
 * --------------------------------------------------------------
 * Lee input/listado_maquinas.xlsx y rellena tiempo_estimado y
 * fecha_pausado en mant_plan (migracion 007) por
 * (cod_maquina_mant, tarea/id_tarea).
 *
 * Detecta columnas por cabecera (fila 1, case-insensitive):
 *   - Maquina:        'maquina', 'máquina', 'cod maquina', 'cod_maquina_mant'
 *   - Tarea:          'id tarea', 'tarea', 'id_tarea', 'cod tarea'
 *   - Tiempo:         'tiempo estimado', 'tiempo', 'duracion', 'minutos'
 *                     (acepta numeros, HH:MM o coma decimal en horas)
 *   - Fecha pausa:    'fecha pausa', 'pausa', 'pausada', 'fecha de pausa'
 *
 * Modo:
 *   DRY-RUN (defecto):  /views/mant_prev_import_tiempo_pausa.php
 *   COMMIT:             /views/mant_prev_import_tiempo_pausa.php?commit=1
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

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=UTF-8');

$CANDIDATES = [
    __DIR__ . '/../input/listado_maquinas.xlsx',
    __DIR__ . '/../input/Copia de Copia de Listado_Maquinas_Mantenimiento_20260519_103414.xlsx',
    __DIR__ . '/../input/Listado_Maquinas_Mantenimiento.xlsx',
];
$INPUT = null;
foreach ($CANDIDATES as $p) { if (is_file($p)) { $INPUT = $p; break; } }
if (!$INPUT) {
    echo "[ERR] No encuentro el archivo. Buscado en:\n";
    foreach ($CANDIDATES as $p) echo "  - {$p}\n";
    exit(1);
}
if (!extension_loaded('zip')) {
    echo "[ERR] Extension PHP 'zip' no cargada.\n";
    exit(1);
}

// COMMIT por URL (?commit=1) o por CLI (--commit / -c)
$COMMIT = !empty($_GET['commit']);
if (PHP_SAPI === 'cli' && !empty($argv)) {
    foreach ($argv as $arg) {
        if ($arg === '--commit' || $arg === '-c') { $COMMIT = true; break; }
    }
}
echo "=== IMPORT TIEMPO ESTIMADO + FECHA PAUSA ===\n";
echo "Modo  : " . ($COMMIT ? "COMMIT" : "DRY-RUN") . "\n";
echo "Origen: {$INPUT}\n\n";

// Verificar migracion 011 (tiempo_estimado) y 008 (fecha_pausado)
$mig011 = Db::pgFetchOne("SELECT 1 FROM schema_migrations WHERE version = '011'");
$mig008 = Db::pgFetchOne("SELECT 1 FROM schema_migrations WHERE version = '008'");
if (!$mig011 || !$mig008) {
    echo "[ERR] Faltan migraciones requeridas (008 fecha_pausado, 011 tiempo_estimado).\n";
    echo "  Ejecuta:  http://localhost/PLAN_ATTAINMENT/views/mant_prev_install_pg.php\n";
    exit(1);
}
echo "✓ Migraciones 008 y 011 aplicadas.\n";

// ─────────────────────────────────────────────────────────────
// 1) Leer xlsx
// ─────────────────────────────────────────────────────────────
$r = IOFactory::createReaderForFile($INPUT);
$r->setReadDataOnly(true);
$ss = $r->load($INPUT);

// El listado tiene UNA HOJA POR MAQUINA: la maquina = nombre de la hoja,
// cada fila es una tarea preventiva con sus campos (Tarea, Periodicidad,
// Descripcion, Alta/Baja, Tipo mantenimiento, Realizacion, Pausada desde,
// Bloqueo, Tiempo Estimado).
$sheetNames = $ss->getSheetNames();
echo "Hojas detectadas: " . count($sheetNames) . " (1 por maquina)\n";

function colIdxFromHeader($sheet, int $highCol, array $patterns, int $headerRow = 1): ?int {
    for ($c = 1; $c <= $highCol; $c++) {
        $L = Coordinate::stringFromColumnIndex($c);
        $v = strtolower(trim((string)($sheet->getCell($L . $headerRow, false)?->getValue() ?? '')));
        if ($v === '') continue;
        foreach ($patterns as $p) {
            if (mb_strpos($v, $p) !== false) return $c;
        }
    }
    return null;
}

// Helpers
function parseTiempo($v): ?int {
    if ($v === null) return null;
    if (is_numeric($v)) {
        $f = (float)$v;
        if ($f <= 0) return null;
        // La columna del .xlsx se llama "Tiempo Estimado (min)" → ya viene en
        // minutos. Excepción: si una celda está formateada como hora (HH:MM)
        // Excel la guarda internamente como fracción de día < 1; en ese caso
        // la convertimos a minutos.
        if ($f < 1) return (int)round($f * 1440);
        return (int)round($f);
    }
    $s = trim((string)$v);
    if ($s === '') return null;
    // HH:MM o H:MM textual
    if (preg_match('/^(\d{1,3}):(\d{1,2})(?::\d+)?$/', $s, $m)) {
        return ((int)$m[1]) * 60 + (int)$m[2];
    }
    // Si viene como texto numerico ("30", "1,5"), tratamos igual que numerico:
    // <1 = fraccion de dia, >=1 = minutos. Para horas con sufijo "h"/"hr" el
    // usuario debera escribirlas explicitamente como HH:MM.
    $s2 = preg_replace('/[^0-9,\.]/', '', $s);
    $s2 = str_replace(',', '.', (string)$s2);
    if (is_numeric($s2)) {
        $f = (float)$s2;
        if ($f <= 0) return null;
        if ($f < 1) return (int)round($f * 1440);
        return (int)round($f);
    }
    return null;
}
function parseFecha($v): ?string {
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

// ─────────────────────────────────────────────────────────────
// 2) Recorrer TODAS las hojas, construir lista de actualizaciones
// ─────────────────────────────────────────────────────────────
$updates = []; $totalFilas = 0; $dups = [];
$pausaSet = 0; $tiempoSet = 0;
$hojasOk = 0; $hojasMal = 0; $samplesHeaders = [];

foreach ($sheetNames as $sheetName) {
    $sheet = $ss->getSheetByName($sheetName);
    if (!$sheet) continue;
    $maq = trim((string)$sheetName);
    if ($maq === '') continue;

    $highRow = (int)$sheet->getHighestRow();
    $highCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());

    // Detectar columnas: probamos filas 1, 2, 3, 4 (algunas hojas tienen
    // "Acciones preventivas · X" en F1, metadata "Codigo: ... Tareas: N" en F2
    // y la cabecera real en F3 o F4). Exigimos que en la MISMA fila aparezcan
    // tarea + (tiempo o pausa); si solo aparece tarea (p.ej. "Tareas: 34" en
    // la metadata), seguimos buscando en filas siguientes.
    $headerRow = null;
    $cTarea = null; $cTiempo = null; $cPausa = null;
    foreach ([1, 2, 3, 4, 5, 6] as $tryRow) {
        $tryTarea  = colIdxFromHeader($sheet, $highCol, ['id tarea', 'id_tarea', 'cod tarea', 'cod_tarea', 'tarea'], $tryRow);
        if ($tryTarea === null) continue;
        $tryTiempo = colIdxFromHeader($sheet, $highCol, ['tiempo estimado', 'tiempo_estimado', 'tiempo', 'minutos', 'duracion', 'duración'], $tryRow);
        $tryPausa  = colIdxFromHeader($sheet, $highCol, ['pausada desde', 'fecha pausa', 'fecha de pausa', 'pausada', 'pausa'], $tryRow);
        if ($tryTiempo !== null || $tryPausa !== null) {
            $cTarea = $tryTarea; $cTiempo = $tryTiempo; $cPausa = $tryPausa;
            $headerRow = $tryRow;
            break;
        }
        // Si solo encontramos tarea (caso "Tareas: 34" en metadata), guardamos
        // como mejor candidato pero seguimos intentando filas siguientes.
    }

    if (!$cTarea || (!$cTiempo && !$cPausa)) {
        $hojasMal++;
        if (count($samplesHeaders) < 5) {
            $dump = "  · '{$maq}':\n";
            for ($fr = 1; $fr <= 5; $fr++) {
                $hdrs = [];
                for ($c = 1; $c <= min(12, $highCol); $c++) {
                    $L = Coordinate::stringFromColumnIndex($c);
                    $hdrs[] = $L . ':' . (string)($sheet->getCell($L . $fr, false)?->getValue() ?? '');
                }
                $dump .= "      F{$fr}: " . implode(' | ', $hdrs) . "\n";
            }
            $samplesHeaders[] = $dump;
        }
        continue;
    }
    $hojasOk++;
    // Las filas de datos empiezan en headerRow+1
    $firstDataRow = $headerRow + 1;

    $LTarea  = Coordinate::stringFromColumnIndex($cTarea);
    $LTiempo = $cTiempo ? Coordinate::stringFromColumnIndex($cTiempo) : null;
    $LPausa  = $cPausa  ? Coordinate::stringFromColumnIndex($cPausa)  : null;
    for ($rr = $firstDataRow; $rr <= $highRow; $rr++) {
        $tar = trim((string)($sheet->getCell($LTarea . $rr, false)?->getValue() ?? ''));
        if ($tar === '') continue;
        $totalFilas++;

        $tiempo = $LTiempo ? parseTiempo($sheet->getCell($LTiempo . $rr, false)?->getValue()) : null;
        $pausa  = $LPausa  ? parseFecha($sheet->getCell($LPausa  . $rr, false)?->getValue()) : null;

        if ($tiempo !== null) $tiempoSet++;
        if ($pausa  !== null) $pausaSet++;

        $k = $maq . '|' . $tar;
        if (isset($updates[$k])) {
            $dups[] = ['maquina' => $maq, 'tarea' => $tar];
            if ($updates[$k]['tiempo_estimado'] === null && $tiempo !== null) $updates[$k]['tiempo_estimado'] = $tiempo;
            if ($updates[$k]['fecha_pausado']  === null && $pausa  !== null) $updates[$k]['fecha_pausado']  = $pausa;
            continue;
        }
        $updates[$k] = [
            'cod_maquina_mant' => $maq,
            'tarea'            => $tar,
            'tiempo_estimado'  => $tiempo,
            'fecha_pausado'    => $pausa,
        ];
    }
}

echo "Hojas procesadas OK : {$hojasOk}\n";
echo "Hojas saltadas      : {$hojasMal}\n";
if (!empty($samplesHeaders)) {
    echo "  (muestras de cabecera en hojas saltadas):\n";
    foreach ($samplesHeaders as $s) echo $s . "\n";
}
echo "Filas leidas        : {$totalFilas}\n";
echo "  · con tiempo_estimado : {$tiempoSet}\n";
echo "  · con fecha_pausado   : {$pausaSet}\n";
echo "  · duplicados ignorados: " . count($dups) . "\n";
echo "Tareas distintas a actualizar: " . count($updates) . "\n\n";

if (empty($updates)) {
    echo "[!] No hay nada que actualizar. Saliendo.\n";
    exit(0);
}

// ─────────────────────────────────────────────────────────────
// 3) UPDATE en transaccion
// ─────────────────────────────────────────────────────────────
$pdo = Db::pg();
$pdo->beginTransaction();

try {
    $stUpd = $pdo->prepare("
        UPDATE mant_plan
           SET tiempo_estimado = COALESCE(:tiempo, tiempo_estimado),
               fecha_pausado   = COALESCE(:pausa,  fecha_pausado)
         WHERE cod_maquina_mant = :cmm
           AND tarea            = :tarea
    ");
    $upd = 0; $noMatch = 0; $matchSinDato = 0; $noMatchSample = [];
    foreach ($updates as $u) {
        if ($u['tiempo_estimado'] === null && $u['fecha_pausado'] === null) { $matchSinDato++; continue; }
        $stUpd->execute([
            ':tiempo' => $u['tiempo_estimado'],
            ':pausa'  => $u['fecha_pausado'],
            ':cmm'    => $u['cod_maquina_mant'],
            ':tarea'  => $u['tarea'],
        ]);
        $n = $stUpd->rowCount();
        if ($n === 0) {
            $noMatch++;
            if (count($noMatchSample) < 15) {
                $noMatchSample[] = "{$u['cod_maquina_mant']} / {$u['tarea']}";
            }
        }
        $upd += $n;
    }
    echo "Resultado:\n";
    echo "  · filas mant_plan actualizadas : {$upd}\n";
    echo "  · filas (maq,tarea) sin match  : {$noMatch}\n";
    if (!empty($noMatchSample)) {
        echo "      (no estan en mant_plan):\n";
        foreach ($noMatchSample as $s) echo "        - {$s}\n";
        if ($noMatch > count($noMatchSample)) echo "        - ... y " . ($noMatch - count($noMatchSample)) . " mas\n";
    }
    echo "  · filas .xlsx sin dato util    : {$matchSinDato}\n";

    // Stats finales
    $sttotal = Db::pgFetchOne("
        SELECT COUNT(*) FILTER (WHERE tiempo_estimado IS NOT NULL) AS con_tiempo,
               COUNT(*) FILTER (WHERE fecha_pausado     IS NOT NULL) AS con_pausa,
               COUNT(*)                                            AS total
          FROM mant_plan
    ");
    echo "\nBD despues de update:\n";
    echo "  · mant_plan total            : {$sttotal['total']}\n";
    echo "  · con tiempo_estimado        : {$sttotal['con_tiempo']}\n";
    echo "  · con fecha_pausado (pausadas) : {$sttotal['con_pausa']}\n";

    if ($COMMIT) {
        $pdo->commit();
        echo "\n✓✓✓ COMMIT realizado.\n";
    } else {
        $pdo->rollBack();
        echo "\n↺ ROLLBACK (modo dry-run). Para confirmar lanza ?commit=1.\n";
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\n✗ ERROR (rollback automatico): " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(2);
}

echo "=== FIN ===\n";
