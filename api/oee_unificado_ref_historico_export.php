<?php
/**
 * Export XLSX · Histórico de fabricación por referencia.
 *
 * Hojas:
 *   1) Histórico — totales por OF (sin fecha), máquinas que la han producido,
 *      días, OK, NOK
 *   2) Comparativa — para cada OF, OK/NOK por máquina + panel resumen
 *      (mejor / peor rendimiento, promedio, total NOK)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/oee_unificado_ref_historico.php_data.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

try {
    ini_set('memory_limit', '512M');

    $codProd = (string) ($_GET['cod_producto'] ?? '');
    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');

    if ($codProd === '') throw new RuntimeException('cod_producto requerido');
    refHistValidarRango($fdesde, $fhasta);

    $prod    = refHistFetchProducto($codProd);
    $ofs     = refHistFetchOfsConMaquinas($codProd, $fdesde, $fhasta);
    $tot     = refHistTotalesOfs($ofs);
    $stats   = refHistComparativaStats($ofs);
    $ranking = refHistMaquinaRanking($ofs);

    $book = new Spreadsheet();
    $book->getProperties()
        ->setCreator('KH Plan Attainment')
        ->setTitle('Histórico referencia · ' . $prod['cod_producto'])
        ->setSubject('Histórico de fabricación por referencia');

    // ===== HOJA 1: Histórico por OF =====
    $ws = $book->getActiveSheet();
    $ws->setTitle('Histórico');

    $ws->setCellValue('A1', 'Histórico de fabricación · ' . $prod['desc_producto'] . ' (' . $prod['cod_producto'] . ')');
    $ws->mergeCells('A1:E1');
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('8C181A');
    $ws->getRowDimension(1)->setRowHeight(22);

    $sub = 'Rango: ' . $fdesde . ' → ' . $fhasta
         . '  ·  OFs: ' . (int)$tot['num_ofs']
         . '  ·  Máquinas: ' . (int)$tot['num_maquinas']
         . '  ·  Días con producción: ' . (int)$tot['dias']
         . '  ·  Total OK: ' . number_format((int)$tot['unidades_ok'], 0, ',', '.')
         . '  ·  Total NOK: ' . number_format((int)$tot['unidades_nok'], 0, ',', '.')
         . '  ·  Exportado: ' . date('d/m/Y H:i');
    $ws->setCellValue('A2', $sub);
    $ws->mergeCells('A2:E2');
    $ws->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('2D4D7A');
    $ws->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF5F5');
    $ws->getStyle('A2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $ws->getRowDimension(2)->setRowHeight(20);

    $headers = ['OF', 'Máquina(s)', 'Días', 'Unidades OK', 'Unidades NOK'];
    foreach ($headers as $i => $h) $ws->setCellValue([$i + 1, 4], $h);
    $ws->getStyle('A4:E4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $ws->getStyle('A4:E4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('8C181A');
    $ws->getStyle('A4:E4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyle('A4:E4')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('6D1214');

    $r = 5;
    if (empty($ofs)) {
        $ws->setCellValue('A5', 'Sin fabricaciones en el rango seleccionado para esta referencia.');
        $ws->mergeCells('A5:E5');
        $ws->getStyle('A5')->getFont()->setItalic(true)->getColor()->setRGB('888888');
    } else {
        foreach ($ofs as $of) {
            $maqsTxt = implode(', ', array_map(fn($m) => $m['maquina'], $of['maquinas']));
            $ws->setCellValue([1, $r], $of['cod_of']);
            $ws->setCellValue([2, $r], $maqsTxt);
            $ws->setCellValue([3, $r], (int)$of['num_dias']);
            $ws->setCellValue([4, $r], (int)$of['unidades_ok']);
            $ws->setCellValue([5, $r], (int)$of['unidades_nok']);
            $ws->getStyle("B{$r}")->getAlignment()->setWrapText(true);
            $r++;
        }
        $ws->setCellValue([1, $r], 'TOTAL');
        $ws->mergeCells("A{$r}:C{$r}");
        $ws->setCellValue([4, $r], (int)$tot['unidades_ok']);
        $ws->setCellValue([5, $r], (int)$tot['unidades_nok']);
        $ws->getStyle("A{$r}:E{$r}")->getFont()->setBold(true);
        $ws->getStyle("A{$r}:E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F4E0E0');
        $ws->getStyle("A{$r}:C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    $ws->getColumnDimension('A')->setWidth(18);
    $ws->getColumnDimension('B')->setWidth(48);
    $ws->getColumnDimension('C')->setAutoSize(true);
    $ws->getColumnDimension('D')->setAutoSize(true);
    $ws->getColumnDimension('E')->setAutoSize(true);
    $lastRow = max(5, $r);
    $ws->getStyle("C5:E{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
    $ws->getStyle("C5:E{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // ===== HOJA 2: Comparativa =====
    $maqsDistintas = [];
    foreach ($ofs as $of) {
        foreach ($of['maquinas'] as $m) {
            $maqsDistintas[$m['cod_maquina']] = $m['maquina'];
        }
    }
    ksort($maqsDistintas);

    $ws2 = $book->createSheet();
    $ws2->setTitle('Comparativa');

    $colsTot = 9; // máximo usado por la tabla ranking
    $endCol = Coordinate::stringFromColumnIndex($colsTot);

    $ws2->setCellValue('A1', 'Comparativa de OFs por máquina · ' . $prod['desc_producto'] . ' (' . $prod['cod_producto'] . ')');
    $ws2->mergeCells("A1:{$endCol}1");
    $ws2->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('8C181A');
    $ws2->getRowDimension(1)->setRowHeight(22);

    // Etiqueta destacada: mejor máquina del rango (sumando todas las OFs)
    $top = $ranking[0] ?? null;
    if ($top) {
        $isOnly = count($ranking) === 1;
        $bestOverallTxt = $isOnly
            ? '🏅 Única máquina del rango: ' . $top['maquina']
              . '  ·  ' . number_format($top['uds_h'], 2, ',', '.') . ' uds/h'
              . '  ·  ' . number_format($top['unidades_ok'], 0, ',', '.') . ' OK en '
              . number_format($top['horas'], 2, ',', '.') . ' h'
              . '  ·  ' . $top['num_ofs'] . ' OF' . ($top['num_ofs'] === 1 ? '' : 's')
            : '🏅 Mejor máquina del rango (sumando todas las OFs): ' . $top['maquina']
              . '  ·  ' . number_format($top['uds_h'], 2, ',', '.') . ' uds/h'
              . '  ·  ' . number_format($top['unidades_ok'], 0, ',', '.') . ' OK en '
              . number_format($top['horas'], 2, ',', '.') . ' h'
              . '  ·  ' . $top['num_ofs'] . ' OF' . ($top['num_ofs'] === 1 ? '' : 's')
              . '  ·  ' . number_format($top['nok_pct'], 2, ',', '.') . '% NOK';
        $ws2->setCellValue('A2', $bestOverallTxt);
        $ws2->mergeCells("A2:{$endCol}2");
        $ws2->getStyle('A2')->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('10B981');
        $ws2->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F7EF');
        $ws2->getStyle('A2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws2->getRowDimension(2)->setRowHeight(22);
    }

    // Panel resumen (mejor/peor pareja OF×máquina + promedio + total NOK)
    $bestTxt  = $stats['mejor'] ? ($stats['mejor']['cod_of'] . ' · ' . $stats['mejor']['maquina'] . ' · ' . number_format($stats['mejor']['uds_h'],  2, ',', '.') . ' uds/h') : '—';
    $worstTxt = $stats['peor']  ? ($stats['peor']['cod_of']  . ' · ' . $stats['peor']['maquina']  . ' · ' . number_format($stats['peor']['uds_h'],   2, ',', '.') . ' uds/h') : '—';
    $sub2 = '🏆 Mayor rendimiento (OF×máq): ' . $bestTxt
          . '  ·  ⬇️ Menor rendimiento (OF×máq): ' . $worstTxt
          . '  ·  ⚖️ Promedio: ' . number_format($stats['promedio'], 2, ',', '.') . ' uds/h'
          . '  ·  🔴 Total NOK: ' . number_format((int)$stats['total_nok'], 0, ',', '.');
    $ws2->setCellValue('A3', $sub2);
    $ws2->mergeCells("A3:{$endCol}3");
    $ws2->getStyle('A3')->getFont()->setSize(10)->getColor()->setRGB('2D4D7A');
    $ws2->getStyle('A3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF5F5');
    $ws2->getStyle('A3')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $ws2->getRowDimension(3)->setRowHeight(34);

    // Tabla ranking completo de máquinas (sumando todas las OFs)
    $rkRow = 5;
    if (!empty($ranking)) {
        $ws2->setCellValue("A{$rkRow}", 'Ranking de máquinas (sumando todas las OFs del rango)');
        $ws2->mergeCells("A{$rkRow}:{$endCol}{$rkRow}");
        $ws2->getStyle("A{$rkRow}")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('8C181A');
        $rkRow++;
        $rkHeaders = ['#', 'Máquina', 'OFs', 'OK', 'NOK', 'Horas', 'uds/h', '% NOK', 'vs mejor'];
        foreach ($rkHeaders as $i => $h) $ws2->setCellValue([$i + 1, $rkRow], $h);
        $rkHr = "A{$rkRow}:I{$rkRow}";
        $ws2->getStyle($rkHr)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $ws2->getStyle($rkHr)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('6D1214');
        $ws2->getStyle($rkHr)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $rkRow++;
        $rkStart = $rkRow;
        foreach ($ranking as $i => $m) {
            $ws2->setCellValue([1, $rkRow], ($i + 1) . ($i === 0 && count($ranking) > 1 ? ' 🏅' : ''));
            $ws2->setCellValue([2, $rkRow], $m['maquina']);
            $ws2->setCellValue([3, $rkRow], (int)$m['num_ofs']);
            $ws2->setCellValue([4, $rkRow], (int)$m['unidades_ok']);
            $ws2->setCellValue([5, $rkRow], (int)$m['unidades_nok']);
            $ws2->setCellValue([6, $rkRow], (float)$m['horas']);
            $ws2->setCellValue([7, $rkRow], (float)$m['uds_h']);
            $ws2->setCellValue([8, $rkRow], (float)$m['nok_pct']);
            $ws2->setCellValue([9, $rkRow], (float)$m['pct_vs_best']);
            if ($i === 0 && count($ranking) > 1) {
                $ws2->getStyle("A{$rkRow}:I{$rkRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6F7EF');
                $ws2->getStyle("A{$rkRow}:I{$rkRow}")->getFont()->setBold(true);
            }
            $rkRow++;
        }
        $rkEnd = $rkRow - 1;
        $ws2->getStyle("C{$rkStart}:F{$rkEnd}")->getNumberFormat()->setFormatCode('#,##0.00');
        $ws2->getStyle("D{$rkStart}:E{$rkEnd}")->getNumberFormat()->setFormatCode('#,##0');
        $ws2->getStyle("G{$rkStart}:G{$rkEnd}")->getNumberFormat()->setFormatCode('#,##0.00 "uds/h"');
        $ws2->getStyle("H{$rkStart}:I{$rkEnd}")->getNumberFormat()->setFormatCode('0.00"%"');
        $ws2->getStyle("C{$rkStart}:I{$rkEnd}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $rkRow++;
    }

    // Detalle por máquina: una sección por máquina con OFs + stats
    $r2 = $rkRow + 1;
    foreach ($ranking as $idx => $m) {
        // Recolectar las OFs en las que esta máquina ha producido
        $cells = [];
        foreach ($ofs as $of) {
            foreach ($of['maquinas'] as $mm) {
                if ($mm['cod_maquina'] === $m['cod_maquina']) {
                    $cells[] = [
                        'cod_of'       => $of['cod_of'],
                        'unidades_ok'  => (int)$mm['unidades_ok'],
                        'unidades_nok' => (int)$mm['unidades_nok'],
                        'horas'        => (float)$mm['horas'],
                        'uds_h'        => (float)$mm['uds_h'],
                        'nok_pct'      => (float)$mm['nok_pct'],
                    ];
                    break;
                }
            }
        }
        if (empty($cells)) continue;

        // Stats por máquina
        $udsValid = array_filter(array_column($cells, 'uds_h'), fn($v) => $v > 0);
        $maxV = !empty($udsValid) ? max($udsValid) : 0;
        $minV = !empty($udsValid) ? min($udsValid) : 0;
        $avgV = !empty($udsValid) ? array_sum($udsValid) / count($udsValid) : 0;
        $tOk  = array_sum(array_column($cells, 'unidades_ok'));
        $tNok = array_sum(array_column($cells, 'unidades_nok'));
        $tAll = $tOk + $tNok;
        $pOk  = $tAll > 0 ? $tOk / $tAll * 100 : 0;
        $pNok = $tAll > 0 ? $tNok / $tAll * 100 : 0;

        $ws2->setCellValue("A{$r2}", 'Máquina: ' . $m['maquina'] . ($idx === 0 && count($ranking) > 1 ? ' 🏅' : ''));
        $ws2->mergeCells("A{$r2}:I{$r2}");
        $ws2->getStyle("A{$r2}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('1A2D4A');
        $ws2->getStyle("A{$r2}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF5F5');
        $r2++;

        // Cabecera de OFs
        $hdr = ['OF', 'uds/h', 'Unidades OK', 'Unidades NOK', 'Horas', '% NOK'];
        foreach ($hdr as $i => $h) $ws2->setCellValue([$i + 1, $r2], $h);
        $ws2->getStyle("A{$r2}:F{$r2}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $ws2->getStyle("A{$r2}:F{$r2}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('6D1214');
        $ws2->getStyle("A{$r2}:F{$r2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $r2++;
        $startOfs = $r2;
        foreach ($cells as $c) {
            $ws2->setCellValue([1, $r2], $c['cod_of']);
            $ws2->setCellValue([2, $r2], $c['uds_h']);
            $ws2->setCellValue([3, $r2], $c['unidades_ok']);
            $ws2->setCellValue([4, $r2], $c['unidades_nok']);
            $ws2->setCellValue([5, $r2], $c['horas']);
            $ws2->setCellValue([6, $r2], $c['nok_pct']);
            $r2++;
        }
        $endOfs = $r2 - 1;
        $ws2->getStyle("B{$startOfs}:B{$endOfs}")->getNumberFormat()->setFormatCode('#,##0.00 "uds/h"');
        $ws2->getStyle("C{$startOfs}:D{$endOfs}")->getNumberFormat()->setFormatCode('#,##0');
        $ws2->getStyle("E{$startOfs}:E{$endOfs}")->getNumberFormat()->setFormatCode('#,##0.00');
        $ws2->getStyle("F{$startOfs}:F{$endOfs}")->getNumberFormat()->setFormatCode('0.00"%"');
        $ws2->getStyle("B{$startOfs}:F{$endOfs}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Stats panel: una fila con todos los valores
        $statsLabels = ['Máx piezas/hora', 'Mín piezas/hora', 'Promedio piezas/hora', 'Total OK', 'Total NOK', '% OK', '% NOK'];
        foreach ($statsLabels as $i => $lbl) $ws2->setCellValue([$i + 1, $r2], $lbl);
        $ws2->getStyle("A{$r2}:G{$r2}")->getFont()->setBold(true)->getColor()->setRGB('6D1214');
        $ws2->getStyle("A{$r2}:G{$r2}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF5F5');
        $ws2->getStyle("A{$r2}:G{$r2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $r2++;
        $ws2->setCellValue([1, $r2], $maxV);
        $ws2->setCellValue([2, $r2], $minV);
        $ws2->setCellValue([3, $r2], $avgV);
        $ws2->setCellValue([4, $r2], $tOk);
        $ws2->setCellValue([5, $r2], $tNok);
        $ws2->setCellValue([6, $r2], $pOk);
        $ws2->setCellValue([7, $r2], $pNok);
        $ws2->getStyle("A{$r2}:G{$r2}")->getFont()->setBold(true);
        $ws2->getStyle("A{$r2}:C{$r2}")->getNumberFormat()->setFormatCode('#,##0.00 "uds/h"');
        $ws2->getStyle("D{$r2}:E{$r2}")->getNumberFormat()->setFormatCode('#,##0');
        $ws2->getStyle("F{$r2}:G{$r2}")->getNumberFormat()->setFormatCode('0.00"%"');
        $ws2->getStyle("A{$r2}:G{$r2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $r2 += 2; // espacio entre máquinas
    }

    foreach (['A','B','C','D','E','F','G','H','I'] as $col) {
        $ws2->getColumnDimension($col)->setAutoSize(true);
    }

    // ── Output
    $safeCod = preg_replace('/[^A-Za-z0-9_\-]/', '_', $prod['cod_producto']);
    $stamp = date('Ymd_His');
    $base = "Historico_referencia_{$safeCod}_{$fdesde}_a_{$fhasta}_{$stamp}";
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
    header('Cache-Control: no-store');
    $writer = IOFactory::createWriter($book, 'Xlsx');
    $writer->save('php://output');
    exit;

} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Error al exportar: ' . $e->getMessage()]);
    }
    exit;
}
