<?php
/**
 * Export OEE Unificado a XLSX.
 *
 * Hojas (solo se generan las que tienen datos según el estado de drill):
 *   1) OEE por Sección                         (siempre)
 *   2) Evolución OEE                            (siempre)
 *   3) Detalle por máquina (OFs + Refs)         (si detalle_cod_maquina)
 *   4) Desglose D/R/C/OEE de sección            (si seccion)
 *   5) Métrica por máquina                       (si seccion + metrica)
 *   6) Motivos Pareto                            (si seccion + metrica)
 *   7) Motivo seleccionado (por máquina + hora)  (si seccion + metrica + motivo)
 *
 * Cada hoja arranca con dos filas de contexto (rango + filtros) y la fila 4
 * lleva la tabla de encabezados.
 *
 * Parámetros:
 *   fecha_desde, fecha_hasta, turnos (CSV), seccion (opt), metrica (opt),
 *   cod_maquina (opt — filtro de motivos por máquina dentro del drill),
 *   motivo (opt), motivo_cod_maquina (opt), detalle_cod_maquina (opt)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

function _secExport(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

function _calcDRCExport(float $M, float $MT, float $MOT, float $MOKT, float $PP, float $PC, float $PNP): array {
    $d   = ($M + $PNP)      > 0 ? $M / ($M + $PNP) * 100              : 0;
    $r   = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
    $c   = ($MOT + $PC)     > 0 ? $MOKT / ($MOT + $PC) * 100           : 0;
    $oee = $d * $r * $c / 10000;
    return [round($d, 2), round($r, 2), round($c, 2), round($oee, 2), (int)$MT];
}

function styleHeader($ws, string $range): void {
    $ws->getStyle($range)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
    $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('8C181A');
    $ws->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('6D1214');
}

function autoWidth($ws, int $colCount): void {
    for ($i = 1; $i <= $colCount; $i++) {
        $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
}

/**
 * Filas 1-2 con título y filtros aplicados. Devuelve la primera fila libre
 * (= 4) para que el caller continúe escribiendo headers + datos.
 *
 * Refleja TODO el estado visible en pantalla: rango efectivo + rango base si
 * había filtro de período transitorio, turnos, exclusiones, sección, métrica,
 * modo (Máquina/Referencia), drill por máquina/referencia + sub-drills.
 */
