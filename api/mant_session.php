<?php
/**
 * Devuelve la sesión actual (para hidratación del frontend tras recargar).
 * 200 ok=true con user/role/csrf si hay sesión; 200 ok=true con role=null si no.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

if (Auth::isLoggedIn()) {
    jsonOk([
        'user'       => Auth::user(),
        'role'       => Auth::role(),
        'csrf_token' => Auth::csrfToken(),
    ]);
}
jsonOk(['user' => null, 'role' => null, 'csrf_token' => null]);
