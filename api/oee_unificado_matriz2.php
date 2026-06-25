<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
require_once __DIR__ . '/../lib/Db.php';

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
/**
 * Calcula el informe de Matriz 2 y lo DEVUELVE como array (sin emitir salida),
 * para que tanto el endpoint JSON como la exportación a Excel lo reutilicen.
 * Lanza Exception si los parámetros son inválidos.
 */
function matriz2Data(): array
{
    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');
    $seccion = strtoupper((string) getParam('seccion'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) throw new Exception('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) throw new Exception('fecha_hasta inválida');
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    // Filtros: excluir el paro 11 (CERRADA) y, además, toda la actividad CERRADA
    // (Id_actividad = 1) — no debe aparecer ni sumar en Matriz 2. Los paros sin
    // producto SÍ se cuentan (se agrupan bajo «Sin referencia»).
    $where  = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",          // excluir paro CERRADA
        "cp.Id_actividad <> 1",       // excluir toda la actividad CERRADA
        "hpp.Fecha_fin IS NOT NULL",
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

    // Mapeo paro → Tipo Paro 1 (categoría agrupadora del árbol, en PostgreSQL).
    // Compacta la matriz: ~7 categorías en vez de decenas de paros individuales.
    $cat = [];
    try {
        foreach (Db::pgFetchAll('SELECT cod_paro, tipo_paro_1 FROM cfg_paro_categoria') as $c) {
            $cat[mb_strtoupper((string)$c['cod_paro'], 'UTF-8')] = (string)$c['tipo_paro_1'];
        }
    } catch (\Throwable $e) { /* sin mapeo: cae a "Otros" más abajo */ }

    // Consolidar por MÁQUINA → REFERENCIA (como la Matriz original).
    // Celdas con dos granularidades:
    //   - "act||categoria"        → resumen por categoría (Tipo Paro 1)
    //   - "act||categoria||paro"  → detalle por paro individual (nivel extra)
    $maqs = [];          // maquina => ['referencias'=>[cod=>...], 'total'=>..]
    $actSet = [];        // actividades presentes
    $catSet = [];        // categorías presentes
    $parosPorCat = [];   // categoria => [paro => true] (para las filas de paro)
    // Pesos (horas totales) por nivel, para ordenar las columnas de MÁS a MENOS
    // horas (la de más peso siempre a la izquierda). Se acumulan globalmente sobre
    // todas las máquinas/referencias del filtro, en paralelo a las celdas.
    $pesoAct = [];       // actividad        => horas
    $pesoCat = [];       // categoría        => horas (global, suma de todas las actividades)
    $pesoMot = [];       // "categoría||paro" => horas (motivo dentro de su categoría)
    foreach ($rows as $r) {
        $maq = (string) $r['maquina'];
        if ($seccion !== '' && in_array($seccion, ['VARILLAS','TROQUELADOS'], true)
            && (PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$maq] ?? null) !== $seccion) {
            continue;
        }
        // Paro sin producto asignado → «Sin referencia» (se cuenta, como en Matriz).
        $cod  = trim((string) $r['cod']);
        if ($cod === '' || $cod === '--') $cod = '__NOREF__';
        $descr = $cod === '__NOREF__' ? 'Sin referencia' : (trim((string)$r['descr']) ?: $cod);
        $act  = (string) $r['actividad'];
        $par  = (string) $r['paro'];
        $cate = $cat[mb_strtoupper($par, 'UTF-8')] ?? 'Otros';   // Tipo Paro 1
        $h    = (int) $r['segundos'] / 3600.0;

        if (!isset($maqs[$maq])) $maqs[$maq] = ['maquina' => $maq, 'total_horas' => 0.0, 'celdas' => [], 'referencias' => []];
        if (!isset($maqs[$maq]['referencias'][$cod])) {
            $maqs[$maq]['referencias'][$cod] = ['cod' => $cod === '__NOREF__' ? '' : $cod, 'desc' => $descr,
                                                'total_horas' => 0.0, 'celdas' => []];
        }
        $kCat = $act . '||' . $cate;
        $kPar = $act . '||' . $cate . '||' . $par;
        // Acumular en referencia y en máquina (ambos niveles, ambas granularidades).
        $maqs[$maq]['referencias'][$cod]['celdas'][$kCat] = ($maqs[$maq]['referencias'][$cod]['celdas'][$kCat] ?? 0) + $h;
        $maqs[$maq]['referencias'][$cod]['celdas'][$kPar] = ($maqs[$maq]['referencias'][$cod]['celdas'][$kPar] ?? 0) + $h;
        $maqs[$maq]['referencias'][$cod]['total_horas']  += $h;
        $maqs[$maq]['celdas'][$kCat] = ($maqs[$maq]['celdas'][$kCat] ?? 0) + $h;
        $maqs[$maq]['celdas'][$kPar] = ($maqs[$maq]['celdas'][$kPar] ?? 0) + $h;
        $maqs[$maq]['total_horas']  += $h;

        $actSet[$act]  = true;
        $catSet[$cate] = true;
        $parosPorCat[$cate][$par] = true;
        // Acumular peso por nivel (mismo $h que las celdas).
        $pesoAct[$act]               = ($pesoAct[$act] ?? 0) + $h;
        $pesoCat[$cate]              = ($pesoCat[$cate] ?? 0) + $h;
        $pesoMot[$cate . '||' . $par] = ($pesoMot[$cate . '||' . $par] ?? 0) + $h;
    }

    // Salida: máquinas (orden por más horas) con sus referencias (idem).
    $out = [];
    foreach ($maqs as $mq) {
        $refsOut = [];
        foreach ($mq['referencias'] as $rf) {
            $cel = [];
            foreach ($rf['celdas'] as $k => $v) $cel[$k] = round($v, 2);
            $refsOut[] = ['cod' => $rf['cod'], 'desc' => $rf['desc'],
                          'total_horas' => round($rf['total_horas'], 2), 'celdas' => $cel];
        }
        usort($refsOut, fn($a, $b) => $b['total_horas'] <=> $a['total_horas']);
        $celM = [];
        foreach ($mq['celdas'] as $k => $v) $celM[$k] = round($v, 2);
        $out[] = ['maquina' => $mq['maquina'], 'total_horas' => round($mq['total_horas'], 2),
                  'celdas' => $celM, 'referencias' => $refsOut];
    }
    usort($out, fn($a, $b) => $b['total_horas'] <=> $a['total_horas']);

    // Orden de columnas POR PESO (horas de paro), de más a menos: la columna con
    // más peso siempre queda a la izquierda. Aplica a los tres niveles de la
    // cabecera (Actividad → Categoría → Motivo). Desempate alfabético para que el
    // orden sea estable y reproducible cuando dos pesos coinciden.
    $actividades = array_keys($actSet);
    usort($actividades, function ($a, $b) use ($pesoAct) {
        $pa = $pesoAct[$a] ?? 0; $pb = $pesoAct[$b] ?? 0;
        return $pa === $pb ? strcmp($a, $b) : $pb <=> $pa;
    });
    // Categorías de paro (Tipo Paro 1) por peso global (suma sobre todas las
    // actividades). El orden de categorías es el mismo dentro de cada actividad.
    $categorias = array_keys($catSet);
    usort($categorias, function ($a, $b) use ($pesoCat) {
        $pa = $pesoCat[$a] ?? 0; $pb = $pesoCat[$b] ?? 0;
        return $pa === $pb ? strcmp($a, $b) : $pb <=> $pa;
    });
    // Motivos individuales dentro de cada categoría, también por peso (más a la
    // izquierda el de más horas). El peso del motivo se mide dentro de su categoría.
    $parosPorCategoria = [];
    foreach ($parosPorCat as $c => $set) {
        $p = array_keys($set);
        usort($p, function ($a, $b) use ($pesoMot, $c) {
            $pa = $pesoMot[$c . '||' . $a] ?? 0; $pb = $pesoMot[$c . '||' . $b] ?? 0;
            return $pa === $pb ? strcmp($a, $b) : $pb <=> $pa;
        });
        $parosPorCategoria[$c] = $p;
    }

    return [
        'seccion'             => $seccion,
        'actividades'         => $actividades,
        'categorias'          => $categorias,
        'paros_por_categoria' => $parosPorCategoria,
        'maquinas'            => $out,
    ];
}

// Si se invoca directamente como endpoint (no incluido por el export), responde JSON.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        jsonOk(matriz2Data());
    } catch (Exception $e) {
        jsonError('Error: ' . $e->getMessage(), 500);
    }
}
