/* Vista Disponibilidad: gauge + por sección, filtrable por máquina y/o artículo
   Drill-down al hacer clic en una sección (VARILLAS / TROQUELADOS):
   - Carga listado de artículos y máquinas de esa sección con su disponibilidad.
   - Las barras del drill-down son clicables → setean el selector y refiltran. */

let gaugeDisp = null;
let chartDispSecc = null;
let chartArticulosSeccion = null;
let chartMaquinasSeccion  = null;
let _selCodMaquina  = '';
let _selCodArticulo = '';
let _seccionDrillDown = null; // 'VARILLAS' | 'TROQUELADOS' | null
let _seccionesData = [];      // último payload de secciones

function getQueryParam(name) {
    const u = new URLSearchParams(window.location.search);
    return u.get(name);
}

function updateUrlParams(updates) {
    const u = new URL(window.location.href);
    Object.entries(updates).forEach(([k, v]) => {
        if (v) u.searchParams.set(k, v);
        else   u.searchParams.delete(k);
    });
    history.replaceState(null, '', u.pathname + (u.search || '') + u.hash);
}

function populateMachineSelector(machines, current) {
    const sel = $('#machine-selector');
    if (!sel) return;
    const bySec = { 'VARILLAS': [], 'TROQUELADOS': [], 'OTROS': [] };
    machines.forEach(m => {
        const k = m.seccion && bySec[m.seccion] ? m.seccion : 'OTROS';
        bySec[k].push(m);
    });
    sel.innerHTML = '<option value="">— Todas —</option>';
    ['VARILLAS', 'TROQUELADOS', 'OTROS'].forEach(sec => {
        if (!bySec[sec].length) return;
        const og = document.createElement('optgroup'); og.label = sec;
        bySec[sec]
            .sort((a, b) => (a.maquina || '').localeCompare(b.maquina || ''))
            .forEach(m => {
                const o = document.createElement('option');
                o.value = m.cod_maquina;
                o.textContent = m.maquina + ' (' + m.cod_maquina + ')';
                og.appendChild(o);
            });
        sel.appendChild(og);
    });
    sel.value = current || '';
}

function populateArticleSelector(articles, current) {
    const sel = $('#article-selector');
    if (!sel) return;
    sel.innerHTML = '<option value="">— Todos —</option>';
    articles.forEach(a => {
        const o = document.createElement('option');
        o.value = a.cod_articulo;
        const desc = (a.desc_articulo || '').substring(0, 48);
        o.textContent = a.cod_articulo + (desc ? '  —  ' + desc : '');
        sel.appendChild(o);
    });
    sel.value = current || '';
}

function renderGauge(value, label) {
    const options = {
        chart: { type: 'radialBar', height: 340, background: 'transparent' },
        series: [value],
        plotOptions: {
            radialBar: {
                startAngle: -135, endAngle: 135,
                hollow: { size: '62%' },
                track: { background: '#e8eef5', strokeWidth: '100%' },
                dataLabels: {
                    name: { show: true, offsetY: -8, color: '#5b8cc7', fontSize: '13px', fontWeight: 600 },
                    value: {
                        show: true, offsetY: 8,
                        color: '#1a2d4a', fontSize: '42px', fontWeight: 700,
                        formatter: v => parseFloat(v).toFixed(2) + '%'
                    }
                }
            }
        },
        fill: {
            type: 'gradient',
            gradient: {
                shade: 'light', type: 'horizontal',
                shadeIntensity: 0.4,
                gradientToColors: [semColor(Math.min(100, value + 10))],
                opacityFrom: 1, opacityTo: 1, stops: [0, 100]
            }
        },
        colors: [semColor(value)],
        labels: [label || 'Disponibilidad'],
        stroke: { lineCap: 'round' }
    };
    if (gaugeDisp) gaugeDisp.destroy();
    gaugeDisp = new ApexCharts($('#gauge-disp'), options);
    gaugeDisp.render();
}

