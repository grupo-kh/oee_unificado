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
 *   OEE = D * R * C / 10000
 */
function _matDRC(float $M, float $MOT, float $MOKT, float $PP, float $PC, float $PNP): array {
    $d = ($M + $PNP)      > 0 ? $M / ($M + $PNP) * 100               : 0.0;
    $r = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0.0;
    $c = ($MOT + $PC)     > 0 ? $MOKT / ($MOT + $PC) * 100            : 0.0;
    $oee = $d * $r * $c / 10000;
    return ['disp' => round($d, 1), 'rend' => round($r, 1), 'cal' => round($c, 1), 'oee' => round($oee, 1)];
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

    // ───── 2) % D/R/C + unidades fabricadas por máquina + referencia ─────
    $drcRef   = [];   // [cod_maquina][cod_producto] => ['disp','rend','cal','oee']
    $unitsRef = [];   // [cod_maquina][cod_producto] => ['u_total','u_teo','dif']
    if (!empty($codMaqs)) {
        $phM = implode(',', array_fill(0, count($codMaqs), '?'));
        $sqlOeeRef = "
            SELECT oee.WorkGroup AS cod_maquina, LTRIM(RTRIM(oee.Cod_producto)) AS cod_ref,
                   SUM(oee.M) AS M, SUM(oee.M_OKNOK_TEO) AS MOT, SUM(oee.M_OK_TEO) AS MOKT,
                   SUM(oee.PPERF) AS PP, SUM(oee.PCALIDAD) AS PC, SUM(oee.PNP) AS PNP,
                   SUM(oee.Unidades_Total) AS u_total, SUM(oee.Unidades_Teo) AS u_teo
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
            $uTot = (int)$r['u_total']; $uTeo = (int)$r['u_teo'];
            $unitsRef[$cm][$cr] = [
                'u_total' => $uTot,
                'u_teo'   => $uTeo,
                'dif'     => $uTot - $uTeo,   // Uds. Total fab. - Uds. Teo fab. (±)
            ];
        }
    }

    // ───── 2b) Rdto. Teo: rendimiento nominal previsto por máquina+producto ─────
    // Fuente MAPEX: configuración de productividad por máquina-producto
    // (cfg_fase_maquina.Rendimientonominal1 = piezas/hora previstas).
    $rendTeo = [];   // [cod_maquina][cod_producto] => piezas/hora nominal
    if (!empty($codMaqs)) {
        $phM = implode(',', array_fill(0, count($codMaqs), '?'));
        $sqlRend = "
            SELECT mq.Cod_maquina AS cod_maquina, LTRIM(RTRIM(p.Cod_producto)) AS cod_ref,
                   MAX(fm.Rendimientonominal1) AS rend
            FROM cfg_fase_maquina fm
            INNER JOIN cfg_fase     f  ON f.Id_fase     = fm.Id_fase AND f.Activo = 1
            INNER JOIN cfg_producto p  ON p.Id_producto = f.Id_producto
            INNER JOIN cfg_maquina  mq ON mq.Id_maquina = fm.Id_maquina
            WHERE fm.Activo = 1 AND mq.Cod_maquina IN ($phM)
            GROUP BY mq.Cod_maquina, LTRIM(RTRIM(p.Cod_producto))
        ";
        foreach (fetchAll('mapex', $sqlRend, $codMaqs) as $r) {
            $cm = (string)$r['cod_maquina']; $cr = (string)$r['cod_ref'];
            if ($cr === '') continue;
            $rendTeo[$cm][$cr] = round((float)$r['rend'], 1);
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
        // Además del span bruto (fin-ini) calculamos el tiempo en PREPARACION
        // (cfg_actividad.Desc_actividad = 'PREPARACION') para restarlo y obtener
        // el tiempo de fabricación REAL.
        $sqlFab = "
            SELECT mq.Cod_maquina AS cod_maquina, LTRIM(RTRIM(prod.Cod_producto)) AS cod_ref,
                   MIN(hp.Fecha_ini) AS ini, MAX(ISNULL(hp.Fecha_fin, hp.Fecha_ini)) AS fin,
                   DATEDIFF(SECOND, MIN(hp.Fecha_ini), MAX(ISNULL(hp.Fecha_fin, hp.Fecha_ini))) AS span_seg,
                   SUM(CASE WHEN LTRIM(RTRIM(act.Desc_actividad)) = 'PREPARACION'
                            THEN DATEDIFF(SECOND, hp.Fecha_ini, ISNULL(hp.Fecha_fin, hp.Fecha_ini))
                            ELSE 0 END) AS prep_seg
            FROM his_prod hp
            INNER JOIN cfg_maquina   mq   ON mq.Id_maquina   = hp.Id_maquina
            INNER JOIN cfg_turno     ct   ON ct.Id_turno     = hp.Id_turno
            LEFT  JOIN cfg_actividad act  ON act.Id_actividad = hp.Id_actividad
            LEFT  JOIN his_fase      fa   ON fa.Id_his_fase  = hp.Id_his_fase
            LEFT  JOIN his_of        o    ON o.Id_his_of     = fa.Id_his_of
            LEFT  JOIN cfg_producto  prod ON prod.Id_producto = o.Id_producto
            WHERE " . implode(' AND ', $wf) . "
            GROUP BY mq.Cod_maquina, LTRIM(RTRIM(prod.Cod_producto))
        ";
        foreach (fetchAll('mapex', $sqlFab, $pf) as $r) {
            $cm = (string)$r['cod_maquina']; $cr = (string)$r['cod_ref'];
            if ($cr === '') continue;
            $prep = (int)$r['prep_seg'];
            $real = max(0, (int)$r['span_seg'] - $prep);   // bruto − preparación
            $fab[$cm][$cr] = [
                'ini'     => _matFecha($r['ini']),
                'fin'     => _matFecha($r['fin']),
                'raw'     => substr((string)$r['ini'], 0, 19),
                'prep_h'  => round($prep / 3600, 2),
                'real_h'  => round($real / 3600, 2),
            ];
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
            "cp.Id_actividad <> 1",   // excluir actividad CERRADA (igual que Matriz 2)
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

    // Orden: motivos por total desc; máquinas por TOTAL paro desc (mayor arriba).
    arsort($motivoTot);
    $motivos = array_keys($motivoTot);
    $maquinas = array_keys($matrix);
    usort($maquinas, fn($a, $b) => array_sum($maqTotal[$b] ?? []) <=> array_sum($maqTotal[$a] ?? []));

    // Helper: datos por referencia listos para JSON/XLSX.
    // Orden de las referencias de cada máquina: por FECHA DE INICIO de fabricación
    // ascendente (orden cronológico de fabricación); las sin fecha, al final.
    $refsDe = function(string $maq) use ($refTotal, $refLabel, $matrix, $drcRef, $unitsRef, $rendTeo, $fab, $sageNom, $codByName) {
        $cm = $codByName[$maq] ?? '';
        $out = [];
        foreach (array_keys($refTotal[$maq] ?? []) as $k) {
            $drc = ($k !== '__NOREF__') ? ($drcRef[$cm][$k] ?? null) : null;
            $un  = ($k !== '__NOREF__') ? ($unitsRef[$cm][$k] ?? null) : null;
            $rdt = ($k !== '__NOREF__') ? ($rendTeo[$cm][$k] ?? null) : null;
            $fb  = ($k !== '__NOREF__') ? ($fab[$cm][$k] ?? null) : null;
            $out[] = [
                'cod_referencia'  => $k === '__NOREF__' ? '' : $k,
                'referencia'      => $refLabel[$maq][$k] ?? $k,
                'nomenclatura'    => $k !== '__NOREF__' ? ($sageNom[$k] ?? '') : '',
                'total'           => round($refTotal[$maq][$k], 2),
                'disponibilidad'  => $drc['disp'] ?? null,
                'rendimiento'     => $drc['rend'] ?? null,
                'calidad'         => $drc['cal']  ?? null,
                'oee'             => $drc['oee']  ?? null,
                'uds_total'       => $un['u_total'] ?? null,
                'uds_teo'         => $un['u_teo']   ?? null,
                'ph_real'         => $un['dif']     ?? null,   // Uds. Total fab. - Uds. Teo fab.
                'ph_teo'          => $rdt,                     // Rdto. Teo (pzs/h previstas, cfg_fase_maquina)
                'fab_inicio'      => $fb['ini'] ?? '',
                'fab_fin'         => $fb['fin'] ?? '',
                'fab_prep'        => $fb['prep_h'] ?? null,
                'fab_real'        => $fb['real_h'] ?? null,
                'por_motivo'      => array_map(fn($v) => round($v, 2), $matrix[$maq][$k] ?? []),
                '_sort'           => $fb['raw'] ?? '9999-12-31 23:59:59',
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['_sort'], $b['_sort']));
        foreach ($out as &$o) unset($o['_sort']);
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
                'oee'            => $drc['oee']  ?? null,
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
    // Columnas: 1 Máquina/Ref · 2 Nom.SAGE · 3 TOTAL(h) · 4 Disp · 5 Rend · 6 Cal · 7 OEE
    //           · 8 Preparación(h) · 9 Inicio fab · 10 Fin fab · 11 Uds.Total · 12 Uds.Teo
    //           · 13 Pzs/h real · 14 Pzs/h teo · 15 Fab. real(h) · 16.. motivos
    $book = new Spreadsheet();
    $book->getProperties()->setCreator('KH Plan Attainment')->setTitle('OEE Unificado · Matriz')
        ->setDescription("Matriz motivos × máquina/referencia $fdesde a $fhasta");
    $ws = $book->getActiveSheet();
    $ws->setTitle('Matriz');

    $colName = 1; $colSage = 2; $colTotal = 3; $colDisp = 4; $colRend = 5; $colCal = 6; $colOee = 7;
    $colPrep = 8; $colIni = 9; $colFin = 10; $colUTot = 11; $colUTeo = 12;
    $colPhReal = 13; $colPhTeo = 14; $colReal = 15; $colMot0 = 16;
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
    $ws->setCellValue([$colOee,  $hRow], 'OEE %');
    $ws->setCellValue([$colIni,  $hRow], 'Inicio fab.');
    $ws->setCellValue([$colFin,  $hRow], 'Fin fab.');
    $ws->setCellValue([$colPrep, $hRow], 'Preparación (h)');
    $ws->setCellValue([$colUTot, $hRow], 'Uds. Total fab.');
    $ws->setCellValue([$colUTeo, $hRow], 'Uds. Teo fab.');
    $ws->setCellValue([$colPhReal, $hRow], 'Pzs/h real');
    $ws->setCellValue([$colPhTeo, $hRow], 'Rdto. Teo');
    $ws->setCellValue([$colReal, $hRow], 'Fab. real (h)');
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

    // Formato condicional (tonos salmón).
    //   - % D/R/C/OEE < 75  → salmón suave.
    //   - celdas de motivo por referencia: >1 suave · >2 medio · >3 oscuro.
    $SAL1 = 'FDE2D8'; $SAL2 = 'F9C3B0'; $SAL3 = 'F3A088';
    $fillCell = function(int $col, int $rw, string $rgb) use ($ws) {
        $ws->getStyle([$col, $rw])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    };
    $salMot = function(float $v) use ($SAL1, $SAL2, $SAL3): ?string {
        if ($v > 3) return $SAL3; if ($v > 2) return $SAL2; if ($v > 1) return $SAL1; return null;
    };

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
            $ws->setCellValue([$colOee,  $maqRow], $drcByName[$maq]['oee']);
        }
        $ws->getStyle("A$maqRow:{$lastColLt}$maqRow")->getFont()->setBold(true);
        $ws->getStyle("A$maqRow:{$lastColLt}$maqRow")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F4');
        // (El formato condicional salmón en % NO se aplica a las filas de máquina,
        //  solo al detalle por referencia.)
        $row++;

        foreach ($refsDe($maq) as $rf) {
            $ws->setCellValue([$colName, $row], '    ' . $rf['referencia']);
            $ws->getStyle([$colName, $row])->getAlignment()->setIndent(1);
            $ws->setCellValue([$colSage, $row], $rf['nomenclatura']);
            $ws->setCellValue([$colTotal, $row], $rf['total']);
            if ($rf['disponibilidad'] !== null) $ws->setCellValue([$colDisp, $row], $rf['disponibilidad']);
            if ($rf['rendimiento'] !== null)    $ws->setCellValue([$colRend, $row], $rf['rendimiento']);
            if ($rf['calidad'] !== null)        $ws->setCellValue([$colCal,  $row], $rf['calidad']);
            if ($rf['oee'] !== null)            $ws->setCellValue([$colOee,  $row], $rf['oee']);
            $ws->setCellValueExplicit([$colIni, $row], $rf['fab_inicio'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $ws->setCellValueExplicit([$colFin, $row], $rf['fab_fin'],    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            if ($rf['fab_prep'] !== null) $ws->setCellValue([$colPrep, $row], $rf['fab_prep']);
            if ($rf['uds_total'] !== null) $ws->setCellValue([$colUTot, $row], $rf['uds_total']);
            if ($rf['uds_teo'] !== null)   $ws->setCellValue([$colUTeo, $row], $rf['uds_teo']);
            if ($rf['ph_real'] !== null)   $ws->setCellValue([$colPhReal, $row], $rf['ph_real']);
            if ($rf['ph_teo'] !== null)    $ws->setCellValue([$colPhTeo, $row], $rf['ph_teo']);
            if ($rf['fab_real'] !== null) $ws->setCellValue([$colReal, $row], $rf['fab_real']);
            // Salmón en % < 75 de la referencia.
            foreach ([[$colDisp,'disponibilidad'],[$colRend,'rendimiento'],[$colCal,'calidad'],[$colOee,'oee']] as $pc) {
                if ($rf[$pc[1]] !== null && $rf[$pc[1]] < 75) $fillCell($pc[0], $row, $SAL1);
            }
            foreach ($motivos as $i => $mot) {
                $v = $rf['por_motivo'][$mot] ?? 0;
                if ($v > 0) $ws->setCellValue([$colMot0 + $i, $row], round($v, 2));
                $tone = $salMot((float)$v);          // gradiente salmón por horas de paro
                if ($tone !== null) $fillCell($colMot0 + $i, $row, $tone);
            }
            $row++;
        }
    }
    $lastRow = $row - 1;

    // Formatos: horas (TOTAL + motivos), % (D/R/C), texto fechas, bordes y anchos.
    $totLt  = Coordinate::stringFromColumnIndex($colTotal);
    $motIniLt = Coordinate::stringFromColumnIndex($colMot0);
    $pctFinLt = Coordinate::stringFromColumnIndex($colOee);
    $dispLt = Coordinate::stringFromColumnIndex($colDisp);
    if ($lastRow >= $totRow) {
        // Columna TOTAL (izquierda) + columnas de motivos = horas.
        $ws->getStyle("{$totLt}$totRow:{$totLt}$lastRow")->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->getStyle("{$totLt}$totRow:{$totLt}$lastRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        if ($nMot > 0) {
            $ws->getStyle("{$motIniLt}$totRow:{$lastColLt}$lastRow")->getNumberFormat()->setFormatCode('#,##0.00');
            $ws->getStyle("{$motIniLt}$totRow:{$lastColLt}$lastRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $ws->getStyle("{$dispLt}$totRow:{$pctFinLt}$lastRow")->getNumberFormat()->setFormatCode('0.0"%"');
        $ws->getStyle("{$dispLt}$totRow:{$pctFinLt}$lastRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        foreach ([$colPrep, $colReal] as $cc) {   // horas: prep y fab. real (no adyacentes)
            $lt = Coordinate::stringFromColumnIndex($cc);
            $ws->getStyle("{$lt}$totRow:{$lt}$lastRow")->getNumberFormat()->setFormatCode('#,##0.00');
            $ws->getStyle("{$lt}$totRow:{$lt}$lastRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $utLt = Coordinate::stringFromColumnIndex($colUTot);
        $ptLt = Coordinate::stringFromColumnIndex($colPhTeo);
        // Uds. Total/Teo fab. + Pzs/h real (diferencia) = enteros; Rdto. Teo = 1 decimal.
        $ws->getStyle("{$utLt}$totRow:" . Coordinate::stringFromColumnIndex($colPhReal) . "$lastRow")->getNumberFormat()->setFormatCode('#,##0');
        $ws->getStyle("{$ptLt}$totRow:{$ptLt}$lastRow")->getNumberFormat()->setFormatCode('#,##0.0');
        $ws->getStyle("{$utLt}$totRow:{$ptLt}$lastRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle("A$hRow:{$lastColLt}$lastRow")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C9D4E3');
    }
    $ws->getColumnDimension('A')->setWidth(40);
    $ws->getColumnDimension(Coordinate::stringFromColumnIndex($colSage))->setWidth(16);
    $ws->getColumnDimension($totLt)->setWidth(13);
    foreach ([$colDisp,$colRend,$colCal,$colOee] as $c) $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(9);
    $ws->getColumnDimension(Coordinate::stringFromColumnIndex($colIni))->setWidth(15);
    $ws->getColumnDimension(Coordinate::stringFromColumnIndex($colFin))->setWidth(15);
    $ws->getColumnDimension(Coordinate::stringFromColumnIndex($colPrep))->setWidth(13);
    foreach ([$colUTot,$colUTeo,$colPhReal,$colPhTeo] as $c) $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(11);
    $ws->getColumnDimension(Coordinate::stringFromColumnIndex($colReal))->setWidth(13);
    for ($c = $colMot0; $c <= $lastCol; $c++) $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(12);
    // Inmoviliza bloque izquierdo (15 cols) + cabecera + fila TOTAL.
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
