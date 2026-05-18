<?php
$pageTitle = 'Plan Attainment';
include __DIR__ . '/includes/header.php';
?>

<main class="home-main">
    <div class="home-intro">
        <p class="intro-text">Selecciona una vista para comenzar el análisis</p>
    </div>

    <div class="home-section-title">
        <span>Plan Attainment</span>
        <small>Cumplimiento del plan de producción</small>
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
                <h2>Plan Attainment</h2>
                <p>Cumplimiento global del plan de producción</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="views/por_seccion.php" class="tile tile-seccion">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="10" y="10" width="35" height="40" rx="3" fill="#3a6aa3" stroke="#5b8cc7" stroke-width="1.5"/>
                    <rect x="55" y="10" width="35" height="40" rx="3" fill="#3a6aa3" stroke="#5b8cc7" stroke-width="1.5"/>
                    <text x="27.5" y="35" text-anchor="middle" fill="#ffffff" font-family="Arial" font-size="11" font-weight="700">77%</text>
                    <text x="72.5" y="35" text-anchor="middle" fill="#ffffff" font-family="Arial" font-size="11" font-weight="700">85%</text>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Por Sección</h2>
                <p>Cumplimiento agrupado por sección</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="views/por_maquina.php" class="tile tile-maquina">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="8"  y="35" width="10" height="20" rx="1" fill="#ef4444"/>
                    <rect x="22" y="25" width="10" height="30" rx="1" fill="#f59e0b"/>
                    <rect x="36" y="20" width="10" height="35" rx="1" fill="#f59e0b"/>
                    <rect x="50" y="15" width="10" height="40" rx="1" fill="#10b981"/>
                    <rect x="64" y="10" width="10" height="45" rx="1" fill="#10b981"/>
                    <rect x="78" y="7"  width="10" height="48" rx="1" fill="#10b981"/>
                    <line x1="5" y1="12" x2="95" y2="12" stroke="#10b981" stroke-width="1.5" stroke-dasharray="3,2"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Por Máquina</h2>
                <p>Cumplimiento individual por máquina</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="views/evolucion.php" class="tile tile-evolucion">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <polyline points="5,40 20,15 35,30 55,50 75,20 95,35" stroke="#f4c430" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="5"  cy="40" r="3" fill="#f4c430"/>
                    <circle cx="20" cy="15" r="3" fill="#f4c430"/>
                    <circle cx="35" cy="30" r="3" fill="#f4c430"/>
                    <circle cx="55" cy="50" r="3" fill="#f4c430"/>
                    <circle cx="75" cy="20" r="3" fill="#f4c430"/>
                    <circle cx="95" cy="35" r="3" fill="#f4c430"/>
                    <line x1="5" y1="10" x2="95" y2="10" stroke="#10b981" stroke-width="1.5" stroke-dasharray="3,2"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Evolución</h2>
                <p>Serie temporal diaria del cumplimiento</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="views/grid.php" class="tile tile-grid">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="5"  y="8"  width="90" height="10" fill="#3a6aa3"/>
                    <rect x="5"  y="20" width="20" height="10" fill="#ffd5cc"/>
                    <rect x="27" y="20" width="20" height="10" fill="#ffcac0"/>
                    <rect x="49" y="20" width="20" height="10" fill="#c9f0c5"/>
                    <rect x="71" y="20" width="24" height="10" fill="#a8e6a3"/>
                    <rect x="5"  y="32" width="20" height="10" fill="#ffcac0"/>
                    <rect x="27" y="32" width="20" height="10" fill="#c9f0c5"/>
                    <rect x="49" y="32" width="20" height="10" fill="#a8e6a3"/>
                    <rect x="71" y="32" width="24" height="10" fill="#ffd5cc"/>
                    <rect x="5"  y="44" width="20" height="10" fill="#c9f0c5"/>
                    <rect x="27" y="44" width="20" height="10" fill="#a8e6a3"/>
                    <rect x="49" y="44" width="20" height="10" fill="#ffd5cc"/>
                    <rect x="71" y="44" width="24" height="10" fill="#ffcac0"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Detalle Plan / Prod</h2>
                <p>Tabla con planificado vs producido por día</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

    </div>

    <div class="home-section-title home-section-title-oee">
        <span>Night Letter · OEE</span>
        <small>Eficiencia global del equipo productivo</small>
    </div>

    <div class="home-grid">

        <a href="views/disponibilidad.php" class="tile tile-disponibilidad">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <path d="M10 50 A30 30 0 0 1 90 50" stroke="#3a6aa3" stroke-width="9" fill="none"/>
                    <path d="M10 50 A30 30 0 0 1 32 23" stroke="#c8102e" stroke-width="9" fill="none"/>
                    <path d="M32 23 A30 30 0 0 1 42 16" stroke="#f59e0b" stroke-width="9" fill="none"/>
                    <path d="M42 16 A30 30 0 0 1 90 50" stroke="#10b981" stroke-width="9" fill="none"/>
                    <line x1="50" y1="50" x2="68" y2="22" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                    <circle cx="50" cy="50" r="4" fill="#ffffff"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Disponibilidad</h2>
                <p>Tiempo en marcha · gauge, sección, evolución, paros</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="views/rendimiento.php" class="tile tile-rendimiento">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <path d="M10 50 A30 30 0 0 1 90 50" stroke="#3a6aa3" stroke-width="9" fill="none"/>
                    <path d="M10 50 A30 30 0 0 1 25 28" stroke="#c8102e" stroke-width="9" fill="none"/>
                    <path d="M25 28 A30 30 0 0 1 36 19" stroke="#f59e0b" stroke-width="9" fill="none"/>
                    <path d="M36 19 A30 30 0 0 1 90 50" stroke="#10b981" stroke-width="9" fill="none"/>
                    <line x1="50" y1="50" x2="74" y2="32" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                    <circle cx="50" cy="50" r="4" fill="#ffffff"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Rendimiento</h2>
                <p>Velocidad real vs nominal · gauge, sección, evolución, pérdidas</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="views/calidad.php" class="tile tile-calidad">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <path d="M10 50 A30 30 0 0 1 90 50" stroke="#3a6aa3" stroke-width="9" fill="none"/>
                    <path d="M10 50 A30 30 0 0 1 18 32" stroke="#c8102e" stroke-width="9" fill="none"/>
                    <path d="M18 32 A30 30 0 0 1 26 22" stroke="#f59e0b" stroke-width="9" fill="none"/>
                    <path d="M26 22 A30 30 0 0 1 90 50" stroke="#10b981" stroke-width="9" fill="none"/>
                    <line x1="50" y1="50" x2="78" y2="32" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                    <circle cx="50" cy="50" r="4" fill="#ffffff"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Calidad</h2>
                <p>Piezas OK vs NOK · gauge, sección, evolución, rechazos por motivo</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="views/oee_fab.php" class="tile tile-oee-fab">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <path d="M15 50 A25 25 0 0 1 85 50" stroke="#3a6aa3" stroke-width="7" fill="none"/>
                    <path d="M15 50 A25 25 0 0 1 35 22" stroke="#c8102e" stroke-width="7" fill="none"/>
                    <path d="M35 22 A25 25 0 0 1 65 22" stroke="#f59e0b" stroke-width="7" fill="none"/>
                    <path d="M65 22 A25 25 0 0 1 85 50" stroke="#10b981" stroke-width="7" fill="none"/>
                    <line x1="50" y1="50" x2="62" y2="30" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                    <circle cx="50" cy="50" r="4" fill="#ffffff"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>OEE FAB</h2>
                <p>Eficiencia global de Fabricación (gauge, sección, máquina, evolución)</p>
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
</main>

<script src="assets/js/common.js"></script>
<script>initFiltros();</script>
</body>
</html>