function renderSecciones(secciones) {
    _seccionesData = secciones || [];
    const data = _seccionesData.map(s => ({
        x: s.seccion, y: parseFloat(s.disponibilidad),
        maquinas: s.maquinas, M_min: s.M_min, PNP_min: s.PNP_min
    }));

    const strokeWidths = data.map(d => d.x === _seccionDrillDown ? 4 : 0);
    const fillOpacity  = data.map(d => _seccionDrillDown ? (d.x === _seccionDrillDown ? 1 : 0.55) : 1);

    const options = {
        chart: {
            type: 'bar', height: 340, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    const sec = data[cfg.dataPointIndex] && data[cfg.dataPointIndex].x;
                    if (sec) toggleDrillDown(sec);
                },
                click: (_e, _ctx, cfg) => {
                    if (cfg.dataPointIndex == null || cfg.dataPointIndex < 0) return;
                    const sec = data[cfg.dataPointIndex] && data[cfg.dataPointIndex].x;
                    if (sec) toggleDrillDown(sec);
                }
            }
        },
        series: [{ name: 'Disponibilidad', data: data.map(d => d.y) }],
        xaxis: {
            categories: data.map(d => d.x), max: 100,
            labels: { style: { colors: '#2d4d7a', fontSize: '11px' }, formatter: v => v + '%' }
        },
        yaxis: { labels: { style: { colors: '#1a2d4a', fontSize: '13px', fontWeight: 700 } } },
        plotOptions: {
            bar: {
                horizontal: true, barHeight: '55%', borderRadius: 4,
                borderRadiusApplication: 'end', distributed: true,
                dataLabels: { position: 'center' }
            }
        },
        states: {
            hover:  { filter: { type: 'lighten', value: 0.08 } },
            active: { filter: { type: 'none', value: 0 } }
        },
        stroke: { show: true, width: strokeWidths, colors: ['#1a2d4a'] },
        fill:   { opacity: fillOpacity },
        colors: data.map(d => semColor(d.y)),
        dataLabels: {
            enabled: true,
            style: { colors: ['#ffffff'], fontFamily: 'Arial', fontSize: '14px', fontWeight: 700 },
            formatter: v => v.toFixed(0) + '%'
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            custom: ({dataPointIndex}) => {
                const r = data[dataPointIndex];
                const fmt = new Intl.NumberFormat('es-ES');
                return `
                    <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:200px">
                        <div style="font-weight:700;margin-bottom:6px;font-size:13px">${r.x}</div>
                        <div style="color:#a3b8d1;font-size:11px;margin-bottom:6px">${r.maquinas} máquina${r.maquinas===1?'':'s'}</div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>En marcha</span><span>${fmt.format(r.M_min)} min</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Paros no progr.</span><span>${fmt.format(r.PNP_min)} min</span></div>
                        <div style="border-top:1px solid #3a5576;margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:13px">
                            <span>Disponibilidad</span><span>${r.y.toFixed(1)}%</span>
                        </div>
                        <div style="color:#a3b8d1;font-size:10px;margin-top:6px;text-align:center">Clic para desglose</div>
                    </div>
                `;
            }
        },
        annotations: {
            xaxis: [{
                x: 75, borderColor: '#10b981', strokeDashArray: 6,
                label: { text: 'Objetivo 75%', borderColor: '#10b981',
                    style: { color: '#fff', background: '#10b981', fontSize: '11px', fontWeight: 700 } }
            }]
        }
    };
    if (chartDispSecc) chartDispSecc.destroy();
    chartDispSecc = new ApexCharts($('#chart-secciones'), options);
    chartDispSecc.render();
}

