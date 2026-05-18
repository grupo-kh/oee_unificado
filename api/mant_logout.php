<?php
/**
 * Logout. Cierra la sesión y vuelve al login.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/Auth.php';

Auth::logout();
header('Location: ../views/mant_login.php');
exit;
