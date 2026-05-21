<?php
/**
 * Export del listado COMPLETO de máquinas (excluyendo las de SECUENCIA)
 * con todas sus tareas preventivas. Soporta XLSX y PDF.
 *
 * Las máquinas de SECUENCIA (E66, RACKS, PLATAFORMAS) tienen su propio
 * listado consolidado aparte (pendiente). Aquí solo aparecen el resto.
 *
 * Parámetros (GET):
 *   - fmt : 'xlsx' (default) | 'pdf'
 *
 * Formato XLSX:
 *   - Hoja "Índice" con resumen del informe + tabla de máquinas
 *   - Una hoja por máquina con su detalle de tareas
 *
 * Formato PDF:
 *   - Portada / resumen
 *   - Sección por cada máquina con su tabla de tareas
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
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Devuelve true si la descripción pertenece a SECUENCIA (E66, RACKS, PLATAFORMAS).
 * Misma lógica que el JS de la vista (ACC_GROUPS).
 */
function _esSecuencia(string $desc): bool
{
    $s = trim($desc);
    if ($s === '') return false;
    if (preg_match('/^E66\b|^E66[_\s\-]/i', $s))     return true; // E66
    if (preg_match('/^RACK[\s\-]/i', $s))            return true; // RACKS
    if (preg_match('/^PLATAFORMA/i', $s))            return true; // PLATAFORMAS
    return false;
}

function _fmtIso(?string $iso): string
{
    if (!$iso) return '';
    $dt = DateTime::createFromFormat('Y-m-d', substr((string)$iso, 0, 10));
    return $dt ? $dt->format('d/m/Y') : (string)$iso;
}

function _h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

