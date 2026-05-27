<?php
$pageTitle = 'Plan Attainment';
$hideFiltros = true;   // usamos cabecera propia (rango + multi-turno) en esta vista
include __DIR__ . '/../includes/header.php';
?>

<!-- ════════════════════════════════════════════════════════════════
     CABECERA PROPIA (sticky): rango fechas + multi-turno + drill-downs
     ════════════════════════════════════════════════════════════════ -->
<div class="pa-filterbar" id="pa-filterbar">
    <div class="pa-filterbar-row">
        <div class="pa-filterbar-group">
            <label for="pa-f-desde">Desde</label>
            <input type="date" id="pa-f-desde" class="filter-field filter-green">
            <label for="pa-f-hasta">Hasta</label>
            <input type="date" id="pa-f-hasta" class="filter-field filter-green">
        </div>
        <div class="pa-filterbar-group">
            <span class="pa-filterbar-label">Atajos</span>
            <button type="button" class="pa-preset-btn" data-preset="ayer" title="Día anterior al actual">Ayer</button>
            <button type="button" class="pa-preset-btn" data-preset="semana" title="Lunes a domingo de la semana anterior">Semana ant.</button>
            <button type="button" class="pa-preset-btn" data-preset="mes" title="Mes natural completo anterior">Mes ant.</button>
        </div>
        <div class="pa-filterbar-group">
            <span class="pa-filterbar-label">Turnos</span>
            <button type="button" class="pa-turno-btn" data-turno="M" title="Mañana (06:00–14:15)">M</button>
            <button type="button" class="pa-turno-btn" data-turno="T" title="Tarde (14:15–22:30)">T</button>
            <button type="button" class="pa-turno-btn" data-turno="N" title="Noche (22:30–06:00)">N</button>
            <button type="button" class="pa-turno-btn" data-turno="C" title="Central (08:00–17:00)">C</button>
        </div>
        <div class="pa-filterbar-group pa-filterbar-chips-group">
            <span class="pa-active-filter-chips" id="pa-active-filter-chips"></span>
            <span class="pa-filterbar-loading" id="pa-filterbar-loading" style="display:none">
                <span class="pa-mini-spinner"></span> Cargando…
            </span>
            <button type="button" id="pa-clear-filter" class="pa-clear-filter-btn" style="display:none">Limpiar drill-downs</button>
        </div>
    </div>
</div>

