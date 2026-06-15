<?php
/**
 * Manual de usuario abreviado · imprimible / exportable a PDF.
 *
 * Se abre en pestaña nueva desde el botón "📖 Manual" de la cabecera.
 * Lleva su propio CSS minimalista para que sea legible y se imprima
 * limpiamente (window.print() → guardar como PDF).
 */
require_once __DIR__ . '/../lib/Auth.php';
// El manual es público dentro de la app — basta con que esté logueado.
Auth::requireLogin();
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manual de usuario · KH Mantenimiento</title>
<style>
    :root {
        --kh-red: #3a6aa3;
        --kh-black: #1a1a1a;
        --kh-bg: #ffffff;
        --kh-text: #1c1c1c;
        --kh-text-soft: #555;
        --kh-line: #e2dada;
        --kh-accent: #fef3f3;
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
        background: var(--kh-black);
        color: #fff;
        padding: 12px 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 3px solid var(--kh-red);
        position: sticky;
        top: 0;
        z-index: 10;
    }
    .toolbar .brand { font-weight: 700; letter-spacing: 1px; font-size: 16px; }
    .toolbar .brand span { color: var(--kh-red); }
    .toolbar button {
        background: var(--kh-red);
        color: #fff;
        border: none;
        padding: 8px 16px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        border-radius: 4px;
        letter-spacing: 0.4px;
    }
    .toolbar button:hover { background: #5b8cc7; }

    .page {
        max-width: 840px;
        margin: 24px auto;
        background: #fff;
        padding: 36px 48px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    }
    h1 {
        font-size: 24px;
        color: var(--kh-red);
        border-bottom: 2px solid var(--kh-red);
        padding-bottom: 8px;
        margin-bottom: 18px;
    }
    h1 .sub { color: var(--kh-text-soft); font-size: 13px; font-weight: 400; display: block; margin-top: 4px; }
    h2 {
        font-size: 17px;
        color: var(--kh-black);
        background: var(--kh-accent);
        border-left: 4px solid var(--kh-red);
        padding: 6px 12px;
        margin: 26px 0 10px;
    }
    h3 {
        font-size: 14px;
        color: var(--kh-red);
        margin: 14px 0 4px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    p { margin-bottom: 8px; }
    ul, ol { margin: 6px 0 12px 22px; }
    li { margin-bottom: 4px; }
    code, kbd {
        background: #f0eded;
        padding: 1px 6px;
        border-radius: 3px;
        font-family: Consolas, monospace;
        font-size: 12px;
    }
    .tag {
        display: inline-block;
        padding: 1px 6px;
        background: var(--kh-red);
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        border-radius: 3px;
        letter-spacing: 0.3px;
        text-transform: uppercase;
    }
    .tag-amber { background: #f59e0b; }
    .tag-green { background: #1f8a3c; }
    .tag-grey  { background: #6b6b6b; }

    table.summary {
        width: 100%;
        border-collapse: collapse;
        margin: 10px 0 14px;
        font-size: 13px;
    }
    table.summary th, table.summary td {
        border: 1px solid var(--kh-line);
        padding: 6px 10px;
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
        width: 30%;
        background: var(--kh-accent);
    }

    .note {
        background: #fff8e1;
        border-left: 4px solid #f59e0b;
        padding: 8px 12px;
        margin: 10px 0;
        font-size: 13px;
    }
    .note strong { color: #92400e; }

    .toc {
        background: var(--kh-accent);
        border: 1px solid var(--kh-line);
        padding: 12px 18px;
        margin-bottom: 26px;
        border-radius: 4px;
    }
    .toc ol { margin-left: 18px; }
    .toc a { color: var(--kh-red); text-decoration: none; }
    .toc a:hover { text-decoration: underline; }

    footer.foot {
        text-align: center;
        color: var(--kh-text-soft);
        font-size: 11px;
        padding: 20px;
        margin-top: 12px;
    }

    /* Impresión */
    @media print {
        body { background: #fff; font-size: 11pt; }
        .toolbar, .toc-print-hide { display: none !important; }
        .page {
            box-shadow: none;
            margin: 0;
            padding: 0;
            max-width: none;
        }
        h2 { page-break-after: avoid; }
        h3 { page-break-after: avoid; }
        table.summary, .note { page-break-inside: avoid; }
    }
</style>
</head>
<body>

<div class="toolbar toc-print-hide">
    <div class="brand">KH <span>·</span> Manual de Mantenimiento</div>
    <button type="button" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
</div>

<main class="page">

<h1>
    Manual de usuario · Plan de Mantenimiento Preventivo
    <span class="sub">Versión resumida — acciones más importantes</span>
</h1>

<div class="toc toc-print-hide">
    <strong>Contenido</strong>
    <ol>
        <li><a href="#menu">Menú principal</a></li>
        <li><a href="#acciones">Acciones por Máquina</a></li>
        <li><a href="#proximas">Próximas Revisiones</a></li>
        <li><a href="#cumplimiento">Cumplimiento Preventivo</a></li>
        <li><a href="#historico">Histórico</a></li>
        <li><a href="#movil">App móvil del operario</a></li>
    </ol>
</div>

<h2 id="menu">1. Menú principal · "Mantenimiento Preventivo"</h2>
<p>Tras el login se accede a un panel con tiles para cada módulo:</p>
<ul>
    <li><strong>Acciones por Máquina</strong>: catálogo de máquinas y sus tareas.</li>
    <li><strong>Próximas Revisiones</strong>: calendario de tareas pendientes y vencidas.</li>
    <li><strong>Cumplimiento Preventivo</strong>: gauge + barras mensuales con el % de tareas hechas.</li>
    <li><strong>Histórico</strong>: registro completo de intervenciones.</li>
    <li><strong>Móvil · Operario</strong> <span class="tag tag-amber">En proceso</span>: prototipo de la app móvil.</li>
</ul>

<h2 id="acciones">2. Acciones por Máquina</h2>
<p>Listado de todas las máquinas con su número de tareas preventivas asociadas. Puedes buscar una máquina por nombre o código en el cuadro superior.</p>

<h3>Estado de una máquina</h3>
<ul>
    <li><span class="tag tag-grey">ACTIVA</span> revisiones planificadas con normalidad.</li>
    <li><span class="tag" style="background:#f59e0b">⏸ PAUSADA</span> sin mantenimiento aplicado; sus revisiones no se planifican mientras esté pausada.</li>
</ul>

<h3>Acciones más usadas (solo Técnico)</h3>
<ul>
    <li><strong>Crear máquina</strong>: botón <code>+ Crear nueva máquina</code> arriba del listado.</li>
    <li><strong>Editar / eliminar tareas</strong>: clic en la máquina → se abre la modal con todas sus tareas preventivas y los botones de acción por fila.</li>
    <li><strong>Pausar / reanudar</strong>: dentro de una tarea (o de un grupo, si tiene varias sub-tareas) con los botones ⏸ Pausar / ▶ Reanudar.</li>
    <li><strong>Borrar máquina</strong>: dentro de la modal de tareas, botón rojo al pie. Pide confirmación con el impacto (cuántas tareas y marcas se borrarán).</li>
</ul>

<h2 id="proximas">3. Próximas Revisiones</h2>
<p>Calendario de tareas con filtros y exportación.</p>

<h3>Filtros</h3>
<ul>
    <li><strong>Desde / Hasta</strong>: rango de fechas que se muestra.</li>
    <li><strong>Máquina</strong>: limita a una máquina concreta.</li>
    <li><strong>Periodicidad</strong>: solo SEMANAL, MENSUAL, TRIMESTRAL, etc.</li>
    <li><strong>Solo vencidas</strong>: muestra únicamente las que han pasado de plazo.</li>
</ul>

<h3>Estados</h3>
<ul>
    <li><span class="tag" style="background:#d24a4a">VENCIDA</span> superó el margen de tolerancia (3 días para semanal, 7 días para mensual, hasta 30 días para anual).</li>
    <li><span class="tag tag-amber">PRÓXIMA</span> vence en los próximos 10 días (aún en plazo).</li>
    <li><span class="tag tag-green">EN PLAZO</span> tiene más de 7 días.</li>
</ul>

<h3>Marcar una revisión como hecha</h3>
<ol>
    <li>Pulsar el botón <code>✓ Marcar</code> en la fila de la tarea.</li>
    <li>Elegir tipo: <em>completada</em> (por defecto) o <em>no realizada</em> (con motivo).</li>
    <li>Indicar fecha de intervención, hora, operario (dropdown con nombres) y observaciones.</li>
    <li>Si la revisión incluye varias sub-tareas, marcar las que se hicieron. Si no se hicieron todas, queda etiquetada como <strong>INCOMPLETA</strong>.</li>
    <li>Pulsar <code>✓ Marcar como hecha</code>. La tarea desaparece del listado y queda registrada en el histórico.</li>
</ol>

<h3>Descargar el calendario</h3>
<p>Botones verde (<strong>XLSX</strong>) y rojo (<strong>PDF</strong>) en la cabecera. El archivo contiene solo las vencidas + próximas con el rango y filtros activos, encabezado <em>F12028_Seguimiento y asignación tareas de mantenimiento</em>.</p>

<h2 id="cumplimiento">4. Cumplimiento Preventivo</h2>
<p>Indicador de qué porcentaje de las revisiones programadas se han hecho.</p>

<h3>Lo que muestra</h3>
<ul>
    <li><strong>Gauge central</strong>: % del mes seleccionado.</li>
    <li><strong>Barras mensuales</strong>: histórico por mes en una franja de 12 meses.</li>
    <li><strong>Detalle del mes</strong>: clic en una barra para ver tareas completadas, no realizadas y recuperaciones.</li>
</ul>

<h3>Fórmula</h3>
<p><code>% cumplimiento = revisiones realizadas / revisiones programadas × 100</code></p>
<p>Cuando una tarea no realizada se hace al mes siguiente, cuenta como <strong>recuperación</strong> en el mes en que finalmente se hizo. Puede llevar a un % superior al 100% ese mes.</p>

<h3>Exportar</h3>
<p>Botones <strong>XLSX</strong> y <strong>PDF</strong> arriba a la derecha. El informe lleva el gauge global y la tabla mensual con todos los desgloses.</p>

<h2 id="historico">5. Histórico</h2>
<p>Registro cronológico de todas las intervenciones realizadas.</p>

<h3>Filtros</h3>
<ul>
    <li><strong>Desde / Hasta</strong>: por defecto desde el 1 de enero del año en curso hasta hoy.</li>
    <li><strong>Máquina</strong>, <strong>Operario</strong>, <strong>Periodicidad</strong>.</li>
</ul>

<h3>Información por línea</h3>
<p>Fecha, hora, operario, máquina, tarea, duración real, badges:
    <span class="tag tag-green">REALIZADA</span>
    <span class="tag" style="background:#d24a4a">PENDIENTE</span>
    <span class="tag" style="background:#5b3fb8">RECUPERADA</span>
    <span class="tag tag-amber">INCOMPLETA</span>
</p>

<h3>Edición (solo Técnico)</h3>
<p>Clic en una fila del histórico → popup con los campos editables (fecha, hora, operario, duración, motivo si fue no realizada, marca incompleta).</p>

<h2 id="movil">6. App móvil del operario · BETA</h2>
<p>Disponible como prototipo en el tile <strong>Móvil · Operario</strong> con badge <span class="tag tag-amber">En proceso de aplicación</span>. Flujo previsto:</p>
<ol>
    <li>El operario introduce su número en el teclado táctil.</li>
    <li>Ve solo sus tareas vencidas y de hoy.</li>
    <li>Pulsa la tarea → arranca un cronómetro.</li>
    <li>Marca las sub-tareas hechas y pulsa <em>Marcar como realizada</em>.</li>
    <li>La intervención queda registrada con su número, fecha, hora y duración real.</li>
</ol>

<footer class="foot">
    Manual de usuario · KH Plan Attainment · Mantenimiento Preventivo<br>
    Para detalles avanzados, consulte con el técnico o use el botón ? de cada vista.
</footer>

</main>

</body>
</html>
