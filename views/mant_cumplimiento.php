<?php
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();

$pageTitle    = 'Mantenimiento · Cumplimiento Preventivo';
$backLink     = 'mantenimiento.php';
$hideFiltros  = true;
$mantUserRole = Auth::role();
$mantUserName = Auth::user();
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Cumplimiento Preventivo <span id="header-scope" class="header-scope"></span></h2>
            <span class="view-card-info" id="info-line">—</span>
        </div>
        <div class="view-card-body">

            <div class="dual-selector-row">
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="f-desde" class="machine-selector-label">Desde:</label>
                    <input type="date" id="f-desde" class="machine-selector" style="min-width:150px">
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="f-hasta" class="machine-selector-label">Hasta:</label>
                    <input type="date" id="f-hasta" class="machine-selector" style="min-width:150px">
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto;gap:4px;flex-wrap:wrap">
                    <span class="machine-selector-label">Rápido:</span>
                    <button type="button" class="cumpl-quick" data-range="dia_ant" title="Día anterior"
                            style="padding:5px 12px;font-size:12px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer;font-weight:600;color:#2d4d7a">Día ant.</button>
                    <button type="button" class="cumpl-quick" data-range="sem_ant" title="Semana anterior (lunes → domingo)"
                            style="padding:5px 12px;font-size:12px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer;font-weight:600;color:#2d4d7a">Semana ant.</button>
                    <button type="button" class="cumpl-quick" data-range="1m" title="Día 1 del mes en curso → hoy"
                            style="padding:5px 12px;font-size:12px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer;font-weight:600;color:#2d4d7a">1 mes</button>
                    <button type="button" class="cumpl-quick" data-range="3m" title="Día 1 del mes hace 3 meses → hoy"
                            style="padding:5px 12px;font-size:12px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer;font-weight:600;color:#2d4d7a">3 meses</button>
                    <button type="button" class="cumpl-quick" data-range="6m" title="Día 1 del mes hace 6 meses → hoy"
                            style="padding:5px 12px;font-size:12px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer;font-weight:600;color:#2d4d7a">6 meses</button>
                </div>
                <div class="machine-selector-row" style="flex:1">
                    <label for="machine-selector" class="machine-selector-label">Máquina:</label>
                    <select id="machine-selector" class="machine-selector">
                        <option value="">— Todas —</option>
                    </select>
                </div>
                <button id="filter-clear" class="machine-selector-clear" type="button" title="Quita máquina y deja el rango en día anterior">× Quitar filtros</button>
                <button id="cumpl-export-xlsx" class="machine-selector-clear" type="button"
                        title="Descargar el informe de cumplimiento en XLSX con los filtros actuales"
                        style="background:#10b981;color:#fff">&#x2B07; Informe XLSX</button>
                <button id="cumpl-export-pdf" class="machine-selector-clear" type="button"
                        title="Descargar el informe de cumplimiento en PDF imprimible"
                        style="background:#c8102e;color:#fff">&#x2B07; Informe PDF</button>
            </div>

            <div class="cumpl-gauge-wrap">
                <div class="cumpl-gauge-card">
                    <div class="oee-detalle-subtitle" id="gauge-title">Cumplimiento mes</div>
                    <div id="gauge-cumpl"></div>
                    <div class="gauge-legend" id="gauge-legend">—</div>
                </div>
            </div>

            <div class="oee-detalle-subtitle" style="margin-top:18px">Cumplimiento por mes</div>
            <div id="chart-cumpl-meses"></div>
            <div class="seccion-hint">
                Calculado como (revisiones realizadas en el mes / revisiones programadas en el mes) × 100.
                Un valor &gt; 100 % indica recuperación de tareas no realizadas en meses anteriores.
                <strong>Haz clic sobre un mes</strong> para ver el detalle de tareas y observaciones de las no realizadas.
            </div>

            <div id="meses-drill-block" class="drill-down-block" style="display:none">
                <div class="drill-down-header">
                    <span class="drill-down-title">Cumplimiento por mes · periodicidad <span id="meses-drill-per">—</span></span>
                    <button id="meses-drill-close" class="drill-down-close" type="button">× Cerrar</button>
                </div>
                <div id="chart-cumpl-meses-per"></div>
                <div class="seccion-hint">Mismo cálculo, filtrado por la periodicidad seleccionada. Clic en un mes para ver sus tareas.</div>
            </div>

            <div id="mes-detalle-block" class="drill-down-block" style="display:none">
                <div class="drill-down-header">
                    <span class="drill-down-title">Tareas de <span id="mes-detalle-label">—</span> <span id="mes-detalle-scope" class="mant-cod"></span></span>
                    <button id="mes-detalle-close" class="drill-down-close" type="button">× Cerrar</button>
                </div>
                <div class="mant-mes-stats" id="mes-detalle-stats">—</div>
                <div class="mant-table-wrap">
                    <table class="mant-table mant-table-zebra">
                        <thead>
                            <tr>
                                <th style="width:120px">Tipo</th>
                                <th style="width:130px">Máquina</th>
                                <th style="width:110px">Periodicidad</th>
                                <th>Grupo / Tarea</th>
                                <th style="width:100px">Programada</th>
                                <th style="width:100px">Realizada</th>
                                <th style="width:90px">Operario</th>
                                <th>Observaciones / Motivo</th>
                            </tr>
                        </thead>
                        <tbody id="mes-detalle-tbody">
                            <tr><td colspan="8" class="mant-empty">—</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="tareas-block" class="drill-down-block" style="display:none">
                <div class="drill-down-header">
                    <span class="drill-down-title">Tareas con periodicidad · <span id="tareas-per-label">—</span></span>
                    <button id="tareas-close" class="drill-down-close" type="button">× Cerrar</button>
                </div>
                <div class="mant-table-wrap">
                    <table class="mant-table mant-table-zebra">
                        <thead>
                            <tr>
                                <th style="width:140px">Máquina</th>
                                <th>Grupo / Tarea</th>
                                <th>Descripción</th>
                                <th style="width:96px">Última</th>
                                <th style="width:96px">Próxima</th>
                                <th style="width:100px">Estado</th>
                                <th style="width:120px">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="tareas-tbody">
                            <tr><td colspan="7" class="mant-empty">—</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p><strong>Cumplimiento Preventivo</strong> = realizadas / (realizadas + previstas + atrasadas) × 100. <em>Realizadas</em> son las revisiones marcadas como hechas dentro del rango. <em>Previstas</em> son las tareas pendientes con fecha próxima dentro del rango. <em>Atrasadas</em> son las pendientes con fecha próxima anterior al rango. Conforme se completan revisiones del mes en curso, el valor sube acercándose al 100%.</p>
                <p class="metric-legend-note" id="footer-actualizado">Fichero actualizado: —</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<div id="per-modal" class="mant-modal" style="display:none" aria-hidden="true">
    <div class="mant-modal-backdrop" id="per-modal-backdrop"></div>
    <div class="mant-modal-dialog" role="dialog" aria-modal="true">
        <div class="mant-modal-header">
            <span>Cambiar periodicidad de la tarea</span>
            <button type="button" class="mant-modal-close" id="per-modal-close" aria-label="Cerrar">×</button>
        </div>
        <div class="mant-modal-body">
            <div class="mant-modal-summary" id="per-modal-summary">—</div>
            <div class="mant-modal-field">
                <label for="per-select">Nueva periodicidad</label>
                <select id="per-select" class="machine-selector"></select>
            </div>
            <div class="mant-modal-field" id="per-restore-wrap" style="display:none">
                <small style="color:var(--blue-mid);font-style:italic">
                    Esta tarea ya tiene un override. Puedes elegir <em>Volver a la del Excel</em> para quitarlo.
                </small>
            </div>
            <div class="mant-modal-field">
                <label for="per-nota">Nota (opcional)</label>
                <textarea id="per-nota" class="machine-selector mant-textarea" rows="3" placeholder="Motivo del cambio…"></textarea>
            </div>
            <div class="mant-modal-field" id="per-preview-wrap" style="display:none">
                <label>Vista previa</label>
                <div class="mant-preview" id="per-preview"></div>
            </div>
        </div>
        <div class="mant-modal-footer">
            <button type="button" class="machine-selector-clear mant-modal-btn-cancel" id="per-modal-cancel" style="background:#a3b8d1">Cancelar</button>
            <button type="button" class="machine-selector-clear mant-modal-btn-ok" id="per-modal-ok" style="background:#3a6aa3">Guardar</button>
        </div>
    </div>
</div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_mant_cumplimiento.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_mant_cumplimiento.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>
</body>
</html>
