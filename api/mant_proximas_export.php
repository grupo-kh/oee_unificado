<?php
/**
 * Export del calendario de PRÓXIMAS REVISIONES para entregar a los operarios.
 *
 * Soporta formato XLSX y PDF. Respeta los mismos filtros que la vista
 * (views/mant_proximas.php + api/mant_proximas.php):
 *
 *   - dias              (int, default 30): ventana hacia el futuro.
 *   - cod_maquina_mant  (opcional): filtrar por máquina.
 *   - periodicidad      (opcional): filtrar por periodicidad.
 *   - solo_vencidas     (0/1): si 1, solo las que ya pasaron.
 *   - fmt               ('xlsx' (default) | 'pdf')
 *
 * El archivo se entrega ordenado por fecha de próxima revisión y agrupado
 * visualmente por día — el operario sabe qué le toca cada jornada.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';
require_once __DIR__ . '/../vendor/autoload.php';

Auth::requireLoginApi();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\IOFactory;

function _h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function _fmtIso(?string $iso): string
{
    if (!$iso) return '';
    $dt = DateTime::createFromFormat('Y-m-d', substr((string)$iso, 0, 10));
    return $dt ? $dt->format('d/m/Y') : (string)$iso;
}

/**
 * Devuelve un literal castellano corto para el día de la semana (Lun, Mar…).
 */
function _diaSemanaCorto(string $iso): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    if (!$dt) return '';
    $dias = ['Sun'=>'Dom','Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mié','Thu'=>'Jue','Fri'=>'Vie','Sat'=>'Sáb'];
    return $dias[$dt->format('D')] ?? '';
}

function _estadoLabel(string $estado): string
{
    switch ($estado) {
        case 'vencida':  return 'VENCIDA';
        case 'urgente':  return 'PENDIENTE';
        case 'en_plazo': return 'EN PLAZO';
        default:         return strtoupper($estado);
    }
}

