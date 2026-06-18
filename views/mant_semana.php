<?php
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();

$pageTitle    = 'Mantenimiento · Planificador de Tareas';
$backLink     = 'mantenimiento.php';
$hideFiltros  = true;
$mantUserRole = Auth::role();
$mantUserName = Auth::user();
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Planificador de tareas <span id="header-scope" class="header-scope"></span></h2>
            <span class="view-card-info" id="info-line">—</span>
        </div>
        <div class="view-card-body">

            <div class="dual-selector-row mant-no-print">
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="fecha-desde" class="machine-selector-label">Desde:</label>
                    <input type="date" id="fecha-desde" class="machine-selector" style="min-width:150px">
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="fecha-hasta" class="machine-selector-label">Hasta:</label>
                    <input type="date" id="fecha-hasta" class="machine-selector" style="min-width:150px">
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label class="machine-selector-label">Atajos:</label>
                    <div class="mant-quick-range">
                        <button type="button" class="mant-quick-btn" data-range="week">Esta semana</button>
                        <button type="button" class="mant-quick-btn" data-range="7">+7d</button>
                        <button type="button" class="mant-quick-btn" data-range="14">+14d</button>
                        <button type="button" class="mant-quick-btn" data-range="30">+30d</button>
                    </div>
                </div>
                <div class="machine-selector-row" style="flex:1">
                    <label for="machine-selector" class="machine-selector-label">Máquina:</label>
                    <select id="machine-selector" class="machine-selector">
                        <option value="">— Todas —</option>
                    </select>
                </div>
                <div class="machine-selector-row" style="flex:1">
                    <label for="periodicidad-selector" class="machine-selector-label">Periodicidad:</label>
                    <select id="periodicidad-selector" class="machine-selector">
                        <option value="">— Todas —</option>
                    </select>
                </div>
                <button id="filter-clear" class="machine-selector-clear" type="button" style="display:none">× Quitar filtros</button>
            </div>

            <div class="mant-stats" id="mant-stats">
                <div class="mant-stat mant-stat-pendientes"><span class="mant-stat-value" id="stat-pendientes">—</span><span class="mant-stat-label">Pendientes</span></div>
                <div class="mant-stat mant-stat-vencidas"><span class="mant-stat-value" id="stat-vencidas">—</span><span class="mant-stat-label">Vencidas</span></div>
                <div class="mant-stat mant-stat-urgentes"><span class="mant-stat-value" id="stat-urgentes">—</span><span class="mant-stat-label">Urgentes (≤7d)</span></div>
                <div class="mant-stat mant-stat-en-plazo"><span class="mant-stat-value" id="stat-en-plazo">—</span><span class="mant-stat-label">En plazo</span></div>
                <div class="mant-stat mant-stat-total"><span class="mant-stat-value" id="stat-total">—</span><span class="mant-stat-label">Tareas</span></div>
                <div class="mant-stat mant-stat-total"><span class="mant-stat-value" id="stat-maquinas">—</span><span class="mant-stat-label">Máquinas</span></div>
            </div>

            <div class="mant-actions-row mant-no-print">
                <button type="button" id="btn-print" class="mant-export-btn mant-export-print">🖨️ Imprimir</button>
                <button type="button" id="btn-export-csv" class="mant-export-btn mant-export-csv">⬇ Exportar CSV</button>
                <label class="mant-print-selectall" title="Marca/desmarca todas las máquinas">
                    <input type="checkbox" id="print-select-all" checked>
                    <span>Seleccionar todas las máquinas (<span id="print-select-count">0/0</span>)</span>
                </label>
                <span class="mant-export-hint">Solo se imprimen las máquinas marcadas. Para PDF, usa "Imprimir" → "Guardar como PDF".</span>
            </div>

            <div id="print-header" class="mant-print-header" style="display:none">
                <h1>Planificador de tareas</h1>
                <div id="print-range">—</div>
            </div>

            <div id="groups-wrap"></div>

        </div>
        <div class="view-card-footer metric-legend metric-legend-compact mant-no-print">
            <div class="metric-legend-text">
                <p><strong>Planificador de tareas</strong> · datos del Excel de mantenimiento (hoja <em>PROXIMAS REV.</em>). Se muestran las tareas cuya próxima revisión cae en el rango seleccionado, agrupadas por máquina y ordenadas por fecha. Se excluyen las ya marcadas como hechas desde la web.</p>
                <p class="metric-legend-note" id="footer-actualizado">Fichero actualizado: —</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<div id="mark-modal" class="mant-modal" style="display:none" aria-hidden="true">
    <div class="mant-modal-backdrop" id="mark-modal-backdrop"></div>
    <div class="mant-modal-dialog" role="dialog" aria-modal="true">
        <div class="mant-modal-header">
            <span>Marcar revisión como hecha</span>
            <button type="button" class="mant-modal-close" id="mark-modal-close" aria-label="Cerrar">×</button>
        </div>
        <div class="mant-modal-body">
            <div class="mant-modal-summary" id="mark-modal-summary">—</div>
            <div class="mant-modal-field">
                <label for="mark-fecha">Fecha de la intervención</label>
                <input type="date" id="mark-fecha" class="machine-selector">
            </div>
            <div class="mant-modal-field">
                <label for="mark-operario">Operario</label>
                <select id="mark-operario" class="machine-selector">
                    <option value="">— Sin operario —</option>
                </select>
            </div>
            <div class="mant-modal-field" id="mark-operario-otro-wrap" style="display:none">
                <label for="mark-operario-otro">Nombre del operario</label>
                <input type="text" id="mark-operario-otro" class="machine-selector" placeholder="Escribe el nombre…">
            </div>
            <div class="mant-modal-field">
                <label for="mark-observaciones">Observaciones</label>
                <textarea id="mark-observaciones" class="machine-selector mant-textarea" rows="4" placeholder="Cualquier nota sobre la intervención…"></textarea>
            </div>
        </div>
        <div class="mant-modal-footer">
            <button type="button" class="machine-selector-clear mant-modal-btn-cancel" id="mark-modal-cancel" style="background:#a3b8d1">Cancelar</button>
            <button type="button" class="machine-selector-clear mant-modal-btn-ok" id="mark-modal-ok" style="background:#8c181a">✓ Marcar como hecha</button>
        </div>
    </div>
