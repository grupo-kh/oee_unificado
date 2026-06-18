<?php
/**
 * Exportación a Excel de Matriz 2 (informe completo y expandido):
 * Máquina → Referencia → Paro, en columnas Actividad × Categoría (Tipo Paro 1).
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

// Columnas (actividad × categoría) con datos.
$cols = [];
foreach ($acts as $a) foreach ($cats as $c) {
    $k = "$a||$c";
    foreach ($maqs as $m) if (($m['celdas'][$k] ?? 0) > 0) { $cols[] = ['act' => $a, 'cat' => $c, 'key' => $k]; break; }
}

$book = new Spreadsheet();
$ws = $book->getActiveSheet();
$ws->setTitle('Matriz 2');

$ws->setCellValue('A1', 'OEE Unificado · Matriz 2 — Paros por máquina / referencia / paro');
$ws->mergeCells('A1:' . Coordinate::stringFromColumnIndex(count($cols) + 2) . '1');
$ws->setCellValue('A2', "Sección: " . ($seccion ?: 'Todas') . "   ·   $fdesde a $fhasta   ·   horas de paro (excluye CERRADA cód. 11)");
$ws->mergeCells('A2:' . Coordinate::stringFromColumnIndex(count($cols) + 2) . '2');
$ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$ws->getStyle('A2')->getFont()->setItalic(true)->setSize(10);

// Cabecera doble: fila 4 = actividad (merge), fila 5 = categoría.
$rH1 = 4; $rH2 = 5;
$ws->setCellValue('A' . $rH1, 'Máquina / Referencia / Paro');
$ws->mergeCells('A' . $rH1 . ':A' . $rH2);
$col = 2;
$actSpan = [];
foreach ($cols as $c) $actSpan[$c['act']] = ($actSpan[$c['act']] ?? 0) + 1;
$shown = [];
$ci = 2;
foreach ($acts as $a) {
    if (!isset($actSpan[$a])) continue;
    $c1 = Coordinate::stringFromColumnIndex($ci);
    $c2 = Coordinate::stringFromColumnIndex($ci + $actSpan[$a] - 1);
    $ws->setCellValue($c1 . $rH1, $a);
    if ($actSpan[$a] > 1) $ws->mergeCells($c1 . $rH1 . ':' . $c2 . $rH1);
    $ci += $actSpan[$a];
}
$ci = 2;
foreach ($cols as $c) { $ws->setCellValue(Coordinate::stringFromColumnIndex($ci) . $rH2, $c['cat']); $ci++; }
$colTot = count($cols) + 2;
$ws->setCellValue(Coordinate::stringFromColumnIndex($colTot) . $rH1, 'TOTAL (h)');
$ws->mergeCells(Coordinate::stringFromColumnIndex($colTot) . $rH1 . ':' . Coordinate::stringFromColumnIndex($colTot) . $rH2);

$ws->getStyle('A' . $rH1 . ':' . Coordinate::stringFromColumnIndex($colTot) . $rH2)
   ->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$ws->getStyle('A' . $rH1 . ':' . Coordinate::stringFromColumnIndex($colTot) . $rH2)
   ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3A2426');

$r = $rH2 + 1;
$f2 = fn($v) => ($v == null || $v == 0) ? null : round($v, 2);

foreach ($maqs as $m) {
    // Fila máquina.
    $ws->setCellValue("A$r", '🏭 ' . $m['maquina']);
    $ci = 2;
    foreach ($cols as $c) { $ws->setCellValue(Coordinate::stringFromColumnIndex($ci) . $r, $f2($m['celdas'][$c['key']] ?? 0)); $ci++; }
    $ws->setCellValue(Coordinate::stringFromColumnIndex($colTot) . $r, round($m['total_horas'], 2));
    $ws->getStyle("A$r:" . Coordinate::stringFromColumnIndex($colTot) . $r)
       ->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $ws->getStyle("A$r:" . Coordinate::stringFromColumnIndex($colTot) . $r)
       ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('5A4446');
    $r++;
    foreach ($m['referencias'] as $ref) {
        // Fila referencia.
        $ws->setCellValue("A$r", '   ' . $ref['desc'] . ($ref['cod'] !== '' ? "  ({$ref['cod']})" : ''));
        $ci = 2;
        foreach ($cols as $c) { $ws->setCellValue(Coordinate::stringFromColumnIndex($ci) . $r, $f2($ref['celdas'][$c['key']] ?? 0)); $ci++; }
        $ws->setCellValue(Coordinate::stringFromColumnIndex($colTot) . $r, round($ref['total_horas'], 2));
        $ws->getStyle("A$r")->getFont()->setBold(true);
        $r++;
        // Filas paro (informe expandido completo). El paro pertenece a UNA categoría
        // ($cate): solo se escribe en columnas cuya categoría coincide (usar $c['cat'],
        // no $cate, en la clave — si no, el valor se repetiría por toda la actividad).
        foreach ($cats as $cate) foreach (($parosPorCat[$cate] ?? []) as $paro) {
            $hayDato = false; $tp = 0;
            foreach ($cols as $c) {
                if ($c['cat'] !== $cate) continue;
                if (($ref['celdas'][$c['act'] . '||' . $cate . '||' . $paro] ?? 0) > 0) { $hayDato = true; break; }
            }
            if (!$hayDato) continue;
            $ws->setCellValue("A$r", '        ↳ ' . $paro . ' · ' . $cate);
            $ci = 2;
            foreach ($cols as $c) {
                $v = ($c['cat'] === $cate) ? ($ref['celdas'][$c['act'] . '||' . $cate . '||' . $paro] ?? 0) : 0;
                $tp += $v; $ws->setCellValue(Coordinate::stringFromColumnIndex($ci) . $r, $f2($v)); $ci++;
            }
            $ws->setCellValue(Coordinate::stringFromColumnIndex($colTot) . $r, round($tp, 2));
            $ws->getStyle("A$r:" . Coordinate::stringFromColumnIndex($colTot) . $r)->getFont()->setSize(9)->getColor()->setRGB('6A5658');
            $r++;
        }
    }
}

// Ancho columnas + bordes.
$ws->getColumnDimension('A')->setWidth(46);
for ($i = 2; $i <= $colTot; $i++) $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(13);
$ws->getStyle('A' . $rH1 . ':' . Coordinate::stringFromColumnIndex($colTot) . ($r - 1))
   ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$ws->freezePane('B' . ($rH2 + 1));

$nombre = 'Matriz2_' . ($seccion ?: 'Todas') . "_{$fdesde}_a_{$fhasta}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: max-age=0');
(IOFactory::createWriter($book, 'Xlsx'))->save('php://output');
exit;
