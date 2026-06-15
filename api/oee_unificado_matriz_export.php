<?php
/**
 * Export "Matriz" de OEE Unificado a XLSX (+ modo JSON para el popup).
 *
 * Tabla cruzada:
 *   - Columnas  = motivos de paro (Desc_paro) presentes en el filtro.
 *   - Filas     = cada MÁQUINA y, debajo, sus REFERENCIAS (las que causaron paro).
 *   - Celdas    = horas de paro de ese motivo atribuidas a esa referencia/máquina.
 *   - Por máquina Y por referencia: % Disponibilidad / Rendimiento / Calidad.
 *   - Por referencia: nomenclatura SAGE (Articulos.ReferenciaEdi_) y fecha/hora
 *     de inicio y fin de fabricación (his_prod).
 *   - Totales: fila TOTAL por motivo ARRIBA (bajo la cabecera) y columna TOTAL
 *     paro a la IZQUIERDA (junto a la columna de referencias).
 *
 * Atribución de paros al producto: his_prod_paro → his_fase → his_of → cfg_producto.
 * Las referencias se identifican por Cod_producto (clave para unir OEE/SAGE/fechas).
 *
 * Parámetros: fecha_desde, fecha_hasta (YYYY-MM-DD), turnos (CSV M,T,N),
 *             seccion (opt), excl (CSV opt), format=json (opt).
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
 *   D = M / (M + PNP)   R = (MOT + PC) / (M + PP + PC)   C = MOKT / (MOT + PC)
 */
function _matDRC(float $M, float $MOT, float $MOKT, float $PP, float $PC, float $PNP): array {
    $d = ($M + $PNP)      > 0 ? $M / ($M + $PNP) * 100               : 0.0;
    $r = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0.0;
    $c = ($MOT + $PC)     > 0 ? $MOKT / ($MOT + $PC) * 100            : 0.0;
    return ['disp' => round($d, 1), 'rend' => round($r, 1), 'cal' => round($c, 1)];
}

/** 'YYYY-MM-DD HH:MM:SS' → 'dd/mm/yy HH:MM' (vacío si no hay fecha). */
function _matFecha(?string $s): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    $ts = strtotime(substr($s, 0, 19));
    return $ts ? date('d/m/y H:i', $ts) : '';
}

