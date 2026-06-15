<?php
$pageTitle = 'Plan Attainment';
$hideFiltros = true;   // en la home los filtros Fch. Productiva / Turno no aplican
include __DIR__ . '/includes/header.php';
?>

<main class="home-main">
    <div class="home-intro">
        <p class="intro-text">Selecciona una vista para comenzar el análisis</p>
    </div>

    <div class="home-section-title">
        <span>Cumplimiento Global Plan de Producción</span>
        <small>Gauge global, por sección y evolución diaria · todo en una pantalla</small>
    </div>

    <div class="home-grid">

        <a href="views/plan_attainment.php" class="tile tile-gauge">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <path d="M10 55 A40 40 0 0 1 90 55" stroke="#c8102e" stroke-width="10" fill="none" stroke-linecap="round"/>
                    <path d="M10 55 A40 40 0 0 1 35 18" stroke="#ef4444" stroke-width="10" fill="none" stroke-linecap="round" opacity="0.4"/>
                    <path d="M50 15 A40 40 0 0 1 75 22" stroke="#f59e0b" stroke-width="10" fill="none" stroke-linecap="round" opacity="0.7"/>
                    <path d="M75 22 A40 40 0 0 1 90 55" stroke="#10b981" stroke-width="10" fill="none" stroke-linecap="round"/>
                    <circle cx="50" cy="55" r="6" fill="#ffffff"/>
                    <line x1="50" y1="55" x2="65" y2="28" stroke="#ffffff" stroke-width="3" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Cumplimiento Global Plan de Producción</h2>
                <p>Gauge, por sección y evolución · todo en una sola vista</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

    </div>

    <div class="home-section-title home-section-title-oee">
        <span>OEE Unificado</span>
        <small>Vista en cascada · rango de fechas · multi-turno · D · R · C · OEE</small>
    </div>

    <div class="home-grid">

        <a href="views/oee_unificado.php" class="tile tile-oee-unificado">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="10" y="18" width="14" height="36" rx="2" fill="#c8102e"/>
                    <rect x="30" y="10" width="14" height="44" rx="2" fill="#f59e0b"/>
                    <rect x="50" y="22" width="14" height="32" rx="2" fill="#3a6aa3"/>
                    <rect x="70" y="6"  width="14" height="48" rx="2" fill="#10b981"/>
                    <line x1="5" y1="54" x2="95" y2="54" stroke="#1a2d4a" stroke-width="1.5"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>OEE Unificado <span class="tile-badge">nuevo</span></h2>
                <p>Vista en cascada · rango de fechas · multi-turno · D · R · C · OEE por sección</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

    </div>

    <div class="home-section-title home-section-title-mant">
        <span>Mantenimiento</span>
        <small>Plan preventivo · cumplimiento · histórico de intervenciones</small>
    </div>

    <div class="home-grid">

        <a href="views/mantenimiento.php" class="tile tile-mant">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <circle cx="32" cy="30" r="14" stroke="#5b8cc7" stroke-width="3" fill="none"/>
                    <circle cx="32" cy="30" r="4" fill="#3a6aa3"/>
                    <line x1="32" y1="6"  x2="32" y2="14" stroke="#3a6aa3" stroke-width="3" stroke-linecap="round"/>
                    <line x1="32" y1="46" x2="32" y2="54" stroke="#3a6aa3" stroke-width="3" stroke-linecap="round"/>
                    <line x1="8"  y1="30" x2="16" y2="30" stroke="#3a6aa3" stroke-width="3" stroke-linecap="round"/>
                    <line x1="48" y1="30" x2="56" y2="30" stroke="#3a6aa3" stroke-width="3" stroke-linecap="round"/>
                    <path d="M70 18 L86 18 L86 42 L70 42 Z" fill="#3a6aa3" stroke="#5b8cc7" stroke-width="1.5"/>
                    <line x1="73" y1="24" x2="83" y2="24" stroke="#ffffff" stroke-width="1.2"/>
                    <line x1="73" y1="30" x2="83" y2="30" stroke="#ffffff" stroke-width="1.2"/>
                    <line x1="73" y1="36" x2="83" y2="36" stroke="#ffffff" stroke-width="1.2"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Mantenimiento Preventivo</h2>
                <p>Próximas revisiones, cumplimiento y histórico de intervenciones</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

    </div>

    <div class="home-section-title home-section-title-oee">
        <span>Producción</span>
        <small>Lanzamiento y seguimiento de órdenes de fabricación en planta</small>
    </div>

    <div class="home-grid">

        <a href="oflanza.php" class="tile tile-mant-acciones" target="_blank" rel="noopener">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <!-- Marco tablet -->
                    <rect x="14" y="8" width="72" height="44" rx="4" fill="#2d4d7a" stroke="#5b8cc7" stroke-width="1.5"/>
                    <rect x="18" y="12" width="64" height="36" rx="2" fill="#fff"/>
                    <!-- 8 huecos del grid -->
                    <rect x="22" y="16" width="14" height="9" rx="1.5" fill="#ffb78a"/>
                    <rect x="40" y="16" width="14" height="9" rx="1.5" fill="#ffb78a"/>
                    <rect x="58" y="16" width="14" height="9" rx="1.5" fill="#fff8e8" stroke="#f0c674" stroke-width="0.5"/>
                    <rect x="22" y="29" width="14" height="9" rx="1.5" fill="#fff8e8" stroke="#f0c674" stroke-width="0.5"/>
                    <rect x="40" y="29" width="14" height="9" rx="1.5" fill="#fff8e8" stroke="#f0c674" stroke-width="0.5"/>
                    <rect x="58" y="29" width="14" height="9" rx="1.5" fill="#fff8e8" stroke="#f0c674" stroke-width="0.5"/>
                    <!-- Botón OK central -->
                    <rect x="40" y="42" width="14" height="5" rx="1" fill="#1f8a3c"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Lanzamiento de OFs <span class="tile-badge">tablet</span></h2>
                <p>App para tablets de planta: selección de estación, listado de OFs del día y ficha de detalle con lanzamiento.</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

    </div>
</main>

<script src="assets/js/common.js"></script>
<script>initFiltros();</script>
</body>
</html>
