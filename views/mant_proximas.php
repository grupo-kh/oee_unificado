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
                    <label for="fecha-desde" class="machine-selector-label">Desde:</label>
                    <input type="date" id="fecha-desde" class="machine-selector" style="min-width:140px">
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="fecha-hasta" class="machine-selector-label">Hasta:</label>
                    <input type="date" id="fecha-hasta" class="machine-selector" style="min-width:140px">
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
                <button id="prox-export-xlsx" class="machine-selector-clear" type="button"
                        title="Descargar el calendario filtrado en XLSX para entregar a los operarios"
                        style="background:#10b981;color:#fff">&#x2B07; Calendario XLSX</button>
                <button id="prox-export-pdf" class="machine-selector-clear" type="button"
                        title="Descargar el calendario filtrado en PDF imprimible para los operarios"
                        style="background:#c8102e;color:#fff">&#x2B07; Calendario PDF</button>
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
                        <div class="mant-stat mant-stat-urgentes"><span class="mant-stat-value" id="stat-urgentes">—</span><span class="mant-stat-label">Próximas (≤10 días)</span></div>
                        <div class="mant-stat mant-stat-en-plazo"><span class="mant-stat-value" id="stat-en-plazo">—</span><span class="mant-stat-label">En plazo</span></div>
                        <div class="mant-stat mant-stat-total"><span class="mant-stat-value" id="stat-total">—</span><span class="mant-stat-label">Total</span></div>
                    </div>
                </div>
            </div>

            <div class="mant-chart-box mant-chart-fullwidth">
                <div class="oee-detalle-subtitle">Top Máquinas con tareas vencidas/próximas <small class="mant-hint">(clic para filtrar)</small></div>
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
                <p><strong>Próximas Revisiones</strong> · una tarea se considera <strong>vencida</strong> si su fecha de "Próxima revisión" superó el margen de tolerancia (gap por periodicidad), <strong>próxima</strong> si vence en los siguientes 10 días, y <strong>en plazo</strong> en caso contrario. El gauge muestra el % no vencido sobre el total del filtro aplicado.</p>
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

            <!-- Lista de subtareas (solo visible si la fila es consolidada).
                 El operario puede desmarcar las que NO ha hecho — esas quedan
                 pendientes en el plan y reaparecerán en la próxima visita. -->
            <div class="mant-modal-field" id="mark-subtareas-wrap" style="display:none">
                <label>Sub-tareas incluidas (desmarca las que no hayas hecho)</label>
                <div id="mark-subtareas-list" class="mant-subtareas-checklist"></div>
                <small style="color:var(--blue-mid);font-style:italic">
                    Por defecto todas están marcadas (la visita las hace todas). Desmarca las que dejes pendientes para la próxima vez.
                </small>
                <div class="mant-subtareas-toolbar">
                    <button type="button" class="machine-selector-clear" id="mark-subtareas-all" style="background:#3a6aa3;color:#fff">Marcar todas</button>
                    <button type="button" class="machine-selector-clear" id="mark-subtareas-none" style="background:#a3b8d1">Desmarcar todas</button>
                </div>
            </div>

            <!-- Selector de tipo: realizada / no realizada -->
            <div class="mant-modal-field mant-modal-tipo">
                <label>Estado de la intervención</label>
                <div class="mant-tipo-row">
                    <label class="mant-tipo-opt">
                        <input type="radio" name="mark-tipo" value="completada" checked>
                        <span>✓ Realizada</span>
                    </label>
                    <label class="mant-tipo-opt">
                        <input type="radio" name="mark-tipo" value="no_realizada">
                        <span>✕ No realizada</span>
                    </label>
                </div>
            </div>

            <!-- Motivo (solo si no_realizada): 3 valores fijos -->
            <div class="mant-modal-field" id="mark-motivo-wrap" style="display:none">
                <label for="mark-motivo">Motivo de no realización</label>
                <select id="mark-motivo" class="machine-selector">
                    <option value="">— Selecciona el motivo —</option>
                    <option value="disponibilidad_maquina">Falta de disponibilidad de máquina</option>
                    <option value="disponibilidad_operario">Falta de disponibilidad de operario</option>
                    <option value="falta_material">Falta de material</option>
                </select>
            </div>

            <div class="mant-modal-field">
                <label for="mark-fecha">Fecha de la intervención</label>
                <input type="date" id="mark-fecha" class="machine-selector">
            </div>
            <!-- Hora a la que el operario inicia la intervención -->
            <div class="mant-modal-field" id="mark-hora-wrap">
                <label for="mark-hora-inicio">Hora de inicio</label>
                <input type="time" id="mark-hora-inicio" class="machine-selector" step="60">
            </div>
            <div class="mant-modal-field">
                <label for="mark-operario">Operario</label>
                <select id="mark-operario" class="machine-selector">
                    <option value="">— Sin operario —</option>
                </select>
            </div>
            <div class="mant-modal-field" id="mark-operario-otro-wrap" style="display:none">
                <label for="mark-operario-otro">Número de operario</label>
                <input type="text" inputmode="numeric" pattern="[0-9]+" id="mark-operario-otro" class="machine-selector" placeholder="Ej. 1004">
                <small style="color:var(--blue-mid);font-style:italic">
                    Solo se permite usarlo si la API no devolvió el catálogo activo. Introduce solo el número de operario.
                </small>
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

<?php
$_jsCommon    = __DIR__ . '/../assets/js/common.js';
$_jsView      = __DIR__ . '/../assets/js/view_mant_proximas.js';
$_jsCommonVer = file_exists($_jsCommon) ? filemtime($_jsCommon) : time();
$_jsViewVer   = file_exists($_jsView)   ? filemtime($_jsView)   : time();
?>
<script src="../assets/js/common.js?v=<?= $_jsCommonVer ?>"></script>
<script src="../assets/js/view_mant_proximas.js?v=<?= $_jsViewVer ?>"></script>
</body>
</html>
