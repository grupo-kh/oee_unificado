<?php
$pageTitle = 'Rendimiento';
include __DIR__ . '/../includes/header.php';
?>

<main class="home-main">
    <div class="home-intro">
        <p class="intro-text">Elige una vista del panel Rendimiento</p>
    </div>

    <div class="home-section-title home-section-title-oee">
        <span>Rendimiento</span>
        <small>Velocidad real vs velocidad nominal</small>
    </div>

    <div class="home-grid">

        <a href="rendimiento_global.php" class="tile tile-rend-global">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <path d="M10 52 A32 32 0 0 1 90 52" stroke="#c8102e" stroke-width="9" fill="none"/>
                    <path d="M30 25 A32 32 0 0 1 60 18" stroke="#f59e0b" stroke-width="9" fill="none"/>
                    <path d="M60 18 A32 32 0 0 1 90 52" stroke="#8c181a" stroke-width="9" fill="none"/>
                    <circle cx="50" cy="52" r="5" fill="#ffffff"/>
                    <line x1="50" y1="52" x2="74" y2="34" stroke="#ffffff" stroke-width="3" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Gauge + Por Sección <span class="tile-badge">dinámico</span></h2>
                <p>Rendimiento global y por sección, filtrable por <strong>máquina o artículo</strong></p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="rendimiento_evolucion.php" class="tile tile-rend-evo">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <polyline points="5,28 20,32 35,18 50,22 65,30 80,16 95,20"
                              stroke="#f4c430" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="5" cy="28" r="3" fill="#f4c430"/>
                    <circle cx="20" cy="32" r="3" fill="#f4c430"/>
                    <circle cx="35" cy="18" r="3" fill="#f4c430"/>
                    <circle cx="50" cy="22" r="3" fill="#f4c430"/>
                    <circle cx="65" cy="30" r="3" fill="#f4c430"/>
                    <circle cx="80" cy="16" r="3" fill="#f4c430"/>
                    <circle cx="95" cy="20" r="3" fill="#f4c430"/>
                    <line x1="3" y1="14" x2="97" y2="14" stroke="#8c181a" stroke-width="1.5" stroke-dasharray="3,2"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Evolución <span class="tile-badge">dinámico</span></h2>
                <p>Serie temporal diaria del Rendimiento, filtrable por máquina/artículo</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="rendimiento_perdidas.php" class="tile tile-rend-perdidas">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="6"  y="42" width="11" height="12" rx="1" fill="#3a6aa3"/>
                    <rect x="20" y="38" width="11" height="16" rx="1" fill="#3a6aa3"/>
                    <rect x="34" y="32" width="11" height="22" rx="1" fill="#3a6aa3"/>
                    <rect x="48" y="26" width="11" height="28" rx="1" fill="#3a6aa3"/>
                    <rect x="62" y="20" width="11" height="34" rx="1" fill="#3a6aa3"/>
                    <rect x="76" y="14" width="11" height="40" rx="1" fill="#3a6aa3"/>
                    <polyline points="11,46 25,38 39,32 53,28 67,22 81,16 95,12"
                              stroke="#f4c430" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Horas Perdidas por Rendimiento <span class="tile-badge">dinámico</span></h2>
                <p>Pareto de pérdidas de rendimiento por máquina (clic → desglose por artículo)</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

    </div>
</main>

<script src="../assets/js/common.js"></script>
<script>initFiltros();</script>
</body>
</html>
