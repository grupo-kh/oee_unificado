<?php
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();

$pageTitle    = 'Mantenimiento · Próximas Revisiones';
$backLink     = 'mantenimiento.php';
$hideFiltros  = true;
$mantUserRole = Auth::role();
$mantUserName = Auth::user();
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Próximas Revisiones <span id="header-scope" class="header-scope"></span></h2>
            <span class="view-card-info" id="info-line">—</span>
        </div>
        <div class="view-card-body">

            <div class="dual-selector-row">
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="dias-selector" class="machine-selector-label">Ventana:</label>
                    <select id="dias-selector" class="machine-selector" style="min-width:140px">
                        <option value="7">Próximos 7 días</option>
                        <option value="15">Próximos 15 días</option>
                        <option value="30" selected>Próximos 30 días</option>
                        <option value="60">Próximos 60 días</option>
                    </select>
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
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label class="machine-selector-label">
                        <input type="checkbox" id="solo-vencidas"> Solo vencidas
                    </label>
                </div>
                <button id="filter-clear" class="machine-selector-clear" type="button" style="display:none">× Quitar filtros</button>
            </div>

            <div class="oee-fab-global-grid">
                <div class="oee-fab-gauge">
                    <div class="oee-detalle-subtitle">% en plazo</div>
                    <div id="gauge-mant"></div>
                </div>
                <div class="oee-fab-secciones">
                    <div class="oee-detalle-subtitle">Resumen</div>
                    <div class="mant-stats" id="mant-stats">
                        <div class="mant-stat mant-stat-vencidas"><span class="mant-stat-value" id="stat-vencidas">—</span><span class="mant-stat-label">Vencidas</span></div>
                        <div class="mant-stat mant-stat-urgentes"><span class="mant-stat-value" id="stat-urgentes">—</span><span class="mant-stat-label">Urgentes (≤7 días)</span></div>
                        <div class="mant-stat mant-stat-en-plazo"><span class="mant-stat-value" id="stat-en-plazo">—</span><span class="mant-stat-label">En plazo</span></div>
                        <div class="mant-stat mant-stat-total"><span class="mant-stat-value" id="stat-total">—</span><span class="mant-stat-label">Total</span></div>
                    </div>
                </div>
            </div>

            <div class="mant-chart-box mant-chart-fullwidth">
                <div class="oee-detalle-subtitle">Top Máquinas con tareas vencidas/urgentes <small class="mant-hint">(clic para filtrar)</small></div>
                <div id="chart-top-maquinas-prox"></div>
                <div id="chart-top-maquinas-prox-empty" class="drill-down-empty" style="display:none">Sin máquinas con tareas en este rango</div>
            </div>

            <div class="mant-table-wrap">
                <table class="mant-table mant-table-zebra">
                    <thead>
                        <tr>
                            <th style="width:96px">Próxima</th>
                            <th style="width:120px">Días</th>
                            <th>Máquina</th>
                            <th style="width:110px">Periodicidad</th>
                            <th>Tarea</th>
                            <th>Descripción</th>
                            <th style="width:96px">Última</th>
                            <th style="width:140px">Acción</th>
                        </tr>
                    </thead>
                    <tbody id="mant-tbody">
                        <tr><td colspan="8" class="mant-empty">Cargando…</td></tr>
                    </tbody>
                </table>
            </div>

        </div>
        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p><strong>Próximas Revisiones</strong> · datos del fichero <code>Z:\Mantenimiento\…</code> (hoja <em>PROXIMAS REV.</em>). Una tarea se considera <strong>vencida</strong> si su fecha de "Próxima revisión" es anterior a hoy, <strong>urgente</strong> si vence en los próximos 7 días, y <strong>en plazo</strong> en caso contrario. El gauge muestra el % no vencido sobre el total filtrado.</p>
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
            <button type="button" class="machine-selector-clear mant-modal-btn-ok" id="mark-modal-ok" style="background:#10b981">✓ Marcar como hecha</button>
        </div>
    </div>
</div>

<script src="../assets/js/common.js"></script>
<script src="../assets/js/view_mant_proximas.js"></script>
</body>
</html>
