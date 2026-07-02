<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/ScadaMural.php';

/** Histórico de TURNOS ANTERIORES de una máquina para el modal SCADA.
 *  GET: cod (Cod_maquina), dias (1|3|7|15, por defecto 7). */
try {
    $cod  = trim((string)($_GET['cod'] ?? ''));
    if ($cod === '') jsonError('cod requerido');
    $dias = (int) ($_GET['dias'] ?? 7);
    jsonOk(ScadaMural::turnosAnteriores($cod, $dias));
} catch (Throwable $e) {
    error_log('scada_maquina_turnos: ' . $e->getMessage());
    jsonError('No se pudo cargar el histórico de turnos', 500);
}
