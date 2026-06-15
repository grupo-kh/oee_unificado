<?php
/**
 * Login móvil simplificado del operario.
 *
 * POST application/json: { numero: "1234" }
 * Acepta cualquier código de 4 a 6 cifras. NO valida contra el catálogo
 * mant_operarios: el responsable del taller decide qué códigos usar.
 *
 * Respuesta:
 *   200 { ok:true, data:{ user, role, csrf_token } }
 *   400 { ok:false, error:'Número inválido' }
 *
 * Diseñado para devolver SIEMPRE JSON, incluso ante errores fatales.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'ok'    => false,
            'error' => 'Fatal: ' . $e['message'] . ' en ' . basename($e['file']) . ':' . $e['line'],
        ]);
    }
});

try {
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../lib/Auth.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Use POST', 405);

    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) $payload = $_POST;

    $numero = trim((string)($payload['numero'] ?? ''));
    if (!preg_match('/^\d{4,6}$/', $numero)) {
        jsonError('Número inválido. Introduce 4 cifras.', 400);
    }

    if (Auth::loginCodigoLibre($numero)) {
        jsonOk([
            'user'       => Auth::user(),
            'role'       => Auth::role(),
            'csrf_token' => Auth::csrfToken(),
        ]);
    }
    jsonError('No se pudo iniciar sesión', 401);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Excepción: ' . $e->getMessage()]);
}