function tooltipDetalleHTML(label, codigo, M_min, PNP_min, y) {
    const fmt = new Intl.NumberFormat('es-ES');
    return `
        <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:240px">
            <div style="font-weight:700;margin-bottom:6px;font-size:13px">${label || codigo || ''}</div>
            ${codigo ? `<div style="color:#a3b8d1;font-size:11px;margin-bottom:6px">${codigo}</div>` : ''}
            <div style="display:flex;justify-content:space-between;gap:12px"><span>En marcha</span><span>${fmt.format(M_min)} min</span></div>
            <div style="display:flex;justify-content:space-between;gap:12px"><span>Paros no progr.</span><span>${fmt.format(PNP_min)} min</span></div>
            <div style="border-top:1px solid #3a5576;margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:13px">
                <span>Disponibilidad</span><span>${parseFloat(y).toFixed(1)}%</span>
            </div>
            <div style="color:#a3b8d1;font-size:10px;margin-top:6px;text-align:center">Clic para filtrar</div>
        </div>
    `;
}

function renderDrillDownArticulos(articulos) {
    const empty = $('#chart-articulos-empty');
    if (!articulos || !articulos.length) {
        if (chartArticulosSeccion) { chartArticulosSeccion.destroy(); chartArticulosSeccion = null; }
        $('#chart-articulos-seccion').innerHTML = '';
        if (empty) empty.style.display = '';
        return;
    }
    if (empty) empty.style.display = 'none';
    const data = articulos.map(a => ({
        x: a.cod_articulo,
        label: (a.desc_articulo || '').substring(0, 60) || a.cod_articulo,
        y: parseFloat(a.disponibilidad),
        cod_articulo: a.cod_articulo,
        M_min: a.M_min, PNP_min: a.PNP_min
    }));
    const height = Math.max(220, Math.min(1400, 28 * data.length + 90));
    const options = {
        chart: {
            type: 'bar', height, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    const cod = data[cfg.dataPointIndex] && data[cfg.dataPointIndex].cod_articulo;
                    if (cod) onArticleClickFromChart(cod);
                }
            }
        },
        series: [{ name: 'Disponibilidad', data: data.map(d => d.y) }],
        xaxis: {
            categories: data.map(d => d.x), max: 100,
            labels: { style: { colors: '#2d4d7a', fontSize: '11px' }, formatter: v => v + '%' }
        },
        yaxis: { labels: { style: { colors: '#1a2d4a', fontSize: '12px', fontWeight: 600 }, maxWidth: 140 } },
        plotOptions: {
            bar: {
                horizontal: true, barHeight: '70%', borderRadius: 3,
                borderRadiusApplication: 'end', distributed: true
            }
        },
        states: { active: { filter: { type: 'none', value: 0 } } },
        colors: data.map(d => semColor(d.y)),
        dataLabels: {
            enabled: true,
            style: { colors: ['#ffffff'], fontFamily: 'Arial', fontSize: '11px', fontWeight: 700 },
            formatter: v => v.toFixed(0) + '%'
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            custom: ({dataPointIndex}) => {
                const r = data[dataPointIndex];
                return tooltipDetalleHTML(r.label, r.x, r.M_min, r.PNP_min, r.y);
            }
        },
        annotations: {
            xaxis: [{ x: 75, borderColor: '#10b981', strokeDashArray: 6 }]
        }
    };
    if (chartArticulosSeccion) chartArticulosSeccion.destroy();
    chartArticulosSeccion = new ApexCharts($('#chart-articulos-seccion'), options);
    chartArticulosSeccion.render();
}