<main class="view-main pa-stack">

    <!-- ════════════════════════════════════════════════════════════════
         MÓDULO 1 · GAUGE GLOBAL (+ leyenda a la derecha)
         ════════════════════════════════════════════════════════════════ -->
    <div class="view-card pa-module pa-module-1">
        <div class="view-card-header">
            <h2 id="m1-title">Plan Attainment Global<button type="button" id="info-icon" class="info-icon" title="Cómo se ha leído esta información" aria-label="Cómo se ha leído esta información">i</button></h2>
        </div>
        <div class="view-card-body pa-body">
            <!-- Fila superior: gauge a la izquierda, leyenda explicativa a la derecha -->
            <div class="pa-gauge-row">
                <div class="pa-gauge-col">
                    <div id="gauge-big"></div>
                </div>
                <aside class="pa-legend-col">
                    <div class="metric-legend-title">¿Cómo se calcula el Cumplimiento Global?</div>
                    <div class="metric-legend-formula">
                        <span class="formula-num">Σ min(producido, planificado)</span>
                        <span class="formula-bar">÷</span>
                        <span class="formula-den">Σ planificado</span>
                        <span class="formula-note">por cada artículo del turno</span>
                    </div>
                    <div class="metric-legend-text">
                        <p><strong>Criterio estricto por artículo:</strong> si una máquina tenía que fabricar 1 000 unidades de una referencia y produjo 1 200, solo se contabilizan las <strong>1 000 planificadas</strong>. La sobreproducción de una referencia no compensa el déficit de otra.</p>
                        <p>El objetivo de este indicador es responder: <em>¿se fabricó lo que se pidió, cuando se pidió y en la máquina prevista?</em>  Un 100 % indica que todas las referencias del plan se cumplieron o superaron; un 0 % indica que no se fabricó nada de lo planificado.</p>
                        <p class="metric-legend-note"><strong>Nota:</strong> este valor puede diferir ligeramente del que aparezca en otros cuadros de mando. Los motivos más habituales son (a) artículos "hermanos" con códigos muy parecidos que algunos sistemas agrupan y este panel trata por separado, (b) lecturas en distintos momentos mientras el turno sigue vivo, y (c) inclusión o exclusión de máquinas de soporte según el criterio de cada panel. Este panel aplica el criterio más conservador: solo cuenta lo que se fabricó del artículo exacto planificado, hasta el límite de su plan.</p>
                    </div>
                </aside>
            </div>

            <!-- Fila inferior: 4 métricas (Disponibilidad / Rendimiento / Calidad / OEE) -->
            <div class="gauge-metrics-big">
                <div class="metric-big">
                    <span class="metric-label-big">Disponibilidad</span>
                    <span class="metric-value-big" id="m-disp">—</span>
                    <span class="metric-desc-big">Tiempo en marcha sobre tiempo programado.<br><em>Penalizan solo los paros no programados (averías, falta de material).</em></span>
                </div>
                <div class="metric-big">
                    <span class="metric-label-big">Rendimiento</span>
                    <span class="metric-value-big" id="m-rend">—</span>
                    <span class="metric-desc-big">Velocidad real vs velocidad nominal.<br><em>Mide microparos y producción a ritmo más lento del estándar.</em></span>
                </div>
                <div class="metric-big">
                    <span class="metric-label-big">Calidad</span>
                    <span class="metric-value-big" id="m-cal">—</span>
                    <span class="metric-desc-big">Piezas OK sobre total producido.<br><em>Mide la proporción que no tuvo defectos ni rechazo.</em></span>
                </div>
                <div class="metric-big highlight">
                    <span class="metric-label-big">OEE</span>
                    <span class="metric-value-big" id="m-oee">—</span>
                    <span class="metric-desc-big">Disponibilidad × Rendimiento × Calidad.<br><em>Indicador global de eficiencia productiva del equipo.</em></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         MÓDULO 2 · POR SECCIÓN (clic en una barra filtra todo el panel)
         ════════════════════════════════════════════════════════════════ -->
    <div class="view-card pa-module pa-module-2">
        <div class="view-card-header">
            <h2>Cumplimiento por Sección
                <span class="pa-hint">· clic en una barra para filtrar</span>
            </h2>
            <span class="view-card-info" id="m2-info">VARILLAS + TROQUELADOS</span>
        </div>
        <div class="view-card-body pa-module-body">
            <div id="chart-seccion-big"></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         MÓDULO 3 · EVOLUCIÓN (clic en un punto filtra por ese día)
         ════════════════════════════════════════════════════════════════ -->
    <div class="view-card pa-module pa-module-3">
        <div class="view-card-header">
            <h2>Evolución del Plan Attainment
                <span class="pa-hint">· clic en un punto para fijar la fecha</span>
            </h2>
            <span class="view-card-info">Últimos 7 días</span>
        </div>
        <div class="view-card-body pa-module-body">
            <div id="chart-evolucion-big"></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         MÓDULO 4 · RANKING POR MÁQUINA (clic en una barra abre detalle)
         ════════════════════════════════════════════════════════════════ -->
    <div class="view-card pa-module pa-module-4">
        <div class="view-card-header">
            <h2>Ranking por Máquina
                <span class="pa-hint">· clic en una barra para ver detalle</span>
            </h2>
            <span class="view-card-info" id="m4-info">VARILLAS + TROQUELADOS</span>
        </div>
        <div class="view-card-body pa-module-body">
            <div id="chart-maquina-big"></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         MÓDULO 5 · DETALLE PLAN vs PRODUCIDO POR ARTÍCULO
         Siempre visible. Sin filtro → agregado global por artículo.
         Con sección → solo artículos de esa sección.
         Con máquina → solo artículos de esa máquina.
         ════════════════════════════════════════════════════════════════ -->
    <div class="view-card pa-module pa-module-5" id="pa-module-5">
        <div class="view-card-header">
            <h2>Detalle Plan vs Producido
                <span class="pa-hint" id="m5-subtitle">· por artículo</span>
            </h2>
            <span class="view-card-info" id="m5-info">—</span>
        </div>
        <div class="view-card-body pa-module-body">
            <div id="detalle-articulos"></div>
        </div>
    </div>

</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_plan_attainment_full.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_plan_attainment_full.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>
</body>
</html>
