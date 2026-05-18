<?php
$cod = isset($_GET['cod_maquina'])  ? htmlspecialchars($_GET['cod_maquina'])  : '';
$art = isset($_GET['cod_articulo']) ? htmlspecialchars($_GET['cod_articulo']) : '';
$pageTitle = 'Calidad · Global + Sección';
$backLink  = 'calidad.php';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Calidad <span id="header-scope" class="header-scope"></span></h2>
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
                    <div class="oee-detalle-subtitle">Calidad</div>
                    <div id="gauge-cal"></div>
                </div>
                <div class="oee-fab-secciones">
                    <div class="oee-detalle-subtitle">Por Sección</div>
                    <div id="chart-secciones"></div>
                </div>
            </div>

        </div>
        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p><strong>Calidad</strong> = M_OK_TEO / (M_OKNOK_TEO + PCALIDAD). Porción del tiempo teórico productivo que se traduce en piezas OK respecto al total fabricado (OK + NOK + tiempo perdido por calidad). 100 % significa que toda la producción del día/turno fue conforme.</p>
                <p class="metric-legend-note"><strong>Filtros:</strong> selecciona una máquina, un artículo o ambos. Al filtrar por artículo, los selectores solo muestran las máquinas que lo trabajaron en la ventana; al filtrar por máquina, los artículos listados son los producidos por esa máquina.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<script src="../assets/js/common.js"></script>
<script src="../assets/js/view_calidad_global.js"></script>
</body>
</html>
