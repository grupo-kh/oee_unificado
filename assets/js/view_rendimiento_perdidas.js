/* Vista Rendimiento - Pareto Horas perdidas por máquina, click → detalle por artículo */

let chartRendPareto = null;
let chartRendDetalle = null;
let _selCodMaquina  = '';
let _selCodArticulo = '';
let _topRef = [];

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

function renderPareto(perdidas, totalHoras) {
    const el = $('#chart-rend-pareto');
    if (!perdidas || !perdidas.length) {
        el.innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:40px;font-size:13px">Sin pérdidas registradas para los filtros seleccionados</div>';
        $('#rend-summary').textContent = '';
        return;
    }
    const top = perdidas.slice(0, 12);
    _topRef = top;
    const categorias = top.map(p => p.maquina);
    const horas      = top.map(p => parseFloat(p.horas));
    const acum       = top.map(p => parseFloat(p.pct_acum));

    $('#rend-summary').textContent =
        '— ' + perdidas.length + ' máquinas · top 12 = ' +
        top.reduce((a, p) => a + parseFloat(p.pct), 0).toFixed(0) + '% del total';

    const options = {
        chart: {
            type: 'line', height: 420, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial', stacked: false,
            events: {
                dataPointSelection: (ev, ctx, cfg) => {
                    const p = _topRef[cfg.dataPointIndex];
                    if (p) cargarDetalle(p.cod_maquina, p.maquina);
                },
                markerClick: (ev, ctx, cfg) => {
                    const p = _topRef[cfg.dataPointIndex];
                    if (p) cargarDetalle(p.cod_maquina, p.maquina);
                }
            }
        },
        series: [
            { name: 'Horas perdidas', type: 'column', data: horas },
            { name: '% Acumulado',    type: 'line',   data: acum }
        ],
        stroke: { width: [0, 3], curve: 'straight' },
        colors: ['#3a6aa3', '#f4c430'],
        plotOptions: {
            bar: {
                columnWidth: '60%',
                borderRadius: 4,
                borderRadiusApplication: 'end',
                dataLabels: { position: 'top' }
            }
        },
        markers: {
            size: [0, 5],
            colors: ['#f4c430'],
            strokeColors: '#1a2d4a', strokeWidth: 2
        },
        xaxis: {
            categories: categorias,
            labels: {
                style: { colors: '#1a2d4a', fontSize: '10px', fontWeight: 700 },
                rotate: -45, rotateAlways: true, hideOverlappingLabels: false, trim: false,
                maxHeight: 130
            },
            axisBorder: { color: '#a3b8d1' },
            axisTicks:  { color: '#a3b8d1' }
        },
        yaxis: [
            {
                seriesName: 'Horas perdidas',
                title: { text: 'Horas', style: { color: '#3a6aa3', fontWeight: 700 } },
                labels: { style: { colors: '#2d4d7a', fontSize: '11px' }, formatter: v => v.toFixed(1) }
            },
            {
                opposite: true,
                seriesName: '% Acumulado',
                min: 0, max: 100,
                title: { text: '% acumulado', style: { color: '#f4c430', fontWeight: 700 } },
                labels: { style: { colors: '#b45309', fontSize: '11px' }, formatter: v => v.toFixed(0) + '%' }
            }
        ],
        dataLabels: {
            enabled: true, enabledOnSeries: [0], offsetY: -18,
            style: { colors: ['#1a2d4a'], fontFamily: 'Arial', fontSize: '11px', fontWeight: 700 },
            formatter: v => v > 0.05 ? v.toFixed(1) + 'h' : ''
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { position: 'top', fontFamily: 'Arial', fontSize: '12px', fontWeight: 600 },
        tooltip: {
            shared: true, intersect: false,
            custom: ({dataPointIndex}) => {
                const p = top[dataPointIndex];
                const fmt = new Intl.NumberFormat('es-ES');
                return `
                    <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:220px">
                        <div style="font-weight:700;margin-bottom:6px;font-size:13px">${p.maquina}</div>
                        <div style="color:#a3b8d1;font-size:10px;margin-bottom:6px">${p.cod_maquina} · ${p.seccion || 'sin sección'}</div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Horas</span><span>${parseFloat(p.horas).toFixed(2)} h</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Minutos</span><span>${fmt.format(parseFloat(p.minutos))} min</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Rendimiento</span><span>${parseFloat(p.rendimiento).toFixed(1)}%</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>% del total</span><span>${parseFloat(p.pct).toFixed(1)}%</span></div>
                        <div style="border-top:1px solid #3a5576;margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:13px">
                            <span>% acumulado</span><span>${parseFloat(p.pct_acum).toFixed(1)}%</span>
                        </div>
                    </div>
                `;
            }
        },
        annotations: {
            yaxis: [{
                y: 80, yAxisIndex: 1,
                borderColor: '#10b981', strokeDashArray: 4,
                label: { text: 'Regla 80%', borderColor: '#10b981',
                    style: { color: '#fff', background: '#10b981', fontSize: '10px', fontWeight: 700 } }
            }]
        }
    };
    if (chartRendPareto) chartRendPareto.destroy();
    chartRendPareto = new ApexCharts(el, options);
    chartRendPareto.render();
    setTimeout(() => {
        el.querySelectorAll('.apexcharts-bar-area, .apexcharts-marker').forEach(b => b.style.cursor = 'pointer');
    }, 100);
}

function renderTabla(perdidas) {
    const tbody = $('#rend-tabla').querySelector('tbody');
    tbody.innerHTML = '';
    if (!perdidas || !perdidas.length) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#5b8cc7;font-size:13px">Sin pérdidas registradas</td></tr>';
        return;
    }
    perdidas.forEach((p, idx) => {
        const tr = document.createElement('tr');
        tr.className = 'row-clickable';
        const secColor = p.seccion === 'TROQUELADOS' ? '#c8102e' : (p.seccion === 'VARILLAS' ? '#3a6aa3' : '#8fa5bf');
        tr.innerHTML = `
            <td>${idx + 1}</td>
            <td><strong>${p.maquina}</strong> <span style="color:#8fa5bf;font-size:11px">(${p.cod_maquina})</span></td>
            <td><span style="color:${secColor};font-weight:700">${p.seccion || '—'}</span></td>
            <td style="text-align:right">${parseFloat(p.horas).toFixed(2)}</td>
            <td style="text-align:right">${parseFloat(p.rendimiento).toFixed(1)}%</td>
            <td style="text-align:right">${parseFloat(p.pct).toFixed(2)}%</td>
            <td style="text-align:right;color:#b45309;font-weight:700">${parseFloat(p.pct_acum).toFixed(1)}%</td>
        `;
        tr.addEventListener('click', () => cargarDetalle(p.cod_maquina, p.maquina));
        tbody.appendChild(tr);
    });
}

