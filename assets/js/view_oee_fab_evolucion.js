/* Vista Evolución OEE FAB Global + Desglosada (Night Letter, panel inferior) */

let chartEvoOee = null;
let chartEvoDrc = null;
let _selectedCodMaquina = '';

function getQueryParam(name) {
    const u = new URLSearchParams(window.location.search);
    return u.get(name);
}

function updateUrlParam(key, value) {
    const u = new URL(window.location.href);
    if (value) u.searchParams.set(key, value);
    else u.searchParams.delete(key);
    if (!value) u.searchParams.delete('maquina');
    history.replaceState(null, '', u.pathname + (u.search ? u.search : '') + u.hash);
}

function populateMachineSelector(machines, current) {
    const sel = $('#machine-selector');
    if (!sel) return;
    const bySec = { 'VARILLAS': [], 'TROQUELADOS': [], 'OTROS': [] };
    machines.forEach(m => {
        const key = m.seccion && bySec[m.seccion] ? m.seccion : 'OTROS';
        bySec[key].push(m);
    });

    sel.innerHTML = '<option value="">— Todas las máquinas (vista global) —</option>';
    ['VARILLAS', 'TROQUELADOS', 'OTROS'].forEach(sec => {
        if (!bySec[sec].length) return;
        const og = document.createElement('optgroup');
        og.label = sec;
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

    const btnClear = $('#machine-selector-clear');
    if (btnClear) btnClear.style.display = current ? '' : 'none';
}

function applyDrcCardColor(cardId, value) {
    const card = document.getElementById(cardId);
    if (!card) return;
    card.classList.remove('drc-card-good', 'drc-card-warn', 'drc-card-bad', 'drc-card-empty');
    if (!isFinite(value) || value <= 0) {
        card.classList.add('drc-card-empty');
        return;
    }
    if (value >= 85)      card.classList.add('drc-card-good');
    else if (value >= 62) card.classList.add('drc-card-warn');
    else                  card.classList.add('drc-card-bad');
}

function renderDrcCards(evo, fechaHasta) {
    // Busca el día seleccionado (fecha_hasta); si no hay datos ese día, usa el más reciente
    let target = null;
    if (evo && evo.length) {
        target = evo.find(r => r.fecha === fechaHasta) || evo[evo.length - 1];
    }
    const d = target ? parseFloat(target.disponibilidad) || 0 : 0;
    const r = target ? parseFloat(target.rendimiento)    || 0 : 0;
    const c = target ? parseFloat(target.calidad)        || 0 : 0;

    const setTxt = (id, v) => { const el = $(id); if (el) el.textContent = v > 0 ? v.toFixed(2) + '%' : '—'; };
    setTxt('#drc-d', d);
    setTxt('#drc-r', r);
    setTxt('#drc-c', c);

    // Etiqueta con la fecha a la que corresponde (si no es la fecha_hasta)
    const etiqueta = target
        ? (target.fecha === fechaHasta ? '' : '· ' + target.fecha)
        : '';
    ['#drc-d-date', '#drc-r-date', '#drc-c-date'].forEach(sel => {
        const el = $(sel); if (el) el.textContent = etiqueta ? ' ' + etiqueta : '';
    });

    applyDrcCardColor('card-d', d);
    applyDrcCardColor('card-r', r);
    applyDrcCardColor('card-c', c);
}

function renderEvoOee(evo) {
    const el = $('#chart-evo-oee');
    if (!evo.length) {
        el.innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:40px;font-size:13px">Sin datos en el periodo seleccionado</div>';
        return;
    }
    const data = evo.map(d => ({ x: d.fecha, y: parseFloat(d.oee) }));

    const options = {
        chart: {
            type: 'line', height: 340, background: 'transparent',
            toolbar: { show: false }, zoom: { enabled: false },
            fontFamily: 'Arial'
        },
        series: [{ name: 'OEE', data }],
        colors: ['#f4c430'],
        stroke: { curve: 'straight', width: 4 },
        xaxis: {
            type: 'datetime',
            labels: {
                style: { colors: '#1a2d4a', fontSize: '11px', fontWeight: 600 },
                datetimeFormatter: { day: 'dd/MM/yyyy' }
            }
        },
        yaxis: {
            min: 0, max: 100,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '11px' },
                formatter: v => v.toFixed(0) + '%'
            }
        },
        markers: {
            size: 7, colors: ['#f4c430'],
            strokeColors: '#1a2d4a', strokeWidth: 2
        },
        dataLabels: {
            enabled: true, offsetY: -12,
            style: { colors: ['#1a2d4a'], fontFamily: 'Arial', fontSize: '11px', fontWeight: 700 },
            background: {
                enabled: true, foreColor: '#ffffff',
                padding: 4, borderRadius: 3, borderWidth: 1, borderColor: '#a3b8d1', opacity: 1
            },
            formatter: v => v.toFixed(0) + '%'
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        tooltip: { x: { format: 'dd/MM/yyyy' }, y: { formatter: v => v.toFixed(2) + '%' } },
        annotations: {
            yaxis: [{
                y: 75,
                borderColor: '#10b981',
                borderWidth: 2,
                strokeDashArray: 6,
                label: {
                    text: 'Objetivo 75%',
                    borderColor: '#10b981',
                    style: { color: '#fff', background: '#10b981', fontSize: '11px', fontWeight: 700 }
                }
            }]
        }
    };
    if (chartEvoOee) chartEvoOee.destroy();
    chartEvoOee = new ApexCharts(el, options);
    chartEvoOee.render();
}

function renderEvoDrc(evo) {
    const el = $('#chart-evo-drc');
    if (!evo.length) {
        el.innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:40px;font-size:13px">Sin datos en el periodo seleccionado</div>';
        return;
    }
    const options = {
        chart: {
            type: 'line', height: 340, background: 'transparent',
            toolbar: { show: false }, zoom: { enabled: false },
            fontFamily: 'Arial'
        },
        series: [
            { name: 'Disponibilidad', data: evo.map(d => ({ x: d.fecha, y: parseFloat(d.disponibilidad) })) },
            { name: 'Rendimiento',    data: evo.map(d => ({ x: d.fecha, y: parseFloat(d.rendimiento) })) },
            { name: 'Calidad',        data: evo.map(d => ({ x: d.fecha, y: parseFloat(d.calidad) })) }
        ],
        colors: ['#8b5cf6', '#c8102e', '#10b981'],
        stroke: { curve: 'straight', width: 3 },
        xaxis: {
            type: 'datetime',
            labels: {
                style: { colors: '#1a2d4a', fontSize: '11px', fontWeight: 600 },
                datetimeFormatter: { day: 'dd/MM/yyyy' }
            }
        },
        yaxis: {
            min: 60, max: 120,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '11px' },
                formatter: v => v.toFixed(0) + '%'
            }
        },
        markers: { size: 5, strokeWidth: 2 },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: {
            position: 'top', horizontalAlign: 'right',
            fontFamily: 'Arial', fontSize: '12px', fontWeight: 600,
            markers: { width: 12, height: 12, radius: 6 }
        },
        tooltip: { x: { format: 'dd/MM/yyyy' }, y: { formatter: v => v.toFixed(1) + '%' } }
    };
    if (chartEvoDrc) chartEvoDrc.destroy();
    chartEvoDrc = new ApexCharts(el, options);
    chartEvoDrc.render();
}

