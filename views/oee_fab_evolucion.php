<?php
$cod = isset($_GET['cod_maquina']) ? htmlspecialchars($_GET['cod_maquina']) : '';
$nom = isset($_GET['maquina'])     ? htmlspecialchars($_GET['maquina'])     : $cod;
$pageTitle = 'OEE FAB · Evolución' . ($cod ? ' · ' . $nom : '');
$backLink  = 'oee_fab.php';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Evolución OEE de Fabricación <span id="header-scope" class="header-scope"></span></h2>
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
                        <span class="drc-card-label">Disponibilidad <small id="drc-d-date" style="font-weight:400;color:#8fa5bf"></small></span>
                        <span class="drc-card-value" id="drc-d">—</span>
                        <span class="drc-card-desc">Tiempo en marcha / tiempo programado</span>
                    </div>
                </div>
                <div class="drc-card" id="card-r">
                    <div class="drc-card-icon"><svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg"><path d="M14 22h-6v18h6v-18zm28 4c0-2.2-1.8-4-4-4h-11l1.65-8.1.05-.55c0-.8-.35-1.55-.85-2.1l-1.8-1.75-11.25 11.25c-.7.7-1.15 1.7-1.15 2.8v17c0 2.2 1.8 4 4 4h17c1.65 0 3.05-1 3.65-2.45l5.4-12.65c.2-.5.3-1.05.3-1.6v-3.85z" fill="currentColor"/></svg></div>
                    <div class="drc-card-body">
                        <span class="drc-card-label">Rendimiento <small id="drc-r-date" style="font-weight:400;color:#8fa5bf"></small></span>
                        <span class="drc-card-value" id="drc-r">—</span>
                        <span class="drc-card-desc">Velocidad real / velocidad nominal</span>
                    </div>
                </div>
                <div class="drc-card" id="card-c">
                    <div class="drc-card-icon"><svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg"><path d="M14 22h-6v18h6v-18zm28 4c0-2.2-1.8-4-4-4h-11l1.65-8.1.05-.55c0-.8-.35-1.55-.85-2.1l-1.8-1.75-11.25 11.25c-.7.7-1.15 1.7-1.15 2.8v17c0 2.2 1.8 4 4 4h17c1.65 0 3.05-1 3.65-2.45l5.4-12.65c.2-.5.3-1.05.3-1.6v-3.85z" fill="currentColor"/></svg></div>
                    <div class="drc-card-body">
                        <span class="drc-card-label">Calidad <small id="drc-c-date" style="font-weight:400;color:#8fa5bf"></small></span>
                        <span class="drc-card-value" id="drc-c">—</span>
                        <span class="drc-card-desc">Piezas OK / piezas producidas</span>
                    </div>
                </div>
            </div>

            <div class="oee-evo-grid">
                <div class="oee-fab-gauge">
                    <div class="oee-detalle-subtitle">Evolución · OEE Global</div>
                    <div id="chart-evo-oee"></div>
                </div>
                <div class="oee-fab-secciones">
                    <div class="oee-detalle-subtitle">Evolución Desglosada · D / R / C</div>
                    <div id="chart-evo-drc"></div>
                </div>
            </div>

        </div>
        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p><strong>Evolución · OEE Global</strong> muestra el OEE (D × R × C) del día/turno seleccionado para los últimos 7 días, con una línea amarilla de referencia. <strong>Evolución Desglosada</strong> separa los tres componentes (Disponibilidad morado · Rendimiento rojo · Calidad verde) para que veas qué factor mueve el OEE.</p>
                <p class="metric-legend-note">Los días sin tiempo programado (domingos o festivos sin turno) se omiten del eje temporal. Al filtrar por una máquina concreta se muestran sus valores diarios para la misma ventana; si algún día no tuvo actividad para esa máquina, ese día también se omite.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<script src="../assets/js/common.js"></script>
<script src="../assets/js/view_oee_fab_evolucion.js"></script>
</body>
</html>
