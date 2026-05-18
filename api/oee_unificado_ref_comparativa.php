<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/oee_unificado_ref_historico.php_data.php';

/**
 * Comparativa de rendimiento de OFs por máquina (misma referencia).
 *
 * Devuelve:
 *   - ofs:               (mismo shape que en histórico) — base del gráfico vertical
 *   - maquinas_distintas: [{cod_maquina, maquina}] — series del chart
 *   - stats:  { mejor, peor, promedio, total_nok, top3, bot3 }
 */

try {
    $codProd = (string) ($_GET['cod_producto'] ?? '');
    $fdesde  = (string) getParam('fecha_desde');
    $fhasta  = (string) getParam('fecha_hasta');

    if ($codProd === '') jsonError('cod_producto requerido');
    try { refHistValidarRango($fdesde, $fhasta); } catch (Throwable $e) { jsonError($e->getMessage()); }

    $prod    = refHistFetchProducto($codProd);
    $ofs     = refHistFetchOfsConMaquinas($codProd, $fdesde, $fhasta);
    $stats   = refHistComparativaStats($ofs);
    $ranking = refHistMaquinaRanking($ofs);

    // Máquinas distintas para series del chart
    $maqs = [];
    foreach ($ofs as $of) {
        foreach ($of['maquinas'] as $m) {
            $maqs[$m['cod_maquina']] = ['cod_maquina' => $m['cod_maquina'], 'maquina' => $m['maquina']];
        }
    }
    $maqs = array_values($maqs);
    usort($maqs, fn($a, $b) => strcmp($a['maquina'], $b['maquina']));

    jsonOk([
        'cod_producto'       => $prod['cod_producto'],
        'desc_producto'      => $prod['desc_producto'],
        'rango'              => ['desde' => $fdesde, 'hasta' => $fhasta],
        'ofs'                => $ofs,
        'maquinas_distintas' => $maqs,
        'stats'              => $stats,
        'maquina_ranking'    => $ranking,
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
