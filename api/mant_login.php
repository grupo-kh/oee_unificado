<?php
/**
 * Login para la sección de Mantenimiento.
 *
 * POST: usuario, contrasena, next (opcional, basename de la vista a la que
 * volver tras autenticarse — por defecto, mantenimiento.php).
 *
 * Si las credenciales son correctas, redirige a la vista solicitada.
 * Si fallan, vuelve a mant_login.php?error=1 (preservando next).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../views/mant_login.php');
    exit;
}

// CSRF: el token se genera al renderizar mant_login.php y se envía en el form.
Auth::requireCsrfForm('../views/mant_login.php?error=1');

$user = (string)($_POST['usuario']    ?? '');
$pass = (string)($_POST['contrasena'] ?? '');
$next = (string)($_POST['next']       ?? 'mantenimiento.php');
$qs   = (string)($_POST['qs']         ?? '');

// Saneo del 'next' para evitar open redirect: solo nombres de fichero .php.
if (!preg_match('/^[a-zA-Z0-9_\-]+\.php$/', $next)) {
    $next = 'mantenimiento.php';
}

if (Auth::login($user, $pass)) {
    $url = '../views/' . $next;
    if ($qs !== '') $url .= '?' . $qs;
    header('Location: ' . $url);
    exit;
}

$param = '?error=1&next=' . urlencode($next);
if ($qs !== '') $param .= '&qs=' . urlencode($qs);
header('Location: ../views/mant_login.php' . $param);
exit;
