<?php
/**
 * Export "Matriz" de OEE Unificado a XLSX.
 *
 * Tabla cruzada en una sola hoja:
 *   - Columnas  = motivos de paro (Desc_paro) presentes en el filtro.
 *   - Filas     = cada MÁQUINA y, debajo, las REFERENCIAS que causaron algún paro.
 *   - Celdas    = horas de paro de ese motivo atribuidas a esa referencia/máquina.
 *   - Extras    = subtotal por máquina, columna TOTAL paro, fila TOTAL por motivo,
 *                 columna % Disponibilidad por máquina (fórmula OEE: M/(M+PNP)).
 *
 * Atribución de paros al producto: his_prod_paro → his_fase → his_of → cfg_producto
 * (mismo criterio que _exportMotivoMaqRef de oee_unificado_export.php).
 *
 * Parámetros (mismos que el export OEE):
 *   fecha_desde, fecha_hasta (YYYY-MM-DD), turnos (CSV M,T,N), seccion (opt), excl (CSV opt).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

function _matSeccion(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

/**
 * Disponibilidad / Rendimiento / Calidad en % (misma fórmula que el export OEE).
 * Devuelve ['disp'=>..., 'rend'=>..., 'cal'=>...] redondeados a 1 decimal.
 *   D = M / (M + PNP)            R = (MOT + PC) / (M + PP + PC)     C = MOKT / (MOT + PC)
 */
function _matDRC(float $M, float $MOT, float $MOKT, float $PP, float $PC, float $PNP): array {
    $d = ($M + $PNP)      > 0 ? $M / ($M + $PNP) * 100               : 0.0;
    $r = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0.0;
    $c = ($MOT + $PC)     > 0 ? $MOKT / ($MOT + $PC) * 100            : 0.0;
    return ['disp' => round($d, 1), 'rend' => round($r, 1), 'cal' => round($c, 1)];
}

