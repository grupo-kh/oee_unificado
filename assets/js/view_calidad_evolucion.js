/* Vista Calidad - Evolución (línea amarilla, filtros máquina/artículo) */

let chartCalEvo = null;
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

function renderEvolucion(evo) {
    const el = $('#chart-cal-evo');
    if (!evo.length) {
        el.innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:40px;font-size:13px">Sin datos para los filtros / ventana seleccionados</div>';
        $('#evo-summary').textContent = '';
        return;
    }
    const promedio = evo.reduce((a, e) => a + parseFloat(e.calidad), 0) / evo.length;
    const minV = Math.min(...evo.map(e => parseFloat(e.calidad)));
    const yMin = Math.max(0, Math.floor((minV - 5) / 5) * 5);
    $('#evo-summary').textContent = '— ' + evo.length + ' día' + (evo.length === 1 ? '' : 's') + ', promedio ' + promedio.toFixed(2) + '%';

    const options = {
        chart: {
            type: 'line', height: 360, background: 'transparent',
            toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'Arial'
        },
        series: [{
            name: 'Calidad',
            data: evo.map(d => ({ x: d.fecha, y: parseFloat(d.calidad), MOKT: d.MOKT_seg, MOT: d.MOT_seg, PC: d.PC_seg }))
        }],
        xaxis: {
            type: 'datetime',
            labels: {
                style: { colors: '#1a2d4a', fontSize: '12px', fontWeight: 600 },
                datetimeFormatter: { day: 'dd/MM/yyyy' }
            },
            axisBorder: { color: '#a3b8d1' },
            axisTicks:  { color: '#a3b8d1' }
        },
        yaxis: {
            min: yMin, max: 100,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '12px', fontWeight: 600 },
                formatter: v => v.toFixed(0) + '%'
            }
        },
        colors: ['#f4c430'],
        stroke: { curve: 'straight', width: 4 },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        dataLabels: {
            enabled: true,
            offsetY: -12,
            style: { colors: ['#1a2d4a'], fontFamily: 'Arial', fontSize: '12px', fontWeight: 700 },
            background: {
                enabled: true, foreColor: '#ffffff',
                padding: 4, borderRadius: 3, borderWidth: 1,
                borderColor: '#a3b8d1', opacity: 1
            },
            formatter: v => v.toFixed(2) + '%'
        },
        markers: {
            size: 7, colors: ['#f4c430'],
            strokeColors: '#1a2d4a', strokeWidth: 2,
            hover: { size: 10 }
        },
        tooltip: {
            x: { format: 'dd/MM/yyyy' },
            custom: ({series, seriesIndex, dataPointIndex, w}) => {
                const r = w.config.series[seriesIndex].data[dataPointIndex];
                const fmt = new Intl.NumberFormat('es-ES');
                const okmin   = Math.round(r.MOKT / 60);
                const oknkmin = Math.round(r.MOT  / 60);
                const pcmin   = Math.round(r.PC   / 60);
                return `
                    <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:220px">
                        <div style="font-weight:700;margin-bottom:6px">${r.x}</div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>M_OK_TEO</span><span>${fmt.format(okmin)} min</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>M_OKNOK_TEO</span><span>${fmt.format(oknkmin)} min</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>PCALIDAD</span><span>${fmt.format(pcmin)} min</span></div>
                        <div style="border-top:1px solid #3a5576;margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:13px">
                            <span>Calidad</span><span>${r.y.toFixed(2)}%</span>
                        </div>
                    </div>
                `;
            }
        },
        annotations: {
            yaxis: [{
                y: 95, borderColor: '#10b981', borderWidth: 2, strokeDashArray: 6,
                label: {
                    text: 'Objetivo 95%', borderColor: '#10b981',
                    style: { color: '#fff', background: '#10b981', fontSize: '11px', fontWeight: 700 }
                }
            }, {
                y: 100, borderColor: '#1a2d4a', strokeDashArray: 4,
                label: {
                    text: '100%', borderColor: '#1a2d4a',
                    style: { color: '#fff', background: '#1a2d4a', fontSize: '10px', fontWeight: 700 }
                }
            }]
        }
    };

    if (chartCalEvo) chartCalEvo.destroy();
    chartCalEvo = new ApexCharts(el, options);
    chartCalEvo.render();
}

async function cargarVista() {
    showLoader(true);
    try {
        const f = getFiltrosActuales();
        const params = { fecha: f.fecha };
        if (f.turno)         params.turno = f.turno;
        if (_selCodMaquina)  params.cod_maquina = _selCodMaquina;
        if (_selCodArticulo) params.cod_articulo = _selCodArticulo;

        const d = await apiFetch('calidad_evolucion.php', params);

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
        $('#info-line').textContent = d.fecha_desde + ' → ' + d.fecha_hasta + ' · ' + turnoLabel;

        renderEvolucion(d.evolucion || []);

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
