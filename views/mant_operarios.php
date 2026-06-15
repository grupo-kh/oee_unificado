<?php
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();
// Acceso 100% restringido al rol técnico.
if (!Auth::isTecnico()) {
    header('Location: mantenimiento.php');
    exit;
}

$pageTitle    = 'Mantenimiento · Gestión de Operarios';
$backLink     = 'mantenimiento.php';
$hideFiltros  = true;
$mantUserRole = Auth::role();
$mantUserName = Auth::user();
include __DIR__ . '/../includes/header.php';
?>
<style>
    .ops-table-wrap { background:#fff; border-radius:8px; box-shadow:0 1px 3px rgba(15,28,48,.08); overflow:hidden; }
    .ops-table { width:100%; border-collapse:collapse; font-size:13px; }
    .ops-table thead th {
        background:#2d4d7a; color:#fff; padding:10px 12px; text-align:left;
        font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.4px;
    }
    .ops-table tbody td { padding:9px 12px; border-bottom:1px solid #eef2f6; vertical-align:middle; }
    .ops-table tbody tr:hover { background:#f8fafc; }
    .ops-table tbody tr.ops-row-baja { opacity:.55; background:#fdf5f3; }

    .ops-toolbar {
        display:flex; align-items:center; gap:10px; flex-wrap:wrap;
        margin-bottom:14px; padding:12px; background:#fff; border-radius:6px;
        box-shadow:0 1px 3px rgba(15,28,48,.06);
    }
    .ops-search-box { flex:1; min-width:220px; position:relative; }
    .ops-search-box input {
        width:100%; padding:8px 12px 8px 36px; border:1px solid #c5d2e0;
        border-radius:5px; font-size:13.5px;
    }
    .ops-search-icon {
        position:absolute; left:10px; top:50%; transform:translateY(-50%);
        color:#5b6f86;
    }
    .ops-btn {
        padding:8px 14px; border:0; border-radius:5px; font-weight:600;
        font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;
    }
    .ops-btn-primary  { background:#c8102e; color:#fff; }
    .ops-btn-primary:hover { background:#a00d24; }
    .ops-btn-icon     { background:transparent; border:1px solid #c5d2e0; color:#2d4d7a; padding:5px 10px; font-size:13px; }
    .ops-btn-icon:hover { background:#eef2f6; }
    .ops-btn-icon.danger:hover { background:#fdecec; border-color:#e9a4a4; color:#8a0d22; }

    .ops-counter   { color:#5b6f86; font-size:12.5px; font-weight:600; margin-left:auto; }
    .ops-only-active {
        display:flex; align-items:center; gap:6px;
        font-size:12.5px; color:#2d4d7a; user-select:none; cursor:pointer;
    }

    .ops-puesto-pill {
        display:inline-block; padding:2px 8px; font-size:11px; font-weight:600;
        border-radius:11px; background:#eef2f6; color:#2d4d7a; white-space:nowrap;
    }
    .ops-cap-pills { display:flex; flex-wrap:wrap; gap:4px; }
    .ops-cap-pill {
        display:inline-block; padding:1px 7px; font-size:10.5px; font-weight:700;
        border-radius:3px;
    }
    .ops-cap-p25  { background:#dbeafe; color:#1e3a8a; }
    .ops-cap-p50  { background:#bfdbfe; color:#1e40af; }
    .ops-cap-p75  { background:#93c5fd; color:#1e3a8a; }
    .ops-cap-p100 { background:#3b82f6; color:#fff; }
    .ops-cap-taller { background:#fde68a; color:#78350f; }

    .ops-baja-badge {
        display:inline-block; padding:1px 6px; font-size:10px; font-weight:700;
        border-radius:3px; background:#fdecec; color:#8a0d22; letter-spacing:.3px;
    }

    /* Modal de edición */
    .ops-modal-backdrop {
        position:fixed; inset:0; background:rgba(15,28,48,.55); backdrop-filter:blur(2px);
        z-index:9000; display:none;
    }
    .ops-modal-backdrop.open { display:flex; align-items:center; justify-content:center; }
    .ops-modal {
        background:#fff; border-radius:10px; width:540px; max-width:96vw;
        max-height:92vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,.35);
    }
    .ops-modal-header {
        display:flex; align-items:center; gap:10px;
        background:linear-gradient(135deg,#1a4a7a 0%,#2d4d7a 100%); color:#fff;
        padding:12px 18px; border-radius:10px 10px 0 0;
    }
    .ops-modal-header h3 { margin:0; font-size:15px; }
    .ops-modal-close {
        margin-left:auto; background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.32);
        color:#fff; font-size:18px; width:30px; height:30px; border-radius:5px; cursor:pointer;
    }
    .ops-modal-body { padding:18px; }
    .ops-modal-footer {
        padding:12px 18px; background:#f8fafc; border-top:1px solid #eef2f6;
        display:flex; justify-content:flex-end; gap:8px;
    }
    .ops-form-grid {
        display:grid; grid-template-columns:1fr 1fr; gap:12px;
    }
    .ops-form-grid .span2 { grid-column:span 2; }
    .ops-form-row label  { display:block; font-size:11.5px; font-weight:600; color:#2d4d7a; margin-bottom:3px; }
    .ops-form-row input,
    .ops-form-row select {
        width:100%; padding:6px 10px; border:1px solid #c5d2e0; border-radius:4px;
        font-size:13px; background:#fff;
    }
    .ops-form-row input:disabled { background:#f4f7fb; color:#5b6f86; }
    .ops-caps-grid {
        display:flex; flex-wrap:wrap; gap:10px; padding:8px;
        background:#f4f7fb; border-radius:5px;
    }
    .ops-cap-check {
        display:flex; align-items:center; gap:5px; font-size:12.5px;
        font-weight:600; cursor:pointer; padding:4px 8px;
        background:#fff; border:1px solid #c5d2e0; border-radius:4px;
    }
    .ops-cap-check input { margin:0; }
    .ops-help { font-size:11px; color:#5b6f86; font-style:italic; margin-top:4px; }
</style>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Gestión de operarios</h2>
            <span class="view-card-info" id="ops-info">—</span>
        </div>
        <div class="view-card-body">
            <div style="background:#eef3f8;border-left:4px solid #2d4d7a;padding:10px 14px;margin-bottom:14px;border-radius:6px;font-size:13px;color:#1a2d4a">
                <strong>Gestión de operarios</strong> · Da de alta operarios con su puesto y capacitación.
                Las capacitaciones del 25 / 50 / 75 / 100 % son <strong>acumulativas</strong>: marcar el 75 % implica que también realiza tareas del 25 y 50 %. <strong>Racks</strong> es independiente.
                Al rellenar <em>Fecha de baja</em> el operario queda inactivo automáticamente.
            </div>

            <div class="ops-toolbar">
                <div class="ops-search-box">
                    <svg class="ops-search-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="search" id="ops-search" placeholder="Buscar por código, nombre, apellidos o puesto…" autocomplete="off">
                </div>
                <label class="ops-only-active">
                    <input type="checkbox" id="ops-only-active"> Solo activos
                </label>
                <button type="button" id="ops-new-btn" class="ops-btn ops-btn-primary">+ Nuevo operario</button>
                <span class="ops-counter" id="ops-counter">— operarios</span>
            </div>

            <div class="ops-table-wrap">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th style="width:80px">Código</th>
                            <th>Apellidos</th>
                            <th>Nombre</th>
                            <th style="width:110px">Fecha alta</th>
                            <th style="width:110px">Fecha baja</th>
                            <th style="width:200px">Puesto</th>
                            <th>Capacitación</th>
                            <th style="width:96px;text-align:right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="ops-tbody">
                        <tr><td colspan="8" style="text-align:center;padding:24px;color:#5b6f86;font-style:italic">Cargando…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Modal: alta / edición ───────────────────────────────────────────────── -->
<div class="ops-modal-backdrop" id="ops-modal-backdrop">
    <div class="ops-modal" role="dialog" aria-modal="true" aria-labelledby="ops-modal-title">
        <div class="ops-modal-header">
            <h3 id="ops-modal-title">Nuevo operario</h3>
            <button type="button" class="ops-modal-close" id="ops-modal-close" aria-label="Cerrar">×</button>
        </div>
        <div class="ops-modal-body">
            <div class="ops-form-grid">
                <div class="ops-form-row">
                    <label for="ops-f-numero">Código *</label>
                    <input type="text" id="ops-f-numero" inputmode="numeric" pattern="[0-9]+" placeholder="Ej. 1004" maxlength="50">
                    <div class="ops-help">Numérico, único, no se puede cambiar tras crear.</div>
                </div>
                <div class="ops-form-row">
                    <label for="ops-f-puesto">Puesto</label>
                    <select id="ops-f-puesto">
                        <option value="">— Sin asignar —</option>
                    </select>
                </div>
                <div class="ops-form-row">
                    <label for="ops-f-apellidos">Apellidos</label>
                    <input type="text" id="ops-f-apellidos" maxlength="120">
                </div>
                <div class="ops-form-row">
                    <label for="ops-f-nombre">Nombre</label>
                    <input type="text" id="ops-f-nombre" maxlength="120">
                </div>
                <div class="ops-form-row">
                    <label for="ops-f-alta">Fecha alta</label>
                    <input type="date" id="ops-f-alta">
                </div>
                <div class="ops-form-row">
                    <label for="ops-f-baja">Fecha baja</label>
                    <input type="date" id="ops-f-baja">
                </div>
                <div class="ops-form-row span2">
                    <label>Capacitación</label>
                    <div class="ops-caps-grid" id="ops-caps-grid">
                        <!-- relleno dinámico por JS -->
                    </div>
                    <div class="ops-help">25 / 50 / 75 / 100 % son acumulativas: al guardar, marcar 75 % añade 25 % y 50 % automáticamente. Racks es independiente.</div>
                </div>
            </div>
        </div>
        <div class="ops-modal-footer">
            <button type="button" id="ops-cancel-btn" class="ops-btn ops-btn-icon">Cancelar</button>
            <button type="button" id="ops-save-btn"   class="ops-btn ops-btn-primary">Guardar</button>
        </div>
    </div>
</div>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<script src="../assets/js/common.js?v=<?= filemtime(__DIR__ . '/../assets/js/common.js') ?>"></script>
<script src="../assets/js/view_mant_operarios.js?v=<?= filemtime(__DIR__ . '/../assets/js/view_mant_operarios.js') ?>"></script>