function writeFilterHeader($ws, array $ctx, string $sheetTitle, int $cols): int {
    $rightCol = Coordinate::stringFromColumnIndex(max($cols, 4));

    // Fila 1: título de la hoja
    $ws->setCellValue('A1', "OEE Unificado · $sheetTitle");
    $ws->mergeCells("A1:{$rightCol}1");
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('8C181A');
    $ws->getStyle('A1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(1)->setRowHeight(22);

    // Fila 2: filtros aplicados (texto continuo, bien estructurado)
    $parts   = [];
    // Si había filtro de período transitorio, lo destacamos primero
    if (!empty($ctx['periodoLabel'])) {
        $parts[] = '⚠ Filtrado por ' . $ctx['periodoLabel'];
        if (!empty($ctx['rangoBaseDesde'])) {
            $parts[] = 'Rango principal de pantalla: ' . $ctx['rangoBaseDesde'] . ' → ' . $ctx['rangoBaseHasta'];
        }
    } else {
        $parts[] = 'Rango: ' . $ctx['fdesde'] . ' → ' . $ctx['fhasta'];
    }
    $parts[] = 'Turnos: ' . $ctx['turnosLabel'];
    if (!empty($ctx['seccion'])) $parts[] = 'Sección: ' . $ctx['seccion'];
    if (!empty($ctx['metrica'])) $parts[] = 'Métrica: ' . $ctx['metricaLabel'];
    if (!empty($ctx['porLabel'])) $parts[] = 'Segmentación: ' . $ctx['porLabel'];
    if (!empty($ctx['maqDrillLabel'])) $parts[] = $ctx['maqDrillTipo'] . ': ' . $ctx['maqDrillLabel'];
    if (!empty($ctx['maqMotivo']))    $parts[] = 'Motivo (drill máquina): ' . $ctx['maqMotivo'];
    if (!empty($ctx['maqMotivoDia'])) $parts[] = 'Día (drill máquina): ' . $ctx['maqMotivoDia'];
    if ($ctx['maqMotivoHora'] !== null && $ctx['maqMotivoHora'] !== '') {
        $parts[] = 'Hora (drill máquina): ' . str_pad((string)$ctx['maqMotivoHora'], 2, '0', STR_PAD_LEFT) . ':00';
    }
    if (!empty($ctx['maqDetalleLabel'])) $parts[] = 'Máquina detalle: ' . $ctx['maqDetalleLabel'];
    if (!empty($ctx['motivo']))  $parts[] = 'Motivo (drill métrica): ' . $ctx['motivo'];
    if (!empty($ctx['motivoMaqLabel'])) $parts[] = 'Filtro máquina motivo: ' . $ctx['motivoMaqLabel'];
    if (!empty($ctx['exclLabel']))      $parts[] = 'Máquinas excluidas: ' . $ctx['exclLabel'];
    $parts[] = 'Exportado: ' . date('d/m/Y H:i');

    $ws->setCellValue('A2', implode('  ·  ', $parts));
    $ws->mergeCells("A2:{$rightCol}2");
    $ws->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('2D4D7A');
    $ws->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()
        ->setRGB(!empty($ctx['periodoLabel']) ? 'FFF3C4' : 'FDF5F5');
    $ws->getStyle('A2')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $ws->getRowDimension(2)->setRowHeight(!empty($ctx['periodoLabel']) ? 36 : 22);

    return 4;
}

/**
 * Genera la "Portada / Resumen del informe" como primera hoja del XLSX.
 *
 * Muestra, en bloques visuales, EXACTAMENTE el estado que el usuario tenía
 * en pantalla en el momento de exportar: filtros principales, filtro
 * transitorio si lo había, drill de sección/métrica/modo, drill por
 * máquina/referencia con sus sub-niveles, y la lista de hojas incluidas.
 */
function _renderPortada($ws, array $ctx): void {
    // Anchos generosos para que sea legible sin ajustar columnas
    $ws->getColumnDimension('A')->setWidth(28);
    $ws->getColumnDimension('B')->setWidth(70);

    // Título grande y vistoso
    $ws->setCellValue('A1', 'INFORME OEE UNIFICADO');
    $ws->mergeCells('A1:B1');
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(20)->getColor()->setRGB('FFFFFF');
    $ws->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('8C181A');
    $ws->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $ws->getRowDimension(1)->setRowHeight(36);

    $ws->setCellValue('A2', 'Exportado: ' . date('d/m/Y H:i'));
    $ws->mergeCells('A2:B2');
    $ws->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('5A6B80');
    $ws->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws->getRowDimension(2)->setRowHeight(18);

    $row = 4;

    // ─── Bloque "Filtros principales" ───
    _portadaSeccion($ws, $row, 'FILTROS PRINCIPALES', '2D4D7A');
    $row++;
    if (!empty($ctx['periodoLabel'])) {
        _portadaFila($ws, $row++, 'Rango efectivo', $ctx['fdesde'] . ' → ' . $ctx['fhasta'] . ' (filtro transitorio)');
        _portadaFila($ws, $row++, 'Filtrando por', $ctx['periodoLabel'], 'C45A2C');
        if (!empty($ctx['rangoBaseDesde'])) {
            _portadaFila($ws, $row++, 'Rango principal de pantalla',
                $ctx['rangoBaseDesde'] . ' → ' . $ctx['rangoBaseHasta']);
        }
    } else {
        _portadaFila($ws, $row++, 'Rango', $ctx['fdesde'] . ' → ' . $ctx['fhasta']);
    }
    _portadaFila($ws, $row++, 'Turnos', $ctx['turnosLabel']);
    if (!empty($ctx['exclLabel'])) {
        _portadaFila($ws, $row++, 'Máquinas excluidas', $ctx['exclLabel']);
    }
    $row++;

    // ─── Bloque "Drill activo en pantalla" ───
    $hayDrill = !empty($ctx['seccion']) || !empty($ctx['metrica']) || !empty($ctx['maqDrillLabel']);
    if ($hayDrill) {
        _portadaSeccion($ws, $row, 'DRILL ACTIVO EN PANTALLA', '2D4D7A');
        $row++;
        if (!empty($ctx['seccion'])) {
            _portadaFila($ws, $row++, 'Sección', $ctx['seccion']);
        }
        if (!empty($ctx['metrica'])) {
            _portadaFila($ws, $row++, 'Métrica', $ctx['metricaLabel']);
            _portadaFila($ws, $row++, 'Segmentación', $ctx['porLabel'] ?: 'Por Máquina');
        }
        if (!empty($ctx['maqDrillLabel'])) {
            _portadaFila($ws, $row++, $ctx['maqDrillTipo'] . ' seleccionada', $ctx['maqDrillLabel']);
        }
        if (!empty($ctx['maqMotivo'])) {
            _portadaFila($ws, $row++, 'Motivo (drill ' . strtolower($ctx['maqDrillTipo'] ?: 'máquina') . ')', $ctx['maqMotivo']);
        }
        if (!empty($ctx['maqMotivoDia'])) {
            _portadaFila($ws, $row++, 'Día seleccionado', $ctx['maqMotivoDia']);
        }
        if ($ctx['maqMotivoHora'] !== null && $ctx['maqMotivoHora'] !== '') {
            _portadaFila($ws, $row++, 'Hora seleccionada',
                str_pad((string)$ctx['maqMotivoHora'], 2, '0', STR_PAD_LEFT) . ':00');
        }
        if (!empty($ctx['motivo'])) {
            _portadaFila($ws, $row++, 'Motivo (drill métrica)', $ctx['motivo']);
        }
        if (!empty($ctx['motivoMaqLabel'])) {
            _portadaFila($ws, $row++, 'Filtro máquina en motivo', $ctx['motivoMaqLabel']);
        }
        $row++;
    } else {
        _portadaSeccion($ws, $row, 'DRILL ACTIVO EN PANTALLA', '2D4D7A');
        $row++;
        _portadaFila($ws, $row++, '—', 'Vista general (sin drill abierto)');
        $row++;
    }

    // ─── Bloque "Contenido del informe" ───
    _portadaSeccion($ws, $row, 'CONTENIDO DEL INFORME', '2D4D7A');
    $row++;
    $hojas = ['OEE por Sección', 'Evolución OEE'];
    if (!empty($ctx['maqDetalleLabel'])) $hojas[] = 'Detalle máquina';
    if (!empty($ctx['seccion'])) {
        $hojas[] = $ctx['seccion'] . ' D-R-C-OEE';
        if (!empty($ctx['metrica'])) {
            $hojas[] = $ctx['seccion'] . ' - ' . ($ctx['por'] === 'referencia' ? 'Referencias' : 'Máquinas');
            $hojas[] = 'Motivos ' . $ctx['metricaLabel'];
            if (!empty($ctx['motivo'])) $hojas[] = 'Motivo ' . $ctx['motivo'];
        }
    }
    if (!empty($ctx['maqDrillLabel'])) {
        $hojas[] = 'Drill ' . strtolower($ctx['maqDrillTipo']);
    }
    foreach ($hojas as $i => $h) {
        _portadaFila($ws, $row++, 'Hoja ' . ($i + 2), $h);
    }
}

/** Encabezado de bloque dentro de la portada. */
function _portadaSeccion($ws, int $row, string $titulo, string $rgb): void {
    $ws->setCellValue("A$row", $titulo);
    $ws->mergeCells("A$row:B$row");
    $ws->getStyle("A$row")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('FFFFFF');
    $ws->getStyle("A$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    $ws->getStyle("A$row")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)
        ->setIndent(1);
    $ws->getRowDimension($row)->setRowHeight(22);
}

/** Fila clave/valor dentro de un bloque. */
function _portadaFila($ws, int $row, string $key, string $value, string $accentRgb = ''): void {
    $ws->setCellValue("A$row", $key);
    $ws->setCellValue("B$row", $value);
    $ws->getStyle("A$row")->getFont()->setBold(true)->getColor()->setRGB('2D4D7A');
    $ws->getStyle("A$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F4F7FB');
    $ws->getStyle("A$row")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
    $ws->getStyle("B$row")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)
        ->setWrapText(true)->setIndent(1);
    if ($accentRgb !== '') {
        $ws->getStyle("B$row")->getFont()->setBold(true)->getColor()->setRGB($accentRgb);
    } else {
        $ws->getStyle("B$row")->getFont()->getColor()->setRGB('1A2D4A');
    }
    $ws->getStyle("A$row:B$row")->getBorders()->getBottom()
        ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E0E8F0');
    $ws->getRowDimension($row)->setRowHeight(20);
}

try {
    ini_set('memory_limit', '512M');

    $fdesde         = (string) getParam('fecha_desde');
    $fhasta         = (string) getParam('fecha_hasta');
    $seccion        = getParam('seccion');
    $metrica        = getParam('metrica');
    $codMaq         = getParam('cod_maquina');             // filtro de máquinas en motivos (drill métrica)
    $codRef         = getParam('cod_referencia');          // drill por referencia (modo Referencia)
    $por            = (string) (getParam('por') ?: 'maquina'); // 'maquina' | 'referencia'
    $maqNombre      = getParam('maq_nombre');              // nombre legible drill máquina/ref
    $maqMotivo      = getParam('maq_motivo');              // motivo activo dentro del drill máquina
    $maqMotivoDia   = getParam('maq_motivo_dia');          // día clicado dentro de motivo drill máquina
    $maqMotivoHora  = getParam('maq_motivo_hora');         // hora clicada
    $motivo         = getParam('motivo');
    $motivoCodMaq   = getParam('motivo_cod_maquina');      // filtro hora-por-máquina
    $detalleCodMaq  = getParam('detalle_cod_maquina');     // detalle por máquina (OFs/Refs)
    $periodoLabel   = getParam('periodo_label');           // texto legible del período transitorio
    $rangoBaseDesde = getParam('rango_base_desde');        // rango original cuando hay filtro transitorio
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

    // Resolver descripción de máquinas para etiquetas de filtros
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

    // Drill por máquina/referencia (la entidad en la que el usuario ha
    // profundizado tras clicar una barra del drill métrica)
    $maqDrillLabel = '';
    $maqDrillTipo  = '';
    $maqDrillCod   = '';
    if ($por === 'referencia' && $codRef) {
        $maqDrillTipo = 'Referencia';
        $maqDrillCod  = (string)$codRef;
        if ($maqNombre) {
            $maqDrillLabel = (string)$maqNombre;
        } else {
            $r = fetchAll('mapex', "SELECT TOP 1 Desc_producto FROM cfg_producto WHERE Cod_producto = ?", [(string)$codRef]);
            $maqDrillLabel = $r[0]['Desc_producto'] ?? (string)$codRef;
        }
    } elseif ($por !== 'referencia' && $codMaq) {
        $maqDrillTipo = 'Máquina';
        $maqDrillCod  = (string)$codMaq;
        if ($maqNombre) {
            $maqDrillLabel = (string)$maqNombre;
        } else {
            $r = fetchAll('mapex', "SELECT TOP 1 Desc_maquina FROM cfg_maquina WHERE Cod_maquina = ?", [(string)$codMaq]);
            $maqDrillLabel = $r[0]['Desc_maquina'] ?? (string)$codMaq;
        }
    }

    // Etiqueta legible de máquinas excluidas (para cabecera del export)
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

    $ctx = [
        'fdesde'           => $fdesde,
        'fhasta'           => $fhasta,
        'turnosLabel'      => $turnosLabel,
        'seccion'          => $seccion,
        'metrica'          => $metrica,
        'metricaLabel'     => $metricaLabel,
        'por'              => $por,
        'porLabel'         => $metrica ? $porLabel : '',
        'maqDetalleLabel'  => $maqDetalleLabel,
        'maqDrillTipo'     => $maqDrillTipo,
        'maqDrillLabel'    => $maqDrillLabel,
        'maqDrillCod'      => $maqDrillCod,
        'maqMotivo'        => $maqMotivo,
        'maqMotivoDia'     => $maqMotivoDia,
        'maqMotivoHora'    => $maqMotivoHora,
        'motivo'           => $motivo,
        'motivoMaqLabel'   => $motivoMaqLabel,
        'exclLabel'        => $exclLabel,
        'periodoLabel'     => $periodoLabel,
        'rangoBaseDesde'   => $rangoBaseDesde,
        'rangoBaseHasta'   => $rangoBaseHasta,
    ];

    // ───── Fetch base data (mismo SQL que oee_unificado.php) ─────
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
        $sec = _secExport($r['maquina']);

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
                'drc' => _calcDRCExport($M, $MT, $MOT, $MOKT, $PP, $PC, $PNP),
            ];
        }
    }

    // ───── Build Excel ─────
    $book = new Spreadsheet();
    $book->getProperties()
        ->setCreator('KH Plan Attainment')
        ->setTitle('OEE Unificado')
        ->setDescription("Exportación OEE $fdesde a $fhasta");

    // === Hoja 1: PORTADA / RESUMEN DEL INFORME ===
    // Indica al usuario, de un vistazo, exactamente qué información contiene
    // el archivo: filtros, secciones/drills activos en pantalla y lista de hojas.
    $wsCover = $book->getActiveSheet();
    $wsCover->setTitle('Portada');
    _renderPortada($wsCover, $ctx);

    // === Hoja 2: OEE por Sección ===
    $ws = $book->createSheet();
    $ws->setTitle('OEE por Sección');

    $row = writeFilterHeader($ws, $ctx, 'OEE por Sección', 6);

    $headers1 = ['Sección', 'Disponibilidad %', 'Rendimiento %', 'Calidad %', 'OEE %', 'M_Teo (seg)'];
    foreach ($headers1 as $i => $h) $ws->setCellValue([$i+1, $row], $h);
    styleHeader($ws, "A$row:F$row");

    $row++;
    $gDRC = _calcDRCExport($globalAcc['M'],$globalAcc['MT'],$globalAcc['MOT'],$globalAcc['MOKT'],$globalAcc['PP'],$globalAcc['PC'],$globalAcc['PNP']);
    $ws->setCellValue("A$row", 'GLOBAL');
    for ($i=0;$i<5;$i++) $ws->setCellValue([$i+2,$row], $gDRC[$i]);
    $ws->getStyle("A$row:F$row")->getFont()->setBold(true);
    $row++;

    foreach (['VARILLAS','TROQUELADOS'] as $sec) {
        if (!isset($secAcc[$sec])) {
            $ws->setCellValue("A$row", $sec);
            for ($i=1;$i<=5;$i++) $ws->setCellValue([$i+1,$row], 0);
        } else {
            $a = $secAcc[$sec];
            $drc = _calcDRCExport($a['M'],$a['MT'],$a['MOT'],$a['MOKT'],$a['PP'],$a['PC'],$a['PNP']);
            $ws->setCellValue("A$row", $sec);
            for ($i=0;$i<5;$i++) $ws->setCellValue([$i+2,$row], $drc[$i]);
        }
        $row++;
    }
    autoWidth($ws, 6);

    // === Hoja 2: Evolución OEE ===
    {
        $dias = (new DateTime($fhasta))->diff(new DateTime($fdesde))->days;
        if ($dias <= 90)      $gran = 'DAY';
        elseif ($dias <= 365) $gran = 'WEEK';
        else                  $gran = 'MONTH';
        $bucket = $gran === 'DAY'
            ? "CAST(oee.TimePeriod AS DATE)"
            : ($gran === 'WEEK'
                ? "DATEADD(WEEK,  DATEDIFF(WEEK,  0, oee.TimePeriod), 0)"
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

        $wsEv = $book->createSheet();
        $wsEv->setTitle('Evolución OEE');
        $granLabel = ['DAY'=>'Diaria', 'WEEK'=>'Semanal', 'MONTH'=>'Mensual'][$gran];
        $rowEv = writeFilterHeader($wsEv, $ctx, "Evolución OEE ($granLabel)", 6);

        $headersEv = ['Periodo', 'Disponibilidad %', 'Rendimiento %', 'Calidad %', 'OEE %', 'Fecha inicio'];
        foreach ($headersEv as $i => $h) $wsEv->setCellValue([$i+1, $rowEv], $h);
        styleHeader($wsEv, "A$rowEv:F$rowEv");
        $rowEv++;

        $meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        foreach ($rowsEv as $r) {
            $bDate = substr((string)$r['bucket_start'], 0, 10);
            $drc = _calcDRCExport((float)$r['M'], 0, (float)$r['MOT'], (float)$r['MOKT'], (float)$r['PPERF'], (float)$r['PCALIDAD'], (float)$r['PNP']);
            $dt = new DateTime($bDate);
            if ($gran === 'DAY')        $label = $dt->format('d/m');
            elseif ($gran === 'WEEK')   $label = 'S' . $dt->format('W') . ' (' . $dt->format('d/m') . ')';
            else                        $label = $meses[(int)$dt->format('n') - 1] . ' ' . $dt->format('Y');
            $wsEv->setCellValue("A$rowEv", $label);
            $wsEv->setCellValue("B$rowEv", $drc[0]);
            $wsEv->setCellValue("C$rowEv", $drc[1]);
            $wsEv->setCellValue("D$rowEv", $drc[2]);
            $wsEv->setCellValue("E$rowEv", $drc[3]);
            $wsEv->setCellValue("F$rowEv", $bDate);
            $rowEv++;
        }
        autoWidth($wsEv, 6);
    }

    // === Hoja 3: Detalle por máquina (OFs + Refs) ===
    // Si la máquina pedida está excluida globalmente, omitimos la hoja.
    if ($detalleCodMaq && !in_array($detalleCodMaq, $excl, true)) {
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
            WHERE $whereDetSQL
              AND o.Cod_of IS NOT NULL AND o.Cod_of <> '--'
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
            WHERE $whereDetSQL
              AND pr.Cod_producto IS NOT NULL AND pr.Cod_producto <> '--'
            ORDER BY pr.Desc_producto
        ";
        $rowsRefsDet = fetchAll('mapex', $sqlRefs, $paramsDet);

        $wsDet = $book->createSheet();
        $wsDet->setTitle('Detalle máquina');
        $rowDet = writeFilterHeader($wsDet, $ctx, "Detalle por máquina · $maqDetalleLabel", 3);

        $wsDet->setCellValue("A$rowDet", 'OFs');
        $wsDet->setCellValue("C$rowDet", 'Referencias');
        styleHeader($wsDet, "A$rowDet:A$rowDet");
        styleHeader($wsDet, "C$rowDet:C$rowDet");
        $rowDet++;

        $rowOfs  = $rowDet;
        $rowRefs = $rowDet;
        foreach ($rowsOfsDet as $r) { $wsDet->setCellValue("A$rowOfs", $r['cod_of']); $rowOfs++; }
        foreach ($rowsRefsDet as $r) {
            $wsDet->setCellValue("C$rowRefs", $r['desc_producto'] ?: $r['cod_producto']);
            $rowRefs++;
        }
        autoWidth($wsDet, 3);
    }

    // === Hojas 4-7: drill por sección/métrica/motivo ===
    if ($seccion && in_array($seccion, ['VARILLAS','TROQUELADOS'], true)) {

        // Hoja 4: Desglose D/R/C/OEE de la sección
        $a = $secAcc[$seccion] ?? ['M'=>0,'MT'=>0,'MOT'=>0,'MOKT'=>0,'PP'=>0,'PC'=>0,'PNP'=>0];
        $drcSec = _calcDRCExport($a['M'],$a['MT'],$a['MOT'],$a['MOKT'],$a['PP'],$a['PC'],$a['PNP']);

        $wsSec = $book->createSheet();
        $wsSec->setTitle("$seccion D-R-C-OEE");
        $rowSec = writeFilterHeader($wsSec, $ctx, "$seccion · Desglose D/R/C/OEE", 2);
        $wsSec->setCellValue("A$rowSec", 'Métrica');
        $wsSec->setCellValue("B$rowSec", '%');
        styleHeader($wsSec, "A$rowSec:B$rowSec");
        $rowSec++;
        $wsSec->setCellValue("A$rowSec", 'Disponibilidad'); $wsSec->setCellValue("B$rowSec", $drcSec[0]); $rowSec++;
        $wsSec->setCellValue("A$rowSec", 'Rendimiento');    $wsSec->setCellValue("B$rowSec", $drcSec[1]); $rowSec++;
        $wsSec->setCellValue("A$rowSec", 'Calidad');        $wsSec->setCellValue("B$rowSec", $drcSec[2]); $rowSec++;
        $wsSec->setCellValue("A$rowSec", 'OEE');            $wsSec->setCellValue("B$rowSec", $drcSec[3]); $rowSec++;
        autoWidth($wsSec, 2);

        // Hoja 5: Métrica por máquina (si hay métrica)
        if ($metrica && in_array($metrica, ['disponibilidad','rendimiento','calidad','oee'], true)) {
            $ws2 = $book->createSheet();
            $ws2->setTitle("$seccion - Máquinas");
            $row2 = writeFilterHeader($ws2, $ctx, "$seccion · D/R/C/OEE por máquina", 6);

            $headers2 = ['Máquina', 'Disponibilidad %', 'Rendimiento %', 'Calidad %', 'OEE %', 'M_Teo (seg)'];
            foreach ($headers2 as $i => $h) $ws2->setCellValue([$i+1, $row2], $h);
            styleHeader($ws2, "A$row2:F$row2");
            $row2++;

            $secMaqs = array_filter($maqData, fn($m) => $m['seccion'] === $seccion);
            $metIdx = ['disponibilidad'=>0,'rendimiento'=>1,'calidad'=>2,'oee'=>3][$metrica];
            usort($secMaqs, fn($a,$b) => $a['drc'][$metIdx] <=> $b['drc'][$metIdx]);

            foreach ($secMaqs as $m) {
                $ws2->setCellValue("A$row2", $m['maquina']);
                for ($i=0;$i<5;$i++) $ws2->setCellValue([$i+2,$row2], $m['drc'][$i]);
                $row2++;
            }
            autoWidth($ws2, 6);

            // Hoja 6: Pareto motivos
            $codMaqsSeccion = array_map(fn($m) => $m['cod_maquina'], $secMaqs);
            require_once __DIR__ . '/oee_unificado_drill.php_motivos.php';

            $motivos = [];
            if (in_array($metrica, ['disponibilidad','oee'], true)) {
                $motivos = _exportMotivosParos($fdesde, $fhasta, $turnos, $codMaqsSeccion, $codMaq);
            } elseif ($metrica === 'calidad') {
                $motivos = _exportMotivosCalidad($fdesde, $fhasta, $turnos, $codMaqsSeccion, $codMaq);
            } elseif ($metrica === 'rendimiento') {
                $motivos = _exportMotivosRendimiento($fdesde, $fhasta, $turnos, $codMaqsSeccion, $codMaq, $fdesde, $fhasta);
            }

            $ws3 = $book->createSheet();
            $ws3->setTitle("Motivos $metricaLabel");
            $row3 = writeFilterHeader($ws3, $ctx, "Motivos de $metricaLabel · $seccion", 4);

            $isHoras = in_array($metrica, ['disponibilidad','oee','rendimiento'], true);
            $headers3 = ['Motivo', $isHoras ? 'Horas' : 'Unidades', '%', '% Acumulado'];
            foreach ($headers3 as $i => $h) $ws3->setCellValue([$i+1, $row3], $h);
            styleHeader($ws3, "A$row3:D$row3");
            $row3++;

            foreach ($motivos as $m) {
                $ws3->setCellValue("A$row3", $m['motivo']);
                $ws3->setCellValue("B$row3", $isHoras ? ($m['horas'] ?? 0) : ($m['unidades'] ?? 0));
                $ws3->setCellValue("C$row3", $m['pct']);
                $ws3->setCellValue("D$row3", $m['pct_acum']);
                $row3++;
            }
            autoWidth($ws3, 4);

            // Hoja 7: Motivo seleccionado (por máquina + por hora)
            if ($motivo) {
                $wsMot = $book->createSheet();
                // Excel: títulos de hoja sin \ / ? * [ ] : y máx 31 chars
                $titleClean = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/u', '_', "Motivo $motivo");
                if (mb_strlen($titleClean) > 31) $titleClean = mb_substr($titleClean, 0, 31);
                $wsMot->setTitle($titleClean);
                $rowM = writeFilterHeader($wsMot, $ctx, "Motivo seleccionado · $motivo · $seccion", 5);

                // Por máquina
                $wsMot->setCellValue("A$rowM", 'POR MÁQUINA');
                $wsMot->getStyle("A$rowM")->getFont()->setBold(true);
                $rowM++;

                $headersMot = ['Máquina', $isHoras ? 'Horas' : 'Unidades', '%'];
                foreach ($headersMot as $i => $h) $wsMot->setCellValue([$i+1, $rowM], $h);
                styleHeader($wsMot, "A$rowM:C$rowM");
                $rowM++;

                // Re-fetch via motivo_drill (las funciones ya están en el require)
                require_once __DIR__ . '/oee_unificado_motivo_drill.php_data.php';
                $detalleMot = _exportMotivoDrillDetalle($fdesde, $fhasta, $turnos, $metrica, $codMaqsSeccion, $motivo);
                $porHora    = _exportMotivoDrillPorHora($fdesde, $fhasta, $turnos, $metrica, $codMaqsSeccion, $motivo, $motivoCodMaq);

                foreach ($detalleMot as $d) {
                    $wsMot->setCellValue("A$rowM", $d['maquina']);
                    $wsMot->setCellValue("B$rowM", $isHoras ? ($d['horas'] ?? 0) : ($d['unidades'] ?? 0));
                    $wsMot->setCellValue("C$rowM", $d['pct']);
                    $rowM++;
                }

                // Por hora
                $rowM += 2;
                $hourTitle = 'POR HORA DEL DÍA';
                if ($motivoCodMaq) $hourTitle .= " · $motivoMaqLabel";
                $wsMot->setCellValue("A$rowM", $hourTitle);
                $wsMot->getStyle("A$rowM")->getFont()->setBold(true);
                $rowM++;

                if (!empty($porHora) && !empty($porHora['horas']) && !empty($porHora['maquinas'])) {
                    $headersHora = ['Hora'];
                    foreach ($porHora['maquinas'] as $mq) $headersHora[] = $mq['maquina'];
                    foreach ($headersHora as $i => $h) $wsMot->setCellValue([$i+1, $rowM], $h);
                    $lastCol = Coordinate::stringFromColumnIndex(count($headersHora));
                    styleHeader($wsMot, "A$rowM:{$lastCol}$rowM");
                    $rowM++;

                    foreach ($porHora['horas'] as $hRow) {
                        $wsMot->setCellValue("A$rowM", str_pad((string)$hRow['h'], 2, '0', STR_PAD_LEFT) . ':00');
                        foreach ($porHora['maquinas'] as $i => $mq) {
                            $wsMot->setCellValue([$i+2, $rowM], $hRow[$mq['cod_maquina']] ?? 0);
                        }
                        $rowM++;
                    }
                } else {
                    $wsMot->setCellValue("A$rowM", 'Sin desglose horario disponible');
                    $wsMot->getStyle("A$rowM")->getFont()->setItalic(true);
                }
                autoWidth($wsMot, 6);
            }
        }
    }

    // Output
    $stamp = date('Ymd_His');
    $base = "OEE_Unificado_{$fdesde}_a_{$fhasta}_{$stamp}";
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
    header('Cache-Control: no-store');
    $writer = IOFactory::createWriter($book, 'Xlsx');
    $writer->save('php://output');
    exit;

} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Error al exportar: ' . $e->getMessage()]);
    }
    exit;
}
