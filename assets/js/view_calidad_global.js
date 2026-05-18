/* Vista Calidad: gauge + por sección, filtrable por máquina y/o artículo */

let gaugeCal = null;
let chartCalSecc = null;
let _selCodMaquina  = '';
let _selCodArticulo = '';

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
    const capped = Math.min(120, Math.max(0, value));
    const options = {
        chart: { type: 'radialBar', height: 340, background: 'transparent' },
        series: [Math.min(100, capped)],
        plotOptions: {
            radialBar: {
                startAngle: -135, endAngle: 135,
                hollow: { size: '62%' },
                track: { background: '#e8eef5', strokeWidth: '100%' },
                dataLabels: {
                    name: { show: true, offsetY: -8, color: '#5b8cc7', fontSize: '13px', fontWeight: 600 },
                    value: {
                        show: true, offsetY: 8,
                        color: '#1a2d4a', fontSize: '40px', fontWeight: 700,
                        formatter: () => parseFloat(value).toFixed(2) + '%'
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
        colors: [semColor(Math.min(100, value))],
        labels: [label || 'Calidad'],
        stroke: { lineCap: 'round' }
    };
    if (gaugeCal) gaugeCal.destroy();
    gaugeCal = new ApexCharts($('#gauge-cal'), options);
    gaugeCal.render();
}

function renderSecciones(secciones) {
    const data = secciones.map(s => ({
        x: s.seccion, y: parseFloat(s.calidad),
        maquinas: s.maquinas, MOKT: s.MOKT_seg, MOT: s.MOT_seg, PC: s.PC_seg
    }));
    const maxV = Math.max(...data.map(d => d.y), 100);
    const options = {
        chart: { type: 'bar', height: 340, background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial' },
        series: [{ name: 'Calidad', data: data.map(d => d.y) }],
        xaxis: {
            categories: data.map(d => d.x),
            max: Math.ceil(maxV / 10) * 10,
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
        colors: data.map(d => semColor(Math.min(100, d.y))),
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
                const okmin = (r.MOKT / 60).toFixed(0);
                const okotmin = (r.MOT / 60).toFixed(0);
                return `
                    <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:220px">
                        <div style="font-weight:700;margin-bottom:6px;font-size:13px">${r.x}</div>
                        <div style="color:#a3b8d1;font-size:11px;margin-bottom:6px">${r.maquinas} máquina${r.maquinas===1?'':'s'}</div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>M_OK_TEO</span><span>${fmt.format(okmin)} min</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>M_OKNOK_TEO</span><span>${fmt.format(okotmin)} min</span></div>
                        <div style="border-top:1px solid #3a5576;margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:13px">
                            <span>Calidad</span><span>${r.y.toFixed(2)}%</span>
                        </div>
                    </div>
                `;
            }
        },
        annotations: {
            xaxis: [{
                x: 95, borderColor: '#10b981', strokeDashArray: 6,
                label: { text: 'Objetivo 95%', borderColor: '#10b981',
                    style: { color: '#fff', background: '#10b981', fontSize: '11px', fontWeight: 700 } }
            }, {
                x: 100, borderColor: '#1a2d4a', strokeDashArray: 4,
                label: { text: '100%', borderColor: '#1a2d4a',
                    style: { color: '#fff', background: '#1a2d4a', fontSize: '11px', fontWeight: 700 } }
            }]
        }
    };
    if (chartCalSecc) chartCalSecc.destroy();
    chartCalSecc = new ApexCharts($('#chart-secciones'), options);
    chartCalSecc.render();
}

async function cargarVista() {
    showLoader(true);
    try {
        const f = getFiltrosActuales();
        const params = { fecha: f.fecha };
        if (f.turno)         params.turno = f.turno;
        if (_selCodMaquina)  params.cod_maquina = _selCodMaquina;
        if (_selCodArticulo) params.cod_articulo = _selCodArticulo;

        const d = await apiFetch('calidad_global.php', params);

        populateMachineSelector(d.machines || [], _selCodMaquina);
        populateArticleSelector(d.articles || [], _selCodArticulo);

        const machineStillValid = !_selCodMaquina  || (d.machines || []).some(m => m.cod_maquina  === _selCodMaquina);
        const articleStillValid = !_selCodArticulo || (d.articles || []).some(a => String(a.cod_articulo) === String(_selCodArticulo));
        if (!machineStillValid)  { _selCodMaquina = '';  const m = $('#machine-selector'); if (m) m.value = ''; updateUrlParams({ cod_maquina: '' }); }
        if (!articleStillValid)  { _selCodArticulo = ''; const a = $('#article-selector'); if (a) a.value = ''; updateUrlParams({ cod_articulo: '' }); }

        const btn = $('#filter-clear');
        if (btn) btn.style.display = (_selCodMaquina || _selCodArticulo) ? '' : 'none';

        const scopeBits = [];
        if (_selCodMaquina && d.maquina_info)   scopeBits.push('máq: ' + d.maquina_info.maquina);
        if (_selCodArticulo && d.articulo_info) scopeBits.push('art: ' + d.articulo_info.cod_articulo);
        $('#header-scope').textContent = scopeBits.length ? '· ' + scopeBits.join(' · ') : '';

        const turnoLabel = d.turno ? { M:'MAÑANA',T:'TARDE',N:'NOCHE' }[d.turno] : 'TODOS LOS TURNOS';
        $('#info-line').textContent = d.fecha + ' · ' + turnoLabel + ' · ' + d.global.maquinas + ' máq.';

        const labelGauge = scopeBits.length ? scopeBits.join(' · ') : 'Calidad';
        renderGauge(parseFloat(d.global.calidad), labelGauge);
        renderSecciones(d.secciones);

    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

function onMachineChange() { _selCodMaquina = $('#machine-selector').value || ''; updateUrlParams({ cod_maquina: _selCodMaquina }); cargarVista(); }
function onArticleChange() { _selCodArticulo = $('#article-selector').value || ''; updateUrlParams({ cod_articulo: _selCodArticulo }); cargarVista(); }
function onClearFilters() {
    _selCodMaquina = ''; _selCodArticulo = '';
    $('#machine-selector').value = ''; $('#article-selector').value = '';
    updateUrlParams({ cod_maquina: '', cod_articulo: '' });
    cargarVista();
}

document.addEventListener('DOMContentLoaded', () => {
    _selCodMaquina  = getQueryParam('cod_maquina')  || '';
    _selCodArticulo = getQueryParam('cod_articulo') || '';
    const m = $('#machine-selector'); if (m) m.addEventListener('change', onMachineChange);
    const a = $('#article-selector'); if (a) a.addEventListener('change', onArticleChange);
    const c = $('#filter-clear');     if (c) c.addEventListener('click',  onClearFilters);
    initFiltros(cargarVista);
    cargarVista();
});
