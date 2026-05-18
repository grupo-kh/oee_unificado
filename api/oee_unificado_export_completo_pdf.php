<?php
/**
 * Informe completo PDF para una sección (VARILLAS | TROQUELADOS).
 *
 * Mismo contenido que oee_unificado_export_completo.php (XLSX) pero
 * en un único PDF con secciones consecutivas:
 *   1) Disponibilidad — paros por motivo, pivot máquina × hora.
 *   2) Rendimiento  — pérdidas por artículo, pivot máquina × hora.
 *   3) Calidad      — rechazos por defecto, pivot máquina × hora.
 *
 * Parámetros: idénticos a oee_unificado_export_completo.php.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/oee_unificado_export_completo.php_data.php';

function _comH(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Pinta una sección completa (Disponibilidad / Rendimiento / Calidad) como
 * conjunto de tablas pivot por motivo. Se escribe directo a la salida con echo.
 *
 * Layout:
 *   - 1 solo día → pivot compacto: Máquina | 00-23 | Total.
 *   - Multi-día  → Día | Máquina | 00-23 | Total, con subtotal por día y total motivo.
 *
 * @param array<int, array{cod_maquina:string, maquina:string}>             $maqs
 * @param array<string, array<string, array<string, array<int, float|int>>>> $data motivo→día→cod_maquina→hora→valor
 * @param string $unidad   'h' | 'uds'
 * @param bool   $multiDay true cuando el rango cubre más de un día
 * @param bool   $hourly   false → mostrar aviso (rendimiento DAY fallback)
 */
