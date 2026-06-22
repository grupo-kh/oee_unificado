<?php
/**
 * Exportación a Excel de Matriz 2 (informe completo):
 * Filas = Máquina → Referencia. Columnas = cabecera de 3 niveles
 * Actividad (fila 4) / Categoría (fila 5) / Motivo (fila 6). Por cada (act×cat)
 * con datos hay 1 columna RESUMEN de categoría + N columnas de MOTIVO presentes
 * en ese act×cat. En Excel se muestran SIEMPRE todas las columnas (sin
 * expandir/colapsar): el detalle de paro está en columnas, no en filas.
 *
 * Reutiliza exactamente el mismo cálculo que oee_unificado_matriz2.php (mismos
 * filtros que la Matriz original: excluye Cod_paro=11, cuenta paros sin producto).
 *
 * GET: fecha_desde, fecha_hasta (req), seccion, turnos (CSV).
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
require_once __DIR__ . '/../lib/Db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Calcula el mismo informe que el endpoint JSON pero devolviéndolo como array
// (matriz2Data está definida en oee_unificado_matriz2.php y NO emite salida).
require_once __DIR__ . '/oee_unificado_matriz2.php';
$d = matriz2Data();
if ($d === null) { http_response_code(500); echo 'No se pudo generar el informe.'; exit; }

$acts = $d['actividades'] ?? [];
$cats = $d['categorias'] ?? [];
$parosPorCat = $d['paros_por_categoria'] ?? [];
$maqs = $d['maquinas'] ?? [];
$seccion = $d['seccion'] ?? '';
$fdesde = (string) getParam('fecha_desde');
$fhasta = (string) getParam('fecha_hasta');

// Columnas-hoja (espejo del modelo de la UI): por cada (actividad × categoría) con
// datos → 1 columna RESUMEN de categoría + N columnas de MOTIVO presentes en ESE
// act×cat concreto. El detalle de paro ahora son COLUMNAS (no filas hacia abajo).
//
// Predicados de presencia (idénticos al backend / a la UI):
//   - (act,cat) existe ⇔ alguna máquina tiene celdas["ACT||CAT"] > 0.
//   - motivo p presente en (act,cat) ⇔ alguna máquina tiene celdas["ACT||CAT||p"] > 0.
// El universo de motivos de una categoría es $parosPorCat[cat] (ya ordenado por el
// backend); se filtran los que tengan dato en ESA actividad concreta. Cada hoja-motivo
// guarda su clave triple completa "ACT||CAT||PARO", de modo que su valor SOLO puede caer
// en su propia columna: el mismo motivo bajo dos act×cat distintos son dos columnas
// distintas con claves distintas (evita el bug antiguo de repetir el valor por toda la
// actividad). Regla dura: cada hoja lee únicamente celdas[$leaf['key']].
$leaves = [];   // orden EXACTO de columnas, de izquierda a derecha
foreach ($acts as $a) foreach ($cats as $c) {
    $kCat = "$a||$c";
    $hayCat = false;
    foreach ($maqs as $m) if (($m['celdas'][$kCat] ?? 0) > 0) { $hayCat = true; break; }
    if (!$hayCat) continue;
    // Columna resumen de la categoría (siempre presente para el par con datos).
    $leaves[] = ['tipo' => 'resumen', 'act' => $a, 'cat' => $c, 'paro' => null, 'key' => $kCat];
    // Columnas de motivo presentes en este act×cat (orden de paros_por_categoria).
    foreach (($parosPorCat[$c] ?? []) as $p) {
        $kPar = "$a||$c||$p";
        $hayPar = false;
        foreach ($maqs as $m) if (($m['celdas'][$kPar] ?? 0) > 0) { $hayPar = true; break; }
        if ($hayPar) $leaves[] = ['tipo' => 'motivo', 'act' => $a, 'cat' => $c, 'paro' => $p, 'key' => $kPar];
    }
}

$book = new Spreadsheet();
$ws = $book->getActiveSheet();
$ws->setTitle('Matriz 2');

$nLeaf  = count($leaves);
$colTot = $nLeaf + 2;   // columna A + N hojas + columna TOTAL (h)

$ws->setCellValue('A1', 'OEE Unificado · Matriz 2 — Paros por máquina / referencia (Actividad / Categoría / Motivo en columnas)');
$ws->mergeCells('A1:' . Coordinate::stringFromColumnIndex($colTot) . '1');
$ws->setCellValue('A2', "Sección: " . ($seccion ?: 'Todas') . "   ·   $fdesde a $fhasta   ·   horas de paro (excluye CERRADA cód. 11)");
$ws->mergeCells('A2:' . Coordinate::stringFromColumnIndex($colTot) . '2');
$ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$ws->getStyle('A2')->getFont()->setItalic(true)->setSize(10);

// Cabecera triple: fila 4 = Actividad (merge), fila 5 = Categoría (merge), fila 6 = Motivo.
$rH1 = 4; $rH2 = 5; $rH3 = 6;
$ws->setCellValue('A' . $rH1, 'Máquina / Referencia');
$ws->mergeCells('A' . $rH1 . ':A' . $rH3);

// Sin paros en el rango/sección: el libro sería degenerado (solo A y TOTAL). Se
// escribe la misma nota que muestra la UI y se cierra, para un archivo autoexplicativo.
if ($nLeaf === 0) {
    $ws->setCellValue('A' . ($rH3 + 1), 'Sin paros para el filtro seleccionado');
    $ws->getColumnDimension('A')->setWidth(46);
    $nombre = 'Matriz2_' . ($seccion ?: 'Todas') . "_{$fdesde}_a_{$fhasta}.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Cache-Control: max-age=0');
    (IOFactory::createWriter($book, 'Xlsx'))->save('php://output');
    exit;
}

// Recorrido único por las hojas, abriendo y cerrando los merges de Actividad y de
// Categoría cuando cambia el grupo. La fila 6 escribe una celda por hoja.
$ci = 2;
$idx = 0;
$nL  = count($leaves);
while ($idx < $nL) {
    $a = $leaves[$idx]['act'];
    $iniAct = $ci;
    // Recorrer todas las hojas de esta actividad.
    while ($idx < $nL && $leaves[$idx]['act'] === $a) {
        $c = $leaves[$idx]['cat'];
        $iniCat = $ci;
        // Recorrer todas las hojas de esta categoría (resumen + sus motivos).
        while ($idx < $nL && $leaves[$idx]['act'] === $a && $leaves[$idx]['cat'] === $c) {
            $leaf = $leaves[$idx];
            $col6 = Coordinate::stringFromColumnIndex($ci);
            $ws->setCellValue($col6 . $rH3, $leaf['tipo'] === 'resumen' ? 'Resumen' : $leaf['paro']);
            $ci++;
            $idx++;
        }
        // Merge de Categoría (fila 5) sobre [iniCat .. ci-1].
        $cCat1 = Coordinate::stringFromColumnIndex($iniCat);
        $cCat2 = Coordinate::stringFromColumnIndex($ci - 1);
        $ws->setCellValue($cCat1 . $rH2, $c);
        if ($ci - 1 > $iniCat) $ws->mergeCells($cCat1 . $rH2 . ':' . $cCat2 . $rH2);
    }
    // Merge de Actividad (fila 4) sobre [iniAct .. ci-1].
    $cAct1 = Coordinate::stringFromColumnIndex($iniAct);
    $cAct2 = Coordinate::stringFromColumnIndex($ci - 1);
    $ws->setCellValue($cAct1 . $rH1, $a);
    if ($ci - 1 > $iniAct) $ws->mergeCells($cAct1 . $rH1 . ':' . $cAct2 . $rH1);
}

// Columna TOTAL (h): merge vertical sobre las 3 filas de cabecera.
$cT = Coordinate::stringFromColumnIndex($colTot);
$ws->setCellValue($cT . $rH1, 'TOTAL (h)');
$ws->mergeCells($cT . $rH1 . ':' . $cT . $rH3);

// Estilo de cabecera (granate, blanco, negrita, centrada) sobre A4:{colTot}6.
$rngCab = 'A' . $rH1 . ':' . $cT . $rH3;
$ws->getStyle($rngCab)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$ws->getStyle($rngCab)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3A2426');
$ws->getStyle($rngCab)->getAlignment()
   ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

$r = $rH3 + 1;   // primera fila de datos
$f2 = fn($v) => ($v == null || $v == 0) ? null : round($v, 2);

foreach ($maqs as $m) {
    // Fila máquina (grupo).
    $ws->setCellValue("A$r", '🏭 ' . $m['maquina']);
    $ci = 2;
    foreach ($leaves as $leaf) {
        $ws->setCellValue(Coordinate::stringFromColumnIndex($ci) . $r, $f2($m['celdas'][$leaf['key']] ?? 0));
        $ci++;
    }
    $ws->setCellValue($cT . $r, round($m['total_horas'], 2));
    $ws->getStyle("A$r:$cT$r")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $ws->getStyle("A$r:$cT$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('5A4446');
    $r++;
    foreach ($m['referencias'] as $ref) {
        // Fila referencia.
        $ws->setCellValue("A$r", '   ' . $ref['desc'] . ($ref['cod'] !== '' ? "  ({$ref['cod']})" : ''));
        $ci = 2;
        foreach ($leaves as $leaf) {
            $ws->setCellValue(Coordinate::stringFromColumnIndex($ci) . $r, $f2($ref['celdas'][$leaf['key']] ?? 0));
            $ci++;
        }
        $ws->setCellValue($cT . $r, round($ref['total_horas'], 2));
        $ws->getStyle("A$r")->getFont()->setBold(true);
        $r++;
    }
}

// Ancho columnas + bordes.
$ws->getColumnDimension('A')->setWidth(46);
for ($i = 2; $i <= $colTot; $i++) $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(13);
$ws->getStyle('A' . $rH1 . ':' . $cT . ($r - 1))
   ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$ws->freezePane('B' . ($rH3 + 1));

$nombre = 'Matriz2_' . ($seccion ?: 'Todas') . "_{$fdesde}_a_{$fhasta}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: max-age=0');
(IOFactory::createWriter($book, 'Xlsx'))->save('php://output');
exit;
