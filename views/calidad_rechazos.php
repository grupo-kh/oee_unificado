<?php
$cod = isset($_GET['cod_maquina'])  ? htmlspecialchars($_GET['cod_maquina'])  : '';
$art = isset($_GET['cod_articulo']) ? htmlspecialchars($_GET['cod_articulo']) : '';
$pageTitle = 'Calidad · Unidades rechazadas por Motivo';
$backLink  = 'calidad.php';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Unidades rechazadas por Motivo <span id="header-scope" class="header-scope"></span></h2>
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
                    <span class="paros-resumen-label">Total unidades rechazadas</span>
                    <span class="paros-resumen-value" id="cal-total-u">—</span>
                </div>
                <div class="paros-resumen-card">
                    <span class="paros-resumen-label">Motivos distintos</span>
                    <span class="paros-resumen-value" id="cal-total-motivos">—</span>
                </div>
                <div class="paros-resumen-card">
                    <span class="paros-resumen-label">Top motivo</span>
                    <span class="paros-resumen-value" id="cal-top-motivo" style="font-size:18px">—</span>
                </div>
            </div>

            <div class="oee-detalle-evolucion">
                <div class="oee-detalle-subtitle">Pareto · unidades rechazadas por motivo (top 12) <span id="cal-summary"></span> <span id="cal-hint" class="paros-hint">· clic sobre una barra o fila para ver el detalle por máquina</span></div>
                <div id="chart-cal-pareto"></div>
            </div>

            <div id="cal-detalle-panel" class="paros-detalle-panel" style="display:none">
                <div class="paros-detalle-header">
                    <div>
                        <span class="paros-detalle-label">Detalle por máquina</span>
                        <h3 id="cal-detalle-titulo">—</h3>
                        <span class="paros-detalle-meta" id="cal-detalle-meta">—</span>
                    </div>
                    <button id="cal-detalle-cerrar" type="button" class="paros-detalle-cerrar" title="Cerrar detalle">×</button>
                </div>
                <div id="chart-cal-detalle"></div>
                <div class="paros-tabla-wrap" style="margin-top:14px;max-height:260px">
                    <table class="paros-tabla" id="cal-detalle-tabla">
                        <thead>
                            <tr>
                                <th style="width:36px">#</th>
                                <th>Máquina</th>
                                <th>Sección</th>
                                <th style="width:90px;text-align:right">Unidades</th>
                                <th style="width:80px;text-align:right">Reg.</th>
                                <th style="width:80px;text-align:right">%</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <div class="oee-detalle-horario" style="margin-top:18px">
                <div class="oee-detalle-subtitle">Tabla detallada (todos los motivos)</div>
                <div class="paros-tabla-wrap">
                    <table class="paros-tabla" id="cal-tabla">
                        <thead>
                            <tr>
                                <th style="width:36px">#</th>
                                <th>Cód. defecto</th>
                                <th>Motivo</th>
                                <th style="width:90px;text-align:right">Unidades</th>
                                <th style="width:80px;text-align:right">Reg.</th>
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
                <p><strong>Pareto de unidades rechazadas por motivo:</strong> cada barra representa las unidades NOK registradas para el día/turno con ese motivo de rechazo. La línea amarilla muestra el % acumulado (regla 80/20: pocos motivos a la izquierda explican la mayor parte del rechazo).</p>
                <p class="metric-legend-note">Clic sobre una barra o fila → desglose <strong>por máquina</strong> para ese motivo, útil para localizar qué equipo concentra el problema.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<script src="../assets/js/common.js"></script>
<script src="../assets/js/view_calidad_rechazos.js"></script>
</body>
</html>
