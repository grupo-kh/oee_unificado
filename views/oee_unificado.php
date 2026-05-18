<?php
$pageTitle   = 'OEE Unificado';
$backLink    = '../index.php';
$hideFiltros = true;  // usamos filtros propios: rango + multi-turno
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main oee-uni-corp">
    <div class="view-card">
        <div class="view-card-header">
            <h2>OEE Unificado <span id="header-scope" class="header-scope"></span></h2>
            <div style="display:flex;align-items:center;gap:12px">
                <span class="view-card-info" id="info-line">—</span>
                <span class="view-card-kpi" id="kpi-num-ofs" title="OFs distintas con actividad productiva en el rango (respeta filtro de turno)">— OFs</span>
                <span class="view-card-kpi" id="kpi-num-refs" title="Referencias distintas con actividad productiva en el rango (respeta filtro de turno)">— Refs</span>
                <div class="oee-export-row">
                    <button type="button" id="btn-export-xlsx" class="oee-export-btn" title="Exportar datos filtrados a Excel">&#x2B07; Excel</button>
                    <button type="button" id="btn-export-pdf" class="oee-export-btn" title="Exportar vista filtrada a PDF">&#x2B07; PDF</button>

                    <div class="oee-export-compl-wrap">
                        <button type="button" id="btn-export-completo-xlsx" class="oee-export-btn oee-export-btn-alt" title="Informe completo: motivos × máquinas × franjas horarias por sección">&#x1F4CB; Completo XLSX &#x25BE;</button>
                        <div id="export-completo-menu-xlsx" class="oee-export-compl-menu" hidden>
                            <div class="oee-export-compl-menu-title">Elige sección</div>
                            <button type="button" data-sec="VARILLAS"    data-fmt="xlsx">VARILLAS</button>
                            <button type="button" data-sec="TROQUELADOS" data-fmt="xlsx">TROQUELADOS</button>
                        </div>
                    </div>

                    <div class="oee-export-compl-wrap">
                        <button type="button" id="btn-export-completo-pdf" class="oee-export-btn oee-export-btn-alt" title="Informe completo PDF: motivos × máquinas × franjas horarias por sección">&#x1F4CB; Completo PDF &#x25BE;</button>
                        <div id="export-completo-menu-pdf" class="oee-export-compl-menu" hidden>
                            <div class="oee-export-compl-menu-title">Elige sección</div>
                            <button type="button" data-sec="VARILLAS"    data-fmt="pdf">VARILLAS</button>
                            <button type="button" data-sec="TROQUELADOS" data-fmt="pdf">TROQUELADOS</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="view-card-body">

            <!-- ───── Filtros: rango de fechas + multi-turno ───── -->
            <div class="dual-selector-row oee-uni-filtros">
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="f-desde" class="machine-selector-label">Desde:</label>
                    <input type="date" id="f-desde" class="machine-selector" style="min-width:150px">
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="f-hasta" class="machine-selector-label">Hasta:</label>
                    <input type="date" id="f-hasta" class="machine-selector" style="min-width:150px">
                </div>

                <div class="machine-selector-row" style="flex:0 0 auto;gap:6px">
                    <span class="machine-selector-label">Rango:</span>
                    <button type="button" class="range-quick" data-range="today">Hoy</button>
                    <button type="button" class="range-quick" data-range="yesterday">Ayer</button>
                    <button type="button" class="range-quick" data-range="week">Semana</button>
                    <button type="button" class="range-quick" data-range="month">Mes</button>
                </div>

                <div class="machine-selector-row turno-multi" style="flex:1">
                    <span class="machine-selector-label">Turnos:</span>
                    <label class="turno-chip"><input type="checkbox" class="turno-cb" value="M"> Mañana</label>
                    <label class="turno-chip"><input type="checkbox" class="turno-cb" value="T"> Tarde</label>
                    <label class="turno-chip"><input type="checkbox" class="turno-cb" value="N"> Noche</label>
                    <button type="button" id="turnos-clear" class="machine-selector-clear" style="margin-left:auto">Todos</button>
                </div>

                <div class="machine-selector-row" style="flex:0 0 auto;position:relative">
                    <span class="machine-selector-label">Máquinas:</span>
                    <button type="button" id="maq-excl-toggle" class="maq-excl-toggle" title="Selecciona máquinas para excluirlas del análisis">
                        <span class="maq-excl-toggle-label">Excluir…</span>
                        <span id="maq-excl-toggle-count" class="maq-excl-toggle-count">0</span>
                        <span class="maq-excl-toggle-caret">▾</span>
                    </button>
                    <div id="maq-excl-panel" class="maq-excl-panel" hidden>
                        <div class="maq-excl-panel-head">
                            <input type="search" id="maq-excl-search" class="maq-excl-search" placeholder="Buscar máquina…" autocomplete="off">
                            <button type="button" id="maq-excl-close" class="maq-excl-panel-close" title="Cerrar">×</button>
                        </div>
                        <div id="maq-excl-list" class="maq-excl-list"><em class="maq-excl-empty">Cargando…</em></div>
                    </div>
                </div>
            </div>

            <!-- ───── Barra global de máquinas excluidas (filtro de análisis) ───── -->
            <div id="maq-excl-bar" class="maq-excl-bar" style="display:none">
                <span class="maq-excl-label">Excluidas del análisis:</span>
                <div id="maq-excl-chips" class="maq-excl-chips"></div>
                <button type="button" id="maq-excl-clear-all" class="maq-excl-clear-all" title="Quitar todas las exclusiones">× Quitar todas</button>
            </div>

            <!-- ───── Paso 1: barras horizontales OEE por sección ───── -->
            <section class="oee-module oee-module-secciones">
                <div class="oee-detalle-subtitle">OEE por Sección</div>
                <div id="chart-secciones"></div>
                <div class="seccion-hint">
                    <strong>Haz clic</strong> sobre <em>VARILLAS</em> o <em>TROQUELADOS</em> para ver el desglose de Disponibilidad, Rendimiento, Calidad y OEE de esa sección.
                </div>
            </section>

            <!-- ───── Evolución OEE (auto semana/mes según rango) ───── -->
            <section class="oee-module oee-module-evolucion">
            <div class="oee-detalle-subtitle evolucion-subtitle">
                <span>Evolución OEE <small id="evolucion-granularidad-label">—</small></span>
                <span class="evolucion-seccion-wrap">
                    Sección: <strong id="evolucion-seccion-label">Todas</strong>
                    <small class="evolucion-seccion-hint">(haz clic en una sección arriba para filtrar)</small>
                </span>
            </div>
            <div class="evolucion-toggles" id="evolucion-toggles">
                <label class="evolucion-toggle evo-toggle-oee" title="OEE = D × R × C">
                    <input type="checkbox" data-evo-serie="oee" checked>
                    <span class="evolucion-toggle-dot" style="background:#8c181a"></span>
                    <span>OEE</span>
                </label>
                <label class="evolucion-toggle evo-toggle-d" title="Disponibilidad">
                    <input type="checkbox" data-evo-serie="disponibilidad">
                    <span class="evolucion-toggle-dot" style="background:#2d4d7a"></span>
                    <span>Disponibilidad</span>
                </label>
                <label class="evolucion-toggle evo-toggle-r" title="Rendimiento">
                    <input type="checkbox" data-evo-serie="rendimiento">
                    <span class="evolucion-toggle-dot" style="background:#c45a2c"></span>
                    <span>Rendimiento</span>
                </label>
                <label class="evolucion-toggle evo-toggle-c" title="Calidad">
                    <input type="checkbox" data-evo-serie="calidad">
                    <span class="evolucion-toggle-dot" style="background:#2a7a4b"></span>
                    <span>Calidad</span>
                </label>
            </div>
            <div id="chart-evolucion"></div>
            </section>

            <!-- ───── Paso 2: drill-down D/R/C/OEE de la sección elegida ───── -->
            <div id="seccion-drill-block" class="drill-down-block oee-module oee-module-drill-seccion" style="display:none">
                <div class="drill-down-header">
                    <span class="drill-down-title">Desglose · <span id="seccion-drill-label">—</span></span>
                    <button id="seccion-drill-close" class="drill-down-close" type="button">× Cerrar</button>
                </div>
                <div id="chart-seccion-drc"></div>
                <div class="seccion-hint seccion-hint-metrica" id="metrica-hint">
                    <strong>Haz clic</strong> sobre una barra (Disponibilidad, Rendimiento, Calidad u OEE) para ver el desglose por máquina y motivos.
                </div>
            </div>

            <!-- ───── Paso 3: drill-down por métrica → máquinas + motivos ───── -->
            <div id="metrica-drill-block" class="drill-down-block oee-module oee-module-drill-metrica" style="display:none">
                <div class="drill-down-header">
                    <span class="drill-down-title"><span id="metrica-drill-label">—</span> · <span id="metrica-drill-seccion">—</span></span>
                    <div class="motivo-por-toggle" id="motivo-por-toggle" hidden>
                        <span class="motivo-por-toggle-label">Motivos de paro por:</span>
                        <label class="motivo-por-chip"><input type="radio" name="motivo-por" value="maquina" checked> Máquina</label>
                        <label class="motivo-por-chip"><input type="radio" name="motivo-por" value="referencia"> Referencia</label>
                    </div>
                    <button id="metrica-drill-close" class="drill-down-close" type="button">× Cerrar</button>
                </div>
                <div class="drill-down-col" style="margin-bottom:18px">
                    <div class="oee-detalle-subtitle">Por Máquina <small>(menor → mayor · clic para ver sus motivos)</small></div>
                    <div id="chart-metrica-maquinas"></div>
                </div>
                <div class="drill-down-col">
                    <div class="oee-detalle-subtitle" id="motivos-col-title">Motivos <small>— clic en una barra para ver desglose por máquina</small></div>
                    <div id="chart-metrica-motivos"></div>
                </div>

                <!-- ───── Paso intermedio: máquina/referencia → motivos → temporal por día → 24h → paros ───── -->
                <div id="maq-motivos-drill-block" class="drill-down-block oee-module oee-module-drill-motivo" style="display:none;margin-top:18px">
                    <div class="drill-down-header">
                        <span class="drill-down-title" id="maq-motivos-drill-title">—</span>
                        <button id="maq-motivos-drill-close" class="drill-down-close" type="button">× Cerrar</button>
                    </div>

                    <!-- Evolución D/R/C/OEE de la máquina/referencia clicada (solo visible
                         cuando hay una máquina seleccionada; en modo Referencia el endpoint
                         de evolución no soporta filtro por ref y este bloque queda oculto) -->
                    <div id="maq-evolucion-wrap" style="display:none;margin-bottom:18px">
                        <div class="oee-detalle-subtitle evolucion-subtitle">
                            <span>Evolución OEE de la máquina <small id="maq-evolucion-granularidad-label">—</small></span>
                        </div>
                        <div class="evolucion-toggles" id="maq-evolucion-toggles">
                            <label class="evolucion-toggle evo-toggle-oee">
                                <input type="checkbox" data-evo-maq-serie="oee" checked>
                                <span class="evolucion-toggle-dot" style="background:#8c181a"></span>
                                <span>OEE</span>
                            </label>
                            <label class="evolucion-toggle evo-toggle-d">
                                <input type="checkbox" data-evo-maq-serie="disponibilidad">
                                <span class="evolucion-toggle-dot" style="background:#2d4d7a"></span>
                                <span>Disponibilidad</span>
                            </label>
                            <label class="evolucion-toggle evo-toggle-r">
                                <input type="checkbox" data-evo-maq-serie="rendimiento">
                                <span class="evolucion-toggle-dot" style="background:#c45a2c"></span>
                                <span>Rendimiento</span>
                            </label>
                            <label class="evolucion-toggle evo-toggle-c">
                                <input type="checkbox" data-evo-maq-serie="calidad">
                                <span class="evolucion-toggle-dot" style="background:#2a7a4b"></span>
                                <span>Calidad</span>
                            </label>
                        </div>
                        <div id="chart-maq-evolucion"></div>
                    </div>

                    <div class="oee-detalle-subtitle">Motivos de paro <small>(pareto · clic en un motivo para ver su evolución temporal)</small></div>
                    <div id="chart-maq-motivos"></div>

                    <!-- Nivel 1: serie temporal por día -->
                    <div id="maq-motivo-por-dia-wrap" style="display:none;margin-top:14px">
                        <div class="oee-detalle-subtitle">
                            Total horas por día <small id="maq-motivo-por-dia-sub">(clic en un día para ver el detalle horario real)</small>
                        </div>
                        <div id="chart-maq-motivo-por-dia"></div>
                    </div>

                    <!-- Nivel 2: distribución 24h del día seleccionado -->
                    <div id="maq-motivo-por-hora-wrap" style="display:none;margin-top:14px">
                        <div class="oee-detalle-subtitle">
                            Distribución horaria · <span id="maq-motivo-por-hora-dia">—</span>
                            <small>(clic en una franja horaria para ver los paros individuales)</small>
                        </div>
                        <div id="chart-maq-motivo-por-hora"></div>
                    </div>

                    <!-- Nivel 3: paros individuales en la hora seleccionada -->
                    <div id="maq-motivo-paros-wrap" style="display:none;margin-top:14px">
                        <div class="oee-detalle-subtitle">
                            Paros individuales · <span id="maq-motivo-paros-label">—</span>
                        </div>
                        <div id="chart-maq-motivo-paros"></div>
                    </div>
                </div>

                <!-- ───── Paso 3b: clic en motivo → desglose por máquina|referencia + por hora ───── -->
                <div id="motivo-drill-block" class="drill-down-block oee-module oee-module-drill-motivo" style="display:none;margin-top:18px">
                    <div class="drill-down-header">
                        <span class="drill-down-title" id="motivo-drill-title">—</span>
                        <button id="motivo-drill-close" class="drill-down-close" type="button">× Cerrar</button>
                    </div>
                    <div id="chart-motivo-maquinas"></div>
                    <div class="oee-detalle-subtitle" style="margin-top:18px">
                        Distribución horaria <small id="motivo-hora-sub">(hora del día 00–23, agregada sobre el rango · clic en una máquina arriba para filtrar)</small>
                        <button type="button" id="motivo-hora-clear" class="motivo-hora-clear" style="display:none">× Quitar filtro</button>
                    </div>
                    <div id="chart-motivo-hora"></div>
                </div>
            </div>

        </div>

        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p>
                    <strong>D</strong> = M / (M + PNP) ·
                    <strong>R</strong> = (M_OKNOK_TEO + PCALIDAD) / (M + PPERF + PCALIDAD) ·
                    <strong>C</strong> = M_OK_TEO / (M_OKNOK_TEO + PCALIDAD) ·
                    <strong>OEE</strong> = D × R × C.
                </p>
                <p class="metric-legend-note">
                    Datos agregados sobre el intervalo <em>Desde – Hasta</em>. Si no marcas ningún turno se incluyen los tres (Mañana, Tarde, Noche). Excluye <code>Improductivos, AUX000, AUXI1, SOLD4, SOLD5</code>.
                </p>
            </div>
        </div>

        <!-- ───── Bloque inline: Top máquinas ───── -->
        <div id="top-maquinas-block" class="top-inline-block oee-module oee-module-top-maq" hidden>
            <div class="top-inline-head">
                <h2>📊 Top máquinas — Disponibilidad</h2>
                <div class="top-inline-actions">
                    <button type="button" data-export="xlsx" data-mode="maquinas" class="oee-export-btn" title="Exportar el top a Excel">&#x2B07; Excel</button>
                    <button type="button" data-export="pdf"  data-mode="maquinas" class="oee-export-btn" title="Exportar el top a PDF">&#x2B07; PDF</button>
                    <button type="button" data-close-top="maquinas" class="top-close-btn" title="Cerrar este bloque">× Cerrar</button>
                </div>
            </div>
            <div class="top-inline-filtros">
                <label for="top-maquinas-seccion">Sección</label>
                <select id="top-maquinas-seccion" class="machine-selector">
                    <option value="VARILLAS">VARILLAS</option>
                    <option value="TROQUELADOS">TROQUELADOS</option>
                </select>
                <label for="top-maquinas-desde">Desde</label>
                <input type="date" id="top-maquinas-desde" class="machine-selector">
                <label for="top-maquinas-hasta">Hasta</label>
                <input type="date" id="top-maquinas-hasta" class="machine-selector">
                <label for="top-maquinas-n">Top N</label>
                <input type="number" id="top-maquinas-n" class="machine-selector top-n-input" min="1" max="20" value="5">

                <div class="top-excl-wrap">
                    <button type="button" id="top-maq-excl-toggle" class="top-excl-toggle" title="Excluir máquinas del análisis Top">
                        <span>Excluir máquinas…</span>
                        <span id="top-maq-excl-count" class="top-excl-count">0</span>
                        <span class="top-excl-caret">▾</span>
                    </button>
                    <div id="top-maq-excl-panel" class="top-excl-panel" hidden>
                        <div class="top-excl-panel-head">
                            <input type="search" id="top-maq-excl-search" class="top-excl-search" placeholder="Buscar máquina…" autocomplete="off">
                            <button type="button" id="top-maq-excl-clear" class="top-excl-clear" title="Quitar todas">× Quitar todas</button>
                        </div>
                        <div id="top-maq-excl-list" class="top-excl-list"><em class="top-excl-empty">Pulsa “Aplicar” o abre el bloque para cargar la lista.</em></div>
                    </div>
                </div>

                <button type="button" data-aplicar="maquinas" class="top-aplicar-btn">Aplicar</button>
            </div>
            <div id="top-maq-excl-chips" class="top-excl-chips"></div>
            <div class="top-panel-chart">
                <div id="top-maquinas-empty" class="top-empty">Configura los filtros y pulsa “Aplicar”.</div>
                <div id="top-maquinas-chart"></div>
            </div>
            <div class="top-panel-detalle">
                <div id="top-maquinas-detalle-empty" class="top-empty">Haz clic en una máquina para ver su histograma por fecha.</div>
                <div id="top-maquinas-detalle-title" class="top-detalle-title" style="display:none">—</div>
                <div id="top-maquinas-detalle-chart"></div>
            </div>
        </div>

        <!-- ───── Bloque inline: Top motivos ───── -->
        <div id="top-motivos-block" class="top-inline-block oee-module oee-module-top-mot" hidden>
            <div class="top-inline-head">
                <h2>📊 Top motivos — Disponibilidad</h2>
                <div class="top-inline-actions">
                    <button type="button" data-export="xlsx" data-mode="motivos" class="oee-export-btn" title="Exportar el top a Excel">&#x2B07; Excel</button>
                    <button type="button" data-export="pdf"  data-mode="motivos" class="oee-export-btn" title="Exportar el top a PDF">&#x2B07; PDF</button>
                    <button type="button" data-close-top="motivos" class="top-close-btn" title="Cerrar este bloque">× Cerrar</button>
                </div>
            </div>
            <div class="top-inline-filtros">
                <label for="top-motivos-seccion">Sección</label>
                <select id="top-motivos-seccion" class="machine-selector">
                    <option value="VARILLAS">VARILLAS</option>
                    <option value="TROQUELADOS">TROQUELADOS</option>
                </select>
                <label for="top-motivos-desde">Desde</label>
                <input type="date" id="top-motivos-desde" class="machine-selector">
                <label for="top-motivos-hasta">Hasta</label>
                <input type="date" id="top-motivos-hasta" class="machine-selector">
                <label for="top-motivos-n">Top N</label>
                <input type="number" id="top-motivos-n" class="machine-selector top-n-input" min="1" max="20" value="5">
                <button type="button" data-aplicar="motivos" class="top-aplicar-btn">Aplicar</button>
            </div>
            <div class="top-panel-chart">
                <div id="top-motivos-empty" class="top-empty">Configura los filtros y pulsa “Aplicar”.</div>
                <div id="top-motivos-chart"></div>
            </div>
            <div class="top-panel-detalle">
                <div id="top-motivos-detalle-empty" class="top-empty">Haz clic en un motivo para ver su histograma por fecha.</div>
                <div id="top-motivos-detalle-title" class="top-detalle-title" style="display:none">—</div>
                <div id="top-motivos-detalle-chart"></div>
            </div>
        </div>

        <!-- Botón progresivo: revela el siguiente bloque oculto -->
        <div class="top-show-next-row">
            <button type="button" id="top-show-next" class="top-show-next-btn">▼ Mostrar Top máquinas</button>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_oee_unificado.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_oee_unificado.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>
</body>
</html>
