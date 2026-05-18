<?php
/**
 * views/mant_prev_inspect.php  (TEMPORAL — uso interno)
 * --------------------------------------------------------------
 * Wrapper que carga la lógica de inspección ubicada en tools/.
 * Bloqueado por .htaccess y por sesión técnica.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/../lib/Auth.php';
if (!Auth::isLoggedIn() || !Auth::isTecnico()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Forbidden — requiere sesión técnica.\n");
}
require __DIR__ . '/../tools/mant_prev_inspect.php';
