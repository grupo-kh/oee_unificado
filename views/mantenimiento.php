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

    <?php
    // Acceso oculto a "Máquinas pausadas": solo si el usuario es técnico,
    // la etiqueta de la sección "Mantenimiento Preventivo" actúa como
    // enlace al panel de pausadas. Para el operario es texto inerte.
    if (Auth::isTecnico()):
    ?>
    <a href="mant_acciones.php?modo=pausadas"
       class="home-section-title home-section-title-oee"
       style="text-decoration:none; cursor:pointer"
       title="Acceso técnico · Máquinas pausadas">
        <span>Mantenimiento Preventivo</span>
        <small>Plan de revisiones · cumplimiento · histórico</small>
    </a>
    <?php else: ?>
    <div class="home-section-title home-section-title-oee">
        <span>Mantenimiento Preventivo</span>
        <small>Plan de revisiones · cumplimiento · histórico</small>
    </div>
    <?php endif; ?>

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

        <a href="mant_acciones.php" class="tile tile-mant-acciones role-tecnico-only">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <rect x="14" y="12" width="44" height="40" rx="3" fill="#3a6aa3" stroke="#5b8cc7" stroke-width="1.2"/>
                    <line x1="20" y1="22" x2="52" y2="22" stroke="#ffffff" stroke-width="1.2"/>
                    <line x1="20" y1="30" x2="48" y2="30" stroke="#ffffff" stroke-width="1.2"/>
                    <line x1="20" y1="38" x2="50" y2="38" stroke="#ffffff" stroke-width="1.2"/>
                    <line x1="20" y1="46" x2="44" y2="46" stroke="#ffffff" stroke-width="1.2"/>
                    <circle cx="78" cy="36" r="14" fill="#8c181a" stroke="#ffffff" stroke-width="2"/>
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
                    <circle cx="60" cy="44" r="2.5" fill="#8c181a"/>
                    <circle cx="80" cy="44" r="2.5" fill="#8c181a"/>
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
                    <path d="M50 18 A30 30 0 0 1 90 50" stroke="#8c181a" stroke-width="9" fill="none"/>
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

        <!-- Gestión de operarios: solo rol técnico (la clase role-tecnico-only
             la oculta vía CSS si el usuario es operario). -->
        <a href="mant_operarios.php" class="tile tile-mant-operarios role-tecnico-only">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none" stroke="#ffffff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <!-- Tres siluetas (operarios) -->
                    <circle cx="30" cy="22" r="7"  fill="#2d4d7a"/>
                    <path  d="M16 50 q14 -14 28 0" fill="#2d4d7a"/>
                    <circle cx="55" cy="20" r="8"  fill="#3a6aa3"/>
                    <path  d="M40 52 q15 -16 30 0" fill="#3a6aa3"/>
                    <circle cx="78" cy="22" r="7"  fill="#5b8cc7"/>
                    <path  d="M64 50 q14 -14 28 0" fill="#5b8cc7"/>
                    <!-- Tick de capacitación -->
                    <path d="M88 12 l-6 6 -3 -3" stroke="#8c181a" stroke-width="3" fill="none"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Gestión de operarios <span class="tile-badge">técnico</span></h2>
                <p>Alta/baja de operarios, puesto y capacitación (25/50/75/100% + Racks). Define quién está habilitado para cada tarea preventiva.</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

        <!-- Calendario laboral: solo rol técnico -->
        <a href="mant_calendario.php" class="tile tile-mant-calendario role-tecnico-only">
            <div class="tile-icon">
                <svg viewBox="0 0 100 60" fill="none">
                    <!-- Cabecera roja del calendario -->
                    <rect x="14" y="8" width="72" height="14" rx="3" fill="#c8102e"/>
                    <rect x="14" y="22" width="72" height="32" rx="0 0 3 3" fill="#fff" stroke="#5b8cc7" stroke-width="1.5"/>
                    <rect x="14" y="50" width="72" height="4" fill="#fff" stroke="#5b8cc7" stroke-width="1.5"/>
                    <!-- Anillas -->
                    <line x1="28" y1="4"  x2="28" y2="14" stroke="#2d4d7a" stroke-width="3" stroke-linecap="round"/>
                    <line x1="72" y1="4"  x2="72" y2="14" stroke="#2d4d7a" stroke-width="3" stroke-linecap="round"/>
                    <!-- Filas de días -->
                    <rect x="20" y="28" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="28" y="28" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="36" y="28" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="44" y="28" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="52" y="28" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="60" y="28" width="6" height="5" fill="#bbf7d0"/>
                    <rect x="68" y="28" width="6" height="5" fill="#fde68a"/>
                    <rect x="20" y="36" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="28" y="36" width="6" height="5" fill="#fde68a"/>
                    <rect x="36" y="36" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="44" y="36" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="52" y="36" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="60" y="36" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="68" y="36" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="20" y="44" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="28" y="44" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="36" y="44" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="44" y="44" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="52" y="44" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="60" y="44" width="6" height="5" fill="#3a6aa3"/>
                    <rect x="68" y="44" width="6" height="5" fill="#3a6aa3"/>
                </svg>
            </div>
            <div class="tile-body">
                <h2>Calendario laboral <span class="tile-badge">técnico</span></h2>
                <p>Define días no laborables extra (puentes, festivos de empresa) o habilita sábados/domingos. Las tareas planificadas se recalculan automáticamente.</p>
            </div>
            <div class="tile-arrow">→</div>
        </a>

    </div>
</main>

<script src="../assets/js/common.js"></script>
<script>initFiltros();</script>
</body>
</html>
