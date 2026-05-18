/* =========================================================
   OEE Unificado — vista en cascada
   Paso 1: barras horizontales con OEE % por sección.
   Paso 2: clic en una sección → desglose D · R · C · OEE.
   Paso 3: clic en una métrica (D/R/C/OEE) → máquinas + motivos.
   Paso 3b: clic en un motivo → desglose de ese motivo por máquina.
   ========================================================= */

let chartSecciones = null;
let chartSeccionDrc = null;
let chartMetricaMaq = null;
let chartMetricaMot = null;
let chartMotivoDet = null;    // chart paso 3b: motivo → por máquina
let chartMotivoHora = null;   // chart paso 3b: motivo → por hora del día
let _motivoActivo = null;     // { nombre } del motivo abierto (para refetch tras exclusión)
let chartEvolucion = null;    // chart evolución OEE
let _evolucionAbort = null;
let _lastSecciones = [];
let _lastMotivosData = [];    // datos de motivos para el clic inverso
let _seccionActiva = null;
let _metricaActiva = null;
let _maqExcl   = new Set();   // cod_maquina excluidas del análisis (filtro global)
let _maqLookup = {};          // cod_maquina → desc_maquina (para chips)
let _maquinasActivas = [];    // listado actual de máquinas (post-exclusión) para el selector
let _reloadTimer = null;      // debounce para recargas al excluir varias máquinas seguidas
let _renderingCharts = false; // guard against ApexCharts ghost click events
let _analisisCompleto = false; // true cuando se abre el motivo drill (fin del flujo) → revela botón "Mostrar Top máquinas"
let _metricaPor = 'maquina';   // 'maquina' | 'referencia' (toggle al abrir Disponibilidad/OEE)

const TURNO_LABELS = { M: 'Mañana', T: 'Tarde', N: 'Noche' };
const STORE_KEY = 'kh_oee_unificado_filtros';

const METRICA_LABELS = {
    disponibilidad: 'Disponibilidad',
    rendimiento:    'Rendimiento',
    calidad:        'Calidad',
    oee:            'OEE',
};
const METRICA_COLORS = {
    disponibilidad: '#8c181a',
    rendimiento:    '#c45a2c',
    calidad:        '#6b2d5b',
    oee:            '#2a7a4b',
};
const CORP_COLOR = '#8c181a';
const MOTIVO_TITLES = {
    disponibilidad: 'Motivos de paro (horas)',
    rendimiento:    'Pérdidas rendimiento por artículo',
    calidad:        'Motivos de rechazo (uds)',
    oee:            'Motivos de paro (horas)',
};

// ───── Persistencia local de filtros ─────
function loadState() {
    try {
        const raw = localStorage.getItem(STORE_KEY);
        if (raw) return JSON.parse(raw);
    } catch (e) {}
    return null;
}
function saveState(s) {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(s)); } catch (e) {}
}

// ───── Estado actual del formulario ─────
function getFiltros() {
    const desde  = $('#f-desde').value;
    const hasta  = $('#f-hasta').value;
    const turnos = Array.from(document.querySelectorAll('.turno-cb:checked')).map(c => c.value);
    const excl   = Array.from(_maqExcl);
    return { desde, hasta, turnos, excl };
}

// Añade params comunes (turnos, excl) al objeto si están activos
function addCommonParams(params, f) {
    if (f.turnos.length) params.turnos = f.turnos.join(',');
    if (f.excl.length)   params.excl   = f.excl.join(',');
    return params;
}

function fmtFechaCorta(iso) {
    if (!iso) return '—';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
}

function labelTurnos(arr) {
    if (!arr || !arr.length) return 'Todos los turnos';
    return arr.map(t => TURNO_LABELS[t] || t).join(' · ');
}

// ───── Rangos rápidos ─────
function setRange(kind) {
    const today = new Date();
    const ymd = (d) => d.toISOString().slice(0, 10);
    let desde = today, hasta = today;
    if (kind === 'today') {
        desde = today; hasta = today;
    } else if (kind === 'yesterday') {
        const y = new Date(today); y.setDate(y.getDate() - 1);
        desde = y; hasta = y;
    } else if (kind === 'week') {
        const dow = today.getDay() === 0 ? 7 : today.getDay();   // 1..7
        const monday = new Date(today); monday.setDate(today.getDate() - (dow - 1));
        desde = monday; hasta = today;
    } else if (kind === 'month') {
        desde = new Date(today.getFullYear(), today.getMonth(), 1);
        hasta = today;
    }
    $('#f-desde').value = ymd(desde);
    $('#f-hasta').value = ymd(hasta);
}

// ───── Paso 1: barras horizontales OEE por sección ─────
function renderSecciones(secciones) {
    const data = secciones.map(s => ({
        x: s.seccion,
        y: parseFloat(s.oee),
        maquinas: s.maquinas,
        disponibilidad: parseFloat(s.disponibilidad),
        rendimiento:    parseFloat(s.rendimiento),
        calidad:        parseFloat(s.calidad),
    }));

    const options = {
        chart: {
            type: 'bar', height: 260, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    const sec = data[cfg.dataPointIndex]?.x;
                    if (sec) abrirDrillSeccion(sec);
                },
            },
        },
        series: [{ name: 'OEE', data: data.map(d => d.y) }],
        xaxis: {
            categories: data.map(d => d.x),
            max: 100,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '11px' },
                formatter: v => v + '%',
            },
        },
        yaxis: {
            labels: {
                style: { colors: '#1a2d4a', fontSize: '13px', fontWeight: 700 },
            },
        },
        plotOptions: {
            bar: {
                horizontal: true,
                barHeight: '55%',
                borderRadius: 4,
                borderRadiusApplication: 'end',
                distributed: true,
                dataLabels: { position: 'center' },
            },
        },
        colors: data.map(d => semColor(d.y)),
        dataLabels: {
            enabled: true,
            style: { colors: ['#ffffff'], fontFamily: 'Arial', fontSize: '14px', fontWeight: 700 },
            formatter: v => v.toFixed(1) + '%',
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { show: false },
        states: {
            hover:  { filter: { type: 'lighten', value: 0.08 } },
            active: { filter: { type: 'darken',  value: 0.10 } },
        },
        tooltip: {
            custom: ({ dataPointIndex }) => {
                const r = data[dataPointIndex];
                return `
                    <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:200px">
                        <div style="font-weight:700;margin-bottom:6px;font-size:13px">${r.x}</div>
                        <div style="color:#a3b8d1;font-size:11px;margin-bottom:6px">${r.maquinas} máquina${r.maquinas === 1 ? '' : 's'}</div>
                        <div style="font-weight:700;font-size:14px">OEE: ${r.y.toFixed(2)}%</div>
                        <div style="color:#a3b8d1;font-size:11px;margin-top:4px">Clic para ver el desglose</div>
                    </div>
                `;
            },
        },
        annotations: {
            xaxis: [{
                x: 75,
                borderColor: '#8c181a',
                strokeDashArray: 6,
                label: {
                    text: 'Objetivo 75%',
                    borderColor: '#8c181a',
                    style: { color: '#fff', background: '#8c181a', fontSize: '11px', fontWeight: 700 },
                },
            }],
        },
    };

    if (chartSecciones) chartSecciones.destroy();
    chartSecciones = new ApexCharts($('#chart-secciones'), options);
    chartSecciones.render();
}

// ───── Paso 2: desglose D · R · C · OEE de una sección ─────
function renderSeccionDrc(sec) {
    const values = [
        parseFloat(sec.disponibilidad),
        parseFloat(sec.rendimiento),
        parseFloat(sec.calidad),
        parseFloat(sec.oee),
    ];
    const categories = ['Disponibilidad', 'Rendimiento', 'Calidad', 'OEE'];
    const metricaKeys = ['disponibilidad', 'rendimiento', 'calidad', 'oee'];

    const options = {
        chart: {
            type: 'bar', height: 300, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    const metrica = metricaKeys[cfg.dataPointIndex];
                    if (!metrica) return;
                    // Rendimiento → redirige al "Histórico de Rendimiento" en lugar
                    // de abrir el desglose por máquinas/motivos.
                    if (metrica === 'rendimiento') {
                        window.location.href = 'oee_unificado_ref_historico.php';
                        return;
                    }
                    abrirDrillMetrica(metrica);
                },
            },
        },
        series: [{ name: sec.seccion, data: values }],
        xaxis: {
            categories: categories,
            max: 100,
            labels: {
                style: { colors: '#1a2d4a', fontSize: '12px', fontWeight: 600 },
                formatter: v => v + '%',
            },
        },
        plotOptions: {
            bar: {
                horizontal: true,
                barHeight: '60%',
                borderRadius: 4,
                borderRadiusApplication: 'end',
                distributed: true,
                dataLabels: { position: 'center' },
            },
        },
        colors: ['#8c181a', '#c45a2c', '#6b2d5b', '#2a7a4b'],
        dataLabels: {
            enabled: true,
            style: { colors: ['#ffffff'], fontFamily: 'Arial', fontSize: '13px', fontWeight: 700 },
            formatter: v => v.toFixed(2) + '%',
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { show: false },
        states: {
            hover:  { filter: { type: 'lighten', value: 0.08 } },
            active: { filter: { type: 'darken',  value: 0.10 } },
        },
        tooltip: {
            custom: ({ dataPointIndex }) => {
                const label = categories[dataPointIndex];
                const val   = values[dataPointIndex];
                const isRend = metricaKeys[dataPointIndex] === 'rendimiento';
                const hint = isRend
                    ? 'Clic para abrir el Histórico de Rendimiento'
                    : 'Clic para ver máquinas y motivos';
                return `
                    <div style="padding:8px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px">
                        <div style="font-weight:700">${label}: ${val.toFixed(2)}%</div>
                        <div style="color:#a3b8d1;font-size:11px;margin-top:4px">${hint}</div>
                    </div>
                `;
            },
        },
    };

    if (chartSeccionDrc) chartSeccionDrc.destroy();
    chartSeccionDrc = new ApexCharts($('#chart-seccion-drc'), options);
    chartSeccionDrc.render();
}

function abrirDrillSeccion(nombre) {
    const sec = _lastSecciones.find(s => s.seccion === nombre);
    if (!sec) return;
    _seccionActiva = nombre;
    $('#seccion-drill-label').textContent = nombre + ' (' + sec.maquinas + ' máquina' + (sec.maquinas === 1 ? '' : 's') + ')';
    $('#seccion-drill-block').style.display = '';
    $('#metrica-hint').style.display = '';
    renderSeccionDrc(sec);
    cerrarDrillMetrica();
    // Recarga la evolución filtrada a esta sección
    cargarEvolucion(getFiltros());
    $('#seccion-drill-block').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function cerrarDrillSeccion() {
    _seccionActiva = null;
    $('#seccion-drill-block').style.display = 'none';
    if (chartSeccionDrc) { chartSeccionDrc.destroy(); chartSeccionDrc = null; }
    cerrarDrillMetrica();
    // Restaura la evolución a "Todas" al cerrar el drill de sección
    cargarEvolucion(getFiltros());
}

// ───── Paso 3: drill-down métrica → máquinas + motivos ─────

function abrirDrillMetrica(metrica) {
    if (!_seccionActiva) return;
    _metricaActiva = metrica;
    _metricaPor = 'maquina'; // reset al abrir

    $('#metrica-drill-label').textContent = METRICA_LABELS[metrica] || metrica;
    $('#metrica-drill-seccion').textContent = _seccionActiva;
    $('#metrica-drill-block').style.display = '';
    $('#metrica-hint').style.display = 'none';
    $('#motivos-col-title').textContent = MOTIVO_TITLES[metrica] || 'Motivos';
    syncMotivoPorToggle();

    cargarDrillMetrica();
    $('#metrica-drill-block').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function cerrarDrillMetrica() {
    _metricaActiva = null;
    _metricaPor = 'maquina';
    const togWrap = $('#motivo-por-toggle');
    if (togWrap) togWrap.hidden = true;
    $('#metrica-drill-block').style.display = 'none';
    if (chartMetricaMaq) { chartMetricaMaq.destroy(); chartMetricaMaq = null; }
    if (chartMetricaMot) { chartMetricaMot.destroy(); chartMetricaMot = null; }
    cerrarDrillMaqMotivos();
    cerrarDrillMotivo();
}

let _drillAbort = null;

async function cargarDrillMetrica() {
    if (!_seccionActiva || !_metricaActiva) return;

    if (_drillAbort) _drillAbort.abort();
    _drillAbort = new AbortController();

    const f = getFiltros();
    const params = addCommonParams({
        fecha_desde: f.desde,
        fecha_hasta: f.hasta,
        seccion: _seccionActiva,
        metrica: _metricaActiva,
        por: _metricaPor,
    }, f);

    try {
        const d = await apiFetch('oee_unificado_drill.php', params, _drillAbort.signal);
        _renderingCharts = true;
        renderChartMaquinas(d.maquinas || [], d.metrica, d.por || _metricaPor);
        renderChartMotivos(d.motivos || [], d.metrica);
        _renderingCharts = false;
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error drill-down: ' + e.message, 'error');
    }
}

function renderChartMaquinas(data, metrica, por) {
    const esRef = por === 'referencia';
    const color = METRICA_COLORS[metrica] || '#3a6aa3';
    const cats = data.map(d => d.maquina);
    const vals = data.map(d => d.valor);

    // Recordar nombres para chips de exclusión (solo cuando son máquinas reales)
    if (!esRef) data.forEach(d => { if (d.cod_maquina) _maqLookup[d.cod_maquina] = d.maquina; });

    // Actualiza el subtítulo de la columna izquierda según el modo
    const colTitle = document.querySelector('#metrica-drill-block .drill-down-col:first-child .oee-detalle-subtitle');
    if (colTitle) {
        colTitle.innerHTML = esRef
            ? 'Por Referencia <small>(mayor → menor horas de paro)</small>'
            : 'Por Máquina <small>(menor → mayor)</small>';
    }

    // Click handler: solo activo para Disponibilidad/OEE (paso intermedio "motivos por máquina/referencia")
    const enableClick = (metrica === 'disponibilidad' || metrica === 'oee');

    const options = {
        chart: {
            type: 'bar', height: Math.max(250, data.length * 36),
            background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
            events: enableClick ? {
                dataPointSelection: (_e, _ctx, cfg) => {
                    if (_renderingCharts) return;
                    const row = data[cfg.dataPointIndex];
                    if (!row || !row.cod_maquina) return;
                    abrirDrillMaqMotivos(esRef, row.cod_maquina, row.maquina);
                },
            } : {},
        },
        series: [{ name: esRef ? 'Horas paro' : METRICA_LABELS[metrica], data: vals }],
        xaxis: {
            categories: cats,
            max: esRef ? undefined : 100,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '11px' },
                formatter: v => esRef ? v.toFixed(1) + 'h' : v + '%',
            },
        },
        yaxis: {
            labels: {
                style: { colors: '#1a2d4a', fontSize: '11px', fontWeight: 600 },
                // En modo Referencia las descripciones son largas: ensancha la columna
                // de etiquetas (el plot area se reduce automáticamente).
                maxWidth: esRef ? 340 : 140,
            },
        },
        plotOptions: {
            bar: {
                horizontal: true,
                barHeight: '65%',
                borderRadius: 3,
                borderRadiusApplication: 'end',
                distributed: true,
                dataLabels: { position: 'center' },
            },
        },
        colors: esRef ? vals.map(() => '#8c181a') : vals.map(v => semColor(v)),
        dataLabels: {
            enabled: true,
            style: { colors: ['#ffffff'], fontFamily: 'Arial', fontSize: '12px', fontWeight: 700 },
            formatter: v => esRef ? v.toFixed(1) + 'h' : v.toFixed(1) + '%',
        },
        grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
        legend: { show: false },
        states: {
            hover:  { filter: { type: 'lighten', value: 0.08 } },
            active: { filter: { type: 'darken',  value: 0.10 } },
        },
        tooltip: {
            custom: ({ dataPointIndex }) => {
                const r = data[dataPointIndex];
                const v = esRef ? r.valor.toFixed(2) + ' h' : r.valor.toFixed(2) + '%';
                return `
                    <div style="padding:8px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px">
                        <div style="font-weight:700">${r.maquina}: ${v}</div>
                    </div>
                `;
            },
        },
    };

    if (chartMetricaMaq) chartMetricaMaq.destroy();
    if (!data.length) {
        $('#chart-metrica-maquinas').innerHTML = esRef
            ? '<div class="drill-down-empty">Sin paros con referencia identificada en el rango</div>'
            : '<div class="drill-down-empty">Sin datos de máquinas</div>';
        chartMetricaMaq = null;
        return;
    }
    chartMetricaMaq = new ApexCharts($('#chart-metrica-maquinas'), options);
    chartMetricaMaq.render();
}

