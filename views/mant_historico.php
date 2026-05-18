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

            <div class="mant-stats mant-stats-3">
                <div class="mant-stat mant-stat-total">
                    <span class="mant-stat-value" id="stat-total">—</span>
                    <span class="mant-stat-label">Intervenciones</span>
                </div>
                <div class="mant-stat mant-stat-en-plazo">
                    <span class="mant-stat-value" id="stat-maquinas">—</span>
                    <span class="mant-stat-label">Máquinas distintas</span>
                </div>
                <div class="mant-stat mant-stat-urgentes">
                    <span class="mant-stat-value" id="stat-operarios">—</span>
                    <span class="mant-stat-label">Operarios distintos</span>
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

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_mant_historico.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_mant_historico.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>
</body>
</html>