try {
    ini_set('memory_limit', '512M');

    $fmt = strtolower((string)getParam('fmt', 'xlsx'));
    if (!in_array($fmt, ['xlsx', 'pdf'], true)) jsonError("fmt debe ser 'xlsx' o 'pdf'");

    // Listado de máquinas + filtro SECUENCIA
    $todas = MaintenancePlanStore::listMaquinasConContador();
    $maquinas = array_values(array_filter($todas, fn($m) => !_esSecuencia((string)($m['desc_maquina'] ?? ''))));

    // Para cada máquina cargamos sus tareas (las que aparecen en el modal)
    $maqDetalle = [];
    foreach ($maquinas as $m) {
        $cod = (string)$m['cod_maquina_mant'];
        $maqDetalle[$cod] = [
            'maquina' => $m,
            'tareas'  => MaintenancePlanStore::listTareasByMaquina($cod),
        ];
    }

    $stamp = date('Ymd_His');

    if ($fmt === 'xlsx') {
        _exportXlsx($maqDetalle, $stamp);
    } else {
        _exportPdf($maqDetalle, $stamp);
    }
    exit;

} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Error al exportar: ' . $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────
//  XLSX — multi-hoja
// ─────────────────────────────────────────────────────────────────
function _exportXlsx(array $maqDetalle, string $stamp): void
{
    $book = new Spreadsheet();
    $book->getProperties()
        ->setCreator('KH Plan Attainment')
        ->setTitle('Listado de máquinas y tareas preventivas')
        ->setDescription('Listado de máquinas no-SECUENCIA con su detalle de tareas preventivas');

    // ─── Hoja 1: Índice ───
    $wsIdx = $book->getActiveSheet();
    $wsIdx->setTitle('Índice');
    $wsIdx->getColumnDimension('A')->setWidth(26);
    $wsIdx->getColumnDimension('B')->setWidth(60);
    $wsIdx->getColumnDimension('C')->setWidth(14);

    // Título grande
    $wsIdx->setCellValue('A1', 'LISTADO DE MÁQUINAS Y TAREAS PREVENTIVAS');
    $wsIdx->mergeCells('A1:C1');
    $wsIdx->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('FFFFFF');
    $wsIdx->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A2D4A');
    $wsIdx->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $wsIdx->getRowDimension(1)->setRowHeight(28);

    $wsIdx->setCellValue('A2',
        'Excluye las máquinas de SECUENCIA (E66, RACKS, PLATAFORMAS) · ' .
        count($maqDetalle) . ' máquinas · Exportado ' . date('d/m/Y H:i'));
    $wsIdx->mergeCells('A2:C2');
    $wsIdx->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('5A6B80');
    $wsIdx->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $wsIdx->getRowDimension(2)->setRowHeight(20);

    // Cabecera tabla
    $row = 4;
    $wsIdx->setCellValue("A$row", 'Código');
    $wsIdx->setCellValue("B$row", 'Máquina');
    $wsIdx->setCellValue("C$row", 'Nº tareas');
    _styleHeaderRowIdx($wsIdx, "A$row:C$row");
    $row++;

    // Mapa de cod_maquina → nombre de hoja para hipervínculos
    $usados = [];
    foreach ($maqDetalle as $cod => $info) {
        $title = _sanitizeSheetTitle((string)($info['maquina']['desc_maquina'] ?? $cod), $usados);
        $info['sheet_title'] = $title;
        $maqDetalle[$cod] = $info;
    }

    foreach ($maqDetalle as $cod => $info) {
        $m = $info['maquina'];
        $wsIdx->setCellValue("A$row", (string)$cod);
        $wsIdx->setCellValue("B$row", (string)($m['desc_maquina'] ?? ''));
        $wsIdx->setCellValue("C$row", (int)($m['task_count'] ?? 0));
        // Hipervínculo a la hoja de detalle
        $sheetRef = "'" . str_replace("'", "''", $info['sheet_title']) . "'!A1";
        $wsIdx->getCell("B$row")->getHyperlink()->setUrl("sheet://$sheetRef");
        $wsIdx->getStyle("B$row")->getFont()->setUnderline(true)->getColor()->setRGB('1D4ED8');

        $wsIdx->getStyle("A$row:C$row")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CCCCCC');
        $wsIdx->getStyle("C$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
    }

    $wsIdx->freezePane('A5');

    // ─── Hoja por máquina ───
    foreach ($maqDetalle as $cod => $info) {
        $m = $info['maquina'];
        $tareas = $info['tareas'];
        $ws = $book->createSheet();
        $ws->setTitle($info['sheet_title']);

        $ws->setCellValue('A1', 'Acciones preventivas · ' . ($m['desc_maquina'] ?? $cod));
        $ws->mergeCells('A1:I1');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1A2D4A');
        $ws->getRowDimension(1)->setRowHeight(24);

        $ws->setCellValue('A2', 'Código: ' . $cod . '  ·  Tareas: ' . count($tareas) .
            '  ·  Exportado: ' . date('d/m/Y H:i'));
        $ws->mergeCells('A2:I2');
        $ws->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('2D4D7A');
        $ws->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF3F8');

        // Enlace de vuelta al índice
        $ws->setCellValue('A3', '↩ Volver al Índice');
        $ws->getCell('A3')->getHyperlink()->setUrl("sheet://'Índice'!A1");
        $ws->getStyle('A3')->getFont()->setItalic(true)->setUnderline(true)->getColor()->setRGB('1D4ED8');

        // Solo catálogo de acciones — sin histórico (Última/Próxima/Intervenciones).
        $headers = [
            'Tarea', 'Periodicidad', 'Descripción',
            'Alta/Baja', 'IP Interna', 'Tipo mantenimiento', 'Realización',
            'Pausada desde', 'Bloqueo (ini → fin)',
        ];
        $headerRow = 5;
        foreach ($headers as $i => $h) $ws->setCellValue([$i+1, $headerRow], $h);
        _styleHeaderRowDet($ws, "A$headerRow:I$headerRow");

        $r = $headerRow + 1;
        foreach ($tareas as $t) {
            $bloq = '';
            if (!empty($t['fecha_bloqueo_ini']) && !empty($t['fecha_bloqueo_fin'])) {
                $bloq = _fmtIso($t['fecha_bloqueo_ini']) . ' → ' . _fmtIso($t['fecha_bloqueo_fin']);
            }
            $ws->setCellValue("A$r", (string)($t['tarea']      ?? ''));
            $ws->setCellValue("B$r", strtoupper((string)($t['periodicidad'] ?? '')));
            $ws->setCellValue("C$r", (string)($t['desc_tarea'] ?? ''));
            $ws->setCellValue("D$r", strtoupper((string)($t['alta_baja'] ?? 'ALTA')));
            $ws->setCellValue("E$r", (string)($t['ip_interna']        ?? ''));
            $ws->setCellValue("F$r", (string)($t['tipo_mantenimiento']?? ''));
            $ws->setCellValue("G$r", (string)($t['tipo_realizacion']  ?? ''));
            $ws->setCellValue("H$r", _fmtIso($t['fecha_pausado'] ?? null));
            $ws->setCellValue("I$r", $bloq);

            // Sombreado para BAJA / pausada / bloqueada vigente
            $hoy = date('Y-m-d');
            $bloqueada = !empty($t['fecha_bloqueo_ini']) && !empty($t['fecha_bloqueo_fin'])
                && $hoy >= $t['fecha_bloqueo_ini'] && $hoy <= $t['fecha_bloqueo_fin'];
            $inactiva  = strtoupper((string)($t['alta_baja'] ?? 'ALTA')) === 'BAJA'
                      || !empty($t['fecha_pausado']) || $bloqueada;
            if ($inactiva) {
                $ws->getStyle("A$r:I$r")->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($bloqueada ? 'FDECEC' : 'FFF8E1');
                $ws->getStyle("A$r:I$r")->getFont()->getColor()->setRGB('6C757D');
            }
            $ws->getStyle("A$r:I$r")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CCCCCC');
            $ws->getStyle("D$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("H$r:I$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $r++;
        }
        if (!$tareas) {
            $ws->setCellValue("A$r", 'Esta máquina no tiene tareas preventivas asignadas.');
            $ws->mergeCells("A$r:I$r");
            $ws->getStyle("A$r")->getFont()->setItalic(true)->getColor()->setRGB('888888');
            $ws->getStyle("A$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $ws->getStyle("C" . ($headerRow + 1) . ":C" . max($r - 1, $headerRow + 1))
            ->getAlignment()->setWrapText(true);

        // Anchos
        $widths = ['A'=>14,'B'=>14,'C'=>52,'D'=>10,'E'=>14,'F'=>18,'G'=>13,'H'=>14,'I'=>22];
        foreach ($widths as $col => $w) $ws->getColumnDimension($col)->setWidth($w);
        $ws->freezePane('A' . ($headerRow + 1));
    }

    $book->setActiveSheetIndex(0);

    // Output
    $base = "Listado_Maquinas_Mantenimiento_$stamp.xlsx";
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $base . '"');
    header('Cache-Control: no-store');
    $writer = IOFactory::createWriter($book, 'Xlsx');
    $writer->save('php://output');
}

function _styleHeaderRowIdx($ws, string $range): void
{
    $ws->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2D4D7A');
    $ws->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyle($range)->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('1A2D4A');
}
function _styleHeaderRowDet($ws, string $range): void
{
    $ws->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A2D4A');
    $ws->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyle($range)->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('0E1B2E');
}

/**
 * Nombre de hoja válido en Excel (sin \ / ? * [ ] : y max 31 chars). Si el
 * nombre ya está usado le añade un sufijo " (2)", " (3)"…
 */
function _sanitizeSheetTitle(string $raw, array &$usados): string
{
    $t = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', '_', $raw);
    if (mb_strlen($t) > 31) $t = mb_substr($t, 0, 31);
    $t = trim($t) ?: 'Hoja';
    $base = $t; $i = 2;
    while (isset($usados[mb_strtolower($t)])) {
        $suf = " ($i)";
        $maxBase = 31 - mb_strlen($suf);
        $t = mb_substr($base, 0, $maxBase) . $suf;
        $i++;
    }
    $usados[mb_strtolower($t)] = true;
    return $t;
}

// ─────────────────────────────────────────────────────────────────
//  PDF — secciones por máquina (mPDF)
// ─────────────────────────────────────────────────────────────────
function _exportPdf(array $maqDetalle, string $stamp): void
{
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><style>
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9pt; color: #1a2d4a; }
    h1 { color: #1a2d4a; font-size: 18pt; margin: 0 0 6pt; }
    h2 { color: #1a2d4a; font-size: 11pt; margin: 14pt 0 5pt; border-bottom: 1px solid #c8d2dd; padding-bottom: 3pt; page-break-after: avoid; }
    .portada { border: 2pt solid #1a2d4a; border-radius: 4pt; padding: 14pt 18pt; margin: 6pt 0 16pt; text-align: center; }
    .portada h1 { font-size: 22pt; }
    .portada .stamp { color: #5a6b80; font-style: italic; font-size: 10pt; margin-top: 4pt; }
    .portada .total { font-size: 14pt; color: #2d4d7a; font-weight: bold; margin-top: 12pt; }
    .filter-bar { background: #eef3f8; border: 1px solid #c8d2dd; padding: 6pt 8pt; font-size: 9pt; margin: 6pt 0 14pt; border-radius: 3pt; color: #2d4d7a; }
    table.data { width: 100%; border-collapse: collapse; margin: 4pt 0 0; }
    table.data th { background: #1a2d4a; color: #fff; padding: 4pt 6pt; font-weight: bold; font-size: 8.5pt; text-align: center; border: 1px solid #0e1b2e; }
    table.data td { padding: 3pt 5pt; border: 1px solid #d8d8d8; font-size: 8.5pt; vertical-align: top; }
    table.data td.c { text-align: center; }
    table.data td.r { text-align: right; }
    table.data tr.baja td      { background: #fff8e1; color: #6c757d; }
    table.data tr.pausada td   { background: #fff8e1; color: #6c757d; }
    table.data tr.bloqueada td { background: #fdecec; color: #6c757d; }
    .indice table.data td.maq { font-weight: bold; }
    .maq-block { page-break-inside: avoid; }
    .maq-meta { font-size: 8.5pt; color: #5a6b80; margin: 0 0 4pt; }
    .empty { font-style: italic; color: #888; margin: 6pt 0; }
</style></head>
<body>

<div class="portada">
    <h1>LISTADO DE MÁQUINAS Y TAREAS PREVENTIVAS</h1>
    <div class="stamp">Exportado el <?= date('d/m/Y H:i') ?></div>
    <div class="total"><?= count($maqDetalle) ?> máquinas · excluye SECUENCIA (E66, RACKS, PLATAFORMAS)</div>
</div>

<!-- ─── Índice ─── -->
<div class="indice">
<h2>Índice de máquinas</h2>
<table class="data">
    <thead>
        <tr><th style="width:18%">Código</th><th>Máquina</th><th style="width:18%">Nº tareas</th></tr>
    </thead>
    <tbody>
    <?php foreach ($maqDetalle as $cod => $info):
        $m = $info['maquina']; $n = (int)($m['task_count'] ?? 0); ?>
        <tr><td><?= _h($cod) ?></td><td class="maq"><?= _h($m['desc_maquina'] ?? '') ?></td><td class="c"><?= $n ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- ─── Detalle por máquina ─── -->
<?php foreach ($maqDetalle as $cod => $info):
    $m = $info['maquina']; $tareas = $info['tareas']; $hoy = date('Y-m-d'); ?>
<div class="maq-block">
    <h2><?= _h($m['desc_maquina'] ?? $cod) ?>  <span style="font-weight:normal;color:#5a6b80;font-size:9pt;">· <?= _h($cod) ?></span></h2>
    <div class="maq-meta">Tareas: <?= count($tareas) ?></div>
    <?php if (empty($tareas)): ?>
        <div class="empty">Esta máquina no tiene tareas preventivas asignadas.</div>
    <?php else: ?>
    <table class="data">
        <thead>
            <tr>
                <th style="width:10%">Tarea</th>
                <th style="width:11%">Period.</th>
                <th>Descripción</th>
                <th style="width:7%">A/B</th>
                <th style="width:10%">IP Interna</th>
                <th style="width:12%">Tipo mant.</th>
                <th style="width:9%">Realiz.</th>
                <th style="width:10%">Pausada</th>
                <th style="width:14%">Bloqueo</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tareas as $t):
            $alta = strtoupper((string)($t['alta_baja'] ?? 'ALTA'));
            $bloq = (!empty($t['fecha_bloqueo_ini']) && !empty($t['fecha_bloqueo_fin']))
                ? (_fmtIso($t['fecha_bloqueo_ini']) . ' → ' . _fmtIso($t['fecha_bloqueo_fin']))
                : '';
            $bloqueadaVigente = !empty($t['fecha_bloqueo_ini']) && !empty($t['fecha_bloqueo_fin'])
                && $hoy >= $t['fecha_bloqueo_ini'] && $hoy <= $t['fecha_bloqueo_fin'];
            $rowCls = $bloqueadaVigente ? 'bloqueada' : ($alta === 'BAJA' || !empty($t['fecha_pausado']) ? 'pausada' : '');
        ?>
            <tr class="<?= $rowCls ?>">
                <td><?= _h($t['tarea'] ?? '') ?></td>
                <td class="c"><?= _h(strtoupper((string)($t['periodicidad'] ?? ''))) ?></td>
                <td><?= _h($t['desc_tarea'] ?? '') ?></td>
                <td class="c"><?= _h($alta) ?></td>
                <td class="c"><?= _h($t['ip_interna'] ?? '') ?></td>
                <td class="c"><?= _h($t['tipo_mantenimiento'] ?? '') ?></td>
                <td class="c"><?= _h($t['tipo_realizacion'] ?? '') ?></td>
                <td class="c"><?= _h(_fmtIso($t['fecha_pausado'] ?? null)) ?></td>
                <td class="c"><?= _h($bloq) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endforeach; ?>

</body>
</html>
    <?php
    $html = ob_get_clean();

    $mpdf = new \Mpdf\Mpdf([
        'mode'        => 'utf-8',
        'format'      => 'A4',
        'orientation' => 'L', // landscape para que entren todas las columnas
        'margin_top'    => 12,
        'margin_bottom' => 12,
        'margin_left'   => 10,
        'margin_right'  => 10,
    ]);
    $mpdf->SetTitle('Listado de máquinas y tareas preventivas');
    $mpdf->SetCreator('KH Plan Attainment');
    $mpdf->WriteHTML($html);
    while (ob_get_level() > 0) ob_end_clean();
    $base = "Listado_Maquinas_Mantenimiento_$stamp.pdf";
    $mpdf->Output($base, 'D');
}
