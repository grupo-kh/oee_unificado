<?php
$pageTitle = 'Por Máquina';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Cumplimiento por Máquina<button type="button" id="info-icon" class="info-icon" title="Cómo se ha leído esta información" aria-label="Cómo se ha leído esta información">i</button></h2>
            <span class="view-card-info" id="maq-count">—</span>
        </div>
        <div class="view-card-body">
            <div id="chart-maquina-big"></div>
        </div>
        <div class="view-card-footer metric-legend">
            <div class="metric-legend-title">¿Cómo se calcula el Cumplimiento?</div>
            <div class="metric-legend-formula">
                <span class="formula-num">Σ min(producido, planificado)</span>
                <span class="formula-bar">÷</span>
                <span class="formula-den">Σ planificado</span>
                <span class="formula-note">por cada artículo de la máquina</span>
            </div>
            <div class="metric-legend-text">
                <p><strong>Criterio estricto por artículo:</strong> si una máquina tenía que hacer 1 000 unidades de una referencia y fabricó 1 200, se cuentan solo las <strong>1 000 planificadas</strong>. La sobreproducción de una referencia <strong>no compensa</strong> el déficit de otra.</p>
                <p>Solo aparecen las máquinas con plan o producción en el día/turno seleccionado. Un 100 % significa que la máquina cumplió (o superó) todas las referencias planificadas; un 0 % significa que no se fabricó nada de lo previsto.</p>
                <p class="metric-legend-note">La barra BT agrupa las dos líneas físicas BT 3.4 DCHA e IZQDA (se planifican como una sola en el Excel). La línea verde discontinua marca el objetivo del 75 %.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_maquina.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_maquina.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>
</body>
</html>
