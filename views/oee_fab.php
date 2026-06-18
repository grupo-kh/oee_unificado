<?php
$pageTitle = 'OEE FAB · Fabricación';
include __DIR__ . '/../includes/header.php';
?>

<main class="home-main">
    <div class="home-intro">
        <p class="intro-text">Elige una vista del panel OEE de Fabricación</p>
    </div>

    <div class="home-section-title home-section-title-oee">
        <span>OEE FAB · Fabricación</span>
        <small>Replicación del panel Night Letter · OEE</small>
    </div>

    <div class="home-grid">

        <a href="oee_fab_global.php" class="tile tile-oee-global">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <path d="M10 52 A32 32 0 0 1 90 52" stroke="#c8102e" stroke-width="9" fill="none"/>
                    <path d="M38 23 A32 32 0 0 1 62 23" stroke="#f59e0b" stroke-width="9" fill="none"/>
                    <path d="M62 23 A32 32 0 0 1 90 52" stroke="#8c181a" stroke-width="9" fill="none"/>
                    <circle cx="50" cy="52" r="5" fill="#ffffff"/>
                    <line x1="50" y1="52" x2="66" y2="28" stroke="#ffffff" stroke-width="3" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Gauge Global + Por Sección</h2>
                <p>OEE de Fabricación conjunto y desglose VARILLAS / TROQUELADOS</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="oee_fab_evolucion.php" class="tile tile-oee-evo">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <polyline points="5,42 20,38 35,20 50,30 65,15 80,22 95,18"
                              stroke="#f4c430" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    <polyline points="5,28 20,30 35,14 50,25 65,20 80,14 95,10"
                              stroke="#8c181a" stroke-width="2" fill="none" stroke-linecap="round" opacity="0.85"/>
                    <polyline points="5,48 20,44 35,38 50,46 65,40 80,44 95,42"
                              stroke="#8b5cf6" stroke-width="2" fill="none" stroke-linecap="round" opacity="0.85"/>
                    <polyline points="5,22 20,26 35,16 50,18 65,22 80,19 95,15"
                              stroke="#c8102e" stroke-width="2" fill="none" stroke-linecap="round" opacity="0.85"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Evolución · Global y Desglosada</h2>
                <p>Serie temporal del OEE global y desglose Disponibilidad / Rendimiento / Calidad</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="oee_fab_maquina.php" class="tile tile-oee-maq">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="6"  y="42" width="82" height="4" rx="1" fill="#c8102e"/>
                    <rect x="6"  y="34" width="66" height="4" rx="1" fill="#ef4444"/>
                    <rect x="6"  y="26" width="58" height="4" rx="1" fill="#f59e0b"/>
                    <rect x="6"  y="18" width="46" height="4" rx="1" fill="#f59e0b"/>
                    <rect x="6"  y="10" width="34" height="4" rx="1" fill="#8c181a"/>
                    <rect x="6"  y="2"  width="20" height="4" rx="1" fill="#8c181a"/>
                    <line x1="72" y1="0" x2="72" y2="50" stroke="#8c181a" stroke-width="1.5" stroke-dasharray="3,2"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Por Máquina <span class="tile-badge">dinámico</span></h2>
                <p>Ranking de OEE por máquina (clic en una barra para filtrar)</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

    </div>
</main>

<script src="../assets/js/common.js"></script>
<script>initFiltros();</script>
</body>
</html>
