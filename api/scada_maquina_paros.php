<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/ScadaMural.php';

/** PAROS de una máquina para el modal. GET: cod, fecha (YYYY-MM-DD), dias (opt). */
try {
    $cod = trim((string)($_GET['cod'] ?? ''));
    if ($cod === '') jsonError('cod requerido');
    $fecha = trim((string)($_GET['fecha'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');
    $dias = (int)($_GET['dias'] ?? 1);
    jsonOk(ScadaMural::parosMaquina($cod, $fecha, $dias));
} catch (Throwable $e) {
    error_log('scada_maquina_paros: ' . $e->getMessage());
    jsonError('No se pudieron cargar los paros', 500);
}
