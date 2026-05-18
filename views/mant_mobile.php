<?php
/**
 * Webapp móvil para operarios.
 * Layout independiente de header.php — pantalla completa, mobile-first.
 */
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#1a2d4a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Mantenimiento · Operario</title>
    <link rel="stylesheet" href="../assets/css/mobile.css">
</head>
<body class="mob-body">

<header class="mob-topbar">
    <button type="button" class="mob-icon-btn" id="mob-back" hidden aria-label="Volver">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
    </button>
    <div class="mob-title-wrap">
        <span class="mob-title" id="mob-title">Mantenimiento</span>
        <span class="mob-subtitle" id="mob-subtitle">Operario</span>
    </div>
    <button type="button" class="mob-icon-btn mob-op-btn" id="mob-op-btn" aria-label="Operario">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <span id="mob-op-name">—</span>
    </button>
</header>

<!-- Pantalla 1 · Lista de máquinas -->
<section class="mob-screen mob-screen-active" id="screen-machines" data-title="Mantenimiento" data-subtitle="Selecciona una máquina">
    <div class="mob-search">
        <svg class="mob-search-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" id="mob-search-input" inputmode="search" autocomplete="off" placeholder="Buscar máquina…">
    </div>
    <div class="mob-summary" id="mob-summary">—</div>
    <div class="mob-list" id="mob-machines-list">
        <div class="mob-empty">Cargando…</div>
    </div>
</section>

<!-- Pantalla 2 · Tareas de la máquina -->
<section class="mob-screen" id="screen-tasks" data-title="" data-subtitle="Tareas pendientes">
    <div class="mob-list" id="mob-tasks-list">
        <div class="mob-empty">—</div>
    </div>
</section>

<!-- Pantalla 3 · Form de cumplimiento -->
<section class="mob-screen" id="screen-form" data-title="" data-subtitle="Marcar como hecha">
    <div class="mob-task-card" id="mob-task-card">—</div>
    <form class="mob-form" id="mob-form" autocomplete="off">
        <div class="mob-field">
            <label for="mob-operario">Operario</label>
            <select id="mob-operario">
                <option value="">— Selecciona —</option>
            </select>
        </div>
        <div class="mob-field" id="mob-operario-otro-wrap" hidden>
            <label for="mob-operario-otro">Nombre del operario</label>
            <input type="text" id="mob-operario-otro" placeholder="Escribe tu nombre…" autocomplete="off">
        </div>
        <div class="mob-field">
            <label for="mob-fecha">Fecha de la intervención</label>
            <input type="date" id="mob-fecha">
        </div>
        <div class="mob-field">
            <label for="mob-observaciones">Observaciones</label>
            <textarea id="mob-observaciones" rows="4" placeholder="Cualquier nota…"></textarea>
        </div>
        <button type="button" class="mob-btn mob-btn-primary mob-btn-big" id="mob-submit">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            ENVIAR
        </button>
    </form>
</section>

<!-- Pantalla 4 · Éxito -->
<section class="mob-screen" id="screen-success" data-title="Hecha" data-subtitle="Registrada y reprogramada">
    <div class="mob-success">
        <div class="mob-success-icon">
            <svg viewBox="0 0 24 24" width="60" height="60" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="mob-success-msg">Tarea registrada</div>
        <div class="mob-success-detail" id="success-detail">—</div>
        <button type="button" class="mob-btn mob-btn-primary mob-btn-big" id="success-back">← Continuar</button>
    </div>
</section>

<!-- Modal · cambiar operario -->
<div class="mob-modal" id="mob-op-modal" hidden>
    <div class="mob-modal-backdrop"></div>
    <div class="mob-modal-dialog">
        <div class="mob-modal-header">Operario actual</div>
        <div class="mob-modal-body">
            <div class="mob-field">
                <label for="mob-op-modal-select">Selecciona un operario</label>
                <select id="mob-op-modal-select">
                    <option value="">— Sin operario —</option>
                </select>
            </div>
            <div class="mob-field" id="mob-op-modal-otro-wrap" hidden>
                <label for="mob-op-modal-otro">Nombre del operario</label>
                <input type="text" id="mob-op-modal-otro" placeholder="Escribe tu nombre…">
            </div>
        </div>
        <div class="mob-modal-footer">
            <button type="button" class="mob-btn mob-btn-secondary" id="mob-op-modal-cancel">Cancelar</button>
            <button type="button" class="mob-btn mob-btn-primary" id="mob-op-modal-ok">Guardar</button>
        </div>
    </div>
</div>

<div id="mob-toast" class="mob-toast"></div>
<div id="mob-loader" class="mob-loader" hidden><div class="mob-spinner"></div></div>

<script src="../assets/js/view_mant_mobile.js"></script>
</body>
</html>
