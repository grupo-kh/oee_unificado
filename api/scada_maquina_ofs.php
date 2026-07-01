<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/ScadaMural.php';

/** OFS de una máquina para el modal. GET: cod, fecha (YYYY-MM-DD). */
try {
    $cod = trim((string)($_GET['cod'] ?? ''));
    if ($cod === '') jsonError('cod requerido');
    $fecha = trim((string)($_GET['fecha'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');
    jsonOk(ScadaMural::ofsMaquina($cod, $fecha));
} catch (Throwable $e) {
    error_log('scada_maquina_ofs: ' . $e->getMessage());
    jsonError('No se pudieron cargar las OFs', 500);
}
