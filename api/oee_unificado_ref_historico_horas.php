<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/oee_unificado_ref_historico.php_data.php';

/**
 * Distribución horaria (00-23) de OK/NOK para una OF.
 * Modo "agregado": si no hay cod_maquina ni dia se suma sobre todas las
 * máquinas y días del rango (necesario para la fila de OF agrupada).
 *
 * Parámetros:
 *   - cod_of (required)
 *   - cod_maquina (opcional)
 *   - dia (opcional, YYYY-MM-DD) ó (fecha_desde + fecha_hasta) para el modo agregado
 *   - cod_producto (opcional)  — recomendado para evitar mezclar OFs homónimas
 */

try {
    $codOf  = (string) ($_GET['cod_of']        ?? '');
    $codMaq = (string) ($_GET['cod_maquina']   ?? '');
    $dia    = (string) ($_GET['dia']           ?? '');
    $fdesde = (string) ($_GET['fecha_desde']   ?? '');
    $fhasta = (string) ($_GET['fecha_hasta']   ?? '');
    $codPrd = (string) ($_GET['cod_producto']  ?? '');

    if ($codOf === '') jsonError('cod_of requerido');
    if ($dia !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) jsonError('dia inválido');
    if ($dia === '' && ($fdesde === '' || $fhasta === '')) {
        jsonError('Faltan filtros: indique dia o fecha_desde + fecha_hasta');
    }
    if ($dia === '') {
        try { refHistValidarRango($fdesde, $fhasta); } catch (Throwable $e) { jsonError($e->getMessage()); }
    }

    $horas = refHistFetchHoras(
        $codOf,
        $codMaq !== '' ? $codMaq : null,
        $dia    !== '' ? $dia    : null,
        $fdesde !== '' ? $fdesde : null,
        $fhasta !== '' ? $fhasta : null,
        $codPrd !== '' ? $codPrd : null
    );
    $totOk = 0; $totNok = 0;
    foreach ($horas as $h) { $totOk += $h['unidades_ok']; $totNok += $h['unidades_nok']; }

    $maqNombre = $codMaq;
    if ($codMaq !== '') {
        $rM = fetchAll('mapex', "SELECT TOP 1 Desc_maquina FROM cfg_maquina WHERE Cod_maquina = ?", [$codMaq]);
        $maqNombre = $rM[0]['Desc_maquina'] ?? $codMaq;
    }

    jsonOk([
        'cod_of'      => $codOf,
        'cod_maquina' => $codMaq,
        'maquina'     => $maqNombre,
        'dia'         => $dia,
        'rango'       => ['desde' => $fdesde, 'hasta' => $fhasta],
        'horas'       => $horas,
        'totales'     => ['unidades_ok' => $totOk, 'unidades_nok' => $totNok],
    ]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