function renderChartMotivos(data, metrica) {
    _lastMotivosData = data;
    cerrarDrillMotivo();

    // Pareto: barras + línea acumulada
    const cats = data.map(d => d.motivo);
    const isHoras = (metrica === 'disponibilidad' || metrica === 'oee' || metrica === 'rendimiento');
    const valKey = isHoras ? 'horas' : 'unidades';
    const valLabel = isHoras ? 'h' : 'uds';
    const barVals = data.map(d => d[valKey] ?? d.pct);
    const lineVals = data.map(d => d.pct_acum);

    if (!data.length) {
        if (chartMetricaMot) { chartMetricaMot.destroy(); chartMetricaMot = null; }
        $('#chart-metrica-motivos').innerHTML = '<div class="drill-down-empty">Sin motivos registrados</div>';
        return;
    }

    const options = {
        chart: {
            type: 'bar', height: Math.max(250, data.length * 32),
            background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    if (_renderingCharts) return;
                    // Only react to bar clicks (seriesIndex 0), not the line
                    if (cfg.seriesIndex !== 0) return;
                    const mot = data[cfg.dataPointIndex];
                    if (mot) abrirDrillMotivo(mot.motivo);
                },
            },
        },
        series: [
            { name: isHoras ? 'Horas' : 'Unidades', type: 'bar', data: barVals },
            { name: '% Acumulado', type: 'line', data: lineVals },
        ],
        xaxis: {
            categories: cats,
            labels: {
                style: { colors: '#1a2d4a', fontSize: '10px', fontWeight: 600 },
                maxWidth: 160,
                trim: true,
            },
        },
        yaxis: [
            {
                title: { text: isHoras ? 'Horas' : 'Unidades', style: { fontSize: '11px', color: '#2d4d7a' } },
                labels: {
                    style: { colors: '#2d4d7a', fontSize: '10px' },
                    formatter: v => isHoras ? v.toFixed(1) + 'h' : Math.round(v).toString(),
                },
            },
            {
                opposite: true, max: 100,
                title: { text: '% Acumulado', style: { fontSize: '11px', color: '#ef4444' } },
                labels: {
                    style: { colors: '#ef4444', fontSize: '10px' },
                    formatter: v => v.toFixed(0) + '%',
                },
            },
        ],
        plotOptions: {
            bar: {
                horizontal: false,
                columnWidth: '60%',
                borderRadius: 3,
                borderRadiusApplication: 'end',
            },
        },
        colors: [METRICA_COLORS[metrica] || '#3a6aa3', '#ef4444'],
        stroke: { width: [0, 2], curve: 'smooth' },
        markers: { size: [0, 4], colors: ['#ef4444'], strokeWidth: 0 },
        // Etiquetas SOLO sobre la línea de % Acumulado (índice 1). El bar chart
        // queda sin etiquetas para no saturar visualmente, y la línea muestra el
        // valor en cada punto de ruptura.
        dataLabels: {
            enabled: true,
            enabledOnSeries: [1],
            formatter: (v) => (v == null ? '' : v.toFixed(0) + '%'),
            style: { fontSize: '10px', fontWeight: 700, colors: ['#ef4444'] },
            background: { enabled: true, foreColor: '#fff', borderRadius: 3, padding: 2, borderWidth: 0, opacity: 0.92 },
            offsetY: -8,
        },
        grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            shared: true,
            intersect: false,
            y: {
                formatter: (v, { seriesIndex }) => {
                    if (seriesIndex === 0) return isHoras ? v.toFixed(2) + ' h' : Math.round(v) + ' uds';
                    return v.toFixed(1) + '%';
                },
            },
            x: { formatter: (v) => v + ' — clic para desglose por máquina' },
        },
        states: {
            hover:  { filter: { type: 'lighten', value: 0.08 } },
            active: { filter: { type: 'darken',  value: 0.10 } },
        },
    };

    if (chartMetricaMot) chartMetricaMot.destroy();
    chartMetricaMot = new ApexCharts($('#chart-metrica-motivos'), options);
    chartMetricaMot.render();
}

// ───── Paso 3b: drill-down inverso — motivo → por máquina ─────

let _motivoAbort = null;

