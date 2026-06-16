<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Export XLSX de TODAS las referencias con sus componentes (solo componentes por
 * pieza), desde el escandallo SAGE (KHITT_estructuras). Una fila por componente.
 */
try {
    // 1) Líneas de componente (componente <> propio artículo). DISTINCT por dups de la vista.
    $rows = fetchAll('sage', "
        SELECT DISTINCT LTRIM(RTRIM(e.codigoarticulo)) AS ref, e.ordenn AS ordenn,
               LTRIM(RTRIM(e.articulocomponente)) AS comp,
               LTRIM(RTRIM(e.centrotrabajo)) AS centro, e.unidades AS unidades
        FROM KHITT_estructuras e
        WHERE LTRIM(RTRIM(e.articulocomponente)) <> LTRIM(RTRIM(e.codigoarticulo))
    ");

    // 2) Descripciones + ReferenciaEdi_ de todos los códigos implicados (refs + componentes).
    $codes = [];
    foreach ($rows as $r) { $codes[(string)$r['ref']] = true; $codes[(string)$r['comp']] = true; }
    $codes = array_keys($codes);
    $art = [];   // cod => ['desc'=>, 'edi'=>]
    foreach (array_chunk($codes, 800) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        foreach (fetchAll('sage',
            "SELECT LTRIM(RTRIM(CodigoArticulo)) AS c, DescripcionArticulo AS d, ReferenciaEdi_ AS edi
             FROM Articulos WHERE LTRIM(RTRIM(CodigoArticulo)) IN ($ph)", $chunk) as $a) {
            $c = (string)$a['c'];
            if (!isset($art[$c])) $art[$c] = ['desc' => trim((string)$a['d']), 'edi' => trim((string)$a['edi'])];
        }
    }

    // 3) Nombre de máquina por centro (MAPEX cfg_maquina).
    $maqName = [];
    try {
        foreach (fetchAll('mapex', "SELECT LTRIM(RTRIM(Cod_maquina)) AS c, Desc_maquina AS d FROM cfg_maquina") as $m)
            $maqName[(string)$m['c']] = trim((string)$m['d']);
    } catch (\Throwable $e) { /* sin MAPEX: solo código de centro */ }

    // Ordenar: por referencia (desc) y luego orden de escandallo.
    usort($rows, function ($a, $b) use ($art) {
        $da = $art[(string)$a['ref']]['desc'] ?? (string)$a['ref'];
        $db = $art[(string)$b['ref']]['desc'] ?? (string)$b['ref'];
        return strcasecmp($da, $db) ?: ((int)$a['ordenn'] <=> (int)$b['ordenn']);
    });

    // 4) Construir XLSX.
    $book = new Spreadsheet();
    $ws = $book->getActiveSheet();
    $ws->setTitle('Componentes');
    $headers = ['Cód. referencia', 'Referencia', 'Cód. SAGE', 'Cód. componente', 'Componente', 'Centro', 'Máquina', 'Uds/ud'];
    foreach ($headers as $i => $hh) $ws->setCellValue([$i + 1, 1], $hh);
    $last = Coordinate::stringFromColumnIndex(count($headers));
    $ws->getStyle("A1:{$last}1")->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));
    $ws->getStyle("A1:{$last}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2D4D7A');
    $ws->getStyle("A1:{$last}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getRowDimension(1)->setRowHeight(20);

    $row = 2;
    foreach ($rows as $r) {
        $ref = (string)$r['ref']; $comp = (string)$r['comp']; $centro = (string)$r['centro'];
        $ws->setCellValueExplicit([1, $row], $ref, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValue([2, $row], $art[$ref]['desc'] ?? $ref);
        $ws->setCellValueExplicit([3, $row], $art[$ref]['edi'] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValueExplicit([4, $row], $comp, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValue([5, $row], $art[$comp]['desc'] ?? $comp);
        $ws->setCellValue([6, $row], $centro);
        $ws->setCellValue([7, $row], $centro !== '' ? ($maqName[$centro] ?? $centro) : '');
        $ws->setCellValue([8, $row], round((float)$r['unidades'], 6));
        $row++;
    }
    $lastRow = $row - 1;
    if ($lastRow >= 2) {
        $ws->getStyle("H2:H$lastRow")->getNumberFormat()->setFormatCode('#,##0.000000');
        $ws->getStyle("A1:{$last}$lastRow")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C9D4E3');
    }
    foreach ([1=>14,2=>42,3=>14,4=>16,5=>42,6=>10,7=>18,8=>12] as $c => $w)
        $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($w);
    $ws->freezePane('A2');

    $fname = 'Escandallo_componentes_por_referencia.xlsx';
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');
    IOFactory::createWriter($book, 'Xlsx')->save('php://output');
    exit;
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