function _comPdfSeccionHtml(
    string $tituloSeccion, string $subtitulo, array $data, array $maqs,
    string $unidad, bool $multiDay, bool $hourly = true,
    string $rowLabel = 'Máquina'
): void {
    echo '<h2 class="metric-h2">' . _comH($tituloSeccion) . ' <span class="metric-sub">' . _comH($subtitulo) . '</span></h2>';

    if (empty($data) || empty($maqs)) {
        echo '<p class="empty">Sin datos disponibles para la selección.</p>';
        return;
    }

    if (!$hourly) {
        echo '<p class="warning"><em>F_his_ct(\'HOUR\') no disponible en este servidor; se muestran totales diarios (las columnas 00-23 quedan vacías).</em></p>';
    }

    $totalColspan = $multiDay ? 27 : 26;

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
        $totLabel = $unidad === 'h'
            ? number_format($totMot, 2, ',', '.') . ' h'
            : number_format((int)round($totMot), 0, ',', '.') . ' uds';

        echo '<div class="motivo-block">';
        echo '<div class="motivo-title"><strong>Motivo:</strong> ' . _comH($mot)
           . ' <span class="motivo-total">Total: ' . _comH($totLabel) . '</span></div>';

        echo '<table class="pivot"><thead><tr>';
        if ($multiDay) echo '<th class="m">Día</th>';
        echo '<th class="m">' . _comH($rowLabel) . '</th>';
        for ($h = 0; $h < 24; $h++) {
            echo '<th>' . str_pad((string)$h, 2, '0', STR_PAD_LEFT) . '</th>';
        }
        echo '<th class="t">Total</th></tr></thead><tbody>';

        $diaMap     = $data[$mot];
        $días       = array_keys($diaMap);
        sort($días);

        $hourTotMot = array_fill(0, 24, 0);
        $totMotAcc  = 0;
        $any        = false;

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

                echo '<tr>';
                if ($multiDay) echo '<td class="m">' . _comH($dia) . '</td>';
                echo '<td class="m">' . _comH($mq['maquina']) . '</td>';
                for ($h = 0; $h < 24; $h++) {
                    $v = $horas[$h] ?? 0;
                    $hourTotDia[$h] += $v;
                    $hourTotMot[$h] += $v;
                    if ($v > 0) {
                        $disp = $unidad === 'h' ? number_format(round($v, 2), 2, ',', '.') : (string)((int)round($v));
                        echo '<td>' . $disp . '</td>';
                    } else {
                        echo '<td class="zero">·</td>';
                    }
                }
                $rowDisp = $unidad === 'h' ? number_format(round($rowTot, 2), 2, ',', '.') : (string)((int)round($rowTot));
                echo '<td class="t">' . $rowDisp . '</td></tr>';
                $totDia    += $rowTot;
                $totMotAcc += $rowTot;
                $rowsThisDia++;
                $any = true;
            }

            // Subtotal por día (solo multiDay)
            if ($multiDay && $rowsThisDia > 0) {
                echo '<tr class="subtotal">';
                echo '<td class="m" colspan="2">TOTAL ' . _comH($dia) . '</td>';
                for ($h = 0; $h < 24; $h++) {
                    if ($hourTotDia[$h] > 0) {
                        $disp = $unidad === 'h' ? number_format(round($hourTotDia[$h], 2), 2, ',', '.') : (string)((int)round($hourTotDia[$h]));
                        echo '<td>' . $disp . '</td>';
                    } else {
                        echo '<td class="zero">·</td>';
                    }
                }
                $sdDisp = $unidad === 'h' ? number_format(round($totDia, 2), 2, ',', '.') : (string)((int)round($totDia));
                echo '<td class="t">' . $sdDisp . '</td></tr>';
            }
        }

        if (!$any) {
            echo '<tr><td class="m empty" colspan="' . $totalColspan . '">(sin datos por máquina)</td></tr>';
        } else {
            echo '<tr class="totals">';
            $labelCol = $multiDay
                ? '<td class="m" colspan="2">TOTAL MOTIVO</td>'
                : '<td class="m">TOTAL</td>';
            echo $labelCol;
            for ($h = 0; $h < 24; $h++) {
                if ($hourTotMot[$h] > 0) {
                    $disp = $unidad === 'h' ? number_format(round($hourTotMot[$h], 2), 2, ',', '.') : (string)((int)round($hourTotMot[$h]));
                    echo '<td>' . $disp . '</td>';
                } else {
                    echo '<td class="zero">·</td>';
                }
            }
            $gtDisp = $unidad === 'h' ? number_format(round($totMotAcc, 2), 2, ',', '.') : (string)((int)round($totMotAcc));
            echo '<td class="t">' . $gtDisp . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
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

    // Contexto de "vista activa" — métricas/motivo/segmentación que tenía el usuario
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

    $maqs    = _completoMaqsSeccion($fdesde, $fhasta, $turnos, $excl, $seccion);
    $codMaqs = array_column($maqs, 'cod_maquina');

    $byRef = ($porParam === 'referencia');
    if ($byRef) {
        $disp      = _completoDisponibilidadPorReferencia($fdesde, $fhasta, $turnos, $codMaqs);
        $dispRows  = _completoRefsDisponibilidad($fdesde, $fhasta, $turnos, $codMaqs);
        $dispLabel = 'Referencia';
        $dispTitle = 'Disponibilidad por referencia';
        $dispSub   = '(horas de paro por motivo · agrupado por referencia)';
    } else {
        $disp      = _completoDisponibilidad($fdesde, $fhasta, $turnos, $codMaqs);
        $dispRows  = $maqs;
        $dispLabel = 'Máquina';
        $dispTitle = 'Disponibilidad';
        $dispSub   = '(horas de paro por motivo)';
    }
    $rend = _completoRendimiento   ($fdesde, $fhasta, $turnos, $codMaqs);
    $cal  = _completoCalidad       ($fdesde, $fhasta, $turnos, $codMaqs);

    $multiDay = $fdesde !== $fhasta;

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><style>
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8pt; color: #1a2d4a; }
    h1 { color: #8c181a; font-size: 15pt; margin: 0 0 4pt; }
    h2.metric-h2 {
        color: #fff; background: #8c181a;
        font-size: 11pt; margin: 12pt 0 6pt;
        padding: 4pt 8pt; border-radius: 3pt;
    }
    h2.metric-h2 .metric-sub { font-weight: normal; font-size: 9pt; opacity: .85; }
    .filter-bar {
        background: #fdf5f5; border: 1px solid #d4a0a1;
        padding: 6pt 8pt; font-size: 9pt; margin-bottom: 8pt;
        border-radius: 3pt;
    }
    .filter-bar b { color: #8c181a; }
    .small { font-size: 8pt; color: #666; }
    .motivo-block { page-break-inside: avoid; margin: 6pt 0 10pt; }
    .motivo-title {
        background: #fdf5f5; padding: 4pt 6pt;
        border-left: 3px solid #8c181a;
        font-size: 9pt; margin-bottom: 2pt;
    }
    .motivo-title strong { color: #8c181a; }
    .motivo-title .motivo-total {
        float: right; color: #8c181a; font-weight: 700;
    }
    table.pivot {
        width: 100%; border-collapse: collapse;
        font-size: 7pt;
    }
    table.pivot th {
        background: #8c181a; color: #fff;
        padding: 2pt 1pt; font-weight: bold;
        border: 1px solid #6d1214; text-align: center;
    }
    table.pivot th.m { text-align: left; padding-left: 4pt; }
    table.pivot th.t { background: #6d1214; }
    table.pivot td {
        padding: 1.5pt 1pt; border: 1px solid #e0e8f0;
        text-align: right; font-size: 7pt;
    }
    table.pivot td.m {
        text-align: left; font-weight: 600;
        padding-left: 4pt; max-width: 24mm;
        overflow: hidden; text-overflow: ellipsis;
        white-space: nowrap;
    }
    table.pivot td.t {
        background: #fdf5f5; font-weight: 700; color: #8c181a;
    }
    table.pivot td.zero { color: #c0c8d0; }
    table.pivot td.empty { font-style: italic; color: #888; text-align: center; }
    table.pivot tr.subtotal td {
        background: #eef3f8; font-weight: 700;
        border-top: 1px solid #c8d2dd;
        color: #2d4d7a;
    }
    table.pivot tr.subtotal td.t { background: #dde9f3; color: #1a2d4a; }
    table.pivot tr.totals td {
        background: #fdebed; font-weight: 700;
        border-top: 2px solid #8c181a;
        color: #8c181a;
    }
    table.pivot tr.totals td.t { background: #f6cccf; color: #6d1214; }
    p.empty   { font-style: italic; color: #666; padding: 6pt 0; }
    p.warning {
        background: #fdf5f5; border-left: 3px solid #8c181a;
        padding: 4pt 8pt; margin: 0 0 6pt; color: #8c181a;
    }
</style></head>
<body>

<h1>OEE Unificado · Informe completo · <?= _comH($seccion) ?></h1>
<div class="filter-bar">
    <b>Rango:</b> <?= _comH($fdesde) ?> → <?= _comH($fhasta) ?>  ·
    <b>Turnos:</b> <?= _comH($turnosLabel) ?>  ·
    <b>Sección:</b> <?= _comH($seccion) ?>
    <?php if ($exclLabel): ?> · <b>Máquinas excluidas:</b> <?= _comH($exclLabel) ?><?php endif; ?>
    <?php if ($metricaLabel): ?> · <b>Métrica activa:</b> <?= _comH($metricaLabel) ?><?php endif; ?>
    <?php if ($motivoParam): ?> · <b>Motivo seleccionado:</b> <?= _comH($motivoParam) ?><?php endif; ?>
    <?php if ($porLabel): ?> · <b>Segmentación:</b> <?= _comH($porLabel) ?><?php endif; ?>
    <br><span class="small">Exportado: <?= date('d/m/Y H:i') ?></span>
</div>

<?php _comPdfSeccionHtml($dispTitle,       $dispSub,                              $disp['data'], $dispRows, 'h',   $multiDay, $disp['hourly'], $dispLabel); ?>
<?php _comPdfSeccionHtml('Rendimiento',    '(horas de pérdida por artículo)',     $rend['data'], $maqs,     'h',   $multiDay, $rend['hourly'], 'Máquina'); ?>
<?php _comPdfSeccionHtml('Calidad',        '(unidades rechazadas por defecto)',   $cal['data'],  $maqs,     'uds', $multiDay, $cal['hourly'],  'Máquina'); ?>

</body>
</html>
    <?php
    $html = ob_get_clean();

    // mPDF — apaisado, márgenes mínimos para que entren 24 columnas
    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4-L',
        'margin_left'   => 8,
        'margin_right'  => 8,
        'margin_top'    => 8,
        'margin_bottom' => 10,
        'margin_header' => 0,
        'margin_footer' => 5,
        'tempDir'       => sys_get_temp_dir(),
    ]);
    $mpdf->SetTitle("OEE Unificado · Informe completo · $seccion · $fdesde a $fhasta");
    $mpdf->SetAuthor('KH Plan Attainment');
    $mpdf->SetFooter('{PAGENO} / {nbpg}');
    $mpdf->WriteHTML($html);

    $stamp = date('Ymd_His');
    $base  = "OEE_Unificado_Completo_{$seccion}_{$fdesde}_a_{$fhasta}_{$stamp}.pdf";
    while (ob_get_level() > 0) ob_end_clean();
    $mpdf->Output($base, \Mpdf\Output\Destination::DOWNLOAD);
    exit;

} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Error al exportar PDF: ' . $e->getMessage()]);
    }
    exit;
}