async function abrirDrillMotivo(motivoNombre) {
    if (!_seccionActiva || !_metricaActiva) return;

    cerrarDrillMotivo();

    // La segmentación viene del toggle de la cabecera del drill métrica (_metricaPor)
    _motivoActivo = { nombre: motivoNombre, codFiltroHora: null };

    $('#motivo-drill-block').style.display = '';
    actualizarMotivoDrillTitulo();

    // El usuario llegó al fondo del análisis → revela el botón "Mostrar Top máquinas"
    _analisisCompleto = true;
    topUpdateShowNext();

    await cargarMotivoDrill();
    $('#motivo-drill-block').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Recarga ambas gráficas del motivo drill con el estado actual (_motivoActivo).
// Se invoca al abrir y al cambiar el toggle Máquina/Referencia.
async function cargarMotivoDrill() {
    if (!_motivoActivo || !_seccionActiva || !_metricaActiva) return;

    if (_motivoAbort) _motivoAbort.abort();
    _motivoAbort = new AbortController();

    const f = getFiltros();
    const params = addCommonParams({
        fecha_desde: f.desde,
        fecha_hasta: f.hasta,
        seccion: _seccionActiva,
        metrica: _metricaActiva,
        motivo: _motivoActivo.nombre,
        por: _metricaPor,
    }, f);
    if (_motivoActivo.codFiltroHora) {
        if (_metricaPor === 'referencia') params.cod_referencia = _motivoActivo.codFiltroHora;
        else                              params.cod_maquina    = _motivoActivo.codFiltroHora;
    }

    try {
        const d = await apiFetch('oee_unificado_motivo_drill.php', params, _motivoAbort.signal);
        renderChartMotivoDet(d.detalle || [], d.metrica, _motivoActivo.nombre);
        renderChartMotivoHora(d.por_hora || null, d.metrica, _motivoActivo.nombre);
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error motivo drill: ' + e.message, 'error');
    }
}

function actualizarMotivoDrillTitulo() {
    if (!_motivoActivo) return;
    const por = _metricaPor === 'referencia' ? 'por referencia' : 'por máquina';
    $('#motivo-drill-title').textContent = _motivoActivo.nombre + ' · ' + por + ' · ' + (_seccionActiva || '');
}

function syncMotivoPorToggle() {
    const wrap = $('#motivo-por-toggle');
    if (!wrap) return;
    // Solo aplica a Disponibilidad / OEE
    const aplica = (_metricaActiva === 'disponibilidad' || _metricaActiva === 'oee');
    wrap.hidden = !aplica;
    if (!aplica) return;
    wrap.querySelectorAll('input[name="motivo-por"]').forEach(r => {
        r.checked = (r.value === _metricaPor);
    });
}

function cerrarDrillMotivo() {
    $('#motivo-drill-block').style.display = 'none';
    if (chartMotivoDet)  { chartMotivoDet.destroy();  chartMotivoDet  = null; }
    if (chartMotivoHora) { chartMotivoHora.destroy(); chartMotivoHora = null; }
    if (_motivoHoraAbort) { _motivoHoraAbort.abort(); _motivoHoraAbort = null; }
    _motivoActivo = null;
    // Vuelve a ocultar el botón "Mostrar Top…" hasta que se complete otro análisis
    _analisisCompleto = false;
    // Ocultar también los bloques Top abiertos, para que el flujo arranque limpio
    TOP_MODES.forEach(m => { const blk = $(`#top-${m}-block`); if (blk && !blk.hidden) topHideBlock(m); });
    topUpdateShowNext();
}

// ───── Paso intermedio: máquina/referencia → motivos → por día → por hora del día → paros ─────

let chartMaqMotivos    = null;
let chartMaqMotivoDia  = null;  // Nivel 1: línea temporal por día
let chartMaqMotivoHora = null;  // Nivel 2: 24h del día clicado
let chartMaqMotivoParos = null; // Nivel 3: paros individuales en la hora clicada
let chartMaqEvolucion  = null;  // Evolución D/R/C/OEE de la máquina seleccionada
let _maqMotivosActivo  = null;  // { esRef, cod, nombre, motivo, dia, hora }
let _maqMotivosAbort   = null;
let _maqMotivoDiaAbort = null;
let _maqMotivoHoraAbort = null;
let _maqMotivoParosAbort = null;
let _maqEvolucionAbort = null;

async function abrirDrillMaqMotivos(esRef, cod, nombre) {
    if (!_seccionActiva || !_metricaActiva) return;

    cerrarDrillMaqMotivos();
    _maqMotivosActivo = { esRef, cod, nombre, motivo: null, dia: null, hora: null };
    _analisisCompleto = true;
    topUpdateShowNext();

    // Carga la evolución D/R/C/OEE de la máquina seleccionada (solo modo Máquina:
    // el endpoint de evolución agrega por WorkGroup = máquina, no por referencia)
    cargarEvolucionMaquina(esRef ? null : cod, nombre);

    const tipo = esRef ? 'Referencia' : 'Máquina';
    $('#maq-motivos-drill-title').textContent =
        METRICA_LABELS[_metricaActiva] + ' · ' + tipo + ': ' + (nombre || cod) + ' · ' + _seccionActiva;
    $('#maq-motivos-drill-block').style.display = '';

    if (_maqMotivosAbort) _maqMotivosAbort.abort();
    _maqMotivosAbort = new AbortController();

    const f = getFiltros();
    const params = addCommonParams({
        fecha_desde: f.desde,
        fecha_hasta: f.hasta,
        seccion: _seccionActiva,
        metrica: _metricaActiva,
    }, f);
    if (esRef) params.cod_referencia = cod;
    else       params.cod_maquina    = cod;

    try {
        const d = await apiFetch('oee_unificado_drill.php', params, _maqMotivosAbort.signal);
        renderChartMaqMotivos(d.motivos || [], _metricaActiva);
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error motivos por ' + tipo.toLowerCase() + ': ' + e.message, 'error');
    }

    $('#maq-motivos-drill-block').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function cerrarDrillMaqMotivos() {
    $('#maq-motivos-drill-block').style.display = 'none';
    $('#maq-motivo-por-dia-wrap').style.display = 'none';
    $('#maq-motivo-por-hora-wrap').style.display = 'none';
    $('#maq-motivo-paros-wrap').style.display = 'none';
    $('#maq-evolucion-wrap').style.display = 'none';
    if (chartMaqMotivos)     { chartMaqMotivos.destroy();     chartMaqMotivos     = null; }
    if (chartMaqMotivoDia)   { chartMaqMotivoDia.destroy();   chartMaqMotivoDia   = null; }
    if (chartMaqMotivoHora)  { chartMaqMotivoHora.destroy();  chartMaqMotivoHora  = null; }
    if (chartMaqMotivoParos) { chartMaqMotivoParos.destroy(); chartMaqMotivoParos = null; }
    if (chartMaqEvolucion)   { chartMaqEvolucion.destroy();   chartMaqEvolucion   = null; }
    if (_maqMotivosAbort)     { _maqMotivosAbort.abort();     _maqMotivosAbort     = null; }
    if (_maqMotivoDiaAbort)   { _maqMotivoDiaAbort.abort();   _maqMotivoDiaAbort   = null; }
    if (_maqMotivoHoraAbort)  { _maqMotivoHoraAbort.abort();  _maqMotivoHoraAbort  = null; }
    if (_maqMotivoParosAbort) { _maqMotivoParosAbort.abort(); _maqMotivoParosAbort = null; }
    if (_maqEvolucionAbort)   { _maqEvolucionAbort.abort();   _maqEvolucionAbort   = null; }
    _maqMotivosActivo = null;
}

// ───── Evolución D/R/C/OEE de la máquina seleccionada en el drill métrica ─────

// Si codMaq es null (modo Referencia) → oculta el bloque, el endpoint no soporta filtro por ref.
async function cargarEvolucionMaquina(codMaq, nombreMaq) {
    const wrap = $('#maq-evolucion-wrap');
    if (!codMaq) { if (wrap) wrap.style.display = 'none'; return; }
    if (!wrap) return;

    wrap.style.display = '';
    if (_maqEvolucionAbort) _maqEvolucionAbort.abort();
    _maqEvolucionAbort = new AbortController();

    const f = getFiltros();
    const params = addCommonParams({
        fecha_desde: f.desde,
        fecha_hasta: f.hasta,
        cod_maquina: codMaq,
    }, f);
    if (_seccionActiva) params.seccion = _seccionActiva;

    try {
        const d = await apiFetch('oee_unificado_evolucion.php', params, _maqEvolucionAbort.signal);
        renderChartMaqEvolucion(d.periodos || [], d.granularidad || 'WEEK', nombreMaq);
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error evolución máquina: ' + e.message, 'error');
    }
}

const LS_EVO_MAQ_VISIBLE = 'kh_oee_unificado_evo_maq_series';
function loadEvoMaqVisible() {
    try {
        const raw = localStorage.getItem(LS_EVO_MAQ_VISIBLE);
        if (raw) {
            const arr = JSON.parse(raw);
            if (Array.isArray(arr)) return new Set(arr);
        }
    } catch (_) {}
    return new Set(['oee']);
}
function saveEvoMaqVisible(set) {
    try { localStorage.setItem(LS_EVO_MAQ_VISIBLE, JSON.stringify(Array.from(set))); } catch (_) {}
}
function syncEvoMaqVisibility() {
    if (!chartMaqEvolucion) return;
    const visible = loadEvoMaqVisible();
    EVO_SERIES.forEach(s => {
        const fn = visible.has(s.key) ? 'showSeries' : 'hideSeries';
        try { chartMaqEvolucion[fn](s.name); } catch (_) {}
    });
}

function renderChartMaqEvolucion(periodos, granularidad, nombreMaq) {
    const labelMap = { DAY: 'Diaria', WEEK: 'Semanal', MONTH: 'Mensual' };
    const label = labelMap[granularidad] || granularidad;
    $('#maq-evolucion-granularidad-label').textContent =
        '· ' + label + ' (' + periodos.length + ') · ' + (nombreMaq || '');

    if (chartMaqEvolucion) { chartMaqEvolucion.destroy(); chartMaqEvolucion = null; }
    const el = $('#chart-maq-evolucion');

    if (!periodos.length) {
        el.innerHTML = '<div class="drill-down-empty">Sin datos de evolución para esta máquina en el rango</div>';
        return;
    }

    // Sincroniza checkboxes con preferencia guardada
    const visible = loadEvoMaqVisible();
    if (visible.size === 0) visible.add('oee');
    $$('#maq-evolucion-toggles input[data-evo-maq-serie]').forEach(cb => {
        cb.checked = visible.has(cb.dataset.evoMaqSerie);
    });

    const cats = periodos.map(p => p.label);
    const series = EVO_SERIES.map(s => ({
        name: s.name,
        type: s.type,
        data: periodos.map(p => +p[s.key]),
    }));
    // Eje Y dinámico (puede haber Rendimiento > 100%)
    let dataMax = 0;
    series.forEach(s => s.data.forEach(v => { if (typeof v === 'number' && v > dataMax) dataMax = v; }));
    const yMax = Math.max(100, Math.ceil((dataMax * 1.1) / 10) * 10);
    const yTicks = Math.max(5, Math.round(yMax / 20));

    const options = {
        chart: {
            type: 'line', height: 360,
            background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
            zoom: { enabled: false },
            events: {
                mounted: () => syncEvoMaqVisibility(),
                updated: () => syncEvoMaqVisibility(),
            },
        },
        series,
        xaxis: {
            categories: cats,
            labels: {
                style: { colors: '#1a2d4a', fontSize: '11px', fontWeight: 600 },
                rotate: cats.length > 14 ? -45 : 0,
                rotateAlways: cats.length > 14,
                hideOverlappingLabels: true,
                trim: true,
            },
            tickPlacement: 'on',
        },
        yaxis: {
            min: 0, max: yMax, tickAmount: yTicks,
            title: { text: '%', style: { fontSize: '11px', color: '#2d4d7a' } },
            labels: { style: { colors: '#2d4d7a', fontSize: '10px' }, formatter: v => v.toFixed(0) + '%' },
        },
        colors: EVO_SERIES.map(s => s.color),
        stroke: { curve: 'smooth', width: EVO_SERIES.map(s => s.type === 'area' ? 3 : 2) },
        fill: {
            type: EVO_SERIES.map(s => s.type === 'area' ? 'gradient' : 'solid'),
            gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 100] },
        },
        markers: { size: cats.length > 30 ? 3 : 4, hover: { sizeOffset: 2 } },
        dataLabels: {
            enabled: true,
            enabledOnSeries: EVO_SERIES.map((_, i) => i),
            formatter: v => (v == null ? '' : v.toFixed(0) + '%'),
            style: { fontSize: '9px', fontWeight: 700, colors: EVO_SERIES.map(s => s.color) },
            background: { enabled: true, foreColor: '#fff', borderRadius: 3, padding: 2, borderWidth: 0, opacity: 0.92 },
            offsetY: -6,
        },
        grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
        annotations: {
            yaxis: [{
                y: 75, borderColor: '#8c181a', strokeDashArray: 4,
                label: {
                    text: 'Objetivo OEE 75%',
                    style: { background: '#8c181a', color: '#fff', fontSize: '10px' },
                    position: 'right', offsetX: -5,
                },
            }],
        },
        legend: { show: false },
        tooltip: {
            shared: true,
            intersect: false,
            x: { formatter: (_v, ctx) => {
                const p = periodos[ctx.dataPointIndex];
                return p ? p.label : '';
            }},
            y: { formatter: (v) => (v == null ? '—' : v.toFixed(1) + '%') },
        },
    };

    chartMaqEvolucion = new ApexCharts(el, options);
    chartMaqEvolucion.render();
}

// Limpia niveles inferiores cuando el usuario sube en la cadena (p.ej. elige otro día)
function _limpiarNivelesDesde(nivel) {
    // nivel 2 = horas; nivel 3 = paros
    if (nivel <= 2) {
        $('#maq-motivo-por-hora-wrap').style.display = 'none';
        if (chartMaqMotivoHora) { chartMaqMotivoHora.destroy(); chartMaqMotivoHora = null; }
        if (_maqMotivoHoraAbort) { _maqMotivoHoraAbort.abort(); _maqMotivoHoraAbort = null; }
        if (_maqMotivosActivo) _maqMotivosActivo.dia = null;
    }
    if (nivel <= 3) {
        $('#maq-motivo-paros-wrap').style.display = 'none';
        if (chartMaqMotivoParos) { chartMaqMotivoParos.destroy(); chartMaqMotivoParos = null; }
        if (_maqMotivoParosAbort) { _maqMotivoParosAbort.abort(); _maqMotivoParosAbort = null; }
        if (_maqMotivosActivo) _maqMotivosActivo.hora = null;
    }
}

function renderChartMaqMotivos(data, metrica) {
    const elChart = document.getElementById('chart-maq-motivos');
    elChart.innerHTML = '';
    if (chartMaqMotivos) { chartMaqMotivos.destroy(); chartMaqMotivos = null; }

    if (!data.length) {
        elChart.innerHTML = '<div class="drill-down-empty">Sin paros para esta selección</div>';
        return;
    }

    const cats = data.map(d => d.motivo);
    const vals = data.map(d => +d.horas);
    const acum = data.map(d => +d.pct_acum);
    const color = METRICA_COLORS[metrica] || '#8c181a';

    const options = {
        chart: {
            type: 'bar', height: Math.max(260, data.length * 30),
            background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    if (_renderingCharts) return;
                    if (cfg.seriesIndex !== 0) return;
                    const mot = data[cfg.dataPointIndex];
                    if (mot) abrirDrillMaqMotivoPorDia(mot.motivo);
                },
            },
        },
        series: [
            { name: 'Horas',        type: 'bar',  data: vals },
            { name: '% Acumulado',  type: 'line', data: acum },
        ],
        xaxis: {
            categories: cats,
            labels: {
                style: { colors: '#1a2d4a', fontSize: '10px', fontWeight: 600 },
                maxWidth: 180, trim: true,
            },
        },
        yaxis: [
            { title: { text: 'Horas', style: { fontSize: '11px', color: '#2d4d7a' } },
              labels: { style: { colors: '#2d4d7a', fontSize: '10px' }, formatter: v => v.toFixed(1) + 'h' } },
            { opposite: true, max: 100,
              title: { text: '% Acumulado', style: { fontSize: '11px', color: '#ef4444' } },
              labels: { style: { colors: '#ef4444', fontSize: '10px' }, formatter: v => v.toFixed(0) + '%' } },
        ],
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 3, borderRadiusApplication: 'end' } },
        colors: [color, '#ef4444'],
        stroke: { width: [0, 2], curve: 'smooth' },
        markers: { size: [0, 4], colors: ['#ef4444'], strokeWidth: 0 },
        // Etiquetas SOLO sobre la línea de % Acumulado (índice 1): valor en cada punto.
        dataLabels: {
            enabled: true,
            enabledOnSeries: [1],
            formatter: (v) => (v == null ? '' : v.toFixed(0) + '%'),
            style: { fontSize: '10px', fontWeight: 700, colors: ['#ef4444'] },
            background: { enabled: true, foreColor: '#fff', borderRadius: 3, padding: 2, borderWidth: 0, opacity: 0.92 },
            offsetY: -8,
        },
        grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            shared: true,
            intersect: false,
            y: {
                formatter: (v, { seriesIndex }) =>
                    seriesIndex === 0 ? v.toFixed(2) + ' h' : v.toFixed(1) + '%',
            },
            x: { formatter: (v) => v + ' — clic para ver distribución horaria' },
        },
    };
    chartMaqMotivos = new ApexCharts(elChart, options);
    chartMaqMotivos.render();
}

// Nivel 1: clic en motivo → carga la serie temporal por día
async function abrirDrillMaqMotivoPorDia(motivo) {
    if (!_maqMotivosActivo) return;
    _maqMotivosActivo.motivo = motivo;
    _limpiarNivelesDesde(2); // limpia niveles 2 y 3 si los había

    const tipo = _maqMotivosActivo.esRef ? 'Referencia' : 'Máquina';
    $('#maq-motivo-por-dia-sub').textContent =
        '(' + motivo + ' · ' + tipo + ': ' + (_maqMotivosActivo.nombre || _maqMotivosActivo.cod) + ' · clic en un día para detalle horario real)';
    $('#maq-motivo-por-dia-wrap').style.display = '';

    if (_maqMotivoDiaAbort) _maqMotivoDiaAbort.abort();
    _maqMotivoDiaAbort = new AbortController();

    const f = getFiltros();
    const params = addCommonParams({
        fecha_desde: f.desde,
        fecha_hasta: f.hasta,
        motivo,
        por: _maqMotivosActivo.esRef ? 'referencia' : 'maquina',
        mode: 'por_dia',
    }, f);
    if (_maqMotivosActivo.esRef) params.cod_referencia = _maqMotivosActivo.cod;
    else                         params.cod_maquina    = _maqMotivosActivo.cod;

    try {
        const d = await apiFetch('oee_unificado_maq_motivo_temporal.php', params, _maqMotivoDiaAbort.signal);
        renderChartMaqMotivoPorDia(d.dias || [], motivo);
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error serie temporal: ' + e.message, 'error');
    }
}

