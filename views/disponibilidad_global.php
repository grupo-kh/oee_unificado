<?php
$cod = isset($_GET['cod_maquina'])  ? htmlspecialchars($_GET['cod_maquina'])  : '';
$art = isset($_GET['cod_articulo']) ? htmlspecialchars($_GET['cod_articulo']) : '';
$pageTitle = 'Disponibilidad · Global + Sección';
$backLink  = 'disponibilidad.php';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Disponibilidad <span id="header-scope" class="header-scope"></span></h2>
            <span class="view-card-info" id="info-line">—</span>
        </div>
        <div class="view-card-body">

            <div class="dual-selector-row">
                <div class="machine-selector-row" style="flex:1">
                    <label for="machine-selector" class="machine-selector-label">Máquina:</label>
                    <select id="machine-selector" class="machine-selector">
                        <option value="">— Todas —</option>
                    </select>
                </div>
                <div class="machine-selector-row" style="flex:1">
                    <label for="article-selector" class="machine-selector-label">Artículo:</label>
                    <select id="article-selector" class="machine-selector">
                        <option value="">— Todos —</option>
                    </select>
                </div>
                <button id="filter-clear" class="machine-selector-clear" type="button" style="display:none">× Quitar filtros</button>
            </div>

            <div class="oee-fab-global-grid">
                <div class="oee-fab-gauge">
                    <div class="oee-detalle-subtitle">Disponibilidad</div>
                    <div id="gauge-disp"></div>
                </div>
                <div class="oee-fab-secciones">
                    <div class="oee-detalle-subtitle">Por Sección</div>
                    <div id="chart-secciones"></div>
                    <div class="seccion-hint">Haz clic sobre una sección para ver el desglose por artículo y máquina.</div>
                </div>
            </div>

            <div id="drill-down-block" class="drill-down-block" style="display:none">
                <div class="drill-down-header">
                    <span class="drill-down-title">Desglose · <span id="drill-down-seccion-label">—</span></span>
                    <button id="drill-down-close" class="drill-down-close" type="button">× Cerrar desglose</button>
                </div>
                <div class="drill-down-grid">
                    <div class="drill-down-col">
                        <div class="oee-detalle-subtitle">Artículos</div>
                        <div id="chart-articulos-seccion"></div>
                        <div id="chart-articulos-empty" class="drill-down-empty" style="display:none">Sin datos para esta sección</div>
                    </div>
                    <div class="drill-down-col">
                        <div class="oee-detalle-subtitle">Máquinas</div>
                        <div id="chart-maquinas-seccion"></div>
                        <div id="chart-maquinas-empty" class="drill-down-empty" style="display:none">Sin datos para esta sección</div>
                    </div>
                </div>
            </div>

        </div>
        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p><strong>Disponibilidad</strong> = Tiempo en marcha / (Tiempo en marcha + Paros no programados). Penalizan averías, falta de material y otros paros no programados (PNP). El gauge muestra el agregado del día/turno seleccionado y las barras separan VARILLAS y TROQUELADOS.</p>
                <p class="metric-legend-note"><strong>Filtros:</strong> selecciona <em>una máquina</em>, <em>un artículo</em>, o <em>ambos</em> a la vez. Cuando filtras por artículo, los selectores solo muestran las máquinas que produjeron ese artículo en el día/turno; cuando filtras por máquina, los artículos listados son los que esa máquina trabajó. Los datos se recalculan al instante.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<script src="../assets/js/common.js"></script>
<script src="../assets/js/view_disponibilidad_global.js"></script>
</body>
</html>
