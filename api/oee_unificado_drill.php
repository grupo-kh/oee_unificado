<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Drill-down OEE Unificado: desglose por máquina + motivos para una sección y métrica.
 *
 * Parámetros:
 *   - fecha_desde, fecha_hasta (YYYY-MM-DD)
 *   - turnos (CSV: M,T,N) — vacío = todos
 *   - seccion (VARILLAS | TROQUELADOS)
 *   - metrica (disponibilidad | rendimiento | calidad | oee)
 *   - cod_maquina (opcional) — filtra motivos por esa máquina
 *
 * Devuelve:
 *   - maquinas: [{ cod_maquina, maquina, valor }, ...] ordenado ASC (peores primero)
 *   - motivos:  [{ motivo, valor, pct, pct_acum }, ...] ordenado DESC (mayores primero)
 */

function _seccion(?string $desc): ?string {
    if ($desc === null) return null;
    return PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc] ?? null;
}

function _drc(float $M, float $MT, float $MOT, float $MOKT, float $PP, float $PC, float $PNP): array {
    $d   = ($M + $PNP)      > 0 ? $M / ($M + $PNP) * 100              : 0;
    $r   = ($M + $PP + $PC) > 0 ? ($MOT + $PC) / ($M + $PP + $PC) * 100 : 0;
    $c   = ($MOT + $PC)     > 0 ? $MOKT / ($MOT + $PC) * 100           : 0;
    $oee = $d * $r * $c / 10000;
    return [
        'disponibilidad' => round($d, 2),
        'rendimiento'    => round($r, 2),
        'calidad'        => round($c, 2),
        'oee'            => round($oee, 2),
    ];
}

