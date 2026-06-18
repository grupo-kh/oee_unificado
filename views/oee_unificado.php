<?php
$pageTitle   = 'OEE Unificado';
$backLink    = '../index.php';
$hideFiltros = true;  // usamos filtros propios: rango + multi-turno
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
include __DIR__ . '/../includes/header.php';

// Lista de máquinas (Desc_maquina como value y label) para el selector
// del nuevo módulo de histograma. Viene del mapping conocido.
$_maquinasHist = array_keys(PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT);
sort($_maquinasHist);
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
                    <button type="button" id="btn-hist-maq" class="oee-export-btn" title="Histograma de motivos de disponibilidad por máquina (popup a pantalla completa)"
                            style="background:#2d4d7a;color:#fff">&#x1F4CA; Histograma</button>
                    <button type="button" id="btn-cambios-of" class="oee-export-btn" title="Abrir el módulo Cambios en OF en un popup a pantalla completa"
                            style="background:#1a4a7a;color:#fff">&#x1F501; Cambios en OF</button>
                    <button type="button" id="btn-export-xlsx" class="oee-export-btn" title="Exportar a Excel TODO lo que tienes en pantalla (filtros, drills y selecciones activas)">&#x2B07; Excel</button>
                    <button type="button" id="btn-export-pdf" class="oee-export-btn" title="Exportar a PDF TODO lo que tienes en pantalla (filtros, drills y selecciones activas)">&#x2B07; PDF</button>
                    <button type="button" id="btn-export-matriz" class="oee-export-btn" title="Exportar la matriz motivos × máquina/referencia (horas de paro) a Excel, con los filtros activos">&#x2B07; Matriz</button>
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

            <!-- ───── Filtro de período (activado al clicar un día/semana/mes en la evolución) ───── -->
            <div id="periodo-filtro-bar" class="periodo-filtro-bar" hidden>
                <span class="periodo-filtro-icon">&#x1F50E;</span>
                <span class="periodo-filtro-label">
                    Filtrando por
                    <strong id="periodo-filtro-text">—</strong>
                </span>
                <span class="periodo-filtro-rango-orig">
                    (rango principal: <span id="periodo-filtro-rango-orig-text">—</span>)
                </span>
                <button type="button" id="periodo-filtro-clear" class="periodo-filtro-clear" title="Volver al rango principal y poder seleccionar otro día">
                    &#x21A9; Volver al rango principal
                </button>
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
                    <span class="evolucion-toggle-dot" style="background:#3a6aa3"></span>
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
                    <span class="evolucion-toggle-dot" style="background:#8c181a"></span>
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
                                <span class="evolucion-toggle-dot" style="background:#3a6aa3"></span>
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
                                <span class="evolucion-toggle-dot" style="background:#8c181a"></span>
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

                    <!-- ───── Bloque DISPONIBILIDAD POR REFERENCIA EN LA MÁQUINA ─────
                         Solo visible cuando se ha clicado una máquina (modo Máquina).
                         Lista las referencias fabricadas en esa máquina con las horas
                         de paro acumuladas mientras se fabricaba cada una.
                         Clic en una referencia → bloque temporal acumulado debajo. -->
                    <div id="maq-refs-wrap" style="display:none;margin-top:20px;padding-top:14px;border-top:2px dashed #f4b3a6">
                        <div class="oee-detalle-subtitle">
                            Horas de paro por <strong>referencia</strong> en esta máquina
                            <small>(mayor → menor · clic en una referencia para ver su evolución temporal)</small>
                        </div>
                        <div id="chart-maq-refs"></div>

                        <!-- Sub-nivel: gráfico temporal acumulado de paros para
                             la referencia clicada -->
                        <div id="maq-ref-tempo-wrap" style="display:none;margin-top:14px">
                            <div class="oee-detalle-subtitle">
                                Acumulado de paros · <span id="maq-ref-tempo-label">—</span>
                                <small id="maq-ref-tempo-sub">(horas acumuladas a lo largo del tiempo)</small>
                            </div>
                            <div id="chart-maq-ref-tempo"></div>
                        </div>
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

            <!-- ═══════════════════════════════════════════════════════════
                 MÓDULO · Histograma de motivos de disponibilidad
                          por una máquina concreta, con filtro de fechas.
                 Bloque colapsable: arranca cerrado, se abre al pulsar.
                 ═══════════════════════════════════════════════════════════ -->
            <!-- (El histograma de motivos por máquina se abre desde el
                  botón "📊 Motivos por máquina" de la barra superior en
                  un popup a pantalla completa — ver div#hist-maq-modal). -->

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

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL · Histograma motivos de disponibilidad por máquina
       Popup full-screen (96vw × 96vh). Abre el botón "📊 Motivos por máquina"
       de la barra superior. Mismo HTML que antes vivía en el módulo inline,
       pero ahora ocupa toda la ventana y los botones de zoom del toolbar
       de ApexCharts se ven sin que el CSS del view-card los recorte.
     ═══════════════════════════════════════════════════════════════════════════ -->
<style>
    /* Forzamos que el toolbar de Apex (lupa+, lupa-, reset, download)
       sea siempre visible y quede por encima de los gradientes de fondo.
       En algunos navegadores quedaba semitransparente o tapado. */
    #hist-maq-modal .apexcharts-toolbar,
    #cambiosof-modal .apexcharts-toolbar {
        opacity: 1 !important;
        visibility: visible !important;
        z-index: 100 !important;
    }
    #hist-maq-modal .apexcharts-toolbar .apexcharts-zoomin-icon,
    #hist-maq-modal .apexcharts-toolbar .apexcharts-zoomout-icon,
    #hist-maq-modal .apexcharts-toolbar .apexcharts-reset-icon,
    #hist-maq-modal .apexcharts-toolbar .apexcharts-download-icon,
    #cambiosof-modal .apexcharts-toolbar .apexcharts-zoomin-icon,
    #cambiosof-modal .apexcharts-toolbar .apexcharts-zoomout-icon,
    #cambiosof-modal .apexcharts-toolbar .apexcharts-reset-icon,
    #cambiosof-modal .apexcharts-toolbar .apexcharts-download-icon {
        opacity: .85 !important;
        cursor: pointer !important;
    }
    #hist-maq-modal .apexcharts-toolbar svg,
    #cambiosof-modal .apexcharts-toolbar svg { fill: #2d4d7a !important; }