function renderDrillDownMaquinas(maquinas) {
    const empty = $('#chart-maquinas-empty');
    if (!maquinas || !maquinas.length) {
        if (chartMaquinasSeccion) { chartMaquinasSeccion.destroy(); chartMaquinasSeccion = null; }
        $('#chart-maquinas-seccion').innerHTML = '';
        if (empty) empty.style.display = '';
        return;
    }
    if (empty) empty.style.display = 'none';
    const data = maquinas.map(m => ({
        x: m.maquina,
        cod_maquina: m.cod_maquina,
        y: parseFloat(m.disponibilidad),
        M_min: m.M_min, PNP_min: m.PNP_min
    }));
    const height = Math.max(220, Math.min(1400, 28 * data.length + 90));
    const options = {
        chart: {
            type: 'bar', height, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    const cod = data[cfg.dataPointIndex] && data[cfg.dataPointIndex].cod_maquina;
                    if (cod) onMachineClickFromChart(cod);
                }
            }
        },
        series: [{ name: 'Disponibilidad', data: data.map(d => d.y) }],
        xaxis: {
            categories: data.map(d => d.x), max: 100,
            labels: { style: { colors: '#2d4d7a', fontSize: '11px' }, formatter: v => v + '%' }
        },
        yaxis: { labels: { style: { colors: '#1a2d4a', fontSize: '12px', fontWeight: 600 }, maxWidth: 140 } },
        plotOptions: {
            bar: {
                horizontal: true, barHeight: '70%', borderRadius: 3,
                borderRadiusApplication: 'end', distributed: true
            }
        },
        states: { active: { filter: { type: 'none', value: 0 } } },
        colors: data.map(d => semColor(d.y)),
        dataLabels: {
            enabled: true,
            style: { colors: ['#ffffff'], fontFamily: 'Arial', fontSize: '11px', fontWeight: 700 },
            formatter: v => v.toFixed(0) + '%'
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            custom: ({dataPointIndex}) => {
                const r = data[dataPointIndex];
                return tooltipDetalleHTML(r.x, r.cod_maquina, r.M_min, r.PNP_min, r.y);
            }
        },
        annotations: {
            xaxis: [{ x: 75, borderColor: '#10b981', strokeDashArray: 6 }]
        }
    };
    if (chartMaquinasSeccion) chartMaquinasSeccion.destroy();
    chartMaquinasSeccion = new ApexCharts($('#chart-maquinas-seccion'), options);
    chartMaquinasSeccion.render();
}

async function cargarDrillDown() {
    if (!_seccionDrillDown) return;
    try {
        const f = getFiltrosActuales();
        const params = {
            fecha:   f.fecha,
            seccion: _seccionDrillDown
        };
        if (f.turno)         params.turno        = f.turno;
        if (_selCodMaquina)  params.cod_maquina  = _selCodMaquina;
        if (_selCodArticulo) params.cod_articulo = _selCodArticulo;

        const d = await apiFetch('disponibilidad_seccion_detalle.php', params);
        $('#drill-down-seccion-label').textContent = _seccionDrillDown;
        renderDrillDownArticulos(d.articulos || []);
        renderDrillDownMaquinas(d.maquinas || []);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    }
}

function abrirDrillDownBlock() {
    const b = $('#drill-down-block');
    if (b) b.style.display = '';
}
function cerrarDrillDownBlock() {
    const b = $('#drill-down-block');
    if (b) b.style.display = 'none';
    if (chartArticulosSeccion) { chartArticulosSeccion.destroy(); chartArticulosSeccion = null; }
    if (chartMaquinasSeccion)  { chartMaquinasSeccion.destroy();  chartMaquinasSeccion  = null; }
}

function toggleDrillDown(seccion) {
    if (seccion !== 'VARILLAS' && seccion !== 'TROQUELADOS') return;
    if (_seccionDrillDown === seccion) {
        _seccionDrillDown = null;
        cerrarDrillDownBlock();
    } else {
        _seccionDrillDown = seccion;
        abrirDrillDownBlock();
        cargarDrillDown();
    }
    renderSecciones(_seccionesData);
}

function onArticleClickFromChart(cod) {
    _selCodArticulo = String(cod);
    const sel = $('#article-selector');
    if (sel) sel.value = _selCodArticulo;
    updateUrlParams({ cod_articulo: _selCodArticulo });
    cargarVista();
}
function onMachineClickFromChart(cod) {
    _selCodMaquina = String(cod);
    const sel = $('#machine-selector');
    if (sel) sel.value = _selCodMaquina;
    updateUrlParams({ cod_maquina: _selCodMaquina });
    cargarVista();
}

