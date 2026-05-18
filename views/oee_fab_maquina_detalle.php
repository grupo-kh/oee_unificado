<?php
$cod = isset($_GET['cod_maquina']) ? htmlspecialchars($_GET['cod_maquina']) : '';
$nom = isset($_GET['maquina']) ? htmlspecialchars($_GET['maquina']) : $cod;
$pageTitle = 'OEE · ' . ($nom ?: 'Máquina');
$backLink  = 'oee_fab_maquina.php';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>OEE de <span id="maq-name"><?= $nom ?></span>
                <small id="maq-cod" style="opacity:.7;font-weight:400;margin-left:8px">(<?= $cod ?>)</small>
            </h2>
            <div class="view-card-header-actions">
                <a id="btn-ver-global" href="#" class="btn-header-action" title="Ver gauge + sección filtrado por esta máquina">
                    Ver Gauge + Sección →
                </a>
                <span class="view-card-info" id="oee-info">—</span>
            </div>
        </div>
        <div class="view-card-body">

            <div class="oee-detalle-grid">
                <div class="oee-detalle-gauge">
                    <div id="gauge-oee-maq"></div>
                </div>
                <div class="oee-detalle-metrics">
                    <div class="metric-big">
                        <span class="metric-label-big">Disponibilidad</span>
                        <span class="metric-value-big" id="m-d">—</span>
                        <span class="metric-desc-big">Tiempo en marcha sobre tiempo programado.</span>
                    </div>
                    <div class="metric-big">
                        <span class="metric-label-big">Rendimiento</span>
                        <span class="metric-value-big" id="m-r">—</span>
                        <span class="metric-desc-big">Velocidad real vs velocidad nominal.</span>
                    </div>
                    <div class="metric-big">
                        <span class="metric-label-big">Calidad</span>
                        <span class="metric-value-big" id="m-c">—</span>
                        <span class="metric-desc-big">Piezas OK sobre total producido.</span>
                    </div>
                    <div class="metric-big highlight">
                        <span class="metric-label-big">OEE</span>
                        <span class="metric-value-big" id="m-oee">—</span>
                        <span class="metric-desc-big">Disponibilidad × Rendimiento × Calidad.</span>
                    </div>
                </div>
            </div>

            <div class="oee-detalle-evolucion">
                <div class="oee-detalle-subtitle">Evolución últimos 7 días</div>
                <div id="chart-oee-maq-evo"></div>
            </div>

            <div class="oee-detalle-horario">
                <div class="oee-detalle-subtitle">Producción por hora <span id="horario-total"></span></div>
                <div id="chart-oee-maq-horario"></div>
            </div>

        </div>
        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p><strong>Detalle por máquina:</strong> gauge con el OEE del día/turno seleccionado, desglose D / R / C, y línea amarilla con la evolución diaria de los últimos 7 días para la misma máquina. Los días sin tiempo programado para esta máquina se omiten.</p>
                <p class="metric-legend-note">Volver al ranking: botón 🏠 en la esquina superior derecha.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<script src="../assets/js/common.js"></script>
<script src="../assets/js/view_oee_fab_maquina_detalle.js"></script>
</body>
</html>
