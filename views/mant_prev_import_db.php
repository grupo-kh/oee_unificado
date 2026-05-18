<?php
/**
 * views/mant_prev_import_db.php  (TEMPORAL — uso interno)
 * --------------------------------------------------------------
 * Wrapper al script de import a BD ubicado en tools/. Bloqueado
 * por .htaccess (views/.htaccess FilesMatch ^mant_prev_) y por
 * sesión técnica como defensa en profundidad.
 *
 * URL DRY-RUN (no commit, solo para previsualizar):
 *   http://localhost/PLAN_ATTAINMENT/views/mant_prev_import_db.php
 * URL COMMIT (cambios reales en la BD):
 *   http://localhost/PLAN_ATTAINMENT/views/mant_prev_import_db.php?commit=1
 * --------------------------------------------------------------
 */
require_once __DIR__ . '/../lib/Auth.php';
if (!Auth::isLoggedIn() || !Auth::isTecnico()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Forbidden — requiere sesión técnica.\n");
}
require __DIR__ . '/../tools/mant_prev_import_db.php';