async function cargarDetalle(cod_maquina, maquina_label) {
    const panel = $('#rend-detalle-panel');
    if (!panel) return;
    panel.style.display = '';
    $('#rend-detalle-titulo').textContent = maquina_label || cod_maquina;
    $('#rend-detalle-meta').textContent = 'Cargando…';
    $('#chart-rend-detalle').innerHTML = '';
    $('#rend-detalle-tabla').querySelector('tbody').innerHTML = '';

    showLoader(true);
    try {
        const f = getFiltrosActuales();
        const params = { fecha: f.fecha, cod_maquina };
        if (f.turno) params.turno = f.turno;

        const d = await apiFetch('rendimiento_perdidas_detalle.php', params);

        $('#rend-detalle-titulo').textContent = d.maquina + ' (' + d.cod_maquina + ')';
        const turnoLabel = d.turno ? { M:'MAÑANA',T:'TARDE',N:'NOCHE' }[d.turno] : 'TODOS LOS TURNOS';
        $('#rend-detalle-meta').textContent =
            d.fecha + ' · ' + turnoLabel + ' · ' +
            d.total_horas + ' h perdidas en ' + (d.breakdown || []).length + ' artículos';

        renderDetalleChart(d.breakdown || []);
        renderDetalleTabla(d.breakdown || []);
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
        $('#rend-detalle-meta').textContent = 'Error cargando detalle';
    } finally {
        showLoader(false);
    }
}

