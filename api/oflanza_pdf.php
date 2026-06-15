<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Genera (o re-sirve) el PDF de una OF lanzada.
 *
 * GET ?id=N  → id del registro en ofs_lanzadas
 *
 * Comportamiento:
 *   - Si la fila tiene `pdf_path` y el fichero existe, sirve ese.
 *   - Si no existe, lo genera, lo guarda en docs/ofs/<YYYY>/<MM>/OF...-id.pdf
 *     y actualiza la fila con la ruta.
 */

try {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo 'id inválido';
        exit;
    }
    $row = Db::pgFetchOne("
        SELECT id, of_codigo, referencia, cod_maquina, desc_maquina,
               cantidad, duracion_horas, ubicacion_galga, notas, notas_operario,
               operario, estado, pdf_path,
               to_char(lanzada_at, 'YYYY-MM-DD HH24:MI:SS') AS lanzada_at_txt,
               to_char(lanzada_at, 'YYYY')                   AS y,
               to_char(lanzada_at, 'MM')                     AS m
          FROM ofs_lanzadas WHERE id = :id
    ", [':id' => $id]);
    if (!$row) {
        http_response_code(404);
        echo 'OF no encontrada';
        exit;
    }

    // Carpeta destino (relativa a la app): docs/ofs/YYYY/MM/
    $baseRel = 'docs/ofs/' . $row['y'] . '/' . $row['m'];
    $baseAbs = __DIR__ . '/../' . $baseRel;
    if (!is_dir($baseAbs)) @mkdir($baseAbs, 0777, true);

    // Nombre fichero: OF<codigo>-<id>.pdf  (id evita colisiones si se relanza)
    $safeOf  = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$row['of_codigo']) ?: 'OF';
    $relPath = $baseRel . '/' . $safeOf . '-' . $row['id'] . '.pdf';
    $absPath = $baseAbs . '/' . $safeOf . '-' . $row['id'] . '.pdf';

    // Servir cacheado si ya existe en disco
    if (!file_exists($absPath)) {
        // Datos para la plantilla
        $ofCod   = htmlspecialchars((string)$row['of_codigo']);
        $ref     = htmlspecialchars((string)($row['referencia']      ?? ''));
        $cm      = htmlspecialchars((string)($row['cod_maquina']     ?? ''));
        $dm      = htmlspecialchars((string)($row['desc_maquina']    ?? ''));
        $cant    = htmlspecialchars((string)($row['cantidad']        ?? ''));
        $dur     = htmlspecialchars((string)($row['duracion_horas']  ?? ''));
        $galga   = htmlspecialchars((string)($row['ubicacion_galga'] ?? ''));
        $notas   = htmlspecialchars((string)($row['notas']           ?? ''));
        $notasOp = htmlspecialchars((string)($row['notas_operario']  ?? ''));
        $op      = htmlspecialchars((string)($row['operario']        ?? ''));
        $estado  = htmlspecialchars((string)($row['estado']          ?? 'lanzada'));
        $cuando  = htmlspecialchars((string)($row['lanzada_at_txt']  ?? ''));

        $html = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8">
<style>
    body { font-family: Arial, sans-serif; color:#1a2d4a; margin:0; padding:0; }
    .head {
        background:#2d4d7a; color:#fff; padding:14px 18px;
        display:flex; justify-content:space-between; align-items:center;
    }
    .head h1 { margin:0; font-size:18px; letter-spacing:2px; }
    .head .id { font-size:11px; opacity:.85; }
    .of-bar {
        background:#ffb78a; color:#4d1500; padding:14px 18px;
        font-size:26px; font-weight:800; letter-spacing:2px;
        text-align:center; border-bottom:3px solid #c8551a;
    }
    .sub-title {
        text-align:center; padding:10px;
        font-size:12px; color:#5b6f86; font-weight:700;
        text-transform:uppercase; letter-spacing:.8px;
    }
    table.detalle { width:100%; border-collapse:collapse; margin:0 18px; width:calc(100% - 36px); }
    table.detalle td { padding:7px 10px; border-bottom:1px solid #eef2f6; font-size:12px; }
    table.detalle td.lbl { font-weight:700; color:#2d4d7a; width:170px; }
    table.detalle td.units { color:#5b6f86; width:80px; font-size:11px; }
    .notas-row { margin:14px 18px; }
    .notas-lbl {
        background:#c8102e; color:#fff; padding:6px 12px; font-weight:700;
        display:inline-block; font-size:11px; border-radius:4px;
        text-transform:uppercase; letter-spacing:.5px;
    }
    .notas-val { padding:8px 12px; background:#fef2f2; border:1px solid #fca5a5; border-radius:4px; margin-top:4px; font-size:12px; min-height:22px; }
    .foot {
        margin:18px; padding:10px 14px; background:#eef3f8;
        border-left:4px solid #2d4d7a; font-size:11px; color:#1a2d4a;
        display:flex; justify-content:space-between;
    }
    .foot strong { color:#2d4d7a; }
</style>
</head><body>

<div class="head">
    <h1>ORDEN DE FABRICACIÓN</h1>
    <span class="id">Registro #{$row['id']} · KH</span>
</div>

<div class="of-bar">$ofCod</div>
<div class="sub-title">Información de resumen de la OF</div>

<table class="detalle">
    <tr><td class="lbl">REF</td><td>$ref</td><td class="units"></td></tr>
    <tr><td class="lbl">UBICACIÓN DE GALGA</td><td>$galga</td><td class="units"></td></tr>
    <tr><td class="lbl">CANTIDAD</td><td>$cant</td><td class="units">UDS</td></tr>
    <tr><td class="lbl">DURACIÓN OF</td><td>$dur</td><td class="units">HORAS</td></tr>
    <tr><td class="lbl">MÁQUINA</td><td>$dm <em style="color:#5b6f86;font-size:10.5px">($cm)</em></td><td class="units"></td></tr>
</table>

<div class="notas-row">
    <span class="notas-lbl">NOTAS O STOPPERS</span>
    <div class="notas-val">$notas</div>
</div>

HTML;

        if ($notasOp !== '') {
            $html .= <<<HTML
<div class="notas-row">
    <span class="notas-lbl" style="background:#2d4d7a">NOTAS DEL OPERARIO</span>
    <div class="notas-val" style="background:#eef3f8;border-color:#c5d2e0">$notasOp</div>
</div>
HTML;
        }

        $html .= <<<HTML
<div class="foot">
    <span>Lanzada por <strong>{$op}</strong> el <strong>{$cuando}</strong></span>
    <span>Estado: <strong>{$estado}</strong></span>
</div>

</body></html>
HTML;

        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'orientation'   => 'P',
            'margin_top'    => 5,
            'margin_bottom' => 5,
            'margin_left'   => 8,
            'margin_right'  => 8,
        ]);
        $mpdf->SetTitle('OF ' . (string)$row['of_codigo']);
        $mpdf->SetCreator('KH Plan Attainment');
        $mpdf->WriteHTML($html);
        $mpdf->Output($absPath, \Mpdf\Output\Destination::FILE);

        // Guardamos la ruta en la fila para futuras consultas
        Db::pgExec(
            "UPDATE ofs_lanzadas SET pdf_path = :p WHERE id = :id",
            [':p' => $relPath, ':id' => $row['id']]
        );
    }

    // Servir el PDF inline
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($absPath) . '"');
    header('Content-Length: ' . filesize($absPath));
    readfile($absPath);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
}
