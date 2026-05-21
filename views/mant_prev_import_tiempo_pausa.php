<?php
/**
 * views/mant_prev_import_tiempo_pausa.php  (TEMPORAL — uso interno)
 * Wrapper al import de tiempo_estimado y fecha_pausa.
 * Bloqueado por .htaccess y por sesion tecnica.
 *
 * URL DRY-RUN: http://localhost/PLAN_ATTAINMENT/views/mant_prev_import_tiempo_pausa.php
 * URL COMMIT : http://localhost/PLAN_ATTAINMENT/views/mant_prev_import_tiempo_pausa.php?commit=1
 */
require_once __DIR__ . '/../lib/Auth.php';
if (!Auth::isLoggedIn() || !Auth::isTecnico()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Forbidden — requiere sesión técnica.\n");
}
require __DIR__ . '/../tools/mant_prev_import_tiempo_pausa.php';
