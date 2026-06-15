<?php
/**
 * Propuesta de mejora: sistemas para acelerar y simplificar el registro
 * de tareas preventivas por parte del operario.
 *
 * Documento de presentación para el jefe de mantenimiento. Imprimible
 * desde el botón superior (PDF). Misma estética que el manual técnico
 * y la documentación interna de la app.
 */
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Propuesta · Acelerar registro de tareas preventivas · KH</title>
<style>
    :root {
        --kh-red:        #3a6aa3;
        --kh-black:      #1a1a1a;
        --kh-text:       #1c1c1c;
        --kh-text-soft:  #555;
        --kh-line:       #e2dada;
        --kh-accent:     #fef3f3;
        --kh-blue:       #1a4a7a;
        --kh-blue-soft:  #e9f0f8;
        --kh-green:      #1f8a3c;
        --kh-amber:      #b46300;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: Arial, "Helvetica Neue", sans-serif;
        font-size: 14px;
        color: var(--kh-text);
        background: #f5f3f3;
        line-height: 1.55;
    }
    .toolbar {
        background: var(--kh-black); color: #fff;
        padding: 12px 24px;
        display: flex; justify-content: space-between; align-items: center;
        border-bottom: 3px solid var(--kh-red);
        position: sticky; top: 0; z-index: 10;
    }
    .toolbar .brand { font-weight: 700; letter-spacing: 1px; font-size: 16px; }
    .toolbar .brand span { color: var(--kh-red); }
    .toolbar button {
        background: var(--kh-red); color: #fff; border: none;
        padding: 8px 16px; font-size: 13px; font-weight: 700;
        cursor: pointer; border-radius: 4px; letter-spacing: 0.4px;
    }
    .toolbar button:hover { background: #5b8cc7; }

    .page {
        max-width: 880px; margin: 24px auto;
        background: #fff; padding: 40px 52px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }

    h1 {
        font-size: 26px;
        color: var(--kh-red);
        border-bottom: 2px solid var(--kh-red);
        padding-bottom: 8px;
        margin-bottom: 6px;
    }
    h1 .sub { color: var(--kh-text-soft); font-size: 13px; font-weight: 400; display: block; margin-top: 6px; }

    .resumen {
        background: var(--kh-accent);
        border-left: 5px solid var(--kh-red);
        padding: 14px 18px;
        margin: 24px 0 30px;
        font-size: 14px;
    }
    .resumen strong { color: var(--kh-red); }

    h2 {
        font-size: 19px;
        color: var(--kh-black);
        background: var(--kh-accent);
        border-left: 5px solid var(--kh-red);
        padding: 8px 14px;
        margin: 32px 0 14px;
        page-break-after: avoid;
    }
    h3 {
        font-size: 15.5px;
        color: var(--kh-red);
        margin: 18px 0 8px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        page-break-after: avoid;
    }
    h4 {
        font-size: 14px;
        color: var(--kh-blue);
        margin: 14px 0 6px;
        font-weight: 700;
    }
    p { margin-bottom: 10px; }
    ul, ol { margin: 6px 0 14px 22px; }
    li { margin-bottom: 5px; }
    strong { color: var(--kh-black); }
    em { color: var(--kh-text-soft); font-style: italic; }

    .tag {
        display: inline-block;
        padding: 1px 8px;
        background: var(--kh-red);
        color: #fff;
        font-size: 10.5px;
        font-weight: 700;
        border-radius: 3px;
        letter-spacing: 0.3px;
        text-transform: uppercase;
        vertical-align: middle;
    }
    .tag-blue   { background: var(--kh-blue); }
    .tag-green  { background: var(--kh-green); }
    .tag-amber  { background: var(--kh-amber); }
    .tag-grey   { background: #6b6b6b; }

    table.summary {
        width: 100%;
        border-collapse: collapse;
        margin: 12px 0 16px;
        font-size: 13px;
    }
    table.summary th, table.summary td {
        border: 1px solid var(--kh-line);
        padding: 8px 11px;
        text-align: left;
        vertical-align: top;
    }
    table.summary th {
        background: var(--kh-black);
        color: #fff;
        font-weight: 600;
    }
    table.summary td:first-child {
        font-weight: 600;
        background: var(--kh-accent);
        width: 32%;
    }
    table.summary.full td:first-child { background: transparent; font-weight: 400; width: auto; }

    .note {
        background: #fff8e1;
        border-left: 4px solid #f59e0b;
        padding: 9px 14px;
        margin: 14px 0;
        font-size: 13px;
    }
    .note strong { color: #92400e; }

    .info {
        background: var(--kh-blue-soft);
        border-left: 4px solid var(--kh-blue);
        padding: 9px 14px;
        margin: 14px 0;
        font-size: 13px;
    }
    .info strong { color: var(--kh-blue); }

    .toc {
        background: var(--kh-accent);
        border: 1px solid var(--kh-line);
        padding: 14px 20px;
        margin-bottom: 28px;
        border-radius: 4px;
    }
    .toc strong { display: block; margin-bottom: 6px; color: var(--kh-red); font-size: 14px; }
    .toc ol { margin-left: 22px; }
    .toc a { color: var(--kh-red); text-decoration: none; font-weight: 600; }
    .toc a:hover { text-decoration: underline; }

    /* Tarjeta de propuesta individual */
    .prop {
        border: 1px solid var(--kh-line);
        border-left: 5px solid var(--kh-red);
        border-radius: 6px;
        padding: 16px 20px;
        margin: 14px 0;
        background: #fafafa;
    }
    .prop-title {
        font-size: 15px;
        font-weight: 700;
        color: var(--kh-black);
        margin-bottom: 4px;
    }
    .prop-letra {
        display: inline-block;
        width: 26px; height: 26px;
        line-height: 26px;
        background: var(--kh-red);
        color: #fff;
        border-radius: 50%;
        text-align: center;
        font-weight: 800;
        margin-right: 8px;
        font-size: 13px;
    }
    .prop-meta {
        display: flex;
        gap: 14px;
        font-size: 12px;
        color: var(--kh-text-soft);
        margin-top: 8px;
        flex-wrap: wrap;
    }
    .prop-meta b { color: var(--kh-black); }
    .prop p { font-size: 13.5px; margin-bottom: 6px; }

    /* Banner de "fase" */
    .fase-banner {
        background: linear-gradient(135deg, var(--kh-black) 0%, #2a2a2a 100%);
        color: #fff;
        padding: 12px 18px;
        border-radius: 6px;
        margin: 22px 0 14px;
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .fase-num {
        width: 36px; height: 36px;
        background: var(--kh-red);
        color: #fff;
        border-radius: 50%;
        display: grid;
        place-items: center;
        font-weight: 800;
        font-size: 17px;
        flex-shrink: 0;
    }
    .fase-titulo { font-size: 16px; font-weight: 700; }
    .fase-sub    { font-size: 12px; opacity: 0.75; margin-top: 1px; }

    /* Cifras destacadas */
    .stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        margin: 16px 0 22px;
    }
    .stat-card {
        background: var(--kh-card, #fff);
        border: 1px solid var(--kh-line);
        border-top: 4px solid var(--kh-red);
        padding: 14px 16px;
        border-radius: 6px;
        text-align: center;
    }
    .stat-num {
        font-size: 26px;
        font-weight: 800;
        color: var(--kh-red);
        line-height: 1.1;
    }
    .stat-lbl {
        font-size: 11px;
        color: var(--kh-text-soft);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 700;
        margin-top: 4px;
    }

    footer.foot {
        text-align: center;
        color: var(--kh-text-soft);
        font-size: 11px;
        padding: 22px;
        margin-top: 14px;
    }

    @media print {
        body { background: #fff; font-size: 11pt; }
        .toolbar { display: none !important; }
        .page { box-shadow: none; margin: 0; padding: 0; max-width: none; }
        h2, h3, h4 { page-break-after: avoid; }
        .prop, .note, .info, table.summary, .stats { page-break-inside: avoid; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <div class="brand">KH <span>·</span> Propuesta de mejora · Mantenimiento</div>
    <button type="button" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
</div>

<main class="page">

<h1>
    Propuesta · Acelerar y simplificar el registro de tareas preventivas
    <span class="sub">Sistemas para reducir el tiempo del operario y mejorar la fiabilidad del cumplimiento · Documento de presentación al jefe de mantenimiento</span>
</h1>

<div class="resumen">
    <strong>En una frase:</strong> Conjunto de mejoras incrementales —de coste cero a inversión moderada—
    que reducen el tiempo del operario en el registro de revisiones preventivas y elevan la fiabilidad
    del % de cumplimiento, sin alterar el método de trabajo actual.
</div>

<div class="toc">
    <strong>Contenido</strong>
    <ol>
        <li><a href="#situacion">Situación actual y oportunidad</a></li>
        <li><a href="#objetivo">Objetivo de la propuesta</a></li>
        <li><a href="#fase1">Fase 1 · Mejoras de uso sin hardware (coste cero)</a></li>
        <li><a href="#fase2">Fase 2 · Optimización del flujo (cambios estructurales)</a></li>
        <li><a href="#fase3">Fase 3 · Visión con hardware (inversión)</a></li>
        <li><a href="#beneficios">Beneficios esperados</a></li>
        <li><a href="#riesgos">Riesgos y mitigaciones</a></li>
        <li><a href="#recomendacion">Recomendación y próximos pasos</a></li>
    </ol>
</div>

<!-- ──────────────────────────────────────────────────────── -->
<h2 id="situacion">1. Situación actual y oportunidad</h2>

<p>Hoy, cuando un operario realiza una revisión preventiva, el flujo de registro es el siguiente:</p>

<ol>
    <li>Acceder a la aplicación móvil con su número.</li>
    <li>Buscar la máquina en la lista.</li>
    <li>Buscar la tarea concreta entre las pendientes.</li>
    <li>Pulsar para abrir el detalle.</li>
    <li>Iniciar el cronómetro, ejecutar la revisión, finalizar.</li>
    <li>Confirmar y volver a empezar con la siguiente.</li>
</ol>

<p>Este proceso, repetido decenas de veces al día, genera <strong>tres tipos de fricciones</strong> que
restan tiempo productivo y comprometen la fiabilidad del % de cumplimiento:</p>

<ul>
    <li><strong>Tiempo perdido</strong> buscando la tarea correcta entre las muchas que aparecen.</li>
    <li><strong>Olvidos al cierre de turno</strong>: si el operario termina la jornada sin volver a la aplicación,
        hay tareas realizadas que no llegan a marcarse.</li>
    <li><strong>Resistencia al uso</strong>: cuanto más cuesta marcar, más se posterga; el dato de cumplimiento
        en pantalla deja de reflejar la realidad operativa.</li>
</ul>

<p>La buena noticia es que <strong>cualquier reducción del tiempo de marcado mejora dos indicadores
a la vez</strong>: menos horas perdidas en burocracia y mayor exactitud del cumplimiento real.</p>

<!-- ──────────────────────────────────────────────────────── -->
<h2 id="objetivo">2. Objetivo de la propuesta</h2>

<p>Reducir el tiempo medio de marcado de una revisión preventiva por debajo de los <strong>5 segundos</strong>
(actualmente, entre 15 y 25 segundos por marcado, según la complejidad de la tarea), conservando:</p>

<ul>
    <li>La trazabilidad completa de quién hizo qué, cuándo y cuánto tardó.</li>
    <li>El método de trabajo y la responsabilidad actual de cada operario.</li>
    <li>El flujo manual disponible como respaldo en cualquier escenario.</li>
</ul>

<p>La propuesta se estructura en <strong>tres fases independientes</strong>. Cada fase aporta valor por sí
sola y puede aprobarse de forma aislada.</p>

<!-- ──────────────────────────────────────────────────────── -->
<h2 id="fase1">3. Fase 1 · Mejoras de uso sin hardware</h2>

<div class="fase-banner">
    <div class="fase-num">1</div>
    <div>
        <div class="fase-titulo">Quick wins · sin inversión, alto impacto</div>
        <div class="fase-sub">Cuatro mejoras de la app móvil · ~5 días de desarrollo · ningún hardware nuevo</div>
    </div>
</div>

<p>Cuatro cambios pequeños pero acumulativos en la app móvil del operario. Recortan aproximadamente
el 50&nbsp;% del tiempo total de marcado sin requerir hardware ni negociación con dirección.</p>

<div class="prop">
    <div class="prop-title"><span class="prop-letra">A</span> Visita completa por máquina</div>
    <p>Al abrir la ficha de una máquina, un botón único <strong>"He hecho todas las pendientes
    de esta máquina"</strong> marca de golpe todas sus tareas pendientes con la hora actual. Si una
    concreta no se hizo, el operario solo desmarca esa una.</p>
    <div class="prop-meta">
        <div><b>Ganancia:</b> 6 toques → 1 toque por máquina</div>
        <div><b>Esfuerzo:</b> 1 día</div>
        <div><b>Riesgo:</b> Cero · es opcional, el flujo individual sigue disponible</div>
    </div>
</div>

<div class="prop">
    <div class="prop-title"><span class="prop-letra">B</span> Filtrado por operario asignado</div>
    <p>Hoy todos los operarios ven todas las tareas pendientes de la planta. Añadiendo un campo
    <em>operario asignado</em> a cada máquina (gestionado por el responsable de mantenimiento desde el
    panel <em>Acciones por Máquina</em>), cada operario ve únicamente <strong>su parte del trabajo</strong>.
    La lista se reduce drásticamente y elimina ruido visual.</p>
    <div class="prop-meta">
        <div><b>Ganancia:</b> Lista 5-10 × más corta, sin scroll</div>
        <div><b>Esfuerzo:</b> 2 días</div>
        <div><b>Riesgo:</b> Bajo · si una asignación es incorrecta, el responsable lo recoloca al instante</div>
    </div>
</div>

<div class="prop">
    <div class="prop-title"><span class="prop-letra">C</span> Cierre de turno por lote</div>
    <p>Nueva pantalla "Cierre de turno" con la lista del día y todas las tareas marcadas por defecto
    como hechas. El operario simplemente <strong>desmarca las que no realizó</strong> y confirma con un
    único botón. Captura las marcas que de otra forma se olvidarían al final del turno.</p>
    <div class="prop-meta">
        <div><b>Ganancia:</b> 30 s por tarea → 30 s en total para todo el turno</div>
        <div><b>Esfuerzo:</b> 1 día</div>
        <div><b>Riesgo:</b> Cero · es una alternativa al flujo tarea-a-tarea, no lo sustituye</div>
    </div>
</div>

<div class="prop">
    <div class="prop-title"><span class="prop-letra">D</span> Cronómetro automático al abrir la tarea</div>
    <p>El cronómetro arranca solo al abrir la ficha de una tarea, sin necesidad de pulsar "Iniciar".
    Pausa, reanudación y cancelación siguen disponibles igual. Un toque menos por tarea.</p>
    <div class="prop-meta">
        <div><b>Ganancia:</b> Pequeña pero acumulativa</div>
        <div><b>Esfuerzo:</b> Media jornada</div>
        <div><b>Riesgo:</b> Cero</div>
    </div>
</div>

<table class="summary">
    <tr><th>Total Fase 1</th><th>Esfuerzo</th><th>Coste</th><th>Tiempo ahorrado estimado</th></tr>
    <tr>
        <td>4 mejoras incrementales</td>
        <td>~5 días de desarrollo</td>
        <td>Ninguno · solo horas internas</td>
        <td>50&nbsp;% del tiempo actual de marcado</td>
    </tr>
</table>

<!-- ──────────────────────────────────────────────────────── -->
<h2 id="fase2">4. Fase 2 · Optimización del flujo</h2>

<div class="fase-banner">
    <div class="fase-num">2</div>
    <div>
        <div class="fase-titulo">Cambios estructurales · valor adicional notable</div>
        <div class="fase-sub">Para considerar tras consolidar la Fase 1 · sigue sin requerir hardware</div>
    </div>
</div>

<div class="prop">
    <div class="prop-title"><span class="prop-letra">E</span> Modo "ruta optimizada"</div>
    <p>Al iniciar el turno, la app calcula el <strong>orden óptimo de las máquinas a visitar</strong> según
    su ubicación física (codificada en el catálogo). El operario sigue un recorrido lógico que minimiza
    desplazamientos en lugar de zigzaguear por la planta.</p>
    <div class="prop-meta">
        <div><b>Ganancia:</b> 10-15&nbsp;% menos de tiempo total por turno por reducción de trayectos</div>
        <div><b>Esfuerzo:</b> 3-4 días · requiere modelar zonas en el catálogo de máquinas</div>
        <div><b>Riesgo:</b> Bajo · la ruta es una sugerencia, el operario puede ignorarla</div>
    </div>
</div>

<div class="prop">
    <div class="prop-title"><span class="prop-letra">F</span> Modo offline + sincronización</div>
    <p>La app guarda los marcados en local cuando no hay cobertura WiFi y los sincroniza cuando vuelve la red.
    Útil en zonas con cobertura intermitente (sótanos, esquinas mal cubiertas). <strong>Hoy, si el operario
    pierde cobertura, no puede marcar la revisión en el momento.</strong></p>
    <div class="prop-meta">
        <div><b>Ganancia:</b> Cero pérdida de trabajo · marca en tiempo real aunque falle la red</div>
        <div><b>Esfuerzo:</b> 3-4 días</div>
        <div><b>Riesgo:</b> Bajo · requiere validar la sincronización en pruebas reales</div>
    </div>
</div>

<div class="prop">
    <div class="prop-title"><span class="prop-letra">G</span> Notificaciones push proactivas</div>
    <p>Cuando una tarea entra en plazo crítico, el sistema envía un aviso al móvil del operario asignado
    con dos botones: <em>"Hecho"</em> y <em>"Programar para mañana"</em>. <strong>Permite marcar sin
    siquiera entrar en la app</strong>.</p>
    <div class="prop-meta">
        <div><b>Ganancia:</b> Cero olvidos en tareas críticas</div>
        <div><b>Esfuerzo:</b> 4-5 días</div>
        <div><b>Riesgo:</b> Medio · requiere permisos en cada dispositivo y servidor de notificaciones</div>
    </div>
</div>

<!-- ──────────────────────────────────────────────────────── -->
<h2 id="fase3">5. Fase 3 · Visión con hardware</h2>

<div class="fase-banner">
    <div class="fase-num">3</div>
    <div>
        <div class="fase-titulo">Alternativas con inversión · transforman radicalmente el flujo</div>
        <div class="fase-sub">Opciones a considerar si las fases 1 y 2 no son suficientes · todas evitables y opcionales</div>
    </div>
</div>

<p>Estas opciones requieren inversión en hardware. Se presentan como <strong>visión a futuro</strong>
para que se contemple su valor; ninguna sustituye al método actual, todas lo complementan.</p>

<div class="prop">
    <div class="prop-title"><span class="prop-letra">H</span> Tags NFC pegados en la máquina</div>
    <p>Pequeños círculos plásticos con un chip NFC pegados a cada máquina. El operario <strong>acerca
    el móvil al tag</strong> y la app abre directamente las tareas de esa máquina. Sin cámara, sin
    foto, sin app abierta previamente. Resistentes a aceite, suciedad y limpiezas industriales.</p>
    <div class="prop-meta">
        <div><b>Coste:</b> ~0,30 €/tag · ~30 € total para 100 máquinas</div>
        <div><b>Esfuerzo:</b> 2 días de desarrollo + pegado físico</div>
        <div><b>Riesgo:</b> Bajo en Android · en iPhone hay limitaciones según versión</div>
    </div>
</div>

<div class="prop">
    <div class="prop-title"><span class="prop-letra">I</span> Beacons BLE de proximidad</div>
    <p>Emisores Bluetooth de bajo consumo en cada zona. La app <strong>detecta automáticamente</strong>
    cuando el operario se acerca a una máquina y le sugiere sus tareas. No hay que escanear nada;
    el sistema se "anticipa".</p>
    <div class="prop-meta">
        <div><b>Coste:</b> ~10-20 €/beacon · batería 1-2 años</div>
        <div><b>Esfuerzo:</b> 5-6 días</div>
        <div><b>Riesgo:</b> Medio · requiere mantener baterías y calibrar zonas</div>
    </div>
</div>

<div class="prop">
    <div class="prop-title"><span class="prop-letra">J</span> Botón físico industrial junto a la máquina</div>
    <p>Un botón Wi-Fi/LoRa pegado a la máquina con etiqueta <em>"Revisión hecha"</em>. Un solo
    pulsado registra la tarea automáticamente. <strong>Sin móvil, sin app, sin entrar a nada</strong>.
    Especialmente eficaz para tareas diarias o muy frecuentes.</p>
    <div class="prop-meta">
        <div><b>Coste:</b> ~30-40 €/botón</div>
        <div><b>Esfuerzo:</b> 1 semana de integración</div>
        <div><b>Limitación:</b> Solo para tareas de marcado simple · sin observaciones</div>
    </div>
</div>

<div class="prop">
    <div class="prop-title"><span class="prop-letra">K</span> Asistente por voz</div>
    <p>El operario dice <em>"He hecho la mensual de la BUCH GRANDE"</em> y un sistema de
    reconocimiento de voz identifica la máquina y la tarea, y la marca. Útil con manos ocupadas
    o sucias.</p>
    <div class="prop-meta">
        <div><b>Coste:</b> Coste por consulta (~0,001 €/marcado)</div>
        <div><b>Esfuerzo:</b> 1-2 semanas</div>
        <div><b>Riesgo:</b> Alto · el ruido industrial puede degradar la precisión</div>
    </div>
</div>

<div class="prop">
    <div class="prop-title"><span class="prop-letra">L</span> Pantalla compartida por zona</div>
    <p>Un panel táctil industrial en cada zona de la planta. Cualquier operario, con un toque al icono
    de su máquina más su código personal, registra la revisión. Independiente del móvil de cada uno
    y accesible a todos.</p>
    <div class="prop-meta">
        <div><b>Coste:</b> 200-400 €/pantalla</div>
        <div><b>Esfuerzo:</b> 1 semana de desarrollo + instalación</div>
        <div><b>Limitación:</b> Mantenimiento del hardware</div>
    </div>
</div>

<!-- ──────────────────────────────────────────────────────── -->
<h2 id="beneficios">6. Beneficios esperados</h2>

<p>Aplicando exclusivamente la <strong>Fase 1</strong>, las cifras estimadas son:</p>

<div class="stats">
    <div class="stat-card">
        <div class="stat-num">~50&nbsp;%</div>
        <div class="stat-lbl">Reducción del tiempo de marcado</div>
    </div>
    <div class="stat-card">
        <div class="stat-num">~10 min</div>
        <div class="stat-lbl">Ahorro estimado por operario y turno</div>
    </div>
    <div class="stat-card">
        <div class="stat-num">+5-10 pts</div>
        <div class="stat-lbl">Mejora del % cumplimiento real</div>
    </div>
</div>

<p>El ahorro de 10 minutos por operario y turno, con la plantilla actual, equivale a una
<strong>liberación de aproximadamente 70-80 horas/año</strong> que se reinvierten directamente en
actividad productiva. La mejora del cumplimiento real es a coste cero porque proviene de eliminar
olvidos y posposiciones.</p>

<p>Añadiendo la <strong>Fase 2</strong> (especialmente la ruta optimizada y el modo offline), el ahorro
podría llegar al <strong>60-65&nbsp;%</strong> del tiempo actual.</p>

<p>La <strong>Fase 3</strong> permitiría bajar el marcado a menos de <strong>3 segundos por revisión</strong>
en los casos cubiertos por NFC, beacons o botón físico, alcanzando un nivel próximo a la marca
automática.</p>

<!-- ──────────────────────────────────────────────────────── -->
<h2 id="riesgos">7. Riesgos y mitigaciones</h2>

<table class="summary">
    <tr><th>Riesgo</th><th>Mitigación</th></tr>
    <tr>
        <td>Resistencia del operario al cambio de flujo</td>
        <td>Mantener el flujo individual actual disponible. Las nuevas opciones son alternativas, no obligaciones. Pilotaje con 1-2 operarios voluntarios.</td>
    </tr>
    <tr>
        <td>Filtro por operario asignado incorrecto deja tareas sin asignar</td>
        <td>El responsable de mantenimiento mantiene la asignación. La aplicación avisa de tareas sin asignar en una sección específica visible para él.</td>
    </tr>
    <tr>
        <td>El "marcado por lote" induce a confirmar sin haberlo hecho</td>
        <td>El control sigue siendo responsabilidad del operario. Tarea de auditoría: muestreo aleatorio mensual por el responsable.</td>
    </tr>
    <tr>
        <td>Pérdida de la cobertura WiFi en planta</td>
        <td>Fase 2 — modo offline. Hasta entonces, el operario marca al recuperar señal con la fecha de la intervención correcta.</td>
    </tr>
    <tr>
        <td>Hardware (Fase 3) con coste o mantenimiento no asumibles</td>
        <td>Fase 3 es opcional. Las fases 1 y 2 ya aportan el grueso de la mejora sin hardware. Pilotaje en una zona piloto antes de extender.</td>
    </tr>
</table>

<!-- ──────────────────────────────────────────────────────── -->
<h2 id="recomendacion">8. Recomendación y próximos pasos</h2>

<div class="info">
    <strong>Recomendación:</strong> <strong>aprobar la Fase 1 completa</strong>. Cinco días de trabajo
    de desarrollo interno, sin hardware ni inversión externa, con un impacto medible inmediato.
    Las fases 2 y 3 quedan en cartera para activarse según necesidad y disponibilidad.
</div>

<p>Plan sugerido para los próximos dos meses:</p>

<ol>
    <li><strong>Semana 1-2:</strong> Aprobación de la Fase 1 y arranque del desarrollo.</li>
    <li><strong>Semana 3:</strong> Despliegue piloto con 2 operarios voluntarios.</li>
    <li><strong>Semana 4-5:</strong> Recolección de feedback, ajustes y despliegue al resto del equipo.</li>
    <li><strong>Semana 6:</strong> Medición real del ahorro de tiempo y del % cumplimiento.</li>
    <li><strong>Semana 7-8:</strong> Decisión sobre la Fase 2 a la vista de los resultados.</li>
</ol>

<p>Las cifras concretas obtenidas en el piloto se trasladarán al jefe de mantenimiento como informe
de cierre de la Fase 1, de modo que la decisión sobre la Fase 2 se tome con datos reales, no con
estimaciones.</p>

<div class="note">
    <strong>Resultado esperado del piloto:</strong> demostrar un ahorro medible de tiempo
    por encima del 40&nbsp;% y una mejora del cumplimiento real de al menos 5 puntos en las
    máquinas asignadas a los operarios piloto. Si los resultados quedan por debajo, la Fase 1
    se mantiene activa pero no se activa la Fase 2 hasta replantear.
</div>

<footer class="foot">
    Documento de propuesta interna · KH Mantenimiento · <?= date('d/m/Y') ?>
</footer>

</main>
</body>
</html>