try {
    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');
    $seccion = trim((string) (getParam('seccion') ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');

    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));
    $excl   = getListParam('excl');

    // ───── 1) Máquinas en ámbito + % D/R/C por máquina (F_his_ct) ─────
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

    $drcByName  = [];   // Desc_maquina => ['disp','rend','cal']
    $codByName  = [];   // Desc_maquina => Cod_maquina
    $codMaqs    = [];   // Cod_maquina en ámbito
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

    // ───── 2) % D/R/C por máquina + referencia (F_his_ct agrupado por producto) ─────
    $drcRef = [];   // [cod_maquina][cod_producto] => ['disp','rend','cal']
    if (!empty($codMaqs)) {
        $phM = implode(',', array_fill(0, count($codMaqs), '?'));
        $sqlOeeRef = "
            SELECT oee.WorkGroup AS cod_maquina, LTRIM(RTRIM(oee.Cod_producto)) AS cod_ref,
                   SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS MOT, SUM(oee.M_OK_TEO) AS MOKT,
                   SUM(oee.PPERF) AS PP, SUM(oee.PCALIDAD) AS PC, SUM(oee.PNP) AS PNP
            FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                          ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
            WHERE $whereSQL AND oee.WorkGroup IN ($phM)
            GROUP BY oee.WorkGroup, LTRIM(RTRIM(oee.Cod_producto))
            HAVING SUM(oee.M) + SUM(oee.PNP) > 0
        ";
        $pRef = array_merge([$fdesde, $fhasta], $params, $codMaqs);
        foreach (fetchAll('mapex', $sqlOeeRef, $pRef) as $r) {
            $cm = (string)$r['cod_maquina']; $cr = (string)$r['cod_ref'];
            if ($cr === '') continue;
            $drcRef[$cm][$cr] = _matDRC(
                (float)$r['M'], (float)$r['MOT'], (float)$r['MOKT'],
                (float)$r['PP'], (float)$r['PC'], (float)$r['PNP']
            );
        }
    }

    // ───── 3) Fechas de fabricación por máquina + referencia (his_prod) ─────
    $fab = [];   // [cod_maquina][cod_producto] => ['ini'=>'dd/mm/yy HH:MM','fin'=>...]
    if (!empty($codMaqs)) {
        $wf = ["CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?", "prod.Cod_producto IS NOT NULL"];
        $pf = [$fdesde, $fhasta];
        if (!empty($turnos)) {
            $ph = implode(',', array_fill(0, count($turnos), '?'));
            $wf[] = "ct.Cod_turno IN ($ph)";
            $pf = array_merge($pf, $turnos);
        }
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $wf[] = "mq.Cod_maquina IN ($ph)";
        $pf = array_merge($pf, $codMaqs);
        $sqlFab = "
            SELECT mq.Cod_maquina AS cod_maquina, LTRIM(RTRIM(prod.Cod_producto)) AS cod_ref,
                   MIN(hp.Fecha_ini) AS ini, MAX(ISNULL(hp.Fecha_fin, hp.Fecha_ini)) AS fin
            FROM his_prod hp
            INNER JOIN cfg_maquina  mq   ON mq.Id_maquina   = hp.Id_maquina
            INNER JOIN cfg_turno    ct   ON ct.Id_turno     = hp.Id_turno
            LEFT  JOIN his_fase     fa   ON fa.Id_his_fase  = hp.Id_his_fase
            LEFT  JOIN his_of       o    ON o.Id_his_of     = fa.Id_his_of
            LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
            WHERE " . implode(' AND ', $wf) . "
            GROUP BY mq.Cod_maquina, LTRIM(RTRIM(prod.Cod_producto))
        ";
        foreach (fetchAll('mapex', $sqlFab, $pf) as $r) {
            $cm = (string)$r['cod_maquina']; $cr = (string)$r['cod_ref'];
            if ($cr === '') continue;
            $fab[$cm][$cr] = ['ini' => _matFecha($r['ini']), 'fin' => _matFecha($r['fin'])];
        }
    }

    // ───── 4) Árbol de paros (motivo × máquina × referencia → horas) ─────
    // Referencias clavadas por Cod_producto; se guarda la descripción como etiqueta.
    $matrix    = [];   // [maquina][cod_ref] => [motivo => horas]
    $refTotal  = [];   // [maquina][cod_ref] => total horas
    $refLabel  = [];   // [maquina][cod_ref] => Desc_producto
    $motivoTot = [];   // motivo => total horas
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
                   LTRIM(RTRIM(prod.Cod_producto)) AS cod_ref,
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
            GROUP BY cp.Desc_paro, mq.Desc_maquina, LTRIM(RTRIM(prod.Cod_producto))
            HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
            ORDER BY cp.Desc_paro, mq.Desc_maquina, segundos DESC
        ";
        foreach (fetchAll('mapex', $sqlParo, $p) as $r) {
            $mot   = (string)$r['motivo'];
            $maq   = (string)($r['maquina'] ?: '—');
            $cr    = (string)$r['cod_ref'];
            $key   = $cr !== '' ? $cr : '__NOREF__';
            $desc  = (string)($r['desc_ref'] ?: ($cr !== '' ? $cr : '(sin referencia)'));
            $horas = round(((int)$r['segundos']) / 3600, 2);
            if ($horas <= 0) continue;

            $matrix[$maq][$key][$mot] = ($matrix[$maq][$key][$mot] ?? 0) + $horas;
            $refTotal[$maq][$key]     = ($refTotal[$maq][$key] ?? 0) + $horas;
            $refLabel[$maq][$key]     = $desc;
            $maqTotal[$maq][$mot]     = ($maqTotal[$maq][$mot] ?? 0) + $horas;
            $motivoTot[$mot]          = ($motivoTot[$mot] ?? 0) + $horas;
        }
    }

    // ───── 5) Nomenclatura SAGE (Articulos.ReferenciaEdi_) por Cod_producto ─────
    $sageNom = [];   // cod_producto => ReferenciaEdi_
    $allCods = [];
    foreach ($refTotal as $maq => $refs) {
        foreach (array_keys($refs) as $k) if ($k !== '__NOREF__') $allCods[$k] = true;
    }
    $allCods = array_keys($allCods);
    if (!empty($allCods)) {
        try {
            foreach (array_chunk($allCods, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $rows = fetchAll('sage',
                    "SELECT LTRIM(RTRIM(CodigoArticulo)) AS cod, ReferenciaEdi_ AS edi
                     FROM Articulos WHERE LTRIM(RTRIM(CodigoArticulo)) IN ($ph)", $chunk);
                foreach ($rows as $r) $sageNom[(string)$r['cod']] = trim((string)$r['edi']);
            }
        } catch (\Throwable $e) { /* SAGE no disponible: nomenclatura vacía, no rompe el export */ }
    }

    // Orden: motivos por total desc; máquinas alfabéticas; refs por total desc.
    arsort($motivoTot);
    $motivos = array_keys($motivoTot);
    $maquinas = array_keys($matrix);
    sort($maquinas, SORT_NATURAL | SORT_FLAG_CASE);

    // Helper: datos por referencia listos para JSON/XLSX (orden por total desc).
    $refsDe = function(string $maq) use ($refTotal, $refLabel, $matrix, $drcRef, $fab, $sageNom, $codByName) {
        $cm = $codByName[$maq] ?? '';
        $refs = $refTotal[$maq] ?? [];
        arsort($refs);
        $out = [];
        foreach (array_keys($refs) as $k) {
            $drc = ($k !== '__NOREF__') ? ($drcRef[$cm][$k] ?? null) : null;
            $fb  = ($k !== '__NOREF__') ? ($fab[$cm][$k] ?? null) : null;
            $out[] = [
                'cod_referencia'  => $k === '__NOREF__' ? '' : $k,
                'referencia'      => $refLabel[$maq][$k] ?? $k,
                'nomenclatura'    => $k !== '__NOREF__' ? ($sageNom[$k] ?? '') : '',
                'total'           => round($refTotal[$maq][$k], 2),
                'disponibilidad'  => $drc['disp'] ?? null,
                'rendimiento'     => $drc['rend'] ?? null,
                'calidad'         => $drc['cal']  ?? null,
                'fab_inicio'      => $fb['ini'] ?? '',
                'fab_fin'         => $fb['fin'] ?? '',
                'por_motivo'      => array_map(fn($v) => round($v, 2), $matrix[$maq][$k] ?? []),
            ];
        }
        return $out;
    };

    // ───── Modo JSON (para pintar la matriz en pantalla) ─────
    if (strtolower((string) (getParam('format') ?? '')) === 'json') {
        $maqOut = [];
        foreach ($maquinas as $maq) {
            $drc = $drcByName[$maq] ?? null;
            $maqOut[] = [
                'maquina'        => $maq,
                'cod_maquina'    => $codByName[$maq] ?? '',
                'disponibilidad' => $drc['disp'] ?? null,
                'rendimiento'    => $drc['rend'] ?? null,
                'calidad'        => $drc['cal']  ?? null,
                'total'          => round(array_sum($maqTotal[$maq] ?? []), 2),
                'por_motivo'     => array_map(fn($v) => round($v, 2), $maqTotal[$maq] ?? []),
                'referencias'    => $refsDe($maq),
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

    // ───── Construir el XLSX ─────
    // Columnas: 1 Máquina/Ref · 2 Nom.SAGE · 3 TOTAL(h) · 4 Disp · 5 Rend · 6 Cal
    //           · 7 Inicio fab · 8 Fin fab · 9.. motivos
    $book = new Spreadsheet();
    $book->getProperties()->setCreator('KH Plan Attainment')->setTitle('OEE Unificado · Matriz')
        ->setDescription("Matriz motivos × máquina/referencia $fdesde a $fhasta");
    $ws = $book->getActiveSheet();
    $ws->setTitle('Matriz');

    $colName = 1; $colSage = 2; $colTotal = 3; $colDisp = 4; $colRend = 5; $colCal = 6;
    $colIni = 7; $colFin = 8; $colMot0 = 9;
    $nMot = count($motivos);
    $lastCol = $colMot0 + $nMot - 1; if ($lastCol < $colFin) $lastCol = $colFin;
    $lastColLt = Coordinate::stringFromColumnIndex($lastCol);

    // Fila 1: título.  Fila 2: filtros.
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

    // Fila 4: cabecera.
    $hRow = 4;
    $ws->setCellValue([$colName, $hRow], 'Máquina / Referencia');
    $ws->setCellValue([$colSage, $hRow], 'Nomenclatura SAGE');
    $ws->setCellValue([$colTotal, $hRow], 'TOTAL paro (h)');
    $ws->setCellValue([$colDisp, $hRow], 'Disp. %');
    $ws->setCellValue([$colRend, $hRow], 'Rend. %');
    $ws->setCellValue([$colCal,  $hRow], 'Cal. %');
    $ws->setCellValue([$colIni,  $hRow], 'Inicio fab.');
    $ws->setCellValue([$colFin,  $hRow], 'Fin fab.');
    foreach ($motivos as $i => $mot) $ws->setCellValue([$colMot0 + $i, $hRow], $mot);
    $hdrRange = "A$hRow:{$lastColLt}$hRow";
    $ws->getStyle($hdrRange)->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));
    $ws->getStyle($hdrRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2D4D7A');
    $ws->getStyle($hdrRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $ws->getStyle($hdrRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('1A2D4A');
    $ws->getRowDimension($hRow)->setRowHeight(30);

    // Fila 5: TOTAL por motivo (horizontal, ARRIBA).
    $totRow = $hRow + 1;
    $ws->setCellValue([$colName, $totRow], 'TOTAL');
    $granTotal = 0.0;
    foreach ($motivos as $i => $mot) {
        $v = $motivoTot[$mot] ?? 0;
        if ($v > 0) { $ws->setCellValue([$colMot0 + $i, $totRow], round($v, 2)); $granTotal += $v; }
    }
    $ws->setCellValue([$colTotal, $totRow], round($granTotal, 2));
    $ws->getStyle("A$totRow:{$lastColLt}$totRow")->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));
    $ws->getStyle("A$totRow:{$lastColLt}$totRow")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2D4D7A');

    // Cuerpo.
    $row = $totRow + 1;
    foreach ($maquinas as $maq) {
        $maqRow = $row;
        $ws->setCellValue([$colName, $maqRow], $maq);
        $sumMaq = 0.0;
        foreach ($motivos as $i => $mot) {
            $v = $maqTotal[$maq][$mot] ?? 0;
            if ($v > 0) { $ws->setCellValue([$colMot0 + $i, $maqRow], round($v, 2)); $sumMaq += $v; }
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

        foreach ($refsDe($maq) as $rf) {
            $ws->setCellValue([$colName, $row], '    ' . $rf['referencia']);
            $ws->getStyle([$colName, $row])->getAlignment()->setIndent(1);
            $ws->setCellValue([$colSage, $row], $rf['nomenclatura']);
            $ws->setCellValue([$colTotal, $row], $rf['total']);
            if ($rf['disponibilidad'] !== null) $ws->setCellValue([$colDisp, $row], $rf['disponibilidad']);
            if ($rf['rendimiento'] !== null)    $ws->setCellValue([$colRend, $row], $rf['rendimiento']);
            if ($rf['calidad'] !== null)        $ws->setCellValue([$colCal,  $row], $rf['calidad']);
            $ws->setCellValueExplicit([$colIni, $row], $rf['fab_inicio'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $ws->setCellValueExplicit([$colFin, $row], $rf['fab_fin'],    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            foreach ($motivos as $i => $mot) {
                $v = $rf['por_motivo'][$mot] ?? 0;
                if ($v > 0) $ws->setCellValue([$colMot0 + $i, $row], round($v, 2));
            }
            $row++;
        }
    }
    $lastRow = $row - 1;

    // Formatos: horas (TOTAL + motivos), % (D/R/C), texto fechas, bordes y anchos.
    $totLt  = Coordinate::stringFromColumnIndex($colTotal);
    $motIniLt = Coordinate::stringFromColumnIndex($colMot0);
    $calLt  = Coordinate::stringFromColumnIndex($colCal);
    $dispLt = Coordinate::stringFromColumnIndex($colDisp);
    if ($lastRow >= $totRow) {
        // Columna TOTAL (izquierda) + columnas de motivos = horas.
        $ws->getStyle("{$totLt}$totRow:{$totLt}$lastRow")->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->getStyle("{$totLt}$totRow:{$totLt}$lastRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        if ($nMot > 0) {
            $ws->getStyle("{$motIniLt}$totRow:{$lastColLt}$lastRow")->getNumberFormat()->setFormatCode('#,##0.00');
            $ws->getStyle("{$motIniLt}$totRow:{$lastColLt}$lastRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $ws->getStyle("{$dispLt}$totRow:{$calLt}$lastRow")->getNumberFormat()->setFormatCode('0.0"%"');
        $ws->getStyle("{$dispLt}$totRow:{$calLt}$lastRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("A$hRow:{$lastColLt}$lastRow")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C9D4E3');
    }
    $ws->getColumnDimension('A')->setWidth(40);
    $ws->getColumnDimension(Coordinate::stringFromColumnIndex($colSage))->setWidth(16);
    $ws->getColumnDimension($totLt)->setWidth(13);
    foreach ([$colDisp,$colRend,$colCal] as $c) $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(9);
    $ws->getColumnDimension(Coordinate::stringFromColumnIndex($colIni))->setWidth(15);
    $ws->getColumnDimension(Coordinate::stringFromColumnIndex($colFin))->setWidth(15);
    for ($c = $colMot0; $c <= $lastCol; $c++) $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(12);
    // Inmoviliza bloque izquierdo (8 cols) + cabecera + fila TOTAL.
    $ws->freezePane(Coordinate::stringFromColumnIndex($colMot0) . ($totRow + 1));

    // ───── Descargar ─────
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
