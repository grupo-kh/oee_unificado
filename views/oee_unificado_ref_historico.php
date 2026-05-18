<?php
$pageTitle   = 'Histórico de Rendimiento';
$backLink    = 'oee_unificado.php';
$hideFiltros = true;
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main ref-hist-view">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Histórico de Rendimiento <small class="ref-hist-h-sub">— periodo y referencia independientes (máx. 1 año)</small></h2>
            <div class="ref-hist-export-row">
                <button type="button" id="btn-ref-hist-xlsx" class="oee-export-btn" title="Exportar histórico de la referencia a Excel" disabled>&#x2B07; Histórico XLSX</button>
                <button type="button" id="btn-ref-hist-pdf"  class="oee-export-btn" title="Exportar histórico de la referencia a PDF"   disabled>&#x2B07; Histórico PDF</button>
            </div>
        </div>

        <div class="view-card-body">

            <!-- ───── Bloque 1: histórico agrupado por OF (totales sin fecha) ───── -->
            <div class="ref-hist-block">
                <div class="ref-hist-filtros">
                    <div class="ref-hist-row">
                        <label for="ref-hist-select" class="machine-selector-label">Referencia:</label>
                        <select id="ref-hist-select" class="machine-selector ref-hist-select">
                            <option value="">— Cargando referencias… —</option>
                        </select>
                        <input type="search" id="ref-hist-search" class="ref-hist-search" placeholder="Filtrar lista…" autocomplete="off" title="Filtra el desplegable por código o descripción">
                        <label class="ref-hist-multi-toggle" title="Mostrar solo referencias que se han fabricado en más de una máquina">
                            <input type="checkbox" id="ref-hist-multi-cb">
                            <span>Solo multi-máquina <span id="ref-hist-multi-count" class="ref-hist-multi-count">0</span></span>
                        </label>
                        <span id="ref-hist-scope" class="ref-hist-scope" title="Solo se listan referencias con producción en este periodo">Solo referencias con producción en el último año</span>
                    </div>

                    <div class="ref-hist-row">
                        <label for="ref-hist-desde" class="machine-selector-label">Desde:</label>
                        <input type="date" id="ref-hist-desde" class="machine-selector" style="min-width:150px">
                        <label for="ref-hist-hasta" class="machine-selector-label">Hasta:</label>
                        <input type="date" id="ref-hist-hasta" class="machine-selector" style="min-width:150px">

                        <span class="machine-selector-label">Rango:</span>
                        <button type="button" class="range-quick ref-hist-quick" data-range="month">Mes</button>
                        <button type="button" class="range-quick ref-hist-quick" data-range="3months">3 meses</button>
                        <button type="button" class="range-quick ref-hist-quick" data-range="6months">6 meses</button>
                        <button type="button" class="range-quick ref-hist-quick" data-range="year">1 año</button>
                    </div>
                </div>

                <div id="ref-hist-resumen" class="ref-hist-resumen" style="display:none">
                    <span class="ref-hist-resumen-item"><strong>OFs:</strong> <span id="ref-hist-tot-ofs">0</span></span>
                    <span class="ref-hist-resumen-item"><strong>Máquinas:</strong> <span id="ref-hist-tot-maqs">0</span></span>
                    <span class="ref-hist-resumen-item"><strong>Días con producción:</strong> <span id="ref-hist-tot-dias">0</span></span>
                    <span class="ref-hist-resumen-item ref-hist-resumen-ok"><strong>Total OK:</strong> <span id="ref-hist-tot-ok">0</span></span>
                    <span class="ref-hist-resumen-item ref-hist-resumen-nok"><strong>Total NOK:</strong> <span id="ref-hist-tot-nok">0</span></span>
                </div>

                <div id="ref-hist-tabla-wrap" class="ref-hist-tabla-wrap" style="display:none">
                    <table class="ref-hist-tabla">
                        <thead>
                            <tr>
                                <th>OF</th>
                                <th>Máquina(s)</th>
                                <th class="num">Días</th>
                                <th class="num">Unidades OK</th>
                                <th class="num">Unidades NOK</th>
                            </tr>
                        </thead>
                        <tbody id="ref-hist-tbody"></tbody>
                    </table>
                </div>

                <div id="ref-hist-empty" class="ref-hist-empty" style="display:none">
                    Sin fabricaciones para esta referencia en el rango seleccionado.
                </div>
            </div>

            <!-- ───── Bloque 2: comparativa de OFs entre máquinas ───── -->
            <div class="ref-comp-block" style="margin-top:24px">
                <div class="ref-comp-head">
                    <h3>Comparativa de rendimiento por OF</h3>
                    <small>Para esta referencia · OFs y máquinas que la han fabricado · gráficos en espejo</small>
                </div>

                <div id="ref-comp-overall" class="ref-comp-overall" style="display:none">
                    <div class="ref-comp-overall-icon">🏅</div>
                    <div class="ref-comp-overall-body">
                        <div class="ref-comp-overall-title">Mejor máquina del rango <small>(sumando todas las OFs)</small></div>
                        <div class="ref-comp-overall-detail" id="ref-comp-overall-detail">—</div>
                        <div id="ref-comp-overall-ranking" class="ref-comp-overall-ranking"></div>
                    </div>
                </div>

                <!-- Por cada máquina: chart de uds/h arriba + chart OK/NOK invertido abajo + stats a la derecha -->
                <div id="ref-comp-machines" class="ref-comp-machines"></div>

                <div id="ref-comp-empty" class="ref-hist-empty" style="display:none;margin-top:10px">
                    No hay OFs comparables para esta referencia en el rango.
                </div>
            </div>

        </div>
    </div>
</main>

<!-- Popup: distribución horaria de una OF -->
<div id="of-popup" class="of-popup" hidden>
    <div class="of-popup-overlay"></div>
    <div class="of-popup-dialog" role="dialog" aria-modal="true" aria-labelledby="of-popup-title">
        <div class="of-popup-head">
            <h3 id="of-popup-title">—</h3>
            <button type="button" id="of-popup-close" class="of-popup-close" aria-label="Cerrar">×</button>
        </div>
        <div id="of-popup-sub" class="of-popup-sub">—</div>
        <div id="of-popup-chart" class="of-popup-chart"></div>
        <div id="of-popup-empty" class="of-popup-empty" style="display:none">Sin producción horaria para esta OF.</div>
    </div>
</div>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_oee_unificado_ref_historico.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_oee_unificado_ref_historico.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>
</body>
</html>
