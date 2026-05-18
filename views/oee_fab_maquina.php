<?php
$pageTitle = 'OEE FAB · Por Máquina';
$backLink  = 'oee_fab.php';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>OEE FAB por Máquina</h2>
            <span class="view-card-info" id="oee-count">—</span>
        </div>
        <div class="view-card-body">
            <div id="chart-oee-fab-maq"></div>
        </div>
        <div class="view-card-footer metric-legend">
            <div class="metric-legend-title">¿Cómo se calcula el OEE de cada máquina?</div>
            <div class="metric-legend-formula">
                <span class="formula-num">Disponibilidad × Rendimiento × Calidad</span>
                <span class="formula-note">por cada máquina del taller</span>
            </div>
            <div class="metric-legend-text">
                <p><strong>Disponibilidad</strong> = tiempo en marcha / tiempo programado · <strong>Rendimiento</strong> = velocidad real / velocidad nominal · <strong>Calidad</strong> = piezas OK / piezas totales producidas.</p>
                <p>Cada barra muestra el OEE global de la máquina en el día/turno seleccionado. Pasa el ratón por encima para ver el desglose D / R / C individual.</p>
                <p class="metric-legend-note">Solo aparecen máquinas con tiempo programado (M + PNP &gt; 0) en la ventana seleccionada. El orden es ascendente: las barras más bajas a la izquierda, las más altas a la derecha, igual que en el panel Night Letter de QV.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<script src="../assets/js/common.js"></script>
<script src="../assets/js/view_oee_fab_maquina.js"></script>
</body>
</html>
