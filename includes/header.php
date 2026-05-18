<?php
/**
 * Cabecera común del dashboard KH Plan Attainment
 * Requiere variables $pageTitle (opcional) antes del include.
 */
if (!isset($pageTitle)) $pageTitle = 'Plan Attainment';
if (!isset($pageSubtitle)) $pageSubtitle = '';
if (!isset($backLink)) $backLink = '../index.php';
// $hideFiltros = true → oculta Fch. Productiva + Turno (uso típico:
// secciones independientes del Plan Attainment como Mantenimiento).
if (!isset($hideFiltros)) $hideFiltros = false;
$isHome = basename($_SERVER['PHP_SELF']) === 'index.php';
// Token CSRF para que el JS lo envíe en cabecera X-CSRF-Token en cada POST.
// Se genera siempre (es gratis) y se expone al frontend vía meta + window.
require_once __DIR__ . '/../lib/Auth.php';
$_csrfToken = Auth::csrfToken();
// Cache-busting: ?v=mtime sobre el style.css para forzar al navegador a refrescar
// cuando el CSS cambia (evita el típico "no veo los cambios").
$_cssPath = __DIR__ . '/../assets/css/style.css';
$_cssVer  = file_exists($_cssPath) ? filemtime($_cssPath) : time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?> · KH</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($_csrfToken, ENT_QUOTES) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Arial:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $isHome ? '' : '../' ?>assets/css/style.css?v=<?= $_cssVer ?>">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
        // Token CSRF expuesto a los fetch() POST del frontend.
        window.__CSRF_TOKEN = <?= json_encode($_csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <?php if (!empty($mantUserRole)): ?>
    <script>
        window.__USER_ROLE = <?= json_encode($mantUserRole, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__USER_NAME = <?= json_encode($mantUserName ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.__IS_OPERARIO = window.__USER_ROLE === 'operario';
        window.__IS_TECNICO  = window.__USER_ROLE === 'tecnico';
    </script>
    <?php endif; ?>
</head>
<body<?= !empty($mantUserRole) ? ' data-role="' . htmlspecialchars($mantUserRole, ENT_QUOTES) . '"' : '' ?>>

<header class="topbar">
    <div class="topbar-left">
        <div class="logo-kh">
            <div class="logo-icon">
                <span class="logo-dot logo-dot-1"></span>
                <span class="logo-dot logo-dot-2"></span>
                <span class="logo-dot logo-dot-3"></span>
            </div>
            <div class="logo-text">
                <span class="logo-kh-text">KH</span>
                <span class="logo-know">KNOW HOW</span>
            </div>
        </div>
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h1>
    </div>

    <div class="topbar-right">
        <?php if (!$hideFiltros): ?>
        <div class="filter-box">
            <label>Fch. Productiva</label>
            <input type="date" id="f-fecha" class="filter-field filter-green">
        </div>
        <div class="filter-box">
            <label>Turno</label>
            <select id="f-turno" class="filter-field filter-green">
                <option value="">TODOS</option>
                <option value="M">MAÑANA</option>
                <option value="T">TARDE</option>
                <option value="N">NOCHE</option>
                <option value="C">CENTRAL</option>
            </select>
        </div>
        <?php endif; ?>
        <?php if (!$isHome): ?>
        <a href="<?= htmlspecialchars($backLink) ?>" class="btn-back" title="Volver">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            </svg>
        </a>
        <?php endif; ?>
    </div>
</header>
