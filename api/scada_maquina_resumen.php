<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/ScadaMural.php';

/** RESUMEN de una máquina para el modal SCADA. GET: cod (Cod_maquina). */
try {
    $cod = trim((string)($_GET['cod'] ?? ''));
    if ($cod === '') jsonError('cod requerido');
    jsonOk(ScadaMural::resumenMaquina($cod));
} catch (Throwable $e) {
    error_log('scada_maquina_resumen: ' . $e->getMessage());
    jsonError('No se pudo cargar el resumen', 500);
}
