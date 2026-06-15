<?php
/**
 * Export OEE Unificado a PDF (mPDF).
 *
 * Replica los mismos datos que el xlsx pero con una sola cabecera al inicio
 * y secciones consecutivas equivalentes a cada hoja del libro:
 *   1) OEE por Sección
 *   2) Evolución OEE (Diaria/Semanal/Mensual)
 *   3) Detalle por máquina (OFs + Refs)             — si detalle_cod_maquina
 *   4) Desglose D/R/C/OEE de la sección             — si seccion
 *   5) D/R/C/OEE por máquina                          — si seccion + metrica
 *   6) Motivos Pareto                                  — si seccion + metrica
 *   7) Motivo seleccionado (por máquina + por hora)   — si motivo activo
 *
 * Parámetros: idénticos a oee_unificado_export.php.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
require_once __DIR__ . '/../vendor/autoload.php';

function _secPdf(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

function _calcDRCPdf(float $M, float $MT, float $MOT, float $MOKT, float $PP, float $PC, float $PNP): array {
    $d   = ($M + $PNP)      > 0 ? $M / ($M + $PNP) * 100              : 0;
    $r   = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
    $c   = ($MOT + $PC)     > 0 ? $MOKT / ($MOT + $PC) * 100           : 0;
    $oee = $d * $r * $c / 10000;
    return [round($d, 2), round($r, 2), round($c, 2), round($oee, 2), (int)$MT];
}

function _h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

try {
    ini_set('memory_limit', '512M');

    $fdesde         = (string) getParam('fecha_desde');
    $fhasta         = (string) getParam('fecha_hasta');
    $seccion        = getParam('seccion');
    $metrica        = getParam('metrica');
    $codMaq         = getParam('cod_maquina');
    $codRef         = getParam('cod_referencia');
    $por            = (string) (getParam('por') ?: 'maquina');
    $maqNombre      = getParam('maq_nombre');
    $maqMotivo      = getParam('maq_motivo');
    $maqMotivoDia   = getParam('maq_motivo_dia');
    $maqMotivoHora  = getParam('maq_motivo_hora');
    $motivo         = getParam('motivo');
    $motivoCodMaq   = getParam('motivo_cod_maquina');
    $detalleCodMaq  = getParam('detalle_cod_maquina');
    $periodoLabel   = getParam('periodo_label');
    $rangoBaseDesde = getParam('rango_base_desde');
    $rangoBaseHasta = getParam('rango_base_hasta');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');

    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));
    $excl   = getListParam('excl');

    $turnosLabel = empty($turnos) ? 'Todos' : implode(', ', $turnos);
    $metricaLabel = $metrica
        ? (['disponibilidad'=>'Disponibilidad','rendimiento'=>'Rendimiento','calidad'=>'Calidad','oee'=>'OEE'][$metrica] ?? $metrica)
        : '';
    $porLabel = $por === 'referencia' ? 'Por Referencia' : 'Por Máquina';

    $maqDetalleLabel = '';
    if ($detalleCodMaq) {
        $r = fetchAll('mapex', "SELECT TOP 1 Desc_maquina FROM cfg_maquina WHERE Cod_maquina = ?", [$detalleCodMaq]);
        $maqDetalleLabel = $r[0]['Desc_maquina'] ?? $detalleCodMaq;
    }
    $motivoMaqLabel = '';
    if ($motivoCodMaq) {
        $r = fetchAll('mapex', "SELECT TOP 1 Desc_maquina FROM cfg_maquina WHERE Cod_maquina = ?", [$motivoCodMaq]);
        $motivoMaqLabel = $r[0]['Desc_maquina'] ?? $motivoCodMaq;
    }
    // Drill por máquina/referencia activo en pantalla (entidad sobre la que
    // el usuario ha profundizado tras clicar una barra del drill métrica)
    $maqDrillLabel = '';
    $maqDrillTipo  = '';
    if ($por === 'referencia' && $codRef) {
        $maqDrillTipo = 'Referencia';
        if ($maqNombre) {
            $maqDrillLabel = (string)$maqNombre;
        } else {
            $r = fetchAll('mapex', "SELECT TOP 1 Desc_producto FROM cfg_producto WHERE Cod_producto = ?", [(string)$codRef]);
            $maqDrillLabel = $r[0]['Desc_producto'] ?? (string)$codRef;
        }
    } elseif ($por !== 'referencia' && $codMaq) {
        $maqDrillTipo = 'Máquina';
        if ($maqNombre) {
            $maqDrillLabel = (string)$maqNombre;
        } else {
            $r = fetchAll('mapex', "SELECT TOP 1 Desc_maquina FROM cfg_maquina WHERE Cod_maquina = ?", [(string)$codMaq]);
            $maqDrillLabel = $r[0]['Desc_maquina'] ?? (string)$codMaq;
        }
    }

    // Etiqueta legible de máquinas excluidas
    $exclLabel = '';
    if (!empty($excl)) {
        $ph = implode(',', array_fill(0, count($excl), '?'));
        $rowsExcl = fetchAll('mapex',
            "SELECT Cod_maquina, Desc_maquina FROM cfg_maquina WHERE Cod_maquina IN ($ph)",
            $excl
        );
        $mapNames = [];
        foreach ($rowsExcl as $rE) $mapNames[$rE['Cod_maquina']] = $rE['Desc_maquina'] ?: $rE['Cod_maquina'];
        $exclLabel = implode(', ', array_map(fn($c) => $mapNames[$c] ?? $c, $excl));
    }

    // ───── Fetch base data ─────
    $where  = [
        "CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?",
        "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')",
    ];
    $params = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "oee.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    if (!empty($excl)) {
        $ph = implode(',', array_fill(0, count($excl), '?'));
        $where[] = "oee.WorkGroup NOT IN ($ph)";
        $params = array_merge($params, $excl);
    }
    $whereSQL = implode(' AND ', $where);

    $sql = "
        SELECT oee.WorkGroup AS cod_maquina, mq.Desc_maquina AS maquina,
            SUM(oee.M) AS M, SUM(oee.M_Teo) AS M_Teo,
            SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO, SUM(oee.M_OK_TEO) AS M_OK_TEO,
            SUM(oee.PPERF) AS PPERF, SUM(oee.PCALIDAD) AS PCALIDAD, SUM(oee.PNP) AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE $whereSQL
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
    ";
    $rows = fetchAll('mapex', $sql, array_merge([$fdesde, $fhasta], $params));

    $globalAcc = ['M'=>0,'MT'=>0,'MOT'=>0,'MOKT'=>0,'PP'=>0,'PC'=>0,'PNP'=>0];
    $secAcc = [];
    $maqData = [];
    foreach ($rows as $r) {
        $M=(float)$r['M']; $MT=(float)$r['M_Teo']; $MOT=(float)$r['M_OKNOK_TEO'];
        $MOKT=(float)$r['M_OK_TEO']; $PP=(float)$r['PPERF']; $PC=(float)$r['PCALIDAD']; $PNP=(float)$r['PNP'];
        $sec = _secPdf($r['maquina']);
        $globalAcc['M']+=$M; $globalAcc['MT']+=$MT; $globalAcc['MOT']+=$MOT;
        $globalAcc['MOKT']+=$MOKT; $globalAcc['PP']+=$PP; $globalAcc['PC']+=$PC; $globalAcc['PNP']+=$PNP;

        $sKey = $sec ?: 'OTROS';
        if (!isset($secAcc[$sKey])) $secAcc[$sKey] = ['M'=>0,'MT'=>0,'MOT'=>0,'MOKT'=>0,'PP'=>0,'PC'=>0,'PNP'=>0];
        $secAcc[$sKey]['M']+=$M; $secAcc[$sKey]['MT']+=$MT; $secAcc[$sKey]['MOT']+=$MOT;
        $secAcc[$sKey]['MOKT']+=$MOKT; $secAcc[$sKey]['PP']+=$PP; $secAcc[$sKey]['PC']+=$PC; $secAcc[$sKey]['PNP']+=$PNP;

        if ($sec) {
            $maqData[] = [
                'maquina' => $r['maquina'] ?: $r['cod_maquina'],
                'cod_maquina' => $r['cod_maquina'],
                'seccion' => $sec,
                'drc' => _calcDRCPdf($M, $MT, $MOT, $MOKT, $PP, $PC, $PNP),
            ];
        }
    }

    // ───── Build HTML ─────
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><style>
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9pt; color: #1a2d4a; }
    h1 { color: #3a6aa3; font-size: 16pt; margin: 0 0 4pt; }
    h2 { color: #3a6aa3; font-size: 12pt; margin: 14pt 0 6pt; border-bottom: 1px solid #d4a0a1; padding-bottom: 3pt; }
    h2.first { margin-top: 4pt; }
    .filter-bar { background: #eef3f8; border: 1px solid #d4a0a1; padding: 6pt 8pt; font-size: 9pt; margin-bottom: 10pt; border-radius: 3pt; }
    .filter-bar b { color: #3a6aa3; }
    table.data { width: 100%; border-collapse: collapse; margin: 4pt 0 0; }
    table.data th { background: #3a6aa3; color: #fff; padding: 4pt 6pt; font-weight: bold; font-size: 9pt; text-align: center; border: 1px solid #1a4a7a; }
    table.data td { padding: 3pt 6pt; border: 1px solid #d8d8d8; font-size: 9pt; }
    table.data tr.global td { background: #eef3f8; font-weight: bold; }
    table.data td.r { text-align: right; }
    table.data td.c { text-align: center; }
    .two-cols { width: 100%; }
    .two-cols td { vertical-align: top; padding: 0 4pt; width: 50%; }
    .small { font-size: 8pt; color: #666; }
    .meses-grid th { font-size: 8pt; padding: 3pt 2pt; }
    .meses-grid td { font-size: 8pt; padding: 2pt 3pt; }
    .seccion-info { font-size: 8pt; color: #2d4d7a; margin: -2pt 0 4pt; font-style: italic; }
    /* Portada / resumen visual */
    .portada { border: 2pt solid #3a6aa3; border-radius: 4pt; padding: 12pt 14pt; margin: 6pt 0 14pt; }
    .portada h1 { font-size: 20pt; text-align: center; margin: 0 0 8pt; }
    .portada .stamp { text-align: center; color: #5a6b80; font-style: italic; font-size: 9pt; margin-bottom: 12pt; }
    .portada .block { margin-bottom: 10pt; }
    .portada .block-title { background: #2d4d7a; color: #fff; padding: 4pt 8pt; font-size: 10pt; font-weight: bold; border-radius: 3pt 3pt 0 0; letter-spacing: 0.5pt; }
    .portada .block table { width: 100%; border-collapse: collapse; }
    .portada .block td { border-bottom: 1px solid #e0e8f0; padding: 4pt 8pt; font-size: 9pt; vertical-align: top; }
    .portada .block td.k { background: #f4f7fb; color: #2d4d7a; font-weight: bold; width: 32%; }
    .portada .block td.v { color: #1a2d4a; }
    .portada .block td.warn { background: #fff8e1; color: #c45a2c; font-weight: bold; }
    .portada ul.toc { margin: 0; padding-left: 18pt; font-size: 9pt; }
    .portada ul.toc li { margin: 2pt 0; }
</style></head>
<body>

<!-- ─── PORTADA / RESUMEN DEL INFORME ─── -->
<div class="portada">
    <h1>INFORME OEE UNIFICADO</h1>
    <div class="stamp">Exportado el <?= date('d/m/Y H:i') ?></div>

    <div class="block">
        <div class="block-title">FILTROS PRINCIPALES</div>
        <table>
            <?php if ($periodoLabel): ?>
            <tr><td class="k">Rango efectivo</td><td class="v"><?= _h($fdesde) ?> → <?= _h($fhasta) ?> <em class="small">(filtro transitorio)</em></td></tr>
            <tr><td class="k">Filtrando por</td><td class="v warn"><?= _h($periodoLabel) ?></td></tr>
            <?php if ($rangoBaseDesde): ?>
            <tr><td class="k">Rango principal de pantalla</td><td class="v"><?= _h($rangoBaseDesde) ?> → <?= _h($rangoBaseHasta) ?></td></tr>
            <?php endif; ?>
            <?php else: ?>
            <tr><td class="k">Rango</td><td class="v"><?= _h($fdesde) ?> → <?= _h($fhasta) ?></td></tr>
            <?php endif; ?>
            <tr><td class="k">Turnos</td><td class="v"><?= _h($turnosLabel) ?></td></tr>
            <?php if ($exclLabel): ?>
            <tr><td class="k">Máquinas excluidas</td><td class="v"><?= _h($exclLabel) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="block">
        <div class="block-title">DRILL ACTIVO EN PANTALLA</div>
        <table>
            <?php if ($seccion || $metrica || $maqDrillLabel): ?>
            <?php if ($seccion): ?><tr><td class="k">Sección</td><td class="v"><?= _h($seccion) ?></td></tr><?php endif; ?>
            <?php if ($metrica): ?>
            <tr><td class="k">Métrica</td><td class="v"><?= _h($metricaLabel) ?></td></tr>
            <tr><td class="k">Segmentación</td><td class="v"><?= _h($porLabel) ?></td></tr>
            <?php endif; ?>
            <?php if ($maqDrillLabel): ?>
            <tr><td class="k"><?= _h($maqDrillTipo) ?> seleccionada</td><td class="v"><?= _h($maqDrillLabel) ?></td></tr>
            <?php endif; ?>
            <?php if ($maqMotivo): ?><tr><td class="k">Motivo (drill <?= _h(strtolower($maqDrillTipo ?: 'máquina')) ?>)</td><td class="v"><?= _h($maqMotivo) ?></td></tr><?php endif; ?>
            <?php if ($maqMotivoDia): ?><tr><td class="k">Día seleccionado</td><td class="v"><?= _h($maqMotivoDia) ?></td></tr><?php endif; ?>
            <?php if ($maqMotivoHora !== null && $maqMotivoHora !== ''): ?>
            <tr><td class="k">Hora seleccionada</td><td class="v"><?= _h(str_pad((string)$maqMotivoHora, 2, '0', STR_PAD_LEFT)) ?>:00</td></tr>
            <?php endif; ?>
            <?php if ($motivo): ?><tr><td class="k">Motivo (drill métrica)</td><td class="v"><?= _h($motivo) ?></td></tr><?php endif; ?>
            <?php if ($motivoMaqLabel): ?><tr><td class="k">Filtro máquina en motivo</td><td class="v"><?= _h($motivoMaqLabel) ?></td></tr><?php endif; ?>
            <?php else: ?>
            <tr><td class="k">—</td><td class="v"><em>Vista general (sin drill abierto)</em></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="block">
        <div class="block-title">CONTENIDO DEL INFORME</div>
        <ul class="toc">
            <li>OEE por Sección (global + VARILLAS + TROQUELADOS)</li>
            <li>Evolución OEE en el rango</li>
            <?php if ($maqDetalleLabel): ?><li>Detalle por máquina: <?= _h($maqDetalleLabel) ?></li><?php endif; ?>
            <?php if ($seccion): ?>
            <li>Desglose D/R/C/OEE de la sección <?= _h($seccion) ?></li>
            <?php if ($metrica): ?>
            <li>D/R/C/OEE por <?= _h($por === 'referencia' ? 'referencia' : 'máquina') ?> en <?= _h($seccion) ?></li>
            <li>Motivos Pareto de <?= _h($metricaLabel) ?></li>
            <?php if ($motivo): ?><li>Motivo seleccionado: <?= _h($motivo) ?> (por máquina + por hora)</li><?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($maqDrillLabel): ?>
            <li>Drill <?= _h(strtolower($maqDrillTipo)) ?>: <?= _h($maqDrillLabel) ?></li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<h1>OEE Unificado</h1>
<div class="filter-bar">
    <?php if ($periodoLabel): ?>
    <b>⚠ Filtrando por:</b> <?= _h($periodoLabel) ?>  ·
    <?php endif; ?>
    <b>Rango:</b> <?= _h($fdesde) ?> → <?= _h($fhasta) ?>  ·
    <b>Turnos:</b> <?= _h($turnosLabel) ?>
    <?php if ($seccion): ?> · <b>Sección:</b> <?= _h($seccion) ?><?php endif; ?>
    <?php if ($metrica): ?> · <b>Métrica:</b> <?= _h($metricaLabel) ?> · <b>Segmentación:</b> <?= _h($porLabel) ?><?php endif; ?>
    <?php if ($maqDrillLabel): ?> · <b><?= _h($maqDrillTipo) ?>:</b> <?= _h($maqDrillLabel) ?><?php endif; ?>
    <?php if ($maqMotivo): ?> · <b>Motivo drill:</b> <?= _h($maqMotivo) ?><?php endif; ?>
    <?php if ($maqDetalleLabel): ?> · <b>Máquina detalle:</b> <?= _h($maqDetalleLabel) ?><?php endif; ?>
    <?php if ($motivo): ?> · <b>Motivo:</b> <?= _h($motivo) ?><?php endif; ?>
    <?php if ($motivoMaqLabel): ?> · <b>Filtro máquina motivo:</b> <?= _h($motivoMaqLabel) ?><?php endif; ?>
    <?php if ($exclLabel): ?> · <b>Máquinas excluidas:</b> <?= _h($exclLabel) ?><?php endif; ?>
    <br><span class="small">Exportado: <?= date('d/m/Y H:i') ?></span>
</div>

<!-- ─── Sección 1: OEE por Sección ─── -->
<h2 class="first">OEE por Sección</h2>
<table class="data">
    <tr><th>Sección</th><th>Disponibilidad %</th><th>Rendimiento %</th><th>Calidad %</th><th>OEE %</th><th>M_Teo (seg)</th></tr>
    <?php $gDRC = _calcDRCPdf($globalAcc['M'],$globalAcc['MT'],$globalAcc['MOT'],$globalAcc['MOKT'],$globalAcc['PP'],$globalAcc['PC'],$globalAcc['PNP']); ?>
    <tr class="global"><td>GLOBAL</td><td class="r"><?= $gDRC[0] ?></td><td class="r"><?= $gDRC[1] ?></td><td class="r"><?= $gDRC[2] ?></td><td class="r"><?= $gDRC[3] ?></td><td class="r"><?= $gDRC[4] ?></td></tr>
    <?php foreach (['VARILLAS','TROQUELADOS'] as $sec):
        $a = $secAcc[$sec] ?? ['M'=>0,'MT'=>0,'MOT'=>0,'MOKT'=>0,'PP'=>0,'PC'=>0,'PNP'=>0];
        $drc = _calcDRCPdf($a['M'],$a['MT'],$a['MOT'],$a['MOKT'],$a['PP'],$a['PC'],$a['PNP']); ?>
    <tr><td><?= _h($sec) ?></td><td class="r"><?= $drc[0] ?></td><td class="r"><?= $drc[1] ?></td><td class="r"><?= $drc[2] ?></td><td class="r"><?= $drc[3] ?></td><td class="r"><?= $drc[4] ?></td></tr>
    <?php endforeach; ?>
</table>

<!-- ─── Sección 2: Evolución OEE ─── -->
<?php
    $dias = (new DateTime($fhasta))->diff(new DateTime($fdesde))->days;
    if ($dias <= 90)      $gran = 'DAY';
    elseif ($dias <= 365) $gran = 'WEEK';
    else                  $gran = 'MONTH';
    $bucket = $gran === 'DAY'
        ? "CAST(oee.TimePeriod AS DATE)"
        : ($gran === 'WEEK' ? "DATEADD(WEEK, DATEDIFF(WEEK, 0, oee.TimePeriod), 0)"
                            : "DATEADD(MONTH, DATEDIFF(MONTH, 0, oee.TimePeriod), 0)");
    $sqlEv = "
        SELECT $bucket AS bucket_start,
            SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS MOT,
            SUM(oee.M_OK_TEO) AS MOKT, SUM(oee.PPERF) AS PPERF,
            SUM(oee.PCALIDAD) AS PCALIDAD, SUM(oee.PNP) AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE $whereSQL
        GROUP BY $bucket
        ORDER BY $bucket
    ";
    $rowsEv = fetchAll('mapex', $sqlEv, array_merge([$fdesde, $fhasta], $params));
    $granLabel = ['DAY'=>'Diaria','WEEK'=>'Semanal','MONTH'=>'Mensual'][$gran];
    $meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
?>
<h2>Evolución OEE <span class="small">(<?= $granLabel ?>, <?= count($rowsEv) ?> periodos)</span></h2>
<table class="data">
    <tr><th>Periodo</th><th>Disponibilidad %</th><th>Rendimiento %</th><th>Calidad %</th><th>OEE %</th><th>Fecha inicio</th></tr>
    <?php foreach ($rowsEv as $r):
        $b = substr((string)$r['bucket_start'], 0, 10);
        $drc = _calcDRCPdf((float)$r['M'], 0, (float)$r['MOT'], (float)$r['MOKT'], (float)$r['PPERF'], (float)$r['PCALIDAD'], (float)$r['PNP']);
        $dt = new DateTime($b);
        if ($gran === 'DAY')       $label = $dt->format('d/m');
        elseif ($gran === 'WEEK')  $label = 'S' . $dt->format('W') . ' (' . $dt->format('d/m') . ')';
        else                       $label = $meses[(int)$dt->format('n') - 1] . ' ' . $dt->format('Y');
    ?>
    <tr><td><?= _h($label) ?></td><td class="r"><?= $drc[0] ?></td><td class="r"><?= $drc[1] ?></td><td class="r"><?= $drc[2] ?></td><td class="r"><?= $drc[3] ?></td><td class="c"><?= _h($b) ?></td></tr>
    <?php endforeach; ?>
</table>

<!-- ─── Sección 3: Detalle por máquina (OFs + Refs) ─── -->
<?php if ($detalleCodMaq && !in_array($detalleCodMaq, $excl, true)):
    $whereDet = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "mq.Cod_maquina = ?",
    ];
    $paramsDet = [$fdesde, $fhasta, $detalleCodMaq];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $whereDet[] = "ct.Cod_turno IN ($ph)";
        $paramsDet = array_merge($paramsDet, $turnos);
    }
    $whereDetSQL = implode(' AND ', $whereDet);

    $sqlOfs = "
        SELECT DISTINCT o.Cod_of AS cod_of
        FROM his_prod hp
        INNER JOIN his_fase    fa ON fa.Id_his_fase = hp.Id_his_fase
        INNER JOIN his_of      o  ON o.Id_his_of    = fa.Id_his_of
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE $whereDetSQL AND o.Cod_of IS NOT NULL AND o.Cod_of <> '--'
        ORDER BY o.Cod_of
    ";
    $rowsOfsDet = fetchAll('mapex', $sqlOfs, $paramsDet);

    $sqlRefs = "
        SELECT DISTINCT pr.Cod_producto AS cod_producto, pr.Desc_producto AS desc_producto
        FROM his_prod hp
        INNER JOIN his_fase     fa ON fa.Id_his_fase = hp.Id_his_fase
        INNER JOIN his_of       o  ON o.Id_his_of    = fa.Id_his_of
        INNER JOIN cfg_producto pr ON pr.Id_producto = o.Id_producto
        INNER JOIN cfg_maquina  mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno    ct ON ct.Id_turno    = hp.Id_turno
        WHERE $whereDetSQL AND pr.Cod_producto IS NOT NULL AND pr.Cod_producto <> '--'
        ORDER BY pr.Desc_producto
    ";
    $rowsRefsDet = fetchAll('mapex', $sqlRefs, $paramsDet);
?>
<h2>Detalle por máquina · <?= _h($maqDetalleLabel) ?></h2>
<table class="two-cols"><tr><td>
    <table class="data"><tr><th>OFs (<?= count($rowsOfsDet) ?>)</th></tr>
        <?php foreach ($rowsOfsDet as $o): ?><tr><td><?= _h($o['cod_of']) ?></td></tr><?php endforeach; ?>
        <?php if (empty($rowsOfsDet)): ?><tr><td><em>Sin OFs</em></td></tr><?php endif; ?>
    </table>
</td><td>
    <table class="data"><tr><th>Referencias (<?= count($rowsRefsDet) ?>)</th></tr>
        <?php foreach ($rowsRefsDet as $r): ?><tr><td><?= _h($r['desc_producto'] ?: $r['cod_producto']) ?></td></tr><?php endforeach; ?>
        <?php if (empty($rowsRefsDet)): ?><tr><td><em>Sin referencias</em></td></tr><?php endif; ?>
    </table>
</td></tr></table>
<?php endif; ?>

<!-- ─── Secciones 4-7: drill ─── -->
<?php if ($seccion && in_array($seccion, ['VARILLAS','TROQUELADOS'], true)):
    $a = $secAcc[$seccion] ?? ['M'=>0,'MT'=>0,'MOT'=>0,'MOKT'=>0,'PP'=>0,'PC'=>0,'PNP'=>0];
    $drcSec = _calcDRCPdf($a['M'],$a['MT'],$a['MOT'],$a['MOKT'],$a['PP'],$a['PC'],$a['PNP']);
?>
<h2><?= _h($seccion) ?> · Desglose D/R/C/OEE</h2>
<table class="data" style="width:60%">
    <tr><th>Métrica</th><th>%</th></tr>
    <tr><td>Disponibilidad</td><td class="r"><?= $drcSec[0] ?></td></tr>
    <tr><td>Rendimiento</td><td class="r"><?= $drcSec[1] ?></td></tr>
    <tr><td>Calidad</td><td class="r"><?= $drcSec[2] ?></td></tr>
    <tr class="global"><td>OEE</td><td class="r"><?= $drcSec[3] ?></td></tr>
</table>

<?php if ($metrica && in_array($metrica, ['disponibilidad','rendimiento','calidad','oee'], true)):
    $secMaqs = array_filter($maqData, fn($m) => $m['seccion'] === $seccion);
    $metIdx = ['disponibilidad'=>0,'rendimiento'=>1,'calidad'=>2,'oee'=>3][$metrica];
    usort($secMaqs, fn($a,$b) => $a['drc'][$metIdx] <=> $b['drc'][$metIdx]);
?>
<h2><?= _h($seccion) ?> · D/R/C/OEE por máquina</h2>
<table class="data">
    <tr><th>Máquina</th><th>Disponibilidad %</th><th>Rendimiento %</th><th>Calidad %</th><th>OEE %</th><th>M_Teo (seg)</th></tr>
    <?php foreach ($secMaqs as $m): ?>
    <tr><td><?= _h($m['maquina']) ?></td><td class="r"><?= $m['drc'][0] ?></td><td class="r"><?= $m['drc'][1] ?></td><td class="r"><?= $m['drc'][2] ?></td><td class="r"><?= $m['drc'][3] ?></td><td class="r"><?= $m['drc'][4] ?></td></tr>
    <?php endforeach; ?>
</table>

<?php
    require_once __DIR__ . '/oee_unificado_drill.php_motivos.php';
    $codMaqsSeccion = array_map(fn($m) => $m['cod_maquina'], $secMaqs);
    $motivos = [];
    if (in_array($metrica, ['disponibilidad','oee'], true)) {
        $motivos = _exportMotivosParos($fdesde, $fhasta, $turnos, $codMaqsSeccion, $codMaq);
    } elseif ($metrica === 'calidad') {
        $motivos = _exportMotivosCalidad($fdesde, $fhasta, $turnos, $codMaqsSeccion, $codMaq);
    } elseif ($metrica === 'rendimiento') {
        $motivos = _exportMotivosRendimiento($fdesde, $fhasta, $turnos, $codMaqsSeccion, $codMaq, $fdesde, $fhasta);
    }
    $isHoras = in_array($metrica, ['disponibilidad','oee','rendimiento'], true);
?>
<h2>Motivos de <?= _h($metricaLabel) ?> · <?= _h($seccion) ?></h2>
<table class="data">
    <tr><th>Motivo</th><th><?= $isHoras ? 'Horas' : 'Unidades' ?></th><th>%</th><th>% Acumulado</th></tr>
    <?php foreach ($motivos as $m): ?>
    <tr>
        <td><?= _h($m['motivo']) ?></td>
        <td class="r"><?= $isHoras ? ($m['horas'] ?? 0) : ($m['unidades'] ?? 0) ?></td>
        <td class="r"><?= $m['pct'] ?? 0 ?></td>
        <td class="r"><?= $m['pct_acum'] ?? 0 ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($motivos)): ?>
    <tr><td colspan="4"><em>Sin motivos en el rango</em></td></tr>
    <?php endif; ?>
</table>

<?php if ($motivo):
    require_once __DIR__ . '/oee_unificado_motivo_drill.php_data.php';
    $detalleMot = _exportMotivoDrillDetalle($fdesde, $fhasta, $turnos, $metrica, $codMaqsSeccion, $motivo);
    $porHora    = _exportMotivoDrillPorHora($fdesde, $fhasta, $turnos, $metrica, $codMaqsSeccion, $motivo, $motivoCodMaq);
?>
<h2>Motivo seleccionado: <?= _h($motivo) ?></h2>
<div class="seccion-info">Por máquina</div>
<table class="data">
    <tr><th>Máquina</th><th><?= $isHoras ? 'Horas' : 'Unidades' ?></th><th>%</th></tr>
    <?php foreach ($detalleMot as $d): ?>
    <tr><td><?= _h($d['maquina']) ?></td><td class="r"><?= $isHoras ? ($d['horas'] ?? 0) : ($d['unidades'] ?? 0) ?></td><td class="r"><?= $d['pct'] ?? 0 ?></td></tr>
    <?php endforeach; ?>
    <?php if (empty($detalleMot)): ?>
    <tr><td colspan="3"><em>Sin datos por máquina para este motivo</em></td></tr>
    <?php endif; ?>
</table>

<div class="seccion-info">Por hora del día<?= $motivoMaqLabel ? ' · solo ' . _h($motivoMaqLabel) : '' ?></div>
<?php if ($porHora && !empty($porHora['horas']) && !empty($porHora['maquinas'])): ?>
<table class="data meses-grid">
    <tr><th>Hora</th><?php foreach ($porHora['maquinas'] as $mq): ?><th><?= _h($mq['maquina']) ?></th><?php endforeach; ?></tr>
    <?php foreach ($porHora['horas'] as $hRow): ?>
    <tr>
        <td class="c"><?= str_pad((string)$hRow['h'], 2, '0', STR_PAD_LEFT) ?>:00</td>
        <?php foreach ($porHora['maquinas'] as $mq): ?>
        <td class="r"><?= $hRow[$mq['cod_maquina']] ?? 0 ?></td>
        <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
</table>
<?php else: ?>
<p><em>Sin desglose horario disponible</em></p>
<?php endif; ?>

<?php endif; // motivo ?>
<?php endif; // metrica ?>
<?php endif; // seccion ?>

</body>
</html>
    <?php
    $html = ob_get_clean();

    // ───── Render via mPDF ─────
    $mpdf = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => 'A4-L',         // landscape
        'margin_left'   => 10,
        'margin_right'  => 10,
        'margin_top'    => 10,
        'margin_bottom' => 12,
        'margin_header' => 0,
        'margin_footer' => 6,
        'tempDir'       => sys_get_temp_dir(),
    ]);
    $mpdf->SetTitle('OEE Unificado · ' . $fdesde . ' a ' . $fhasta);
    $mpdf->SetAuthor('KH Plan Attainment');
    $mpdf->SetFooter('{PAGENO} / {nbpg}');
    $mpdf->WriteHTML($html);

    $stamp = date('Ymd_His');
    $base = "OEE_Unificado_{$fdesde}_a_{$fhasta}_{$stamp}.pdf";
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
