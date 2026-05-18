/* Vista Plan Attainment (gauge grande) */

let gaugeChart = null;

function renderGauge(valor) {
    const options = {
        chart: {
            type: 'radialBar',
            height: 420,
            background: 'transparent'
        },
        series: [valor],
        colors: ['#c8102e'],
        plotOptions: {
            radialBar: {
                startAngle: -135,
                endAngle: 135,
                hollow: { size: '58%', background: '#ffffff' },
                track: {
                    background: '#e8eef5',
                    strokeWidth: '100%',
                    margin: 0
                },
                dataLabels: {
                    name: {
                        show: true,
                        color: '#5b8cc7',
                        fontSize: '13px',
                        fontFamily: 'Arial',
                        fontWeight: 700,
                        offsetY: 40,
                        formatter: () => 'CUMPLIMIENTO GLOBAL'
                    },
                    value: {
                        show: true,
                        color: '#1a2d4a',
                        fontSize: '68px',
                        fontFamily: 'Arial',
                        fontWeight: 700,
                        offsetY: -5,
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
                colorStops: [
                    { offset: 0,   color: '#ef4444', opacity: 1 },
                    { offset: 50,  color: '#f59e0b', opacity: 1 },
                    { offset: 100, color: '#10b981', opacity: 1 }
                ]
            }
        },
        stroke: { lineCap: 'round' }
    };

    if (gaugeChart) gaugeChart.destroy();
    gaugeChart = new ApexCharts($('#gauge-big'), options);
    gaugeChart.render();
}

let _gaugeMeta = null;

async function cargarVista() {
    showLoader(true);
    try {
        const f = getFiltrosActuales();
        // Plan Attainment se mira por día concreto (como QW): solo fecha + turno.
        const data = await apiFetch('plan_attainment.php', { fecha: f.fecha, turno: f.turno });
        _gaugeMeta = data.meta || null;
        renderGauge(data.plan_attainment);
        $('#m-disp').textContent = data.disponibilidad.toFixed(1) + '%';
        $('#m-rend').textContent = data.rendimiento.toFixed(1) + '%';
        $('#m-cal').textContent  = data.calidad.toFixed(1) + '%';
        $('#m-oee').textContent  = data.oee.toFixed(1) + '%';
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initFiltros(cargarVista);
    attachInfoIcon('#info-icon', () => _gaugeMeta);
    cargarVista();
});
