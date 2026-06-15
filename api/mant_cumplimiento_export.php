<?php
/**
 * Export del informe de CUMPLIMIENTO PREVENTIVO en XLSX o PDF.
 * Respeta los mismos filtros que la vista (fecha_desde, fecha_hasta,
 * cod_maquina_mant, periodicidad).
 *
 * Parámetros (GET):
 *   - fecha_desde / fecha_hasta : rango (YYYY-MM-DD). Defaults = mes actual.
 *   - cod_maquina_mant          : opcional, filtrar por máquina.
 *   - periodicidad              : opcional, filtrar por periodicidad.
 *   - fmt                       : 'xlsx' (default) | 'pdf'.
 *
 * El cálculo del cumplimiento por mes incluye también las tareas vivas con
 * fecha próxima vencida y sin marcar (cuentan como pendientes) — coherente
 * con el cálculo actualizado de api/mant_cumplimiento.php.
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

function _mesLabel(string $ym): string
{
    $meses = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio',
              '07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
    $parts = explode('-', $ym);
    if (count($parts) !== 2) return $ym;
    $mm = $meses[$parts[1]] ?? $parts[1];
    return $mm . ' ' . $parts[0];
}

try {
    ini_set('memory_limit', '256M');

    $hoy = date('Y-m-d');
    $cm  = (string) getParam('cod_maquina_mant', '');
    $pe  = (string) getParam('periodicidad', '');
    $defDesde = date('Y-m-01');
    $defHasta = date('Y-m-t');
    $fdesde   = (string) getParam('fecha_desde', $defDesde);
    $fhasta   = (string) getParam('fecha_hasta', $defHasta);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');

    $fmt = strtolower((string) getParam('fmt', 'xlsx'));
    if (!in_array($fmt, ['xlsx', 'pdf'], true)) jsonError("fmt debe ser 'xlsx' o 'pdf'");

    // ─────────── Reproducimos el cálculo de api/mant_cumplimiento.php ───────────
    $data           = MaintenancePlanStore::load();
    $proximas       = $data['proximas'];
    $marcadasIdx    = MaintenanceCompletionStore::loadIndexed();

    $isSecuencia = function (string $desc): bool {
        $s = trim($desc);
        return preg_match('/^E66($|[^A-Za-z0-9])/i', $s)
            || preg_match('/^RACK[\s\-_]/i', $s)
            || preg_match('/^PLATAFORMA/i', $s);
    };

    // Gauge global
    $realizadas = 0;
    foreach ($marcadasIdx as $rec) {
        $tipo = (string)($rec['tipo'] ?? '');
        if ($tipo !== 'completada' && $tipo !== 'recuperacion') continue;
        if ($cm && ($rec['cod_maquina_mant'] ?? '') !== $cm) continue;
        if ($pe && ($rec['periodicidad']    ?? '') !== $pe) continue;
        $fi = (string)($rec['fecha_intervencion'] ?? '');
        if ($fi === '' || $fi < $fdesde || $fi > $fhasta) continue;
        $descRec = (string)($rec['desc_maquina'] ?? '');
        if ($tipo === 'recuperacion' && $isSecuencia($descRec)) continue;
        $realizadas++;
    }

    $previstas = 0; $atrasadas = 0;
    foreach ($proximas as $p) {
        $px = $p['proxima_revision'] ?? null;
        if ($px === null || $px === '') continue;
        if ($cm && $p['cod_maquina_mant'] !== $cm) continue;
        if ($pe && ($p['periodicidad'] ?? '') !== $pe) continue;
        if ($isSecuencia((string)$p['desc_maquina'])) continue;

        if ($px >= $fdesde && $px <= $fhasta) $previstas++;
        elseif ($px < $fdesde) $atrasadas++;
    }
    $denomTot = $realizadas + $previstas + $atrasadas;
    $cumplGlob = $denomTot > 0 ? round($realizadas / $denomTot * 100, 2) : 0;

    // Cumplimiento por mes — incluye vencidas sin marcar
    $perMesAcc    = [];
    $marcasClaves = [];

    foreach ($marcadasIdx as $rec) {
        $tipo = (string)($rec['tipo'] ?? '');
        if ($tipo === '') {
            $tipo = empty($rec['fecha_intervencion']) ? 'no_realizada' : 'completada';
        }
        $cmRec = (string)($rec['cod_maquina_mant'] ?? '');
        $peRec = (string)($rec['periodicidad'] ?? '');
        $descRec = (string)($rec['desc_maquina'] ?? '');
        $ordenRec = (string)($rec['orden'] ?? '');
        $tareaRec = (string)($rec['tarea'] ?? '');
        $fpoRec   = (string)($rec['fecha_proxima_original'] ?? '');

        if ($cm && $cmRec !== $cm) continue;
        if ($pe && $peRec !== $pe) continue;
        if (($tipo === 'no_realizada' || $tipo === 'recuperacion') && $isSecuencia($descRec)) continue;

        if ($tipo === 'recuperacion') {
            $fi = (string)($rec['fecha_intervencion'] ?? '');
            if ($fi === '' || $fi < $fdesde || $fi > $fhasta) continue;
            $m = substr($fi, 0, 7);
            if (!isset($perMesAcc[$m])) $perMesAcc[$m] = ['denom'=>0,'numer'=>0,'completadas'=>0,'no_realizadas'=>0,'recuperaciones'=>0,'vencidas_sin_marcar'=>0];
            $perMesAcc[$m]['numer']++;
            $perMesAcc[$m]['recuperaciones']++;
        } else {
            if ($fpoRec === '' || $fpoRec < $fdesde || $fpoRec > $fhasta) continue;
            $m = substr($fpoRec, 0, 7);
            if (!isset($perMesAcc[$m])) $perMesAcc[$m] = ['denom'=>0,'numer'=>0,'completadas'=>0,'no_realizadas'=>0,'recuperaciones'=>0,'vencidas_sin_marcar'=>0];
            $perMesAcc[$m]['denom']++;
            if ($tipo === 'completada' || !empty($rec['fecha_intervencion'])) {
                $perMesAcc[$m]['numer']++;
                $perMesAcc[$m]['completadas']++;
            } else {
                $perMesAcc[$m]['no_realizadas']++;
            }
            $marcasClaves[$ordenRec . '||' . $tareaRec . '||' . $fpoRec] = true;
        }
    }

    foreach ($proximas as $p) {
        $px = (string)($p['proxima_revision'] ?? '');
        if ($px === '' || $px < $fdesde || $px > $fhasta) continue;
        if ($px >= $hoy) continue;
        $cmP = (string)($p['cod_maquina_mant'] ?? '');
        $peP = (string)($p['periodicidad'] ?? '');
        if ($cm && $cmP !== $cm) continue;
        if ($pe && $peP !== $pe) continue;
        if ($isSecuencia((string)($p['desc_maquina'] ?? ''))) continue;
        $clave = (string)($p['orden'] ?? '') . '||' . (string)($p['tarea'] ?? '') . '||' . $px;
        if (isset($marcasClaves[$clave])) continue;
        $m = substr($px, 0, 7);
        if (!isset($perMesAcc[$m])) $perMesAcc[$m] = ['denom'=>0,'numer'=>0,'completadas'=>0,'no_realizadas'=>0,'recuperaciones'=>0,'vencidas_sin_marcar'=>0];
        $perMesAcc[$m]['denom']++;
        $perMesAcc[$m]['vencidas_sin_marcar']++;
    }
    ksort($perMesAcc);

    // Unificar el cumplimiento global del informe con la suma de meses,
    // igual que hace api/mant_cumplimiento.php. Antes el KPI grande del PDF
    // y la cifra de la tabla por mes podían no cuadrar (mismo bug que en la
    // vista). Ahora cuadran siempre.
    $sumDenom = 0; $sumNumer = 0;
    foreach ($perMesAcc as $v) {
        $sumDenom += $v['denom'];
        $sumNumer += $v['numer'];
    }
    $cumplGlob = $sumDenom > 0 ? round($sumNumer / $sumDenom * 100, 2) : 0;
    $denomTot  = $sumDenom;
    $realizadas = $sumNumer;
    // 'previstas' y 'atrasadas' se mantienen como cifras informativas en el
    // resumen del XLSX/PDF (denominador alternativo basado en el plan), pero
    // ya no se usan para calcular el porcentaje.

    // Resolver nombre de máquina para el filtro (si aplica)
    $descMaqFiltro = '';
    if ($cm !== '') {
        foreach ($proximas as $p) {
            if ($p['cod_maquina_mant'] === $cm) { $descMaqFiltro = (string)$p['desc_maquina']; break; }
        }
    }

    $filtros = [];
    $filtros[] = 'Rango: ' . date('d/m/Y', strtotime($fdesde)) . ' → ' . date('d/m/Y', strtotime($fhasta));
    if ($cm !== '') $filtros[] = 'Máquina: ' . ($descMaqFiltro !== '' ? $descMaqFiltro : $cm);
    if ($pe !== '') $filtros[] = 'Periodicidad: ' . strtoupper($pe);
    $filtrosTxt = implode('  ·  ', $filtros);

    $stamp = date('Ymd_His');
    $baseStem = 'Cumplimiento_Preventivo_' . $stamp;

    $global = [
        'cumplimiento' => $cumplGlob,
        'realizadas'   => $realizadas,
        'previstas'    => $previstas,
        'atrasadas'    => $atrasadas,
        'total'        => $denomTot,
    ];

    if ($fmt === 'xlsx') {
        _exportCumplXlsx($global, $perMesAcc, $filtrosTxt, $baseStem);
    } else {
        _exportCumplPdf($global, $perMesAcc, $filtrosTxt, $baseStem);
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
// XLSX
// ─────────────────────────────────────────────────────────────────────────────
function _exportCumplXlsx(array $global, array $perMesAcc, string $filtrosTxt, string $baseStem): void
{
    $book = new Spreadsheet();
    $book->getProperties()
        ->setCreator('KH Plan Attainment')
        ->setTitle('Informe de Cumplimiento Preventivo')
        ->setDescription('Cumplimiento preventivo global y desglose por mes');

    // ───── Hoja 1: Resumen ─────
    $ws = $book->getActiveSheet();
    $ws->setTitle('Resumen');
    $ws->getColumnDimension('A')->setWidth(30);
    $ws->getColumnDimension('B')->setWidth(18);

    $ws->setCellValue('A1', 'INFORME DE CUMPLIMIENTO PREVENTIVO');
    $ws->mergeCells('A1:B1');
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('FFFFFF');
    $ws->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A2D4A');
    $ws->getStyle('A1')->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(1)->setRowHeight(28);

    $ws->setCellValue('A2', $filtrosTxt . '  ·  Exportado: ' . date('d/m/Y H:i'));
    $ws->mergeCells('A2:B2');
    $ws->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('2D4D7A');
    $ws->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF3F8');
    $ws->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getRowDimension(2)->setRowHeight(22);

    $rows = [
        ['Cumplimiento (%)', $global['cumplimiento']],
        ['Realizadas',       $global['realizadas']],
        ['Previstas',        $global['previstas']],
        ['Atrasadas',        $global['atrasadas']],
        ['Total',            $global['total']],
    ];
    $r = 4;
    foreach ($rows as $row) {
        $ws->setCellValue("A$r", $row[0]);
        $ws->setCellValue("B$r", $row[1]);
        $ws->getStyle("A$r:B$r")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CCCCCC');
        $ws->getStyle("A$r")->getFont()->setBold(true);
        $ws->getStyle("B$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $r++;
    }
    // KPI destacado del cumplimiento
    $ws->getStyle('B4')->getFont()->setBold(true)->setSize(14);
    $pct = (float)$global['cumplimiento'];
    $color = $pct >= 90 ? '166534' : ($pct >= 70 ? 'B45309' : '8C181A');
    $ws->getStyle('B4')->getFont()->getColor()->setRGB($color);

    // ───── Hoja 2: Cumplimiento por mes ─────
    $ws2 = $book->createSheet();
    $ws2->setTitle('Por mes');
    $headers = ['Mes', 'Cumplimiento (%)', 'Programadas', 'Realizadas',
                'Completadas', 'No realizadas', 'Pendientes', 'Recuperaciones'];
    $headerRow = 1;
    foreach ($headers as $i => $h) $ws2->setCellValue([$i + 1, $headerRow], $h);
    $rangoHead = 'A1:H1';
    $ws2->getStyle($rangoHead)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $ws2->getStyle($rangoHead)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A2D4A');
    $ws2->getStyle($rangoHead)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $ws2->getStyle($rangoHead)->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('0E1B2E');

    $r = 2;
    foreach ($perMesAcc as $mes => $v) {
        $pct = $v['denom'] > 0 ? round($v['numer'] / $v['denom'] * 100, 2) : null;
        $ws2->setCellValue("A$r", _mesLabel($mes));
        $ws2->setCellValue("B$r", $pct !== null ? $pct : '—');
        $ws2->setCellValue("C$r", (int)$v['denom']);
        $ws2->setCellValue("D$r", (int)$v['numer']);
        $ws2->setCellValue("E$r", (int)$v['completadas']);
        $ws2->setCellValue("F$r", (int)$v['no_realizadas']);
        $ws2->setCellValue("G$r", (int)$v['vencidas_sin_marcar']);
        $ws2->setCellValue("H$r", (int)$v['recuperaciones']);

        // Color de la celda de cumplimiento según umbral
        if ($pct !== null) {
            $bg = $pct >= 90 ? 'E8F3EA' : ($pct >= 70 ? 'FFF4DB' : 'FDECEC');
            $ws2->getStyle("B$r")->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($bg);
            $ws2->getStyle("B$r")->getFont()->setBold(true);
        }
        $ws2->getStyle("A$r:H$r")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CCCCCC');
        $ws2->getStyle("B$r:H$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $r++;
    }
    if (empty($perMesAcc)) {
        $ws2->setCellValue("A2", 'Sin datos en el rango filtrado.');
        $ws2->mergeCells("A2:H2");
        $ws2->getStyle("A2")->getFont()->setItalic(true)->getColor()->setRGB('888888');
        $ws2->getStyle("A2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
    $widths = ['A'=>18,'B'=>18,'C'=>14,'D'=>14,'E'=>14,'F'=>14,'G'=>20,'H'=>15];
    foreach ($widths as $col => $w) $ws2->getColumnDimension($col)->setWidth($w);
    $ws2->freezePane('A2');

    $book->setActiveSheetIndex(0);

    $base = $baseStem . '.xlsx';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $base . '"');
    header('Cache-Control: no-store');
    $writer = IOFactory::createWriter($book, 'Xlsx');
    $writer->save('php://output');
}

// ─────────────────────────────────────────────────────────────────────────────
// PDF
// ─────────────────────────────────────────────────────────────────────────────
function _exportCumplPdf(array $global, array $perMesAcc, string $filtrosTxt, string $baseStem): void
{
    $pct = (float)$global['cumplimiento'];
    $colorPct = $pct >= 90 ? '#166534' : ($pct >= 70 ? '#b45309' : '#c8102e');

    ob_start(); ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><style>
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9.5pt; color: #1a2d4a; }
    h1 { color: #1a2d4a; font-size: 18pt; margin: 0 0 6pt; }
    h2 { color: #1a2d4a; font-size: 12pt; margin: 14pt 0 6pt;
         border-bottom: 1.5pt solid #1a2d4a; padding-bottom: 3pt; page-break-after: avoid; }
    .portada { border: 2pt solid #1a2d4a; border-radius: 4pt; padding: 14pt 18pt;
               margin: 6pt 0 14pt; text-align: center; }
    .portada h1 { font-size: 22pt; }
    .portada .stamp { color: #5a6b80; font-style: italic; font-size: 10pt; margin-top: 4pt; }
    .kpi { font-size: 38pt; font-weight: bold; color: <?= $colorPct ?>;
           margin: 12pt 0 4pt; }
    .kpi-sub { font-size: 11pt; color: #5a6b80; }
    .resumen { margin-top: 12pt; }
    .resumen .pill { display: inline-block; padding: 3pt 10pt; border-radius: 3pt;
                     margin: 0 4pt; font-weight: bold; font-size: 10pt; }
    .pill-ok   { background: #e8f3ea; color: #166534; }
    .pill-prev { background: #eef3f8; color: #2d4d7a; }
    .pill-atr  { background: #fdecec; color: #c8102e; }
    .filter-bar { background: #eef3f8; border: 1px solid #c8d2dd; padding: 6pt 8pt;
                  font-size: 9pt; margin: 6pt 0 12pt; border-radius: 3pt; color: #2d4d7a; }
    table.data { width: 100%; border-collapse: collapse; margin: 4pt 0 0; }
    table.data th { background: #1a2d4a; color: #fff; padding: 5pt 6pt;
                    font-weight: bold; font-size: 9pt; text-align: center;
                    border: 1px solid #0e1b2e; }
    table.data td { padding: 4pt 6pt; border: 1px solid #d8d8d8; font-size: 9pt;
                    vertical-align: middle; text-align: center; }
    table.data td.l { text-align: left; font-weight: bold; }
    .cell-ok   { background: #e8f3ea; color: #166534; font-weight: bold; }
    .cell-med  { background: #fff4db; color: #92400e; font-weight: bold; }
    .cell-bad  { background: #fdecec; color: #c8102e; font-weight: bold; }
    .empty { font-style: italic; color: #888; margin: 8pt 0; text-align: center; }
    .leyenda { font-size: 8.5pt; color: #5a6b80; margin-top: 8pt; font-style: italic; }
</style></head>
<body>

<div class="portada">
    <h1>INFORME DE CUMPLIMIENTO PREVENTIVO</h1>
    <div class="stamp">Exportado el <?= date('d/m/Y H:i') ?></div>

    <div class="kpi"><?= number_format($pct, 2, ',', '.') ?> %</div>
    <div class="kpi-sub">Cumplimiento del rango</div>

    <div class="resumen">
        <span class="pill pill-ok">Realizadas: <?= (int)$global['realizadas'] ?></span>
        <span class="pill pill-prev">Previstas: <?= (int)$global['previstas'] ?></span>
        <span class="pill pill-atr">Atrasadas: <?= (int)$global['atrasadas'] ?></span>
        <span class="pill" style="background:#1a2d4a;color:#fff">Total: <?= (int)$global['total'] ?></span>
    </div>
</div>

<div class="filter-bar">
    <strong>Filtros:</strong> <?= _h($filtrosTxt ?: '—') ?>
</div>

<h2>Cumplimiento por mes</h2>

<?php if (empty($perMesAcc)): ?>
    <div class="empty">No hay datos en el rango filtrado.</div>
<?php else: ?>
<table class="data">
    <thead>
        <tr>
            <th style="width:18%">Mes</th>
            <th style="width:14%">Cumplimiento</th>
            <th>Programadas</th>
            <th>Realizadas</th>
            <th>Completadas</th>
            <th>No realizadas</th>
            <th>Pendientes</th>
            <th>Recuperaciones</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($perMesAcc as $mes => $v):
        $pctM = $v['denom'] > 0 ? round($v['numer'] / $v['denom'] * 100, 2) : null;
        $cls  = $pctM === null ? '' : ($pctM >= 90 ? 'cell-ok' : ($pctM >= 70 ? 'cell-med' : 'cell-bad')); ?>
        <tr>
            <td class="l"><?= _h(_mesLabel((string)$mes)) ?></td>
            <td class="<?= $cls ?>"><?= $pctM === null ? '—' : number_format($pctM, 2, ',', '.') . ' %' ?></td>
            <td><?= (int)$v['denom'] ?></td>
            <td><?= (int)$v['numer'] ?></td>
            <td><?= (int)$v['completadas'] ?></td>
            <td><?= (int)$v['no_realizadas'] ?></td>
            <td><?= (int)$v['vencidas_sin_marcar'] ?></td>
            <td><?= (int)$v['recuperaciones'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<div class="leyenda">
    Las "Pendientes" son tareas cuya fecha de próxima revisión ya pasó pero
    nadie las ha marcado todavía: cuentan como no realizadas en el cálculo del mes.
    Una tarea marcada como "Recuperación" suma como realizada en el mes en que
    se hizo, no en el mes original.
</div>
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
        'margin_left'   => 12,
        'margin_right'  => 12,
    ]);
    $mpdf->SetTitle('Informe de Cumplimiento Preventivo');
    $mpdf->SetCreator('KH Plan Attainment');
    $mpdf->WriteHTML($html);
    while (ob_get_level() > 0) ob_end_clean();
    $mpdf->Output($baseStem . '.pdf', 'D');
}
