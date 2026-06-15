<?php
/**
 * Export PDF · Histórico de fabricación por referencia (mPDF).
 * Sección 1: tabla totales por OF
 * Sección 2: comparativa OFs × máquinas + panel resumen
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/oee_unificado_ref_historico.php_data.php';

function _refHistH(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function _refHistFmt($n): string       { return number_format((int)$n, 0, ',', '.'); }
function _refHistFmtF($n, $dec = 2): string { return number_format((float)$n, $dec, ',', '.'); }

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

    // Máquinas distintas para la tabla comparativa
    $maqsDist = [];
    foreach ($ofs as $of) {
        foreach ($of['maquinas'] as $m) $maqsDist[$m['cod_maquina']] = $m['maquina'];
    }
    ksort($maqsDist);

    ob_start();
    ?>
    <style>
        body { font-family: DejaVuSans, sans-serif; color:#1a2d4a; font-size: 10pt; }
        h1 { color:#3a6aa3; font-size:14pt; margin:0 0 6px 0; }
        h2.section { color:#3a6aa3; font-size:13pt; margin:18px 0 6px 0; border-bottom:1px solid #d4a0a1; padding-bottom:3px; }
        .meta { background:#eef3f8; border:1px solid #f0d0d0; color:#2d4d7a; padding:6px 10px; border-radius:4px; margin:0 0 10px 0; font-size:9pt; }
        .meta strong { color:#3a6aa3; }
        table.hist, table.cmp { width:100%; border-collapse:collapse; font-size:9pt; }
        table.hist thead th, table.cmp thead th {
            background:#3a6aa3; color:#fff; border:1px solid #1a4a7a; padding:5px 6px; text-align:center;
        }
        table.hist tbody td, table.cmp tbody td { border:1px solid #e6c8c8; padding:4px 6px; }
        table.hist tbody td.num, table.cmp tbody td.num { text-align:right; font-variant-numeric:tabular-nums; }
        table.hist tbody tr:nth-child(odd) td, table.cmp tbody tr:nth-child(odd) td { background:#faf3f3; }
        table.hist tfoot td { background:#f4e0e0; font-weight:700; border:1px solid #e6c8c8; padding:5px 6px; }
        table.hist tfoot td.num { text-align:right; }
        .empty { font-style:italic; color:#888; padding:8px 0; }
        .panel { width:100%; margin:6px 0 10px 0; }
        .panel td {
            border:1px solid #c89191; padding:6px 10px; font-size:9pt; vertical-align:top;
        }
        .panel .t { font-weight:700; font-size:8.5pt; text-transform:uppercase; color:#1a4a7a; letter-spacing:.3px; }
        .panel .v { font-size:13pt; font-weight:700; color:#1a2d4a; }
        .panel .sub { font-size:8pt; color:#1a4a7a; margin-top:2px; }
        .panel td.best  { border-color:#10b981; } .panel td.best  .v { color:#10b981; }
        .panel td.worst { border-color:#f59e0b; } .panel td.worst .v { color:#f59e0b; }
        .panel td.avg   { border-color:#3a6aa3; } .panel td.avg   .v { color:#3a6aa3; }
        .panel td.nok   { border-color:#c8102e; } .panel td.nok   .v { color:#c8102e; }
        table.cmp tbody td.maqcell { font-size:8.5pt; }
        table.cmp thead th.maqhead { font-size:9pt; }
    </style>

    <h1>Histórico de fabricación · <?= _refHistH($prod['desc_producto']) ?> (<?= _refHistH($prod['cod_producto']) ?>)</h1>
    <div class="meta">
        <strong>Rango:</strong> <?= _refHistH($fdesde) ?> → <?= _refHistH($fhasta) ?>
        &nbsp;·&nbsp; <strong>OFs:</strong> <?= (int)$tot['num_ofs'] ?>
        &nbsp;·&nbsp; <strong>Máquinas:</strong> <?= (int)$tot['num_maquinas'] ?>
        &nbsp;·&nbsp; <strong>Días con producción:</strong> <?= (int)$tot['dias'] ?>
        &nbsp;·&nbsp; <strong>Total OK:</strong> <?= _refHistFmt($tot['unidades_ok']) ?>
        &nbsp;·&nbsp; <strong>Total NOK:</strong> <?= _refHistFmt($tot['unidades_nok']) ?>
        &nbsp;·&nbsp; <strong>Exportado:</strong> <?= date('d/m/Y H:i') ?>
    </div>

    <h2 class="section">Totales por OF</h2>
    <?php if (empty($ofs)): ?>
        <div class="empty">Sin fabricaciones en el rango seleccionado para esta referencia.</div>
    <?php else: ?>
        <table class="hist">
            <thead>
                <tr>
                    <th style="width:14%">OF</th>
                    <th>Máquina(s)</th>
                    <th style="width:9%">Días</th>
                    <th style="width:14%">Unidades OK</th>
                    <th style="width:14%">Unidades NOK</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ofs as $of): ?>
                    <tr>
                        <td><strong><?= _refHistH($of['cod_of']) ?></strong></td>
                        <td><?= _refHistH(implode(', ', array_map(fn($m) => $m['maquina'], $of['maquinas']))) ?></td>
                        <td class="num"><?= _refHistFmt($of['num_dias']) ?></td>
                        <td class="num"><?= _refHistFmt($of['unidades_ok']) ?></td>
                        <td class="num"><?= _refHistFmt($of['unidades_nok']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right">TOTAL</td>
                    <td class="num"><?= _refHistFmt($tot['unidades_ok']) ?></td>
                    <td class="num"><?= _refHistFmt($tot['unidades_nok']) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <h2 class="section">Comparativa de rendimiento por OF</h2>

    <?php if (empty($ofs)): ?>
        <div class="empty">Sin OFs comparables.</div>
    <?php else: ?>
        <?php
        $top = $ranking[0] ?? null;
        if ($top):
            $isOnly = count($ranking) === 1;
        ?>
            <div style="background:#e6f7ef; border:2px solid #10b981; border-radius:5px; padding:8px 12px; margin:0 0 10px 0; font-size:10pt;">
                🏅 <strong style="color:#10b981; text-transform:uppercase; font-size:9pt; letter-spacing:.5px;">
                    <?= $isOnly ? 'Única máquina del rango' : 'Mejor máquina del rango (sumando todas las OFs)' ?>:
                </strong>
                <br>
                <strong style="color:#10b981; font-size:11pt;"><?= _refHistH($top['maquina']) ?></strong>
                con <strong><?= _refHistFmtF($top['uds_h']) ?> uds/h</strong>
                · <?= _refHistFmt($top['unidades_ok']) ?> OK en <?= _refHistFmtF($top['horas']) ?> h
                · <?= (int)$top['num_ofs'] ?> OF<?= $top['num_ofs'] === 1 ? '' : 's' ?>
                · <?= _refHistFmtF($top['nok_pct']) ?>% NOK
            </div>

            <?php if (count($ranking) > 1): ?>
                <h3 style="color:#1a4a7a; font-size:10pt; margin:8px 0 4px 0;">Ranking de máquinas (sumando todas las OFs del rango)</h3>
                <table class="cmp" style="margin-bottom:10px;">
                    <thead>
                        <tr>
                            <th style="width:5%">#</th>
                            <th>Máquina</th>
                            <th style="width:7%">OFs</th>
                            <th style="width:10%">OK</th>
                            <th style="width:9%">NOK</th>
                            <th style="width:9%">Horas</th>
                            <th style="width:11%">uds/h</th>
                            <th style="width:8%">% NOK</th>
                            <th style="width:9%">vs mejor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ranking as $i => $m):
                            $isBest = ($i === 0);
                            $rowStyle = $isBest ? 'style="background:#e6f7ef; font-weight:700;"' : '';
                        ?>
                            <tr <?= $rowStyle ?>>
                                <td class="num"><?= ($i + 1) . ($isBest ? ' 🏅' : '') ?></td>
                                <td><?= _refHistH($m['maquina']) ?></td>
                                <td class="num"><?= _refHistFmt($m['num_ofs']) ?></td>
                                <td class="num"><?= _refHistFmt($m['unidades_ok']) ?></td>
                                <td class="num"><?= _refHistFmt($m['unidades_nok']) ?></td>
                                <td class="num"><?= _refHistFmtF($m['horas']) ?></td>
                                <td class="num"><strong><?= _refHistFmtF($m['uds_h']) ?></strong></td>
                                <td class="num"><?= _refHistFmtF($m['nok_pct']) ?>%</td>
                                <td class="num"><?= _refHistFmtF($m['pct_vs_best'], 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Detalle por máquina: tabla OFs + stats panel -->
        <?php foreach ($ranking as $idx => $m):
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
            $udsValid = array_filter(array_column($cells, 'uds_h'), fn($v) => $v > 0);
            $maxV = !empty($udsValid) ? max($udsValid) : 0;
            $minV = !empty($udsValid) ? min($udsValid) : 0;
            $avgV = !empty($udsValid) ? array_sum($udsValid) / count($udsValid) : 0;
            $tOk  = array_sum(array_column($cells, 'unidades_ok'));
            $tNok = array_sum(array_column($cells, 'unidades_nok'));
            $tAll = $tOk + $tNok;
            $pOk  = $tAll > 0 ? $tOk / $tAll * 100 : 0;
            $pNok = $tAll > 0 ? $tNok / $tAll * 100 : 0;
            $isBest = ($idx === 0 && count($ranking) > 1);
        ?>
            <h3 style="color:#1a2d4a; font-size:11pt; margin:14px 0 4px 0; background:#eef3f8; border-left:4px solid <?= $isBest ? '#10b981' : '#3a6aa3' ?>; padding:4px 8px;">
                Máquina: <strong><?= _refHistH($m['maquina']) ?></strong> <?= $isBest ? '🏅' : '' ?>
                <span style="font-weight:400; font-size:9pt; color:#1a4a7a;">
                    · <?= count($cells) ?> OF<?= count($cells) === 1 ? '' : 's' ?>
                </span>
            </h3>
            <table class="cmp" style="margin-bottom:4px;">
                <thead>
                    <tr>
                        <th style="width:24%">OF</th>
                        <th style="width:16%">uds/h</th>
                        <th style="width:14%">Unidades OK</th>
                        <th style="width:14%">Unidades NOK</th>
                        <th style="width:14%">Horas</th>
                        <th style="width:10%">% NOK</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cells as $c): ?>
                        <tr>
                            <td><strong><?= _refHistH($c['cod_of']) ?></strong></td>
                            <td class="num"><?= _refHistFmtF($c['uds_h']) ?></td>
                            <td class="num"><?= _refHistFmt($c['unidades_ok']) ?></td>
                            <td class="num"><?= _refHistFmt($c['unidades_nok']) ?></td>
                            <td class="num"><?= _refHistFmtF($c['horas']) ?></td>
                            <td class="num"><?= _refHistFmtF($c['nok_pct']) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <table class="cmp" style="margin-bottom:10px; font-size:8.5pt;">
                <thead>
                    <tr>
                        <th>Máx piezas/hora</th>
                        <th>Mín piezas/hora</th>
                        <th>Promedio piezas/hora</th>
                        <th>Total OK</th>
                        <th>Total NOK</th>
                        <th>% OK</th>
                        <th>% NOK</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background:#eef3f8; font-weight:700;">
                        <td class="num"><?= _refHistFmtF($maxV) ?></td>
                        <td class="num"><?= _refHistFmtF($minV) ?></td>
                        <td class="num"><?= _refHistFmtF($avgV) ?></td>
                        <td class="num" style="color:#10b981;"><?= _refHistFmt($tOk) ?></td>
                        <td class="num" style="color:#c8102e;"><?= _refHistFmt($tNok) ?></td>
                        <td class="num" style="color:#10b981;"><?= _refHistFmtF($pOk) ?>%</td>
                        <td class="num" style="color:#c8102e;"><?= _refHistFmtF($pNok) ?>%</td>
                    </tr>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    $html = ob_get_clean();

    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4-L',
        'margin_left'   => 10,
        'margin_right'  => 10,
        'margin_top'    => 12,
        'margin_bottom' => 14,
        'margin_header' => 0,
        'margin_footer' => 6,
        'tempDir'       => sys_get_temp_dir(),
    ]);
    $mpdf->SetTitle('Histórico referencia · ' . $prod['cod_producto']);
    $mpdf->SetAuthor('KH Plan Attainment');
    $mpdf->SetFooter('{PAGENO} / {nbpg}');
    $mpdf->WriteHTML($html);

    $safeCod = preg_replace('/[^A-Za-z0-9_\-]/', '_', $prod['cod_producto']);
    $stamp = date('Ymd_His');
    $base = "Historico_referencia_{$safeCod}_{$fdesde}_a_{$fhasta}_{$stamp}.pdf";
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
