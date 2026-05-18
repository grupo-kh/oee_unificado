<?php
/**
 * views/mant_prev_recover_secuencia.php  (TEMPORAL — uso interno)
 * --------------------------------------------------------------
 * Wrapper de recuperación de Secuencia. Bloqueado por .htaccess y
 * por sesión técnica.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/../lib/Auth.php';
if (!Auth::isLoggedIn() || !Auth::isTecnico()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Forbidden — requiere sesión técnica.\n");
}
require __DIR__ . '/../tools/mant_prev_recover_secuencia.php';
