<?php
/**
 * Export XLSX / PDF · Análisis Top (Disponibilidad).
 *
 * Genera un informe del top seleccionado (máquinas o motivos) reutilizando
 * las funciones del endpoint JSON oee_unificado_top_analisis.php.
 *
 * Parámetros:
 *   - fmt        : xlsx | pdf
 *   - mode       : maquinas | motivos
 *   - seccion    : VARILLAS | TROQUELADOS
 *   - fecha_desde, fecha_hasta : YYYY-MM-DD
 *   - top_n      : 1..20 (default 5)
 *   - turnos     : CSV (M,T,N)
 *   - excl       : CSV cod_maquina excluidas
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

// helpers.php fija Content-Type JSON; lo dejamos sin enviar nada hasta el final
// para evitar headers tempranos (jsonOk/jsonError no se usan aquí).

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

// Reutilizamos las funciones de cálculo del endpoint JSON. Sólo ejecutaremos
// las funciones; el try/catch global del otro archivo no se dispara porque
// utilizamos require_once de la sección de helpers… pero ese archivo ejecuta
// código al cargarse. Para evitarlo, replicamos aquí las helpers mínimas que
// necesitamos en lugar de hacer require del endpoint JSON.

function _texSeccion(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

function _texResolverMaqs(string $fdesde, string $fhasta, array $turnos, string $seccion, array $excl): array
{
    $where = [
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
    $sql = "
        SELECT oee.WorkGroup AS cod_maquina, mq.Desc_maquina AS maquina
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE " . implode(' AND ', $where) . "
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
    ";
    $rows = fetchAll('mapex', $sql, array_merge([$fdesde, $fhasta], $params));
    $out = [];
    foreach ($rows as $r) {
        if (_texSeccion($r['maquina']) === $seccion) {
            $out[$r['cod_maquina']] = $r['maquina'] ?: $r['cod_maquina'];
        }
    }
    return $out;
}

function _texWherePar(string $fdesde, string $fhasta, array $turnos, array $codMaqs, array &$params): array
{
    $where = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "hpp.Fecha_fin IS NOT NULL",
    ];
    $params = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    $codMaqs = array_values($codMaqs);
    $ph = implode(',', array_fill(0, count($codMaqs), '?'));
    $where[] = "mq.Cod_maquina IN ($ph)";
    $params = array_merge($params, $codMaqs);
    return $where;
}

function _texTopMaquinas(string $fdesde, string $fhasta, array $turnos, array $codMaqs, int $topN): array
{
    if (empty($codMaqs)) return [];
    $params = [];
    $where = _texWherePar($fdesde, $fhasta, $turnos, $codMaqs, $params);
    $sql = "
        SELECT mq.Cod_maquina AS cod_maquina, mq.Desc_maquina AS maquina,
               SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY mq.Cod_maquina, mq.Desc_maquina
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY segundos DESC
    ";
    $rows = fetchAll('mapex', $sql, $params);
    $rows = array_slice($rows, 0, $topN);
    $total = 0;
    foreach ($rows as $r) $total += (int)$r['segundos'];
    $out = [];
    foreach ($rows as $r) {
        $seg = (int)$r['segundos'];
        $out[] = [
            'cod_maquina' => $r['cod_maquina'],
            'etiqueta'    => $r['maquina'] ?: $r['cod_maquina'],
            'horas'       => round($seg / 3600, 2),
            'pct'         => $total > 0 ? round($seg / $total * 100, 2) : 0,
        ];
    }
    return $out;
}

function _texDetalleFechaMaquina(string $fdesde, string $fhasta, array $turnos, array $codMaqs, string $codMaqFiltro): array
{
    if (empty($codMaqs) || $codMaqFiltro === '') return [];
    if (!in_array($codMaqFiltro, $codMaqs, true)) return [];
    $params = [];
    $where = _texWherePar($fdesde, $fhasta, $turnos, [$codMaqFiltro], $params);
    $sql = "
        SELECT CAST(hp.Dia_productivo AS DATE) AS fecha,
               SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY CAST(hp.Dia_productivo AS DATE)
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY fecha
    ";
    $rows = fetchAll('mapex', $sql, $params);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'fecha' => substr((string)$r['fecha'], 0, 10),
            'horas' => round(((int)$r['segundos']) / 3600, 2),
        ];
    }
    return $out;
}

function _texDetalleFechaMotivo(string $fdesde, string $fhasta, array $turnos, array $codMaqs, string $motivo): array
{
    if (empty($codMaqs) || $motivo === '') return [];
    $params = [];
    $where = _texWherePar($fdesde, $fhasta, $turnos, $codMaqs, $params);
    $where[] = "cp.Desc_paro = ?";
    $params[] = $motivo;
    $sql = "
        SELECT CAST(hp.Dia_productivo AS DATE) AS fecha,
               SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY CAST(hp.Dia_productivo AS DATE)
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY fecha
    ";
    $rows = fetchAll('mapex', $sql, $params);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'fecha' => substr((string)$r['fecha'], 0, 10),
            'horas' => round(((int)$r['segundos']) / 3600, 2),
        ];
    }
    return $out;
}

function _texTopMotivos(string $fdesde, string $fhasta, array $turnos, array $codMaqs, int $topN): array
{
    if (empty($codMaqs)) return [];
    $params = [];
    $where = _texWherePar($fdesde, $fhasta, $turnos, $codMaqs, $params);
    $sql = "
        SELECT cp.Desc_paro AS motivo,
               SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY cp.Desc_paro
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY segundos DESC
    ";
    $rows = fetchAll('mapex', $sql, $params);
    $rows = array_slice($rows, 0, $topN);
    $total = 0;
    foreach ($rows as $r) $total += (int)$r['segundos'];
    $out = [];
    foreach ($rows as $r) {
        $seg = (int)$r['segundos'];
        $out[] = [
            'cod_maquina' => null,
            'etiqueta'    => (string)$r['motivo'],
            'horas'       => round($seg / 3600, 2),
            'pct'         => $total > 0 ? round($seg / $total * 100, 2) : 0,
        ];
    }
    return $out;
}

// ───── Lectura y validación de parámetros ─────
$fmt    = (string) getParam('fmt', 'xlsx');
$mode   = (string) getParam('mode', 'maquinas');
$seccion = (string) getParam('seccion');
$fdesde = (string) getParam('fecha_desde');
$fhasta = (string) getParam('fecha_hasta');
$topN   = (int) (getParam('top_n', 5) ?: 5);
if ($topN < 1)  $topN = 1;
if ($topN > 20) $topN = 20;

if (!in_array($fmt,  ['xlsx', 'pdf'],            true)) { http_response_code(400); exit('fmt inválido'); }
if (!in_array($mode, ['maquinas', 'motivos'],    true)) { http_response_code(400); exit('mode inválido'); }
if (!in_array($seccion, ['VARILLAS','TROQUELADOS'], true)) { http_response_code(400); exit('seccion inválida'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) { http_response_code(400); exit('fecha_desde inválida'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) { http_response_code(400); exit('fecha_hasta inválida'); }
if ($fdesde > $fhasta) { http_response_code(400); exit('fecha_desde posterior a fecha_hasta'); }

$turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));
$excl   = getListParam('excl');

try {
    $maqMap  = _texResolverMaqs($fdesde, $fhasta, $turnos, $seccion, $excl);
    $codMaqs = array_keys($maqMap);

    $rows = $mode === 'maquinas'
        ? _texTopMaquinas($fdesde, $fhasta, $turnos, $codMaqs, $topN)
        : _texTopMotivos ($fdesde, $fhasta, $turnos, $codMaqs, $topN);

    // Histograma por fecha de cada item del top, ya ordenado (mayor → menor).
    $histogramas = [];
    foreach ($rows as $r) {
        $fechas = $mode === 'maquinas'
            ? _texDetalleFechaMaquina($fdesde, $fhasta, $turnos, $codMaqs, (string)($r['cod_maquina'] ?? ''))
            : _texDetalleFechaMotivo ($fdesde, $fhasta, $turnos, $codMaqs, (string)$r['etiqueta']);
        $histogramas[] = ['item' => $r, 'fechas' => $fechas];
    }

    $titulo = $mode === 'maquinas'
        ? "Top {$topN} máquinas — Disponibilidad"
        : "Top {$topN} motivos — Disponibilidad";
    $columnaEt = $mode === 'maquinas' ? 'Máquina' : 'Motivo de paro';

    $fname = sprintf('top_%s_%s_%s_%s.%s',
        $mode, strtolower($seccion), $fdesde, $fhasta,
        $fmt === 'xlsx' ? 'xlsx' : 'pdf'
    );

    if ($fmt === 'xlsx') {
        // Desactiva headers JSON que pueda haber fijado helpers.php
        if (!headers_sent()) {
            header_remove('Content-Type');
            header_remove('Cache-Control');
        }

        $ss    = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Top');

        // Cabecera
        $sheet->setCellValue([1, 1], $titulo);
        $sheet->mergeCells([1, 1, 4, 1]);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF1A2D4A');
        $sheet->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $turnosTxt = !empty($turnos) ? implode(',', $turnos) : 'TODOS';
        $sheet->setCellValue([1, 2], "Sección: $seccion · Rango: $fdesde a $fhasta · Turnos: $turnosTxt · Top $topN");
        $sheet->mergeCells([1, 2, 4, 2]);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Encabezado de tabla
        $sheet->setCellValue([1, 4], '#');
        $sheet->setCellValue([2, 4], $columnaEt);
        $sheet->setCellValue([3, 4], 'Horas paro');
        $sheet->setCellValue([4, 4], '% del top');
        $sheet->getStyle('A4:D4')->getFont()->setBold(true);
        $sheet->getStyle('A4:D4')->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE6ECF3');
        $sheet->getStyle('A4:D4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = 5;
        foreach ($rows as $i => $r) {
            $sheet->setCellValue([1, $row], $i + 1);
            $sheet->setCellValue([2, $row], $r['etiqueta']);
            $sheet->setCellValue([3, $row], $r['horas']);
            $sheet->setCellValue([4, $row], $r['pct']);
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('0.00" h"');
            $sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode('0.0"%"');
            $row++;
        }
        if (empty($rows)) {
            $sheet->setCellValue([1, $row], 'Sin paros en el rango seleccionado.');
            $sheet->mergeCells([1, $row, 4, $row]);
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        } else {
            $last = $row - 1;
            $sheet->getStyle("A4:D{$last}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setARGB('FFB0BEC9');
        }

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(48);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(12);

        // ───── Histogramas por item (orden mayor → menor) ─────
        $row += 2;
        foreach ($histogramas as $idx => $h) {
            $it     = $h['item'];
            $fechas = $h['fechas'];
            $rank   = $idx + 1;
            $etq    = (string)$it['etiqueta'];
            $totH   = (float)$it['horas'];

            // Subtítulo "#N · etiqueta · X.XX h"
            $sheet->setCellValue([1, $row], "#{$rank} · {$etq} · " . number_format($totH, 2, ',', '.') . ' h');
            $sheet->mergeCells([1, $row, 4, $row]);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF8C181A');
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setIndent(1);
            $row++;

            if (empty($fechas)) {
                $sheet->setCellValue([1, $row], 'Sin paros en el rango para este item.');
                $sheet->mergeCells([1, $row, 4, $row]);
                $sheet->getStyle("A{$row}")->getFont()->setItalic(true);
                $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $row += 2;
                continue;
            }

            // Encabezado de histograma
            $sheet->setCellValue([1, $row], 'Fecha');
            $sheet->setCellValue([2, $row], 'Horas paro');
            $sheet->setCellValue([3, $row], 'Histograma');
            $sheet->mergeCells([3, $row, 4, $row]);
            $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFE6ECF3');
            $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $hHead = $row;
            $row++;

            // Máximo de horas en ese histograma para escalar la barra (▓)
            $maxH = 0.0;
            foreach ($fechas as $f) $maxH = max($maxH, (float)$f['horas']);
            $hStart = $row;
            foreach ($fechas as $f) {
                $h2 = (float)$f['horas'];
                $bars = $maxH > 0 ? (int) round(($h2 / $maxH) * 40) : 0;
                $sheet->setCellValue([1, $row], $f['fecha']);
                $sheet->setCellValue([2, $row], $h2);
                $sheet->setCellValue([3, $row], str_repeat('█', max(1, $bars)));
                $sheet->mergeCells([3, $row, 4, $row]);
                $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('0.00" h"');
                $sheet->getStyle("C{$row}")->getFont()->getColor()->setARGB('FF8C181A');
                $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $row++;
            }
            $hEnd = $row - 1;
            $sheet->getStyle("A{$hHead}:D{$hEnd}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setARGB('FFB0BEC9');
            $row += 1; // gap antes del siguiente item
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Cache-Control: max-age=0');
        IOFactory::createWriter($ss, 'Xlsx')->save('php://output');
        exit;
    }

    // PDF (mPDF, A4 vertical)
    if (!headers_sent()) {
        header_remove('Content-Type');
        header_remove('Cache-Control');
    }
    $turnosTxt = !empty($turnos) ? implode(',', $turnos) : 'TODOS';
    $html = '<html><head><style>
        body { font-family: Arial, sans-serif; font-size: 11px; color:#1a2d4a; }
        h1   { background:#1a2d4a; color:#fff; padding:8px 12px; margin:0 0 6px 0; font-size:16px; }
        h2   { background:#8c181a; color:#fff; padding:5px 10px; margin:14px 0 4px 0; font-size:12px; page-break-after: avoid; }
        .meta { color:#2d4d7a; margin-bottom:10px; font-size:11px; }
        table { width:100%; border-collapse: collapse; }
        th, td { border:1px solid #b0bec9; padding:5px 8px; }
        th { background:#e6ecf3; font-weight:bold; text-align:center; }
        td.num { text-align:right; font-variant-numeric: tabular-nums; }
        td.rank { text-align:center; font-weight:bold; color:#8c181a; }
        .empty-row { text-align:center; font-style:italic; color:#6b7d99; }
        .bar-wrap { width:100%; height:14px; background:#eef2f7; border:1px solid #d5dfe8; padding:0; }
        .bar-fill { height:14px; background:#8c181a; }
        .hist-section { page-break-inside: avoid; margin-bottom: 10px; }
    </style></head><body>';
    $html .= '<h1>' . htmlspecialchars($titulo, ENT_QUOTES) . '</h1>';
    $html .= '<div class="meta">Sección: <strong>' . htmlspecialchars($seccion, ENT_QUOTES)
        . '</strong> · Rango: ' . htmlspecialchars($fdesde, ENT_QUOTES) . ' a '
        . htmlspecialchars($fhasta, ENT_QUOTES)
        . ' · Turnos: ' . htmlspecialchars($turnosTxt, ENT_QUOTES)
        . ' · Top ' . (int)$topN . '</div>';
    $html .= '<table><thead><tr><th>#</th><th>' . htmlspecialchars($columnaEt, ENT_QUOTES)
        . '</th><th>Horas paro</th><th>% del top</th></tr></thead><tbody>';
    if (empty($rows)) {
        $html .= '<tr><td colspan="4" style="text-align:center;font-style:italic">Sin paros en el rango seleccionado.</td></tr>';
    } else {
        foreach ($rows as $i => $r) {
            $html .= '<tr>'
                . '<td class="rank">' . ($i + 1) . '</td>'
                . '<td>' . htmlspecialchars((string)$r['etiqueta'], ENT_QUOTES) . '</td>'
                . '<td class="num">' . number_format($r['horas'], 2, ',', '.') . ' h</td>'
                . '<td class="num">' . number_format($r['pct'],   1, ',', '.') . ' %</td>'
                . '</tr>';
        }
    }
    $html .= '</tbody></table>';

    // ───── Histogramas por item (orden mayor → menor) ─────
    foreach ($histogramas as $idx => $h) {
        $it     = $h['item'];
        $fechas = $h['fechas'];
        $rank   = $idx + 1;
        $etq    = (string)$it['etiqueta'];
        $totH   = (float)$it['horas'];

        $html .= '<div class="hist-section">';
        $html .= '<h2>#' . $rank . ' · ' . htmlspecialchars($etq, ENT_QUOTES)
            . ' · ' . number_format($totH, 2, ',', '.') . ' h</h2>';

        if (empty($fechas)) {
            $html .= '<div class="empty-row">Sin paros en el rango para este item.</div>';
            $html .= '</div>';
            continue;
        }

        // Máximo del histograma para escalar la barra
        $maxH = 0.0;
        foreach ($fechas as $f) $maxH = max($maxH, (float)$f['horas']);

        $html .= '<table><thead><tr>'
            . '<th style="width:20%">Fecha</th>'
            . '<th style="width:18%">Horas</th>'
            . '<th>Histograma</th>'
            . '</tr></thead><tbody>';
        foreach ($fechas as $f) {
            $h2  = (float)$f['horas'];
            $pct = $maxH > 0 ? max(1, (int) round($h2 / $maxH * 100)) : 0;
            $html .= '<tr>'
                . '<td style="text-align:center">' . htmlspecialchars((string)$f['fecha'], ENT_QUOTES) . '</td>'
                . '<td class="num">' . number_format($h2, 2, ',', '.') . ' h</td>'
                . '<td><div class="bar-wrap"><div class="bar-fill" style="width:' . $pct . '%"></div></div></td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '</div>';
    }

    $html .= '</body></html>';

    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
    $mpdf->SetTitle($titulo);
    $mpdf->WriteHTML($html);
    $mpdf->Output($fname, \Mpdf\Output\Destination::DOWNLOAD);
    exit;

} catch (Throwable $e) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo 'Error: ' . $e->getMessage();
    exit;
}