function renderDetalleChart(breakdown) {
    const el = $('#chart-rend-detalle');
    if (!breakdown.length) {
        el.innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:30px;font-size:13px">Sin pérdidas atribuibles a artículos para esta máquina</div>';
        return;
    }
    const data = breakdown.map(b => ({
        x: String(b.cod_articulo || '--'),
        y: parseFloat(b.horas),
        desc: b.desc_articulo, rend: parseFloat(b.rendimiento), pct: parseFloat(b.pct),
        minutos: b.minutos
    }));

    const options = {
        chart: { type: 'bar', height: 320, background: 'transparent',
                 toolbar: { show: false }, fontFamily: 'Arial' },
        series: [{ name: 'Horas perdidas', data: data.map(d => d.y) }],
        xaxis: {
            categories: data.map(d => d.x),
            labels: {
                style: { colors: '#1a2d4a', fontSize: '10px', fontWeight: 700 },
                rotate: -35, rotateAlways: true, hideOverlappingLabels: false, trim: false,
                maxHeight: 100
            }
        },
        yaxis: {
            min: 0,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '11px' },
                formatter: v => v.toFixed(2) + ' h'
            }
        },
        plotOptions: {
            bar: {
                columnWidth: '55%', borderRadius: 3, borderRadiusApplication: 'end',
                distributed: true, dataLabels: { position: 'top' }
            }
        },
        colors: ['#3a6aa3', '#5b8cc7', '#7eaee4', '#a3c5ea', '#c8102e', '#ef4444', '#f59e0b', '#10b981'],
        dataLabels: {
            enabled: true, offsetY: -18,
            style: { colors: ['#1a2d4a'], fontFamily: 'Arial', fontSize: '11px', fontWeight: 700 },
            formatter: (v, opts) => {
                const r = data[opts.dataPointIndex];
                return r.pct.toFixed(0) + '%';
            }
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            custom: ({dataPointIndex}) => {
                const r = data[dataPointIndex];
                const fmt = new Intl.NumberFormat('es-ES');
                return `
                    <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:220px">
                        <div style="font-weight:700;margin-bottom:4px">${r.x}</div>
                        <div style="color:#a3b8d1;font-size:10px;margin-bottom:6px">${(r.desc || '').substring(0, 60)}</div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Horas perdidas</span><span>${r.y.toFixed(2)} h</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Minutos</span><span>${fmt.format(r.minutos)} min</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Rendimiento</span><span>${r.rend.toFixed(1)}%</span></div>
                        <div style="border-top:1px solid #3a5576;margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:13px">
                            <span>% sobre máquina</span><span>${r.pct.toFixed(1)}%</span>
                        </div>
                    </div>
                `;
            }
        }
    };
    if (chartRendDetalle) chartRendDetalle.destroy();
    chartRendDetalle = new ApexCharts(el, options);
    chartRendDetalle.render();
}

function renderDetalleTabla(breakdown) {
    const tbody = $('#rend-detalle-tabla').querySelector('tbody');
    tbody.innerHTML = '';
    if (!breakdown.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#5b8cc7">—</td></tr>';
        return;
    }
    breakdown.forEach((b, i) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${i + 1}</td>
            <td><strong>${b.cod_articulo}</strong></td>
            <td style="color:#5b8cc7;font-size:11px">${(b.desc_articulo || '').substring(0, 60)}</td>
            <td style="text-align:right">${parseFloat(b.horas).toFixed(2)}</td>
            <td style="text-align:right">${parseFloat(b.rendimiento).toFixed(1)}%</td>
            <td style="text-align:right;font-weight:700;color:#b45309">${parseFloat(b.pct).toFixed(1)}%</td>
        `;
        tbody.appendChild(tr);
    });
}

function cerrarDetalle() {
    const panel = $('#rend-detalle-panel');
    if (panel) panel.style.display = 'none';
    if (chartRendDetalle) { chartRendDetalle.destroy(); chartRendDetalle = null; }
}

async function cargarVista() {
    showLoader(true);
    try {
        const f = getFiltrosActuales();
        const params = { fecha: f.fecha };
        if (f.turno)         params.turno = f.turno;
        if (_selCodMaquina)  params.cod_maquina = _selCodMaquina;
        if (_selCodArticulo) params.cod_articulo = _selCodArticulo;

        const d = await apiFetch('rendimiento_perdidas.php', params);

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
        $('#info-line').textContent = d.fecha + ' · ' + turnoLabel;

        $('#rend-total-h').textContent = parseFloat(d.total_horas).toFixed(2) + ' h';
        $('#rend-total-maquinas').textContent = (d.perdidas || []).length;
        $('#rend-top-maquina').textContent = (d.perdidas && d.perdidas.length) ? d.perdidas[0].maquina : '—';

        renderPareto(d.perdidas || [], d.total_horas);
        renderTabla(d.perdidas || []);

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
    const cd = $('#rend-detalle-cerrar'); if (cd) cd.addEventListener('click', cerrarDetalle);
    initFiltros(cargarVista);
    cargarVista();
});
