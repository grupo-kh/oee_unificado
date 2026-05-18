<?php
/**
 * Funciones compartidas para el Histórico de referencia.
 *
 * Estructura principal devuelta por refHistFetchOfsConMaquinas():
 *   ofs: [
 *     {
 *       cod_of, unidades_ok, unidades_nok, horas, uds_h, nok_pct, num_dias,
 *       maquinas: [{ cod_maquina, maquina, unidades_ok, unidades_nok, horas, uds_h, nok_pct }]
 *     }
 *   ]
 */

if (!function_exists('refHistFetchProducto')) {
    function refHistFetchProducto(string $cod): array {
        $r = fetchAll('mapex',
            "SELECT TOP 1 Cod_producto, Desc_producto FROM cfg_producto WHERE Cod_producto = ?",
            [$cod]
        );
        if (empty($r)) return ['cod_producto' => $cod, 'desc_producto' => $cod];
        return [
            'cod_producto'  => $r[0]['Cod_producto'],
            'desc_producto' => $r[0]['Desc_producto'] ?: $r[0]['Cod_producto'],
        ];
    }
}

if (!function_exists('refHistFetchOfsConMaquinas')) {
    /**
     * OFs agrupadas (totales por OF ignorando la fecha) con desglose por
     * máquina para cada OF. Núcleo de los dos bloques de la vista.
     */
    function refHistFetchOfsConMaquinas(string $codProducto, string $fdesde, string $fhasta): array {
        // OF × máquina con tiempo trabajado (para uds/h)
        $sql = "
            SELECT COALESCE(NULLIF(LTRIM(RTRIM(o.Cod_of)), ''), '—') AS cod_of,
                   mq.Cod_maquina  AS cod_maquina,
                   mq.Desc_maquina AS maquina,
                   COUNT(DISTINCT CAST(hp.Dia_productivo AS DATE)) AS num_dias,
                   SUM(CAST(ISNULL(hp.Unidades_ok,  0) AS FLOAT)) AS unidades_ok,
                   SUM(CAST(ISNULL(hp.Unidades_nok, 0) AS FLOAT)) AS unidades_nok,
                   SUM(DATEDIFF(SECOND, hp.Fecha_ini, ISNULL(hp.Fecha_fin, hp.Fecha_ini))) AS segundos
            FROM his_prod hp
            INNER JOIN his_fase     fa ON fa.Id_his_fase = hp.Id_his_fase
            INNER JOIN his_of       o  ON o.Id_his_of    = fa.Id_his_of
            INNER JOIN cfg_producto pr ON pr.Id_producto = o.Id_producto
            INNER JOIN cfg_maquina  mq ON mq.Id_maquina  = hp.Id_maquina
            WHERE CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?
              AND pr.Cod_producto = ?
            GROUP BY o.Cod_of, mq.Cod_maquina, mq.Desc_maquina
            HAVING SUM(CAST(ISNULL(hp.Unidades_ok,0) AS FLOAT))
                 + SUM(CAST(ISNULL(hp.Unidades_nok,0) AS FLOAT)) > 0
            ORDER BY cod_of, mq.Desc_maquina
        ";
        $rows = fetchAll('mapex', $sql, [$fdesde, $fhasta, $codProducto]);

        $byOf = [];
        $diasPorOf = [];
        foreach ($rows as $r) {
            $ok  = (float)$r['unidades_ok'];
            $nok = (float)$r['unidades_nok'];
            $h   = ((float)$r['segundos']) / 3600.0;
            $codOf = $r['cod_of'];
            if (!isset($byOf[$codOf])) {
                $byOf[$codOf] = [
                    'cod_of'       => $codOf,
                    'unidades_ok'  => 0,
                    'unidades_nok' => 0,
                    'horas'        => 0.0,
                    'num_dias'     => 0,
                    'maquinas'     => [],
                ];
                $diasPorOf[$codOf] = 0;
            }
            $byOf[$codOf]['maquinas'][] = [
                'cod_maquina'  => $r['cod_maquina'],
                'maquina'      => $r['maquina'] ?: $r['cod_maquina'],
                'unidades_ok'  => (int) round($ok),
                'unidades_nok' => (int) round($nok),
                'horas'        => round($h, 2),
                'uds_h'        => $h > 0 ? round($ok / $h, 2) : 0,
                'nok_pct'      => ($ok + $nok) > 0 ? round($nok / ($ok + $nok) * 100, 2) : 0,
            ];
            $byOf[$codOf]['unidades_ok']  += (int) round($ok);
            $byOf[$codOf]['unidades_nok'] += (int) round($nok);
            $byOf[$codOf]['horas']        += $h;
            // num_dias se acumulará desde otra consulta (distinto por OF, no por máquina)
        }

        // num_dias distinto por OF
        if (!empty($byOf)) {
            $sqlD = "
                SELECT COALESCE(NULLIF(LTRIM(RTRIM(o.Cod_of)), ''), '—') AS cod_of,
                       COUNT(DISTINCT CAST(hp.Dia_productivo AS DATE)) AS num_dias
                FROM his_prod hp
                INNER JOIN his_fase     fa ON fa.Id_his_fase = hp.Id_his_fase
                INNER JOIN his_of       o  ON o.Id_his_of    = fa.Id_his_of
                INNER JOIN cfg_producto pr ON pr.Id_producto = o.Id_producto
                WHERE CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?
                  AND pr.Cod_producto = ?
                GROUP BY o.Cod_of
            ";
            $rowsD = fetchAll('mapex', $sqlD, [$fdesde, $fhasta, $codProducto]);
            foreach ($rowsD as $rd) {
                if (isset($byOf[$rd['cod_of']])) $byOf[$rd['cod_of']]['num_dias'] = (int)$rd['num_dias'];
            }
        }

        // Calcular uds/h y nok_pct totales por OF
        foreach ($byOf as &$of) {
            $totOk  = $of['unidades_ok'];
            $totNok = $of['unidades_nok'];
            $of['uds_h']   = $of['horas'] > 0 ? round($totOk / $of['horas'], 2) : 0;
            $of['horas']   = round($of['horas'], 2);
            $of['nok_pct'] = ($totOk + $totNok) > 0 ? round($totNok / ($totOk + $totNok) * 100, 2) : 0;
        }
        unset($of);

        // Ordenar OFs por uds/h desc para que las mejores aparezcan primero
        $ofsList = array_values($byOf);
        usort($ofsList, fn($a, $b) => $b['uds_h'] <=> $a['uds_h']);
        return $ofsList;
    }
}