function renderChartMaqMotivoPorDia(dias, motivo) {
    if (chartMaqMotivoDia) { chartMaqMotivoDia.destroy(); chartMaqMotivoDia = null; }
    const el = document.getElementById('chart-maq-motivo-por-dia');
    el.innerHTML = '';

    if (!dias.length) {
        el.innerHTML = '<div class="drill-down-empty">Sin datos en el rango seleccionado</div>';
        return;
    }
    const cats = dias.map(d => d.dia);
    const vals = dias.map(d => +d.horas);
    const paros = dias.map(d => +d.num_paros);

    const options = {
        chart: {
            type: 'line', height: 280, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                markerClick: (_e, _ctx, cfg) => {
                    if (_renderingCharts) return;
                    const idx = cfg.dataPointIndex;
                    const d = dias[idx];
                    if (d) abrirDrillMaqMotivoPorHoraDia(d.dia);
                },
            },
        },
        series: [{ name: 'Horas paro', data: vals }],
        xaxis: {
            categories: cats,
            labels: {
                style: { colors: '#1a2d4a', fontSize: '10px', fontWeight: 600 },
                rotate: cats.length > 10 ? -45 : 0,
                rotateAlways: cats.length > 10,
                formatter: (v) => {
                    // Mostrar dd/mm para mayor legibilidad
                    if (!v || !v.includes || !v.includes('-')) return v;
                    const p = v.split('-');
                    return p.length === 3 ? p[2] + '/' + p[1] : v;
                },
            },
        },
        yaxis: {
            title: { text: 'Horas de paro', style: { fontSize: '11px', color: '#2d4d7a' } },
            labels: { style: { colors: '#2d4d7a', fontSize: '10px' }, formatter: v => v.toFixed(1) + 'h' },
        },
        stroke: { curve: 'smooth', width: 3 },
        colors: ['#8c181a'],
        markers: { size: 6, hover: { sizeOffset: 3 } },
        dataLabels: {
            enabled: true,
            formatter: v => v > 0 ? v.toFixed(1) + 'h' : '',
            style: { fontSize: '10px', fontWeight: 700, colors: ['#8c181a'] },
            background: { enabled: true, foreColor: '#fff', borderRadius: 3, padding: 2, borderWidth: 0, opacity: 0.92 },
            offsetY: -8,
        },
        grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            x: { formatter: v => v },
            y: { formatter: (v, { dataPointIndex }) => v.toFixed(2) + ' h · ' + (paros[dataPointIndex] || 0) + ' paro(s) · clic para detalle horario' },
        },
    };
    chartMaqMotivoDia = new ApexCharts(el, options);
    chartMaqMotivoDia.render();
}

// Nivel 2: clic en día → carga la 24h real de ese día
async function abrirDrillMaqMotivoPorHoraDia(dia) {
    if (!_maqMotivosActivo || !_maqMotivosActivo.motivo) return;
    _maqMotivosActivo.dia = dia;
    _limpiarNivelesDesde(3);

    $('#maq-motivo-por-hora-dia').textContent = dia;
    $('#maq-motivo-por-hora-wrap').style.display = '';

    if (_maqMotivoHoraAbort) _maqMotivoHoraAbort.abort();
    _maqMotivoHoraAbort = new AbortController();

    const f = getFiltros();
    const params = addCommonParams({
        fecha_desde: f.desde,
        fecha_hasta: f.hasta,
        motivo: _maqMotivosActivo.motivo,
        por: _maqMotivosActivo.esRef ? 'referencia' : 'maquina',
        mode: 'por_hora_dia',
        dia,
    }, f);
    if (_maqMotivosActivo.esRef) params.cod_referencia = _maqMotivosActivo.cod;
    else                         params.cod_maquina    = _maqMotivosActivo.cod;

    try {
        const d = await apiFetch('oee_unificado_maq_motivo_temporal.php', params, _maqMotivoHoraAbort.signal);
        renderChartMaqMotivoPorHora(d.horas || [], _maqMotivosActivo.motivo, dia);
        $('#maq-motivo-por-hora-wrap').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error detalle horario: ' + e.message, 'error');
    }
}

function renderChartMaqMotivoPorHora(horas, motivo, dia) {
    if (chartMaqMotivoHora) { chartMaqMotivoHora.destroy(); chartMaqMotivoHora = null; }
    const el = document.getElementById('chart-maq-motivo-por-hora');
    el.innerHTML = '';

    const totalDia = horas.reduce((s, h) => s + (+h.horas || 0), 0);
    if (totalDia <= 0) {
        el.innerHTML = '<div class="drill-down-empty">Sin paros de este motivo el ' + dia + '</div>';
        return;
    }
    const cats = horas.map(h => String(h.hora).padStart(2, '0'));
    const vals = horas.map(h => +h.horas);

    const options = {
        chart: {
            type: 'bar', height: 280, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    if (_renderingCharts) return;
                    const h = horas[cfg.dataPointIndex];
                    if (!h || (+h.horas) <= 0) return;
                    abrirDrillMaqMotivoParos(dia, h.hora);
                },
            },
        },
        series: [{ name: motivo, data: vals }],
        xaxis: {
            categories: cats,
            title: { text: 'Hora del día', style: { fontSize: '11px', color: '#2d4d7a' } },
            labels: { style: { colors: '#1a2d4a', fontSize: '10px', fontWeight: 600 } },
        },
        yaxis: {
            title: { text: 'Horas de paro', style: { fontSize: '11px', color: '#2d4d7a' } },
            labels: { style: { colors: '#2d4d7a', fontSize: '10px' }, formatter: v => v.toFixed(1) + 'h' },
        },
        plotOptions: { bar: { columnWidth: '70%', borderRadius: 2, borderRadiusApplication: 'end' } },
        colors: ['#8c181a'],
        dataLabels: { enabled: cats.length <= 14, formatter: v => v > 0 ? v.toFixed(1) + 'h' : '', style: { fontSize: '10px', colors: ['#ffffff'], fontWeight: 700 } },
        grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            x: { formatter: v => v + ':00 – ' + v + ':59' },
            y: { formatter: v => v.toFixed(2) + ' h · clic para ver paros individuales' },
        },
    };
    chartMaqMotivoHora = new ApexCharts(el, options);
    chartMaqMotivoHora.render();
}

// Nivel 3: clic en hora → lista de paros individuales en esa franja
async function abrirDrillMaqMotivoParos(dia, hora) {
    if (!_maqMotivosActivo || !_maqMotivosActivo.motivo) return;
    _maqMotivosActivo.hora = hora;

    const hh = String(hora).padStart(2, '0');
    $('#maq-motivo-paros-label').textContent =
        _maqMotivosActivo.motivo + ' · ' + dia + ' · ' + hh + ':00–' + hh + ':59';
    $('#maq-motivo-paros-wrap').style.display = '';

    if (_maqMotivoParosAbort) _maqMotivoParosAbort.abort();
    _maqMotivoParosAbort = new AbortController();

    const f = getFiltros();
    const params = addCommonParams({
        fecha_desde: f.desde,
        fecha_hasta: f.hasta,
        motivo: _maqMotivosActivo.motivo,
        por: _maqMotivosActivo.esRef ? 'referencia' : 'maquina',
        mode: 'paros',
        dia,
        hora,
    }, f);
    if (_maqMotivosActivo.esRef) params.cod_referencia = _maqMotivosActivo.cod;
    else                         params.cod_maquina    = _maqMotivosActivo.cod;

    try {
        const d = await apiFetch('oee_unificado_maq_motivo_temporal.php', params, _maqMotivoParosAbort.signal);
        renderChartMaqMotivoParos(d.paros || []);
        $('#maq-motivo-paros-wrap').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error paros individuales: ' + e.message, 'error');
    }
}

function renderChartMaqMotivoParos(paros) {
    if (chartMaqMotivoParos) { chartMaqMotivoParos.destroy(); chartMaqMotivoParos = null; }
    const el = document.getElementById('chart-maq-motivo-paros');
    el.innerHTML = '';

    if (!paros.length) {
        el.innerHTML = '<div class="drill-down-empty">No hay paros que solapen con esta franja</div>';
        return;
    }
    const esRef = !!(_maqMotivosActivo && _maqMotivosActivo.esRef);

    // Clave de agrupación para colorear:
    //  - Modo Máquina  → agrupamos por referencia (cod_referencia)
    //  - Modo Referencia → agrupamos por máquina (cod_maquina)
    // Paros que comparten entidad heredan el mismo color, así se aprecia visualmente
    // qué paros pertenecen a la misma referencia (o máquina) sin tener que leer la etiqueta.
    const PALETA = ['#8c181a', '#2d4d7a', '#c45a2c', '#2a7a4b', '#6b2d5b', '#3a6aa3', '#b8860b'];
    const keyOf = p => esRef
        ? (p.cod_maquina || '?')
        : (p.cod_referencia || '__SIN_OF__');
    const colorByKey = {};
    let nextColor = 0;
    paros.forEach(p => {
        const k = keyOf(p);
        if (!(k in colorByKey)) {
            colorByKey[k] = k === '__SIN_OF__' ? '#9aa7b8' : PALETA[(nextColor++) % PALETA.length];
        }
    });

    // Etiqueta y de cada barra: "HH:MM:SS  ·  Referencia/Máquina"
    // Para evitar que dos paros con misma hora+entidad colapsen, ApexCharts soporta
    // categorías duplicadas en gráficos horizontales — pero conviene añadir un
    // marcador invisible (zero-width) si fueran idénticas. En la práctica no
    // sucede porque cada paro tiene un timestamp distinto.
    const cats = paros.map(p => {
        if (esRef) return p.hora_ini + '  ·  ' + (p.maquina || '?');
        const refTxt = p.referencia || p.cod_referencia || '(sin OF)';
        return p.hora_ini + '  ·  ' + refTxt;
    });
    const vals = paros.map(p => +p.minutos);
    const colors = paros.map(p => colorByKey[keyOf(p)]);

    const options = {
        chart: {
            type: 'bar', height: Math.max(240, paros.length * 38),
            background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
        },
        series: [{ name: 'Duración', data: vals }],
        xaxis: {
            categories: cats,
            title: { text: 'Minutos', style: { fontSize: '11px', color: '#2d4d7a' } },
            labels: { style: { colors: '#2d4d7a', fontSize: '11px' }, formatter: v => v.toFixed(1) + ' min' },
        },
        yaxis: {
            labels: {
                style: { colors: '#1a2d4a', fontSize: '11px', fontWeight: 600 },
                // Ancho amplio en ambos modos: las refs son largas en modo Máquina,
                // y los nombres de máquina pueden encadenarse también.
                maxWidth: 360,
            },
        },
        plotOptions: { bar: { horizontal: true, barHeight: '65%', borderRadius: 3, borderRadiusApplication: 'end', distributed: true } },
        colors,
        dataLabels: {
            enabled: true,
            style: { colors: ['#ffffff'], fontSize: '11px', fontWeight: 700 },
            formatter: v => v.toFixed(1) + ' min',
        },
        grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            custom: ({ dataPointIndex }) => {
                const p = paros[dataPointIndex];
                // En modo Máquina mostramos la referencia; en modo Referencia, la máquina.
                const extra = esRef
                    ? `<div>Máquina: <strong>${escapeHtml(p.maquina || '?')}</strong></div>`
                    : `<div>Referencia: <strong>${escapeHtml(p.referencia || '(sin OF)')}</strong>${p.cod_referencia ? `<br><span style="color:#a3b8d1;font-size:11px">${escapeHtml(p.cod_referencia)}</span>` : ''}</div>`;
                return `<div style="padding:8px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px">
                    <div style="font-weight:700">${escapeHtml(p.hora_ini)} → ${escapeHtml(p.fecha_fin ? p.fecha_fin.slice(11,19) : '?')}</div>
                    <div>${p.minutos.toFixed(1)} min  (${p.horas.toFixed(2)} h)</div>
                    ${extra}
                </div>`;
            },
        },
    };
    chartMaqMotivoParos = new ApexCharts(el, options);
    chartMaqMotivoParos.render();

    // Pie de leyenda: resumen "entidad → paros, minutos totales" para asociar visualmente
    // cada color con su referencia (modo Máquina) o máquina (modo Referencia).
    const resumen = {};
    paros.forEach(p => {
        const k = keyOf(p);
        const label = esRef ? (p.maquina || k) : (p.referencia || p.cod_referencia || '(sin OF)');
        if (!resumen[k]) resumen[k] = { label, color: colorByKey[k], n: 0, mins: 0 };
        resumen[k].n += 1;
        resumen[k].mins += (+p.minutos || 0);
    });
    const tipoEntidad = esRef ? 'Máquina' : 'Referencia';
    const items = Object.values(resumen).map(r =>
        `<span class="maq-motivo-paros-chip" style="border-color:${r.color}">
            <span class="maq-motivo-paros-dot" style="background:${r.color}"></span>
            <strong>${escapeHtml(r.label)}</strong>
            <span class="maq-motivo-paros-meta">· ${r.n} paro${r.n === 1 ? '' : 's'} · ${r.mins.toFixed(1)} min</span>
        </span>`
    ).join('');
    el.insertAdjacentHTML('beforeend',
        `<div class="maq-motivo-paros-resumen">
            <span class="maq-motivo-paros-resumen-title">${tipoEntidad}${Object.keys(resumen).length > 1 ? 's involucradas' : ' involucrada'}:</span>
            ${items}
        </div>`);
}