try {
    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');
    $seccion = (string) getParam('seccion');
    $metrica = (string) getParam('metrica');
    $codMaq  = getParam('cod_maquina');
    // Filtro opcional por referencia (Cod_producto) para el paso intermedio
    // "clic en máquina/referencia → motivos filtrados". Solo afecta a Disponibilidad/OEE.
    $codRef  = isset($_GET['cod_referencia']) ? trim((string)$_GET['cod_referencia']) : '';
    // Segmentación del chart superior: 'maquina' (default, valor de la métrica) |
    // 'referencia' (horas de paro acumuladas por referencia). Aplica a disp/oee.
    $por = (string) ($_GET['por'] ?? 'maquina');
    if (!in_array($por, ['maquina', 'referencia'], true)) $por = 'maquina';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    if (!in_array($seccion, ['VARILLAS', 'TROQUELADOS'], true)) jsonError('seccion inválida');
    if (!in_array($metrica, ['disponibilidad', 'rendimiento', 'calidad', 'oee'], true)) jsonError('metrica inválida');

    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));
    $excl   = getListParam('excl');

    // ───── 1) Máquinas: OEE components por máquina en la sección ─────
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
        SELECT
            oee.WorkGroup        AS cod_maquina,
            mq.Desc_maquina      AS maquina,
            SUM(oee.M)           AS M,
            SUM(oee.M_Teo)       AS M_Teo,
            SUM(oee.M_OKNOK_TEO) AS M_OKNOK_TEO,
            SUM(oee.M_OK_TEO)    AS M_OK_TEO,
            SUM(oee.PPERF)       AS PPERF,
            SUM(oee.PCALIDAD)    AS PCALIDAD,
            SUM(oee.PNP)         AS PNP
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
        WHERE $whereSQL
        GROUP BY oee.WorkGroup, mq.Desc_maquina
        HAVING SUM(oee.M) + SUM(oee.PNP) > 0
    ";
    $allParams = array_merge([$fdesde, $fhasta], $params);
    $rows = fetchAll('mapex', $sql, $allParams);

    $maquinas = [];
    $codMaqsSeccion = []; // cod_maquina values that belong to the section
    foreach ($rows as $r) {
        $sec = _seccion($r['maquina']);
        if ($sec !== $seccion) continue;

        $codMaqsSeccion[] = $r['cod_maquina'];

        $drc = _drc(
            (float)$r['M'], (float)$r['M_Teo'], (float)$r['M_OKNOK_TEO'],
            (float)$r['M_OK_TEO'], (float)$r['PPERF'], (float)$r['PCALIDAD'], (float)$r['PNP']
        );
        $maquinas[] = [
            'cod_maquina' => $r['cod_maquina'],
            'maquina'     => $r['maquina'] ?: $r['cod_maquina'],
            'valor'       => $drc[$metrica],
        ];
    }
    // Sort ASC (peores primero)
    usort($maquinas, fn($a, $b) => $a['valor'] <=> $b['valor']);

    // Si la segmentación pedida es "referencia" (solo para disponibilidad/oee),
    // sustituimos el listado superior por referencias con horas de paro acumuladas.
    // Las "maquinas" se mantienen como nombre del campo en la respuesta para
    // reutilizar el render del cliente sin duplicar lógica.
    if ($por === 'referencia' && in_array($metrica, ['disponibilidad', 'oee'], true)) {
        $maquinas = _refsParos($fdesde, $fhasta, $turnos, $codMaqsSeccion);
    }

    // ───── 2) Motivos según la métrica ─────
    $motivos = [];

    if (in_array($metrica, ['disponibilidad', 'oee'], true)) {
        // Paros: his_prod_paro agrupados por motivo (opcionalmente filtrados por máquina o referencia)
        $motivos = _motivosParos($fdesde, $fhasta, $turnos, $codMaqsSeccion, $codMaq, $codRef ?: null);
    } elseif ($metrica === 'calidad') {
        // Rechazos: his_prod_defecto agrupados por defecto
        $motivos = _motivosCalidad($fdesde, $fhasta, $turnos, $codMaqsSeccion, $codMaq);
    } elseif ($metrica === 'rendimiento') {
        // Pérdidas rendimiento por artículo (desde F_his_ct)
        $motivos = _motivosRendimiento($fdesde, $fhasta, $turnos, $codMaqsSeccion, $codMaq);
    }

    jsonOk([
        'seccion'     => $seccion,
        'metrica'     => $metrica,
        'por'         => $por,
        'cod_maquina' => $codMaq ?: null,
        'maquinas'    => $maquinas,
        'motivos'     => $motivos,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}

// ───── Funciones de motivos ─────

function _motivosParos(string $fdesde, string $fhasta, array $turnos, array $codMaqs, ?string $codMaq, ?string $codRef = null): array
{
    if (empty($codMaqs)) return [];

    $where = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11", // excluir CERRADA
        "hpp.Fecha_fin IS NOT NULL",
    ];
    $params = [$fdesde, $fhasta];

    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    // Filter by section machines (or specific machine)
    if ($codMaq && in_array($codMaq, $codMaqs, true)) {
        $where[] = "mq.Cod_maquina = ?";
        $params[] = $codMaq;
    } else {
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "mq.Cod_maquina IN ($ph)";
        $params = array_merge($params, $codMaqs);
    }

    // Filtro opcional por referencia (Cod_producto): requiere la cadena de joins
    $extraJoin = '';
    if ($codRef !== null && $codRef !== '') {
        $extraJoin = "
            LEFT JOIN his_fase     fa   ON fa.Id_his_fase  = hp.Id_his_fase
            LEFT JOIN his_of       o    ON o.Id_his_of     = fa.Id_his_of
            LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        ";
        $where[]  = "prod.Cod_producto = ?";
        $params[] = $codRef;
    }

    $whereSQL = implode(' AND ', $where);
    $sql = "
        SELECT
            cp.Desc_paro AS motivo,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro cp ON cp.Id_paro = hpp.Id_paro
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        $extraJoin
        WHERE $whereSQL
        GROUP BY cp.Desc_paro
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY segundos DESC
    ";
    $rows = fetchAll('mapex', $sql, $params);

    $totSeg = 0;
    foreach ($rows as $r) $totSeg += (int)$r['segundos'];

    $out = [];
    $acum = 0;
    foreach ($rows as $r) {
        $seg = (int)$r['segundos'];
        $pct = $totSeg > 0 ? $seg / $totSeg * 100 : 0;
        $acum += $pct;
        $out[] = [
            'motivo'   => $r['motivo'] ?: '(sin nombre)',
            'horas'    => round($seg / 3600, 2),
            'minutos'  => round($seg / 60, 1),
            'pct'      => round($pct, 2),
            'pct_acum' => round(min($acum, 100), 2),
        ];
    }
    return $out;
}

/**
 * Listado superior cuando el toggle Máquina/Referencia está en "Referencia":
 * agrega TODOS los paros (cualquier motivo) por referencia producida y devuelve
 * el listado con horas acumuladas como "valor". El campo cod_maquina/maquina se
 * usa para que el render del cliente lo trate como una "fila" más sin duplicar
 * código. Sólo entran paros con producto identificado (no '--').
 */
