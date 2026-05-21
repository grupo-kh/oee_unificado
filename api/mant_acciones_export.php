<?php
/**
 * Export XLSX de las acciones preventivas de UNA máquina.
 *
 * Parámetros (GET):
 *   - cod : código de máquina (cod_maquina_mant)
 *
 * Devuelve un xlsx con:
 *   - Cabecera (filas 1-2): nombre de la máquina + filtros
 *   - Tabla de tareas tal como aparecen en el formulario
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../vendor/autoload.php';

Auth::requireLoginApi();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

function _styleHeaderRow($ws, string $range): void {
    $ws->getStyle($range)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
    $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A2D4A');
    $ws->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('0E1B2E');
}

try {
    ini_set('memory_limit', '256M');

    $cod = (string) getParam('cod', '');
    if ($cod === '') jsonError('Falta parámetro cod');

    $rows = MaintenancePlanStore::listTareasByMaquina($cod);
    $descMaquina = $rows ? (string)$rows[0]['desc_maquina'] : '';
    $grupo       = $rows ? (string)$rows[0]['desc_grupo']   : '';

    $book = new Spreadsheet();
    $book->getProperties()
        ->setCreator('KH Plan Attainment')
        ->setTitle('Acciones preventivas · ' . ($descMaquina ?: $cod))
        ->setDescription("Acciones preventivas de la máquina $cod");

    $ws = $book->getActiveSheet();
    // Nombre de hoja sanitizado (sin \ / ? * [ ] : y max 31 chars)
    $sheetTitle = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', '_', ($descMaquina ?: $cod));
    if (mb_strlen($sheetTitle) > 31) $sheetTitle = mb_substr($sheetTitle, 0, 31);
    $ws->setTitle($sheetTitle);

    // ───── Cabecera (filas 1-2) ─────
    $ws->setCellValue('A1', 'Acciones preventivas · ' . ($descMaquina ?: $cod));
    $ws->mergeCells('A1:H1');
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1A2D4A');
    $ws->getStyle('A1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(1)->setRowHeight(24);

    $sub = [];
    $sub[] = 'Código: ' . $cod;
    if ($descMaquina) $sub[] = 'Máquina: ' . $descMaquina;
    if ($grupo)       $sub[] = 'Grupo: ' . $grupo;
    $sub[] = 'Tareas: ' . count($rows);
    $sub[] = 'Exportado: ' . date('d/m/Y H:i');
    $ws->setCellValue('A2', implode('  ·  ', $sub));
    $ws->mergeCells('A2:H2');
    $ws->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('2D4D7A');
    $ws->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF3F8');
    $ws->getStyle('A2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(2)->setRowHeight(20);

    // ───── Tabla (filas 4+) ─────
    // Solo catálogo de acciones — sin histórico (Última/Próxima/Intervenciones).
    $headers = [
        'Tarea', 'Periodicidad', 'Descripción',
        'Alta/Baja', 'IP Interna', 'Tipo mantenimiento', 'Realización', 'Fecha pausado',
    ];
    $headerRow = 4;
    foreach ($headers as $i => $h) $ws->setCellValue([$i+1, $headerRow], $h);
    _styleHeaderRow($ws, "A$headerRow:H$headerRow");

    $row = $headerRow + 1;
    $fmt = function ($iso) {
        if (!$iso) return '';
        $dt = DateTime::createFromFormat('Y-m-d', substr((string)$iso, 0, 10));
        return $dt ? $dt->format('d/m/Y') : (string)$iso;
    };

    foreach ($rows as $t) {
        $ws->setCellValue("A$row", (string)($t['tarea']            ?? ''));
        $ws->setCellValue("B$row", strtoupper((string)($t['periodicidad'] ?? '')));
        $ws->setCellValue("C$row", (string)($t['desc_tarea']       ?? ''));
        $ws->setCellValue("D$row", strtoupper((string)($t['alta_baja'] ?? 'ALTA')));
        $ws->setCellValue("E$row", (string)($t['ip_interna']        ?? ''));
        $ws->setCellValue("F$row", (string)($t['tipo_mantenimiento']?? ''));
        $ws->setCellValue("G$row", (string)($t['tipo_realizacion']  ?? ''));
        $ws->setCellValue("H$row", $fmt($t['fecha_pausado']    ?? null));

        // Sombreado para BAJA o pausadas
        if (strtoupper((string)($t['alta_baja'] ?? 'ALTA')) === 'BAJA' || !empty($t['fecha_pausado'])) {
            $ws->getStyle("A$row:H$row")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF8E1');
            $ws->getStyle("A$row:H$row")->getFont()->getColor()->setRGB('6C757D');
        }
        // Borde fino
        $ws->getStyle("A$row:H$row")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CCCCCC');
        $ws->getStyle("H$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
    }

    if (empty($rows)) {
        $ws->setCellValue("A$row", 'Esta máquina no tiene tareas preventivas asignadas.');
        $ws->mergeCells("A$row:H$row");
        $ws->getStyle("A$row")->getFont()->setItalic(true)->getColor()->setRGB('888888');
        $ws->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    // Wrap text en descripción
    $ws->getStyle("C5:C" . max($row-1, 5))->getAlignment()->setWrapText(true);

    // Anchos de columna
    $widths = ['A'=>14, 'B'=>14, 'C'=>60, 'D'=>10, 'E'=>14, 'F'=>18, 'G'=>13, 'H'=>14];
    foreach ($widths as $col => $w) $ws->getColumnDimension($col)->setWidth($w);

    // Freeze panes bajo cabecera
    $ws->freezePane('A' . ($headerRow + 1));

    // Output
    $stamp = date('Ymd_His');
    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $descMaquina ?: $cod);
    if ($safeName === '') $safeName = 'maquina';
    $base = "Acciones_$safeName" . '_' . $stamp . '.xlsx';

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $base . '"');
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
