<?php
/**
 * Login JSON para la app móvil de operarios.
 * POST application/json: { usuario, contrasena }
 * 200 { ok:true, data:{ user, role, csrf_token } }
 * 401 { ok:false, error:'Credenciales inválidas' }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = $_POST;

$user = (string)($payload['usuario']    ?? '');
$pass = (string)($payload['contrasena'] ?? '');

if (Auth::login($user, $pass)) {
    jsonOk([
        'user'       => Auth::user(),
        'role'       => Auth::role(),
        'csrf_token' => Auth::csrfToken(),
    ]);
}
jsonError('Credenciales inválidas', 401);
