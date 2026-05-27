<?php
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();

$pageTitle = 'Mantenimiento';
$hideFiltros = true;
$mantUserRole = Auth::role();
$mantUserName = Auth::user();
include __DIR__ . '/../includes/header.php';
?>

<main class="home-main">
    <div class="mant-user-bar">
        <span>Conectado como <span class="mant-user-name"><?= htmlspecialchars($mantUserName ?? '', ENT_QUOTES) ?></span></span>
        <span class="mant-user-role role-<?= htmlspecialchars($mantUserRole ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($mantUserRole ?? '', ENT_QUOTES) ?></span>
        <a href="../api/mant_logout.php" class="mant-user-logout">Cerrar sesión</a>
    </div>

    <div class="home-intro">
        <p class="intro-text">Elige una vista del panel Mantenimiento</p>
    </div>

    <div class="home-section-title home-section-title-oee">
        <span>Mantenimiento Preventivo</span>
        <small>Plan de revisiones · cumplimiento · histórico</small>
    </div>

    <div class="home-grid">

        <!--
            Acceso al MANUAL DE USO de la app de mantenimiento. Va el primero
            del menú general para que cualquier usuario lo encuentre antes de
            entrar en los módulos. Abre el manual en pestaña nueva, imprimible
            a PDF desde el botón superior.
        -->
        <a href="manual.php" class="tile tile-mant-manual" target="_blank" rel="noopener">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <!-- Libro abierto -->
                    <path d="M14 14 L48 18 L48 50 L14 46 Z" fill="#5b3fb8"/>
                    <path d="M86 14 L52 18 L52 50 L86 46 Z" fill="#7c5fd9"/>
                    <line x1="20" y1="24" x2="42" y2="27"/>
                    <line x1="20" y1="30" x2="42" y2="33"/>
                    <line x1="20" y1="36" x2="38" y2="38"/>
                    <line x1="58" y1="27" x2="80" y2="24"/>
                    <line x1="58" y1="33" x2="80" y2="30"/>
                    <line x1="58" y1="38" x2="76" y2="36"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Manual de uso app mantenimiento <span class="tile-badge">guía</span></h2>
                <p>Resumen de cómo se usa cada módulo (Acciones, Próximas, Cumplimiento, Histórico, Móvil). Botón para imprimir o guardar como PDF.</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <!--
            Acceso al PROTOTIPO de la app móvil del operario.
            Aún no es operativo: enlaza al mockup HTML autocontenido.
            Cuando se cablée a la BD se sustituirá el href.
        -->
        <a href="../prototipos/operario_mobile.html" class="tile tile-mant-mobile tile-wip" target="_blank" rel="noopener">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="32" y="6" width="36" height="50" rx="5" fill="#0d0d0d" stroke="#8c181a" stroke-width="1.8"/>
                    <rect x="35" y="11" width="30" height="38" rx="2" fill="#1d1d1d"/>
                    <circle cx="50" cy="53" r="2" fill="#8c181a"/>
                    <rect x="40" y="16" width="20" height="4" rx="1" fill="#8c181a"/>
                    <rect x="40" y="22" width="14" height="3" rx="1" fill="#ffffff" opacity="0.7"/>
                    <rect x="40" y="28" width="20" height="3" rx="1" fill="#ffffff" opacity="0.5"/>
                    <rect x="40" y="33" width="20" height="3" rx="1" fill="#ffffff" opacity="0.35"/>
                    <rect x="40" y="38" width="20" height="3" rx="1" fill="#ffffff" opacity="0.2"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Móvil · Operario
                    <span class="tile-badge tile-badge-wip">En proceso de aplicación</span>
                </h2>
                <p>App responsive para móvil. El operario se identifica con su nº, abre la tarea (arranca cronómetro), marca las sub-tareas hechas y la revisión queda registrada con su tiempo real.</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="mant_acciones.php" class="tile tile-mant-acciones role-tecnico-only">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="14" y="12" width="44" height="40" rx="3" fill="#3a6aa3" stroke="#5b8cc7" stroke-width="1.2"/>
                    <line x1="20" y1="22" x2="52" y2="22" stroke="#ffffff" stroke-width="1.2"/>
                    <line x1="20" y1="30" x2="48" y2="30" stroke="#ffffff" stroke-width="1.2"/>
                    <line x1="20" y1="38" x2="50" y2="38" stroke="#ffffff" stroke-width="1.2"/>
                    <line x1="20" y1="46" x2="44" y2="46" stroke="#ffffff" stroke-width="1.2"/>
                    <circle cx="78" cy="36" r="14" fill="#10b981" stroke="#ffffff" stroke-width="2"/>
                    <line x1="78" y1="29" x2="78" y2="43" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                    <line x1="71" y1="36" x2="85" y2="36" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Acciones por Máquina <span class="tile-badge">editor</span></h2>
                <p>Listado de máquinas con su número de tareas preventivas. Al pinchar, edita / borra / añade tareas con periodicidad y descripción.</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <?php /* Tile "Planificador de Tareas" (mant_semana.php) retirado:
                  las tareas se imputan únicamente desde Próximas Revisiones
                  para evitar duplicidad de entrada. */ ?>

        <a href="mant_proximas.php" class="tile tile-mant-proximas">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="10" y="10" width="80" height="44" rx="3" fill="#3a6aa3" stroke="#5b8cc7" stroke-width="1.5"/>
                    <line x1="10" y1="22" x2="90" y2="22" stroke="#ffffff" stroke-width="1.2"/>
                    <line x1="30" y1="22" x2="30" y2="54" stroke="#ffffff" stroke-width="1"/>
                    <line x1="50" y1="22" x2="50" y2="54" stroke="#ffffff" stroke-width="1"/>
                    <line x1="70" y1="22" x2="70" y2="54" stroke="#ffffff" stroke-width="1"/>
                    <circle cx="20" cy="32" r="2.5" fill="#ef4444"/>
                    <circle cx="40" cy="32" r="2.5" fill="#f59e0b"/>
                    <circle cx="60" cy="44" r="2.5" fill="#10b981"/>
                    <circle cx="80" cy="44" r="2.5" fill="#10b981"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Próximas Revisiones <span class="tile-badge">dinámico</span></h2>
                <p>Tareas planificadas en los próximos N días con estado vencida/urgente/en plazo</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="mant_cumplimiento.php" class="tile tile-mant-cumpl">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <path d="M10 50 A30 30 0 0 1 90 50" stroke="#3a6aa3" stroke-width="9" fill="none"/>
                    <path d="M10 50 A30 30 0 0 1 30 25" stroke="#c8102e" stroke-width="9" fill="none"/>
                    <path d="M30 25 A30 30 0 0 1 50 18" stroke="#f59e0b" stroke-width="9" fill="none"/>
                    <path d="M50 18 A30 30 0 0 1 90 50" stroke="#10b981" stroke-width="9" fill="none"/>
                    <line x1="50" y1="50" x2="68" y2="22" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                    <circle cx="50" cy="50" r="4" fill="#ffffff"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Cumplimiento Preventivo <span class="tile-badge">dinámico</span></h2>
                <p>Gauge global y desglose por periodicidad (semanal, quincenal, mensual…)</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <a href="mant_historico.php" class="tile tile-mant-historico">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="6"  y="42" width="11" height="12" rx="1" fill="#3a6aa3"/>
                    <rect x="20" y="34" width="11" height="20" rx="1" fill="#3a6aa3"/>
                    <rect x="34" y="28" width="11" height="26" rx="1" fill="#3a6aa3"/>
                    <rect x="48" y="22" width="11" height="32" rx="1" fill="#3a6aa3"/>
                    <rect x="62" y="16" width="11" height="38" rx="1" fill="#3a6aa3"/>
                    <rect x="76" y="10" width="11" height="44" rx="1" fill="#3a6aa3"/>
                    <polyline points="11,46 25,38 39,32 53,28 67,22 81,16 95,12"
                              stroke="#f4c430" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Histórico por Máquina <span class="tile-badge">dinámico</span></h2>
                <p>Lista de intervenciones realizadas en un periodo, filtrable por máquina/operario</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

    </div>
</main>

<script src="../assets/js/common.js"></script>
<script>initFiltros();</script>
</body>
</html>
