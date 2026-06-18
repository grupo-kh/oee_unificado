<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Matriz 2 — Paros por referencia, en matriz Actividad × Tipo de Paro.
 *
 * Para cada referencia producida en el rango/sección/turnos, agrega las horas de
 * paro (his_prod_paro) cruzando con la clasificación de MAPEX:
 *   - cfg_paro     → Desc_paro (tipo de paro) + Id_actividad
 *   - cfg_actividad → Desc_actividad (Preparación, Producción, Mantenimiento…)
 *   - cfg_maquina  → tipo de máquina (Id_tipomaquina) y sección
 *
 * El árbol de clasificación coincide con el Excel «ArbolParosMapex /
 * Consulta Paros Ricardo»; aquí los datos salen 100% de MAPEX (siempre vivos).
 *
 * GET: fecha_desde, fecha_hasta (req), seccion (VARILLAS|TROQUELADOS), turnos (CSV)
 * Devuelve: {
 *   actividades: [..],            // orden de columnas-fila (eje filas)
 *   referencias: [ {
 *     cod, desc, total_horas,
 *     celdas: { "actividad||paro": horas },
 *     paros: [..]                 // tipos de paro presentes en esta referencia
 *   } ]
 * }
 */
try {
    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');
    $seccion = strtoupper((string) getParam('seccion'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) jsonError('fecha_hasta inválida');
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    $where  = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "hpp.Fecha_fin IS NOT NULL",
        "prod.Cod_producto IS NOT NULL",
        "LTRIM(RTRIM(prod.Cod_producto)) NOT IN ('', '--')",
    ];
    $params = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    $sql = "
        SELECT
            LTRIM(RTRIM(prod.Cod_producto)) AS cod,
            MAX(prod.Desc_producto)         AS descr,
            mq.Desc_maquina                 AS maquina,
            COALESCE(NULLIF(LTRIM(RTRIM(ac.Desc_actividad)), ''), 'SIN ACTIVIDAD') AS actividad,
            COALESCE(NULLIF(LTRIM(RTRIM(cp.Desc_paro)), ''), '--') AS paro,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos,
            COUNT(*) AS n_paros
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro     cp   ON cp.Id_paro      = hpp.Id_paro
        LEFT  JOIN cfg_actividad ac  ON ac.Id_actividad = cp.Id_actividad
        INNER JOIN his_prod     hp   ON hp.Id_his_prod  = hpp.Id_his_prod
        INNER JOIN cfg_maquina  mq   ON mq.Id_maquina   = hp.Id_maquina
        INNER JOIN cfg_turno    ct   ON ct.Id_turno     = hp.Id_turno
        LEFT  JOIN his_fase     fa   ON fa.Id_his_fase  = hp.Id_his_fase
        LEFT  JOIN his_of       o    ON o.Id_his_of     = fa.Id_his_of
        LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
        WHERE " . implode(' AND ', $where) . "
        GROUP BY LTRIM(RTRIM(prod.Cod_producto)), mq.Desc_maquina, ac.Desc_actividad, cp.Desc_paro
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
    ";
    $rows = fetchAll('mapex', $sql, $params);

    // Consolidar por referencia, filtrando por sección (vía tipo de máquina).
    $refs = [];          // cod => datos
    $actSet = [];        // actividades presentes (eje filas)
    $paroSet = [];       // tipos de paro presentes (eje columnas)
    foreach ($rows as $r) {
        // Sección a partir de la máquina (misma lógica que el resto de la app).
        if ($seccion !== '' && PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$r['maquina']] !== $seccion) {
            // Si no mapea o no coincide la sección pedida, se descarta.
            if (($seccion === 'VARILLAS' || $seccion === 'TROQUELADOS')) continue;
        }
        $cod = (string) $r['cod'];
        $act = (string) $r['actividad'];
        $par = (string) $r['paro'];
        $h   = (int) $r['segundos'] / 3600.0;
        if (!isset($refs[$cod])) {
            $refs[$cod] = ['cod' => $cod, 'desc' => trim((string)$r['descr']) ?: $cod,
                           'total_horas' => 0.0, 'celdas' => [], 'parosSet' => []];
        }
        $key = $act . '||' . $par;
        $refs[$cod]['celdas'][$key] = ($refs[$cod]['celdas'][$key] ?? 0) + $h;
        $refs[$cod]['total_horas']  += $h;
        $refs[$cod]['parosSet'][$par] = true;
        $actSet[$act]  = true;
        $paroSet[$par] = true;
    }

    // Redondeo y limpieza de salida.
    $out = [];
    foreach ($refs as $cod => $rf) {
        $celdas = [];
        foreach ($rf['celdas'] as $k => $v) $celdas[$k] = round($v, 2);
        $out[] = [
            'cod'         => $cod,
            'desc'        => $rf['desc'],
            'total_horas' => round($rf['total_horas'], 2),
            'celdas'      => $celdas,
            'paros'       => array_keys($rf['parosSet']),
        ];
    }
    // Referencias por más horas de paro primero.
    usort($out, fn($a, $b) => $b['total_horas'] <=> $a['total_horas']);

    // Ordenar actividades por un orden lógico conocido y el resto alfabético.
    $ordenAct = ['PREPARACION','PRODUCCION','AJUSTES EN PRODUCCION','MANTENIMIENTO',
                 'MEJORAS DE PROCESO','PROTOTIPOS AJUSTE','PROTOTIPOS PRODUCCIÓN','TEST','CERRADA'];
    $actividades = array_keys($actSet);
    usort($actividades, function ($a, $b) use ($ordenAct) {
        $ia = array_search($a, $ordenAct); $ib = array_search($b, $ordenAct);
        $ia = $ia === false ? 999 : $ia; $ib = $ib === false ? 999 : $ib;
        return $ia === $ib ? strcmp($a, $b) : $ia <=> $ib;
    });
    $paros = array_keys($paroSet);
    sort($paros);

    jsonOk([
        'seccion'     => $seccion,
        'actividades' => $actividades,
        'paros'       => $paros,
        'referencias' => $out,
    ]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
