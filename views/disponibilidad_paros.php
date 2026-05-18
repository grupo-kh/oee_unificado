<?php
$cod = isset($_GET['cod_maquina'])  ? htmlspecialchars($_GET['cod_maquina'])  : '';
$art = isset($_GET['cod_articulo']) ? htmlspecialchars($_GET['cod_articulo']) : '';
$pageTitle = 'Disponibilidad · Horas de paro por motivo';
$backLink  = 'disponibilidad.php';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Horas de paro por motivo <span id="header-scope" class="header-scope"></span></h2>
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
                    <span class="paros-resumen-label">Total horas de paro</span>
                    <span class="paros-resumen-value" id="paros-total-h">—</span>
                </div>
                <div class="paros-resumen-card">
                    <span class="paros-resumen-label">Motivos distintos</span>
                    <span class="paros-resumen-value" id="paros-total-motivos">—</span>
                </div>
                <div class="paros-resumen-card">
                    <span class="paros-resumen-label">Top motivo</span>
                    <span class="paros-resumen-value" id="paros-top-motivo" style="font-size:18px">—</span>
                </div>
            </div>

            <div class="oee-detalle-evolucion">
                <div class="oee-detalle-subtitle">Pareto · horas por motivo (top 12) <span id="paros-summary"></span> <span id="paros-hint" class="paros-hint">· clic sobre una barra o fila para ver el detalle por máquina</span></div>
                <div id="chart-paros-pareto"></div>
            </div>

            <div id="paros-detalle-panel" class="paros-detalle-panel" style="display:none">
                <div class="paros-detalle-header">
                    <div>
                        <span class="paros-detalle-label">Detalle del motivo</span>
                        <h3 id="paros-detalle-titulo">—</h3>
                        <span class="paros-detalle-meta" id="paros-detalle-meta">—</span>
                    </div>
                    <button id="paros-detalle-cerrar" type="button" class="paros-detalle-cerrar" title="Cerrar detalle">×</button>
                </div>
                <div id="chart-paros-detalle"></div>
                <div class="paros-tabla-wrap" style="margin-top:14px;max-height:260px">
                    <table class="paros-tabla" id="paros-detalle-tabla">
                        <thead>
                            <tr>
                                <th style="width:36px">#</th>
                                <th>Máquina</th>
                                <th>Sección</th>
                                <th style="width:90px;text-align:right">Horas</th>
                                <th style="width:80px;text-align:right">Nº paros</th>
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
                    <table class="paros-tabla" id="paros-tabla">
                        <thead>
                            <tr>
                                <th style="width:36px">#</th>
                                <th>Motivo</th>
                                <th style="width:80px">Cod.</th>
                                <th style="width:90px;text-align:right">Horas</th>
                                <th style="width:80px;text-align:right">Nº paros</th>
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
                <p><strong>Pareto de horas de paro:</strong> cada barra azul muestra las horas acumuladas de un motivo de paro durante el día/turno. La línea amarilla representa el porcentaje acumulado (regla 80/20: los pocos motivos a la izquierda explican la mayoría del tiempo perdido).</p>
                <p class="metric-legend-note"><strong>Filtros:</strong> selecciona <em>una máquina</em>, <em>un artículo</em> o <em>ambos</em> para acotar los paros. Sin filtro se muestra el agregado de todas las máquinas. Se incluyen <strong>todos los tipos</strong> de paro (no programados, programados, microparos…) — útil para ver el peso real del tiempo no productivo.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<script src="../assets/js/common.js"></script>
<script src="../assets/js/view_disponibilidad_paros.js"></script>
</body>
</html>
