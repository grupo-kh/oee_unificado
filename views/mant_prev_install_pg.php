<?php
/**
 * views/mant_prev_install_pg.php  (TEMPORAL — uso interno)
 * --------------------------------------------------------------
 * Instalador idempotente de migraciones PostgreSQL. Bloqueado por
 * .htaccess y por sesión técnica.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/../lib/Auth.php';
if (!Auth::isLoggedIn() || !Auth::isTecnico()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Forbidden — requiere sesión técnica.\n");
}
header('Content-Type: text/plain; charset=UTF-8');
require __DIR__ . '/../tools/install_postgres.php';