async function cargarVista() {
    showLoader(true);
    try {
        const f = getFiltrosActuales();
        const params = { fecha: f.fecha };
        if (f.turno)            params.turno = f.turno;
        if (_selCodMaquina)     params.cod_maquina = _selCodMaquina;
        if (_selCodArticulo)    params.cod_articulo = _selCodArticulo;

        const d = await apiFetch('disponibilidad_global.php', params);

        populateMachineSelector(d.machines || [], _selCodMaquina);
        populateArticleSelector(d.articles || [], _selCodArticulo);

        // Sincronizar estado: si el filtro actual ya no aparece en la lista
        // cruzada (la combinación máquina+artículo no es válida), limpiarlo.
        const machineStillValid = !_selCodMaquina  || (d.machines || []).some(m => m.cod_maquina  === _selCodMaquina);
        const articleStillValid = !_selCodArticulo || (d.articles || []).some(a => String(a.cod_articulo) === String(_selCodArticulo));
        if (!machineStillValid)  { _selCodMaquina = '';  const m = $('#machine-selector'); if (m) m.value = ''; updateUrlParams({ cod_maquina: '' }); }
        if (!articleStillValid)  { _selCodArticulo = ''; const a = $('#article-selector'); if (a) a.value = ''; updateUrlParams({ cod_articulo: '' }); }

        // Botón "× Quitar filtros" visible solo si hay alguno
        const btn = $('#filter-clear');
        if (btn) btn.style.display = (_selCodMaquina || _selCodArticulo) ? '' : 'none';

        // Cabecera de alcance
        const scopeBits = [];
        if (_selCodMaquina && d.maquina_info)   scopeBits.push('máq: ' + d.maquina_info.maquina);
        if (_selCodArticulo && d.articulo_info) scopeBits.push('art: ' + d.articulo_info.cod_articulo);
        $('#header-scope').textContent = scopeBits.length ? '· ' + scopeBits.join(' · ') : '';

        const turnoLabel = d.turno ? { M:'MAÑANA',T:'TARDE',N:'NOCHE' }[d.turno] : 'TODOS LOS TURNOS';
        $('#info-line').textContent = d.fecha + ' · ' + turnoLabel + ' · ' + d.global.maquinas + ' máq.';

        const labelGauge = scopeBits.length ? scopeBits.join(' · ') : 'Disponibilidad';
        renderGauge(parseFloat(d.global.disponibilidad), labelGauge);
        renderSecciones(d.secciones);

        if (_seccionDrillDown) {
            abrirDrillDownBlock();
            cargarDrillDown();
        }

    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

function onMachineChange() {
    const sel = $('#machine-selector');
    _selCodMaquina = sel ? (sel.value || '') : '';
    updateUrlParams({ cod_maquina: _selCodMaquina });
    cargarVista();
}
function onArticleChange() {
    const sel = $('#article-selector');
    _selCodArticulo = sel ? (sel.value || '') : '';
    updateUrlParams({ cod_articulo: _selCodArticulo });
    cargarVista();
}
function onClearFilters() {
    _selCodMaquina = ''; _selCodArticulo = '';
    const m = $('#machine-selector'); if (m) m.value = '';
    const a = $('#article-selector'); if (a) a.value = '';
    updateUrlParams({ cod_maquina: '', cod_articulo: '' });
    cargarVista();
}
function onCloseDrillDown() {
    if (_seccionDrillDown) toggleDrillDown(_seccionDrillDown);
}

document.addEventListener('DOMContentLoaded', () => {
    _selCodMaquina  = getQueryParam('cod_maquina')  || '';
    _selCodArticulo = getQueryParam('cod_articulo') || '';

    const m = $('#machine-selector'); if (m) m.addEventListener('change', onMachineChange);
    const a = $('#article-selector'); if (a) a.addEventListener('change', onArticleChange);
    const c = $('#filter-clear');     if (c) c.addEventListener('click',  onClearFilters);
    const dc = $('#drill-down-close'); if (dc) dc.addEventListener('click', onCloseDrillDown);

    initFiltros(cargarVista);
    cargarVista();
});
