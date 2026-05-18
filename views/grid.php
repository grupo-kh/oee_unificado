<?php
$pageTitle = 'Detalle Plan / Producido';
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main view-main-grid">
    <div class="view-card view-card-full">
        <div class="view-card-header">
            <h2>Planificado vs. Producido<button type="button" id="info-icon" class="info-icon" title="Cómo se ha leído esta información" aria-label="Cómo se ha leído esta información">i</button></h2>
            <div class="grid-legend">
                <span class="legend-item"><span class="legend-box legend-ok"></span> Cumplido</span>
                <span class="legend-item"><span class="legend-box legend-warn"></span> Parcial</span>
                <span class="legend-item"><span class="legend-box legend-bad"></span> Incumplido</span>
            </div>
        </div>
        <div class="view-card-body grid-body">
            <div class="grid-scroll" id="grid-scroll">
                <table class="grid-table" id="grid-table">
                    <thead></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="view-card-footer metric-legend metric-legend-compact">
            <div class="metric-legend-text">
                <p><strong>¿Qué máquinas aparecen aquí?</strong> Este grid es una vista <strong>diagnóstica</strong>: muestra <strong>todo el plan del Excel del turno</strong>, incluidas las máquinas de soporte (<code>PRENSA 3D N2</code>, <code>TBE30</code>, <code>TBE35</code>, <code>TBE RAPIDFORM</code>, <code>MONTAJE AUTOMATICO</code>, etc.).</p>
                <p class="metric-legend-note"><strong>Por qué difiere de otros paneles:</strong> otros cuadros (p. ej. QV) ocultan estas máquinas porque solo listan las 14 del plan oficial (<code>DOBL1–11</code>, <code>SOLD1/3/6</code>, <code>TROQ3</code>). Aquí las verás todas. El <strong>Cumplimiento Global</strong>, <strong>Por Sección</strong> y <strong>Evolución</strong> sí aplican el whitelist estricto; este grid y <strong>Por Máquina</strong> enseñan el plan completo.</p>
            </div>
        </div>
    </div>
</main>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_grid.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_grid.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>
</body>
</html>
