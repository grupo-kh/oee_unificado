/* Vista detalle OEE por máquina: gauge + evolución 7 días */

let gaugeOeeMaq = null;
let chartOeeMaqEvo = null;
let chartOeeMaqHorario = null;

function getQueryParam(name) {
    const u = new URLSearchParams(window.location.search);
    return u.get(name);
}

function renderGauge(oee) {
    const options = {
        chart: { type: 'radialBar', height: 300, background: 'transparent' },
        series: [oee],
        plotOptions: {
            radialBar: {
                hollow: { size: '65%' },
                track: { background: '#e8eef5', strokeWidth: '100%' },
                dataLabels: {
                    name: { show: true, offsetY: -8, color: '#5b8cc7', fontSize: '13px' },
                    value: {
                        show: true, offsetY: 6, color: '#1a2d4a',
                        fontSize: '36px', fontWeight: 700,
                        formatter: v => parseFloat(v).toFixed(1) + '%'
                    }
                }
            }
        },
        labels: ['OEE'],
        colors: [semColor(oee)],
        stroke: { lineCap: 'round' }
    };
    if (gaugeOeeMaq) gaugeOeeMaq.destroy();
    gaugeOeeMaq = new ApexCharts($('#gauge-oee-maq'), options);
    gaugeOeeMaq.render();
}

function renderEvolucion(evo) {
    const el = $('#chart-oee-maq-evo');
    if (!evo.length) {
        el.innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:30px;font-size:13px">Sin datos de evolución para esta máquina</div>';
        return;
    }
    const options = {
        chart: {
            type: 'line', height: 280, background: 'transparent',
            toolbar: { show: false }, zoom: { enabled: false },
            fontFamily: 'Arial'
        },
        series: [
            { name: 'OEE',            data: evo.map(d => ({ x: d.fecha, y: parseFloat(d.oee) })) },
            { name: 'Disponibilidad', data: evo.map(d => ({ x: d.fecha, y: parseFloat(d.disponibilidad) })) },
            { name: 'Rendimiento',    data: evo.map(d => ({ x: d.fecha, y: parseFloat(d.rendimiento) })) },
            { name: 'Calidad',        data: evo.map(d => ({ x: d.fecha, y: parseFloat(d.calidad) })) }
        ],
        colors: ['#f4c430', '#8b5cf6', '#c8102e', '#10b981'],
        stroke: { curve: 'straight', width: [4, 2, 2, 2] },
        xaxis: {
            type: 'datetime',
            labels: { style: { colors: '#1a2d4a', fontSize: '11px', fontWeight: 600 },
                      datetimeFormatter: { day: 'dd/MM/yyyy' } }
        },
        yaxis: {
            min: 0, max: 120,
            labels: { style: { colors: '#2d4d7a', fontSize: '11px' },
                      formatter: v => v.toFixed(0) + '%' }
        },
        markers: { size: [6, 4, 4, 4], strokeWidth: 2 },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: {
            position: 'top', horizontalAlign: 'right',
            fontFamily: 'Arial', fontSize: '12px', fontWeight: 600
        },
        tooltip: { x: { format: 'dd/MM/yyyy' },
                   y: { formatter: v => v.toFixed(1) + '%' } }
    };
    if (chartOeeMaqEvo) chartOeeMaqEvo.destroy();
    chartOeeMaqEvo = new ApexCharts(el, options);
    chartOeeMaqEvo.render();
}

function renderHorario(horario) {
    const el = $('#chart-oee-maq-horario');
    const totalEl = $('#horario-total');
    if (!horario || !horario.length) {
        el.innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:30px;font-size:13px">Sin producción registrada en el turno</div>';
        if (totalEl) totalEl.textContent = '';
        return;
    }
    const total = horario.reduce((a, h) => a + parseFloat(h.prod || 0), 0);
    if (totalEl) totalEl.textContent = '— ' + new Intl.NumberFormat('es-ES').format(total) + ' u totales';

    const categorias = horario.map(h => h.hora);
    const valores    = horario.map(h => parseFloat(h.prod));
    const maxVal = Math.max(...valores, 1);

    const options = {
        chart: {
            type: 'bar', height: 240, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial'
        },
        series: [{ name: 'Producción', data: valores }],
        xaxis: {
            categories: categorias,
            labels: { style: { colors: '#1a2d4a', fontSize: '11px', fontWeight: 600 } },
            axisBorder: { color: '#a3b8d1' },
            axisTicks:  { color: '#a3b8d1' }
        },
        yaxis: {
            min: 0,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '11px' },
                formatter: v => new Intl.NumberFormat('es-ES').format(Math.round(v))
            }
        },
        plotOptions: {
            bar: {
                columnWidth: '55%',
                borderRadius: 3,
                borderRadiusApplication: 'end',
                dataLabels: { position: 'top' }
            }
        },
        colors: ['#3a6aa3'],
        dataLabels: {
            enabled: true,
            offsetY: -18,
            style: { colors: ['#1a2d4a'], fontFamily: 'Arial', fontSize: '11px', fontWeight: 700 },
            formatter: v => v > 0 ? new Intl.NumberFormat('es-ES').format(Math.round(v)) : ''
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            y: { formatter: v => new Intl.NumberFormat('es-ES').format(Math.round(v)) + ' u' }
        }
    };
    if (chartOeeMaqHorario) chartOeeMaqHorario.destroy();
    chartOeeMaqHorario = new ApexCharts(el, options);
    chartOeeMaqHorario.render();
}

async function cargarVista() {
    const cod = getQueryParam('cod_maquina');
    if (!cod) {
        showToast('Falta parámetro cod_maquina', 'error');
        return;
    }
    showLoader(true);
    try {
        const f = getFiltrosActuales();
        const params = { cod_maquina: cod, fecha: f.fecha };
        if (f.turno) params.turno = f.turno;
        const d = await apiFetch('oee_fab_maquina_detalle.php', params);

        $('#maq-name').textContent = d.maquina;
        $('#maq-cod').textContent  = '(' + d.cod_maquina + ')';

        // Enlace al gauge+sección con la máquina filtrada
        const btn = $('#btn-ver-global');
        if (btn) {
            const p = new URLSearchParams({ cod_maquina: d.cod_maquina, maquina: d.maquina });
            if (d.fecha) p.set('fecha', d.fecha);
            if (d.turno) p.set('turno', d.turno);
            btn.href = 'oee_fab_global.php?' + p.toString();
        }
        $('#oee-info').textContent = d.turno
            ? `${d.fecha} · ${ {M:'MAÑANA',T:'TARDE',N:'NOCHE'}[d.turno] || d.turno }`
            : `${d.fecha} · TODOS LOS TURNOS`;

        $('#m-d').textContent   = d.current.disponibilidad.toFixed(1) + '%';
        $('#m-r').textContent   = d.current.rendimiento.toFixed(1) + '%';
        $('#m-c').textContent   = d.current.calidad.toFixed(1) + '%';
        $('#m-oee').textContent = d.current.oee.toFixed(1) + '%';

        renderGauge(d.current.oee);
        renderEvolucion(d.evolucion);
        renderHorario(d.horario);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Al cambiar filtros recargamos el detalle (misma máquina, otra fecha/turno)
    initFiltros(cargarVista);
    cargarVista();
});
