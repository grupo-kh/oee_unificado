<?php
/**
 * API: Grid Plan vs Producido (desglose HORARIO del turno seleccionado)
 *
 * Entrada:
 *   fecha : YYYY-MM-DD (día productivo)
 *   turno : M | T | N
 *
 * Salida:
 * {
 *   fecha: '2026-04-21',
 *   turno: 'T',
 *   horas: [ {hora: 14, label: '14:00'}, ..., {hora: 21, label: '21:00'} ],
 *   filas: [
 *     { maquina, cod_articulo,
 *       plan: {14: 343, 15: 457, ...},
 *       prod: {14: 335, 15: 353, ...} },
 *     ...
 *   ]
 * }
 *
 * Modelo de cálculo:
 *   - Plan por hora = Σ (Rendimientonominal1 × Factor_multiplicativo) × (segundos trabajados en esa hora / 3600),
 *     uniendo his_prod con su his_fase. Así las horas parciales de inicio/fin salen proporcionales.
 *   - Prod por hora = Σ his_prod.Unidades_ok agrupado por DATEPART(HOUR, Fecha_ini).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PanelMetaBuilder.php';

try {
    $fecha = getParam('fecha', date('Y-m-d'));
    $turno = getParam('turno', 'T');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');
    if (!in_array($turno, ['M', 'T', 'N', 'C'], true)) jsonError('turno inválido (M/T/N/C)');

    // Configuración de turnos (hh:mm inicio - hh:mm fin).
    // Los márgenes de 15 min replican la configuración de QlikView.
    // Convención: la fecha productiva = día de FIN del turno (NOCHE empieza el día anterior).
    $shiftConfig = [
        'M' => ['start' => '06:00', 'end' => '14:15'],  // MAÑANA
        'T' => ['start' => '14:15', 'end' => '22:30'],  // TARDE
        'N' => ['start' => '22:30', 'end' => '06:00'],  // NOCHE (empieza día anterior)
        'C' => ['start' => '08:00', 'end' => '17:00'],  // CENTRAL
    ][$turno];

    if ($turno === 'N') {
        $dtStart = new DateTime($fecha . ' ' . $shiftConfig['start'] . ':00');
        $dtStart->modify('-1 day');
        $dtEnd = new DateTime($fecha . ' ' . $shiftConfig['end'] . ':00');
    } else {
        $dtStart = new DateTime($fecha . ' ' . $shiftConfig['start'] . ':00');
        $dtEnd = new DateTime($fecha . ' ' . $shiftConfig['end'] . ':00');
    }

    // Generar slots de 1 hora alineados a inicio/fin de hora.
    // Si el inicio/fin del turno no está alineado (ej. 14:15), el primer o último slot
    // es parcial (menor a 60 min).
    $slots = [];
    $cursor = clone $dtStart;
    while ($cursor < $dtEnd) {
        // Límite superior del slot = siguiente frontera de hora o fin del turno
        $next = new DateTime($cursor->format('Y-m-d H:00:00'));
        $next->modify('+1 hour');
        if ($next > $dtEnd) $next = clone $dtEnd;
        $slots[] = [
            'hora'  => (int)$cursor->format('G'),
            'ini'   => $cursor->format('Y-m-d H:i:s'),
            'fin'   => $next->format('Y-m-d H:i:s'),
            'label' => $cursor->format('H:i'),
            'fecha' => $cursor->format('d/m/Y'),
        ];
        $cursor = $next;
    }
    $rangoIni = $slots[0]['ini'];
    $rangoFin = end($slots)['fin'];

    // VALUES clause con los slots (compartido por prod y plan)
    $valuesSlots = [];
    $paramsSlots = [];
    foreach ($slots as $s) {
        $valuesSlots[] = "(?, ?, ?)";
        array_push($paramsSlots, $s['ini'], $s['fin'], $s['hora']);
    }
    $valuesSql = implode(',', $valuesSlots);

    $excluidas = "('Improductivos','AUX000','SOLD5','AUXI1','SOLD4')";

    // ============ PRODUCCIÓN REAL por hora (prorrateada por solape temporal) ============
    // Cada registro de his_prod tiene Fecha_ini/Fecha_fin; sus Unidades_ok se
    // reparten proporcionalmente entre los slots horarios del turno según el solape.
    // Esto replica el comportamiento de QlikView.
    // Remapeos de máquina estilo QW (Map_Maqina_Planificacion):
    //   DOBL6 + DOBL7 (BT 3.4 DCHA/IZQDA) se consolidan como 'BT' — el plan del Excel usa 'BT'.
    $sqlProd = "
        WITH slots(ini, fin, hora) AS (
            SELECT CAST(V.c1 AS DATETIME), CAST(V.c2 AS DATETIME), CAST(V.c3 AS INT)
            FROM (VALUES $valuesSql) AS V(c1, c2, c3)
        )
        SELECT
            CASE WHEN mq.Cod_maquina IN ('DOBL6','DOBL7') THEN 'BT'
                 ELSE mq.Desc_maquina END AS maquina,
            pr.Cod_producto AS cod_articulo,
            s.hora          AS hora,
            SUM(
                CAST(ISNULL(p.Unidades_ok, 0) AS FLOAT) *
                DATEDIFF(SECOND,
                    CASE WHEN p.Fecha_ini > s.ini THEN p.Fecha_ini ELSE s.ini END,
                    CASE WHEN ISNULL(p.Fecha_fin, p.Fecha_ini) < s.fin THEN ISNULL(p.Fecha_fin, p.Fecha_ini) ELSE s.fin END
                ) / NULLIF(DATEDIFF(SECOND, p.Fecha_ini, ISNULL(p.Fecha_fin, p.Fecha_ini)), 0)
            ) AS producido
        FROM his_prod p
        INNER JOIN slots s
              ON p.Fecha_ini < s.fin
             AND ISNULL(p.Fecha_fin, p.Fecha_ini) > s.ini
        LEFT JOIN his_fase     fa ON fa.Id_his_fase = p.Id_his_fase
        LEFT JOIN his_of       o  ON o.Id_his_of    = fa.Id_his_of
        LEFT JOIN cfg_maquina  mq ON mq.Id_maquina  = p.Id_maquina
        LEFT JOIN cfg_producto pr ON pr.Id_producto = o.Id_producto
        WHERE mq.Cod_maquina NOT IN $excluidas
          AND pr.Cod_producto IS NOT NULL
        GROUP BY
            CASE WHEN mq.Cod_maquina IN ('DOBL6','DOBL7') THEN 'BT'
                 ELSE mq.Desc_maquina END,
            pr.Cod_producto, s.hora
    ";
    $paramsProd = $paramsSlots;
    $rowsProd = fetchAll('mapex', $sqlProd, $paramsProd);

    // ============ PLAN por hora ============
    // Se lee de los Excel de planificación diaria (carpeta configurada en .env:
    // EXCEL_BASE_PATH) replicando
    // la lógica de QlikView (hoja PLANIFICACIÓN: top section + cross-table de slots
    // 30 min desde 14:15). El parser cachea el resultado en JSON.
    require_once __DIR__ . '/../lib/PlanExcelReader.php';
    ini_set('memory_limit', '2G');
    try {
        $planesExcel = PlanExcelReader::getPlanPorHora($fecha, $turno, $slots);
    } catch (\Throwable $e) {
        // Si falla la lectura (red, Excel corrupto…) no rompemos la vista; el plan queda vacío.
        error_log('PlanExcelReader: ' . $e->getMessage());
        $planesExcel = [];
    }
    // Adaptar al formato que usa el código aguas abajo
    $rowsPlan = [];
    foreach ($planesExcel as $p) {
        $rowsPlan[] = [
            'maquina'      => $p['maquina'],
            'cod_articulo' => $p['cod_articulo'],
            'hora'         => $p['hora'],
            'planificado'  => $p['ud'],
        ];
    }

    // ============ CONSOLIDAR en formato pivote ============
    $pivot = [];
    foreach ($rowsProd as $r) {
        $key = $r['maquina'] . '|' . $r['cod_articulo'];
        if (!isset($pivot[$key])) {
            $pivot[$key] = [
                'maquina' => $r['maquina'], 'cod_articulo' => $r['cod_articulo'],
                'plan' => [], 'prod' => []
            ];
        }
        $pivot[$key]['prod'][(int)$r['hora']] = (int)$r['producido'];
    }
    foreach ($rowsPlan as $r) {
        $key = $r['maquina'] . '|' . $r['cod_articulo'];
        if (!isset($pivot[$key])) {
            $pivot[$key] = [
                'maquina' => $r['maquina'], 'cod_articulo' => $r['cod_articulo'],
                'plan' => [], 'prod' => []
            ];
        }
        $pivot[$key]['plan'][(int)$r['hora']] = (int)round((float)$r['planificado']);
    }

    $filas = array_values($pivot);
    usort($filas, function($a, $b) {
        $c = strcmp($a['maquina'], $b['maquina']);
        return $c !== 0 ? $c : strcmp($a['cod_articulo'], $b['cod_articulo']);
    });

    $horas = array_map(function($s) {
        return ['hora' => $s['hora'], 'label' => $s['label'], 'fecha' => $s['fecha']];
    }, $slots);

    $meta = PanelMetaBuilder::buildPlanProdMeta([
        'panel'        => 'Grid Plan vs Producido (desglose horario)',
        'fechaDesde'   => $fecha,
        'fechaHasta'   => $fecha,
        'turnos'       => [$turno],
        'whitelist'    => "TODAS las máquinas activas (excluyendo Cod_maquina IN ('Improductivos','AUX000','SOLD5','AUXI1','SOLD4')). Vista diagnóstica que muestra también máquinas de soporte (PRENSA 3D N2, TBE30, TBE35, TBE RAPIDFORM, MONTAJE AUTOMATICO…) que QV oculta.",
        'includeFormula' => false,
        'includeFuzzy'   => false,
        'extras' => [
            [
                'titulo' => 'Notas específicas de este panel',
                'notas'  => [
                    'Plan por hora: Σ unidades del pedido × (segundos del slot solapados con el orden ÷ duración total del orden en cross-table).',
                    'Prod por hora: Unidades_ok prorrateadas por el solape de la fase de his_prod con el slot horario.',
                    'Las celdas verde/amarillo/rojo dependen del % producido sobre planificado por hora y artículo.',
                    'BT 3.4 DCHA y BT 3.4 IZQDA (DOBL6 + DOBL7) se consolidan como "BT" (igual que QV).',
                ],
            ],
        ],
    ]);

    jsonOk([
        'fecha' => $fecha,
        'turno' => $turno,
        'horas' => $horas,
        'filas' => $filas,
        'meta'  => $meta,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
