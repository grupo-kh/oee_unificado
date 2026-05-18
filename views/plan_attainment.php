<?php
$pageTitle = 'Plan Attainment';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Plan Attainment Global<button type="button" id="info-icon" class="info-icon" title="Cómo se ha leído esta información" aria-label="Cómo se ha leído esta información">i</button></h2>
        </div>
        <div class="view-card-body gauge-big-wrapper">
            <div id="gauge-big"></div>
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
        <div class="view-card-footer metric-legend">
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
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_gauge.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_gauge.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>
</body>
</html>
