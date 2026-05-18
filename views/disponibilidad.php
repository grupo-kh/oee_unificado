<?php
$pageTitle = 'Disponibilidad';
include __DIR__ . '/../includes/header.php';
?>

<main class="home-main">
    <div class="home-intro">
        <p class="intro-text">Elige una vista del panel Disponibilidad</p>
    </div>

    <div class="home-section-title home-section-title-oee">
        <span>Disponibilidad</span>
        <small>Tiempo en marcha sobre tiempo programado</small>
    </div>

    <div class="home-grid">

        <a href="disponibilidad_global.php" class="tile tile-disp-global">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <path d="M10 52 A32 32 0 0 1 90 52" stroke="#c8102e" stroke-width="9" fill="none"/>
                    <path d="M30 25 A32 32 0 0 1 60 18" stroke="#f59e0b" stroke-width="9" fill="none"/>
                    <path d="M60 18 A32 32 0 0 1 90 52" stroke="#10b981" stroke-width="9" fill="none"/>
                    <circle cx="50" cy="52" r="5" fill="#ffffff"/>
                    <line x1="50" y1="52" x2="66" y2="28" stroke="#ffffff" stroke-width="3" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Gauge + Por Sección <span class="tile-badge">dinámico</span></h2>
                <p>Disponibilidad global y por sección, filtrable por <strong>máquina o artículo</strong></p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="disponibilidad_evolucion.php" class="tile tile-disp-evo">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <polyline points="5,40 20,30 35,38 50,18 65,12 80,28 95,22"
                              stroke="#f4c430" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="5" cy="40" r="3" fill="#f4c430"/>
                    <circle cx="20" cy="30" r="3" fill="#f4c430"/>
                    <circle cx="35" cy="38" r="3" fill="#f4c430"/>
                    <circle cx="50" cy="18" r="3" fill="#f4c430"/>
                    <circle cx="65" cy="12" r="3" fill="#f4c430"/>
                    <circle cx="80" cy="28" r="3" fill="#f4c430"/>
                    <circle cx="95" cy="22" r="3" fill="#f4c430"/>
                    <line x1="3" y1="20" x2="97" y2="20" stroke="#10b981" stroke-width="1.5" stroke-dasharray="3,2"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Evolución <span class="tile-badge">dinámico</span></h2>
                <p>Serie temporal diaria de la Disponibilidad, filtrable por máquina/artículo</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="disponibilidad_paros.php" class="tile tile-disp-paros">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="6"  y="14" width="14" height="40" rx="1" fill="#3a6aa3"/>
                    <rect x="22" y="32" width="14" height="22" rx="1" fill="#3a6aa3"/>
                    <rect x="38" y="40" width="14" height="14" rx="1" fill="#3a6aa3"/>
                    <rect x="54" y="44" width="14" height="10" rx="1" fill="#3a6aa3"/>
                    <rect x="70" y="48" width="14" height="6"  rx="1" fill="#3a6aa3"/>
                    <polyline points="13,18 29,32 45,40 61,44 77,48 93,50"
                              stroke="#f4c430" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Horas de paro por motivo</h2>
                <p>Pareto de tipos de paro (próximamente)</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

    </div>
</main>

<script src="../assets/js/common.js"></script>
<script>initFiltros();</script>
</body>
</html>