try {
    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');
    $seccion = trim((string) (getParam('seccion') ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');

    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));
    $excl   = getListParam('excl');

    // ───── 1) Máquinas en ámbito + % D/R/C (query OEE F_his_ct) ─────
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

    $sqlOee = "
        SELECT oee.WorkGroup AS cod_maquina, mq.Desc_maquina AS maquina,
               SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS MOT, SUM(oee.M_OK_TEO) AS MOKT,
               SUM(oee.PPERF) AS PP, SUM(oee.PCALIDAD) AS PC, SUM(oee.PNP) AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE $whereSQL
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
    ";
    $oeeRows = fetchAll('mapex', $sqlOee, array_merge([$fdesde, $fhasta], $params));

    $drcByName  = [];   // Desc_maquina => ['disp'=>,'rend'=>,'cal'=>]
    $codByName  = [];   // Desc_maquina => Cod_maquina (para el drill al histograma)
    $codMaqs    = [];   // Cod_maquina en ámbito (para el árbol de paros)
    $seccionLabel = $seccion !== '' ? $seccion : 'Todas';
    foreach ($oeeRows as $r) {
        $name = (string)($r['maquina'] ?: $r['cod_maquina']);
        $sec  = _matSeccion($r['maquina']);
        if ($seccion !== '' && $sec !== $seccion) continue;   // filtro de sección
        $drcByName[$name] = _matDRC(
            (float)$r['M'], (float)$r['MOT'], (float)$r['MOKT'],
            (float)$r['PP'], (float)$r['PC'], (float)$r['PNP']
        );
        $codByName[$name]  = (string)$r['cod_maquina'];
        $codMaqs[] = (string)$r['cod_maquina'];
    }
    $codMaqs = array_values(array_unique($codMaqs));

    // ───── 2) Árbol de paros (motivo × máquina × referencia → horas) ─────
    $matrix    = [];   // [maquina][ref] => [motivo => horas]
    $refTotal  = [];   // [maquina][ref] => total horas (todos los motivos)
    $motivoTot = [];   // motivo => total horas (todas las máquinas)
    $maqTotal  = [];   // [maquina][motivo] => subtotal horas

    if (!empty($codMaqs)) {
        $w = [
            "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
            "cp.Cod_paro <> 11",
            "hpp.Fecha_fin IS NOT NULL",
        ];
        $p = [$fdesde, $fhasta];
        if (!empty($turnos)) {
            $ph = implode(',', array_fill(0, count($turnos), '?'));
            $w[] = "ct.Cod_turno IN ($ph)";
            $p = array_merge($p, $turnos);
        }
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $w[] = "mq.Cod_maquina IN ($ph)";
        $p = array_merge($p, $codMaqs);

        $sqlParo = "
            SELECT cp.Desc_paro       AS motivo,
                   mq.Desc_maquina    AS maquina,
                   prod.Cod_producto  AS cod_ref,
                   MAX(prod.Desc_producto) AS desc_ref,
                   SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
            FROM his_prod_paro hpp
            INNER JOIN cfg_paro     cp   ON cp.Id_paro      = hpp.Id_paro
            INNER JOIN his_prod     hp   ON hp.Id_his_prod  = hpp.Id_his_prod
            INNER JOIN cfg_maquina  mq   ON mq.Id_maquina   = hp.Id_maquina
            INNER JOIN cfg_turno    ct   ON ct.Id_turno     = hp.Id_turno
            LEFT  JOIN his_fase     fa   ON fa.Id_his_fase  = hp.Id_his_fase
            LEFT  JOIN his_of       o    ON o.Id_his_of     = fa.Id_his_of
            LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
            WHERE " . implode(' AND ', $w) . "
            GROUP BY cp.Desc_paro, mq.Desc_maquina, prod.Cod_producto
            HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
            ORDER BY cp.Desc_paro, mq.Desc_maquina, segundos DESC
        ";
        foreach (fetchAll('mapex', $sqlParo, $p) as $r) {
            $mot   = (string)$r['motivo'];
            $maq   = (string)($r['maquina'] ?: '—');
            $ref   = (string)($r['desc_ref'] ?: ($r['cod_ref'] ?: '(sin referencia)'));
            $horas = round(((int)$r['segundos']) / 3600, 2);
            if ($horas <= 0) continue;

            $matrix[$maq][$ref][$mot] = ($matrix[$maq][$ref][$mot] ?? 0) + $horas;
            $refTotal[$maq][$ref]     = ($refTotal[$maq][$ref] ?? 0) + $horas;
            $maqTotal[$maq][$mot]     = ($maqTotal[$maq][$mot] ?? 0) + $horas;
            $motivoTot[$mot]          = ($motivoTot[$mot] ?? 0) + $horas;
        }
    }

    // Orden: motivos (columnas) por total desc; máquinas alfabéticas; refs por total desc.
    arsort($motivoTot);
    $motivos = array_keys($motivoTot);
    $maquinas = array_keys($matrix);
    sort($maquinas, SORT_NATURAL | SORT_FLAG_CASE);

    // ───── Modo JSON (para pintar la matriz en pantalla) ─────
    if (strtolower((string) (getParam('format') ?? '')) === 'json') {
        $maqOut = [];
        foreach ($maquinas as $maq) {
            $refs = $refTotal[$maq] ?? [];
            arsort($refs);
            $refOut = [];
            foreach (array_keys($refs) as $ref) {
                $refOut[] = [
                    'referencia' => $ref,
                    'total'      => round($refTotal[$maq][$ref], 2),
                    'por_motivo' => array_map(fn($v) => round($v, 2), $matrix[$maq][$ref] ?? []),
                ];
            }
            $drc = $drcByName[$maq] ?? null;
            $maqOut[] = [
                'maquina'        => $maq,
                'cod_maquina'    => $codByName[$maq] ?? '',
                'disponibilidad' => $drc['disp'] ?? null,
                'rendimiento'    => $drc['rend'] ?? null,
                'calidad'        => $drc['cal']  ?? null,
                'total'          => round(array_sum($maqTotal[$maq] ?? []), 2),
                'por_motivo'     => array_map(fn($v) => round($v, 2), $maqTotal[$maq] ?? []),
                'referencias'    => $refOut,
            ];
        }
        jsonOk([
            'motivos'         => $motivos,
            'maquinas'        => $maqOut,
            'total_por_motivo'=> array_map(fn($v) => round($v, 2), $motivoTot),
            'total_general'   => round(array_sum($motivoTot), 2),
            'filtros'         => [
                'fecha_desde' => $fdesde, 'fecha_hasta' => $fhasta,
                'turnos'      => $turnos, 'seccion' => $seccionLabel,
            ],
        ]);
        exit;
    }

    // ───── 3) Construir el XLSX ─────
    $book = new Spreadsheet();
    $book->getProperties()
        ->setCreator('KH Plan Attainment')
        ->setTitle('OEE Unificado · Matriz')
        ->setDescription("Matriz motivos × máquina/referencia $fdesde a $fhasta");
    $ws = $book->getActiveSheet();
    $ws->setTitle('Matriz');

    $nMot      = count($motivos);
    $colTotal  = 2 + $nMot;            // índice 1-based de la columna "TOTAL paro"
    $colDisp   = $colTotal + 1;        // columna "Disp. %"
    $colRend   = $colTotal + 2;        // columna "Rend. %"
    $colCal    = $colTotal + 3;        // columna "Cal. %"
    $lastColLt = Coordinate::stringFromColumnIndex($colCal);

    // Fila 1: título.  Fila 2: filtros aplicados.
    $ws->setCellValue('A1', 'OEE Unificado · Matriz motivos × máquina/referencia');
    $ws->mergeCells("A1:{$lastColLt}1");
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('2D4D7A');
    $ws->getStyle('A1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(1)->setRowHeight(22);

    $filtro = "Rango: $fdesde → $fhasta   ·   Turnos: " . (empty($turnos) ? 'Todos' : implode(', ', $turnos))
            . "   ·   Sección: $seccionLabel   ·   Valores: horas de paro";
    $ws->setCellValue('A2', $filtro);
    $ws->mergeCells("A2:{$lastColLt}2");
    $ws->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('555555');

    // Fila 4: cabecera de la tabla.
    $hRow = 4;
    $ws->setCellValue("A$hRow", 'Máquina / Referencia');
    foreach ($motivos as $i => $mot) {
        $ws->setCellValue([2 + $i, $hRow], $mot);
    }
    $ws->setCellValue([$colTotal, $hRow], 'TOTAL paro (h)');
    $ws->setCellValue([$colDisp,  $hRow], 'Disp. %');
    $ws->setCellValue([$colRend,  $hRow], 'Rend. %');
    $ws->setCellValue([$colCal,   $hRow], 'Cal. %');

    $hdrRange = "A$hRow:{$lastColLt}$hRow";
    $ws->getStyle($hdrRange)->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));
    $ws->getStyle($hdrRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2D4D7A');
    $ws->getStyle($hdrRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $ws->getStyle($hdrRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('1A2D4A');
    $ws->getRowDimension($hRow)->setRowHeight(30);

    // Cuerpo: por cada máquina, fila de máquina (subtotales) + filas de referencia.
    $row = $hRow + 1;
    $colTotalLt = Coordinate::stringFromColumnIndex($colTotal);
    $colMotIni  = Coordinate::stringFromColumnIndex(2);
    $colMotFin  = Coordinate::stringFromColumnIndex(1 + max($nMot, 1));

    foreach ($maquinas as $maq) {
        // Fila de máquina (negrita, sombreada).
        $maqRow = $row;
        $ws->setCellValue("A$maqRow", $maq);
        $sumMaq = 0.0;
        foreach ($motivos as $i => $mot) {
            $v = $maqTotal[$maq][$mot] ?? 0;
            if ($v > 0) { $ws->setCellValue([2 + $i, $maqRow], round($v, 2)); $sumMaq += $v; }
        }
        $ws->setCellValue([$colTotal, $maqRow], round($sumMaq, 2));
        if (isset($drcByName[$maq])) {
            $ws->setCellValue([$colDisp, $maqRow], $drcByName[$maq]['disp']);
            $ws->setCellValue([$colRend, $maqRow], $drcByName[$maq]['rend']);
            $ws->setCellValue([$colCal,  $maqRow], $drcByName[$maq]['cal']);
        }
        $ws->getStyle("A$maqRow:{$lastColLt}$maqRow")->getFont()->setBold(true);
        $ws->getStyle("A$maqRow:{$lastColLt}$maqRow")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F4');
        $row++;

        // Filas de referencia (indentadas), ordenadas por total desc.
        $refs = $refTotal[$maq] ?? [];
        arsort($refs);
        foreach (array_keys($refs) as $ref) {
            $ws->setCellValue("A$row", '    ' . $ref);
            $ws->getStyle("A$row")->getAlignment()->setIndent(1);
            foreach ($motivos as $i => $mot) {
                $v = $matrix[$maq][$ref][$mot] ?? 0;
                if ($v > 0) $ws->setCellValue([2 + $i, $row], round($v, 2));
            }
            $ws->setCellValue([$colTotal, $row], round($refTotal[$maq][$ref], 2));
            $row++;
        }
    }

    // Fila final TOTAL por motivo.
    $totRow = $row;
    $ws->setCellValue("A$totRow", 'TOTAL');
    $granTotal = 0.0;
    foreach ($motivos as $i => $mot) {
        $v = $motivoTot[$mot] ?? 0;
        if ($v > 0) { $ws->setCellValue([2 + $i, $totRow], round($v, 2)); $granTotal += $v; }
    }
    $ws->setCellValue([$colTotal, $totRow], round($granTotal, 2));
    $ws->getStyle("A$totRow:{$lastColLt}$totRow")->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));
    $ws->getStyle("A$totRow:{$lastColLt}$totRow")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2D4D7A');

    // Formato numérico de las celdas de horas + total, bordes y anchos.
    if ($row > $hRow + 1) {
        $numRange = "{$colMotIni}" . ($hRow + 1) . ":{$colTotalLt}$totRow";
        $ws->getStyle($numRange)->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->getStyle($numRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $pctIni = Coordinate::stringFromColumnIndex($colDisp);
        $pctRange = "{$pctIni}" . ($hRow + 1) . ":{$lastColLt}$totRow";
        $ws->getStyle($pctRange)->getNumberFormat()->setFormatCode('0.0"%"');
        $ws->getStyle($pctRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $allRange = "A$hRow:{$lastColLt}$totRow";
        $ws->getStyle($allRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C9D4E3');
    }
    $ws->getColumnDimension('A')->setWidth(38);
    for ($c = 2; $c <= $colCal; $c++) {
        $w = $c === $colTotal ? 14 : ($c >= $colDisp ? 10 : 12);
        $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth($w);
    }
    $ws->freezePane('B' . ($hRow + 1));

    // ───── 4) Descargar ─────
    $fname = "OEE_Matriz_{$fdesde}_a_{$fhasta}.xlsx";
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: max-age=0');
    $writer = IOFactory::createWriter($book, 'Xlsx');
    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
