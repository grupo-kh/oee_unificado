/* Vista OEE FAB Global + Por Sección (Night Letter, panel izquierdo) */

let gaugeGlobal = null;
let chartSecciones = null;
let _selectedCodMaquina = '';   // estado actual del selector

function getQueryParam(name) {
    const u = new URLSearchParams(window.location.search);
    return u.get(name);
}

function updateUrlParam(key, value) {
    const u = new URL(window.location.href);
    if (value) u.searchParams.set(key, value);
    else u.searchParams.delete(key);
    // Limpiar también 'maquina' (nombre) si se quita el filtro
    if (!value) u.searchParams.delete('maquina');
    history.replaceState(null, '', u.pathname + (u.search ? u.search : '') + u.hash);
}

function populateMachineSelector(machines, current) {
    const sel = $('#machine-selector');
    if (!sel) return;
    // Orden por sección y luego por nombre
    const bySec = { 'VARILLAS': [], 'TROQUELADOS': [], 'OTROS': [] };
    machines.forEach(m => {
        const key = m.seccion && bySec[m.seccion] ? m.seccion : 'OTROS';
        bySec[key].push(m);
    });

    // Preservar la primera opción "todas"
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

function renderGauge(oeeVal, labelText) {
    const options = {
        chart: { type: 'radialBar', height: 340, background: 'transparent' },
        series: [oeeVal],
        plotOptions: {
            radialBar: {
                startAngle: -135,
                endAngle: 135,
                hollow: { size: '62%' },
                track: { background: '#e8eef5', strokeWidth: '100%' },
                dataLabels: {
                    name: {
                        show: true,
                        offsetY: -8,
                        color: '#5b8cc7',
                        fontSize: '13px',
                        fontWeight: 600
                    },
                    value: {
                        show: true,
                        offsetY: 8,
                        color: '#1a2d4a',
                        fontSize: '42px',
                        fontWeight: 700,
                        formatter: v => parseFloat(v).toFixed(2) + '%'
                    }
                }
            }
        },
        fill: {
            type: 'gradient',
            gradient: {
                shade: 'light',
                type: 'horizontal',
                shadeIntensity: 0.4,
                gradientToColors: [semColor(Math.min(100, oeeVal + 10))],
                inverseColors: false,
                opacityFrom: 1,
                opacityTo: 1,
                stops: [0, 100]
            }
        },
        colors: [semColor(oeeVal)],
        labels: [labelText || 'OEE'],
        stroke: { lineCap: 'round' }
    };
    if (gaugeGlobal) gaugeGlobal.destroy();
    gaugeGlobal = new ApexCharts($('#gauge-oee-global'), options);
    gaugeGlobal.render();
}

function renderSecciones(secciones) {
    const data = secciones.map(s => ({
        x: s.seccion,
        y: parseFloat(s.oee),
        maquinas: s.maquinas,
        disponibilidad: s.disponibilidad,
        rendimiento: s.rendimiento,
        calidad: s.calidad
    }));

    const options = {
        chart: {
            type: 'bar', height: 340, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial'
        },
        series: [{ name: 'OEE', data: data.map(d => d.y) }],
        xaxis: {
            categories: data.map(d => d.x),
            max: 100,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '11px' },
                formatter: v => v + '%'
            }
        },
        yaxis: {
            labels: {
                style: { colors: '#1a2d4a', fontSize: '13px', fontWeight: 700 }
            }
        },
        plotOptions: {
            bar: {
                horizontal: true,
                barHeight: '55%',
                borderRadius: 4,
                borderRadiusApplication: 'end',
                distributed: true,
                dataLabels: { position: 'center' }
            }
        },
        colors: data.map(d => semColor(d.y)),
        dataLabels: {
            enabled: true,
            style: { colors: ['#ffffff'], fontFamily: 'Arial', fontSize: '14px', fontWeight: 700 },
            formatter: v => v.toFixed(0) + '%'
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            custom: function({dataPointIndex}) {
                const r = data[dataPointIndex];
                return `
                    <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:180px">
                        <div style="font-weight:700;margin-bottom:6px;font-size:13px">${r.x}</div>
                        <div style="color:#a3b8d1;font-size:11px;margin-bottom:6px">${r.maquinas} máquina${r.maquinas===1?'':'s'}</div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Disponibilidad</span><span>${parseFloat(r.disponibilidad).toFixed(1)}%</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Rendimiento</span><span>${parseFloat(r.rendimiento).toFixed(1)}%</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Calidad</span><span>${parseFloat(r.calidad).toFixed(1)}%</span></div>
                        <div style="border-top:1px solid #3a5576;margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:13px">
                            <span>OEE</span><span>${r.y.toFixed(1)}%</span>
                        </div>
                    </div>
                `;
            }
        },
        annotations: {
            xaxis: [{
                x: 75,
                borderColor: '#10b981',
                strokeDashArray: 6,
                label: {
                    text: 'Objetivo 75%',
                    borderColor: '#10b981',
                    style: { color: '#fff', background: '#10b981', fontSize: '11px', fontWeight: 700 }
                }
            }]
        }
    };

    if (chartSecciones) chartSecciones.destroy();
    chartSecciones = new ApexCharts($('#chart-secciones'), options);
    chartSecciones.render();
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

function renderDrcCards(g) {
    const d = parseFloat(g.disponibilidad) || 0;
    const r = parseFloat(g.rendimiento)    || 0;
    const c = parseFloat(g.calidad)        || 0;

    const elD = $('#drc-d'); if (elD) elD.textContent = d > 0 ? d.toFixed(2) + '%' : '—';
    const elR = $('#drc-r'); if (elR) elR.textContent = r > 0 ? r.toFixed(2) + '%' : '—';
    const elC = $('#drc-c'); if (elC) elC.textContent = c > 0 ? c.toFixed(2) + '%' : '—';

    applyDrcCardColor('card-d', d);
    applyDrcCardColor('card-r', r);
    applyDrcCardColor('card-c', c);
}

async function cargarVista() {
    showLoader(true);
    try {
        const f = getFiltrosActuales();
        const params = { fecha: f.fecha };
        if (f.turno) params.turno = f.turno;
        if (_selectedCodMaquina) params.cod_maquina = _selectedCodMaquina;

        const d = await apiFetch('oee_fab_global.php', params);

        // Poblar selector (siempre con la lista completa del día/turno)
        populateMachineSelector(d.machines || [], _selectedCodMaquina);

        // Cabecera: mostrar alcance
        const scopeEl = $('#header-scope');
        if (_selectedCodMaquina) {
            const label = (d.maquina_info && d.maquina_info.maquina) || _selectedCodMaquina;
            scopeEl.textContent = '· filtrado: ' + label;
        } else {
            scopeEl.textContent = '';
        }

        const turnoLabel = d.turno ? { M:'MAÑANA',T:'TARDE',N:'NOCHE' }[d.turno] : 'TODOS LOS TURNOS';
        $('#oee-info').textContent = d.fecha + ' · ' + turnoLabel;

        renderGauge(
            parseFloat(d.global.oee),
            _selectedCodMaquina ? ((d.maquina_info && d.maquina_info.maquina) || _selectedCodMaquina) : 'OEE de Fabricación'
        );
        renderSecciones(d.secciones);
        renderDrcCards(d.global);

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
    // Estado inicial desde la URL (venimos del detalle/ranking con máquina pre-seleccionada)
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

    // Si cambia fecha/turno del header, re-cargar manteniendo la máquina seleccionada
    initFiltros(cargarVista);
    cargarVista();
});
