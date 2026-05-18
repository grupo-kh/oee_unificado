<?php
require_once __DIR__ . '/../lib/Auth.php';

// Si ya hay sesión, redirige directamente a la vista destino.
if (Auth::isLoggedIn()) {
    $next = (string)($_GET['next'] ?? 'mantenimiento.php');
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.php$/', $next)) $next = 'mantenimiento.php';
    $qs = (string)($_GET['qs'] ?? '');
    $url = $next . ($qs !== '' ? '?' . $qs : '');
    header('Location: ' . $url);
    exit;
}

$pageTitle = 'Mantenimiento · Acceso';
$backLink  = '../index.php';
$hideFiltros = true;

$error = isset($_GET['error']);
$next  = (string)($_GET['next'] ?? 'mantenimiento.php');
$qs    = (string)($_GET['qs']   ?? '');

include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card login-card">
        <div class="view-card-header">
            <h2>Acceso a Mantenimiento</h2>
            <span class="view-card-info">Identifícate para continuar</span>
        </div>
        <div class="view-card-body">
            <form method="post" action="../api/mant_login.php" class="login-form" autocomplete="off">
                <input type="hidden" name="next"  value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
                <input type="hidden" name="qs"    value="<?= htmlspecialchars($qs,   ENT_QUOTES) ?>">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES) ?>">

                <?php if ($error): ?>
                    <div class="login-error">Usuario o contraseña incorrectos.</div>
                <?php endif; ?>

                <div class="login-field">
                    <label for="usuario">Usuario</label>
                    <input type="text" id="usuario" name="usuario" required autofocus>
                </div>
                <div class="login-field">
                    <label for="contrasena">Contraseña</label>
                    <input type="password" id="contrasena" name="contrasena" required>
                </div>
                <div class="login-actions">
                    <button type="submit" class="login-submit">Entrar</button>
                </div>

                <div class="login-hint">
                    Roles disponibles · <strong>Técnico</strong> (acceso completo) · <strong>Operario</strong> (solo registro de fechas).
                </div>
            </form>
        </div>
    </div>
</main>
</body>
</html>
