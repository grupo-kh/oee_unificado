<?php
$pageTitle   = 'Histórico de Rendimiento';
$backLink    = 'oee_unificado.php';
$hideFiltros = true;
include __DIR__ . '/../includes/header.php';
?>

<main class="view-main ref-hist-view">
    <div class="view-card">
        <div class="view-card-header">
            <h2>Histórico de Rendimiento <small class="ref-hist-h-sub">— periodo y referencia independientes (máx. 1 año)</small></h2>
            <div class="ref-hist-export-row">
                <button type="button" id="btn-ref-hist-xlsx" class="oee-export-btn" title="Exportar histórico de la referencia a Excel" disabled>&#x2B07; Histórico XLSX</button>
                <button type="button" id="btn-ref-hist-pdf"  class="oee-export-btn" title="Exportar histórico de la referencia a PDF"   disabled>&#x2B07; Histórico PDF</button>
            </div>
        </div>

        <div class="view-card-body">

            <!-- ───── Bloque 1: histórico agrupado por OF (totales sin fecha) ───── -->
            <div class="ref-hist-block">
                <div class="ref-hist-filtros">
                    <div class="ref-hist-row">
                        <label for="ref-hist-select" class="machine-selector-label">Referencia:</label>
                        <select id="ref-hist-select" class="machine-selector ref-hist-select">
                            <option value="">— Cargando referencias… —</option>
                        </select>
                        <input type="search" id="ref-hist-search" class="ref-hist-search" placeholder="Filtrar lista…" autocomplete="off" title="Filtra el desplegable por código o descripción">
                        <label class="ref-hist-multi-toggle" title="Mostrar solo referencias que se han fabricado en más de una máquina">
                            <input type="checkbox" id="ref-hist-multi-cb">
                            <span>Solo multi-máquina <span id="ref-hist-multi-count" class="ref-hist-multi-count">0</span></span>
                        </label>
                        <span id="ref-hist-scope" class="ref-hist-scope" title="Solo se listan referencias con producción en este periodo">Solo referencias con producción en el último año</span>
                    </div>

                    <div class="ref-hist-row">
                        <label for="ref-hist-desde" class="machine-selector-label">Desde:</label>
                        <input type="date" id="ref-hist-desde" class="machine-selector" style="min-width:150px">
                        <label for="ref-hist-hasta" class="machine-selector-label">Hasta:</label>
                        <input type="date" id="ref-hist-hasta" class="machine-selector" style="min-width:150px">

                        <span class="machine-selector-label">Rango:</span>
                        <button type="button" class="range-quick ref-hist-quick" data-range="month">Mes</button>
                        <button type="button" class="range-quick ref-hist-quick" data-range="3months">3 meses</button>
                        <button type="button" class="range-quick ref-hist-quick" data-range="6months">6 meses</button>
                        <button type="button" class="range-quick ref-hist-quick" data-range="year">1 año</button>
                    </div>
                </div>

                <div id="ref-hist-resumen" class="ref-hist-resumen" style="display:none">
                    <span class="ref-hist-resumen-item"><strong>OFs:</strong> <span id="ref-hist-tot-ofs">0</span></span>
                    <span class="ref-hist-resumen-item"><strong>Máquinas:</strong> <span id="ref-hist-tot-maqs">0</span></span>
                    <span class="ref-hist-resumen-item"><strong>Días con producción:</strong> <span id="ref-hist-tot-dias">0</span></span>
                    <span class="ref-hist-resumen-item ref-hist-resumen-ok"><strong>Total OK:</strong> <span id="ref-hist-tot-ok">0</span></span>
                    <span class="ref-hist-resumen-item ref-hist-resumen-nok"><strong>Total NOK:</strong> <span id="ref-hist-tot-nok">0</span></span>
                </div>

                <div id="ref-hist-tabla-wrap" class="ref-hist-tabla-wrap" style="display:none">
                    <table class="ref-hist-tabla">
                        <thead>
                            <tr>
                                <th>OF</th>
                                <th>Máquina(s)</th>
                                <th class="num">Días</th>
                                <th class="num">Unidades OK</th>
                                <th class="num">Unidades NOK</th>
                            </tr>
                        </thead>
                        <tbody id="ref-hist-tbody"></tbody>
                    </table>
                </div>

                <div id="ref-hist-empty" class="ref-hist-empty" style="display:none">
                    Sin fabricaciones para esta referencia en el rango seleccionado.
                </div>
            </div>

            <!-- ───── Bloque 2: comparativa de OFs entre máquinas ───── -->
            <div class="ref-comp-block" style="margin-top:24px">
                <div class="ref-comp-head">
                    <h3>Comparativa de rendimiento por OF</h3>
                    <small>Para esta referencia · OFs y máquinas que la han fabricado · gráficos en espejo</small>
                </div>

                <div id="ref-comp-overall" class="ref-comp-overall" style="display:none">
                    <div class="ref-comp-overall-icon">🏅</div>
                    <div class="ref-comp-overall-body">
                        <div class="ref-comp-overall-title">Mejor máquina del rango <small>(sumando todas las OFs)</small></div>
                        <div class="ref-comp-overall-detail" id="ref-comp-overall-detail">—</div>
                        <div id="ref-comp-overall-ranking" class="ref-comp-overall-ranking"></div>
                    </div>
                </div>

                <!-- Por cada máquina: chart de uds/h arriba + chart OK/NOK invertido abajo + stats a la derecha -->
                <div id="ref-comp-machines" class="ref-comp-machines"></div>

                <div id="ref-comp-empty" class="ref-hist-empty" style="display:none;margin-top:10px">
                    No hay OFs comparables para esta referencia en el rango.
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════════════
                 MÓDULOS de análisis de Rendimiento por sección
                   Replican lo que en OEE Unificado aparece debajo de "Disponibilidad":
                     1) Ranking por Máquina o Referencia (peor → mejor %).
                     2) Pareto de motivos (pérdidas por producto/artículo) de la sección.
                     3) Al clicar una máquina/referencia: evolución D/R/C/OEE +
                        pareto de pérdidas de esa máquina.
                     4) Al clicar un motivo: drill por máquina y por hora.
                   Vive al final de esta pantalla para que el usuario tenga TODO
                   sobre Rendimiento en un único sitio sin saltar entre páginas.
                 ═══════════════════════════════════════════════════════════════════════ -->
            <div id="rend-anal-block" class="ref-hist-block" style="margin-top:32px;border-top:2px solid #d5dfe8;padding-top:18px">
                <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap">
                    <h3 style="margin:0;font-size:16px;color:#1a2d4a">Análisis de Rendimiento por sección</h3>
                    <span style="color:#5b6f86;font-size:12.5px">— ranking de máquinas y pareto de pérdidas. Pulsa una barra para profundizar.</span>
                </div>

                <!-- Filtros propios: sección + rango de fechas + turnos. Independientes
                     del bloque de "referencia" de arriba para no acoplar conceptos. -->
                <div class="dual-selector-row" style="margin-bottom:14px;gap:10px;flex-wrap:wrap">
                    <div class="machine-selector-row" style="flex:0 0 auto">
                        <label for="rend-seccion" class="machine-selector-label">Sección:</label>
                        <select id="rend-seccion" class="machine-selector" style="min-width:160px">
                            <option value="VARILLAS">VARILLAS</option>
                            <option value="TROQUELADOS">TROQUELADOS</option>
                        </select>
                    </div>
                    <div class="machine-selector-row" style="flex:0 0 auto">
                        <label for="rend-desde" class="machine-selector-label">Desde:</label>
                        <input type="date" id="rend-desde" class="machine-selector" style="min-width:150px">
                    </div>
                    <div class="machine-selector-row" style="flex:0 0 auto">
                        <label for="rend-hasta" class="machine-selector-label">Hasta:</label>
                        <input type="date" id="rend-hasta" class="machine-selector" style="min-width:150px">
                    </div>
                    <div class="machine-selector-row turno-multi" style="flex:0 0 auto;gap:8px">
                        <span class="machine-selector-label">Turnos:</span>
                        <label class="turno-chip"><input type="checkbox" class="rend-turno" value="M" checked> M</label>
                        <label class="turno-chip"><input type="checkbox" class="rend-turno" value="T" checked> T</label>
                        <label class="turno-chip"><input type="checkbox" class="rend-turno" value="N" checked> N</label>
                    </div>
                    <div class="machine-selector-row" style="flex:0 0 auto;gap:8px">
                        <span class="machine-selector-label">Por:</span>
                        <label class="turno-chip"><input type="radio" name="rend-por" value="maquina" checked> Máquina</label>
                        <label class="turno-chip"><input type="radio" name="rend-por" value="referencia"> Referencia</label>
                    </div>
                    <button type="button" id="rend-aplicar" class="top-aplicar-btn" style="flex:0 0 auto">Aplicar</button>
                </div>

                <!-- Nivel 1: dos columnas — Ranking | Pareto motivos.
                     Patrón "wrapper scroll + chart libre":
                       · El div EXTERIOR (.rend-scroll-box) tiene max-height fija
                         y overflow-y:auto. Es el viewport.
                       · El div INTERIOR (#rend-*-chart) es donde ApexCharts pinta,
                         sin restricciones de altura. Apex calcula su altura según
                         el nº de motivos (~28 px por barra) y, si supera la del
                         wrapper, aparece el scroll DENTRO del propio módulo. -->
                <div class="drill-down-row" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">
                    <div class="drill-down-col">
                        <h4 class="oee-detalle-subtitle" style="margin:0 0 6px">
                            <span id="rend-rank-titulo">Ranking por Máquina</span>
                            <small>(menor → mayor %)</small>
                        </h4>
                        <div class="rend-scroll-box" style="max-height:460px;overflow-y:auto;border:1px solid #eef2f6;border-radius:6px">
                            <div id="rend-rank-chart"></div>
                        </div>
                    </div>
                    <div class="drill-down-col">
                        <h4 class="oee-detalle-subtitle" style="margin:0 0 6px">Pareto de pérdidas (motivos)
                            <small style="font-weight:400;color:#5b6f86">— pulsa una barra para el drill por máquina/hora</small>
                        </h4>
                        <div class="rend-scroll-box" style="max-height:460px;overflow-y:auto;border:1px solid #eef2f6;border-radius:6px">
                            <div id="rend-motivos-chart"></div>
                        </div>
                    </div>
                </div>

                <!-- Nivel 2 (oculto inicialmente): drill al clicar una máquina/referencia.
                     Cambio respecto a Disponibilidad: la "Evolución D/R/C/OEE" va en
                     una FILA INDEPENDIENTE a todo el ancho — la serie temporal queda
                     mucho más legible que cuando comparte fila con el pareto. El
                     pareto de pérdidas se renderiza debajo, también a todo el ancho. -->
                <div id="rend-maq-drill" style="display:none;margin-top:24px;border-top:1px dashed #d5dfe8;padding-top:18px">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                        <strong id="rend-maq-drill-titulo" style="font-size:14px;color:#1a2d4a">—</strong>
                        <button type="button" id="rend-maq-drill-cerrar" style="margin-left:auto;background:#fff;color:#5b6f86;border:1px solid #d5dfe8;padding:3px 10px;border-radius:4px;cursor:pointer;font-size:12px">Cerrar</button>
                    </div>
                    <!-- Fila 1 (full width): evolución temporal -->
                    <div class="drill-down-row" style="margin-bottom:18px">
                        <div class="drill-down-col" style="width:100%">
                            <h4 class="oee-detalle-subtitle" style="margin:0 0 6px">Evolución D/R/C/OEE</h4>
                            <div id="rend-maq-evol-chart" style="width:100%;min-height:380px"></div>
                        </div>
                    </div>
                    <!-- Fila 2 (full width): pareto de pérdidas.
                         Mismo patrón "wrapper scroll + chart libre" que en Nivel 1
                         para que con muchas referencias el chart se quede
                         encapsulado en su caja con scroll interno y NO empuje al
                         resto de la página. -->
                    <div class="drill-down-row">
                        <div class="drill-down-col" style="width:100%">
                            <h4 class="oee-detalle-subtitle" style="margin:0 0 6px">Pérdidas por producto / referencia
                                <small style="font-weight:400;color:#5b6f86">— pulsa una barra para ver el detalle horario</small>
                            </h4>
                            <div class="rend-scroll-box" style="width:100%;max-height:420px;overflow-y:auto;border:1px solid #eef2f6;border-radius:6px">
                                <div id="rend-maq-motivos-chart"></div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Nivel 3 (oculto inicialmente): drill al clicar un motivo del pareto.
                     Mostraba antes dos columnas (por máquina + distribución horaria),
                     pero la distribución horaria no aporta datos útiles para Rendimiento
                     (el endpoint no devuelve granularidad horaria fiable para pérdidas
                     por producto). Se mantiene sólo el desglose por máquina/referencia
                     a todo el ancho. -->
                <div id="rend-motivo-drill" style="display:none;margin-top:24px;border-top:1px dashed #d5dfe8;padding-top:18px">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                        <strong id="rend-motivo-drill-titulo" style="font-size:14px;color:#1a2d4a">—</strong>
                        <button type="button" id="rend-motivo-drill-cerrar" style="margin-left:auto;background:#fff;color:#5b6f86;border:1px solid #d5dfe8;padding:3px 10px;border-radius:4px;cursor:pointer;font-size:12px">Cerrar</button>
                    </div>
                    <div class="drill-down-row">
                        <div class="drill-down-col" style="width:100%">
                            <h4 class="oee-detalle-subtitle" style="margin:0 0 6px">Pérdida por máquina / referencia</h4>
                            <div class="rend-scroll-box" style="width:100%;max-height:420px;overflow-y:auto;border:1px solid #eef2f6;border-radius:6px">
                                <div id="rend-motivo-maq-chart"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="rend-anal-empty" style="display:none;padding:20px;text-align:center;color:#5b6f86;font-style:italic">
                    Sin datos para los filtros seleccionados.
                </div>
            </div>

        </div>
    </div>
</main>

<!-- Popup: distribución horaria de una OF -->
<div id="of-popup" class="of-popup" hidden>
    <div class="of-popup-overlay"></div>
    <div class="of-popup-dialog" role="dialog" aria-modal="true" aria-labelledby="of-popup-title">
        <div class="of-popup-head">
            <h3 id="of-popup-title">—</h3>
            <button type="button" id="of-popup-close" class="of-popup-close" aria-label="Cerrar">×</button>
        </div>
        <div id="of-popup-sub" class="of-popup-sub">—</div>
        <div id="of-popup-chart" class="of-popup-chart"></div>
        <div id="of-popup-empty" class="of-popup-empty" style="display:none">Sin producción horaria para esta OF.</div>
    </div>
</div>

<div class="loader" id="loader"><div class="spinner"></div></div>
<div class="toast" id="toast"></div>

<?php
$_jsCommon = __DIR__ . '/../assets/js/common.js';
$_jsView   = __DIR__ . '/../assets/js/view_oee_unificado_ref_historico.js';
?>
<script src="../assets/js/common.js?v=<?= file_exists($_jsCommon) ? filemtime($_jsCommon) : time() ?>"></script>
<script src="../assets/js/view_oee_unificado_ref_historico.js?v=<?= file_exists($_jsView) ? filemtime($_jsView) : time() ?>"></script>

<!-- ════════════════════════════════════════════════════════════════════════
     Módulo "Análisis de Rendimiento por sección" — JS cliente
     Cablea los nuevos bloques (#rend-anal-block) a los endpoints ya
     existentes del OEE Unificado:
       · oee_unificado_drill.php           → ranking y pareto motivos
       · oee_unificado_evolucion.php       → evolución D/R/C/OEE de una máq.
       · oee_unificado_motivo_drill.php    → drill al clicar un motivo
     Se ejecuta como IIFE para no contaminar el scope global del histórico.
     ════════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    const $ = sel => document.querySelector(sel);
    const $$ = sel => Array.from(document.querySelectorAll(sel));
    if (!$('#rend-anal-block')) return;

    // ── Estado ─────────────────────────────────────────────────────────
    let chartRank = null, chartMotivos = null;
    let chartMaqEvol = null, chartMaqMotivos = null;
    let chartMotMaq = null;       // pérdida del motivo por máquina/referencia (Nivel 3)
    let _ultData = null;          // último response del nivel 1 (para evitar refetch)
    let _maqActiva = null;        // máquina/ref que tiene el drill abierto en Nivel 2

    // ── Fechas por defecto: últimos 30 días ────────────────────────────
    const today = new Date();
    const ago   = new Date(); ago.setDate(today.getDate() - 30);
    const isoFn = d => d.toISOString().substring(0, 10);
    $('#rend-desde').value = isoFn(ago);
    $('#rend-hasta').value = isoFn(today);

    // ── Helpers ────────────────────────────────────────────────────────
    function leerFiltros() {
        const sec = $('#rend-seccion').value;
        const fd  = $('#rend-desde').value;
        const fh  = $('#rend-hasta').value;
        if (!sec || !fd || !fh) return null;
        const turnos = $$('.rend-turno:checked').map(c => c.value);
        const por    = ($$('input[name="rend-por"]:checked')[0] || {}).value || 'maquina';
        return { sec, fd, fh, turnos, por };
    }
    function mostrarVacio(msg) {
        $('#rend-anal-empty').textContent = msg;
        $('#rend-anal-empty').style.display = '';
    }
    function ocultarVacio() {
        $('#rend-anal-empty').style.display = 'none';
    }
    function destroyChart(c) { if (c) { try { c.destroy(); } catch(_){} } }

    async function apiFetch(endpoint, params) {
        const u = new URL('../api/' + endpoint, location.href);
        Object.entries(params || {}).forEach(([k, v]) => {
            if (v !== '' && v !== null && v !== undefined) u.searchParams.set(k, v);
        });
        const r = await fetch(u.toString(), { cache: 'no-store' });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Error en ' + endpoint);
        return j.data;
    }

    // ════════════════════════════════════════════════════════════════
    // Nivel 1 — Ranking + Pareto de motivos
    // ════════════════════════════════════════════════════════════════
    async function cargarNivel1() {
        const f = leerFiltros();
        if (!f) { mostrarVacio('Indica sección y rango de fechas.'); return; }
        ocultarVacio();
        cerrarNivelMaq();
        cerrarNivelMotivo();

        // Etiqueta del ranking según modo
        $('#rend-rank-titulo').textContent = f.por === 'referencia'
            ? 'Ranking por Referencia' : 'Ranking por Máquina';

        try {
            const params = {
                seccion: f.sec, metrica: 'rendimiento', por: f.por,
                fecha_desde: f.fd, fecha_hasta: f.fh,
            };
            if (f.turnos.length) params.turnos = f.turnos.join(',');
            const d = await apiFetch('oee_unificado_drill.php', params);
            _ultData = { ...d, _por: f.por, _sec: f.sec, _fd: f.fd, _fh: f.fh, _turnos: f.turnos };
            renderRank(d.maquinas || d.referencias || [], f.por);
            renderMotivos(d.motivos || []);
        } catch (e) {
            mostrarVacio('Error: ' + (e.message || e));
        }
    }

    function renderRank(items, por) {
        destroyChart(chartRank);
        const el = $('#rend-rank-chart');
        if (!items.length) { el.innerHTML = '<div class="drill-down-empty">Sin datos</div>'; return; }
        // Orden DESC para ver peor primero (menor %)
        const data = items.slice().sort((a, b) => (a.rendimiento ?? a.valor) - (b.rendimiento ?? b.valor));
        const cats = data.map(x => x.maquina || x.referencia || x.nombre || x.cod_maquina);
        const vals = data.map(x => +((x.rendimiento ?? x.valor) || 0).toFixed(2));

        chartRank = new ApexCharts(el, {
            chart: {
                type: 'bar', height: Math.max(280, data.length * 28 + 60),
                background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
                events: {
                    dataPointSelection: (_e, _ctx, opts) => {
                        const row = data[opts.dataPointIndex];
                        if (row) abrirNivelMaq(row, por);
                    },
                },
            },
            series: [{ name: 'Rendimiento %', data: vals }],
            plotOptions: { bar: { horizontal: true, barHeight: '70%', borderRadius: 3, distributed: true } },
            colors: vals.map(v => v < 60 ? '#c8102e' : v < 80 ? '#d97706' : '#1f8a3c'),
            xaxis: { categories: cats, max: 100,
                     labels: { formatter: v => v + '%', style: { fontSize: '11px' } } },
            dataLabels: { enabled: true, formatter: v => v + '%',
                          style: { fontSize: '10.5px', colors: ['#fff'], fontWeight: 700 } },
            grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
            legend: { show: false },
            tooltip: { y: { formatter: v => v + ' %' } },
        });
        chartRank.render();
    }

    function renderMotivos(motivos) {
        destroyChart(chartMotivos);
        const el = $('#rend-motivos-chart');
        if (!motivos.length) { el.innerHTML = '<div class="drill-down-empty">Sin pérdidas relevantes</div>'; return; }
        const cats = motivos.map(m => m.motivo);
        const horas = motivos.map(m => +((m.horas || 0)).toFixed(2));
        const pcts = motivos.map(m => +((m.pct || 0)).toFixed(1));

        chartMotivos = new ApexCharts(el, {
            chart: {
                type: 'bar', height: Math.max(280, motivos.length * 28 + 60),
                background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
                events: {
                    dataPointSelection: (_e, _ctx, opts) => {
                        const m = motivos[opts.dataPointIndex];
                        if (m) abrirNivelMotivo(m.motivo);
                    },
                },
            },
            series: [{ name: 'Horas perdidas', data: horas }],
            plotOptions: { bar: { horizontal: true, barHeight: '70%', borderRadius: 3 } },
            colors: ['#5b3fb8'],
            xaxis: { categories: cats, title: { text: 'Horas perdidas' } },
            dataLabels: { enabled: true,
                          formatter: (v, opt) => v + ' h (' + pcts[opt.dataPointIndex] + '%)',
                          style: { fontSize: '10.5px', colors: ['#fff'], fontWeight: 700 } },
            grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
            legend: { show: false },
            tooltip: { y: { formatter: (v, opt) =>
                v + ' h  ·  ' + pcts[opt.seriesIndex !== undefined ? opt.dataPointIndex : 0] + '% del total' } },
        });
        chartMotivos.render();
    }

    // ════════════════════════════════════════════════════════════════
    // Nivel 2 — Drill por máquina/referencia
    // ════════════════════════════════════════════════════════════════
    async function abrirNivelMaq(row, por) {
        if (!_ultData) return;
        $('#rend-maq-drill').style.display = '';
        const nombre = row.maquina || row.referencia || row.nombre || row.cod_maquina;
        const cod    = row.cod_maquina || row.cod_referencia || row.cod || nombre;
        const tipo   = por === 'referencia' ? 'Referencia' : 'Máquina';
        // Guardamos el contexto del Nivel 2 para que el click sobre una barra
        // del pareto de pérdidas pueda lanzar el sub-drill horario sabiendo
        // máquina + modo de agrupación.
        _maqActiva = { por, cod, nombre, tipo };
        cerrarHorarioRef();
        $('#rend-maq-drill-titulo').textContent =
            tipo + ': ' + nombre + ' · Rendimiento · ' + _ultData._sec;

        const f = _ultData;
        // Evolución solo en modo máquina (el endpoint agrupa por WorkGroup).
        if (por === 'maquina') {
            try {
                const params = {
                    fecha_desde: f._fd, fecha_hasta: f._fh, cod_maquina: cod,
                };
                if (f._turnos.length) params.turnos = f._turnos.join(',');
                const d = await apiFetch('oee_unificado_evolucion.php', params);
                renderMaqEvol(d.periodos || []);
            } catch (e) {
                $('#rend-maq-evol-chart').innerHTML = '<div class="drill-down-empty">Sin evolución</div>';
            }
        } else {
            $('#rend-maq-evol-chart').innerHTML =
                '<div class="drill-down-empty">La evolución D/R/C/OEE solo aplica a máquinas.</div>';
        }

        // Pareto de pérdidas para esa máquina/referencia
        try {
            const params = {
                seccion: f._sec, metrica: 'rendimiento', por: f._por,
                fecha_desde: f._fd, fecha_hasta: f._fh,
            };
            if (f._turnos.length) params.turnos = f._turnos.join(',');
            if (por === 'referencia') params.cod_referencia = cod;
            else                      params.cod_maquina    = cod;
            const d = await apiFetch('oee_unificado_drill.php', params);
            renderMaqMotivos(d.motivos || []);
        } catch (e) {
            $('#rend-maq-motivos-chart').innerHTML = '<div class="drill-down-empty">Sin pérdidas</div>';
        }

        $('#rend-maq-drill').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function renderMaqEvol(periodos) {
        destroyChart(chartMaqEvol);
        const el = $('#rend-maq-evol-chart');
        if (!periodos.length) { el.innerHTML = '<div class="drill-down-empty">Sin datos</div>'; return; }
        const cats = periodos.map(p => p.label);
        const series = [
            { name: 'OEE',           data: periodos.map(p => +p.oee || 0)         },
            { name: 'Disponibilidad',data: periodos.map(p => +p.disponibilidad||0)},
            { name: 'Rendimiento',   data: periodos.map(p => +p.rendimiento || 0) },
            { name: 'Calidad',       data: periodos.map(p => +p.calidad      || 0) },
        ];
        chartMaqEvol = new ApexCharts(el, {
            chart: { type: 'line', height: 380, background: 'transparent',
                     toolbar: { show: false }, fontFamily: 'Arial' },
            series,
            stroke: { curve: 'smooth', width: 2 },
            xaxis: { categories: cats, labels: { rotate: -35, style: { fontSize: '10.5px' } } },
            yaxis: { min: 0, max: 100, tickAmount: 5, labels: { formatter: v => v + '%' } },
            colors: ['#8c181a', '#1a4a7a', '#5b3fb8', '#1f8a3c'],
            legend: { position: 'top', fontSize: '11px' },
            grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
            tooltip: { y: { formatter: v => v.toFixed(1) + ' %' } },
        });
        chartMaqEvol.render();
    }

    function renderMaqMotivos(motivos) {
        destroyChart(chartMaqMotivos);
        const el = $('#rend-maq-motivos-chart');
        if (!motivos.length) { el.innerHTML = '<div class="drill-down-empty">Sin pérdidas</div>'; return; }
        const cats = motivos.map(m => m.motivo);
        const horas = motivos.map(m => +((m.horas || 0)).toFixed(2));
        chartMaqMotivos = new ApexCharts(el, {
            chart: { type: 'bar', height: Math.max(240, motivos.length * 24 + 50),
                     background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial' },
            series: [{ name: 'Horas perdidas', data: horas }],
            plotOptions: { bar: { horizontal: true, barHeight: '70%', borderRadius: 3 } },
            colors: ['#c45a2c'],
            xaxis: { categories: cats },
            dataLabels: { enabled: true, formatter: v => v + ' h',
                          style: { fontSize: '10.5px', colors: ['#fff'], fontWeight: 700 } },
            grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
            legend: { show: false },
            states: {
                hover:  { filter: { type: 'lighten', value: 0.06 } },
                active: { filter: { type: 'darken',  value: 0.12 } },
            },
        });
        chartMaqMotivos.render();
    }

    // (El sub-drill de "distribución horaria del producto" se ha retirado:
    //  el endpoint no devolvía datos útiles a esa granularidad para la
    //  métrica Rendimiento. Si en el futuro se quiere recuperar, hay que
    //  añadir un endpoint que cuente las pérdidas de velocidad por hora
    //  del día para una referencia concreta.)

    function cerrarNivelMaq() {
        $('#rend-maq-drill').style.display = 'none';
        destroyChart(chartMaqEvol);    chartMaqEvol    = null;
        destroyChart(chartMaqMotivos); chartMaqMotivos = null;
        _maqActiva = null;
    }
    $('#rend-maq-drill-cerrar').addEventListener('click', cerrarNivelMaq);

    // ════════════════════════════════════════════════════════════════
    // Nivel 3 — Drill por motivo
    // ════════════════════════════════════════════════════════════════
    async function abrirNivelMotivo(motivo) {
        if (!_ultData) return;
        $('#rend-motivo-drill').style.display = '';
        $('#rend-motivo-drill-titulo').textContent =
            'Motivo: ' + motivo + ' · Rendimiento · ' + _ultData._sec;

        const f = _ultData;
        try {
            const params = {
                seccion: f._sec, metrica: 'rendimiento', motivo,
                fecha_desde: f._fd, fecha_hasta: f._fh,
            };
            if (f._turnos.length) params.turnos = f._turnos.join(',');
            const d = await apiFetch('oee_unificado_motivo_drill.php', params);
            renderMotMaq(d.detalle || []);
        } catch (e) {
            $('#rend-motivo-maq-chart').innerHTML = '<div class="drill-down-empty">Sin datos</div>';
        }
        $('#rend-motivo-drill').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function renderMotMaq(rows) {
        destroyChart(chartMotMaq);
        const el = $('#rend-motivo-maq-chart');
        if (!rows.length) { el.innerHTML = '<div class="drill-down-empty">Sin datos</div>'; return; }
        const cats  = rows.map(r => r.maquina || r.referencia || r.nombre);
        const horas = rows.map(r => +((r.horas || 0)).toFixed(2));
        chartMotMaq = new ApexCharts(el, {
            chart: { type: 'bar', height: Math.max(240, rows.length * 24 + 50),
                     background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial' },
            series: [{ name: 'Horas perdidas', data: horas }],
            plotOptions: { bar: { horizontal: true, barHeight: '70%', borderRadius: 3 } },
            colors: ['#5b3fb8'],
            xaxis: { categories: cats },
            dataLabels: { enabled: true, formatter: v => v + ' h',
                          style: { fontSize: '10.5px', colors: ['#fff'], fontWeight: 700 } },
            grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
            legend: { show: false },
        });
        chartMotMaq.render();
    }

    function cerrarNivelMotivo() {
        $('#rend-motivo-drill').style.display = 'none';
        destroyChart(chartMotMaq); chartMotMaq = null;
    }
    $('#rend-motivo-drill-cerrar').addEventListener('click', cerrarNivelMotivo);

    // ── Botón Aplicar y cargas iniciales ───────────────────────────────
    $('#rend-aplicar').addEventListener('click', cargarNivel1);
    // Auto-carga inicial: VARILLAS, últimos 30 días, por Máquina.
    cargarNivel1();
})();
</script>

</body>
</html>
