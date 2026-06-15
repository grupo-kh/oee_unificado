<?php
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();
if (!Auth::isTecnico()) {
    header('Location: mantenimiento.php');
    exit;
}

$pageTitle    = 'Mantenimiento · Calendario laboral';
$backLink     = 'mantenimiento.php';
$hideFiltros  = true;
$mantUserRole = Auth::role();
$mantUserName = Auth::user();
include __DIR__ . '/../includes/header.php';
?>
<style>
    .cal-toolbar {
        display:flex; align-items:center; gap:12px; flex-wrap:wrap;
        background:#fff; padding:12px 16px; border-radius:6px;
        box-shadow:0 1px 3px rgba(15,28,48,.06); margin-bottom:14px;
    }
    .cal-nav-btn {
        background:#eef2f6; border:1px solid #c5d2e0; color:#2d4d7a;
        padding:7px 14px; font-size:15px; font-weight:700; border-radius:5px;
        cursor:pointer;
    }
    .cal-nav-btn:hover { background:#dbe7f3; }
    .cal-anyo-label { font-size:22px; font-weight:700; color:#1a2d4a; min-width:90px; text-align:center; }
    .cal-today-btn {
        background:#2d4d7a; border:0; color:#fff;
        padding:7px 14px; font-size:12.5px; font-weight:700; border-radius:5px;
        cursor:pointer;
    }
    .cal-today-btn:hover { background:#1a4a7a; }
    .cal-stats { font-size:12.5px; color:#5b6f86; }
    .cal-stats strong { color:#2d4d7a; }

    /* Leyenda */
    .cal-legenda {
        display:flex; flex-wrap:wrap; gap:14px;
        background:#eef3f8; border-left:4px solid #2d4d7a; padding:10px 14px;
        border-radius:6px; margin-bottom:14px; font-size:12.5px; color:#1a2d4a;
    }
    .cal-legenda-item { display:flex; align-items:center; gap:6px; }
    .cal-legenda-sw { width:14px; height:14px; border-radius:3px; display:inline-block; }
    .cal-legenda-sw.habil    { background:#dcfce7; border:1px solid #86efac; }
    .cal-legenda-sw.no-habil { background:#fee2e2; border:1px solid #fca5a5; }
    .cal-legenda-sw.exc      { background:#fde68a; border:1px solid #d97706; }
    .cal-legenda-sw.tasks    { background:#3a6aa3; }

    /* Grid anual: 4 columnas × 3 filas */
    .cal-year-grid {
        display:grid; grid-template-columns:repeat(4, 1fr); gap:12px;
    }
    .cal-month {
        background:#fff; border-radius:8px; padding:10px;
        box-shadow:0 1px 3px rgba(15,28,48,.08);
    }
    .cal-month-title {
        font-size:14px; font-weight:700; color:#2d4d7a;
        text-transform:capitalize; margin:0 0 6px 0; text-align:center;
        padding-bottom:5px; border-bottom:1px solid #eef2f6;
    }
    .cal-month-dow {
        display:grid; grid-template-columns:repeat(7, 1fr); gap:2px;
        margin-bottom:3px;
    }
    .cal-month-dow > span {
        text-align:center; font-size:10px; font-weight:700;
        color:#5b6f86; text-transform:uppercase; padding:1px 0;
    }
    .cal-month-dow > span.weekend { color:#a00; }
    .cal-month-grid {
        display:grid; grid-template-columns:repeat(7, 1fr); gap:2px;
    }
    .cal-day-cell {
        position:relative;
        display:flex; align-items:center; justify-content:center;
        aspect-ratio:1 / 1;
        font-size:11.5px; font-weight:700;
        background:#dcfce7;                       /* hábil */
        color:#1a2d4a;
        border-radius:3px;
        cursor:pointer;
        user-select:none;
        transition:transform .08s, box-shadow .12s;
    }
    .cal-day-cell:hover { transform:scale(1.06); box-shadow:0 1px 6px rgba(15,28,48,.18); z-index:2; }
    .cal-day-cell.empty { background:transparent; cursor:default; visibility:hidden; }
    .cal-day-cell.no-habil { background:#fee2e2; color:#7f1d1d; text-decoration:line-through; text-decoration-color:#dc2626; }
    .cal-day-cell.excepcion { outline:2px solid #d97706; outline-offset:-2px; }
    .cal-day-cell.today { box-shadow:0 0 0 2px #2d4d7a inset; }

    /* Badge azul con el nº de tareas */
    .cal-day-tasks {
        position:absolute; top:-3px; right:-3px;
        background:#3a6aa3; color:#fff;
        font-size:8px; font-weight:800;
        min-width:13px; height:13px; padding:0 3px;
        border-radius:7px;
        display:flex; align-items:center; justify-content:center;
        line-height:1;
    }

    /* Modal: corto, con motivo opcional */
    .cal-modal-bg {
        position:fixed; inset:0; background:rgba(15,28,48,.55); backdrop-filter:blur(2px);
        z-index:9000; display:none;
    }
    .cal-modal-bg.open { display:flex; align-items:center; justify-content:center; }
    .cal-modal {
        background:#fff; border-radius:10px; width:440px; max-width:96vw;
        box-shadow:0 10px 40px rgba(0,0,0,.35);
    }
    .cal-modal-head {
        display:flex; align-items:center; padding:12px 18px;
        background:linear-gradient(135deg,#1a4a7a 0%,#2d4d7a 100%); color:#fff;
        border-radius:10px 10px 0 0;
    }
    .cal-modal-head h3 { margin:0; font-size:14.5px; flex:1; }
    .cal-modal-close {
        background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.32);
        color:#fff; font-size:18px; width:28px; height:28px; border-radius:5px; cursor:pointer;
    }
    .cal-modal-body { padding:16px 18px; }
    .cal-modal-foot {
        padding:10px 18px; background:#f8fafc; border-top:1px solid #eef2f6;
        display:flex; justify-content:flex-end; gap:8px;
    }
    .cal-modal-info {
        background:#eef3f8; border-left:4px solid #2d4d7a;
        padding:8px 12px; border-radius:5px; font-size:12.5px; margin-bottom:12px; line-height:1.5;
    }
    .cal-modal label { display:block; font-size:11.5px; font-weight:600; color:#2d4d7a; margin-bottom:3px; }
    .cal-modal input[type=text] {
        width:100%; padding:6px 10px; border:1px solid #c5d2e0; border-radius:4px; font-size:13px;
    }
    .cal-btn {
        padding:7px 14px; border:0; border-radius:5px; font-weight:600;
        font-size:12.5px; cursor:pointer;
    }
    .cal-btn-primary  { background:#c8102e; color:#fff; }
    .cal-btn-primary:hover { background:#a00d24; }
    .cal-btn-icon { background:transparent; border:1px solid #c5d2e0; color:#2d4d7a; }
    .cal-btn-icon:hover { background:#eef2f6; }
</style>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Calendario laboral de mantenimiento</h2>
            <span class="view-card-info" id="cal-info">—</span>
        </div>
        <div class="view-card-body">
            <div class="cal-legenda">
                <span class="cal-legenda-item"><span class="cal-legenda-sw habil"></span> Día laborable</span>
                <span class="cal-legenda-item"><span class="cal-legenda-sw no-habil"></span> No laborable (S/D/festivo)</span>
                <span class="cal-legenda-item"><span class="cal-legenda-sw exc"></span> Excepción (borde naranja)</span>
                <span class="cal-legenda-item"><span class="cal-legenda-sw tasks"></span> Badge azul = nº tareas</span>
                <span style="flex:1"></span>
                <span style="font-size:11.5px;color:#5b6f86;font-style:italic">
                    Clic sobre un día para alternar laborable / no laborable. El recálculo es automático.
                </span>
            </div>

            <div class="cal-toolbar">
                <button type="button" class="cal-nav-btn" id="cal-prev" title="Año anterior">◀</button>
                <span class="cal-anyo-label" id="cal-anyo-label">—</span>
                <button type="button" class="cal-nav-btn" id="cal-next" title="Año siguiente">▶</button>
                <button type="button" class="cal-today-btn" id="cal-today">Hoy</button>
                <span style="flex:1"></span>
                <span class="cal-stats" id="cal-stats">—</span>
            </div>

            <div class="cal-year-grid" id="cal-year-grid">
                <!-- Render dinámico: 12 mini-calendarios -->
            </div>
        </div>
    </div>
</main>

<!-- Modal corto: confirmar cambio + motivo opcional ─────────────────── -->
<div class="cal-modal-bg" id="cal-modal-bg">
    <div class="cal-modal" role="dialog" aria-modal="true">
        <div class="cal-modal-head">
            <h3 id="cal-modal-title">Cambiar día</h3>
            <button type="button" class="cal-modal-close" id="cal-modal-close" aria-label="Cerrar">×</button>
        </div>
        <div class="cal-modal-body">
            <div class="cal-modal-info" id="cal-modal-info">—</div>
            <label for="cal-motivo">Motivo (opcional)</label>
            <input type="text" id="cal-motivo" placeholder="Ej. Festivo local, Inventario, Puente del 6D…">
        </div>
        <div class="cal-modal-foot">
            <button type="button" id="cal-modal-cancel" class="cal-btn cal-btn-icon">Cancelar</button>
            <button type="button" id="cal-modal-save"   class="cal-btn cal-btn-primary">Aceptar</button>
        </div>
    </div>
</div>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<script src="../assets/js/common.js?v=<?= filemtime(__DIR__ . '/../assets/js/common.js') ?>"></script>
<script src="../assets/js/view_mant_calendario.js?v=<?= filemtime(__DIR__ . '/../assets/js/view_mant_calendario.js') ?>"></script>
