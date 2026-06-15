<?php
/**
 * App móvil del operario · KH Mantenimiento
 *
 * Pantallas:
 *  1. Login: cualquier código de 4 cifras (sin contraseña).
 *  2. Lista de MÁQUINAS con tareas pendientes (vencidas + hoy).
 *     Al pulsar una máquina, despliega sus tareas.
 *  3. Detalle de la tarea con cronómetro:
 *       · Iniciar → arranca el contador.
 *       · Pausar / Reanudar → mide solo el tiempo activo.
 *       · Cancelar → descarta y vuelve.
 *       · Finalizar → marca como realizada, imputa el tiempo acumulado
 *         como tiempo_real_segundos para futuras estadísticas.
 *  4. Confirmación con resumen y duración.
 *
 * Todo autocontenido (HTML + CSS + JS en este mismo archivo).
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/../lib/Auth.php';

$mantUser = Auth::user();
$csrf = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d0d0d">
<title>KH · Operario</title>
<style>
:root {
    --kh-red:        #3a6aa3;
    --kh-red-2:      #5b8cc7;
    --kh-red-dark:   #1a4a7a;
    --kh-red-bg:     #fbe6e7;
    --kh-black:      #0d0d0d;
    --kh-black-2:    #1d1d1d;
    --kh-amber:      #c47600;
    --kh-amber-bg:   #fff5e1;
    --kh-green:      #1f8a3c;
    --kh-green-bg:   #e3f5e8;
    --kh-blue:       #1a4a7a;
    --kh-text:       #1a1a1a;
    --kh-text-soft:  #6b6b6b;
    --kh-line:       #e7e3e3;
    --kh-bg:         #f5f3f3;
    --kh-card:       #ffffff;
}
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
[hidden] { display: none !important; }
html, body { height: 100%; background: var(--kh-bg); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: var(--kh-text); overscroll-behavior: none; }
button { border: none; background: none; font: inherit; color: inherit; cursor: pointer; }
input, textarea { font: inherit; }

#app { min-height: 100vh; min-height: 100dvh; max-width: 520px; margin: 0 auto; background: var(--kh-bg); position: relative; box-shadow: 0 0 32px rgba(0,0,0,0.08); }
.screen { display: none; flex-direction: column; min-height: 100vh; min-height: 100dvh; }
.screen.active { display: flex; }

.topbar { background: var(--kh-black); color: #fff; padding: 14px 18px; padding-top: calc(14px + env(safe-area-inset-top)); display: flex; align-items: center; gap: 12px; position: sticky; top: 0; z-index: 10; box-shadow: 0 2px 8px rgba(0,0,0,0.30); border-bottom: 3px solid var(--kh-red); }
.topbar h1 { font-size: 17px; font-weight: 700; }
.topbar .sub { font-size: 12px; opacity: 0.7; margin-top: 1px; }
.topbar .back { width: 36px; height: 36px; border-radius: 10px; background: rgba(255,255,255,0.10); display: grid; place-items: center; font-size: 24px; flex-shrink: 0; }
.topbar .logout { margin-left: auto; font-size: 12px; opacity: 0.7; padding: 6px 10px; border-radius: 8px; background: rgba(255,255,255,0.08); }
.body { flex: 1; padding: 16px 14px 100px; overflow-y: auto; }

/* ─── LOGIN ─── */
.login {
    background:
        radial-gradient(ellipse at top right, rgba(140, 24, 26, 0.50) 0%, transparent 55%),
        radial-gradient(ellipse at bottom left, rgba(140, 24, 26, 0.30) 0%, transparent 55%),
        linear-gradient(180deg, var(--kh-black) 0%, var(--kh-black-2) 70%);
    color: #fff;
    padding: 28px 22px;
    padding-top: calc(28px + env(safe-area-inset-top));
    padding-bottom: calc(28px + env(safe-area-inset-bottom));
    min-height: 100vh; min-height: 100dvh;
    display: flex; flex-direction: column;
}
.brand-block { display: flex; align-items: center; gap: 14px; margin: 8px 0 30px; padding: 14px; background: rgba(0,0,0,0.40); border-radius: 14px; border: 1px solid rgba(255,255,255,0.06); }
.brand-logo { width: 56px; height: 56px; flex-shrink: 0; }
.brand-sep { width: 2px; height: 40px; background: rgba(255,255,255,0.85); border-radius: 2px; }
.brand-text { display: flex; flex-direction: column; }
.brand-name { font-family: Georgia, "Times New Roman", serif; font-size: 32px; font-weight: 700; letter-spacing: 4px; line-height: 1; }
.brand-sub { font-family: Georgia, "Times New Roman", serif; font-size: 10px; opacity: 0.85; letter-spacing: 5px; margin-top: 4px; }
.login h2 { font-size: 24px; line-height: 1.25; margin: 8px 0 6px; font-weight: 700; }
.login p.helper { font-size: 13px; opacity: 0.7; margin-bottom: 22px; }
.op-input { background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.16); border-radius: 14px; color: #fff; font-size: 32px; text-align: center; font-weight: 600; letter-spacing: 6px; padding: 16px; width: 100%; margin-bottom: 18px; outline: none; }
.op-input::placeholder { color: rgba(255,255,255,0.35); letter-spacing: 0; font-weight: 400; font-size: 16px; }
.keypad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.key { aspect-ratio: 1.6 / 1; background: rgba(255,255,255,0.08); border-radius: 14px; font-size: 26px; font-weight: 600; color: #fff; display: grid; place-items: center; user-select: none; }
.key:active { background: rgba(255,255,255,0.18); transform: scale(0.96); }
.key.dim { background: transparent; color: rgba(255,255,255,0.6); }
.login-error { margin-top: 14px; padding: 10px 12px; background: rgba(255, 80, 80, 0.18); border: 1px solid rgba(255, 80, 80, 0.35); color: #ffd6d6; border-radius: 10px; font-size: 13px; text-align: center; }
.btn-primary { background: linear-gradient(135deg, var(--kh-red) 0%, var(--kh-red-2) 100%); color: #fff; font-size: 16px; font-weight: 700; padding: 16px; border-radius: 14px; width: 100%; margin-top: 18px; box-shadow: 0 6px 16px rgba(140, 24, 26, 0.40); }
.btn-primary:active { transform: scale(0.98); }
.btn-primary:disabled { opacity: 0.4; box-shadow: none; }

/* ─── LISTA MÁQUINAS ─── */
.greeting { background: var(--kh-card); border-radius: 14px; padding: 14px 16px; box-shadow: 0 2px 12px rgba(140, 24, 26, 0.08); margin-bottom: 14px; display: flex; align-items: center; gap: 12px; }
.greeting .day-icon { width: 44px; height: 44px; border-radius: 12px; background: var(--kh-red-bg); color: var(--kh-red); display: grid; place-items: center; font-size: 19px; font-weight: 800; flex-shrink: 0; }
.greeting .day-main { font-size: 14px; font-weight: 700; }
.greeting .day-sub { font-size: 12px; color: var(--kh-text-soft); margin-top: 2px; }
.greeting .day-refresh { margin-left: auto; width: 36px; height: 36px; border-radius: 10px; background: var(--kh-bg); display: grid; place-items: center; font-size: 18px; font-weight: 700; }

.maq-group { background: var(--kh-card); border-radius: 14px; box-shadow: 0 2px 12px rgba(140, 24, 26, 0.08); margin-bottom: 12px; overflow: hidden; }
.maq-group-head { background: linear-gradient(135deg, var(--kh-black) 0%, var(--kh-black-2) 100%); color: #fff; padding: 12px 16px; display: flex; align-items: center; gap: 10px; cursor: pointer; }
.maq-group-head:active { filter: brightness(1.1); }
.maq-group-head .icon-cog { width: 30px; height: 30px; border-radius: 8px; background: rgba(255,255,255,0.10); display: grid; place-items: center; font-size: 14px; }
.maq-group-head .name { font-size: 14px; font-weight: 700; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.maq-group-head .badge-n { background: var(--kh-red); color: #fff; font-size: 11px; font-weight: 800; padding: 3px 8px; border-radius: 10px; }
.maq-group-head .chev-down { font-size: 18px; transition: transform .2s; }
.maq-group.open .chev-down { transform: rotate(180deg); }
.maq-group-body { display: none; }
.maq-group.open .maq-group-body { display: block; }

.tarea-row { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--kh-line); cursor: pointer; background: var(--kh-card); }
.tarea-row:last-child { border-bottom: none; }
.tarea-row:active { background: var(--kh-bg); }
.tarea-row.vencida { border-left: 4px solid var(--kh-red); }
.tarea-row.hoy     { border-left: 4px solid var(--kh-amber); }
.tarea-row .info { flex: 1; min-width: 0; }
.tarea-row .titulo { font-size: 13.5px; font-weight: 700; color: var(--kh-text); margin-bottom: 2px; }
.tarea-row .meta { font-size: 12px; color: var(--kh-text-soft); }
.tarea-row .meta .per { color: var(--kh-red); font-weight: 700; }
.tarea-row .meta .est { color: var(--kh-amber); font-weight: 700; }
.tarea-row .chev { color: var(--kh-text-soft); font-size: 22px; }

.empty-state { background: var(--kh-card); border-radius: 14px; padding: 36px 24px; text-align: center; box-shadow: 0 2px 12px rgba(140, 24, 26, 0.08); }
.empty-state .e-icon { font-size: 44px; margin-bottom: 8px; }
.empty-state .e-title { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
.empty-state .e-sub { font-size: 13px; color: var(--kh-text-soft); }

/* ─── DETALLE + CRONÓMETRO ─── */
.detail-head { background: radial-gradient(ellipse at top right, rgba(140, 24, 26, 0.55) 0%, transparent 60%), linear-gradient(135deg, var(--kh-black) 0%, var(--kh-black-2) 100%); color: #fff; padding: 20px 18px 22px; margin: -16px -14px 14px; border-radius: 0 0 18px 18px; box-shadow: 0 2px 12px rgba(140, 24, 26, 0.10); }
.detail-head .maq-name { font-size: 17px; font-weight: 800; margin-bottom: 4px; }
.detail-head .per { font-size: 12px; opacity: 0.85; }
.badges-row { display: flex; gap: 6px; margin-top: 10px; flex-wrap: wrap; }
.badge { font-size: 10px; padding: 3px 8px; border-radius: 999px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
.badge.red    { background: var(--kh-red-bg);   color: var(--kh-red); }
.badge.amber  { background: var(--kh-amber-bg); color: var(--kh-amber); }
.badge.pink   { background: var(--kh-red-bg);   color: var(--kh-red); }

.field-row { display: flex; align-items: center; gap: 10px; padding: 12px 14px; background: var(--kh-card); border-radius: 12px; margin-bottom: 8px; box-shadow: 0 2px 8px rgba(140, 24, 26, 0.06); }
.field-row .lbl { font-size: 11px; color: var(--kh-text-soft); text-transform: uppercase; letter-spacing: 0.4px; font-weight: 700; min-width: 100px; }
.field-row .val { font-size: 14px; font-weight: 600; }

.timer-card { background: var(--kh-card); border-radius: 16px; padding: 22px 18px; box-shadow: 0 2px 12px rgba(140, 24, 26, 0.08); margin: 14px 0; text-align: center; }
.timer-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; color: var(--kh-text-soft); font-weight: 700; }
.timer-display { font-size: 52px; font-weight: 800; letter-spacing: -1px; margin: 8px 0 4px; font-variant-numeric: tabular-nums; color: var(--kh-red); }
.timer-display.paused { color: var(--kh-amber); }
.timer-display.idle   { color: var(--kh-text-soft); }
.timer-state {
    display: inline-block; padding: 3px 10px; border-radius: 10px;
    font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;
}
.timer-state.idle    { background: #eef0f4; color: var(--kh-text-soft); }
.timer-state.running { background: var(--kh-red-bg); color: var(--kh-red); }
.timer-state.paused  { background: var(--kh-amber-bg); color: var(--kh-amber); }

.timer-controls { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; }
.timer-controls.solo { grid-template-columns: 1fr; }
.btn-timer { padding: 14px; border-radius: 12px; font-size: 14px; font-weight: 800; letter-spacing: 0.3px; text-transform: uppercase; display: flex; align-items: center; justify-content: center; gap: 8px; }
.btn-start    { background: linear-gradient(135deg, var(--kh-red) 0%, var(--kh-red-dark) 100%); color: #fff; box-shadow: 0 4px 14px rgba(140, 24, 26, 0.30); }
.btn-pause    { background: linear-gradient(135deg, #d97706 0%, #b45309 100%); color: #fff; box-shadow: 0 4px 14px rgba(180, 83, 9, 0.30); }
.btn-resume   { background: linear-gradient(135deg, var(--kh-green) 0%, #14542e 100%); color: #fff; box-shadow: 0 4px 14px rgba(31, 138, 60, 0.30); }
.btn-cancel-t { background: var(--kh-bg); color: var(--kh-text); border: 1px solid var(--kh-line); }
.btn-finish-t { background: linear-gradient(135deg, var(--kh-green) 0%, #14542e 100%); color: #fff; box-shadow: 0 4px 14px rgba(31, 138, 60, 0.30); width: 100%; padding: 16px; border-radius: 12px; font-size: 15px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; margin-top: 10px; }
.btn-back-bar { display: block; width: 100%; padding: 14px; background: var(--kh-bg); color: var(--kh-text); border-radius: 12px; font-weight: 700; font-size: 14px; margin-top: 14px; }

/* ─── CONFIRMACIÓN ─── */
.conf-screen { background: linear-gradient(180deg, #fff 0%, var(--kh-bg) 60%); align-items: center; justify-content: center; padding: 26px 24px; text-align: center; flex: 1; display: flex; flex-direction: column; }
.conf-icon { width: 92px; height: 92px; border-radius: 50%; background: var(--kh-green-bg); display: grid; place-items: center; font-size: 44px; color: var(--kh-green); margin: 16px auto; animation: pop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
@keyframes pop { 0% { transform: scale(0.4); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
.conf-title { font-size: 22px; font-weight: 800; margin-bottom: 6px; }
.conf-sub { font-size: 13px; color: var(--kh-text-soft); margin-bottom: 22px; }
.summary { background: var(--kh-card); border-radius: 16px; padding: 14px 16px; width: 100%; max-width: 360px; box-shadow: 0 2px 8px rgba(140, 24, 26, 0.06); margin: 0 auto 18px; }
.summary .row { display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid var(--kh-line); font-size: 13.5px; }
.summary .row:last-child { border-bottom: none; }
.summary .row .lbl { color: var(--kh-text-soft); }
.summary .row .val { font-weight: 700; text-align: right; }

/* ─── Toast & Loader ─── */
.toast { position: fixed; left: 50%; bottom: 30px; transform: translateX(-50%); background: var(--kh-black); color: #fff; padding: 12px 18px; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,0.30); font-size: 13.5px; font-weight: 600; max-width: 92vw; text-align: center; z-index: 1000; }
.toast.error   { background: var(--kh-red); }
.toast.success { background: var(--kh-green); }
.toast.warn    { background: var(--kh-amber); color: #1a1a1a; }
.loader { position: fixed; inset: 0; z-index: 2000; background: rgba(0,0,0,0.55); display: grid; place-items: center; }
.spinner { width: 48px; height: 48px; border: 4px solid rgba(255,255,255,0.2); border-top-color: #fff; border-radius: 50%; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
<div id="app">

<!-- ════════════ PANTALLA 1 · LOGIN ════════════ -->
<div id="s-login" class="screen<?= $mantUser ? '' : ' active' ?>">
    <div class="login">
        <div class="brand-block">
            <svg class="brand-logo" viewBox="0 0 100 100" aria-label="KH">
                <g fill="#c8182b">
                    <circle cx="28" cy="28" r="20"/>
                    <circle cx="72" cy="28" r="14"/>
                    <circle cx="28" cy="72" r="14"/>
                    <circle cx="72" cy="72" r="20"/>
                </g>
                <circle cx="50" cy="50" r="5" fill="#c8182b"/>
            </svg>
            <div class="brand-sep"></div>
            <div class="brand-text">
                <div class="brand-name">KH</div>
                <div class="brand-sub">KNOW HOW</div>
            </div>
        </div>

        <h2>Identifícate</h2>
        <p class="helper">Introduce tu código de operario (4 cifras).</p>

        <input id="op-input" class="op-input" type="text" inputmode="numeric"
               maxlength="6" placeholder="• • • •" autocomplete="off" readonly>

        <div class="keypad">
            <button class="key" data-k="1">1</button>
            <button class="key" data-k="2">2</button>
            <button class="key" data-k="3">3</button>
            <button class="key" data-k="4">4</button>
            <button class="key" data-k="5">5</button>
            <button class="key" data-k="6">6</button>
            <button class="key" data-k="7">7</button>
            <button class="key" data-k="8">8</button>
            <button class="key" data-k="9">9</button>
            <button class="key dim" data-k="clear">×</button>
            <button class="key" data-k="0">0</button>
            <button class="key dim" data-k="back">⌫</button>
        </div>

        <div id="login-error" class="login-error" hidden>—</div>
        <button id="btn-login" class="btn-primary" disabled>Entrar</button>
    </div>
</div>

<!-- ════════════ PANTALLA 2 · LISTA DE MÁQUINAS ════════════ -->
<div id="s-list" class="screen<?= $mantUser ? ' active' : '' ?>">
    <div class="topbar">
        <div>
            <h1>Tareas pendientes</h1>
            <div class="sub" id="hello-sub">Operario —</div>
        </div>
        <button class="logout" id="btn-logout">Salir</button>
    </div>
    <div class="body">
        <div class="greeting">
            <div class="day-icon" id="day-icon">—</div>
            <div>
                <div class="day-main" id="day-main">—</div>
                <div class="day-sub">Pulsa una máquina para ver sus tareas</div>
            </div>
            <button class="day-refresh" id="btn-refresh" title="Recargar">↻</button>
        </div>
        <div id="lista-maquinas"></div>
        <div id="lista-empty" class="empty-state" hidden>
            <div class="e-icon">🎉</div>
            <div class="e-title">¡Sin tareas pendientes!</div>
            <div class="e-sub">No hay tareas vencidas ni que caduquen hoy.</div>
        </div>
    </div>
</div>

<!-- ════════════ PANTALLA 3 · DETALLE TAREA + CRONÓMETRO ════════════ -->
<div id="s-detail" class="screen">
    <div class="topbar">
        <button class="back" id="btn-back-detail">‹</button>
        <div>
            <h1>Tarea</h1>
            <div class="sub" id="detail-bread">—</div>
        </div>
    </div>
    <div class="body">
        <div class="detail-head">
            <div class="maq-name" id="d-maq">—</div>
            <div class="per" id="d-per">—</div>
            <div class="badges-row" id="d-badges"></div>
        </div>
        <div class="field-row"><div class="lbl">Tarea</div><div class="val" id="d-tarea">—</div></div>
        <div class="field-row"><div class="lbl">Descripción</div><div class="val" id="d-desc">—</div></div>
        <div class="field-row"><div class="lbl">Programada</div><div class="val" id="d-fecha">—</div></div>
        <div class="field-row"><div class="lbl">T. estimado</div><div class="val" id="d-est">—</div></div>
        <div class="field-row"><div class="lbl">Operario</div><div class="val" id="d-op">—</div></div>

        <!-- Cronómetro -->
        <div class="timer-card">
            <div class="timer-label">Tiempo de la revisión</div>
            <div id="timer-display" class="timer-display idle">00:00</div>
            <div><span id="timer-state" class="timer-state idle">Sin iniciar</span></div>

            <div id="timer-controls" class="timer-controls solo">
                <!-- Botones dinámicos según estado -->
            </div>

            <button id="btn-finish" class="btn-finish-t" hidden>
                ✓ Finalizar y marcar como realizada
            </button>
            <button id="btn-back-bar" class="btn-back-bar">‹ Volver a la lista</button>
        </div>

    </div>
</div>

<!-- ════════════ PANTALLA 4 · CONFIRMACIÓN ════════════ -->
<div id="s-conf" class="screen">
    <div class="conf-screen">
        <div class="conf-icon">✓</div>
        <div class="conf-title">Tarea registrada</div>
        <div class="conf-sub">Guardada en el histórico a tu nombre.</div>
        <div class="summary">
            <div class="row"><div class="lbl">Máquina</div><div class="val" id="c-maq">—</div></div>
            <div class="row"><div class="lbl">Tarea</div><div class="val" id="c-tarea">—</div></div>
            <div class="row"><div class="lbl">Operario</div><div class="val" id="c-op">—</div></div>
            <div class="row"><div class="lbl">Duración</div><div class="val" id="c-dur">—</div></div>
            <div class="row"><div class="lbl">Próxima</div><div class="val" id="c-next">—</div></div>
        </div>
        <button class="btn-primary" id="btn-continuar" style="max-width:360px">Continuar</button>
    </div>
</div>

</div>

<div id="toast" class="toast" hidden></div>
<div id="loader" class="loader" hidden><div class="spinner"></div></div>

<script>
// ════════════════════════════════════════════════════════════════
// APP MÓVIL · KH OPERARIO
// ════════════════════════════════════════════════════════════════
var CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var OPNUM = <?= json_encode((string)($mantUser ?? '')) ?>;

var PER_DAYS = { DIARIO: 1, DIARIA: 1, SEMANAL: 7, QUINCENAL: 15, MENSUAL: 30, BIMESTRAL: 60, BIMENSUAL: 60, TRIMESTRAL: 90, CUATRIMESTRAL: 120, SEMESTRAL: 180, ANUAL: 365 };

var state = {
    opnum: OPNUM,
    csrf: CSRF,
    maquinas: [],          // [{cod, desc, vencidas:[], hoy:[], abierto:bool}, ...]
    tareaActual: null,
    maquinaActual: null,
    timer: {
        running: false,
        paused: false,
        acumulado: 0,        // ms acumulados antes del último start
        ultimoStart: 0,      // timestamp ms del último arranque
        intervalId: null,
    }
};

function $(id) { return document.getElementById(id); }
function show(id) {
    var ss = document.querySelectorAll('.screen');
    for (var i = 0; i < ss.length; i++) ss[i].classList.remove('active');
    var el = $(id);
    if (el) el.classList.add('active');
    window.scrollTo(0, 0);
}
function loader(v) { var l = $('loader'); if (l) l.hidden = !v; }
function toast(msg, tipo) {
    var t = $('toast'); if (!t) return;
    t.textContent = msg;
    t.className = 'toast' + (tipo ? ' ' + tipo : '');
    t.hidden = false;
    setTimeout(function () { t.hidden = true; }, 3500);
}
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
function isoToday() {
    var d = new Date();
    var m = String(d.getMonth() + 1); if (m.length < 2) m = '0' + m;
    var dd = String(d.getDate()); if (dd.length < 2) dd = '0' + dd;
    return d.getFullYear() + '-' + m + '-' + dd;
}
function fmtFecha(iso) {
    if (!iso) return '—';
    var p = String(iso).split('-');
    if (p.length < 3) return iso;
    return p[2] + '/' + p[1] + '/' + p[0];
}
function fmtFechaLarga(iso) {
    if (!iso) return '—';
    var p = String(iso).split('-');
    var d = new Date(parseInt(p[0],10), parseInt(p[1],10) - 1, parseInt(p[2],10));
    var dias  = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    var meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return dias[d.getDay()] + ', ' + d.getDate() + ' de ' + meses[d.getMonth()];
}
function fmtDuracion(seg) {
    if (!seg && seg !== 0) return '—';
    seg = Math.floor(seg);
    var h = Math.floor(seg / 3600);
    var m = Math.floor((seg % 3600) / 60);
    var s = seg % 60;
    if (h > 0) return h + ' h ' + m + ' min';
    if (m > 0) return m + ' min ' + s + ' s';
    return s + ' s';
}
function fmtCrono(ms) {
    var seg = Math.floor(ms / 1000);
    var h = Math.floor(seg / 3600);
    var m = Math.floor((seg % 3600) / 60);
    var s = seg % 60;
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    if (h > 0) return pad(h) + ':' + pad(m) + ':' + pad(s);
    return pad(m) + ':' + pad(s);
}

// ─── fetch robusto que siempre devuelve JSON o lanza error útil ─
function call(url, body) {
    var opts = { method: body ? 'POST' : 'GET', cache: 'no-store' };
    if (body) {
        opts.headers = { 'Content-Type': 'application/json' };
        if (state.csrf) opts.headers['X-CSRF-Token'] = state.csrf;
        opts.body = JSON.stringify(body);
    }
    return fetch(url, opts).then(function (r) {
        var status = r.status;
        return r.text().then(function (txt) {
            var j = null;
            try { j = JSON.parse(txt); }
            catch (e) {
                // Mostramos el inicio de la respuesta para diagnóstico.
                var preview = (txt || '').replace(/\s+/g, ' ').substring(0, 140);
                if (preview === '') preview = '(respuesta vacía)';
                console.error('[mant_mobile] HTTP', status, 'NO-JSON:', txt);
                throw new Error('HTTP ' + status + ' · ' + preview);
            }
            if (!r.ok || !j.ok) throw new Error(j.error || ('HTTP ' + status));
            return j.data;
        });
    });
}

// ════════════════════════════════════════════════════════════════
//  PANTALLA 1 · LOGIN
// ════════════════════════════════════════════════════════════════
(function setupLogin() {
    var input = $('op-input');
    var btn = $('btn-login');
    var err = $('login-error');
    var keys = document.querySelectorAll('.keypad .key');
    for (var i = 0; i < keys.length; i++) {
        keys[i].addEventListener('click', function () {
            var v = this.getAttribute('data-k');
            if (v === 'back') input.value = input.value.slice(0, -1);
            else if (v === 'clear') input.value = '';
            else if (input.value.length < 6) input.value += v;
            btn.disabled = input.value.length < 4;
            err.hidden = true;
        });
    }
    btn.addEventListener('click', function () {
        if (input.value.length < 4) return;
        loader(true); err.hidden = true;
        call('../api/mant_login_movil.php', { numero: input.value }).then(function (d) {
            state.opnum = String(d.user || input.value);
            if (d.csrf_token) state.csrf = d.csrf_token;
            return cargarTareas();
        }).then(function () {
            loader(false);
            show('s-list');
        }).catch(function (e) {
            loader(false);
            err.textContent = e.message || 'Error al entrar';
            err.hidden = false;
        });
    });
})();

// ─── Salir ─────────────────────────────────────────────────────
$('btn-logout').addEventListener('click', function () {
    if (!confirm('¿Cerrar sesión?')) return;
    call('../api/mant_logout_json.php', {}).catch(function(){}).then(function () {
        state.opnum = '';
        $('op-input').value = '';
        $('btn-login').disabled = true;
        show('s-login');
    });
});

// ════════════════════════════════════════════════════════════════
//  PANTALLA 2 · LISTA DE MÁQUINAS CON TAREAS PENDIENTES
// ════════════════════════════════════════════════════════════════
function cargarTareas() {
    loader(true);
    return call('../api/mant_mobile.php?action=tasks_due').then(function (d) {
        state.opnum = state.opnum || '';
        $('hello-sub').textContent = 'Operario ' + state.opnum;
        var iso = d.fecha_hoy || isoToday();
        var p = iso.split('-');
        $('day-icon').textContent = parseInt(p[2], 10);
        $('day-main').textContent = fmtFechaLarga(iso);
        agruparPorMaquina(d.vencidas || [], d.hoy || []);
        renderMaquinas();
        loader(false);
    }).catch(function (e) {
        loader(false);
        toast('Error: ' + (e.message || e), 'error');
    });
}

function agruparPorMaquina(vencidas, hoy) {
    var map = {};
    function add(tarea, estado) {
        var cod = tarea.cod_maquina_mant || '__SIN__';
        if (!map[cod]) {
            map[cod] = {
                cod_maquina_mant: cod,
                desc_maquina:     tarea.desc_maquina || cod,
                tareas:           [],
                vencidas:         0,
                hoy:              0,
                abierto:          false
            };
        }
        tarea._estado = estado;
        map[cod].tareas.push(tarea);
        if (estado === 'vencida') map[cod].vencidas++;
        else                      map[cod].hoy++;
    }
    for (var i = 0; i < vencidas.length; i++) add(vencidas[i], 'vencida');
    for (var j = 0; j < hoy.length; j++)      add(hoy[j],      'hoy');

    // Ordenar máquinas alfabéticamente y tareas vencidas primero
    var arr = [];
    for (var k in map) if (Object.prototype.hasOwnProperty.call(map, k)) arr.push(map[k]);
    arr.sort(function (a, b) { return String(a.desc_maquina).localeCompare(String(b.desc_maquina)); });
    for (var i = 0; i < arr.length; i++) {
        arr[i].tareas.sort(function (a, b) {
            if (a._estado !== b._estado) return a._estado === 'vencida' ? -1 : 1;
            return String(a.proxima_revision).localeCompare(String(b.proxima_revision));
        });
    }
    state.maquinas = arr;
}

function renderMaquinas() {
    var wrap = $('lista-maquinas');
    if (!state.maquinas.length) {
        wrap.innerHTML = '';
        $('lista-empty').hidden = false;
        return;
    }
    $('lista-empty').hidden = true;
    wrap.innerHTML = state.maquinas.map(function (m, idx) {
        var totalBadge = m.tareas.length;
        var tareasHtml = m.tareas.map(function (t, ti) {
            var estado = t._estado;
            var dias = Math.abs(t.dias_restantes || 0);
            var info = estado === 'vencida' ? ('vence hace ' + dias + ' d') : 'hoy';
            var tEst = t.tiempo_estimado ? (' · <span class="est">~' + t.tiempo_estimado + ' min</span>') : '';
            return '<div class="tarea-row ' + estado + '" data-mi="' + idx + '" data-ti="' + ti + '">' +
                   '<div class="info">' +
                     '<div class="titulo">Tarea ' + esc(t.tarea) + ' · ' + esc(t.desc_tarea || '') + '</div>' +
                     '<div class="meta"><span class="per">' + esc(t.periodicidad) + '</span> · ' + info + tEst + '</div>' +
                   '</div>' +
                   '<div class="chev">›</div>' +
                   '</div>';
        }).join('');
        var open = m.abierto ? ' open' : '';
        return '<div class="maq-group' + open + '" data-mi="' + idx + '">' +
               '<div class="maq-group-head">' +
                 '<div class="icon-cog">⚙</div>' +
                 '<div class="name">' + esc(m.desc_maquina) + '</div>' +
                 '<div class="badge-n">' + totalBadge + '</div>' +
                 '<div class="chev-down">▾</div>' +
               '</div>' +
               '<div class="maq-group-body">' + tareasHtml + '</div>' +
               '</div>';
    }).join('');

    // Toggle de la máquina
    var heads = wrap.querySelectorAll('.maq-group-head');
    for (var i = 0; i < heads.length; i++) {
        heads[i].addEventListener('click', function () {
            var idx = parseInt(this.parentNode.getAttribute('data-mi'), 10);
            state.maquinas[idx].abierto = !state.maquinas[idx].abierto;
            this.parentNode.classList.toggle('open', state.maquinas[idx].abierto);
        });
    }
    // Click en tarea
    var rows = wrap.querySelectorAll('.tarea-row');
    for (var j = 0; j < rows.length; j++) {
        rows[j].addEventListener('click', function () {
            var mi = parseInt(this.getAttribute('data-mi'), 10);
            var ti = parseInt(this.getAttribute('data-ti'), 10);
            abrirTarea(state.maquinas[mi], state.maquinas[mi].tareas[ti]);
        });
    }
}

$('btn-refresh').addEventListener('click', cargarTareas);

// ════════════════════════════════════════════════════════════════
//  PANTALLA 3 · DETALLE + CRONÓMETRO
// ════════════════════════════════════════════════════════════════
function abrirTarea(maq, t) {
    state.tareaActual = t;
    state.maquinaActual = maq;
    $('d-maq').textContent = maq.desc_maquina;
    $('detail-bread').textContent = maq.desc_maquina;
    $('d-per').textContent = 'Periodicidad ' + (t.periodicidad || '—');
    $('d-badges').innerHTML =
        (t._estado === 'vencida' ? '<span class="badge red">Vencida</span>' : '<span class="badge amber">Hoy</span>') +
        ' <span class="badge pink">' + esc(t.periodicidad || '') + '</span>';
    $('d-tarea').textContent = t.tarea || '—';
    $('d-desc').textContent = t.desc_tarea || '—';
    $('d-fecha').textContent = fmtFecha(t.proxima_revision);
    $('d-est').textContent = t.tiempo_estimado ? (t.tiempo_estimado + ' min') : '—';
    $('d-op').textContent = state.opnum;

    resetTimer();
    renderTimerControles('idle');
    show('s-detail');
}

// Estado del cronómetro: 'idle', 'running', 'paused'
function getTimerState() {
    if (state.timer.running)  return 'running';
    if (state.timer.paused)   return 'paused';
    return 'idle';
}
function getTimerMs() {
    var acum = state.timer.acumulado;
    if (state.timer.running) acum += Date.now() - state.timer.ultimoStart;
    return acum;
}
function tickTimer() {
    $('timer-display').textContent = fmtCrono(getTimerMs());
}
function arrancarTimer() {
    state.timer.running = true;
    state.timer.paused = false;
    state.timer.ultimoStart = Date.now();
    if (state.timer.intervalId) clearInterval(state.timer.intervalId);
    state.timer.intervalId = setInterval(tickTimer, 500);
    tickTimer();
    $('timer-display').classList.remove('idle', 'paused');
    renderTimerControles('running');
}
function pausarTimer() {
    if (!state.timer.running) return;
    state.timer.acumulado += Date.now() - state.timer.ultimoStart;
    state.timer.running = false;
    state.timer.paused = true;
    if (state.timer.intervalId) { clearInterval(state.timer.intervalId); state.timer.intervalId = null; }
    $('timer-display').classList.remove('idle');
    $('timer-display').classList.add('paused');
    tickTimer();
    renderTimerControles('paused');
}
function reanudarTimer() {
    state.timer.running = true;
    state.timer.paused = false;
    state.timer.ultimoStart = Date.now();
    if (state.timer.intervalId) clearInterval(state.timer.intervalId);
    state.timer.intervalId = setInterval(tickTimer, 500);
    $('timer-display').classList.remove('paused', 'idle');
    tickTimer();
    renderTimerControles('running');
}
function resetTimer() {
    state.timer.running = false;
    state.timer.paused = false;
    state.timer.acumulado = 0;
    state.timer.ultimoStart = 0;
    if (state.timer.intervalId) { clearInterval(state.timer.intervalId); state.timer.intervalId = null; }
    $('timer-display').textContent = '00:00';
    $('timer-display').classList.add('idle');
    $('timer-display').classList.remove('paused');
}
function cancelarTarea() {
    if (state.timer.acumulado > 0 || state.timer.running) {
        if (!confirm('¿Cancelar la revisión? Se descartará el tiempo cronometrado.')) return;
    }
    resetTimer();
    state.tareaActual = null;
    state.maquinaActual = null;
    show('s-list');
}

function renderTimerControles(estado) {
    var wrap = $('timer-controls');
    var stateEl = $('timer-state');
    var btnFinish = $('btn-finish');
    wrap.className = 'timer-controls';
    stateEl.className = 'timer-state';
    if (estado === 'idle') {
        wrap.classList.add('solo');
        stateEl.classList.add('idle');
        stateEl.textContent = 'Sin iniciar';
        wrap.innerHTML =
            '<button class="btn-timer btn-start" id="btn-start">▶ Iniciar revisión</button>';
        btnFinish.hidden = true;
        $('btn-start').addEventListener('click', arrancarTimer);
    } else if (estado === 'running') {
        stateEl.classList.add('running');
        stateEl.textContent = '● En curso';
        wrap.innerHTML =
            '<button class="btn-timer btn-pause" id="btn-pause">⏸ Pausar</button>' +
            '<button class="btn-timer btn-cancel-t" id="btn-cancel">Cancelar</button>';
        btnFinish.hidden = false;
        $('btn-pause').addEventListener('click', pausarTimer);
        $('btn-cancel').addEventListener('click', cancelarTarea);
    } else if (estado === 'paused') {
        stateEl.classList.add('paused');
        stateEl.textContent = '⏸ En pausa';
        wrap.innerHTML =
            '<button class="btn-timer btn-resume" id="btn-resume">▶ Reanudar</button>' +
            '<button class="btn-timer btn-cancel-t" id="btn-cancel">Cancelar</button>';
        btnFinish.hidden = false;
        $('btn-resume').addEventListener('click', reanudarTimer);
        $('btn-cancel').addEventListener('click', cancelarTarea);
    }
}

$('btn-back-detail').addEventListener('click', function () {
    if (state.timer.acumulado > 0 || state.timer.running) {
        if (!confirm('¿Volver a la lista? Se descartará el tiempo cronometrado.')) return;
    }
    resetTimer();
    state.tareaActual = null;
    state.maquinaActual = null;
    show('s-list');
});
$('btn-back-bar').addEventListener('click', function () {
    if (state.timer.acumulado > 0 || state.timer.running) {
        if (!confirm('¿Volver a la lista? Se descartará el tiempo cronometrado.')) return;
    }
    resetTimer();
    state.tareaActual = null;
    state.maquinaActual = null;
    show('s-list');
});

$('btn-finish').addEventListener('click', function () {
    var t = state.tareaActual;
    if (!t) return;
    // Pausamos el cronómetro para fijar el tiempo acumulado.
    if (state.timer.running) {
        state.timer.acumulado += Date.now() - state.timer.ultimoStart;
        state.timer.running = false;
        if (state.timer.intervalId) { clearInterval(state.timer.intervalId); state.timer.intervalId = null; }
    }
    var segundos = Math.round(state.timer.acumulado / 1000);
    if (segundos < 5) {
        if (!confirm('El cronómetro está casi a 0. ¿Marcar igualmente como realizada?')) return;
    }
    loader(true);
    call('../api/mant_marcar_hecha.php', {
        orden:                  t.orden,
        tarea:                  t.tarea,
        fecha_proxima_original: t.proxima_revision,
        fecha_intervencion:     isoToday(),
        operario:               state.opnum,
        observaciones:          '',
        tiempo_real_segundos:   segundos
    }).then(function () {
        var dias = PER_DAYS[(t.periodicidad || '').toUpperCase()] || null;
        var prox = null;
        if (dias) {
            var d = new Date();
            d.setDate(d.getDate() + dias);
            var m = String(d.getMonth() + 1); if (m.length < 2) m = '0' + m;
            var dd = String(d.getDate()); if (dd.length < 2) dd = '0' + dd;
            prox = d.getFullYear() + '-' + m + '-' + dd;
        }
        $('c-maq').textContent = state.maquinaActual.desc_maquina || '—';
        $('c-tarea').textContent = (t.tarea || '—') + ' · ' + (t.desc_tarea || '');
        $('c-op').textContent = state.opnum;
        $('c-dur').textContent = fmtDuracion(segundos);
        $('c-next').textContent = prox ? fmtFecha(prox) : '—';
        resetTimer();
        state.tareaActual = null;
        state.maquinaActual = null;
        loader(false);
        show('s-conf');
    }).catch(function (e) {
        loader(false);
        toast('Error: ' + (e.message || e), 'error');
    });
});

$('btn-continuar').addEventListener('click', function () {
    cargarTareas().then(function () { show('s-list'); });
});

// ─── Init ─────────────────────────────────────────────────────
if (state.opnum) {
    cargarTareas().catch(function () { /* err ya mostrado */ });
}
</script>
</body>
</html>