async function cargarVista() {
    showLoader(true);
    try {
        const f = getFiltrosActuales();
        const params = { fecha: f.fecha };
        if (f.turno) params.turno = f.turno;
        if (_selectedCodMaquina) params.cod_maquina = _selectedCodMaquina;

        const d = await apiFetch('oee_fab_evolucion.php', params);

        populateMachineSelector(d.machines || [], _selectedCodMaquina);

        const scopeEl = $('#header-scope');
        if (_selectedCodMaquina) {
            const label = (d.maquina_info && d.maquina_info.maquina) || _selectedCodMaquina;
            scopeEl.textContent = '· filtrado: ' + label;
        } else {
            scopeEl.textContent = '';
        }

        const turnoLabel = d.turno ? { M:'MAÑANA',T:'TARDE',N:'NOCHE' }[d.turno] : 'TODOS LOS TURNOS';
        $('#oee-info').textContent = d.fecha_desde + ' — ' + d.fecha_hasta + ' · ' + turnoLabel;

        renderDrcCards(d.evolucion || [], d.fecha_hasta);
        renderEvoOee(d.evolucion || []);
        renderEvoDrc(d.evolucion || []);

    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

function onMachineSelectorChange() {
    const sel = $('#machine-selector');
    _selectedCodMaquina = sel ? (sel.value || '') : '';
    updateUrlParam('cod_maquina', _selectedCodMaquina);
    const btnClear = $('#machine-selector-clear');
    if (btnClear) btnClear.style.display = _selectedCodMaquina ? '' : 'none';
    cargarVista();
}

document.addEventListener('DOMContentLoaded', () => {
    _selectedCodMaquina = getQueryParam('cod_maquina') || '';

    const sel = $('#machine-selector');
    if (sel) sel.addEventListener('change', onMachineSelectorChange);

    const btnClear = $('#machine-selector-clear');
    if (btnClear) {
        btnClear.addEventListener('click', () => {
            _selectedCodMaquina = '';
            const s = $('#machine-selector'); if (s) s.value = '';
            updateUrlParam('cod_maquina', '');
            btnClear.style.display = 'none';
            cargarVista();
        });
    }

    initFiltros(cargarVista);
    cargarVista();
});