function renderChartMotivoDet(data, metrica, motivoNombre) {
    const isHoras = (metrica === 'disponibilidad' || metrica === 'oee' || metrica === 'rendimiento');
    const valKey = isHoras ? 'horas' : 'unidades';
    const valLabel = isHoras ? 'h' : 'uds';
    const esRef = _metricaPor === 'referencia';

    if (!data.length) {
        if (chartMotivoDet) { chartMotivoDet.destroy(); chartMotivoDet = null; }
        $('#chart-motivo-maquinas').innerHTML = '<div class="drill-down-empty">Sin datos para este motivo</div>';
        return;
    }

    const cats = data.map(d => d.maquina);
    const vals = data.map(d => d[valKey] ?? 0);
    const pcts = data.map(d => d.pct);
    const color = METRICA_COLORS[metrica] || '#3a6aa3';

    // Recordar nombres para chips de exclusión (solo cuando son máquinas reales)
    if (!esRef) data.forEach(d => { if (d.cod_maquina) _maqLookup[d.cod_maquina] = d.maquina; });

    const options = {
        chart: {
            type: 'bar', height: Math.max(220, data.length * 38),
            background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    if (_renderingCharts) return;
                    const row = data[cfg.dataPointIndex];
                    if (!row) return;
                    toggleMotivoHoraMaquina(row.cod_maquina, row.maquina);
                },
            },
        },
        series: [{ name: motivoNombre, data: vals }],
        xaxis: {
            categories: cats,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '11px' },
                formatter: v => isHoras ? v.toFixed(1) + 'h' : Math.round(v).toString(),
            },
        },
        yaxis: {
            labels: {
                style: { colors: '#1a2d4a', fontSize: '11px', fontWeight: 600 },
                // En modo Referencia las descripciones son largas: ensancha la columna
                // de etiquetas (el plot area se reduce automáticamente).
                maxWidth: esRef ? 340 : 140,
            },
        },
        plotOptions: {
            bar: {
                horizontal: true,
                barHeight: '65%',
                borderRadius: 3,
                borderRadiusApplication: 'end',
                distributed: true,
                dataLabels: { position: 'center' },
            },
        },
        colors: data.map(() => color),
        states: {
            hover:  { filter: { type: 'lighten', value: 0.08 } },
            active: { filter: { type: 'darken',  value: 0.10 } },
        },
        dataLabels: {
            enabled: true,
            style: { colors: ['#ffffff'], fontFamily: 'Arial', fontSize: '12px', fontWeight: 700 },
            formatter: (v, { dataPointIndex }) => {
                const p = pcts[dataPointIndex];
                if (isHoras) return v.toFixed(1) + 'h (' + p.toFixed(0) + '%)';
                return Math.round(v) + ' uds (' + p.toFixed(0) + '%)';
            },
        },
        grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            custom: ({ dataPointIndex }) => {
                const r = data[dataPointIndex];
                const v = isHoras
                    ? r.horas.toFixed(2) + ' h (' + r.minutos.toFixed(0) + ' min)'
                    : r.unidades + ' uds';
                return `
                    <div style="padding:8px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px">
                        <div style="font-weight:700">${r.maquina}</div>
                        <div>${v}</div>
                        <div style="color:#a3b8d1;font-size:11px">${r.pct.toFixed(1)}% del total</div>
                    </div>
                `;
            },
        },
    };

    if (chartMotivoDet) chartMotivoDet.destroy();
    chartMotivoDet = new ApexCharts($('#chart-motivo-maquinas'), options);
    chartMotivoDet.render();
}

// ───── Paso 3b (continuación): distribución horaria del motivo ─────

const _HORA_OTRAS_COLOR  = '#9aa7b8';
const _HORA_COLOR_PALETA = ['#8c181a', '#2d4d7a', '#c45a2c', '#6b2d5b', '#2a7a4b', '#3a6aa3'];

let _motivoHoraAbort = null;

// Toggle de filtro horario por máquina/referencia: si se reclica el mismo, vuelve
// al apilado de todas. Si no, recarga la distribución horaria solo para ese.
function toggleMotivoHoraMaquina(cod, nombre) {
    if (!_motivoActivo || !cod) return;
    if (_motivoActivo.codFiltroHora === cod) {
        _motivoActivo.codFiltroHora = null;
    } else {
        _motivoActivo.codFiltroHora = cod;
        if (nombre) _maqLookup[cod] = nombre;
    }
    cargarMotivoHora();
}

function actualizarMotivoHoraSubtitulo() {
    const sub  = $('#motivo-hora-sub');
    const btn  = $('#motivo-hora-clear');
    if (!sub || !btn) return;
    const cod = _motivoActivo && _motivoActivo.codFiltroHora;
    const esRef = _metricaPor === 'referencia';
    if (cod) {
        const nombre = _maqLookup[cod] || cod;
        sub.innerHTML = '(filtrado a <strong>' + escapeHtml(nombre) + '</strong>)';
        btn.style.display = '';
    } else {
        const hint = esRef
            ? '(hora del día 00–23, agregada sobre el rango · clic en una referencia arriba para filtrar)'
            : '(hora del día 00–23, agregada sobre el rango · clic en una máquina arriba para filtrar)';
        sub.textContent = hint;
        btn.style.display = 'none';
    }
}

async function cargarMotivoHora() {
    if (!_motivoActivo || !_seccionActiva || !_metricaActiva) return;
    if (_motivoHoraAbort) _motivoHoraAbort.abort();
    _motivoHoraAbort = new AbortController();

    const f = getFiltros();
    const params = addCommonParams({
        fecha_desde: f.desde,
        fecha_hasta: f.hasta,
        seccion: _seccionActiva,
        metrica: _metricaActiva,
        motivo: _motivoActivo.nombre,
        por: _metricaPor,
    }, f);
    if (_motivoActivo.codFiltroHora) {
        if (_metricaPor === 'referencia') params.cod_referencia = _motivoActivo.codFiltroHora;
        else                              params.cod_maquina    = _motivoActivo.codFiltroHora;
    }

    try {
        const d = await apiFetch('oee_unificado_motivo_drill.php', params, _motivoHoraAbort.signal);
        renderChartMotivoHora(d.por_hora || null, d.metrica, _motivoActivo.nombre);
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error distribución horaria: ' + e.message, 'error');
    }
}

function renderChartMotivoHora(porHora, metrica, motivoNombre) {
    if (chartMotivoHora) { chartMotivoHora.destroy(); chartMotivoHora = null; }
    actualizarMotivoHoraSubtitulo();

    if (!porHora || !Array.isArray(porHora.horas) || !porHora.horas.length || !Array.isArray(porHora.maquinas) || !porHora.maquinas.length) {
        $('#chart-motivo-hora').innerHTML = '<div class="drill-down-empty">Sin desglose horario disponible</div>';
        return;
    }

    const unidad = porHora.unidad === 'h' ? 'h' : 'uds';
    const cats = porHora.horas.map(h => String(h.h).padStart(2, '0'));

    // Las referencias pueden venir tipadas como número (193033650001) cuando el
    // Cod_producto parece entero. Forzamos string en ambos lados de la lookup.
    const series = porHora.maquinas.map(m => {
        const cod = String(m.cod_maquina);
        return {
            name: String(m.maquina || cod),
            data: porHora.horas.map(h => +(h[cod] ?? 0)),
        };
    });

    const colors = porHora.maquinas.map((m, i) =>
        String(m.cod_maquina) === '__OTRAS__' ? _HORA_OTRAS_COLOR : _HORA_COLOR_PALETA[i % _HORA_COLOR_PALETA.length]
    );

    // Altura dinámica: cada referencia con nombre largo añade ~20px a la leyenda.
    // Sin esto, con 6 refs de 50+ caracteres la leyenda ocupaba el chart entero.
    const nombreMax = series.reduce((m, s) => Math.max(m, s.name.length), 0);
    const filasLeyenda = nombreMax > 25 ? series.length : Math.ceil(series.length / 3);
    const chartHeight = 280 + filasLeyenda * 22;

    const options = {
        chart: {
            type: 'bar',
            stacked: true,
            height: chartHeight,
            background: 'transparent',
            toolbar: { show: false },
            fontFamily: 'Arial',
        },
        series,
        xaxis: {
            categories: cats,
            title: { text: 'Hora del día', style: { fontSize: '11px', color: '#2d4d7a' } },
            labels: { style: { colors: '#1a2d4a', fontSize: '10px', fontWeight: 600 } },
        },
        yaxis: {
            title: {
                text: unidad === 'h' ? 'Horas' : 'Unidades',
                style: { fontSize: '11px', color: '#2d4d7a' },
            },
            labels: {
                style: { colors: '#2d4d7a', fontSize: '10px' },
                formatter: v => unidad === 'h' ? (v.toFixed ? v.toFixed(1) + 'h' : v + 'h') : Math.round(v).toString(),
            },
        },
        plotOptions: {
            bar: {
                columnWidth: '70%',
                borderRadius: 2,
                borderRadiusApplication: 'end',
                borderRadiusWhenStacked: 'last',
            },
        },
        dataLabels: { enabled: false },
        stroke: { show: true, width: 1, colors: ['transparent'] },
        colors,
        grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
        legend: {
            position: 'bottom', fontSize: '11px',
            markers: { width: 10, height: 10 },
            // Trunca nombres largos en la leyenda (en modo referencia las descripciones
            // pueden tener 50+ caracteres y desbordan el contenedor).
            formatter: (name) => name.length > 32 ? name.slice(0, 30) + '…' : name,
            itemMargin: { horizontal: 8, vertical: 2 },
        },
        tooltip: {
            shared: true,
            intersect: false,
            x: { formatter: v => v + ':00 – ' + v + ':59' },
            y: {
                formatter: v => {
                    if (v == null || v === 0) return unidad === 'h' ? '0 h' : '0 uds';
                    return unidad === 'h' ? v.toFixed(2) + ' h' : Math.round(v) + ' uds';
                },
            },
        },
        states: {
            hover:  { filter: { type: 'lighten', value: 0.08 } },
            active: { filter: { type: 'darken',  value: 0.10 } },
        },
    };

    chartMotivoHora = new ApexCharts($('#chart-motivo-hora'), options);
    chartMotivoHora.render();
}

// ───── Exclusión global de máquinas (filtro de análisis) ─────

function recargarDebounced() {
    if (_reloadTimer) clearTimeout(_reloadTimer);
    _reloadTimer = setTimeout(() => { _reloadTimer = null; cargar(); }, 350);
}

function toggleExclusion(codMaq, nombre) {
    if (!codMaq) return;
    if (_maqExcl.has(codMaq)) {
        _maqExcl.delete(codMaq);
    } else {
        _maqExcl.add(codMaq);
        if (nombre) _maqLookup[codMaq] = nombre;
    }
    renderExclBadge();
    updateMaqExclToggleCount();
    recargarDebounced();
}

function quitarExclusion(codMaq) {
    if (!_maqExcl.has(codMaq)) return;
    _maqExcl.delete(codMaq);
    renderExclBadge();
    updateMaqExclToggleCount();
    recargarDebounced();
}

function limpiarExclusiones() {
    if (_maqExcl.size === 0) return;
    _maqExcl.clear();
    renderExclBadge();
    updateMaqExclToggleCount();
    recargarDebounced();
}

// ───── Selector desplegable de máquinas para exclusión ─────

function updateMaqExclToggleCount() {
    const el = $('#maq-excl-toggle-count');
    if (el) el.textContent = String(_maqExcl.size);
}

function renderMaqExclList(filter) {
    const list = $('#maq-excl-list');
    if (!list) return;
    if (!_maquinasActivas.length) {
        list.innerHTML = '<em class="maq-excl-empty">Sin máquinas en el rango/turnos seleccionados</em>';
        return;
    }
    const q = (filter || '').trim().toLowerCase();
    const filtered = q
        ? _maquinasActivas.filter(m =>
            (m.maquina || '').toLowerCase().includes(q) ||
            (m.cod_maquina || '').toLowerCase().includes(q))
        : _maquinasActivas;
    if (!filtered.length) {
        list.innerHTML = '<em class="maq-excl-empty">Sin coincidencias</em>';
        return;
    }
    let html = '';
    let prevSec = null;
    filtered.forEach(m => {
        if (m.seccion !== prevSec) {
            html += `<div class="maq-excl-section-title">${escapeHtml(m.seccion)}</div>`;
            prevSec = m.seccion;
        }
        html += `
            <label class="maq-excl-item">
                <input type="checkbox" class="maq-excl-cb"
                       data-cod="${escapeHtml(m.cod_maquina)}"
                       data-name="${escapeHtml(m.maquina)}">
                <span class="maq-excl-item-name">${escapeHtml(m.maquina)}</span>
            </label>
        `;
    });
    list.innerHTML = html;
}

function openMaqExclPanel() {
    const panel  = $('#maq-excl-panel');
    const toggle = $('#maq-excl-toggle');
    if (!panel || !toggle) return;
    panel.hidden = false;
    toggle.classList.add('open');
    const search = $('#maq-excl-search');
    if (search) {
        search.value = '';
        renderMaqExclList('');
        setTimeout(() => search.focus(), 30);
    }
}
function closeMaqExclPanel() {
    const panel  = $('#maq-excl-panel');
    const toggle = $('#maq-excl-toggle');
    if (!panel || !toggle) return;
    panel.hidden = true;
    toggle.classList.remove('open');
}
function toggleMaqExclPanel() {
    const panel = $('#maq-excl-panel');
    if (!panel) return;
    panel.hidden ? openMaqExclPanel() : closeMaqExclPanel();
}

function renderExclBadge() {
    const bar   = $('#maq-excl-bar');
    const chips = $('#maq-excl-chips');
    if (!bar || !chips) return;
    if (_maqExcl.size === 0) {
        bar.style.display = 'none';
        chips.innerHTML = '';
        return;
    }
    const items = Array.from(_maqExcl).map(cod => {
        const name = _maqLookup[cod] || cod;
        return `
            <span class="maq-excl-chip" data-cod="${escapeHtml(cod)}" title="Quitar exclusión">
                <span class="maq-excl-chip-name">${escapeHtml(name)}</span>
                <button type="button" class="maq-excl-chip-remove" data-cod="${escapeHtml(cod)}" aria-label="Quitar">×</button>
            </span>
        `;
    }).join('');
    chips.innerHTML = items;
    bar.style.display = '';
}

