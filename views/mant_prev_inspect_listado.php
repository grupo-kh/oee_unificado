<?php
/**
 * views/mant_prev_inspect_listado.php  (TEMPORAL — uso interno)
 * Wrapper al inspector del listado de maquinas con tiempos estimados.
 * Bloqueado por .htaccess y por sesion tecnica.
 */
require_once __DIR__ . '/../lib/Auth.php';
if (!Auth::isLoggedIn() || !Auth::isTecnico()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Forbidden — requiere sesión técnica.\n");
}
require __DIR__ . '/../tools/mant_prev_inspect_listado.php';
