<?php
/**
 * Informe completo XLSX para una sección (VARILLAS | TROQUELADOS).
 *
 * 3 hojas: Disponibilidad, Rendimiento, Calidad.
 * En cada hoja, una tabla pivot por motivo:
 *   filas = máquinas activas no excluidas, columnas = hora 00-23, celda = h | uds.
 *
 * Parámetros: fecha_desde, fecha_hasta, turnos (CSV), excl (CSV), seccion.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/oee_unificado_export_completo.php_data.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

function _comStyleHeader($ws, string $range): void {
    $ws->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFFFF');
    $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('8C181A');
    $ws->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('6D1214');
}

function _comWriteContext($ws, array $ctx, string $sheetTitle, string $rightCol): int {
    $ws->setCellValue('A1', "OEE Unificado · {$ctx['seccion']} · $sheetTitle");
    $ws->mergeCells("A1:{$rightCol}1");
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('8C181A');
    $ws->getStyle('A1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(1)->setRowHeight(22);

    $parts = [
        'Rango: ' . $ctx['fdesde'] . ' → ' . $ctx['fhasta'],
        'Turnos: ' . $ctx['turnosLabel'],
        'Sección: ' . $ctx['seccion'],
    ];
    if (!empty($ctx['exclLabel'])) $parts[] = 'Máquinas excluidas: ' . $ctx['exclLabel'];
    // Refleja la "vista activa" del usuario en el momento de la exportación
    if (!empty($ctx['metricaLabel'])) $parts[] = 'Métrica activa: ' . $ctx['metricaLabel'];
    if (!empty($ctx['motivo']))       $parts[] = 'Motivo seleccionado: ' . $ctx['motivo'];
    if (!empty($ctx['porLabel']))     $parts[] = 'Segmentación: ' . $ctx['porLabel'];
    $parts[] = 'Exportado: ' . date('d/m/Y H:i');

    $ws->setCellValue('A2', implode('  ·  ', $parts));
    $ws->mergeCells("A2:{$rightCol}2");
    $ws->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('2D4D7A');
    $ws->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF5F5');
    $ws->getStyle('A2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $ws->getRowDimension(2)->setRowHeight(20);
    return 4;
}

/**
 * Pinta la hoja con sub-tablas pivot por motivo.
 *
 * Layout:
 *   - Rango de 1 solo día → pivot compacto: filas = máquinas, cols = 00-23 + Total (A..Z).
 *   - Rango multi-día      → cols = Día | Máquina | 00-23 | Total (A..AA), con
 *                            subtotal por día y total motivo al final.
 *
 * @param array<int, array{cod_maquina:string, maquina:string}>             $maqs
 * @param array<string, array<string, array<string, array<int, float|int>>>> $data motivo→día→cod_maquina→hora→valor
 * @param string $unidad   'h' (horas decimales) | 'uds' (entero)
 * @param bool   $multiDay si true, layout con columna Día y subtotales
 * @param bool   $hourly   si false (fallback rendimiento DAY), las celdas hora 00-23 quedan vacías; todo va al Total
 */