if (!function_exists('refHistTotalesOfs')) {
    function refHistTotalesOfs(array $ofs): array {
        $totOk = 0; $totNok = 0; $dias = 0; $maqs = [];
        $sumUds = 0; $cntUds = 0;
        foreach ($ofs as $of) {
            $totOk  += (int)$of['unidades_ok'];
            $totNok += (int)$of['unidades_nok'];
            $dias   += (int)$of['num_dias'];
            foreach ($of['maquinas'] as $m) {
                $maqs[$m['cod_maquina']] = true;
                if ($m['uds_h'] > 0) { $sumUds += $m['uds_h']; $cntUds++; }
            }
        }
        return [
            'unidades_ok'  => $totOk,
            'unidades_nok' => $totNok,
            'num_ofs'      => count($ofs),
            'num_maquinas' => count($maqs),
            'dias'         => $dias,
            'uds_h_medio'  => $cntUds > 0 ? round($sumUds / $cntUds, 2) : 0,
        ];
    }
}

if (!function_exists('refHistComparativaStats')) {
    /**
     * Resumen para el panel lateral del bloque de comparativa:
     * mejor / peor pareja (OF, máquina) por uds/h, promedio y total NOK.
     */
    function refHistComparativaStats(array $ofs): array {
        $best = null; $worst = null;
        $sumUds = 0; $cntUds = 0; $totNok = 0;
        $top3 = []; $bot3 = [];

        foreach ($ofs as $of) {
            $totNok += (int)$of['unidades_nok'];
            foreach ($of['maquinas'] as $m) {
                if ($m['uds_h'] <= 0) continue;
                $entry = [
                    'cod_of'      => $of['cod_of'],
                    'cod_maquina' => $m['cod_maquina'],
                    'maquina'     => $m['maquina'],
                    'uds_h'       => $m['uds_h'],
                    'unidades_ok' => $m['unidades_ok'],
                    'unidades_nok'=> $m['unidades_nok'],
                ];
                if ($best === null  || $entry['uds_h'] > $best['uds_h'])  $best  = $entry;
                if ($worst === null || $entry['uds_h'] < $worst['uds_h']) $worst = $entry;
                $sumUds += $entry['uds_h']; $cntUds++;
                $top3[] = $entry; $bot3[] = $entry;
            }
        }
        usort($top3, fn($a, $b) => $b['uds_h'] <=> $a['uds_h']);
        usort($bot3, fn($a, $b) => $a['uds_h'] <=> $b['uds_h']);
        return [
            'mejor'     => $best,
            'peor'      => $worst,
            'promedio'  => $cntUds > 0 ? round($sumUds / $cntUds, 2) : 0,
            'total_nok' => $totNok,
            'top3'      => array_slice($top3, 0, 3),
            'bot3'      => array_slice($bot3, 0, 3),
        ];
    }
}