</div>

<div id="pend-modal" class="mant-modal" style="display:none" aria-hidden="true">
    <div class="mant-modal-backdrop" id="pend-modal-backdrop"></div>
    <div class="mant-modal-dialog" role="dialog" aria-modal="true">
        <div class="mant-modal-header">
            <span>Marcar como pendiente de revisar</span>
            <button type="button" class="mant-modal-close" id="pend-modal-close" aria-label="Cerrar">×</button>
        </div>
        <div class="mant-modal-body">
            <div class="mant-modal-summary" id="pend-modal-summary">—</div>
            <div class="mant-modal-field">
                <small style="color:var(--blue-mid);font-style:italic">
                    La tarea quedará marcada con un check rojo y aparecerá siempre en este formulario hasta que la revises (quites el check) o la marques como hecha.
                </small>
            </div>
            <div class="mant-modal-field">
                <label for="pend-nota">Nota (opcional)</label>
                <textarea id="pend-nota" class="machine-selector mant-textarea" rows="3" placeholder="Motivo, observación…"></textarea>
            </div>
        </div>
        <div class="mant-modal-footer">
            <button type="button" class="machine-selector-clear mant-modal-btn-cancel" id="pend-modal-cancel" style="background:#a3b8d1">Cancelar</button>
            <button type="button" class="machine-selector-clear mant-modal-btn-ok" id="pend-modal-ok" style="background:#c8102e">🚩 Marcar pendiente</button>
        </div>
    </div>
</div>

<script src="../assets/js/common.js"></script>
<script src="../assets/js/view_mant_semana.js"></script>
</body>
</html>