function _comRenderHoja(
    $ws, string $sheetTitle, array $data, array $maqs,
    string $unidad, array $ctx, bool $multiDay, bool $hourly = true,
    string $rowLabel = 'Máquina'
): void {
    // Layout de columnas
    if ($multiDay) {
        $rightCol      = 'AA';
        $colDia        = 1;   // A
        $colMaq        = 2;   // B
        $hourStartCol  = 3;   // C..Z (24 horas)
        $totalCol      = 27;  // AA
        $nameSpanRange = "A%d:B%d";
    } else {
        $rightCol      = 'Z';
        $colDia        = 0;   // no se usa
        $colMaq        = 1;   // A
        $hourStartCol  = 2;   // B..Y
        $totalCol      = 26;  // Z
        $nameSpanRange = "A%d:A%d";
    }

    $row = _comWriteContext($ws, $ctx, $sheetTitle, $rightCol);

    if (empty($data) || empty($maqs)) {
        $ws->setCellValue("A$row", 'Sin datos disponibles para la selección.');
        $ws->getStyle("A$row")->getFont()->setItalic(true)->getColor()->setRGB('6B7D92');
        return;
    }

    if (!$hourly) {
        $ws->setCellValue("A$row", 'Aviso: F_his_ct(\'HOUR\') no está disponible en este servidor; se muestran totales diarios (las columnas 00-23 quedan vacías).');
        $ws->mergeCells("A$row:{$rightCol}$row");
        $ws->getStyle("A$row")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('8C181A');
        $ws->getStyle("A$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF5F5');
        $row += 2;
    }

    // Orden motivos por total descendente
    $motTotals = [];
    foreach ($data as $mot => $diaMap) {
        $t = 0;
        foreach ($diaMap as $maqMap) {
            foreach ($maqMap as $horas) $t += array_sum($horas);
        }
        $motTotals[$mot] = $t;
    }
    arsort($motTotals);

    foreach ($motTotals as $mot => $totMot) {
        // Fila título del motivo
        $titleVal = $unidad === 'h'
            ? sprintf('Motivo: %s · Total: %s h', $mot, number_format($totMot, 2, ',', '.'))
            : sprintf('Motivo: %s · Total: %s uds', $mot, number_format((int)round($totMot), 0, ',', '.'));
        $ws->setCellValue("A$row", $titleVal);
        $ws->mergeCells("A$row:{$rightCol}$row");
        $ws->getStyle("A$row")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('8C181A');
        $ws->getStyle("A$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDF5F5');
        $ws->getStyle("A$row")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $row++;

        // Headers
        if ($multiDay) {
            $ws->setCellValue([$colDia, $row], 'Día');
            $ws->setCellValue([$colMaq, $row], $rowLabel);
        } else {
            $ws->setCellValue([$colMaq, $row], $rowLabel);
        }
        for ($h = 0; $h < 24; $h++) {
            $ws->setCellValue([$h + $hourStartCol, $row], str_pad((string)$h, 2, '0', STR_PAD_LEFT));
        }
        $ws->setCellValue([$totalCol, $row], 'Total');
        _comStyleHeader($ws, "A$row:{$rightCol}$row");
        $row++;

        // Filas de datos: ordenadas por día (cronológico)
        $diaMap = $data[$mot];
        $días   = array_keys($diaMap);
        sort($días);

        $hourTotMot = array_fill(0, 24, 0);
        $totMotAcc  = 0;
        $anyRow     = false;

        foreach ($días as $dia) {
            $maqDayMap   = $diaMap[$dia];
            $hourTotDia  = array_fill(0, 24, 0);
            $totDia      = 0;
            $rowsThisDia = 0;

            foreach ($maqs as $mq) {
                $cod = $mq['cod_maquina'];
                if (!isset($maqDayMap[$cod])) continue;
                $horas  = $maqDayMap[$cod];
                $rowTot = array_sum($horas);

                if ($multiDay) {
                    $ws->setCellValue([$colDia, $row], $dia);
                    $ws->setCellValue([$colMaq, $row], $mq['maquina']);
                } else {
                    $ws->setCellValue([$colMaq, $row], $mq['maquina']);
                }
                for ($h = 0; $h < 24; $h++) {
                    $v = $horas[$h] ?? 0;
                    if ($v > 0) {
                        $ws->setCellValue([$h + $hourStartCol, $row],
                            $unidad === 'h' ? round($v, 2) : (int)round($v));
                    }
                    $hourTotDia[$h] += $v;
                    $hourTotMot[$h] += $v;
                }
                $ws->setCellValue([$totalCol, $row],
                    $unidad === 'h' ? round($rowTot, 2) : (int)round($rowTot));
                $totDia    += $rowTot;
                $totMotAcc += $rowTot;
                $rowsThisDia++;
                $anyRow = true;
                $row++;
            }

            // Subtotal por día (solo en multiDay)
            if ($multiDay && $rowsThisDia > 0) {
                $ws->setCellValue([$colDia, $row], "TOTAL $dia");
                $ws->mergeCells(sprintf($nameSpanRange, $row, $row));
                $ws->getStyle("A$row:{$rightCol}$row")->getFont()->setBold(true);
                $ws->getStyle("A$row:{$rightCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF3F8');
                $ws->getStyle("A$row:{$rightCol}$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C8D2DD');
                for ($h = 0; $h < 24; $h++) {
                    if ($hourTotDia[$h] > 0) {
                        $ws->setCellValue([$h + $hourStartCol, $row],
                            $unidad === 'h' ? round($hourTotDia[$h], 2) : (int)round($hourTotDia[$h]));
                    }
                }
                $ws->setCellValue([$totalCol, $row],
                    $unidad === 'h' ? round($totDia, 2) : (int)round($totDia));
                $row++;
            }
        }

        if (!$anyRow) {
            $ws->setCellValue("A$row", '(sin datos por máquina)');
            $ws->getStyle("A$row")->getFont()->setItalic(true)->getColor()->setRGB('6B7D92');
            $row++;
        } else {
            // Total del motivo
            $totalLabel = $multiDay ? 'TOTAL MOTIVO' : 'TOTAL';
            $ws->setCellValue([$colDia ?: $colMaq, $row], $totalLabel);
            if ($multiDay) $ws->mergeCells(sprintf($nameSpanRange, $row, $row));
            $ws->getStyle("A$row:{$rightCol}$row")->getFont()->setBold(true);
            $ws->getStyle("A$row:{$rightCol}$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDEBED');
            $ws->getStyle("A$row:{$rightCol}$row")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('8C181A');
            for ($h = 0; $h < 24; $h++) {
                if ($hourTotMot[$h] > 0) {
                    $ws->setCellValue([$h + $hourStartCol, $row],
                        $unidad === 'h' ? round($hourTotMot[$h], 2) : (int)round($hourTotMot[$h]));
                }
            }
            $ws->setCellValue([$totalCol, $row],
                $unidad === 'h' ? round($totMotAcc, 2) : (int)round($totMotAcc));
            $row++;
        }
        $row++; // separación entre motivos
    }

    // Anchos
    if ($multiDay) {
        $ws->getColumnDimension('A')->setWidth(12);  // Día
        $ws->getColumnDimension('B')->setWidth(28);  // Máquina
        for ($c = 3; $c <= 26; $c++) {
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(6);
        }
        $ws->getColumnDimension('AA')->setWidth(10);
    } else {
        $ws->getColumnDimension('A')->setWidth(28);
        for ($c = 2; $c <= 25; $c++) {
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(6);
        }
        $ws->getColumnDimension('Z')->setWidth(10);
    }
}

try {
    ini_set('memory_limit', '512M');

    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');
    $seccion = (string) getParam('seccion');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    if (!in_array($seccion, ['VARILLAS', 'TROQUELADOS'], true)) jsonError('seccion inválida (VARILLAS | TROQUELADOS)');

    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));
    $excl   = getListParam('excl');

    // Contexto de "vista activa" en pantalla — refleja la métrica/motivo/segmentación
    // que el usuario tenía abierta al pulsar el botón. Todos opcionales.
    $metricaParam = (string) ($_GET['metrica'] ?? '');
    $motivoParam  = (string) ($_GET['motivo']  ?? '');
    $porParam     = (string) ($_GET['por']     ?? '');
    $metricaLabels = [
        'disponibilidad' => 'Disponibilidad',
        'rendimiento'    => 'Rendimiento',
        'calidad'        => 'Calidad',
        'oee'            => 'OEE',
    ];
    $metricaLabel = $metricaLabels[$metricaParam] ?? '';
    $porLabel     = ($porParam === 'referencia') ? 'Por referencia'
                  : (($porParam === 'maquina')   ? 'Por máquina' : '');

    $turnosLabel = empty($turnos) ? 'Todos' : implode(', ', $turnos);
    $exclLabel   = _completoExclLabel($excl);

    $ctx = [
        'fdesde'       => $fdesde,
        'fhasta'       => $fhasta,
        'turnosLabel'  => $turnosLabel,
        'seccion'      => $seccion,
        'exclLabel'    => $exclLabel,
        'metricaParam' => $metricaParam,
        'metricaLabel' => $metricaLabel,
        'motivo'       => $motivoParam,
        'porParam'     => $porParam,
        'porLabel'     => $porLabel,
    ];

    $maqs    = _completoMaqsSeccion($fdesde, $fhasta, $turnos, $excl, $seccion);
    $codMaqs = array_column($maqs, 'cod_maquina');

    // Si el usuario tenía el toggle en "Referencia" (solo afecta a Disponibilidad),
    // la hoja de Disponibilidad se segmenta por referencia en vez de por máquina.
    $byRef = ($porParam === 'referencia');
    if ($byRef) {
        $disp     = _completoDisponibilidadPorReferencia($fdesde, $fhasta, $turnos, $codMaqs);
        $dispRows = _completoRefsDisponibilidad($fdesde, $fhasta, $turnos, $codMaqs);
        $dispLabel = 'Referencia';
        $dispTitle = 'Disponibilidad por referencia (horas de paro por motivo)';
    } else {
        $disp      = _completoDisponibilidad($fdesde, $fhasta, $turnos, $codMaqs);
        $dispRows  = $maqs;
        $dispLabel = 'Máquina';
        $dispTitle = 'Disponibilidad (horas de paro por motivo)';
    }
    $rend = _completoRendimiento   ($fdesde, $fhasta, $turnos, $codMaqs);
    $cal  = _completoCalidad       ($fdesde, $fhasta, $turnos, $codMaqs);

    $multiDay = $fdesde !== $fhasta;

    $book = new Spreadsheet();
    $book->getProperties()
        ->setCreator('KH Plan Attainment')
        ->setTitle("OEE Unificado - Informe completo $seccion")
        ->setDescription("Informe completo $seccion · $fdesde a $fhasta");

    $wsDisp = $book->getActiveSheet();
    $wsDisp->setTitle('Disponibilidad');
    _comRenderHoja($wsDisp, $dispTitle, $disp['data'], $dispRows, 'h', $ctx, $multiDay, $disp['hourly'], $dispLabel);

    $wsRend = $book->createSheet();
    $wsRend->setTitle('Rendimiento');
    _comRenderHoja($wsRend, 'Rendimiento (horas de pérdida por artículo)', $rend['data'], $maqs, 'h', $ctx, $multiDay, $rend['hourly'], 'Máquina');

    $wsCal = $book->createSheet();
    $wsCal->setTitle('Calidad');
    _comRenderHoja($wsCal, 'Calidad (unidades rechazadas por defecto)', $cal['data'], $maqs, 'uds', $ctx, $multiDay, $cal['hourly'], 'Máquina');

    $stamp = date('Ymd_His');
    $base  = "OEE_Unificado_Completo_{$seccion}_{$fdesde}_a_{$fhasta}_{$stamp}";
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
