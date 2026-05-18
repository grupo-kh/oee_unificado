<?php
$pageTitle = 'Calidad';
include __DIR__ . '/../includes/header.php';
?>

<main class="home-main">
    <div class="home-intro">
        <p class="intro-text">Elige una vista del panel Calidad</p>
    </div>

    <div class="home-section-title home-section-title-oee">
        <span>Calidad</span>
        <small>Producción conforme · piezas OK vs. NOK</small>
    </div>

    <div class="home-grid">

        <a href="calidad_global.php" class="tile tile-cal-global">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <path d="M10 52 A32 32 0 0 1 90 52" stroke="#c8102e" stroke-width="9" fill="none"/>
                    <path d="M14 38 A32 32 0 0 1 28 22" stroke="#f59e0b" stroke-width="9" fill="none"/>
                    <path d="M28 22 A32 32 0 0 1 90 52" stroke="#10b981" stroke-width="9" fill="none"/>
                    <circle cx="50" cy="52" r="5" fill="#ffffff"/>
                    <line x1="50" y1="52" x2="78" y2="36" stroke="#ffffff" stroke-width="3" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Gauge + Por Sección <span class="tile-badge">dinámico</span></h2>
                <p>Calidad global y por sección, filtrable por <strong>máquina o artículo</strong></p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="calidad_evolucion.php" class="tile tile-cal-evo">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <polyline points="5,22 20,18 35,14 50,10 65,18 80,12 95,15"
                              stroke="#f4c430" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="5"  cy="22" r="3" fill="#f4c430"/>
                    <circle cx="20" cy="18" r="3" fill="#f4c430"/>
                    <circle cx="35" cy="14" r="3" fill="#f4c430"/>
                    <circle cx="50" cy="10" r="3" fill="#f4c430"/>
                    <circle cx="65" cy="18" r="3" fill="#f4c430"/>
                    <circle cx="80" cy="12" r="3" fill="#f4c430"/>
                    <circle cx="95" cy="15" r="3" fill="#f4c430"/>
                    <line x1="3" y1="8" x2="97" y2="8" stroke="#10b981" stroke-width="1.5" stroke-dasharray="3,2"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Evolución <span class="tile-badge">dinámico</span></h2>
                <p>Serie temporal diaria de la Calidad, filtrable por máquina/artículo</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="calidad_rechazos.php" class="tile tile-cal-rechazos">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="6"  y="14" width="11" height="40" rx="1" fill="#c8102e"/>
                    <rect x="20" y="22" width="11" height="32" rx="1" fill="#c8102e"/>
                    <rect x="34" y="28" width="11" height="26" rx="1" fill="#ef4444"/>
                    <rect x="48" y="34" width="11" height="20" rx="1" fill="#ef4444"/>
                    <rect x="62" y="38" width="11" height="16" rx="1" fill="#f59e0b"/>
                    <rect x="76" y="42" width="11" height="12" rx="1" fill="#f59e0b"/>
                    <polyline points="11,18 25,28 39,36 53,42 67,46 81,50 95,52"
                              stroke="#f4c430" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Unidades Rechazadas por Motivo <span class="tile-badge">dinámico</span></h2>
                <p>Pareto de rechazos por motivo (clic → desglose por máquina)</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

    </div>
</main>

<script src="../assets/js/common.js"></script>
<script>initFiltros();</script>
</body>
</html>