if (!function_exists('refHistMaquinaRanking')) {
    /**
     * Ranking de máquinas sumando TODAS las OFs de la referencia en el rango.
     * Devuelve lista ordenada por uds/h descendente, cada entrada con métricas
     * agregadas (OK, NOK, horas, uds_h, nok_pct, num_ofs).
     */
    function refHistMaquinaRanking(array $ofs): array {
        $byMaq = [];
        foreach ($ofs as $of) {
            foreach ($of['maquinas'] as $m) {
                $cod = $m['cod_maquina'];
                if (!isset($byMaq[$cod])) {
                    $byMaq[$cod] = [
                        'cod_maquina'  => $cod,
                        'maquina'      => $m['maquina'],
                        'unidades_ok'  => 0,
                        'unidades_nok' => 0,
                        'horas'        => 0.0,
                        'num_ofs'      => 0,
                    ];
                }
                $byMaq[$cod]['unidades_ok']  += (int)$m['unidades_ok'];
                $byMaq[$cod]['unidades_nok'] += (int)$m['unidades_nok'];
                $byMaq[$cod]['horas']        += (float)$m['horas'];
                $byMaq[$cod]['num_ofs']++;
            }
        }
        foreach ($byMaq as &$m) {
            $ok = $m['unidades_ok']; $nok = $m['unidades_nok']; $h = $m['horas'];
            $m['uds_h']   = $h > 0 ? round($ok / $h, 2) : 0;
            $m['nok_pct'] = ($ok + $nok) > 0 ? round($nok / ($ok + $nok) * 100, 2) : 0;
            $m['horas']   = round($h, 2);
        }
        unset($m);
        $list = array_values($byMaq);
        usort($list, fn($a, $b) => $b['uds_h'] <=> $a['uds_h']);

        // pct_vs_best
        $best = $list[0]['uds_h'] ?? 0;
        foreach ($list as &$m) {
            $m['pct_vs_best'] = $best > 0 ? round($m['uds_h'] / $best * 100, 1) : 0;
        }
        unset($m);
        return $list;
    }
}

if (!function_exists('refHistFetchHoras')) {
    /**
     * Distribución horaria (00-23) de OK/NOK para una OF.
     * cod_maquina y dia opcionales — si vacíos, agrega por todas las máquinas
     * y días en el rango fdesde/fhasta.
     */
    function refHistFetchHoras(
        string $codOf,
        ?string $codMaq = null,
        ?string $dia    = null,
        ?string $fdesde = null,
        ?string $fhasta = null,
        ?string $codProducto = null
    ): array {
        $where = ["o.Cod_of = ?"];
        $params = [$codOf];
        if ($codMaq) { $where[] = "mq.Cod_maquina = ?"; $params[] = $codMaq; }
        if ($dia) {
            $where[] = "CAST(hp.Dia_productivo AS DATE) = ?";
            $params[] = $dia;
        } elseif ($fdesde && $fhasta) {
            $where[] = "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?";
            $params[] = $fdesde; $params[] = $fhasta;
        }
        if ($codProducto) {
            $where[] = "pr.Cod_producto = ?";
            $params[] = $codProducto;
        }
        $whereSQL = implode(' AND ', $where);

        $sql = "
            SELECT DATEPART(HOUR, hp.Fecha_ini) AS hora,
                   SUM(CAST(ISNULL(hp.Unidades_ok,  0) AS FLOAT)) AS unidades_ok,
                   SUM(CAST(ISNULL(hp.Unidades_nok, 0) AS FLOAT)) AS unidades_nok
            FROM his_prod hp
            INNER JOIN his_fase    fa ON fa.Id_his_fase = hp.Id_his_fase
            INNER JOIN his_of      o  ON o.Id_his_of    = fa.Id_his_of
            INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
            " . ($codProducto ? "INNER JOIN cfg_producto pr ON pr.Id_producto = o.Id_producto" : "") . "
            WHERE $whereSQL
            GROUP BY DATEPART(HOUR, hp.Fecha_ini)
            ORDER BY hora
        ";
        $rows = fetchAll('mapex', $sql, $params);
        $byHora = [];
        foreach ($rows as $r) {
            $byHora[(int)$r['hora']] = [
                'unidades_ok'  => (int) round((float)$r['unidades_ok']),
                'unidades_nok' => (int) round((float)$r['unidades_nok']),
            ];
        }
        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[] = [
                'hora'         => str_pad((string)$h, 2, '0', STR_PAD_LEFT),
                'unidades_ok'  => $byHora[$h]['unidades_ok']  ?? 0,
                'unidades_nok' => $byHora[$h]['unidades_nok'] ?? 0,
            ];
        }
        return $out;
    }
}

if (!function_exists('refHistValidarRango')) {
    function refHistValidarRango(string $fdesde, string $fhasta): void {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) throw new RuntimeException('fecha_desde inválida');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) throw new RuntimeException('fecha_hasta inválida');
        $d1 = DateTime::createFromFormat('Y-m-d', $fdesde);
        $d2 = DateTime::createFromFormat('Y-m-d', $fhasta);
        if (!$d1 || !$d2) throw new RuntimeException('Fechas inválidas');
        if ($d1 > $d2) throw new RuntimeException('fecha_desde no puede ser posterior a fecha_hasta');
        $diff = (int) $d1->diff($d2)->days;
        if ($diff > 366) throw new RuntimeException('El rango máximo es de 1 año (366 días)');
    }
}
