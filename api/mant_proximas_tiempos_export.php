<?php
/**
 * Export XLSX · "Resumen de Próximas Revisiones"
 *
 * Documento entregable al operario. Diseño minimalista:
 *   · Encabezado de una sola línea con el rango y los totales.
 *   · UNA cabecera de columnas al inicio (no se repite por máquina).
 *   · Lista plana de tareas con la máquina como columna más.
 *   · Separación entre máquinas mediante una fila gris muy clara con
 *     el nombre de la máquina y sus totales — sin grandes bloques de
 *     color.
 *   · Sólo el "Estado" lleva color (rojo / ámbar / verde) en forma de
 *     puntito en la primera columna. Todo lo demás en negro/gris.
 *
 * Misma lógica de filtros que api/mant_proximas.php:
 *   · Consolida RACK/PLATAFORMA/TROLEY (filtrando antes las ya marcadas).
 *   · Para el resto excluye las que ya tengan marca de hechas.
 *
 * GET params:
 *   cod_maquina_mant   (opc): limita a una sola máquina
 *   fecha_desde, fecha_hasta (YYYY-MM-DD): rango activo
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';
require_once __DIR__ . '/../vendor/autoload.php';

Auth::requireLoginApi();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\IOFactory;

function _fmtMin($min) {
    $min = (int)$min;
    if ($min <= 0) return '0';
    $h = intdiv($min, 60); $m = $min % 60;
    if ($h === 0) return $min . ' min';
    if ($m === 0) return $h . ' h';
    return $h . ' h ' . $m . ' min';
}

function _gapVenc(string $per): int {
    switch (strtoupper(trim($per))) {
        case 'DIARIO': case 'DIARIA':       return 1;
        case 'SEMANAL':                     return 3;
        case 'QUINCENAL':                   return 5;
        case 'MENSUAL':                     return 7;
        case 'BIMESTRAL': case 'BIMENSUAL': return 10;
        case 'TRIMESTRAL':                  return 14;
        case 'CUATRIMESTRAL':               return 18;
        case 'SEMESTRAL':                   return 21;
        case 'ANUAL':                       return 30;
        default:                            return 0;
    }
}

function _fmtDias(int $d): string {
    if ($d === 0)  return 'hoy';
    if ($d === 1)  return 'en 1 día';
    if ($d > 0)    return "en $d días";
    if ($d === -1) return 'vencida 1 día';
    return 'vencida ' . abs($d) . ' días';
}

try {
    ini_set('memory_limit', '512M');
    $hoy = date('Y-m-d');

    $codFiltro = isset($_GET['cod_maquina_mant'])
        ? trim((string)$_GET['cod_maquina_mant']) : '';
    $fDesde = isset($_GET['fecha_desde']) ? (string)$_GET['fecha_desde'] : '';
    $fHasta = isset($_GET['fecha_hasta']) ? (string)$_GET['fecha_hasta'] : '';
    $usaIntervalo = false;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDesde)
     && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fHasta)
     && $fDesde <= $fHasta) {
        $usaIntervalo = true;
    } else {
        $fDesde = $fHasta = '';
    }

    $data        = MaintenancePlanStore::load();
    $marcadasIdx = MaintenanceCompletionStore::loadIndexed();
    $perIdx      = MaintenancePeriodicidadStore::loadIndexed();

    // Pre-filtrado: consolidables ya marcadas fuera; resto pasa.
    $proximasPrep = [];
    foreach ($data['proximas'] as $p) {
        $desc = (string)($p['desc_maquina'] ?? '');
        if (MaintenancePlanStore::esConsolidable($desc)) {
            $idMark = MaintenanceCompletionStore::buildId(
                (string)$p['orden'], (string)$p['tarea'], (string)($p['proxima_revision'] ?? '')
            );
            if (isset($marcadasIdx[$idMark])) continue;
        }
        $proximasPrep[] = $p;
    }
    $proximas = MaintenancePlanStore::consolidateSecuenciaProximas($proximasPrep);

    $byMaq = [];
    foreach ($proximas as $p) {
        if (!empty($p['fecha_pausado']))            continue;
        if (($p['alta_baja'] ?? 'ALTA') === 'BAJA') continue;
        if (($p['activa']    ?? 'A')    === 'B')    continue;
        $bi = (string)($p['fecha_bloqueo_ini'] ?? '');
        $bf = (string)($p['fecha_bloqueo_fin'] ?? '');
        if ($bi && $bf && $hoy >= $bi && $hoy <= $bf) continue;

        $cod  = (string)($p['cod_maquina_mant'] ?? '');
        if ($codFiltro !== '' && $cod !== $codFiltro) continue;
        $desc = (string)($p['desc_maquina'] ?? $cod);

        $esCons = !empty($p['consolidada']);
        if ($esCons) {
            $eff = $p;
        } else {
            $idOv = MaintenancePeriodicidadStore::buildId(
                (string)($p['orden'] ?? ''), (string)($p['tarea'] ?? '')
            );
            $eff = MaintenancePeriodicidadStore::applyOverride(
                $p, $perIdx[$idOv] ?? null
            );
            $idMark = MaintenanceCompletionStore::buildId(
                (string)$eff['orden'], (string)$eff['tarea'], (string)$p['proxima_revision']
            );
            if (isset($marcadasIdx[$idMark])) continue;
        }

        $per = strtoupper((string)($eff['periodicidad'] ?? ''));
        $px  = (string)($eff['proxima_revision'] ?? '');
        if ($px === '') continue;
        if ($usaIntervalo && ($px < $fDesde || $px > $fHasta)) continue;

        // Tiempo total
        $te = 0;
        if ($esCons && !empty($p['sub_tareas'])) {
            foreach ($p['sub_tareas'] as $st) {
                foreach ($proximasPrep as $orig) {
                    if ((string)$orig['cod_maquina_mant'] === $cod
                     && (string)$orig['orden'] === (string)$st['orden']
                     && (string)$orig['tarea'] === (string)$st['tarea']) {
                        $te += (int)($orig['tiempo_estimado'] ?? 0);
                        break;
                    }
                }
            }
        } else {
            $te = isset($eff['tiempo_estimado']) ? (int)$eff['tiempo_estimado'] : 0;
        }

        $diff = (int)round((strtotime($px) - strtotime($hoy)) / 86400);
        $gap  = _gapVenc($per);
        $estado = 'en_plazo';
        if ($diff < -$gap)    $estado = 'vencida';
        elseif ($diff <= 10)  $estado = 'urgente';

        $tarea = $esCons
            ? 'Revisión completa · ' . count($p['sub_tareas'] ?? []) . ' tareas'
            : (string)($eff['tarea'] ?? '');
        // Para tareas consolidadas (RACK/PLATAFORMA/TROLEY) la fila NO tiene
        // descripción propia; las descripciones REALES viven en cada
        // sub_tarea. Las concatenamos formando una lista (una sub-tarea por
        // línea) para que el operario sepa qué tiene que hacer aunque vea
        // una sola fila.
        if ($esCons) {
            $partes = [];
            foreach (($p['sub_tareas'] ?? []) as $st) {
                $codT = (string)($st['tarea'] ?? '');
                $dT   = trim((string)($st['desc_tarea'] ?? ''));
                if ($dT === '') continue;
                $partes[] = '• [' . $codT . '] ' . $dT;
            }
            $descTarea = implode("\n", $partes);
        } else {
            $descTarea = (string)($eff['desc_tarea'] ?? '');
        }

        if (!isset($byMaq[$cod])) {
            $byMaq[$cod] = [
                'cod' => $cod, 'desc' => $desc,
                'n' => 0, 'min' => 0, 'tareas' => [],
            ];
        }
        $byMaq[$cod]['n']++;
        $byMaq[$cod]['min'] += $te;
        $byMaq[$cod]['tareas'][] = [
            'px' => $px, 'diff' => $diff, 'estado' => $estado,
            'per' => $per, 'tarea' => $tarea, 'desc' => $descTarea,
            'te'  => $te,
        ];
    }

    // Ordenar: máquinas por nombre, tareas por fecha asc.
    uasort($byMaq, fn($a, $b) => strcasecmp($a['desc'], $b['desc']));
    foreach ($byMaq as &$m) {
        usort($m['tareas'], fn($a, $b) => strcmp($a['px'], $b['px']));
    }
    unset($m);

    // ── Construir Excel ──────────────────────────────────────────────
    $book = new Spreadsheet();
    $book->getProperties()->setCreator('KH Mantenimiento')
        ->setTitle('Plan de Próximas Revisiones');

    // Paleta minimalista
    $C_TXT      = '1A2D4A';   // texto principal (azul muy oscuro casi negro)
    $C_TXT_SUB  = '5B6F86';   // texto secundario / gris azulado
    $C_FILA_MAQ = 'F0F3F7';   // gris-azulado MUY claro para separar máquinas
    $C_BORDE    = 'E0E5EB';   // borde gris claro
    $C_AZUL_HDR = '2D4D7A';   // azul corporativo de cabeceras (el de siempre)
    $C_VENC     = 'C8102E';
    $C_URG      = 'B45309';
    $C_OK       = '1F8A3C';

    // ════════════════════════════════════════════════════════════════
    // Hoja 1 (PRINCIPAL) · "Seguimiento" — formato F12028
    //   Lista plana ordenada cronológicamente. La hoja "Plan" antigua
    //   queda suprimida; toda la información cabe aquí y en la nueva
    //   "Tiempos por máquina" para planificar paradas.
    // ════════════════════════════════════════════════════════════════
    $ws3 = $book->getActiveSheet();
    $ws3->setTitle('Seguimiento');

    // Helper: día de la semana abreviado en español
    $diasSem = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    $diaAbr = function (string $ymd) use ($diasSem): string {
        $ts = strtotime($ymd);
        // PHP 'N' devuelve 1=lunes ... 7=domingo
        return $diasSem[(int)date('N', $ts) - 1] ?? '';
    };

    // Aplanar todas las tareas y contar vencidas / próximas
    $todas = [];
    $nVenc = 0; $nProx = 0;
    foreach ($byMaq as $m) {
        foreach ($m['tareas'] as $t) {
            $todas[] = $t + ['cod' => $m['cod'], 'desc_maq' => $m['desc']];
            if ($t['estado'] === 'vencida') $nVenc++;
            elseif ($t['estado'] === 'urgente') $nProx++;
        }
    }
    // Orden cronológico
    usort($todas, fn($a, $b) => strcmp($a['px'], $b['px']));

    // Fila 1 — Título estilo plantilla F12028 sobre fondo azul corporativo
    $ws3->setCellValue('A1', 'F12028_Seguimiento y asignación tareas de mantenimiento');
    $ws3->mergeCells('A1:I1');
    $ws3->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('FFFFFF');
    $ws3->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($C_AZUL_HDR);
    $ws3->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $ws3->getRowDimension(1)->setRowHeight(28);

    // Fila 2 — Línea de filtros (similar al ejemplo de plantilla)
    $linea = 'Filtros: ';
    if ($usaIntervalo) {
        $diff = (int)round((strtotime($fHasta) - strtotime($fDesde)) / 86400) + 1;
        $linea .= "Rango " . date('d/m/Y', strtotime($fDesde)) . " → "
                . date('d/m/Y', strtotime($fHasta)) . " ($diff días)";
    } else {
        $linea .= 'Sin rango específico';
    }
    if ($codFiltro !== '') $linea .= '  ·  Máquina: ' . $codFiltro;
    $totalListadas = count($todas);
    $linea .= "  ·  Tareas listadas: $totalListadas"
            . " ($nVenc vencidas + $nProx próximas)";
    $linea .= '  ·  Exportado: ' . date('d/m/Y H:i');
    $ws3->setCellValue('A2', $linea);
    $ws3->mergeCells('A2:I2');
    $ws3->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB($C_TXT_SUB);
    $ws3->getRowDimension(2)->setRowHeight(18);

    // Fila 4 — Cabecera de columnas
    $hdr3 = ['Fecha', 'Día', 'Máquina', 'Tarea', 'Periodicidad',
             'Tiempo (min)', 'Descripción', 'Estado', 'Operario / Observaciones'];
    foreach ($hdr3 as $i => $h) {
        $ws3->setCellValue([$i + 1, 4], $h);
    }
    $ws3->getStyle('A4:I4')->getFont()->setBold(true)->setSize(10)->getColor()->setRGB($C_TXT);
    $ws3->getStyle('A4:I4')->getFill()->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB($C_FILA_MAQ);
    $ws3->getStyle('A4:I4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws3->getStyle('A4:I4')->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($C_BORDE);
    $ws3->getRowDimension(4)->setRowHeight(22);

    // Filas 5+ — Una por tarea
    $rowS = 5;
    foreach ($todas as $t) {
        $estado = ['vencida'=>'VENCIDA', 'urgente'=>'PRÓXIMA', 'en_plazo'=>'EN PLAZO'][$t['estado']] ?? '';
        $color  = ['vencida'=>$C_VENC,   'urgente'=>$C_URG,     'en_plazo'=>$C_OK][$t['estado']] ?? '999999';

        $ws3->setCellValue("A$rowS", date('d/m/Y', strtotime($t['px'])));
        $ws3->getStyle("A$rowS")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $ws3->setCellValue("B$rowS", $diaAbr($t['px']));
        $ws3->getStyle("B$rowS")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $ws3->setCellValue("C$rowS", $t['desc_maq']);
        $ws3->setCellValue("D$rowS", $t['tarea']);
        $ws3->getStyle("D$rowS")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $ws3->setCellValue("E$rowS", $t['per']);
        $ws3->getStyle("E$rowS")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Tiempo en minutos como número (no como texto "X min" para que
        // se pueda sumar fácilmente con fórmulas en Excel).
        $ws3->setCellValue("F$rowS", (int)$t['te']);
        $ws3->getStyle("F$rowS")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $ws3->setCellValue("G$rowS", $t['desc']);
        $ws3->getStyle("G$rowS")->getAlignment()->setWrapText(true)
            ->setVertical(Alignment::VERTICAL_TOP);

        $ws3->setCellValue("H$rowS", $estado);
        $ws3->getStyle("H$rowS")->getFont()->setBold(true)->getColor()->setRGB($color);
        $ws3->getStyle("H$rowS")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Columna I vacía para que el operario apunte a mano
        $ws3->setCellValue("I$rowS", '');

        // Alto de fila adaptable a la descripción (mínimo 22 px)
        $lineas = max(1, (int)ceil(mb_strlen($t['desc']) / 80));
        $ws3->getRowDimension($rowS)->setRowHeight(max(22, 14 + $lineas * 13));
        $rowS++;
    }

    // Línea final con TOTAL de minutos (útil para planificación)
    if ($rowS > 5) {
        $ws3->setCellValue("E$rowS", 'TOTAL minutos');
        $ws3->setCellValue("F$rowS", "=SUM(F5:F" . ($rowS - 1) . ")");
        $ws3->getStyle("E$rowS:F$rowS")->getFont()->setBold(true);
        $ws3->getStyle("E$rowS:F$rowS")->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($C_FILA_MAQ);
        $ws3->getStyle("F$rowS")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    // Bordes finos en toda la tabla
    if ($rowS > 5) {
        $ws3->getStyle("A5:I" . ($rowS - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB($C_BORDE);
    }

    // Anchos de columna
    $ws3->getColumnDimension('A')->setWidth(11);   // Fecha
    $ws3->getColumnDimension('B')->setWidth(6);    // Día
    $ws3->getColumnDimension('C')->setWidth(22);   // Máquina
    $ws3->getColumnDimension('D')->setWidth(9);    // Tarea
    $ws3->getColumnDimension('E')->setWidth(13);   // Periodicidad
    $ws3->getColumnDimension('F')->setWidth(11);   // Tiempo
    $ws3->getColumnDimension('G')->setWidth(70);   // Descripción
    $ws3->getColumnDimension('H')->setWidth(11);   // Estado
    $ws3->getColumnDimension('I')->setWidth(26);   // Operario / Observ.

    $ws3->freezePane('A5');
    $ws3->setAutoFilter('A4:I4');                  // filtros nativos de Excel
    $ws3->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setFitToWidth(1)->setFitToHeight(0);

    // ════════════════════════════════════════════════════════════════
    // Hoja 2 · "Tiempos por máquina"
    //   Resumen de cuánto tiempo hay que tener PARADA cada máquina
    //   para hacer todas sus tareas pendientes del intervalo. Pensado
    //   para repartir carga de trabajo entre departamentos / equipos:
    //   con esta tabla se ve de un vistazo qué máquinas requieren más
    //   minutos de parada y se pueden agrupar por familia.
    //
    //   Columnas:
    //     Máquina · Código · Nº tareas · Tiempo (min) · Tiempo (h:mm)
    //
    //   Las máquinas van ordenadas por TIEMPO total descendente, así
    //   las más costosas aparecen arriba. Una fila de TOTAL al final
    //   suma todos los minutos.
    // ════════════════════════════════════════════════════════════════
    $ws4 = $book->createSheet();
    $ws4->setTitle('Tiempos por máquina');

    // Reordenar máquinas por tiempo DESC para esta hoja (sin afectar
    // a la "Seguimiento" que las quiere por orden alfabético).
    $maqTiempo = $byMaq;
    uasort($maqTiempo, fn($a, $b) => $b['min'] - $a['min']);

    // Fila 1 — Título azul
    $ws4->setCellValue('A1', 'Tiempos de parada por máquina');
    $ws4->mergeCells('A1:E1');
    $ws4->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('FFFFFF');
    $ws4->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($C_AZUL_HDR);
    $ws4->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);
    $ws4->getRowDimension(1)->setRowHeight(26);

    // Fila 2 — Contexto
    $nM = count($maqTiempo);
    $mAcum = array_sum(array_map(fn($m) => $m['min'], $maqTiempo));
    $nT = array_sum(array_map(fn($m) => $m['n'], $maqTiempo));
    $sub = $nM . ' máquinas · ' . $nT . ' tareas · '
         . _fmtMin($mAcum) . ' de parada acumulada';
    if ($usaIntervalo) {
        $sub = date('d/m/Y', strtotime($fDesde))
             . ' → ' . date('d/m/Y', strtotime($fHasta))
             . '  ·  ' . $sub;
    }
    $ws4->setCellValue('A2', $sub);
    $ws4->mergeCells('A2:E2');
    $ws4->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB($C_TXT_SUB);
    $ws4->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Fila 4 — Cabecera
    $hdr4 = ['Máquina', 'Código', 'Nº tareas', 'Tiempo (min)', 'Tiempo (h:mm)'];
    foreach ($hdr4 as $i => $h) $ws4->setCellValue([$i + 1, 4], $h);
    $ws4->getStyle('A4:E4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $ws4->getStyle('A4:E4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($C_AZUL_HDR);
    $ws4->getStyle('A4:E4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $ws4->getStyle('A4:E4')->getBorders()->getAllBorders()
        ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($C_BORDE);
    $ws4->getRowDimension(4)->setRowHeight(22);

    // Filas 5+ — Una máquina por fila
    $r4 = 5;
    foreach ($maqTiempo as $m) {
        $ws4->setCellValue("A$r4", $m['desc']);
        $ws4->setCellValue("B$r4", $m['cod']);
        $ws4->getStyle("B$r4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws4->setCellValue("C$r4", (int)$m['n']);
        $ws4->getStyle("C$r4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws4->setCellValue("D$r4", (int)$m['min']);
        $ws4->getStyle("D$r4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $ws4->setCellValue("E$r4", _fmtMin($m['min']));
        $ws4->getStyle("E$r4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws4->getRowDimension($r4)->setRowHeight(20);
        $r4++;
    }

    // Fila TOTAL al final
    if ($r4 > 5) {
        $ws4->setCellValue("A$r4", 'TOTAL');
        $ws4->setCellValue("C$r4", "=SUM(C5:C" . ($r4 - 1) . ")");
        $ws4->setCellValue("D$r4", "=SUM(D5:D" . ($r4 - 1) . ")");
        // h:mm calculado a partir del total de minutos
        $ws4->setCellValue("E$r4", "=TEXT(INT(D$r4/60),\"0\")&\" h \"&TEXT(MOD(D$r4,60),\"0\")&\" min\"");
        $ws4->getStyle("A$r4:E$r4")->getFont()->setBold(true)->getColor()->setRGB($C_TXT);
        $ws4->getStyle("A$r4:E$r4")->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($C_FILA_MAQ);
        $ws4->getStyle("A$r4:E$r4")->getBorders()->getTop()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB($C_TXT);
        $ws4->getStyle("C$r4:E$r4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws4->getRowDimension($r4)->setRowHeight(22);
    }

    // Bordes finos en datos
    if ($r4 > 5) {
        $ws4->getStyle("A5:E" . ($r4 - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB($C_BORDE);
    }

    // Anchos
    $ws4->getColumnDimension('A')->setWidth(36);
    $ws4->getColumnDimension('B')->setWidth(14);
    $ws4->getColumnDimension('C')->setWidth(12);
    $ws4->getColumnDimension('D')->setWidth(14);
    $ws4->getColumnDimension('E')->setWidth(16);
    $ws4->freezePane('A5');
    $ws4->setAutoFilter('A4:E4');
    $ws4->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT)
        ->setFitToWidth(1)->setFitToHeight(0);

    // Aseguramos que la hoja activa al abrir es la "Seguimiento" (la 0).
    $book->setActiveSheetIndex(0);

    // ── Salida ───────────────────────────────────────────────────────
    $rangoTag = $usaIntervalo
        ? '_' . str_replace('-', '', $fDesde) . '-' . str_replace('-', '', $fHasta)
        : '';
    $tag = $codFiltro !== ''
        ? '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $codFiltro)
        : '';
    $fileName = 'plan_revisiones' . $tag . $rangoTag . '_' . date('Ymd_His') . '.xlsx';

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
    echo 'Error al generar el XLSX: ' . $e->getMessage();
}
