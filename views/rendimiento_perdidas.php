<?php
$cod = isset($_GET['cod_maquina'])  ? htmlspecialchars($_GET['cod_maquina'])  : '';
$art = isset($_GET['cod_articulo']) ? htmlspecialchars($_GET['cod_articulo']) : '';
$pageTitle = 'Rendimiento · Horas perdidas por máquina';
$backLink  = 'rendimiento.php';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Horas perdidas por falta de Rendimiento <span id="header-scope" class="header-scope"></span></h2>
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

            <div class="paros-resumen">
                <div class="paros-resumen-card">
                    <span class="paros-resumen-label">Total horas perdidas</span>
                    <span class="paros-resumen-value" id="rend-total-h">—</span>
                </div>
                <div class="paros-resumen-card">
                    <span class="paros-resumen-label">Máquinas con pérdida</span>
                    <span class="paros-resumen-value" id="rend-total-maquinas">—</span>
                </div>
                <div class="paros-resumen-card">
                    <span class="paros-resumen-label">Top máquina</span>
                    <span class="paros-resumen-value" id="rend-top-maquina" style="font-size:18px">—</span>
                </div>
            </div>

            <div class="oee-detalle-evolucion">
                <div class="oee-detalle-subtitle">Pareto · horas perdidas por máquina (top 12) <span id="rend-summary"></span> <span id="rend-hint" class="paros-hint">· clic sobre una barra o fila para ver el detalle por artículo</span></div>
                <div id="chart-rend-pareto"></div>
            </div>

            <div id="rend-detalle-panel" class="paros-detalle-panel" style="display:none">
                <div class="paros-detalle-header">
                    <div>
                        <span class="paros-detalle-label">Detalle por artículo</span>
                        <h3 id="rend-detalle-titulo">—</h3>
                        <span class="paros-detalle-meta" id="rend-detalle-meta">—</span>
                    </div>
                    <button id="rend-detalle-cerrar" type="button" class="paros-detalle-cerrar" title="Cerrar detalle">×</button>
                </div>
                <div id="chart-rend-detalle"></div>
                <div class="paros-tabla-wrap" style="margin-top:14px;max-height:260px">
                    <table class="paros-tabla" id="rend-detalle-tabla">
                        <thead>
                            <tr>
                                <th style="width:36px">#</th>
                                <th>Artículo</th>
                                <th>Descripción</th>
                                <th style="width:90px;text-align:right">Horas</th>
                                <th style="width:80px;text-align:right">Rend.</th>
                                <th style="width:80px;text-align:right">%</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <div class="oee-detalle-horario" style="margin-top:18px">
                <div class="oee-detalle-subtitle">Tabla detallada (todas las máquinas)</div>
                <div class="paros-tabla-wrap">
                    <table class="paros-tabla" id="rend-tabla">
                        <thead>
                            <tr>
                                <th style="width:36px">#</th>
                                <th>Máquina</th>
                                <th>Sección</th>
                                <th style="width:90px;text-align:right">Horas perd.</th>
                                <th style="width:80px;text-align:right">Rend.</th>
                                <th style="width:80px;text-align:right">%</th>
                                <th style="width:80px;text-align:right">% acum.</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

        </div>
        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p><strong>Pareto de horas perdidas por Rendimiento (PPERF):</strong> cada barra representa los minutos en que la máquina trabajó por debajo de la velocidad nominal durante el día/turno. La línea amarilla muestra el porcentaje acumulado (regla 80/20: las pocas máquinas a la izquierda explican la mayoría del tiempo perdido).</p>
                <p class="metric-legend-note">Clic sobre una barra o fila → desglose de pérdidas <strong>por artículo</strong> en esa máquina, útil para identificar qué referencias arrastran el rendimiento abajo.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<script src="../assets/js/common.js"></script>
<script src="../assets/js/view_rendimiento_perdidas.js"></script>
</body>
</html>
