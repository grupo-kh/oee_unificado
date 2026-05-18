<?php
/**
 * _mant_prev_inspect.php (raíz)
 * --------------------------------------------------------------
 * Proxy temporal al script real en tools/. Bloqueado por
 * .htaccess (FilesMatch ^_mant_prev_.*\.php$). Como defensa en
 * profundidad también exige sesión técnica.
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/lib/Auth.php';
if (!Auth::isLoggedIn() || !Auth::isTecnico()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Forbidden — requiere sesión técnica.\n");
}
require __DIR__ . '/tools/mant_prev_inspect.php';
