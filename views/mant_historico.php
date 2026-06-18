<?php
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();

$pageTitle    = 'Mantenimiento · Histórico por Máquina';
$backLink     = 'mantenimiento.php';
$hideFiltros  = true;
$mantUserRole = Auth::role();
$mantUserName = Auth::user();
include __DIR__ . '/../includes/header.php';
?>
<style>
    .mant-quick-filters { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .mant-quick-label   { font-size:12px; color:#5b6f86; font-weight:600; }
    .mant-quick-btn {
        background:#eef2f6; border:1px solid #c5d2e0; color:#2d4d7a;
        padding:6px 12px; font-size:12px; font-weight:600;
        border-radius:4px; cursor:pointer; transition:all .15s;
    }
    .mant-quick-btn:hover { background:#dbe7f3; border-color:#a3b8d1; }
    .mant-quick-btn.active { background:#2d4d7a; color:#fff; border-color:#2d4d7a; }
    .mant-quick-btn-pausadas { background:#fff8e6; border-color:#f0c674; color:#7a5b1b; }
    .mant-quick-btn-pausadas:hover { background:#fde9c5; border-color:#d6a542; }
    .mant-quick-btn-pausadas.active { background:#c8102e; color:#fff; border-color:#c8102e; }
    .mant-task-pausada { opacity:.85; background:#fff7ed; border-left:3px solid #f0c674; }
    .mant-pausada-badge {
        display:inline-block; background:#c8102e; color:#fff;
        padding:1px 6px; border-radius:3px; font-size:10px; font-weight:700;
        margin-left:6px; letter-spacing:.3px;
    }
</style>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Histórico de Intervenciones <span id="header-scope" class="header-scope"></span></h2>
            <span class="view-card-info" id="info-line">—</span>
        </div>
        <div class="view-card-body">

            <div class="mant-filters">
                <div class="mant-filters-group mant-filters-dates">
                    <div class="mant-filter">
                        <label>Desde</label>
                        <input type="date" id="f-desde" class="machine-selector">
                    </div>
                    <div class="mant-filter">
                        <label>Hasta</label>
                        <input type="date" id="f-hasta" class="machine-selector">
                    </div>
                </div>
                <div class="mant-filters-group mant-quick-filters">
                    <span class="mant-quick-label">Filtro rápido:</span>
                    <button type="button" class="mant-quick-btn" data-quick="semana">Semana actual</button>
                    <button type="button" class="mant-quick-btn" data-quick="mes">Mes actual</button>
                    <button type="button" class="mant-quick-btn" data-quick="mes_ant">Mes anterior</button>
                </div>
                <div class="mant-filters-group mant-filters-selects">
                    <div class="mant-filter">
                        <label>Máquina</label>
                        <select id="machine-selector" class="machine-selector">
                            <option value="">— Todas —</option>
                        </select>
                    </div>
                    <div class="mant-filter">
                        <label>Operario</label>
                        <select id="operario-selector" class="machine-selector">
                            <option value="">— Todos —</option>
                        </select>
                    </div>
                    <div class="mant-filter">
                        <label>Periodicidad</label>
                        <select id="periodicidad-selector" class="machine-selector">
                            <option value="">— Todas —</option>
                        </select>
                    </div>
                </div>
                <div class="mant-filters-group mant-filters-actions">
                    <button id="btn-export-csv"  type="button" class="mant-export-btn mant-export-csv"   title="Exportar el filtro actual a CSV">⬇ CSV</button>
                    <button id="btn-export-xlsx" type="button" class="mant-export-btn mant-export-excel" title="Exportar el filtro actual a Excel (.xlsx)">⬇ Excel</button>
                    <button id="filter-clear" class="machine-selector-clear" type="button" style="display:none">× Quitar filtros</button>
                </div>
            </div>

            <div class="mant-stats">
                <div class="mant-stat mant-stat-total">
                    <span class="mant-stat-value" id="stat-total">—</span>
                    <span class="mant-stat-label">Intervenciones</span>
                </div>
                <div class="mant-stat mant-stat-en-plazo">
                    <span class="mant-stat-value" id="stat-maquinas">—</span>
                    <span class="mant-stat-label">Máquinas distintas</span>
                </div>
            </div>

            <div class="mant-table-section">
                <div class="mant-table-title">Intervenciones <small id="mant-table-count">—</small></div>
                <div id="mant-machines-wrap" class="mant-machines-wrap">
                    <div class="mant-empty">Cargando…</div>
                </div>
                <div id="mant-truncado" class="mant-truncado" style="display:none"></div>
            </div>

        </div>
        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p><strong>Histórico</strong> · datos de la hoja <em>Hoja3</em> del Excel de mantenimiento. Una fila por intervención realizada (fecha, tarea, operario). Pulsa sobre una barra de los gráficos para filtrar la tabla por esa máquina u operario.</p>
                <p class="metric-legend-note" id="footer-actualizado">Fichero actualizado: —</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<!-- Modal · edición de intervención (solo técnico) -->
<div id="hist-edit-modal" class="mant-modal role-tecnico-only" style="display:none" aria-hidden="true">
    <div class="mant-modal-backdrop" id="hist-edit-backdrop"></div>
    <div class="mant-modal-dialog" role="dialog" aria-modal="true">
        <div class="mant-modal-header">
            <span>Editar intervención</span>
            <button type="button" class="mant-modal-close" id="hist-edit-close" aria-label="Cerrar">&times;</button>
        </div>
        <div class="mant-modal-body">
            <div class="mant-modal-summary" id="hist-edit-summary">&mdash;</div>

            <div class="mant-modal-field" id="hist-edit-fecha-wrap">
                <label for="hist-edit-fecha">Fecha de la intervención</label>
                <input type="date" id="hist-edit-fecha" class="machine-selector">
            </div>

            <div class="mant-modal-field" id="hist-edit-hora-wrap">
                <label for="hist-edit-hora">Hora de inicio</label>
                <input type="time" id="hist-edit-hora" class="machine-selector" step="60">
            </div>

            <div class="mant-modal-field">
                <label for="hist-edit-tiempo-min">Tiempo de ejecución</label>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="number" id="hist-edit-tiempo-min" class="machine-selector" min="0" max="600" step="1" style="width:90px" placeholder="min">
                    <span>min</span>
                </div>
                <small style="color:var(--blue-mid);font-style:italic">
                    Valor real en minutos. Internamente se guardan segundos con variación ±5..10 sobre tiempo_estimado.
                </small>
                <!-- Input de segundos eliminado del UI; el seg se preserva al guardar
                     (si el usuario cambia el min, se redondea con jitter aleatorio ±5s). -->
                <input type="hidden" id="hist-edit-tiempo-seg" value="">
            </div>

            <div class="mant-modal-field" id="hist-edit-motivo-wrap" style="display:none">
                <label for="hist-edit-motivo">Motivo de no realización</label>
                <select id="hist-edit-motivo" class="machine-selector">
                    <option value="">— Selecciona —</option>
                    <option value="disponibilidad_maquina">Falta de disponibilidad de máquina</option>
                    <option value="disponibilidad_operario">Falta de disponibilidad de operario</option>
                    <option value="falta_material">Falta de material</option>
                </select>
            </div>

            <div class="mant-modal-field">
                <label for="hist-edit-operario">Número de operario</label>
                <input type="text" inputmode="numeric" pattern="[0-9]+" id="hist-edit-operario" class="machine-selector" placeholder="Ej. 1004">
            </div>

            <div class="mant-modal-field">
                <label for="hist-edit-obs">Comentario / observaciones</label>
                <textarea id="hist-edit-obs" class="machine-selector mant-textarea" rows="4" placeholder="Notas sobre la intervención…"></textarea>
            </div>

            <div class="mant-modal-field">
                <label class="mant-tipo-opt" style="display:inline-flex;align-items:center;gap:8px">
                    <input type="checkbox" id="hist-edit-incompleta">
                    <span>Visita incompleta (se quedaron sub-tareas pendientes)</span>
                </label>
            </div>
        </div>
        <div class="mant-modal-footer">
            <button type="button" class="machine-selector-clear" id="hist-edit-cancel" style="background:#a3b8d1">Cancelar</button>
            <button type="button" class="machine-selector-clear" id="hist-edit-save" style="background:#8c181a;color:#fff">Guardar</button>
        </div>
    </div>
</div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_mant_historico.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_mant_historico.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>
</body>
</html>
