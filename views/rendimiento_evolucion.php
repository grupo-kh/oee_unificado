<?php
$cod = isset($_GET['cod_maquina'])  ? htmlspecialchars($_GET['cod_maquina'])  : '';
$art = isset($_GET['cod_articulo']) ? htmlspecialchars($_GET['cod_articulo']) : '';
$pageTitle = 'Rendimiento · Evolución';
$backLink  = 'rendimiento.php';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Evolución · Rendimiento <span id="header-scope" class="header-scope"></span></h2>
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

            <div class="oee-detalle-evolucion">
                <div class="oee-detalle-subtitle">Rendimiento diario <span id="evo-summary"></span></div>
                <div id="chart-rend-evo"></div>
            </div>

        </div>
        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p><strong>Evolución del Rendimiento</strong> = serie diaria de los últimos 7 días para el día/turno seleccionado. La línea verde discontinua marca el objetivo del 75 %. Al filtrar por máquina y/o artículo, la serie se recalcula solo con esos datos.</p>
                <p class="metric-legend-note">Valores &gt;100 % son posibles cuando la máquina trabaja por encima del estándar nominal. Los días sin tiempo programado se omiten del eje temporal.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<script src="../assets/js/common.js"></script>
<script src="../assets/js/view_rendimiento_evolucion.js"></script>
</body>
</html>
