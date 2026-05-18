<?php
$cod = isset($_GET['cod_maquina']) ? htmlspecialchars($_GET['cod_maquina']) : '';
$nom = isset($_GET['maquina'])     ? htmlspecialchars($_GET['maquina'])     : $cod;
$pageTitle = 'OEE FAB · Global + Sección' . ($cod ? ' · ' . $nom : '');
$backLink  = 'oee_fab.php';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>OEE de Fabricación <span id="header-scope" class="header-scope"></span></h2>
            <span class="view-card-info" id="oee-info">—</span>
        </div>
        <div class="view-card-body">

            <div class="machine-selector-row">
                <label for="machine-selector" class="machine-selector-label">Filtrar por máquina:</label>
                <select id="machine-selector" class="machine-selector">
                    <option value="">— Todas las máquinas (vista global) —</option>
                </select>
                <button id="machine-selector-clear" class="machine-selector-clear" type="button" style="display:none">× Quitar filtro</button>
            </div>

            <div class="drc-cards">
                <div class="drc-card" id="card-d">
                    <div class="drc-card-icon"><svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg"><path d="M14 22h-6v18h6v-18zm28 4c0-2.2-1.8-4-4-4h-11l1.65-8.1.05-.55c0-.8-.35-1.55-.85-2.1l-1.8-1.75-11.25 11.25c-.7.7-1.15 1.7-1.15 2.8v17c0 2.2 1.8 4 4 4h17c1.65 0 3.05-1 3.65-2.45l5.4-12.65c.2-.5.3-1.05.3-1.6v-3.85z" fill="currentColor"/></svg></div>
                    <div class="drc-card-body">
                        <span class="drc-card-label">Disponibilidad</span>
                        <span class="drc-card-value" id="drc-d">—</span>
                        <span class="drc-card-desc">Tiempo en marcha / tiempo programado</span>
                    </div>
                </div>
                <div class="drc-card" id="card-r">
                    <div class="drc-card-icon"><svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg"><path d="M14 22h-6v18h6v-18zm28 4c0-2.2-1.8-4-4-4h-11l1.65-8.1.05-.55c0-.8-.35-1.55-.85-2.1l-1.8-1.75-11.25 11.25c-.7.7-1.15 1.7-1.15 2.8v17c0 2.2 1.8 4 4 4h17c1.65 0 3.05-1 3.65-2.45l5.4-12.65c.2-.5.3-1.05.3-1.6v-3.85z" fill="currentColor"/></svg></div>
                    <div class="drc-card-body">
                        <span class="drc-card-label">Rendimiento</span>
                        <span class="drc-card-value" id="drc-r">—</span>
                        <span class="drc-card-desc">Velocidad real / velocidad nominal</span>
                    </div>
                </div>
                <div class="drc-card" id="card-c">
                    <div class="drc-card-icon"><svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg"><path d="M14 22h-6v18h6v-18zm28 4c0-2.2-1.8-4-4-4h-11l1.65-8.1.05-.55c0-.8-.35-1.55-.85-2.1l-1.8-1.75-11.25 11.25c-.7.7-1.15 1.7-1.15 2.8v17c0 2.2 1.8 4 4 4h17c1.65 0 3.05-1 3.65-2.45l5.4-12.65c.2-.5.3-1.05.3-1.6v-3.85z" fill="currentColor"/></svg></div>
                    <div class="drc-card-body">
                        <span class="drc-card-label">Calidad</span>
                        <span class="drc-card-value" id="drc-c">—</span>
                        <span class="drc-card-desc">Piezas OK / piezas producidas</span>
                    </div>
                </div>
            </div>

            <div class="oee-fab-global-grid">
                <div class="oee-fab-gauge">
                    <div class="oee-detalle-subtitle">OEE de Fabricación</div>
                    <div id="gauge-oee-global"></div>
                </div>
                <div class="oee-fab-secciones">
                    <div class="oee-detalle-subtitle">Por Sección</div>
                    <div id="chart-secciones"></div>
                </div>
            </div>

        </div>
        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p><strong>OEE de Fabricación</strong> = agregado D × R × C de todas las máquinas de Fabricación con tiempo programado en el día/turno seleccionado. <strong>Por Sección</strong> separa el mismo cálculo en VARILLAS y TROQUELADOS.</p>
                <p class="metric-legend-note">Si llegas a esta vista con una máquina filtrada (p. ej. desde el ranking Por Máquina), el gauge y las barras muestran los valores agregados únicamente para esa máquina. Usa la × del filtro para volver a la vista global.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<script src="../assets/js/common.js"></script>
<script src="../assets/js/view_oee_fab_global.js"></script>
</body>
</html>
