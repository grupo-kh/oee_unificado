<?php
/**
 * Export del histórico de intervenciones (mant_historico) a CSV o XLSX.
 *
 * Recibe los mismos filtros que mant_historico.php (fecha_desde, fecha_hasta,
 * cod_maquina_mant, operario, periodicidad) más un parámetro:
 *   - formato : 'csv' | 'xlsx'
 *
 * Devuelve el fichero como descarga directa al navegador.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

// Lectura para auditoría → permitido a ambos roles.
Auth::requireLoginApi();

try {
    $hoy    = date('Y-m-d');
    $fdesde = getParam('fecha_desde', date('Y-m-d', strtotime('-90 days')));
    $fhasta = getParam('fecha_hasta', $hoy);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');

    $cm  = (string)getParam('cod_maquina_mant', '');
    $op  = (string)getParam('operario',         '');
    $pe  = (string)getParam('periodicidad',     '');
    $fmt = strtolower((string)getParam('formato', 'csv'));
    if (!in_array($fmt, ['csv', 'xlsx'], true)) jsonError("formato debe ser 'csv' o 'xlsx'");

    // Reaprovechamos el almacén que ya hace toda la lógica de filtrado.
    $marcadasWeb = MaintenanceCompletionStore::loadAll();
    $rows = [];
    foreach ($marcadasWeb as $m) {
        $f = $m['fecha_intervencion'] ?? null;
        $motivo = trim((string)($m['motivo_no_realizada'] ?? ''));
        $isMissed = ($f === null || $f === '') && $motivo !== '';
        $fechaRef = $isMissed ? (string)($m['fecha_proxima_original'] ?? '') : (string)$f;
        if ($fechaRef === '')          continue;
        if ($fechaRef < $fdesde)        continue;
        if ($fechaRef > $fhasta)        continue;
        if ($cm && ($m['cod_maquina_mant'] ?? '') !== $cm) continue;
        if ($op && ($m['operario']         ?? '') !== $op) continue;
        if ($pe && ($m['periodicidad']     ?? '') !== $pe) continue;

        $rows[] = [
            'Fecha'              => $isMissed ? '(no realizada)' : (string)$f,
            'Tipo'               => $isMissed ? 'No realizada'  : 'Completada',
            'Cod. máquina'       => (string)($m['cod_maquina_mant'] ?? ''),
            'Máquina'            => (string)($m['desc_maquina']     ?? ''),
            'Periodicidad'       => (string)($m['periodicidad']     ?? ''),
            'Tarea'              => (string)($m['tarea']            ?? ''),
            'Descripción tarea'  => (string)($m['desc_tarea']       ?? ''),
            'Operario'           => (string)($m['operario']         ?? ''),
            'Observaciones'      => (string)($m['observaciones']    ?? ''),
            'Motivo no realizada'=> (string)($m['motivo_no_realizada'] ?? ''),
            'Fecha próxima orig.'=> (string)($m['fecha_proxima_original'] ?? ''),
            'Marcada por'        => (string)($m['marcada_por']      ?? ''),
        ];
    }

    // Ordenar agrupando por máquina (alfabético, A→Z) y, dentro de cada
    // máquina, por fecha descendente (más reciente primero). Así queda más
    // legible en el export para auditorías.
    usort($rows, function($a, $b) {
        $cmpMaq = strcmp((string)$a['Máquina'], (string)$b['Máquina']);
        if ($cmpMaq !== 0) return $cmpMaq;
        return strcmp((string)$b['Fecha'], (string)$a['Fecha']);
    });

    $stamp = date('Ymd_His');
    $base  = "historico_mant_{$fdesde}_a_{$fhasta}_{$stamp}";

    if ($fmt === 'csv') {
        // CSV con BOM UTF-8 para que Excel lo abra con tildes correctas.
        // No usamos jsonError aquí — necesitamos cabeceras de descarga.
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '.csv"');
        header('Cache-Control: no-store');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // BOM
        if ($rows) {
            fputcsv($fp, array_keys($rows[0]), ';', '"', '\\');
            foreach ($rows as $r) fputcsv($fp, array_values($r), ';', '"', '\\');
        } else {
            fputcsv($fp, ['Sin filas para los filtros seleccionados'], ';', '"', '\\');
        }
        fclose($fp);
        exit;
    }

    // ─── XLSX ───
    require_once __DIR__ . '/../vendor/autoload.php';
    ini_set('memory_limit', '512M');

    $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws    = $sheet->getActiveSheet();
    $ws->setTitle('Histórico');

    if ($rows) {
        $headers = array_keys($rows[0]);
        $col = 1;
        foreach ($headers as $h) {
            $ws->setCellValue([$col++, 1], $h);
        }
        // Estilo cabecera (negrita + fondo claro).
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $ws->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $ws->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DCE7F4');
        $ws->freezePane('A2');

        $row = 2;
        foreach ($rows as $r) {
            $col = 1;
            foreach ($r as $v) {
                $ws->setCellValue([$col++, $row], $v);
            }
            $row++;
        }
        // Auto-ancho
        for ($i = 1; $i <= count($headers); $i++) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $ws->getColumnDimension($letter)->setAutoSize(true);
        }
        // Filtro
        $ws->setAutoFilter("A1:{$lastCol}" . ($row - 1));
    } else {
        $ws->setCellValue('A1', 'Sin filas para los filtros seleccionados');
    }

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
    header('Cache-Control: no-store');
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($sheet, 'Xlsx');
    $writer->save('php://output');
    exit;

} catch (Throwable $e) {
    // En caso de error después de empezar headers ya no se puede devolver
    // JSON limpio; intentamos como mejor esfuerzo.
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Error al exportar: ' . $e->getMessage()]);
    }
    exit;
}