// ───── Cargar datos y refrescar ─────
async function cargar() {
    const f = getFiltros();
    if (!f.desde || !f.hasta) return;
    if (f.desde > f.hasta) {
        showToast('La fecha "Desde" no puede ser posterior a "Hasta"', 'error');
        return;
    }
    saveState(f);

    const params = addCommonParams({ fecha_desde: f.desde, fecha_hasta: f.hasta }, f);

    showLoader(true);
    try {
        const d = await apiFetch('oee_unificado.php', params);
        const rango = (f.desde === f.hasta)
            ? fmtFechaCorta(f.desde)
            : fmtFechaCorta(f.desde) + ' → ' + fmtFechaCorta(f.hasta);
        $('#info-line').textContent = rango + ' · ' + labelTurnos(f.turnos) + ' · ' + d.global.maquinas + ' máquinas';
        $('#header-scope').textContent = '';
        const numOfs = d.global?.num_ofs ?? 0;
        $('#kpi-num-ofs').textContent = numOfs + ' OF' + (numOfs === 1 ? '' : 's');
        const ofsMaq = d.global?.ofs_por_maquina || [];
        $('#kpi-num-ofs').title = ofsMaq.length
            ? 'OFs por máquina:\n' + ofsMaq.map(m => `  ${m.maquina}: ${m.num_ofs} OF${m.num_ofs === 1 ? '' : 's'}`).join('\n')
            : 'Sin OFs en el rango';

        const numRefs = d.global?.num_refs ?? 0;
        $('#kpi-num-refs').textContent = numRefs + ' Ref' + (numRefs === 1 ? '' : 's');
        const refsMaq = d.global?.refs_por_maquina || [];
        $('#kpi-num-refs').title = refsMaq.length
            ? 'Referencias por máquina:\n' + refsMaq.map(m => `  ${m.maquina}: ${m.num_refs} Ref${m.num_refs === 1 ? '' : 's'}`).join('\n')
            : 'Sin referencias en el rango';

        // Alimentar el lookup de nombres para los chips de exclusión
        ofsMaq.forEach(m  => { if (m.cod_maquina) _maqLookup[m.cod_maquina] = m.maquina; });
        refsMaq.forEach(m => { if (m.cod_maquina) _maqLookup[m.cod_maquina] = m.maquina; });

        // Listado para el selector de exclusión
        _maquinasActivas = d.global?.maquinas_activas || [];
        _maquinasActivas.forEach(m => { if (m.cod_maquina) _maqLookup[m.cod_maquina] = m.maquina; });
        const search = $('#maq-excl-search');
        renderMaqExclList(search ? search.value : '');
        updateMaqExclToggleCount();

        renderExclBadge();

        cargarEvolucion(f);

        _lastSecciones = d.secciones || [];
        renderSecciones(_lastSecciones);

        // Si había un drill de sección abierto, refrescarlo
        if (_seccionActiva) {
            const sec = _lastSecciones.find(s => s.seccion === _seccionActiva);
            if (sec) {
                renderSeccionDrc(sec);
                // Si había un drill de métrica abierto, refrescarlo también
                if (_metricaActiva) cargarDrillMetrica();
            } else {
                cerrarDrillSeccion();
            }
        }
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

// ───── Init ─────
// ───── Evolución OEE (semana/mes auto) ─────

// La sección de la evolución está vinculada a `_seccionActiva` (la sección clicada
// en el chart superior). Sin sección activa → "Todas".
function _actualizarEvolucionSeccionLabel() {
    const el = $('#evolucion-seccion-label');
    if (el) el.textContent = _seccionActiva || 'Todas';
}

async function cargarEvolucion(f) {
    if (_evolucionAbort) _evolucionAbort.abort();
    _evolucionAbort = new AbortController();

    const params = addCommonParams({ fecha_desde: f.desde, fecha_hasta: f.hasta }, f);
    if (_seccionActiva) params.seccion = _seccionActiva;
    _actualizarEvolucionSeccionLabel();

    try {
        const d = await apiFetch('oee_unificado_evolucion.php', params, _evolucionAbort.signal);
        renderChartEvolucion(d.periodos || [], d.granularidad || 'WEEK');
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error evolución: ' + e.message, 'error');
    }
}

const EVO_SERIES = [
    { key: 'oee',            name: 'OEE',            color: '#8c181a', type: 'area' },
    { key: 'disponibilidad', name: 'Disponibilidad', color: '#2d4d7a', type: 'line' },
    { key: 'rendimiento',    name: 'Rendimiento',    color: '#c45a2c', type: 'line' },
    { key: 'calidad',        name: 'Calidad',        color: '#2a7a4b', type: 'line' },
];
const LS_EVO_VISIBLE = 'kh_oee_unificado_evo_series';

function loadEvoVisible() {
    try {
        const raw = localStorage.getItem(LS_EVO_VISIBLE);
        if (raw) {
            const arr = JSON.parse(raw);
            if (Array.isArray(arr)) return new Set(arr);
        }
    } catch (_) {}
    return new Set(['oee']);
}
function saveEvoVisible(set) {
    try { localStorage.setItem(LS_EVO_VISIBLE, JSON.stringify([...set])); } catch (_) {}
}

function renderChartEvolucion(periodos, granularidad) {
    const labelMap = { DAY: 'Diaria', WEEK: 'Semanal', MONTH: 'Mensual' };
    const unidadMap = {
        DAY:   periodos.length === 1 ? 'día'    : 'días',
        WEEK:  periodos.length === 1 ? 'semana' : 'semanas',
        MONTH: periodos.length === 1 ? 'mes'    : 'meses',
    };
    const label = labelMap[granularidad] || granularidad;
    const unidad = unidadMap[granularidad] || '';
    $('#evolucion-granularidad-label').textContent = `· ${label} (${periodos.length} ${unidad})`;

    if (chartEvolucion) { chartEvolucion.destroy(); chartEvolucion = null; }

    if (!periodos.length) {
        $('#chart-evolucion').innerHTML = '<div class="drill-down-empty">Sin datos en el rango</div>';
        return;
    }

    const cats = periodos.map(p => p.label);

    // Sincroniza checkboxes con la preferencia guardada
    const visible = loadEvoVisible();
    if (visible.size === 0) visible.add('oee');
    $$('#evolucion-toggles input[data-evo-serie]').forEach(cb => {
        cb.checked = visible.has(cb.dataset.evoSerie);
    });

    // Construir series: OEE área primero, métricas como líneas
    const series = EVO_SERIES.map(s => ({
        name: s.name,
        type: s.type,
        data: periodos.map(p => +p[s.key]),
    }));

    // Eje Y dinámico: cualquier serie puede superar 100% (típicamente Rendimiento).
    // Redondea al múltiplo de 10 superior con +10% de holgura para que las etiquetas no queden pegadas al borde.
    let dataMax = 0;
    series.forEach(s => s.data.forEach(v => { if (typeof v === 'number' && v > dataMax) dataMax = v; }));
    const yMax = Math.max(100, Math.ceil((dataMax * 1.1) / 10) * 10);
    const yTicks = Math.max(5, Math.round(yMax / 20));

    const options = {
        chart: {
            type: 'line', height: 460,
            background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
            zoom: { enabled: false },
            events: {
                mounted: () => syncEvoVisibility(),
                updated: () => syncEvoVisibility(),
            },
        },
        series,
        xaxis: {
            categories: cats,
            labels: {
                style: { colors: '#1a2d4a', fontSize: '11px', fontWeight: 600 },
                rotate: cats.length > 14 ? -45 : 0,
                rotateAlways: cats.length > 14,
                hideOverlappingLabels: true,
                trim: true,
            },
            tickPlacement: 'on',
        },
        yaxis: {
            min: 0, max: yMax,
            tickAmount: yTicks,
            title: { text: '%', style: { fontSize: '11px', color: '#2d4d7a' } },
            labels: {
                style: { colors: '#2d4d7a', fontSize: '10px' },
                formatter: v => v.toFixed(0) + '%',
            },
        },
        colors: EVO_SERIES.map(s => s.color),
        stroke: { curve: 'smooth', width: EVO_SERIES.map(s => s.type === 'area' ? 3 : 2) },
        fill: {
            type: EVO_SERIES.map(s => s.type === 'area' ? 'gradient' : 'solid'),
            gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05, stops: [0, 100] },
        },
        markers: { size: cats.length > 30 ? 3 : 4, hover: { sizeOffset: 2 } },
        dataLabels: {
            // Etiquetas en TODAS las series (D/R/C/OEE) con color por serie.
            enabled: true,
            enabledOnSeries: EVO_SERIES.map((_, i) => i),
            formatter: v => (v == null ? '' : v.toFixed(0) + '%'),
            style: { fontSize: '9px', fontWeight: 700, colors: EVO_SERIES.map(s => s.color) },
            background: { enabled: true, foreColor: '#fff', borderRadius: 3, padding: 2, borderWidth: 0, opacity: 0.92 },
            offsetY: -6,
        },
        grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
        annotations: {
            yaxis: [{
                y: 75, borderColor: '#8c181a', strokeDashArray: 4,
                label: {
                    text: 'Objetivo OEE 75%',
                    style: { background: '#8c181a', color: '#fff', fontSize: '10px' },
                    position: 'right',
                    offsetX: -5,
                },
            }],
            xaxis: buildEvoXAxisAnnotations(periodos, granularidad),
        },
        legend: { show: false },
        tooltip: {
            shared: true,
            intersect: false,
            x: { formatter: (_v, ctx) => {
                const p = periodos[ctx.dataPointIndex];
                return p ? p.label : '';
            }},
            y: {
                formatter: (v) => (v == null ? '—' : v.toFixed(1) + '%'),
            },
        },
    };

    chartEvolucion = new ApexCharts($('#chart-evolucion'), options);
    chartEvolucion.render();
}

// Construye bandas para fines de semana / festivos en la evolución diaria.
// En granularidad WEEK / MONTH no aplica.
function buildEvoXAxisAnnotations(periodos, granularidad) {
    if (granularidad !== 'DAY' || !Array.isArray(periodos)) return [];
    const ann = [];
    periodos.forEach((p, i) => {
        if (p.tipo_dia !== 'weekend' && p.tipo_dia !== 'holiday') return;
        const isHoliday = p.tipo_dia === 'holiday';
        const next = periodos[i + 1];
        const x  = p.label;
        const x2 = next ? next.label : p.label;
        ann.push({
            x, x2,
            fillColor: isHoliday ? '#fdf5f5' : '#eef2f7',
            opacity: isHoliday ? 0.55 : 0.55,
            borderColor: 'transparent',
            label: isHoliday ? {
                text: '★',
                style: { background: '#8c181a', color: '#fff', fontSize: '9px', fontWeight: 700 },
                position: 'top',
                offsetY: 4,
            } : undefined,
        });
    });
    return ann;
}

// Mostrar/ocultar series sin destruir el chart
function syncEvoVisibility() {
    if (!chartEvolucion) return;
    const visible = loadEvoVisible();
    EVO_SERIES.forEach(s => {
        const fn = visible.has(s.key) ? 'showSeries' : 'hideSeries';
        try { chartEvolucion[fn](s.name); } catch (_) {}
    });
}

function setupEvolucionToggles() {
    $$('#evolucion-toggles input[data-evo-serie]').forEach(cb => {
        cb.addEventListener('change', () => {
            const visible = loadEvoVisible();
            const key = cb.dataset.evoSerie;
            if (cb.checked) visible.add(key); else visible.delete(key);
            // Garantiza al menos una serie visible
            if (visible.size === 0) { visible.add('oee'); $('#evolucion-toggles input[data-evo-serie="oee"]').checked = true; }
            saveEvoVisible(visible);
            syncEvoVisibility();
        });
    });

    // Toggles del chart de evolución por máquina (panel intermedio del drill métrica)
    $$('#maq-evolucion-toggles input[data-evo-maq-serie]').forEach(cb => {
        cb.addEventListener('change', () => {
            const visible = loadEvoMaqVisible();
            const key = cb.dataset.evoMaqSerie;
            if (cb.checked) visible.add(key); else visible.delete(key);
            if (visible.size === 0) {
                visible.add('oee');
                $('#maq-evolucion-toggles input[data-evo-maq-serie="oee"]').checked = true;
            }
            saveEvoMaqVisible(visible);
            syncEvoMaqVisibility();
        });
    });
}

function escapeHtml(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// ============================================================
//  Bloque inline "Análisis Top — Disponibilidad"
//  - Radio en el footer: ocultar / Top máquinas / Top motivos
//  - Solo se muestra uno a la vez, con sus filtros y exportación propios
//  - El histograma por fecha aparece sólo al clicar una barra
// ============================================================

const LS_TOP_INLINE = 'kh_oee_unificado_top_inline';
const _TOP_PALETA  = ['#8c181a', '#c45a2c', '#2d4d7a', '#6b2d5b', '#2a7a4b', '#3a6aa3', '#a82124', '#1a2d4a'];
function topColor(i) { return _TOP_PALETA[i % _TOP_PALETA.length]; }

// Dos bloques independientes ('maquinas' | 'motivos'). Cada uno tiene sus
// propios IDs con prefijo "top-${mode}-…". El botón #top-show-next revela el
// siguiente bloque oculto siguiendo el orden [maquinas, motivos].
const TOP_MODES = ['maquinas', 'motivos'];
const TOP_LABELS = { maquinas: 'Top máquinas', motivos: 'Top motivos' };
const _topCharts    = { maquinas: null, motivos: null };
const _topDetCharts = { maquinas: null, motivos: null };
const _topAborts    = {
    maquinas:    null, motivos:    null,
    maquinasDet: null, motivosDet: null,
};

function topInlineLoadState() {
    try {
        const raw = localStorage.getItem(LS_TOP_INLINE);
        if (!raw) return {};
        const d = JSON.parse(raw);
        return d && typeof d === 'object' ? d : {};
    } catch (_) { return {}; }
}
function topInlineSaveState(mode) {
    try {
        const st = topInlineLoadState();
        st[mode] = {
            seccion: $(`#top-${mode}-seccion`).value || 'VARILLAS',
            desde:   $(`#top-${mode}-desde`).value || '',
            hasta:   $(`#top-${mode}-hasta`).value || '',
            n:       parseInt($(`#top-${mode}-n`).value || '5', 10) || 5,
        };
        localStorage.setItem(LS_TOP_INLINE, JSON.stringify(st));
    } catch (_) {}
}

function topDestroyChart(mode, which) {
    const slot = which === 'det' ? _topDetCharts : _topCharts;
    if (slot[mode]) { try { slot[mode].destroy(); } catch (_) {} slot[mode] = null; }
}
function topAbort(key) {
    if (_topAborts[key]) { try { _topAborts[key].abort(); } catch (_) {} _topAborts[key] = null; }
}

function topReadFiltros(mode) {
    const seccion = $(`#top-${mode}-seccion`).value || 'VARILLAS';
    const desde   = $(`#top-${mode}-desde`).value;
    const hasta   = $(`#top-${mode}-hasta`).value;
    let n = parseInt($(`#top-${mode}-n`).value || '5', 10);
    if (!Number.isFinite(n) || n < 1) n = 5;
    if (n > 20) n = 20;
    $(`#top-${mode}-n`).value = n;
    return { seccion, desde, hasta, n };
}

function topCommonParams(mode) {
    const f = getFiltros();
    const { seccion, desde, hasta, n } = topReadFiltros(mode);
    const params = { fecha_desde: desde, fecha_hasta: hasta, seccion, top_n: n };
    if (Array.isArray(f.turnos) && f.turnos.length) params.turnos = f.turnos.join(',');
    // Exclusión efectiva = global del panel principal ∪ específica del Top
    // (sólo aplica para el modo "maquinas").
    const merged = new Set(_maqExcl);
    if (mode === 'maquinas') _topMaqExcl.forEach(c => merged.add(c));
    if (merged.size) params.excl = [...merged].join(',');
    return params;
}

// ───── Exclusión específica del Top máquinas ─────
const _topMaqExcl  = new Set();         // cod_maquina excluidos solo para el Top
let   _topMaqList  = [];                // [{cod_maquina, maquina}] de la sección
let   _topMaqListSeccion = null;        // sección para la que se cargó la lista
let   _topMaqListLoading = false;

function topMaqExclState() {
    try {
        const raw = localStorage.getItem('kh_oee_unificado_top_maq_excl');
        if (!raw) return [];
        const a = JSON.parse(raw);
        return Array.isArray(a) ? a.filter(v => typeof v === 'string' && v) : [];
    } catch (_) { return []; }
}
function topMaqExclSave() {
    try { localStorage.setItem('kh_oee_unificado_top_maq_excl', JSON.stringify([..._topMaqExcl])); } catch (_) {}
}

async function topMaqLoadLista() {
    if (_topMaqListLoading) return;
    const { seccion, desde, hasta } = topReadFiltros('maquinas');
    if (!desde || !hasta) {
        renderTopMaqExclList('Indica primero un rango Desde/Hasta');
        return;
    }
    _topMaqListLoading = true;
    try {
        const f = getFiltros();
        const params = {
            mode: 'maquinas_seccion',
            fecha_desde: desde,
            fecha_hasta: hasta,
            seccion,
        };
        if (Array.isArray(f.turnos) && f.turnos.length) params.turnos = f.turnos.join(',');
        const d = await apiFetch('oee_unificado_top_analisis.php', params);
        _topMaqList = (d.maquinas || []);
        _topMaqListSeccion = seccion;
        renderTopMaqExclList();
    } catch (e) {
        renderTopMaqExclList('Error cargando máquinas: ' + e.message);
    } finally {
        _topMaqListLoading = false;
    }
}

function renderTopMaqExclList(errorMsg) {
    const list = $('#top-maq-excl-list');
    if (!list) return;
    if (errorMsg) {
        list.innerHTML = '<em class="top-excl-empty">' + escapeHtml(errorMsg) + '</em>';
        return;
    }
    if (!_topMaqList.length) {
        list.innerHTML = '<em class="top-excl-empty">Sin máquinas en la sección.</em>';
        return;
    }
    const q = ($('#top-maq-excl-search')?.value || '').trim().toLowerCase();
    const filtered = q
        ? _topMaqList.filter(m =>
            (m.maquina || '').toLowerCase().includes(q) ||
            (m.cod_maquina || '').toLowerCase().includes(q))
        : _topMaqList;
    if (!filtered.length) {
        list.innerHTML = '<em class="top-excl-empty">Sin coincidencias</em>';
        return;
    }
    list.innerHTML = filtered.map(m => `
        <label class="top-excl-item">
            <input type="checkbox" class="top-excl-cb"
                   data-cod="${escapeHtml(m.cod_maquina)}"
                   data-name="${escapeHtml(m.maquina)}"
                   ${_topMaqExcl.has(m.cod_maquina) ? 'checked' : ''}>
            <span class="top-excl-item-name">${escapeHtml(m.maquina)}</span>
        </label>
    `).join('');
}

function renderTopMaqExclChips() {
    const wrap = $('#top-maq-excl-chips');
    const count = $('#top-maq-excl-count');
    if (count) count.textContent = String(_topMaqExcl.size);
    if (!wrap) return;
    if (_topMaqExcl.size === 0) { wrap.innerHTML = ''; return; }
    // Etiqueta legible buscando en la lista cargada o en el lookup global.
    const nameOf = (cod) => {
        const row = _topMaqList.find(m => m.cod_maquina === cod);
        if (row) return row.maquina;
        return _maqLookup[cod] || cod;
    };
    wrap.innerHTML = [..._topMaqExcl].map(cod => `
        <span class="top-excl-chip">
            ${escapeHtml(nameOf(cod))}
            <button type="button" class="top-excl-chip-remove" data-cod="${escapeHtml(cod)}" title="Quitar">×</button>
        </span>
    `).join('');
}

function toggleTopMaqExclPanel() {
    const panel  = $('#top-maq-excl-panel');
    const toggle = $('#top-maq-excl-toggle');
    if (!panel || !toggle) return;
    const willOpen = panel.hidden;
    panel.hidden = !willOpen;
    toggle.classList.toggle('open', willOpen);
    if (willOpen) {
        $('#top-maq-excl-search').value = '';
        // Si la lista no se ha cargado o la sección cambió, recárgala
        const sec = $('#top-maquinas-seccion').value;
        if (!_topMaqList.length || _topMaqListSeccion !== sec) topMaqLoadLista();
        else renderTopMaqExclList();
    }
}

function closeTopMaqExclPanel() {
    $('#top-maq-excl-panel').hidden = true;
    $('#top-maq-excl-toggle').classList.remove('open');
}

function setTopMaqExclusion(cod, on, name) {
    if (!cod) return;
    if (on) _topMaqExcl.add(cod);
    else    _topMaqExcl.delete(cod);
    if (name) _maqLookup[cod] = name;
    topMaqExclSave();
    renderTopMaqExclChips();
    // Recarga el top automáticamente si ya hay datos previos
    if (!$('#top-maquinas-block').hidden) topAplicar('maquinas');
}

function topResetDetalle(mode) {
    $(`#top-${mode}-detalle-empty`).style.display = '';
    $(`#top-${mode}-detalle-empty`).textContent = mode === 'maquinas'
        ? 'Haz clic en una máquina para ver su histograma por fecha.'
        : 'Haz clic en un motivo para ver su histograma por fecha.';
    $(`#top-${mode}-detalle-title`).style.display = 'none';
    document.getElementById(`top-${mode}-detalle-chart`).innerHTML = '';
    topDestroyChart(mode, 'det');
}

function topShowBlock(mode) {
    // Defaults: estado guardado o filtro principal
    const sub = topInlineLoadState()[mode] || {};
    const f   = getFiltros();
    $(`#top-${mode}-seccion`).value = sub.seccion || _seccionActiva || 'VARILLAS';
    $(`#top-${mode}-desde`).value   = sub.desde   || f.desde || '';
    $(`#top-${mode}-hasta`).value   = sub.hasta   || f.hasta || '';
    $(`#top-${mode}-n`).value       = sub.n       || 5;

    // Reset visual del chart + detalle
    document.getElementById(`top-${mode}-chart`).innerHTML = '';
    $(`#top-${mode}-empty`).style.display = '';
    $(`#top-${mode}-empty`).textContent = 'Configura los filtros y pulsa “Aplicar”.';
    topDestroyChart(mode, 'chart');
    topResetDetalle(mode);

    // Si abrimos top máquinas, restauramos exclusiones persistidas y
    // precargamos la lista para el desplegable.
    if (mode === 'maquinas') {
        _topMaqExcl.clear();
        topMaqExclState().forEach(c => _topMaqExcl.add(c));
        renderTopMaqExclChips();
        _topMaqListSeccion = null; // fuerza recarga si se abre el panel
    }

    $(`#top-${mode}-block`).hidden = false;
    $(`#top-${mode}-block`).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    topUpdateShowNext();
}

function topHideBlock(mode) {
    topAbort(mode); topAbort(`${mode}Det`);
    topDestroyChart(mode, 'chart'); topDestroyChart(mode, 'det');
    $(`#top-${mode}-block`).hidden = true;
    topUpdateShowNext();
}

function topUpdateShowNext() {
    const btn = $('#top-show-next');
    if (!btn) return;
    // El botón solo aparece una vez completado el flujo de análisis (motivo drill abierto).
    if (!_analisisCompleto) {
        btn.hidden = true;
        return;
    }
    // Encuentra el primer modo oculto en orden
    const nextHidden = TOP_MODES.find(m => $(`#top-${m}-block`).hidden);
    if (!nextHidden) {
        btn.hidden = true;
        return;
    }
    btn.hidden = false;
    btn.textContent = '▼ Mostrar ' + TOP_LABELS[nextHidden];
    btn.dataset.next = nextHidden;
}

async function topAplicar(mode) {
    const { desde, hasta } = topReadFiltros(mode);
    if (!desde || !hasta) { showToast('Indica rango Desde/Hasta', 'error'); return; }
    if (desde > hasta)    { showToast('Desde no puede ser posterior a Hasta', 'error'); return; }

    topInlineSaveState(mode);
    topAbort(mode);
    _topAborts[mode] = new AbortController();

    const params = topCommonParams(mode);
    params.mode = mode;

    showLoader(true);
    try {
        const d = await apiFetch('oee_unificado_top_analisis.php', params, _topAborts[mode].signal);
        topResetDetalle(mode);
        renderTopChart(mode, mode === 'maquinas' ? (d.maquinas || []) : (d.motivos || []));
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error Top: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

function renderTopChart(mode, rows) {
    const elChart = document.getElementById(`top-${mode}-chart`);
    const empty   = $(`#top-${mode}-empty`);
    elChart.innerHTML = '';
    topDestroyChart(mode, 'chart');

    if (!rows.length) {
        empty.textContent = 'Sin paros en el rango seleccionado.';
        empty.style.display = '';
        return;
    }
    empty.style.display = 'none';

    let cats, vals, cods;
    if (mode === 'maquinas') {
        cats = rows.map(r => r.maquina);
        vals = rows.map(r => +r.horas);
        cods = rows.map(r => r.cod_maquina);
    } else {
        cats = rows.map(r => r.motivo);
        vals = rows.map(r => +r.horas);
        cods = rows.map(() => null);
    }

    const opts = {
        chart: {
            type: 'bar', height: Math.max(280, cats.length * 42),
            background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    const idx = cfg.dataPointIndex;
                    if (mode === 'maquinas') topCargarDetalle('maquinas', cods[idx], cats[idx]);
                    else                     topCargarDetalle('motivos',  null,      cats[idx]);
                },
            },
        },
        series: [{ name: 'Horas paro', data: vals }],
        xaxis: { categories: cats, labels: { style: { colors: '#2d4d7a', fontSize: '11px' }, formatter: v => v.toFixed(1) + 'h' } },
        yaxis: { labels: { style: { colors: '#1a2d4a', fontSize: '11px', fontWeight: 600 }, maxWidth: 240 } },
        plotOptions: { bar: { horizontal: true, barHeight: '65%', borderRadius: 3, borderRadiusApplication: 'end', distributed: true } },
        colors: vals.map((_, i) => topColor(i)),
        dataLabels: { enabled: true, style: { colors: ['#fff'], fontSize: '11px', fontWeight: 700 }, formatter: v => v.toFixed(2) + 'h' },
        grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: { y: { formatter: v => v.toFixed(2) + ' h · clic para ver histograma por fecha' } },
    };
    _topCharts[mode] = new ApexCharts(elChart, opts);
    _topCharts[mode].render();
}

async function topCargarDetalle(mode, codMaq, titulo) {
    topAbort(`${mode}Det`);
    _topAborts[`${mode}Det`] = new AbortController();

    const params = topCommonParams(mode);
    if (mode === 'maquinas') {
        if (!codMaq) return;
        params.mode = 'detalle_fecha_maquina';
        params.cod_maquina = codMaq;
    } else {
        if (!titulo) return;
        params.mode = 'detalle_fecha_motivo';
        params.motivo = titulo;
    }

    try {
        const d = await apiFetch('oee_unificado_top_analisis.php', params, _topAborts[`${mode}Det`].signal);
        renderTopDetalleFecha(mode, d.fechas || [], titulo);
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error detalle: ' + e.message, 'error');
    }
}

function renderTopDetalleFecha(mode, fechas, titulo) {
    const emptyEl = $(`#top-${mode}-detalle-empty`);
    const titleEl = $(`#top-${mode}-detalle-title`);
    const chartEl = document.getElementById(`top-${mode}-detalle-chart`);

    chartEl.innerHTML = '';
    topDestroyChart(mode, 'det');

    if (!fechas.length) {
        emptyEl.style.display = '';
        emptyEl.textContent = `Sin paros para "${titulo}" en el rango.`;
        titleEl.style.display = 'none';
        return;
    }
    emptyEl.style.display = 'none';
    titleEl.style.display = '';
    titleEl.textContent = `Histograma por fecha · ${titulo}`;

    const cats = fechas.map(r => r.fecha);
    const vals = fechas.map(r => +r.horas);
    const opts = {
        chart: { type: 'bar', height: 300, background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial' },
        series: [{ name: 'Horas paro', data: vals }],
        xaxis: {
            categories: cats,
            labels: {
                style: { colors: '#1a2d4a', fontSize: '10px', fontWeight: 600 },
                rotate: cats.length > 12 ? -45 : 0,
                rotateAlways: cats.length > 12,
                hideOverlappingLabels: true,
            },
        },
        yaxis: { labels: { style: { colors: '#2d4d7a', fontSize: '10px' }, formatter: v => v.toFixed(1) + 'h' } },
        plotOptions: { bar: { columnWidth: '70%', borderRadius: 2 } },
        dataLabels: { enabled: cats.length <= 14, formatter: v => v.toFixed(1) + 'h', style: { fontSize: '10px', colors: ['#ffffff'], fontWeight: 700 } },
        colors: ['#8c181a'],
        grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: { y: { formatter: v => v.toFixed(2) + ' h' } },
    };
    _topDetCharts[mode] = new ApexCharts(chartEl, opts);
    _topDetCharts[mode].render();
}

function topExport(mode, fmt) {
    const { desde, hasta } = topReadFiltros(mode);
    if (!desde || !hasta) { showToast('Indica rango Desde/Hasta', 'error'); return; }
    const params = topCommonParams(mode);
    params.mode = mode;
    params.fmt  = fmt;
    const qs = new URLSearchParams(params).toString();
    window.location.href = `../api/oee_unificado_top_export.php?${qs}`;
}

function setupTopModal() {
    // Botón progresivo: revela el siguiente bloque oculto
    const btnNext = $('#top-show-next');
    if (btnNext) {
        topUpdateShowNext();
        btnNext.addEventListener('click', () => {
            const next = btnNext.dataset.next;
            if (next) topShowBlock(next);
        });
    }

    // Aplicar (delegado por data-attribute)
    document.querySelectorAll('[data-aplicar]').forEach(b => {
        b.addEventListener('click', () => topAplicar(b.dataset.aplicar));
    });

    // Cerrar bloque
    document.querySelectorAll('[data-close-top]').forEach(b => {
        b.addEventListener('click', () => topHideBlock(b.dataset.closeTop));
    });

    // Exportación
    document.querySelectorAll('[data-export]').forEach(b => {
        b.addEventListener('click', () => topExport(b.dataset.mode, b.dataset.export));
    });

    // Persistencia ligera por modo
    TOP_MODES.forEach(mode => {
        ['seccion','desde','hasta','n'].forEach(suffix => {
            const el = $(`#top-${mode}-${suffix}`);
            if (el) el.addEventListener('change', () => topInlineSaveState(mode));
        });
    });

    // Si cambia la sección/rango del top máquinas, hay que recargar la lista
    // del desplegable (las máquinas dependen de sección + rango activos).
    ['seccion','desde','hasta'].forEach(suffix => {
        const el = $(`#top-maquinas-${suffix}`);
        if (el) el.addEventListener('change', () => {
            _topMaqListSeccion = null;
            if (!$('#top-maq-excl-panel').hidden) topMaqLoadLista();
        });
    });

    // Desplegable de exclusión de máquinas (solo en bloque top maquinas)
    const exclToggle = $('#top-maq-excl-toggle');
    if (exclToggle) exclToggle.addEventListener('click', (e) => { e.stopPropagation(); toggleTopMaqExclPanel(); });
    const exclSearch = $('#top-maq-excl-search');
    if (exclSearch) exclSearch.addEventListener('input', () => renderTopMaqExclList());
    const exclClear = $('#top-maq-excl-clear');
    if (exclClear) exclClear.addEventListener('click', () => {
        if (_topMaqExcl.size === 0) return;
        _topMaqExcl.clear();
        topMaqExclSave();
        renderTopMaqExclChips();
        renderTopMaqExclList();
        if (!$('#top-maquinas-block').hidden) topAplicar('maquinas');
    });
    const exclList = $('#top-maq-excl-list');
    if (exclList) exclList.addEventListener('change', (e) => {
        const cb = e.target.closest('.top-excl-cb');
        if (!cb) return;
        setTopMaqExclusion(cb.dataset.cod, cb.checked, cb.dataset.name);
    });
    const exclChips = $('#top-maq-excl-chips');
    if (exclChips) exclChips.addEventListener('click', (e) => {
        const btn = e.target.closest('.top-excl-chip-remove');
        if (!btn) return;
        setTopMaqExclusion(btn.dataset.cod, false);
        // Si el desplegable está abierto, refleja la deselección
        if (!$('#top-maq-excl-panel').hidden) renderTopMaqExclList();
    });
    // Cierre por clic fuera
    document.addEventListener('click', (e) => {
        const panel  = $('#top-maq-excl-panel');
        const toggle = $('#top-maq-excl-toggle');
        if (!panel || !toggle || panel.hidden) return;
        if (panel.contains(e.target) || toggle.contains(e.target)) return;
        closeTopMaqExclPanel();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const saved = loadState();
    if (saved && saved.desde && saved.hasta) {
        $('#f-desde').value = saved.desde;
        $('#f-hasta').value = saved.hasta;
        document.querySelectorAll('.turno-cb').forEach(cb => {
            cb.checked = Array.isArray(saved.turnos) && saved.turnos.includes(cb.value);
        });
        if (Array.isArray(saved.excl)) {
            _maqExcl = new Set(saved.excl.filter(v => typeof v === 'string' && v));
        }
    } else {
        setRange('today');
    }
    renderExclBadge();
    updateMaqExclToggleCount();

    $('#f-desde').addEventListener('change', cargar);
    $('#f-hasta').addEventListener('change', cargar);

    document.querySelectorAll('.turno-cb').forEach(cb => {
        cb.addEventListener('change', cargar);
    });

    $('#turnos-clear').addEventListener('click', () => {
        document.querySelectorAll('.turno-cb').forEach(cb => { cb.checked = false; });
        cargar();
    });

    document.querySelectorAll('.range-quick').forEach(btn => {
        btn.addEventListener('click', () => {
            setRange(btn.dataset.range);
            cargar();
        });
    });

    $('#seccion-drill-close').addEventListener('click', cerrarDrillSeccion);
    $('#metrica-drill-close').addEventListener('click', cerrarDrillMetrica);
    $('#motivo-drill-close').addEventListener('click', cerrarDrillMotivo);
    const btnMaqMotClose = $('#maq-motivos-drill-close');
    if (btnMaqMotClose) btnMaqMotClose.addEventListener('click', cerrarDrillMaqMotivos);

    setupEvolucionToggles();
    setupTopModal();

    const motivoHoraClear = $('#motivo-hora-clear');
    if (motivoHoraClear) {
        motivoHoraClear.addEventListener('click', () => {
            if (!_motivoActivo) return;
            _motivoActivo.codFiltroHora = null;
            cargarMotivoHora();
        });
    }

    // Toggle Máquina / Referencia en la cabecera del drill métrica
    document.querySelectorAll('#motivo-por-toggle input[name="motivo-por"]').forEach(r => {
        r.addEventListener('change', () => {
            const nuevo = r.value === 'referencia' ? 'referencia' : 'maquina';
            if (nuevo === _metricaPor) return;
            _metricaPor = nuevo;
            // Refresca el chart superior y cierra el drill intermedio (la entidad cambia)
            cerrarDrillMaqMotivos();
            cargarDrillMetrica();
            // Y, si hay un motivo abierto, recarga el motivo drill con la nueva segmentación
            if (_motivoActivo) {
                _motivoActivo.codFiltroHora = null;
                actualizarMotivoDrillTitulo();
                cargarMotivoDrill();
            }
        });
    });

    // Exclusión global de máquinas
    $('#maq-excl-clear-all').addEventListener('click', limpiarExclusiones);
    $('#maq-excl-chips').addEventListener('click', (e) => {
        const btn = e.target.closest('.maq-excl-chip-remove');
        if (!btn) return;
        quitarExclusion(btn.dataset.cod);
    });

    // Selector desplegable de máquinas
    $('#maq-excl-toggle').addEventListener('click', (e) => {
        e.stopPropagation();
        toggleMaqExclPanel();
    });
    $('#maq-excl-close').addEventListener('click', closeMaqExclPanel);
    $('#maq-excl-search').addEventListener('input', (e) => renderMaqExclList(e.target.value));
    $('#maq-excl-list').addEventListener('change', (e) => {
        const cb = e.target;
        if (!cb || !cb.classList.contains('maq-excl-cb')) return;
        const cod  = cb.dataset.cod;
        const name = cb.dataset.name;
        if (!cod) return;
        if (cb.checked) {
            _maqExcl.add(cod);
            if (name) _maqLookup[cod] = name;
        } else {
            _maqExcl.delete(cod);
        }
        renderExclBadge();
        updateMaqExclToggleCount();
        recargarDebounced();
    });
    document.addEventListener('click', (e) => {
        const panel  = $('#maq-excl-panel');
        const toggle = $('#maq-excl-toggle');
        if (!panel || panel.hidden) return;
        if (panel.contains(e.target) || (toggle && toggle.contains(e.target))) return;
        closeMaqExclPanel();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !$('#maq-excl-panel').hidden) closeMaqExclPanel();
    });

    // Export buttons
    $('#btn-export-xlsx').addEventListener('click', exportarXlsx);
    $('#btn-export-pdf').addEventListener('click', exportarPdf);

    // Informe completo (popover de selección de sección)
    _wireCompletoBtn('#btn-export-completo-xlsx', '#export-completo-menu-xlsx');
    _wireCompletoBtn('#btn-export-completo-pdf',  '#export-completo-menu-pdf');
    document.addEventListener('click', (e) => {
        const inside = e.target.closest('.oee-export-compl-wrap');
        if (!inside) _cerrarCompletoMenus(null);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') _cerrarCompletoMenus(null);
    });

    cargar();
});

// ───── Export ─────

function _buildExportParams(f) {
    const params = new URLSearchParams({ fecha_desde: f.desde, fecha_hasta: f.hasta });
    if (f.turnos.length) params.set('turnos', f.turnos.join(','));
    if (f.excl.length)   params.set('excl',   f.excl.join(','));
    if (_seccionActiva)        params.set('seccion', _seccionActiva);
    if (_metricaActiva)        params.set('metrica', _metricaActiva);
    if (_motivoActivo?.nombre) params.set('motivo',  _motivoActivo.nombre);
    return params;
}

function exportarXlsx() {
    const f = getFiltros();
    if (!f.desde || !f.hasta) { showToast('Selecciona un rango de fechas', 'error'); return; }
    window.location.href = `${API_BASE}/oee_unificado_export.php?${_buildExportParams(f)}`;
}

function exportarPdf() {
    const f = getFiltros();
    if (!f.desde || !f.hasta) { showToast('Selecciona un rango de fechas', 'error'); return; }
    window.location.href = `${API_BASE}/oee_unificado_export_pdf.php?${_buildExportParams(f)}`;
}

// ───── Informe completo (XLSX/PDF) por sección ─────

// Construye los parámetros del informe completo añadiendo todo el contexto activo
// (sección, métrica, motivo, modo Máquina/Referencia) para que el informe refleje
// lo que el usuario tiene en pantalla.
function _buildCompletoParams(f, seccion) {
    const p = new URLSearchParams({
        fecha_desde: f.desde,
        fecha_hasta: f.hasta,
        seccion,
    });
    if (f.turnos.length) p.set('turnos', f.turnos.join(','));
    if (f.excl.length)   p.set('excl',   f.excl.join(','));
    if (_metricaActiva)         p.set('metrica', _metricaActiva);
    if (_motivoActivo?.nombre)  p.set('motivo',  _motivoActivo.nombre);
    if (_metricaPor)            p.set('por',     _metricaPor);
    return p;
}

function descargarCompleto(fmt, seccion) {
    const f = getFiltros();
    if (!f.desde || !f.hasta) { showToast('Selecciona un rango de fechas', 'error'); return; }
    if (!['VARILLAS', 'TROQUELADOS'].includes(seccion)) return;
    const endpoint = fmt === 'pdf'
        ? 'oee_unificado_export_completo_pdf.php'
        : 'oee_unificado_export_completo.php';
    window.location.href = `${API_BASE}/${endpoint}?${_buildCompletoParams(f, seccion)}`;
}

function _cerrarCompletoMenus(exceptId) {
    ['#export-completo-menu-xlsx', '#export-completo-menu-pdf'].forEach(sel => {
        if (sel === exceptId) return;
        const m = document.querySelector(sel);
        if (m) m.hidden = true;
    });
    ['#btn-export-completo-xlsx', '#btn-export-completo-pdf'].forEach(sel => {
        const b = document.querySelector(sel);
        if (b) b.classList.remove('open');
    });
}

function _wireCompletoBtn(btnSel, menuSel) {
    const btn  = document.querySelector(btnSel);
    const menu = document.querySelector(menuSel);
    if (!btn || !menu) return;
    const fmt = btnSel.endsWith('pdf') ? 'pdf' : 'xlsx';
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        // Si el usuario tiene una sección abierta en pantalla → descarga directa.
        // Si no, abre el desplegable para que elija sección.
        if (_seccionActiva && ['VARILLAS', 'TROQUELADOS'].includes(_seccionActiva)) {
            _cerrarCompletoMenus(null);
            descargarCompleto(fmt, _seccionActiva);
            return;
        }
        const willOpen = menu.hidden;
        _cerrarCompletoMenus(menuSel);
        menu.hidden = !willOpen;
        btn.classList.toggle('open', !menu.hidden);
    });
    menu.addEventListener('click', (e) => {
        const b = e.target.closest('button[data-sec]');
        if (!b) return;
        e.stopPropagation();
        menu.hidden = true;
        btn.classList.remove('open');
        descargarCompleto(b.dataset.fmt, b.dataset.sec);
    });
}