try {
    ini_set('memory_limit', '256M');

    $diasRaw = getParam('dias', '30');
    $dias    = max(1, min(365, (int)$diasRaw));
    $cm      = (string) getParam('cod_maquina_mant', '');
    $pe      = (string) getParam('periodicidad', '');
    $solo    = (int) getParam('solo_vencidas', '0') === 1;
    $fmt     = strtolower((string) getParam('fmt', 'xlsx'));
    if (!in_array($fmt, ['xlsx', 'pdf'], true)) jsonError("fmt debe ser 'xlsx' o 'pdf'");

    // ─────────── Reproducimos el filtrado de api/mant_proximas.php ───────────
    $data           = MaintenancePlanStore::load();
    $marcadasIdx    = MaintenanceCompletionStore::loadIndexed();
    $perOverrideIdx = MaintenancePeriodicidadStore::loadIndexed();

    // Mapa [orden|tarea → tiempo_estimado (min)] para mostrar en cada fila del
    // archivo. Útil para que el operario sepa cuánto debe durar cada acción.
    $tiempoEstIdx = [];
    try {
        $teRows = Db::pgFetchAll("SELECT orden, tarea, tiempo_estimado FROM mant_plan WHERE tiempo_estimado IS NOT NULL");
        foreach ($teRows as $te) {
            $tiempoEstIdx[((string)$te['orden']) . '|' . ((string)$te['tarea'])] = (int)$te['tiempo_estimado'];
        }
    } catch (Throwable $e) {
        $tiempoEstIdx = [];
    }

    $proximasFiltradas = array_values(array_filter(
        $data['proximas'],
        function ($p) use ($marcadasIdx) {
            $idMark = MaintenanceCompletionStore::buildId(
                (string)$p['orden'], (string)$p['tarea'], (string)($p['proxima_revision'] ?? '')
            );
            return !isset($marcadasIdx[$idMark]);
        }
    ));
    $proximas = MaintenancePlanStore::consolidateSecuenciaProximas($proximasFiltradas);

    $hoy = date('Y-m-d');
    $rows = [];
    foreach ($proximas as $p) {
        $idOverride = MaintenancePeriodicidadStore::buildId(
            (string)$p['orden'], (string)$p['tarea']
        );
        $eff = MaintenancePeriodicidadStore::applyOverride(
            $p, $perOverrideIdx[$idOverride] ?? null
        );

        $px = $eff['proxima_revision'] ?? null;
        if ($px === null) continue;

        $idMark = MaintenanceCompletionStore::buildId(
            (string)$p['orden'], (string)$p['tarea'], (string)$p['proxima_revision']
        );
        if (isset($marcadasIdx[$idMark])) continue;

        if ($cm !== '' && $eff['cod_maquina_mant'] !== $cm) continue;
        if ($pe !== '' && $eff['periodicidad']     !== $pe) continue;

        $diff = (int) round((strtotime($px) - strtotime($hoy)) / 86400);

        if ($solo) {
            if ($diff >= 0) continue;
        } else {
            if ($diff > $dias) continue;
        }

        // Resolver tiempo estimado. Para consolidadas (orden CONSOL:*) sumamos
        // el tiempo de todas las sub-tareas (lo lleva el campo sub_tareas
        // dejado por consolidateSecuenciaProximas).
        $teMin = null;
        $keyTE = ((string)$eff['orden']) . '|' . ((string)$eff['tarea']);
        if (isset($tiempoEstIdx[$keyTE])) {
            $teMin = $tiempoEstIdx[$keyTE];
        } elseif (!empty($eff['sub_tareas']) && is_array($eff['sub_tareas'])) {
            $sumTE = 0; $cntTE = 0;
            foreach ($eff['sub_tareas'] as $s) {
                $k = ((string)($s['orden'] ?? '')) . '|' . ((string)($s['tarea'] ?? ''));
                if (isset($tiempoEstIdx[$k])) { $sumTE += $tiempoEstIdx[$k]; $cntTE++; }
            }
            if ($cntTE > 0) $teMin = $sumTE;
        }

        $rows[] = $eff + [
            'dias_restantes'   => $diff,
            'estado'           => $diff < 0 ? 'vencida' : ($diff <= 7 ? 'urgente' : 'en_plazo'),
            'tiempo_estimado'  => $teMin,
        ];
    }
    usort($rows, fn($a, $b) => strcmp((string)$a['proxima_revision'], (string)$b['proxima_revision']));

    // Resumen completo (incluye en_plazo aunque no se exporten)
    $resumen = [
        'total'    => count($rows),
        'vencidas' => count(array_filter($rows, fn($r) => $r['estado'] === 'vencida')),
        'urgentes' => count(array_filter($rows, fn($r) => $r['estado'] === 'urgente')),
        'en_plazo' => count(array_filter($rows, fn($r) => $r['estado'] === 'en_plazo')),
    ];

    // El archivo descargable lista SOLO vencidas y pendientes (≤7 días).
    // Las "en plazo" tienen tiempo de sobra y no aportan al operario al
    // recibir el calendario.
    $rows = array_values(array_filter($rows, fn($r) => $r['estado'] !== 'en_plazo'));
    $resumen['exportadas'] = count($rows);

    // Texto de filtros aplicados (para mostrar en la cabecera del archivo)
    $filtros = [];
    $filtros[] = $solo ? 'Solo VENCIDAS' : ('Próximos ' . $dias . ' días');
    if ($cm !== '') {
        $descMaq = '';
        foreach ($rows as $r) {
            if ($r['cod_maquina_mant'] === $cm) { $descMaq = $r['desc_maquina']; break; }
        }
        $filtros[] = 'Máquina: ' . ($descMaq !== '' ? $descMaq : $cm);
    }
    if ($pe !== '') $filtros[] = 'Periodicidad: ' . strtoupper($pe);
    $filtrosTxt = implode('  ·  ', $filtros);

    $stamp = date('Ymd_His');
    $baseStem = 'Calendario_Mantenimiento_' . ($solo ? 'vencidas' : ('prox' . $dias . 'd'));
    if ($cm !== '') $baseStem .= '_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $cm);
    $baseStem .= '_' . $stamp;

    if ($fmt === 'xlsx') {
        _exportProximasXlsx($rows, $resumen, $filtrosTxt, $baseStem);
    } else {
        _exportProximasPdf($rows, $resumen, $filtrosTxt, $baseStem);
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

// ─────────────────────────────────────────────────────────────────────────────
// XLSX — calendario imprimible y editable
// ─────────────────────────────────────────────────────────────────────────────
function _exportProximasXlsx(array $rows, array $resumen, string $filtrosTxt, string $baseStem): void
{
    $book = new Spreadsheet();
    $book->getProperties()
        ->setCreator('KH Plan Attainment')
        ->setTitle('Calendario de mantenimiento preventivo')
        ->setDescription('Calendario de próximas revisiones para operarios');

    $ws = $book->getActiveSheet();
    $ws->setTitle('Calendario');

    // ── Cabecera ──
    $ws->setCellValue('A1', 'ACCIONES PREVENTIVAS VENCIDAS Y PENDIENTES');
    $ws->mergeCells('A1:I1');
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('FFFFFF');
    $ws->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A2D4A');
    $ws->getStyle('A1')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(1)->setRowHeight(28);

    $sub = [];
    $sub[] = 'Filtros: ' . ($filtrosTxt ?: '—');
    $sub[] = 'Tareas listadas: ' . $resumen['exportadas']
            . ' (' . $resumen['vencidas'] . ' vencidas + ' . $resumen['urgentes'] . ' pendientes)';
    $sub[] = 'En plazo (no listadas): ' . $resumen['en_plazo'];
    $sub[] = 'Exportado: ' . date('d/m/Y H:i');
    $ws->setCellValue('A2', implode('  ·  ', $sub));
    $ws->mergeCells('A2:I2');
    $ws->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('2D4D7A');
    $ws->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF3F8');
    $ws->getStyle('A2')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(2)->setRowHeight(22);

    // ── Cabecera de tabla ──
    $headerRow = 4;
    $headers = [
        'Fecha', 'Día', 'Máquina', 'Tarea', 'Periodicidad', 'Tiempo (min)',
        'Descripción', 'Estado', 'Operario / Observaciones',
    ];
    foreach ($headers as $i => $h) $ws->setCellValue([$i + 1, $headerRow], $h);
    $ws->getStyle("A$headerRow:I$headerRow")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $ws->getStyle("A$headerRow:I$headerRow")->getFill()->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('1A2D4A');
    $ws->getStyle("A$headerRow:I$headerRow")->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getStyle("A$headerRow:I$headerRow")->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('0E1B2E');

    // ── Filas ──
    $r = $headerRow + 1;
    $fechaActual = null;
    foreach ($rows as $row) {
        $fecha = (string) ($row['proxima_revision'] ?? '');
        $cambioDia = ($fecha !== $fechaActual);
        $fechaActual = $fecha;

        $estado    = (string) ($row['estado'] ?? '');
        $estadoLbl = _estadoLabel($estado);
        $teMin     = $row['tiempo_estimado'] ?? null;

        $ws->setCellValue("A$r", _fmtIso($fecha));
        $ws->setCellValue("B$r", _diaSemanaCorto($fecha));
        $ws->setCellValue("C$r", (string) ($row['desc_maquina'] ?? $row['cod_maquina_mant'] ?? ''));
        $ws->setCellValue("D$r", (string) ($row['tarea'] ?? ''));
        $ws->setCellValue("E$r", strtoupper((string) ($row['periodicidad'] ?? '')));
        $ws->setCellValue("F$r", $teMin !== null ? (int)$teMin : '');
        $ws->setCellValue("G$r", (string) ($row['desc_tarea'] ?? ''));
        $ws->setCellValue("H$r", $estadoLbl);
        $ws->setCellValue("I$r", '');  // a rellenar por el operario en papel/Excel

        // Color del estado
        $colorBg = '';
        switch ($estado) {
            case 'vencida':  $colorBg = 'FDECEC'; break; // rojo claro
            case 'urgente':  $colorBg = 'FFF4DB'; break; // ámbar claro
            case 'en_plazo': $colorBg = 'E8F3EA'; break; // verde claro
        }
        if ($colorBg !== '') {
            $ws->getStyle("H$r")->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($colorBg);
            $ws->getStyle("H$r")->getFont()->setBold(true);
        }

        // Separador visual cada vez que cambia el día
        if ($cambioDia && $r > $headerRow + 1) {
            $ws->getStyle("A$r:I$r")->getBorders()->getTop()
                ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('1A2D4A');
        }

        $ws->getStyle("A$r:I$r")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CCCCCC');
        $ws->getStyle("A$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("B$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("E$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("F$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("H$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $r++;
    }

    if (empty($rows)) {
        $ws->setCellValue("A$r", 'No hay revisiones que cumplan los filtros aplicados.');
        $ws->mergeCells("A$r:I$r");
        $ws->getStyle("A$r")->getFont()->setItalic(true)->getColor()->setRGB('888888');
        $ws->getStyle("A$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    // Wrap text en descripción (G) y observaciones (I)
    $maxRow = max($r - 1, $headerRow + 1);
    $ws->getStyle("G" . ($headerRow + 1) . ":G$maxRow")->getAlignment()->setWrapText(true);
    $ws->getStyle("I" . ($headerRow + 1) . ":I$maxRow")->getAlignment()->setWrapText(true);

    // Anchos
    $widths = ['A'=>13,'B'=>6,'C'=>32,'D'=>18,'E'=>14,'F'=>10,'G'=>50,'H'=>12,'I'=>30];
    foreach ($widths as $col => $w) $ws->getColumnDimension($col)->setWidth($w);

    // Freeze + zoom + ajustes de impresión
    $ws->freezePane('A' . ($headerRow + 1));
    $ws->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)->setFitToHeight(0);
    $ws->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $headerRow);

    // Output
    $base = $baseStem . '.xlsx';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $base . '"');
    header('Cache-Control: no-store');
    $writer = IOFactory::createWriter($book, 'Xlsx');
    $writer->save('php://output');
}

// ─────────────────────────────────────────────────────────────────────────────
// PDF — calendario imprimible para entregar al operario
// ─────────────────────────────────────────────────────────────────────────────
function _exportProximasPdf(array $rows, array $resumen, string $filtrosTxt, string $baseStem): void
{
    // Agrupamos por fecha para que el operario vea su jornada de un vistazo
    $porDia = [];
    foreach ($rows as $row) {
        $fecha = (string) ($row['proxima_revision'] ?? '');
        if (!isset($porDia[$fecha])) $porDia[$fecha] = [];
        $porDia[$fecha][] = $row;
    }
    ksort($porDia);

    ob_start(); ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><style>
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9pt; color: #1a2d4a; }
    h1 { color: #1a2d4a; font-size: 18pt; margin: 0 0 6pt; }
    h2 { color: #1a2d4a; font-size: 11pt; margin: 12pt 0 4pt;
         border-bottom: 1.5pt solid #1a2d4a; padding-bottom: 3pt; page-break-after: avoid; }
    .portada { border: 2pt solid #1a2d4a; border-radius: 4pt; padding: 14pt 18pt;
               margin: 6pt 0 14pt; text-align: center; }
    .portada h1 { font-size: 22pt; }
    .portada .stamp { color: #5a6b80; font-style: italic; font-size: 10pt; margin-top: 4pt; }
    .portada .total { font-size: 12pt; color: #2d4d7a; font-weight: bold; margin-top: 10pt; }
    .resumen-row { margin-top: 8pt; font-size: 10pt; color: #2d4d7a; }
    .resumen-row .pill { display: inline-block; padding: 2pt 8pt; border-radius: 3pt;
                         margin: 0 4pt; font-weight: bold; }
    .pill-venc { background: #fdecec; color: #8c181a; }
    .pill-urg  { background: #fff4db; color: #92400e; }
    .pill-ok   { background: #e8f3ea; color: #166534; }
    .filter-bar { background: #eef3f8; border: 1px solid #c8d2dd; padding: 6pt 8pt;
                  font-size: 9pt; margin: 6pt 0 12pt; border-radius: 3pt; color: #2d4d7a; }
    table.data { width: 100%; border-collapse: collapse; margin: 4pt 0 0; }
    table.data th { background: #1a2d4a; color: #fff; padding: 4pt 6pt;
                    font-weight: bold; font-size: 8.5pt; text-align: center;
                    border: 1px solid #0e1b2e; }
    table.data td { padding: 4pt 5pt; border: 1px solid #d8d8d8; font-size: 8.5pt;
                    vertical-align: top; }
    table.data td.c { text-align: center; }
    .estado-venc { background: #fdecec; color: #8c181a; font-weight: bold; }
    .estado-urg  { background: #fff4db; color: #92400e; font-weight: bold; }
    .estado-ok   { background: #e8f3ea; color: #166534; font-weight: bold; }
    .dia-block { page-break-inside: avoid; }
    .obs-cell { width: 90pt; }   /* hueco para anotaciones a mano */
    .empty { font-style: italic; color: #888; margin: 8pt 0; text-align: center; }
</style></head>
<body>

<div class="portada">
    <h1>ACCIONES PREVENTIVAS VENCIDAS Y PENDIENTES</h1>
    <div class="stamp">Exportado el <?= date('d/m/Y H:i') ?></div>
    <div class="total"><?= (int)$resumen['exportadas'] ?> tareas a realizar</div>
    <div class="resumen-row">
        <span class="pill pill-venc">Vencidas: <?= (int)$resumen['vencidas'] ?></span>
        <span class="pill pill-urg">Pendientes (≤7 d): <?= (int)$resumen['urgentes'] ?></span>
        <span class="pill pill-ok">En plazo (no listadas): <?= (int)$resumen['en_plazo'] ?></span>
    </div>
</div>

<div class="filter-bar">
    <strong>Filtros aplicados:</strong> <?= _h($filtrosTxt ?: '—') ?>
    <br><em>Este informe lista solo las tareas vencidas y pendientes (≤7 días). Las "en plazo" tienen margen suficiente y no se incluyen.</em>
</div>

<?php if (empty($porDia)): ?>
    <div class="empty">No hay revisiones que cumplan los filtros aplicados.</div>
<?php else: ?>
    <?php foreach ($porDia as $fechaIso => $items):
        $dia = _diaSemanaCorto($fechaIso) . ' · ' . _fmtIso($fechaIso); ?>
        <div class="dia-block">
            <h2><?= _h($dia) ?>  <span style="font-weight:normal;color:#5a6b80;font-size:9pt;">· <?= count($items) ?> tareas</span></h2>
            <table class="data">
                <thead>
                    <tr>
                        <th style="width:20%">Máquina</th>
                        <th style="width:10%">Tarea</th>
                        <th style="width:9%">Period.</th>
                        <th style="width:7%">Tiempo</th>
                        <th>Descripción</th>
                        <th style="width:8%">Estado</th>
                        <th class="obs-cell">Operario / Observaciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $row):
                    $estado = (string)($row['estado'] ?? '');
                    $estCls = $estado === 'vencida' ? 'estado-venc'
                            : ($estado === 'urgente' ? 'estado-urg' : 'estado-ok');
                    $teMin  = $row['tiempo_estimado'] ?? null; ?>
                    <tr>
                        <td><?= _h($row['desc_maquina'] ?? $row['cod_maquina_mant'] ?? '') ?></td>
                        <td><?= _h($row['tarea'] ?? '') ?></td>
                        <td class="c"><?= _h(strtoupper((string)($row['periodicidad'] ?? ''))) ?></td>
                        <td class="c"><?= $teMin !== null ? ((int)$teMin) . ' min' : '—' ?></td>
                        <td><?= _h($row['desc_tarea'] ?? '') ?></td>
                        <td class="c <?= $estCls ?>"><?= _h(_estadoLabel($estado)) ?></td>
                        <td>&nbsp;</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
    <?php
    $html = ob_get_clean();

    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4',
        'orientation'   => 'L',
        'margin_top'    => 12,
        'margin_bottom' => 12,
        'margin_left'   => 10,
        'margin_right'  => 10,
    ]);
    $mpdf->SetTitle('Calendario de mantenimiento preventivo');
    $mpdf->SetCreator('KH Plan Attainment');
    $mpdf->WriteHTML($html);
    while (ob_get_level() > 0) ob_end_clean();
    $mpdf->Output($baseStem . '.pdf', 'D');
}