function _refsParos(string $fdesde, string $fhasta, array $turnos, array $codMaqs): array
{
    if (empty($codMaqs)) return [];

    $where = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "hpp.Fecha_fin IS NOT NULL",
        "prod.Cod_producto IS NOT NULL",
        "prod.Cod_producto <> '--'",
    ];
    $params = [$fdesde, $fhasta];

    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }
    $ph = implode(',', array_fill(0, count($codMaqs), '?'));
    $where[] = "mq.Cod_maquina IN ($ph)";
    $params = array_merge($params, $codMaqs);

    $sql = "
        SELECT
            prod.Cod_producto       AS cod_referencia,
            MAX(prod.Desc_producto) AS referencia,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro     cp   ON cp.Id_paro      = hpp.Id_paro
        INNER JOIN his_prod     hp   ON hp.Id_his_prod  = hpp.Id_his_prod
        INNER JOIN cfg_maquina  mq   ON mq.Id_maquina   = hp.Id_maquina
        INNER JOIN cfg_turno    ct   ON ct.Id_turno     = hp.Id_turno
        LEFT  JOIN his_fase     fa   ON fa.Id_his_fase  = hp.Id_his_fase
        LEFT  JOIN his_of       o    ON o.Id_his_of     = fa.Id_his_of
        LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        WHERE " . implode(' AND ', $where) . "
        GROUP BY prod.Cod_producto
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY segundos DESC
    ";
    $rows = fetchAll('mapex', $sql, $params);

    $out = [];
    foreach ($rows as $r) {
        $seg = (int) $r['segundos'];
        $cod = (string) $r['cod_referencia'];
        $des = (string) ($r['referencia'] ?: $cod);
        $out[] = [
            // Reusa los campos cod_maquina/maquina para compatibilidad con el render
            'cod_maquina'    => $cod,
            'maquina'        => $des,
            'cod_referencia' => $cod,
            'referencia'     => $des,
            'valor'          => round($seg / 3600, 2), // horas
        ];
    }
    return $out;
}

function _motivosCalidad(string $fdesde, string $fhasta, array $turnos, array $codMaqs, ?string $codMaq): array
{
    if (empty($codMaqs)) return [];

    $where = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "hpd.Activo = 1",
        "df.esNOK = 1",
    ];
    $params = [$fdesde, $fhasta];

    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    if ($codMaq && in_array($codMaq, $codMaqs, true)) {
        $where[] = "mq.Cod_maquina = ?";
        $params[] = $codMaq;
    } else {
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "mq.Cod_maquina IN ($ph)";
        $params = array_merge($params, $codMaqs);
    }

    $whereSQL = implode(' AND ', $where);
    $sql = "
        SELECT
            df.Desc_defecto AS motivo,
            SUM(hpd.Unidades) AS unidades
        FROM his_prod_defecto hpd
        INNER JOIN cfg_defecto df ON df.Id_defecto = hpd.Id_defecto
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpd.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        WHERE $whereSQL
        GROUP BY df.Desc_defecto
        HAVING SUM(hpd.Unidades) > 0
        ORDER BY unidades DESC
    ";
    $rows = fetchAll('mapex', $sql, $params);

    $totU = 0;
    foreach ($rows as $r) $totU += (int)$r['unidades'];

    $out = [];
    $acum = 0;
    foreach ($rows as $r) {
        $u = (int)$r['unidades'];
        $pct = $totU > 0 ? $u / $totU * 100 : 0;
        $acum += $pct;
        $out[] = [
            'motivo'   => $r['motivo'] ?: '(sin nombre)',
            'unidades' => $u,
            'pct'      => round($pct, 2),
            'pct_acum' => round(min($acum, 100), 2),
        ];
    }
    return $out;
}

function _motivosRendimiento(string $fdesde, string $fhasta, array $turnos, array $codMaqs, ?string $codMaq): array
{
    if (empty($codMaqs)) return [];

    $where = [
        "CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?",
        "oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')",
        "oee.Cod_producto IS NOT NULL",
        "oee.Cod_producto <> '--'",
    ];
    $params = [$fdesde, $fhasta];

    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "oee.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    if ($codMaq && in_array($codMaq, $codMaqs, true)) {
        $where[] = "oee.WorkGroup = ?";
        $params[] = $codMaq;
    } else {
        $ph = implode(',', array_fill(0, count($codMaqs), '?'));
        $where[] = "oee.WorkGroup IN ($ph)";
        $params = array_merge($params, $codMaqs);
    }

    $whereSQL = implode(' AND ', $where);
    $sql = "
        SELECT
            oee.Cod_producto AS cod_articulo,
            MAX(oee.Desc_producto) AS motivo,
            SUM(oee.M) - SUM(oee.M_OKNOK_TEO) AS perdida_seg
        FROM F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS',
                      ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
        WHERE $whereSQL
        GROUP BY oee.Cod_producto
        HAVING SUM(oee.M) - SUM(oee.M_OKNOK_TEO) > 0
        ORDER BY perdida_seg DESC
    ";
    $allParams = array_merge([$fdesde, $fhasta], $params);
    $rows = fetchAll('mapex', $sql, $allParams);

    $totSeg = 0;
    foreach ($rows as $r) $totSeg += (float)$r['perdida_seg'];

    $out = [];
    $acum = 0;
    foreach ($rows as $r) {
        $seg = (float)$r['perdida_seg'];
        $pct = $totSeg > 0 ? $seg / $totSeg * 100 : 0;
        $acum += $pct;
        $desc = $r['motivo'] ?: $r['cod_articulo'];
        $out[] = [
            'motivo'   => $desc,
            'horas'    => round($seg / 3600, 2),
            'minutos'  => round($seg / 60, 1),
            'pct'      => round($pct, 2),
            'pct_acum' => round(min($acum, 100), 2),
        ];
    }
    return $out;
}
