<?php
/**
 * Export XLSX · Cronograma de motivos de disponibilidad por máquina.
 *
 * Devuelve un libro Excel con cuatro hojas:
 *   1. "Filtros"          — Resumen del filtro aplicado (rango, hora, turnos)
 *   2. "Eventos"          — Cada paro como una fila (máquina, motivo,
 *                            inicio, fin, duración) listo para tabla dinámica.
 *   3. "Por máquina"      — Pivot máquina × motivo en horas.
 *   4. "Por motivo"       — Pareto motivos (horas, %, % acumulado).
 *
 * Acepta los mismos parámetros GET que oee_unificado_hist_maquina.php:
 *   cod_maquina, desc_maquina, fecha_desde, fecha_hasta, turnos,
 *   hora_desde, hora_hasta.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

Auth::requireLoginApi();

set_error_handler(function ($s, $m, $f, $l) {
    if (!(error_reporting() & $s)) return false;
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "PHP: $m ($f:$l)";
    exit;
});

try {
    ini_set('memory_limit', '512M');

    // ── 1) Parseo de parámetros (misma lógica que el endpoint JSON) ──
    $cod  = trim((string)getParam('cod_maquina', ''));
    $desc = trim((string)getParam('desc_maquina', ''));
    $todasLasMaquinas = ($cod === '' && $desc === '');

    $fdesde = (string)getParam('fecha_desde', date('Y-m-d', strtotime('-30 days')));
    $fhasta = (string)getParam('fecha_hasta', date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) throw new \Exception('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) throw new \Exception('fecha_hasta inválida');

    $turnosStr = (string)getParam('turnos', '');
    $turnos = [];
    if ($turnosStr !== '') {
        foreach (explode(',', $turnosStr) as $t) {
            $t = strtoupper(trim($t));
            if (in_array($t, ['M','T','N'], true)) $turnos[] = $t;
        }
    }

    $horaDesde = (string)getParam('hora_desde', '');
    $horaHasta = (string)getParam('hora_hasta', '');
    $horaFiltroActivo = false;
    $horaCruzaMedia   = false;
    $horaIni = $horaFin = null;
    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaDesde)
     && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaHasta)
     && $horaDesde !== $horaHasta) {
        $horaFiltroActivo = true;
        $horaCruzaMedia   = ($horaDesde > $horaHasta);
        $horaIni = $horaDesde;
        $horaFin = $horaHasta;
    }

    // Resolución máquina (mismo bloque que el endpoint JSON)
    if (!$todasLasMaquinas) {
        if ($cod !== '' && $desc === '') {
            $r = fetchAll('mapex',
                "SELECT Desc_maquina FROM cfg_maquina WHERE Cod_maquina = ?", [$cod]);
            $desc = $r ? (string)($r[0]['Desc_maquina'] ?? $cod) : $cod;
        } elseif ($desc !== '' && $cod === '') {
            $r = fetchAll('mapex',
                "SELECT Cod_maquina FROM cfg_maquina WHERE Desc_maquina = ?", [$desc]);
            $cod = $r ? (string)($r[0]['Cod_maquina'] ?? $desc) : $desc;
        }
    }

    // ── 2) WHERE (misma lógica de filtros fecha+hora del JSON) ───────
    $where = [
        "cp.Cod_paro <> 11",
        "hpp.Fecha_fin IS NOT NULL",
    ];
    $params = [];

    if ($todasLasMaquinas) {
        $where[] = "mq.Cod_maquina NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')";
    } elseif ($cod !== '' && $desc !== '' && $cod !== $desc) {
        $where[] = "mq.Cod_maquina = ?";
        $params[] = $cod;
    } elseif ($cod !== '') {
        $where[] = "mq.Cod_maquina = ?";
        $params[] = $cod;
    } else {
        $where[] = "mq.Desc_maquina = ?";
        $params[] = $desc;
    }
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    if (!$horaFiltroActivo) {
        $where[] = "CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?";
        $params[] = $fdesde;
        $params[] = $fhasta;
    } elseif (!$horaCruzaMedia) {
        $where[] = "CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?";
        $where[] = "CONVERT(varchar(5), hpp.Fecha_ini, 108) >= ?";
        $where[] = "CONVERT(varchar(5), hpp.Fecha_ini, 108) < ?";
        $params[] = $fdesde;
        $params[] = $fhasta;
        $params[] = $horaIni;
        $params[] = $horaFin;
    } else {
        $fdesdePlus1 = date('Y-m-d', strtotime($fdesde . ' +1 day'));
        $fhastaPlus1 = date('Y-m-d', strtotime($fhasta . ' +1 day'));
        $where[] = "("
            . " (CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?"
            . "  AND CONVERT(varchar(5), hpp.Fecha_ini, 108) >= ?)"
            . " OR"
            . " (CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?"
            . "  AND CONVERT(varchar(5), hpp.Fecha_ini, 108) < ?)"
            . ")";
        $params[] = $fdesde;
        $params[] = $fhasta;
        $params[] = $horaIni;
        $params[] = $fdesdePlus1;
        $params[] = $fhastaPlus1;
        $params[] = $horaFin;
    }

    // Para el export quitamos el TOP 5000: si el usuario está exportando
    // es porque quiere los datos completos.
    $sql = "SELECT cp.Desc_paro    AS motivo,
                   mq.Cod_maquina  AS cod_maquina,
                   mq.Desc_maquina AS desc_maquina,
                   hpp.Fecha_ini   AS fecha_ini,
                   hpp.Fecha_fin   AS fecha_fin,
                   DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) AS segundos
            FROM his_prod_paro hpp
            INNER JOIN cfg_paro    cp  ON cp.Id_paro     = hpp.Id_paro
            INNER JOIN his_prod    hp  ON hp.Id_his_prod = hpp.Id_his_prod
            INNER JOIN cfg_maquina mq  ON mq.Id_maquina  = hp.Id_maquina
            INNER JOIN cfg_turno   ct  ON ct.Id_turno    = hp.Id_turno
            WHERE " . implode(' AND ', $where) . "
              AND DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) > 0
            ORDER BY mq.Desc_maquina, hpp.Fecha_ini";

    $rows = fetchAll('mapex', $sql, $params);

    // Agregados para hojas resumen
    $totMot  = [];
    $totMaq  = [];
    $matriz  = [];   // [motivo][nombreMaq] = segundos
    foreach ($rows as $r) {
        $mot  = (string)($r['motivo'] ?: '(sin nombre)');
        $nMaq = trim((string)($r['desc_maquina'] ?? '')) ?: (string)$r['cod_maquina'];
        $seg  = (int)$r['segundos'];
        $totMot[$mot]   = ($totMot[$mot]   ?? 0) + $seg;
        $totMaq[$nMaq]  = ($totMaq[$nMaq]  ?? 0) + $seg;
        if (!isset($matriz[$mot])) $matriz[$mot] = [];
        $matriz[$mot][$nMaq] = ($matriz[$mot][$nMaq] ?? 0) + $seg;
    }
    arsort($totMot);
    ksort($totMaq);
    $totGlobalSeg = array_sum($totMot);

    // ── 3) Construir libro Excel ─────────────────────────────────────
    $book = new Spreadsheet();
    $book->getProperties()
        ->setCreator('KH OEE Unificado')
        ->setTitle('Cronograma de motivos de disponibilidad')
        ->setDescription("Paros entre $fdesde y $fhasta");

    $borderThin = ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'C9D3DE']];

    $applyHeader = function ($ws, string $range): void {
        $ws->getStyle($range)->getFont()->setBold(true)
            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
        $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('2D4D7A');
        $ws->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    };

    // Helper formato de duración legible
    $fmtDur = function (int $seg): string {
        if ($seg < 60) return $seg . ' s';
        $m = intdiv($seg, 60); $s = $seg % 60;
        if ($m < 60) return $s ? "$m min $s s" : "$m min";
        $h = intdiv($m, 60); $mr = $m % 60;
        return $mr ? "$h h $mr min" : "$h h";
    };

    // ── Hoja 1: Filtros aplicados ────────────────────────────────────
    $wsF = $book->getActiveSheet();
    $wsF->setTitle('Filtros');
    $wsF->getColumnDimension('A')->setWidth(28);
    $wsF->getColumnDimension('B')->setWidth(70);

    $wsF->setCellValue('A1', 'CRONOGRAMA DE MOTIVOS DE DISPONIBILIDAD');
    $wsF->mergeCells('A1:B1');
    $wsF->getStyle('A1')->getFont()->setBold(true)->setSize(16)
        ->getColor()->setRGB('FFFFFF');
    $wsF->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('2D4D7A');
    $wsF->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $wsF->getRowDimension(1)->setRowHeight(32);

    $putFilt = function ($row, string $k, string $v) use ($wsF) {
        $wsF->setCellValue("A$row", $k);
        $wsF->setCellValue("B$row", $v);
        $wsF->getStyle("A$row")->getFont()->setBold(true)->getColor()->setRGB('2D4D7A');
        $wsF->getStyle("A$row")->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F4F7FB');
        $wsF->getStyle("A$row:B$row")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $wsF->getRowDimension($row)->setRowHeight(20);
    };

    $r = 3;
    $putFilt($r++, 'Generado',     date('d/m/Y H:i'));
    $putFilt($r++, 'Máquina',      $todasLasMaquinas
        ? 'Todas las máquinas (' . count($totMaq) . ')'
        : ($desc !== '' ? "$desc ($cod)" : $cod));
    $putFilt($r++, 'Rango fechas', date('d/m/Y', strtotime($fdesde))
                                . '  →  '
                                . date('d/m/Y', strtotime($fhasta)));
    if ($horaFiltroActivo) {
        $putFilt($r++, 'Franja horaria',
            "$horaIni  →  $horaFin" . ($horaCruzaMedia ? '  (cruza medianoche)' : ''));
    } else {
        $putFilt($r++, 'Franja horaria', 'Todo el día');
    }
    $putFilt($r++, 'Turnos',
        empty($turnos) ? 'M, T, N (todos)' : implode(', ', $turnos));
    $putFilt($r++, 'Paros encontrados', (string)count($rows));
    $putFilt($r++, 'Tiempo total parado',
        $fmtDur($totGlobalSeg) . '  (' . round($totGlobalSeg / 3600, 2) . ' h)');
    $putFilt($r++, 'Motivos distintos',  (string)count($totMot));
    $putFilt($r++, 'Máquinas afectadas', (string)count($totMaq));

    // ── Hoja 2: Eventos (uno por paro) ───────────────────────────────
    $wsE = $book->createSheet();
    $wsE->setTitle('Eventos');
    $headers = ['Máquina', 'Código', 'Motivo', 'Fecha inicio', 'Hora inicio',
                'Fecha fin',   'Hora fin',   'Duración (min)', 'Duración legible'];
    foreach ($headers as $i => $h) {
        $wsE->setCellValue([$i + 1, 1], $h);
    }
    $lastCol = Coordinate::stringFromColumnIndex(count($headers));
    $applyHeader($wsE, "A1:{$lastCol}1");
    $wsE->getRowDimension(1)->setRowHeight(22);

    $row = 2;
    foreach ($rows as $r0) {
        $nMaq = trim((string)($r0['desc_maquina'] ?? '')) ?: (string)$r0['cod_maquina'];
        $seg  = (int)$r0['segundos'];
        $ini  = substr((string)$r0['fecha_ini'], 0, 19);
        $fin  = substr((string)$r0['fecha_fin'], 0, 19);
        // Separamos fecha (dd/mm/yyyy) y hora (HH:MM:SS) para que el
        // usuario pueda filtrar y ordenar fácilmente en Excel.
        $fIniDate = date('d/m/Y', strtotime($ini));
        $fIniTime = substr($ini, 11, 8);
        $fFinDate = date('d/m/Y', strtotime($fin));
        $fFinTime = substr($fin, 11, 8);
        $wsE->setCellValue([1, $row], $nMaq);
        $wsE->setCellValue([2, $row], (string)$r0['cod_maquina']);
        $wsE->setCellValue([3, $row], (string)($r0['motivo'] ?: '(sin nombre)'));
        $wsE->setCellValue([4, $row], $fIniDate);
        $wsE->setCellValue([5, $row], $fIniTime);
        $wsE->setCellValue([6, $row], $fFinDate);
        $wsE->setCellValue([7, $row], $fFinTime);
        $wsE->setCellValue([8, $row], round($seg / 60, 2));
        $wsE->setCellValue([9, $row], $fmtDur($seg));
        $row++;
    }
    // Bordes finos + autosize + freeze + autofiltro
    if ($row > 2) {
        $wsE->getStyle("A2:{$lastCol}" . ($row - 1))->getBorders()
            ->getAllBorders()->applyFromArray($borderThin);
    }
    foreach (range(1, count($headers)) as $i) {
        $wsE->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
    $wsE->freezePane('A2');
    $wsE->setAutoFilter("A1:{$lastCol}" . max(1, $row - 1));

    // ── Hoja 3: Por máquina × motivo (pivot horas) ────────────────────
    $wsM = $book->createSheet();
    $wsM->setTitle('Por máquina');
    $maqsList = array_keys($totMaq);
    $motsList = array_keys($totMot);   // ya ordenados DESC por totMot

    // Cabecera: A=Máquina, luego una columna por motivo, luego Total
    $wsM->setCellValue('A1', 'Máquina');
    foreach ($motsList as $i => $mot) {
        $wsM->setCellValue([$i + 2, 1], $mot);
    }
    $colTotalM = count($motsList) + 2;
    $wsM->setCellValue([$colTotalM, 1], 'Total (h)');
    $lastColM = Coordinate::stringFromColumnIndex($colTotalM);
    $applyHeader($wsM, "A1:{$lastColM}1");
    $wsM->getRowDimension(1)->setRowHeight(36);
    $wsM->getStyle("A1:{$lastColM}1")->getAlignment()->setWrapText(true);

    $rowM = 2;
    foreach ($maqsList as $nMaq) {
        $wsM->setCellValue("A$rowM", $nMaq);
        foreach ($motsList as $i => $mot) {
            $segCell = $matriz[$mot][$nMaq] ?? 0;
            if ($segCell > 0) {
                $wsM->setCellValue([$i + 2, $rowM], round($segCell / 3600, 2));
            }
        }
        $wsM->setCellValue([$colTotalM, $rowM],
            round(($totMaq[$nMaq] ?? 0) / 3600, 2));
        $rowM++;
    }
    // Fila total por motivo
    $wsM->setCellValue("A$rowM", 'TOTAL (h)');
    foreach ($motsList as $i => $mot) {
        $wsM->setCellValue([$i + 2, $rowM],
            round(($totMot[$mot] ?? 0) / 3600, 2));
    }
    $wsM->setCellValue([$colTotalM, $rowM],
        round($totGlobalSeg / 3600, 2));
    $wsM->getStyle("A$rowM:{$lastColM}$rowM")->getFont()->setBold(true);
    $wsM->getStyle("A$rowM:{$lastColM}$rowM")->getFill()
        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF3F8');
    // Bordes finos
    if ($rowM > 2) {
        $wsM->getStyle("A2:{$lastColM}$rowM")->getBorders()
            ->getAllBorders()->applyFromArray($borderThin);
    }
    foreach (range(1, $colTotalM) as $i) {
        $wsM->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
    $wsM->getColumnDimension('A')->setWidth(30);
    $wsM->freezePane('B2');

    // ── Hoja 4: Por motivo (Pareto) ──────────────────────────────────
    $wsP = $book->createSheet();
    $wsP->setTitle('Por motivo');
    foreach (['Motivo', 'Horas', '% del total', '% acumulado',
              'Nº paros', 'Duración media (min)'] as $i => $h) {
        $wsP->setCellValue([$i + 1, 1], $h);
    }
    $applyHeader($wsP, 'A1:F1');
    $wsP->getRowDimension(1)->setRowHeight(22);

    // Conteo de paros por motivo
    $cntMot = [];
    foreach ($rows as $r0) {
        $mot = (string)($r0['motivo'] ?: '(sin nombre)');
        $cntMot[$mot] = ($cntMot[$mot] ?? 0) + 1;
    }

    $acumPct = 0;
    $rowP = 2;
    foreach ($totMot as $mot => $seg) {
        $h    = round($seg / 3600, 2);
        $pct  = $totGlobalSeg > 0 ? round($seg / $totGlobalSeg * 100, 2) : 0;
        $acumPct += $pct;
        $cnt  = $cntMot[$mot] ?? 0;
        $mediaMin = $cnt > 0 ? round(($seg / $cnt) / 60, 2) : 0;
        $wsP->setCellValue("A$rowP", $mot);
        $wsP->setCellValue("B$rowP", $h);
        $wsP->setCellValue("C$rowP", $pct);
        $wsP->setCellValue("D$rowP", min(100, round($acumPct, 2)));
        $wsP->setCellValue("E$rowP", $cnt);
        $wsP->setCellValue("F$rowP", $mediaMin);
        $rowP++;
    }
    if ($rowP > 2) {
        $wsP->getStyle("A2:F" . ($rowP - 1))->getBorders()
            ->getAllBorders()->applyFromArray($borderThin);
    }
    foreach (range(1, 6) as $i) {
        $wsP->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
    $wsP->getColumnDimension('A')->setWidth(36);
    $wsP->freezePane('A2');

    // Hoja activa al abrir = Eventos (lo más útil para trabajar encima)
    $book->setActiveSheetIndexByName('Eventos');

    // ── 4) Salida ────────────────────────────────────────────────────
    $tag = $todasLasMaquinas
        ? 'todas'
        : preg_replace('/[^A-Za-z0-9_-]/', '_', $cod !== '' ? $cod : $desc);
    $rangoTag = str_replace('-', '', $fdesde) . '-' . str_replace('-', '', $fhasta);
    $horaTag  = $horaFiltroActivo ? '_' . str_replace(':', '', $horaIni)
                                    . '-' . str_replace(':', '', $horaFin) : '';
    $fileName = "cronograma_paros_{$tag}_{$rangoTag}{$horaTag}_" . date('His') . ".xlsx";

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: no-store');
    $writer = IOFactory::createWriter($book, 'Xlsx');
    $writer->save('php://output');
    exit;

} catch (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Error al exportar: ' . $e->getMessage();
}
