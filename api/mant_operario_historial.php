<?php
/**
 * Endpoint retirado.
 *
 * El historial de tareas por operario se consulta ahora desde el módulo
 * Histórico (views/mant_historico.php) usando el filtro Operario del
 * desplegable. Este endpoint queda como stub para evitar 404 si quedó
 * alguna llamada antigua en caché.
 */
require_once __DIR__ . '/../includes/helpers.php';
jsonError('Endpoint retirado. Usa Histórico (mant_historico.php) con filtro Operario.', 410);
