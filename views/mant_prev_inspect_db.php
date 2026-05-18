<?php
/**
 * views/mant_prev_inspect_db.php (TEMPORAL — uso interno)
 * Wrapper al inspector de BD del módulo de mantenimiento.
 * Bloqueado por .htaccess y por sesión técnica.
 */
require_once __DIR__ . '/../lib/Auth.php';
if (!Auth::isLoggedIn() || !Auth::isTecnico()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Forbidden — requiere sesión técnica.\n");
}
require __DIR__ . '/../tools/mant_prev_inspect_db.php';
