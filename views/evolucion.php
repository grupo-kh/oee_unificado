<?php
$pageTitle = 'Evolución';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Evolución del Plan Attainment<button type="button" id="info-icon" class="info-icon" title="Cómo se ha leído esta información" aria-label="Cómo se ha leído esta información">i</button></h2>
            <span class="view-card-info">Últimos 7 días</span>
        </div>
        <div class="view-card-body">
            <div id="chart-evolucion-big"></div>
        </div>
        <div class="view-card-footer metric-legend">
            <div class="metric-legend-title">¿Cómo se calcula cada punto?</div>
            <div class="metric-legend-formula">
                <span class="formula-num">Σ min(producido, planificado)</span>
                <span class="formula-bar">÷</span>
                <span class="formula-den">Σ planificado</span>
                <span class="formula-note">por cada artículo del turno, cada día</span>
            </div>
            <div class="metric-legend-text">
                <p>Cada punto de la línea representa el <strong>Cumplimiento Global</strong> de ese día y turno. Se aplica el mismo criterio estricto que en el gauge principal: la sobreproducción de una referencia <strong>no compensa</strong> el déficit de otra.</p>
                <p>Los <strong>días sin plan</strong> (sábados/domingos sin parte oficial, festivos) se omiten del eje temporal. Si un día aparece con un valor muy bajo pese a fabricarse mucho, suele significar que se produjo un artículo distinto al planificado o en una máquina fuera del plan oficial.</p>
                <p class="metric-legend-note"><strong>Nota:</strong> estos valores pueden diferir ligeramente de los que aparezcan en otros cuadros de mando para los mismos días. Los motivos más habituales son (a) artículos "hermanos" con códigos muy parecidos que algunos sistemas agrupan y este panel trata por separado, (b) días con plan muy reducido (p.ej. domingos con un solo turno activo) donde una pequeña desviación mueve el porcentaje mucho, y (c) inclusión o exclusión de máquinas de soporte según el criterio de cada panel. Este panel aplica el criterio más conservador: solo cuenta lo que se fabricó del artículo exacto planificado, hasta el límite de su plan.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_evolucion.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_evolucion.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>
</body>
</html>