</style>
<div id="hist-maq-modal" style="display:none;position:fixed;inset:0;z-index:9000">
    <div id="hist-maq-backdrop" style="position:absolute;inset:0;background:rgba(15,28,48,.55);backdrop-filter:blur(2px)"></div>
    <div role="dialog" aria-modal="true" aria-labelledby="hist-maq-modal-title"
         style="position:relative;margin:1vh auto;width:99vw;max-width:none;height:98vh;
                background:#fff;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,.35);
                display:flex;flex-direction:column;overflow:hidden">
        <header style="display:flex;align-items:center;gap:12px;padding:10px 14px;
                       background:linear-gradient(135deg,#1a4a7a 0%,#2d4d7a 100%);color:#fff">
            <span style="font-size:20px">📊</span>
            <strong id="hist-maq-modal-title" style="font-size:16px">Histograma de motivos de disponibilidad por máquina</strong>
            <span style="font-size:12.5px;opacity:0.85">— elige una máquina y un rango. Botones lupa+/lupa− del toolbar para zoom horizontal.</span>
            <button type="button" id="hist-maq-modal-close" title="Cerrar"
                    style="margin-left:auto;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.32);
                           color:#fff;font-size:18px;width:34px;height:34px;border-radius:6px;cursor:pointer;line-height:1">×</button>
        </header>
        <div style="flex:1;overflow:auto;padding:8px 4px 4px 4px">
            <div class="dual-selector-row" style="margin-bottom:14px;gap:10px;flex-wrap:wrap">
                <!-- Selector múltiple de máquinas con modo incluir / excluir.
                     El <select id="hist-maq-select"> se mantiene oculto para
                     que el JS antiguo siga leyendo .value (vacío = "todas").
                     El estado real está en window.__histMaqMulti que se serializa
                     a `desc_maquinas_in` o `desc_maquinas_excl` al aplicar. -->
                <div class="machine-selector-row" style="flex:0 0 auto;position:relative">
                    <span class="machine-selector-label">Máquinas:</span>
                    <button type="button" id="hist-maq-multi-toggle" class="machine-selector"
                            style="min-width:240px;text-align:left;cursor:pointer;background:#fff;border:1px solid #c5d2e0">
                        <span id="hist-maq-multi-label">Todas las máquinas</span>
                        <span style="float:right;color:#5b6f86">▼</span>
                    </button>
                    <select id="hist-maq-select" style="display:none">
                        <option value="" selected>Todas las máquinas</option>
                        <?php foreach ($_maquinasHist as $m): ?>
                        <option value="<?= htmlspecialchars($m, ENT_QUOTES) ?>"><?= htmlspecialchars($m, ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="hist-maq-multi-panel" hidden
                         style="position:absolute;top:100%;left:0;z-index:1000;margin-top:4px;
                                background:#fff;border:1px solid #c5d2e0;border-radius:6px;
                                box-shadow:0 6px 22px rgba(15,28,48,.18);min-width:340px;max-width:420px;
                                padding:10px">
                        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
                            <label style="display:flex;gap:4px;align-items:center;font-size:12.5px;cursor:pointer">
                                <input type="radio" name="hist-maq-multi-modo" value="incluir" checked> Solo estas
                            </label>
                            <label style="display:flex;gap:4px;align-items:center;font-size:12.5px;cursor:pointer">
                                <input type="radio" name="hist-maq-multi-modo" value="excluir"> Excluir estas
                            </label>
                            <span style="margin-left:auto;font-size:11px;color:#5b6f86" id="hist-maq-multi-count">0 / <?= count($_maquinasHist) ?></span>
                        </div>
                        <input type="search" id="hist-maq-multi-search" placeholder="Buscar máquina…"
                               style="width:100%;padding:5px 8px;border:1px solid #c5d2e0;border-radius:4px;font-size:12.5px;margin-bottom:6px">
                        <div style="display:flex;gap:6px;margin-bottom:6px">
                            <button type="button" id="hist-maq-multi-all" style="flex:1;padding:4px 6px;font-size:11.5px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer">Marcar todas</button>
                            <button type="button" id="hist-maq-multi-none" style="flex:1;padding:4px 6px;font-size:11.5px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer">Quitar todas</button>
                        </div>
                        <div id="hist-maq-multi-list" style="max-height:260px;overflow-y:auto;border:1px solid #eef2f6;border-radius:4px;padding:4px 6px">
                            <?php foreach ($_maquinasHist as $m): ?>
                            <label class="hist-maq-multi-item" style="display:flex;gap:6px;align-items:center;font-size:12.5px;padding:2px 4px;cursor:pointer;border-radius:3px">
                                <input type="checkbox" class="hist-maq-multi-cb" value="<?= htmlspecialchars($m, ENT_QUOTES) ?>">
                                <span><?= htmlspecialchars($m, ENT_QUOTES) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div style="text-align:right;margin-top:8px">
                            <button type="button" id="hist-maq-multi-close" style="padding:5px 12px;background:#2d4d7a;color:#fff;border:0;border-radius:4px;font-weight:600;cursor:pointer">Cerrar</button>
                        </div>
                    </div>
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="hist-maq-desde" class="machine-selector-label">Desde:</label>
                    <input type="date" id="hist-maq-desde" class="machine-selector" style="min-width:150px">
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="hist-maq-hasta" class="machine-selector-label">Hasta:</label>
                    <input type="date" id="hist-maq-hasta" class="machine-selector" style="min-width:150px">
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto;gap:4px">
                    <span class="machine-selector-label">Rápido:</span>
                    <button type="button" class="hist-maq-quick" data-range="today"     style="padding:5px 10px;font-size:12px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer;font-weight:600;color:#2d4d7a">Hoy</button>
                    <button type="button" class="hist-maq-quick" data-range="yesterday" style="padding:5px 10px;font-size:12px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer;font-weight:600;color:#2d4d7a">Ayer</button>
                    <button type="button" class="hist-maq-quick" data-range="week"      style="padding:5px 10px;font-size:12px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer;font-weight:600;color:#2d4d7a">Semana</button>
                </div>
                <button type="button" id="hist-maq-clear" title="Restablece todos los filtros: mes actual, todas las máquinas, todos los turnos, sin filtro horario"
                        style="flex:0 0 auto;padding:6px 12px;font-size:12px;background:#fdecec;border:1px solid #e9a4a4;border-radius:4px;cursor:pointer;font-weight:600;color:#8a0d22">× Quitar filtros</button>
                <div class="machine-selector-row turno-multi" style="flex:0 0 auto;gap:8px">
                    <span class="machine-selector-label">Turnos:</span>
                    <label class="turno-chip"><input type="checkbox" class="hist-maq-turno" value="M"> M</label>
                    <label class="turno-chip"><input type="checkbox" class="hist-maq-turno" value="T"> T</label>
                    <label class="turno-chip"><input type="checkbox" class="hist-maq-turno" value="N"> N</label>
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto;gap:6px">
                    <label for="hist-maq-hora-desde" class="machine-selector-label" title="Solo cuenta paros cuya hora de inicio cae en este rango horario. Si Desde > Hasta el rango cruza medianoche (ej. 22:00 → 06:00).">Hora:</label>
                    <input type="time" id="hist-maq-hora-desde" class="machine-selector" style="min-width:100px" step="60" placeholder="--:--">
                    <span class="machine-selector-label">→</span>
                    <input type="time" id="hist-maq-hora-hasta" class="machine-selector" style="min-width:100px" step="60" placeholder="--:--">
                    <button type="button" id="hist-maq-hora-clear" class="machine-selector-clear" title="Quitar filtro horario" style="padding:4px 8px">×</button>
                </div>
                <button type="button" id="hist-maq-aplicar" class="top-aplicar-btn" style="flex:0 0 auto">Aplicar</button>
                <button type="button" id="hist-maq-xlsx" title="Exporta los paros visibles con sus motivos, máquinas y duraciones a un fichero Excel listo para trabajar"
                        style="flex:0 0 auto;background:#8c181a;color:#fff;border:0;padding:7px 14px;border-radius:6px;font-weight:700;cursor:pointer;font-size:13px;box-shadow:0 1px 3px rgba(16,185,129,0.30)">⬇ Exportar XLSX</button>
            </div>
            <div id="hist-maq-resumen" style="display:none;background:#eef3f8;border-left:4px solid #1a4a7a;padding:8px 12px;border-radius:6px;margin:0 8px 10px 8px;font-size:13.5px;color:#1a2d4a">—</div>
            <div id="hist-maq-empty" class="top-empty" style="padding:24px;text-align:center;color:#5b6f86;font-style:italic">Pulsa Aplicar para ver el histograma (por defecto, todas las máquinas).</div>
            <!-- Chart container: ancho completo del modal (sin márgenes ajenos)
                 para aprovechar al máximo el espacio horizontal. -->
            <div id="hist-maq-chart" style="display:none;width:100%;min-height:500px;margin:0;padding:0"></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL · Cambios en OF
       Popup a pantalla casi completa (95vw / 92vh). Lo abre el botón de la
       barra superior. Contiene los mismos filtros + el bar chart de ranking
       y el sub-bloque de detalle cronológico (gráfico vertical).
     ═══════════════════════════════════════════════════════════════════════════ -->
<div id="cambiosof-modal" style="display:none;position:fixed;inset:0;z-index:9000">
    <div id="cambiosof-backdrop" style="position:absolute;inset:0;background:rgba(15,28,48,.55);backdrop-filter:blur(2px)"></div>
    <div role="dialog" aria-modal="true" aria-labelledby="cambiosof-modal-title"
         style="position:relative;margin:2vh auto;width:96vw;max-width:1800px;height:96vh;
                background:#fff;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.35);
                display:flex;flex-direction:column;overflow:hidden">
        <header style="display:flex;align-items:center;gap:12px;padding:12px 18px;
                       background:linear-gradient(135deg,#1a4a7a 0%,#2d4d7a 100%);color:#fff">
            <span style="font-size:20px">🔁</span>
            <strong id="cambiosof-modal-title" style="font-size:16px">Cambios en OF</strong>
            <span style="font-size:12.5px;opacity:0.85">— ranking por máquina (mayor → menor). Pulsa una barra para ver el detalle cronológico.</span>
            <button type="button" id="cambiosof-modal-close"
                    title="Cerrar"
                    style="margin-left:auto;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.32);
                           color:#fff;font-size:18px;width:34px;height:34px;border-radius:6px;cursor:pointer;line-height:1">×</button>
        </header>
        <div style="flex:1;overflow:auto;padding:14px 18px">

            <div class="dual-selector-row" style="margin-bottom:14px;gap:10px;flex-wrap:wrap">
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="cambiosof-desde" class="machine-selector-label">Desde:</label>
                    <input type="date" id="cambiosof-desde" class="machine-selector" style="min-width:150px">
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto">
                    <label for="cambiosof-hasta" class="machine-selector-label">Hasta:</label>
                    <input type="date" id="cambiosof-hasta" class="machine-selector" style="min-width:150px">
                </div>
                <div class="machine-selector-row turno-multi" style="flex:0 0 auto;gap:8px">
                    <span class="machine-selector-label">Turnos:</span>
                    <label class="turno-chip"><input type="checkbox" class="cambiosof-turno" value="M"> M</label>
                    <label class="turno-chip"><input type="checkbox" class="cambiosof-turno" value="T"> T</label>
                    <label class="turno-chip"><input type="checkbox" class="cambiosof-turno" value="N"> N</label>
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto;gap:6px">
                    <label for="cambiosof-hora-desde" class="machine-selector-label" title="Sólo cuenta cambios que arrancan en este intervalo horario">Hora:</label>
                    <input type="time" id="cambiosof-hora-desde" class="machine-selector" style="min-width:100px" step="60" placeholder="--:--">
                    <span class="machine-selector-label">→</span>
                    <input type="time" id="cambiosof-hora-hasta" class="machine-selector" style="min-width:100px" step="60" placeholder="--:--">
                    <button type="button" id="cambiosof-hora-clear" class="machine-selector-clear" title="Quitar filtro horario" style="padding:4px 8px">×</button>
                </div>
                <div class="machine-selector-row" style="flex:1 1 320px;gap:6px"
                     title="Patrones LIKE separados por | (OR). Por defecto sólo paros que indican cambio de OF/REFERENCIA — NO cambio de utillaje, turno, etc. Edita si tu MAPEX usa otra nomenclatura.">
                    <label for="cambiosof-motivo" class="machine-selector-label">Motivo:</label>
                    <input type="text" id="cambiosof-motivo" class="machine-selector" style="min-width:360px;flex:1"
                           value="%CAMBIO DE OF%|%CAMBIO DE REFERENCIA%|%CAMBIO DE FORMATO%|%CAMBIO DE PRODUCTO%|%CAMBIO REF%"
                           placeholder="%CAMBIO DE OF%|%CAMBIO DE REFERENCIA%">
                </div>
                <div class="machine-selector-row" style="flex:0 0 auto;gap:4px">
                    <span class="machine-selector-label">Rápido:</span>
                    <button type="button" class="cambiosof-quick" data-range="today"     style="padding:5px 10px;font-size:12px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer;font-weight:600;color:#2d4d7a">Hoy</button>
                    <button type="button" class="cambiosof-quick" data-range="yesterday" style="padding:5px 10px;font-size:12px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer;font-weight:600;color:#2d4d7a">Ayer</button>
                    <button type="button" class="cambiosof-quick" data-range="week"      style="padding:5px 10px;font-size:12px;background:#eef2f6;border:1px solid #c5d2e0;border-radius:4px;cursor:pointer;font-weight:600;color:#2d4d7a">Semana</button>
                </div>
                <button type="button" id="cambiosof-clear" title="Restablece todos los filtros: mes actual, todos los turnos, sin filtro horario y patrón de motivo por defecto"
                        style="flex:0 0 auto;padding:6px 12px;font-size:12px;background:#fdecec;border:1px solid #e9a4a4;border-radius:4px;cursor:pointer;font-weight:600;color:#8a0d22">× Quitar filtros</button>
                <button type="button" id="cambiosof-aplicar" class="top-aplicar-btn" style="flex:0 0 auto">Aplicar</button>
            </div>

            <div id="cambiosof-resumen" style="display:none;background:#eef3f8;border-left:4px solid #1a4a7a;padding:10px 14px;border-radius:6px;margin-bottom:12px;font-size:13.5px;color:#1a2d4a">—</div>
            <div id="cambiosof-empty" class="top-empty" style="padding:24px;text-align:center;color:#5b6f86;font-style:italic">Pulsa Aplicar para ver el ranking de cambios por máquina.</div>
            <div id="cambiosof-chart" style="display:none;min-height:380px"></div>

            <!-- Submódulo: detalle cronológico de la máquina pulsada (vertical) -->
            <div id="cambiosof-detalle" style="display:none;margin-top:18px;border-top:2px solid #d5dfe8;padding-top:14px">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                    <span style="font-size:16px">🕒</span>
                    <strong id="cambiosof-detalle-titulo" style="font-size:13.5px;color:#1a2d4a">Cambios cronológicos</strong>
                    <button type="button" id="cambiosof-detalle-cerrar"
                            style="margin-left:auto;background:#fff;color:#5b6f86;border:1px solid #d5dfe8;padding:4px 10px;border-radius:4px;cursor:pointer;font-size:12px">Cerrar detalle</button>
                </div>
                <div id="cambiosof-detalle-resumen" style="background:#fff8e6;border-left:4px solid #f0c674;padding:8px 12px;border-radius:6px;margin-bottom:10px;font-size:12.5px;color:#7a5b1b">—</div>
                <div id="cambiosof-detalle-chart" style="min-height:420px"></div>
            </div>

        </div>
    </div>
</div>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_oee_unificado.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_oee_unificado.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>

<script>
/* ════════════ MÓDULO · Histograma motivos disponibilidad por máquina ════════════
   Independiente del resto del panel. Llama directamente a
   api/oee_unificado_hist_maquina.php con filtro de máquina + rango + turnos.
═══════════════════════════════════════════════════════════════════════════════ */
(function () {
    const modal    = document.getElementById('hist-maq-modal');
    const btnOpen  = document.getElementById('btn-hist-maq');
    const btnClose = document.getElementById('hist-maq-modal-close');
    const backdrop = document.getElementById('hist-maq-backdrop');
    if (!modal || !btnOpen) return;

    // Inicializar fechas por defecto: día 1 del mes actual → hoy.
    const today = new Date();
    const desde = new Date(today.getFullYear(), today.getMonth(), 1);
    const pad   = n => String(n).padStart(2, '0');
    const toIso = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
    document.getElementById('hist-maq-desde').value = toIso(desde);
    document.getElementById('hist-maq-hasta').value = toIso(today);

    function abrirModal() {
        modal.style.display = '';
        document.body.style.overflow = 'hidden';
        // Forzamos a ApexCharts a recalcular ancho y alto: dispara un resize
        // del window con un pequeño delay para que el modal esté pintado.
        setTimeout(() => {
            try { ajustarAlturaChart(); } catch(_){}
            try { window.dispatchEvent(new Event('resize')); } catch(_){}
        }, 80);
    }
    function cerrarModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
    btnOpen.addEventListener('click', abrirModal);
    btnClose.addEventListener('click', cerrarModal);
    backdrop.addEventListener('click', cerrarModal);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.style.display !== 'none') cerrarModal();
    });

    let chart = null;
    const aplicarBtn = document.getElementById('hist-maq-aplicar');
    aplicarBtn.addEventListener('click', cargarHistograma);
    document.getElementById('hist-maq-hora-clear')?.addEventListener('click', () => {
        document.getElementById('hist-maq-hora-desde').value = '';
        document.getElementById('hist-maq-hora-hasta').value = '';
    });

    // ── Botones rápidos Hoy / Ayer / Semana ──────────────────────────
    document.querySelectorAll('.hist-maq-quick').forEach(btn => {
        btn.addEventListener('click', () => {
            const today = new Date();
            const pad = n => String(n).padStart(2, '0');
            const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
            let desde = today, hasta = today;
            const kind = btn.dataset.range;
            if (kind === 'yesterday') {
                const y = new Date(today); y.setDate(y.getDate() - 1);
                desde = y; hasta = y;
            } else if (kind === 'week') {
                const dow = today.getDay() === 0 ? 7 : today.getDay();
                const mon = new Date(today); mon.setDate(today.getDate() - (dow - 1));
                desde = mon; hasta = today;
            }
            document.getElementById('hist-maq-desde').value = fmt(desde);
            document.getElementById('hist-maq-hasta').value = fmt(hasta);
        });
    });

    // ── Quitar filtros: restablece todo al estado por defecto ─────────
    document.getElementById('hist-maq-clear')?.addEventListener('click', () => {
        // Rango: mes actual (día 1 → hoy)
        const today2 = new Date();
        const pad2 = n => String(n).padStart(2, '0');
        const fmt2 = d => `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
        document.getElementById('hist-maq-desde').value = fmt2(new Date(today2.getFullYear(), today2.getMonth(), 1));
        document.getElementById('hist-maq-hasta').value = fmt2(today2);
        // Turnos: todos desmarcados (= todos los turnos en el endpoint)
        document.querySelectorAll('.hist-maq-turno').forEach(cb => { cb.checked = false; });
        // Filtro horario: vaciar
        document.getElementById('hist-maq-hora-desde').value = '';
        document.getElementById('hist-maq-hora-hasta').value = '';
        // Selección de máquinas: vaciar (→ todas)
        _histMulti.sel.clear();
        _histMulti.modo = 'incluir';
        document.querySelectorAll('.hist-maq-multi-cb').forEach(cb => { cb.checked = false; });
        const radioInc = document.querySelector('input[name="hist-maq-multi-modo"][value="incluir"]');
        if (radioInc) radioInc.checked = true;
        const search = document.getElementById('hist-maq-multi-search');
        if (search) { search.value = ''; search.dispatchEvent(new Event('input')); }
        _updateMultiLabel();
        // Vista: vacío + mensaje neutral hasta que el usuario pulse Aplicar.
        mostrarVacio('Filtros restablecidos. Pulsa Aplicar para ver el histograma del mes actual.');
    });

    // ── Panel multi-selector de máquinas (incluir / excluir) ─────────
    // Estado: lista de máquinas seleccionadas + modo ('incluir' | 'excluir').
    const _histMulti = { sel: new Set(), modo: 'incluir' };
    const _multiPanel  = document.getElementById('hist-maq-multi-panel');
    const _multiToggle = document.getElementById('hist-maq-multi-toggle');
    const _multiLabel  = document.getElementById('hist-maq-multi-label');
    const _multiCount  = document.getElementById('hist-maq-multi-count');
    const _multiSearch = document.getElementById('hist-maq-multi-search');
    const _multiList   = document.getElementById('hist-maq-multi-list');
    const TOTAL_MAQ    = document.querySelectorAll('.hist-maq-multi-cb').length;

    function _updateMultiLabel() {
        const n = _histMulti.sel.size;
        if (n === 0) {
            _multiLabel.textContent = 'Todas las máquinas';
        } else if (_histMulti.modo === 'incluir') {
            _multiLabel.textContent = n === 1
                ? Array.from(_histMulti.sel)[0]
                : 'Solo estas ' + n + ' máquinas';
        } else {
            _multiLabel.textContent = 'Todas excepto ' + n;
        }
        _multiCount.textContent = n + ' / ' + TOTAL_MAQ;
    }
    _multiToggle.addEventListener('click', () => {
        _multiPanel.hidden = !_multiPanel.hidden;
    });
    document.getElementById('hist-maq-multi-close').addEventListener('click', () => {
        _multiPanel.hidden = true;
    });
    document.addEventListener('click', e => {
        if (_multiPanel.hidden) return;
        if (_multiPanel.contains(e.target) || _multiToggle.contains(e.target)) return;
        _multiPanel.hidden = true;
    });
    document.querySelectorAll('input[name="hist-maq-multi-modo"]').forEach(r => {
        r.addEventListener('change', () => {
            _histMulti.modo = r.value;
            _updateMultiLabel();
        });
    });
    document.querySelectorAll('.hist-maq-multi-cb').forEach(cb => {
        cb.addEventListener('change', () => {
            if (cb.checked) _histMulti.sel.add(cb.value);
            else            _histMulti.sel.delete(cb.value);
            _updateMultiLabel();
        });
    });
    _multiSearch.addEventListener('input', () => {
        const q = _multiSearch.value.toLowerCase().trim();
        _multiList.querySelectorAll('.hist-maq-multi-item').forEach(it => {
            const txt = it.textContent.toLowerCase();
            it.style.display = (!q || txt.includes(q)) ? '' : 'none';
        });
    });
    document.getElementById('hist-maq-multi-all').addEventListener('click', () => {
        _multiList.querySelectorAll('.hist-maq-multi-cb').forEach(cb => {
            if (cb.closest('.hist-maq-multi-item').style.display !== 'none') {
                cb.checked = true; _histMulti.sel.add(cb.value);
            }
        });
        _updateMultiLabel();
    });
    document.getElementById('hist-maq-multi-none').addEventListener('click', () => {
        _multiList.querySelectorAll('.hist-maq-multi-cb').forEach(cb => {
            cb.checked = false; _histMulti.sel.delete(cb.value);
        });
        _updateMultiLabel();
    });
    _updateMultiLabel();

    // Export XLSX: dispara el endpoint con los mismos filtros que el chart.
    // Si faltan fechas avisa, igual que cargarHistograma. NOTA: el export NO
    // recibe `desc_maquinas_in`/`desc_maquinas_excl` — mantiene su comportamiento
    // intacto (siempre devuelve todas las máquinas en el XLSX). El usuario lo ha
    // marcado como "perfecto, NO TOCAR".
    document.getElementById('hist-maq-xlsx')?.addEventListener('click', () => {
        const fd  = document.getElementById('hist-maq-desde').value;
        const fh  = document.getElementById('hist-maq-hasta').value;
        if (!fd || !fh) {
            alert('Indica un rango de fechas válido antes de exportar.');
            return;
        }
        const turnos = Array.from(document.querySelectorAll('.hist-maq-turno:checked'))
                            .map(c => c.value);
        const hd = document.getElementById('hist-maq-hora-desde').value;
        const hh = document.getElementById('hist-maq-hora-hasta').value;
        const horaActiva = (hd && hh && hd !== hh);
        if ((hd || hh) && !horaActiva) {
            alert('Para el filtro horario rellena AMBOS valores (Desde y Hasta) y deben ser distintos.');
            return;
        }
        const params = new URLSearchParams({
            fecha_desde: fd,
            fecha_hasta: fh,
        });
        if (turnos.length)  params.set('turnos', turnos.join(','));
        if (horaActiva) {
            params.set('hora_desde', hd);
            params.set('hora_hasta', hh);
        }
        window.location.href = '../api/oee_unificado_hist_maquina_export.php?' + params.toString();
    });

    async function cargarHistograma() {
        const fd  = document.getElementById('hist-maq-desde').value;
        const fh  = document.getElementById('hist-maq-hasta').value;
        if (!fd || !fh) {
            mostrarVacio('Indica un rango de fechas válido.');
            return;
        }
        const turnos = Array.from(document.querySelectorAll('.hist-maq-turno:checked'))
                            .map(c => c.value);

        // Intervalo horario: se manda si están AMBOS rellenos y son distintos.
        const hd = document.getElementById('hist-maq-hora-desde').value;
        const hh = document.getElementById('hist-maq-hora-hasta').value;
        const horaActiva = (hd && hh && hd !== hh);
        if ((hd || hh) && !horaActiva) {
            mostrarVacio('Para el filtro horario rellena AMBOS valores (Desde y Hasta) y deben ser distintos.');
            return;
        }

        mostrarVacio('Cargando datos desde MAPEX…');

        const params = new URLSearchParams({
            fecha_desde:  fd,
            fecha_hasta:  fh,
        });
        // Selección múltiple: si hay máquinas marcadas y modo='incluir' →
        // `desc_maquinas_in`; si modo='excluir' → `desc_maquinas_excl`.
        // Si no hay nada marcado: todas las máquinas (como antes).
        if (_histMulti.sel.size > 0) {
            const csv = Array.from(_histMulti.sel).join('||');
            if (_histMulti.modo === 'incluir') params.set('desc_maquinas_in',   csv);
            else                                params.set('desc_maquinas_excl', csv);
        }
        if (turnos.length)  params.set('turnos', turnos.join(','));
        if (horaActiva) {
            params.set('hora_desde', hd);
            params.set('hora_hasta', hh);
        }

        try {
            const r = await fetch('../api/oee_unificado_hist_maquina.php?' + params.toString(),
                                  { cache: 'no-store' });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Error');
            renderHistograma(j.data);
        } catch (e) {
            mostrarVacio('Error: ' + (e.message || e));
        }
    }

    function mostrarVacio(msg) {
        document.getElementById('hist-maq-empty').textContent = msg;
        document.getElementById('hist-maq-empty').style.display = '';
        document.getElementById('hist-maq-chart').style.display = 'none';
        document.getElementById('hist-maq-resumen').style.display = 'none';
        if (chart) { try { chart.destroy(); } catch(_){} chart = null; }
    }

    function renderHistograma(d) {
        const motivos  = d.motivos  || [];   // [{motivo,horas,pct}, ...] orden DESC
        const maquinas = d.maquinas || [];   // [{maquina,horas}, ...]   orden DESC
        const eventos  = d.eventos  || [];   // [{maquina,motivo,inicio,fin,segundos}]
        if (!eventos.length || !maquinas.length) {
            mostrarVacio('No hay paros registrados con los filtros seleccionados.');
            return;
        }
        document.getElementById('hist-maq-empty').style.display = 'none';
        document.getElementById('hist-maq-chart').style.display = '';

        // ── Cabecera resumen ─────────────────────────────────────────
        const fmtFecha = iso => iso ? iso.split('-').reverse().join('/') : '—';
        const totH = d.total_horas || 0;
        const h = Math.floor(totH);
        const m = Math.round((totH - h) * 60);
        const totLabel = h > 0 ? h + 'h ' + m + 'min' : m + 'min';
        const turnosLabel = (d.turnos || []).join(', ') || 'Todos';
        const ambitoMaq = d.todas_las_maquinas
            ? '<strong>Todas las máquinas (' + maquinas.length + ')</strong>'
            : '<strong>' + escapeHtml(d.desc_maquina || '') + '</strong>';
        let horaLabel = '';
        if (d.hora_desde && d.hora_hasta) {
            horaLabel = ' · franja ' + d.hora_desde + ' → ' + d.hora_hasta
                      + (d.hora_cruza_medianoche ? ' (cruza medianoche)' : '');
        }
        const limitWarn = d.eventos_limitados
            ? ' · <span style="color:#c8102e;font-weight:700">⚠ Mostrando los primeros 5000 paros — afina los filtros para ver el detalle completo</span>'
            : '';
        const resumen = document.getElementById('hist-maq-resumen');
        resumen.innerHTML =
            ambitoMaq + ' · ' +
            '<strong>' + eventos.length + '</strong> paros · ' +
            '<strong>' + motivos.length + '</strong> motivos · ' +
            'total <strong>' + totLabel + '</strong> · ' +
            'rango ' + fmtFecha(d.fecha_desde) + ' → ' + fmtFecha(d.fecha_hasta) + ' · ' +
            'turnos: ' + turnosLabel + horaLabel + limitWarn;
        resumen.style.display = '';

        // ── Construcción de las series para el timeline ──────────────
        // Cada paro = un rectángulo en la fila de su máquina (eje Y),
        // posicionado entre Fecha_ini y Fecha_fin (eje X = datetime).
        // Agrupamos por motivo para que cada motivo sea una serie con
        // su propio color y aparezca en la leyenda.
        const porMotivo = Object.create(null);
        for (const ev of eventos) {
            // 'YYYY-MM-DD HH:MM:SS' interpretado como hora local
            // — usamos replace para que Safari/Firefox lo parseen.
            const tIni = new Date(ev.inicio.replace(' ', 'T')).getTime();
            const tFin = new Date(ev.fin.replace(' ', 'T')).getTime();
            if (!porMotivo[ev.motivo]) porMotivo[ev.motivo] = [];
            porMotivo[ev.motivo].push({
                x: ev.maquina,
                y: [tIni, tFin],
                // Metadatos para el tooltip personalizado
                motivo:   ev.motivo,
                inicio:   ev.inicio,
                fin:      ev.fin,
                segundos: ev.segundos,
            });
        }

        // Las series siguen el orden DESC del backend (motivos con más
        // horas → arriba en la leyenda) y reciben colores fijos por
        // posición. Si un motivo no tiene eventos lo saltamos.
        const paleta = [
            '#1a4a7a','#c45a2c','#1f8a3c','#5b3fb8','#c8102e',
            '#2d4d7a','#d97706','#8c181a','#8a6fd1','#c8102e',
            '#3a6aa3','#e0a458','#7ab87c','#5b8cc7','#6b6b6b',
            '#5b8cc7','#9e9e9e',
        ];
        const series = [];
        const colors = [];
        motivos.forEach((mo, i) => {
            if (!porMotivo[mo.motivo]) return;
            series.push({ name: mo.motivo, data: porMotivo[mo.motivo] });
            colors.push(paleta[i % paleta.length]);
        });

        // Altura del cronograma adaptativa con techos seguros para que
        // ApexCharts no se atragante:
        //   · alturaVentana → ~75% del viewport (mín. 500, máx. 1100 px)
        //   · alturaPorFila → ~36 px por máquina (mín. 380 px)
        //   · Tomamos la mayor de las dos, capeada a 1600 px
        //     (más allá el render se pone exigente y degrada UX).
        const alturaVentana = Math.min(1100,
                              Math.max(500, Math.round(window.innerHeight * 0.75)));
        const alturaPorFila = Math.max(380, maquinas.length * 36 + 120);
        const alturaCalc    = Math.min(1600, Math.max(alturaVentana, alturaPorFila));

        // ── Acotar el eje X exactamente al rango pedido ──────────────
        // Sin esto, ApexCharts auto-escalaba para incluir el FIN de cada
        // paro, mostrando horas/días que NO estaban en el filtro. Ahora
        // marcamos min/max explícitos: los paros que se extienden más
        // allá se ven truncados visualmente en el borde.
        const [yD, mD, dD] = d.fecha_desde.split('-').map(Number);
        const [yH, mH, dH] = d.fecha_hasta.split('-').map(Number);
        const tsDesde00 = new Date(yD, mD - 1, dD, 0, 0, 0).getTime();
        const tsHasta00 = new Date(yH, mH - 1, dH, 0, 0, 0).getTime();
        let xMin, xMax;
        if (!d.hora_desde || !d.hora_hasta) {
            // Sin filtro horario: todo el día desde/hasta
            xMin = tsDesde00;
            xMax = tsHasta00 + 86400000;     // fecha_hasta + 1 día
        } else if (!d.hora_cruza_medianoche) {
            // Horario normal (p.ej. 06:00 → 18:00)
            const [hd, md] = d.hora_desde.split(':').map(Number);
            const [hh, mh] = d.hora_hasta.split(':').map(Number);
            xMin = tsDesde00 + (hd * 3600 + md * 60) * 1000;
            xMax = tsHasta00 + (hh * 3600 + mh * 60) * 1000;
        } else {
            // Cruza medianoche (p.ej. 22:00 → 06:00 = noche)
            const [hd, md] = d.hora_desde.split(':').map(Number);
            const [hh, mh] = d.hora_hasta.split(':').map(Number);
            xMin = tsDesde00 + (hd * 3600 + md * 60) * 1000;
            xMax = tsHasta00 + 86400000 + (hh * 3600 + mh * 60) * 1000;
        }

        // Pequeño helper de formateo para el tooltip.
        const fmtDateTime = ts => {
            const d = new Date(ts);
            const dd = String(d.getDate()).padStart(2, '0');
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const yy = d.getFullYear();
            const hh = String(d.getHours()).padStart(2, '0');
            const mi = String(d.getMinutes()).padStart(2, '0');
            const ss = String(d.getSeconds()).padStart(2, '0');
            return `${dd}/${mm}/${yy} ${hh}:${mi}:${ss}`;
        };
        const fmtDuracion = seg => {
            if (seg < 60) return seg + ' s';
            const m = Math.floor(seg / 60);
            const s = seg % 60;
            if (m < 60) return s ? `${m} min ${s} s` : `${m} min`;
            const h = Math.floor(m / 60);
            const mr = m % 60;
            return mr ? `${h} h ${mr} min` : `${h} h`;
        };

        const options = {
            chart: {
                type: 'rangeBar',
                height: alturaCalc,
                width:  '100%',            // ancho responsive del contenedor
                background: 'transparent',
                fontFamily: 'Arial',
                // Animaciones OFF: con muchos eventos se nota mucho la
                // diferencia y no aportan información.
                animations: { enabled: false },
                // IMPORTANTE: ApexCharts SOLO pinta los iconos zoomin /
                // zoomout / reset del toolbar si chart.zoom.enabled es
                // true. Lo dejamos a true para que se vean los botones,
                // pero compensamos:
                //   · `type:'x'` para limitar el zoom al eje horizontal.
                //   · `beforeZoom` (más abajo) clampa siempre a [xMin,xMax]
                //     para que ni el drag ni los botones puedan sacar la
                //     vista del rango filtrado.
                zoom:      { enabled: true, type: 'x', autoScaleYaxis: false },
                selection: { enabled: false },
                toolbar: {
                    show: true,
                    tools: {
                        download: true,
                        zoom:     true,    // habilita drag (recortado por beforeZoom)
                        zoomin:   true,    // botón lupa+
                        zoomout:  true,    // botón lupa−
                        pan:      false,   // sin panning
                        reset:    true,    // vuelve a [xMin, xMax]
                        selection: false,
                    },
                    autoSelected: 'zoom',
                },
                // Si pese a todo se desencadena un zoom (botones), lo
                // mantenemos siempre dentro de [xMin, xMax].
                events: {
                    beforeZoom: function (_ctx, { xaxis }) {
                        const nMin = Math.max(xMin, xaxis.min);
                        const nMax = Math.min(xMax, xaxis.max);
                        if (nMin >= nMax) return { xaxis: { min: xMin, max: xMax } };
                        return { xaxis: { min: nMin, max: nMax } };
                    },
                    beforeResetZoom: function () {
                        return { xaxis: { min: xMin, max: xMax } };
                    },
                },
            },
            series,
            colors,
            plotOptions: {
                bar: {
                    horizontal: true,
                    barHeight: '80%',
                    // Esta opción es la clave: agrupa todas las series
                    // que tienen filas con el mismo "x" (la misma máquina)
                    // sobre la MISMA línea horizontal, en vez de
                    // apilarlas verticalmente — que es lo que necesitamos
                    // para un cronograma tipo Gantt.
                    rangeBarGroupRows: true,
                },
            },
            xaxis: (function () {
                // Calculamos cuántas horas abarca el filtro para dar a
                // ApexCharts una "pista" de cuántas marcas de hora pintar.
                // Limitamos entre 6 y 24 para que no sature en rangos
                // grandes (días) ni quede vacío en rangos pequeños.
                const rangoHoras = Math.max(1, (xMax - xMin) / 3600000);
                const tickHint = Math.max(6, Math.min(24, Math.round(rangoHoras)));
                return {
                    type: 'datetime',
                    min: xMin,
                    max: xMax,
                    tickAmount: tickHint,
                    tickPlacement: 'on',
                    labels: {
                        datetimeUTC: false,
                        rotate: -35,
                        rotateAlways: rangoHoras > 8,
                        hideOverlappingLabels: true,
                        style: { colors: '#2d4d7a', fontSize: '11px' },
                        // Formato que muestra día+hora cuando el rango
                        // cruza fechas, y solo hora cuando es un único día.
                        datetimeFormatter: {
                            year:   'yyyy',
                            month:  "MMM 'yy",
                            day:    'dd/MM HH:mm',
                            hour:   'HH:mm',
                            minute: 'HH:mm',
                        },
                    },
                    title: {
                        text: 'Tiempo',
                        style: { fontWeight: 700, color: '#1a2d4a' },
                    },
                };
            })(),
            yaxis: {
                labels: {
                    style: { colors: '#1a1a1a', fontSize: '12px', fontWeight: 600 },
                    maxWidth: 260,
                },
            },
            dataLabels: { enabled: false },
            stroke: { width: 1, colors: ['#ffffff'] },
            tooltip: {
                // Tooltip personalizado — muestra motivo, máquina,
                // inicio, fin y duración legible.
                custom: function ({ seriesIndex, dataPointIndex, w }) {
                    const ev = w.config.series[seriesIndex].data[dataPointIndex];
                    const color = w.config.colors[seriesIndex] || '#1a4a7a';
                    return `
                        <div style="background:#fff;border:1px solid #d5dfe8;border-radius:6px;
                                    padding:10px 12px;font-size:12.5px;color:#1a2d4a;
                                    box-shadow:0 2px 10px rgba(0,0,0,0.14);max-width:340px">
                            <div style="font-weight:800;color:${color};
                                        border-bottom:1px solid #eef;
                                        padding-bottom:5px;margin-bottom:6px;
                                        font-size:13.5px">
                                ${escapeHtml(ev.motivo)}
                            </div>
                            <div style="margin-bottom:2px">
                                <span style="color:#5b6f86">Máquina:</span>
                                <strong>${escapeHtml(ev.x)}</strong>
                            </div>
                            <div style="margin-bottom:2px">
                                <span style="color:#5b6f86">Inicio:</span>
                                <strong>${fmtDateTime(ev.y[0])}</strong>
                            </div>
                            <div style="margin-bottom:2px">
                                <span style="color:#5b6f86">Fin:</span>
                                <strong>${fmtDateTime(ev.y[1])}</strong>
                            </div>
                            <div>
                                <span style="color:#5b6f86">Duración:</span>
                                <strong>${fmtDuracion(ev.segundos)}</strong>
                            </div>
                        </div>
                    `;
                },
            },
            legend: {
                show: true,
                position: 'bottom',
                horizontalAlign: 'left',
                fontSize: '12px',
                fontWeight: 600,
                markers: { width: 12, height: 12, radius: 3 },
                itemMargin: { horizontal: 8, vertical: 4 },
            },
            grid: {
                borderColor: '#d5dfe8',
                strokeDashArray: 3,
                xaxis: { lines: { show: true } },
                yaxis: { lines: { show: true } },
            },
        };

        if (chart) { try { chart.destroy(); } catch(_){} }
        chart = new ApexCharts(document.getElementById('hist-maq-chart'), options);
        chart.render();
    }

    // ── Adaptar el chart al tamaño de la ventana ────────────────────
    // Si el usuario redimensiona la ventana, recalculamos la altura.
    // Salvaguardas:
    //   · No tocar si el chart no existe (todavía sin Aplicar).
    //   · No tocar si el módulo está colapsado (display === 'none').
    //   · Capturar cualquier error de updateOptions para no romper la
    //     UI ni dejar el "Cargando…" pillado.
    let _resizeTO = null;
    function ajustarAlturaChart() {
        if (!chart) return;
        const cont = document.getElementById('hist-maq-chart');
        if (!cont || cont.style.display === 'none') return;
        try {
            const nMaq      = (chart.w?.globals?.labels?.length) || 1;
            const alturaVp  = Math.min(1100,
                              Math.max(500, Math.round(window.innerHeight * 0.75)));
            const alturaFil = Math.max(380, nMaq * 36 + 120);
            const nueva     = Math.min(1600, Math.max(alturaVp, alturaFil));
            chart.updateOptions({ chart: { height: nueva } }, false, false);
        } catch (_) { /* noop — no bloqueamos UI por errores de Apex */ }
    }
    window.addEventListener('resize', () => {
        clearTimeout(_resizeTO);
        _resizeTO = setTimeout(ajustarAlturaChart, 200);
    });

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])
        );
    }
})();

/* ════════════ MÓDULO · Cambios en OF ═══════════════════════════════════════
   Llama a api/oee_unificado_cambios_of.php con los mismos filtros que el
   histograma (fechas, turnos, hora opcional) más un patrón LIKE de motivo.
   1) Bar chart horizontal de máquinas ordenado DESC por nº de cambios.
   2) Al clicar una barra, dispara la llamada en modo "detalle" y pinta
      un segundo chart (rangeBar/timeline) con cada cambio cronológico.
═══════════════════════════════════════════════════════════════════════════════ */
(function () {
    const modal       = document.getElementById('cambiosof-modal');
    const btnOpen     = document.getElementById('btn-cambios-of');
    const btnClose    = document.getElementById('cambiosof-modal-close');
    const backdrop    = document.getElementById('cambiosof-backdrop');
    if (!modal || !btnOpen) return;

    // Fechas por defecto: día 1 del mes actual → hoy.
    // OJO: usamos componentes locales (no toISOString) para no caer en el
    // bug de zona horaria que devolvía el último día del mes anterior.
    const today = new Date();
    const desde = new Date(today.getFullYear(), today.getMonth(), 1);
    const pad   = n => String(n).padStart(2, '0');
    const toIso = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
    document.getElementById('cambiosof-desde').value = toIso(desde);
    document.getElementById('cambiosof-hasta').value = toIso(today);
    // Patrón de motivo "por defecto" — el mismo valor que el placeholder.
    const _CAMBIOSOF_MOTIVO_DEF = '%CAMBIO DE OF%|%CAMBIO DE REFERENCIA%|%CAMBIO DE FORMATO%|%CAMBIO DE PRODUCTO%|%CAMBIO REF%';

    // Abrir/cerrar modal
    function abrirModal() {
        modal.style.display = '';
        document.body.style.overflow = 'hidden';
        // Apex tarda en renderizar al estar dentro de un display:none antes,
        // así que disparamos un resize por si quedó el chart con tamaño 0.
        setTimeout(() => { if (chartMaq) try { chartMaq.render(); } catch(_){} }, 80);
    }
    function cerrarModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
    btnOpen.addEventListener('click', abrirModal);
    btnClose.addEventListener('click', cerrarModal);
    backdrop.addEventListener('click', cerrarModal);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.style.display !== 'none') cerrarModal();
    });

    let chartMaq = null;
    let chartDet = null;

    document.getElementById('cambiosof-aplicar')
        .addEventListener('click', () => cargarRanking(null));
    document.getElementById('cambiosof-hora-clear')
        ?.addEventListener('click', () => {
            document.getElementById('cambiosof-hora-desde').value = '';
            document.getElementById('cambiosof-hora-hasta').value = '';
        });
    document.getElementById('cambiosof-detalle-cerrar')
        ?.addEventListener('click', cerrarDetalle);

    // ── Botones rápidos Hoy / Ayer / Semana ──────────────────────────
    document.querySelectorAll('.cambiosof-quick').forEach(btn => {
        btn.addEventListener('click', () => {
            const tnow = new Date();
            let d1 = tnow, d2 = tnow;
            const kind = btn.dataset.range;
            if (kind === 'yesterday') {
                const y = new Date(tnow); y.setDate(y.getDate() - 1);
                d1 = y; d2 = y;
            } else if (kind === 'week') {
                const dow = tnow.getDay() === 0 ? 7 : tnow.getDay();
                const mon = new Date(tnow); mon.setDate(tnow.getDate() - (dow - 1));
                d1 = mon; d2 = tnow;
            }
            document.getElementById('cambiosof-desde').value = toIso(d1);
            document.getElementById('cambiosof-hasta').value = toIso(d2);
        });
    });

    // ── Quitar filtros: restablece todo al estado por defecto ─────────
    document.getElementById('cambiosof-clear')?.addEventListener('click', () => {
        const tnow = new Date();
        document.getElementById('cambiosof-desde').value =
            toIso(new Date(tnow.getFullYear(), tnow.getMonth(), 1));
        document.getElementById('cambiosof-hasta').value = toIso(tnow);
        document.querySelectorAll('.cambiosof-turno').forEach(cb => { cb.checked = false; });
        document.getElementById('cambiosof-hora-desde').value = '';
        document.getElementById('cambiosof-hora-hasta').value = '';
        document.getElementById('cambiosof-motivo').value = _CAMBIOSOF_MOTIVO_DEF;
        // Vista vuelve a estado neutro.
        const empty = document.getElementById('cambiosof-empty');
        const ch    = document.getElementById('cambiosof-chart');
        const res   = document.getElementById('cambiosof-resumen');
        if (empty) { empty.style.display = ''; empty.textContent = 'Filtros restablecidos. Pulsa Aplicar para ver el ranking del mes actual.'; }
        if (ch)    ch.style.display = 'none';
        if (res)   res.style.display = 'none';
        if (chartMaq) { try { chartMaq.destroy(); } catch(_){} chartMaq = null; }
        if (typeof cerrarDetalle === 'function') { try { cerrarDetalle(); } catch(_){} }
    });

    function lecturaFiltros(extraParams) {
        const fd = document.getElementById('cambiosof-desde').value;
        const fh = document.getElementById('cambiosof-hasta').value;
        if (!fd || !fh) { return { error: 'Indica un rango de fechas válido.' }; }
        const turnos = Array.from(document.querySelectorAll('.cambiosof-turno:checked')).map(c => c.value);
        const hd = document.getElementById('cambiosof-hora-desde').value;
        const hh = document.getElementById('cambiosof-hora-hasta').value;
        const horaActiva = (hd && hh && hd !== hh);
        if ((hd || hh) && !horaActiva) {
            return { error: 'Para el filtro horario rellena AMBOS valores y deben ser distintos.' };
        }
        const motivo = (document.getElementById('cambiosof-motivo').value || '%CAMBIO%').trim();
        const p = new URLSearchParams({
            fecha_desde: fd,
            fecha_hasta: fh,
            motivo,
        });
        if (turnos.length) p.set('turnos', turnos.join(','));
        if (horaActiva) {
            p.set('hora_desde', hd);
            p.set('hora_hasta', hh);
        }
        if (extraParams) {
            for (const [k, v] of Object.entries(extraParams)) p.set(k, v);
        }
        return { params: p };
    }

    function mostrarVacioMaq(msg) {
        document.getElementById('cambiosof-empty').textContent = msg;
        document.getElementById('cambiosof-empty').style.display = '';
        document.getElementById('cambiosof-chart').style.display = 'none';
        document.getElementById('cambiosof-resumen').style.display = 'none';
        cerrarDetalle();
        if (chartMaq) { try { chartMaq.destroy(); } catch(_){} chartMaq = null; }
    }

    async function cargarRanking() {
        const f = lecturaFiltros();
        if (f.error) { mostrarVacioMaq(f.error); return; }
        mostrarVacioMaq('Consultando MAPEX…');
        try {
            const r = await fetch('../api/oee_unificado_cambios_of.php?' + f.params.toString(),
                                  { cache: 'no-store' });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Error');
            lastData = j.data;
            renderRanking(j.data);
        } catch (e) {
            mostrarVacioMaq('Error: ' + (e.message || e));
        }
    }

    function renderRanking(d) {
        const arr = (d.por_maquina || []).filter(m => m.n_cambios > 0);
        if (!arr.length) {
            mostrarVacioMaq('No se han encontrado cambios con los filtros indicados.');
            return;
        }
        document.getElementById('cambiosof-empty').style.display = 'none';
        document.getElementById('cambiosof-chart').style.display = '';

        // Resumen superior
        const fmtFecha = iso => iso ? iso.split('-').reverse().join('/') : '—';
        const horaLbl = (d.hora_desde && d.hora_hasta)
            ? ' · franja ' + d.hora_desde + ' → ' + d.hora_hasta
              + (d.hora_cruza_medianoche ? ' (cruza medianoche)' : '')
            : '';
        const totalH = d.total_horas || 0;
        const h = Math.floor(totalH);
        const m = Math.round((totalH - h) * 60);
        const totLbl = h > 0 ? (h + 'h ' + m + 'min') : (m + 'min');
        document.getElementById('cambiosof-resumen').innerHTML =
            '<strong>' + arr.length + '</strong> máquinas con cambios · ' +
            '<strong>' + (d.total_cambios || 0) + '</strong> cambios totales · ' +
            'tiempo total <strong>' + totLbl + '</strong> · ' +
            'rango ' + fmtFecha(d.fecha_desde) + ' → ' + fmtFecha(d.fecha_hasta) + horaLbl + ' · ' +
            'motivo ' + escapeHtml(d.motivo_patron || '%CAMBIO%');
        document.getElementById('cambiosof-resumen').style.display = '';

        // Eje Y = máquinas (orden DESC por n_cambios)
        const cats   = arr.map(o => o.desc_maquina);
        const codes  = arr.map(o => o.cod_maquina);
        const counts = arr.map(o => o.n_cambios);
        const tots   = arr.map(o => o.minutos_total);    // duración acumulada
        const medios = arr.map(o => o.minutos_medio);

        const altura = Math.max(360, cats.length * 30 + 120);
        const options = {
            chart: {
                type: 'bar',
                height: altura,
                background: 'transparent',
                fontFamily: 'Arial',
                animations: { enabled: false },
                toolbar: { show: false },
                events: {
                    // Pulsar una barra dispara el detalle de esa máquina.
                    dataPointSelection: function (_e, _ctx, opts) {
                        const i = opts.dataPointIndex;
                        if (i < 0 || i >= codes.length) return;
                        cargarDetalle(codes[i], cats[i]);
                    },
                },
            },
            series: [{ name: 'Cambios', data: counts }],
            plotOptions: {
                bar: {
                    horizontal: true,
                    barHeight: '70%',
                    borderRadius: 3,
                    distributed: false,
                    dataLabels: { position: 'top' },
                },
            },
            colors: ['#3a6aa3'],
            xaxis: {
                categories: cats,
                title: { text: 'Nº de cambios de OF',
                         style: { fontWeight: 700, color: '#1a2d4a' } },
                labels: { style: { colors: '#2d4d7a', fontSize: '11px' } },
            },
            yaxis: {
                labels: {
                    style: { colors: '#1a1a1a', fontSize: '12px', fontWeight: 600 },
                    maxWidth: 280,
                },
            },
            dataLabels: {
                enabled: true,
                offsetX: 28,
                style: { colors: ['#1a2d4a'], fontSize: '11.5px', fontWeight: 700 },
                formatter: v => v,
            },
            tooltip: {
                shared: false,
                intersect: true,
                custom: function ({ dataPointIndex }) {
                    const n   = counts[dataPointIndex];
                    const tot = tots[dataPointIndex];
                    const med = medios[dataPointIndex];
                    return `<div style="background:#fff;border:1px solid #d5dfe8;
                                        border-radius:6px;padding:8px 10px;font-size:12px;
                                        color:#1a2d4a;max-width:280px;
                                        box-shadow:0 2px 8px rgba(0,0,0,0.10)">
                        <div style="font-weight:700;color:#2d4d7a;
                                    border-bottom:1px solid #eee;
                                    padding-bottom:4px;margin-bottom:4px">
                            ${escapeHtml(cats[dataPointIndex])}
                        </div>
                        <div><strong>${n}</strong> cambios</div>
                        <div>Tiempo total: <strong>${tot} min</strong></div>
                        <div>Tiempo medio: <strong>${med} min/cambio</strong></div>
                        <div style="margin-top:4px;color:#5b6f86;font-size:11px;font-style:italic">
                            Pulsa la barra para ver el detalle cronológico.
                        </div>
                    </div>`;
                },
            },
            grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
            legend: { show: false },
            states: {
                hover:  { filter: { type: 'lighten', value: 0.04 } },
                active: { filter: { type: 'darken',  value: 0.10 } },
            },
        };

        if (chartMaq) { try { chartMaq.destroy(); } catch(_){} }
        chartMaq = new ApexCharts(document.getElementById('cambiosof-chart'), options);
        chartMaq.render();
    }

    // ── Detalle cronológico de una máquina ─────────────────────────
    async function cargarDetalle(cod, nombre) {
        const f = lecturaFiltros({ cod_maquina: cod });
        if (f.error) return;
        const cont = document.getElementById('cambiosof-detalle');
        cont.style.display = '';
        document.getElementById('cambiosof-detalle-titulo').textContent =
            'Cambios cronológicos · ' + nombre;
        document.getElementById('cambiosof-detalle-resumen').textContent = 'Cargando…';
        const chartEl = document.getElementById('cambiosof-detalle-chart');
        chartEl.innerHTML = '';
        if (chartDet) { try { chartDet.destroy(); } catch(_){} chartDet = null; }

        try {
            const r = await fetch('../api/oee_unificado_cambios_of.php?' + f.params.toString(),
                                  { cache: 'no-store' });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Error');
            renderDetalle(j.data, nombre);
        } catch (e) {
            document.getElementById('cambiosof-detalle-resumen').textContent = 'Error: ' + (e.message || e);
        }
    }

    function renderDetalle(d, nombre) {
        const eventos = (d.detalle || []);
        const cont = document.getElementById('cambiosof-detalle-resumen');
        if (!eventos.length) {
            cont.textContent = 'No hay cambios para esta máquina en el filtro indicado.';
            return;
        }
        // Resumen
        let totSeg = 0; eventos.forEach(e => totSeg += e.segundos);
        const med = Math.round((totSeg / eventos.length) / 60);
        const total = Math.round(totSeg / 60);
        cont.innerHTML = '<strong>' + eventos.length + '</strong> cambios · '
            + 'tiempo total <strong>' + total + ' min</strong> · '
            + 'medio <strong>' + med + ' min/cambio</strong> · '
            + 'orden cronológico (1 = primero del rango)';

        // Cada cambio es una columna; eje X = orden cronológico
        // (1, 2, 3…), eje Y = duración en minutos. Coloreamos por motivo.
        const paleta = ['#1a4a7a','#c45a2c','#1f8a3c','#5b3fb8','#c8102e',
                        '#2d4d7a','#d97706','#8c181a','#3a6aa3','#6b6b6b'];
        const motCol = new Map();
        const data = eventos.map((ev, i) => {
            if (!motCol.has(ev.motivo)) motCol.set(ev.motivo, paleta[motCol.size % paleta.length]);
            return {
                x: '#' + (i + 1),                  // categoría = orden
                y: +(ev.segundos / 60).toFixed(2), // minutos
                fillColor: motCol.get(ev.motivo),
                __meta: ev,                         // payload completo para el tooltip
            };
        });

        // Helpers de formato (compartidos con tooltip)
        const fmtTS = ts => {
            const d = new Date(ts);
            const dd = String(d.getDate()).padStart(2, '0');
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const yy = d.getFullYear();
            const hh = String(d.getHours()).padStart(2, '0');
            const mi = String(d.getMinutes()).padStart(2, '0');
            const ss = String(d.getSeconds()).padStart(2, '0');
            return `${dd}/${mm}/${yy} ${hh}:${mi}:${ss}`;
        };
        const fmtDur = seg => {
            if (seg < 60) return seg + ' s';
            const m = Math.floor(seg / 60), s = seg % 60;
            if (m < 60) return s ? `${m} min ${s} s` : `${m} min`;
            const h = Math.floor(m / 60), mr = m % 60;
            return mr ? `${h} h ${mr} min` : `${h} h`;
        };

        // Altura proporcional al nº de eventos para que las columnas no se aplasten
        const altura = Math.max(380, Math.min(640, 320 + eventos.length * 6));

        const options = {
            chart: {
                type: 'bar',                       // vertical (column)
                height: altura,
                background: 'transparent',
                fontFamily: 'Arial',
                animations: { enabled: false },
                toolbar: {
                    show: true,
                    tools: { download: true, zoom: false, zoomin: true, zoomout: true,
                             pan: false, reset: true, selection: false },
                },
                zoom:      { enabled: false },
                selection: { enabled: false },
            },
            series: [{ name: 'Duración (min)', data }],
            plotOptions: {
                bar: {
                    horizontal: false,             // ← VERTICAL
                    columnWidth: eventos.length > 30 ? '60%' : '50%',
                    borderRadius: 3,
                    borderRadiusApplication: 'end',
                },
            },
            xaxis: {
                type: 'category',
                title: { text: 'Orden cronológico (1 = más antiguo)',
                         style: { fontWeight: 700, color: '#1a2d4a' } },
                labels: { style: { colors: '#2d4d7a', fontSize: '10.5px' },
                          rotate: -35, rotateAlways: eventos.length > 18,
                          hideOverlappingLabels: true },
            },
            yaxis: {
                title: { text: 'Duración del cambio (min)',
                         style: { fontWeight: 700, color: '#1a2d4a' } },
                labels: { style: { colors: '#2d4d7a', fontSize: '11px' },
                          formatter: v => (v ?? 0).toFixed(0) + ' m' },
            },
            dataLabels: { enabled: false },
            stroke: { show: false },
            tooltip: {
                // Tooltip rico: motivo, fechas, duración Y OF / referencia
                custom: function ({ dataPointIndex, w }) {
                    const pt = w.config.series[0].data[dataPointIndex];
                    const ev = pt.__meta || {};
                    const color = pt.fillColor || '#1a4a7a';
                    const tIni = new Date(ev.inicio.replace(' ', 'T')).getTime();
                    const tFin = new Date(ev.fin.replace(' ', 'T')).getTime();
                    const refLineas = [];
                    if (ev.cod_of) {
                        refLineas.push(
                            `<div><span style="color:#5b6f86">OF:</span> <strong>${escapeHtml(ev.cod_of)}</strong></div>`);
                    }
                    if (ev.desc_producto || ev.cod_producto) {
                        const ref = ev.desc_producto || ev.cod_producto;
                        const sub = (ev.desc_producto && ev.cod_producto && ev.cod_producto !== ev.desc_producto)
                            ? ` <span style="color:#5b6f86">(${escapeHtml(ev.cod_producto)})</span>` : '';
                        refLineas.push(
                            `<div><span style="color:#5b6f86">Referencia:</span> <strong>${escapeHtml(ref)}</strong>${sub}</div>`);
                    }
                    if (!refLineas.length) {
                        refLineas.push(
                            `<div style="color:#9aa6b8;font-style:italic;font-size:11.5px">Sin OF/Ref asociada al periodo</div>`);
                    }
                    return `<div style="background:#fff;border:1px solid #d5dfe8;
                                        border-radius:6px;padding:10px 12px;font-size:12.5px;
                                        color:#1a2d4a;box-shadow:0 2px 10px rgba(0,0,0,.14);
                                        max-width:380px">
                        <div style="font-weight:800;color:${color};
                                    border-bottom:1px solid #eef;
                                    padding-bottom:5px;margin-bottom:6px;font-size:13.5px">
                            #${dataPointIndex + 1} · ${escapeHtml(ev.motivo)}
                        </div>
                        <div><span style="color:#5b6f86">Inicio:</span> <strong>${fmtTS(tIni)}</strong></div>
                        <div><span style="color:#5b6f86">Fin:</span> <strong>${fmtTS(tFin)}</strong></div>
                        <div style="margin-bottom:4px">
                            <span style="color:#5b6f86">Duración:</span> <strong>${fmtDur(ev.segundos)}</strong>
                        </div>
                        ${refLineas.join('')}
                    </div>`;
                },
            },
            // Leyenda manual con los motivos coloreados (Apex no la genera
            // automáticamente cuando el color va por punto via fillColor).
            legend: { show: false },
            grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        };

        if (chartDet) { try { chartDet.destroy(); } catch(_){} }
        chartDet = new ApexCharts(document.getElementById('cambiosof-detalle-chart'), options);
        chartDet.render();

        // Leyenda HTML simple con motivos + color, debajo del chart
        const leyendaHtml = Array.from(motCol.entries()).map(([mot, col]) => `
            <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;color:#1a2d4a;margin:0 12px 4px 0">
                <span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:${col}"></span>
                ${escapeHtml(mot)}
            </span>`).join('');
        const leyEl = document.getElementById('cambiosof-detalle-leyenda')
            || (() => {
                const div = document.createElement('div');
                div.id = 'cambiosof-detalle-leyenda';
                div.style.cssText = 'margin-top:8px;padding:6px 10px;border-top:1px dashed #d5dfe8';
                document.getElementById('cambiosof-detalle').appendChild(div);
                return div;
            })();
        leyEl.innerHTML = leyendaHtml;
    }

    function cerrarDetalle() {
        const cont = document.getElementById('cambiosof-detalle');
        if (cont) cont.style.display = 'none';
        if (chartDet) { try { chartDet.destroy(); } catch(_){} chartDet = null; }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])
        );
    }
})();
</script>

</body>
</html>
