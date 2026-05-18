<?php
$pageTitle = 'Por Sección';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Cumplimiento por Sección<button type="button" id="info-icon" class="info-icon" title="Cómo se ha leído esta información" aria-label="Cómo se ha leído esta información">i</button></h2>
        </div>
        <div class="view-card-body">
            <div id="chart-seccion-big"></div>
        </div>
        <div class="view-card-footer metric-legend">
            <div class="metric-legend-title">¿Cómo se calcula el Cumplimiento?</div>
            <div class="metric-legend-formula">
                <span class="formula-num">Σ min(producido, planificado)</span>
                <span class="formula-bar">÷</span>
                <span class="formula-den">Σ planificado</span>
                <span class="formula-note">por cada artículo</span>
            </div>
            <div class="metric-legend-text">
                <p><strong>Criterio estricto por artículo:</strong> si el plan pedía 1 000 unidades y se fabricaron 1 200, se cuentan solo las <strong>1 000 planificadas</strong>. La sobreproducción de una referencia <strong>no compensa</strong> el déficit de otra.</p>
                <p>Esto refleja el cumplimiento real del plan: <em>¿se fabricó lo que se pidió, cuando se pidió y en la máquina planificada?</em>  Un 100 % significa que cada artículo se cumplió (igualado o superado), un 0 % significa que no se fabricó nada de lo planificado.</p>
                <p class="metric-legend-note">Solo se consideran las máquinas del plan oficial (VARILLAS y TROQUELADOS). La producción de artículos no planificados o en máquinas fuera del plan no suma al numerador ni al denominador.</p>
                <p class="metric-legend-note"><strong>Nota sobre diferencias con otros paneles:</strong> este valor puede diferir ligeramente del que aparezca en otros cuadros de mando de la misma fecha/turno. Los motivos más habituales son (a) artículos "hermanos" con códigos muy parecidos (p.ej. <code>151011470</code> vs <code>151011480</code>) que algunos sistemas agrupan y este panel trata por separado, (b) lecturas en distintos momentos mientras el turno aún está vivo, y (c) inclusión o exclusión de máquinas de soporte (TBE30, TBE35, TBE RAPIDFORM, prensas 3D, etc.) según el criterio de cada panel. Este panel aplica el criterio más conservador: solo cuenta lo que se fabricó del artículo exacto planificado, hasta el límite de su plan.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_seccion.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_seccion.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>
</body>
</html>
