/* Vista Evolución - línea temporal amarilla como el original QlikView */

let chartEvolucion = null;

function renderChart(data) {
    if (!data.length) {
        $('#chart-evolucion-big').innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:40px;font-size:14px">Sin datos en el periodo seleccionado</div>';
        return;
    }

    const series = [{
        name: 'Plan Attainment',
        data: data.map(d => ({
            x: d.fecha,
            y: parseFloat(d.plan_attainment)
        }))
    }];

    const options = {
        chart: {
            type: 'line',
            height: '100%',
            background: 'transparent',
            toolbar: { show: false },
            zoom: { enabled: false },
            fontFamily: 'Arial'
        },
        series: series,
        xaxis: {
            type: 'datetime',
            labels: {
                style: { colors: '#1a2d4a', fontSize: '12px', fontWeight: 600 },
                datetimeFormatter: {
                    year:  'yyyy',
                    month: "MMM 'yy",
                    day:   'dd/MM/yyyy',
                    hour:  'HH:mm'
                }
            },
            axisBorder: { color: '#a3b8d1' },
            axisTicks:  { color: '#a3b8d1' }
        },
        yaxis: {
            min: 0,
            max: 100,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '12px', fontWeight: 600 },
                formatter: v => v.toFixed(0) + '%'
            }
        },
        colors: ['#f4c430'],   // amarillo como el QlikView original
        stroke: { curve: 'straight', width: 4 },
        grid: {
            borderColor: '#d5dfe8',
            strokeDashArray: 3
        },
        dataLabels: {
            enabled: true,
            offsetY: -12,
            style: {
                colors: ['#1a2d4a'],
                fontFamily: 'Arial',
                fontSize: '12px',
                fontWeight: 700
            },
            background: {
                enabled: true,
                foreColor: '#ffffff',
                padding: 4,
                borderRadius: 3,
                borderWidth: 1,
                borderColor: '#a3b8d1',
                opacity: 1
            },
            formatter: v => v.toFixed(0) + '%'
        },
        tooltip: {
            x: { format: 'dd/MM/yyyy' },
            y: { formatter: v => v.toFixed(2) + '%' }
        },
        markers: {
            size: 7,
            colors: ['#f4c430'],
            strokeColors: '#1a2d4a',
            strokeWidth: 2,
            hover: { size: 10 }
        },
        annotations: {
            yaxis: [{
                y: 75,
                borderColor: '#10b981',
                borderWidth: 2,
                strokeDashArray: 6,
                label: {
                    text: 'Objetivo 75%',
                    borderColor: '#10b981',
                    style: {
                        color: '#ffffff',
                        background: '#10b981',
                        fontSize: '11px',
                        fontWeight: 700,
                        padding: { left: 8, right: 8, top: 4, bottom: 4 }
                    }
                }
            }]
        }
    };

    if (chartEvolucion) chartEvolucion.destroy();
    chartEvolucion = new ApexCharts($('#chart-evolucion-big'), options);
    chartEvolucion.render();
}

async function cargarVista() {
    showLoader(true);
    try {
        // 7 días hacia atrás desde fecha_hasta, igual que el panel de referencia
        const f = getFiltrosActuales();
        const dHasta = new Date(f.fecha_hasta);
        const dDesde = new Date(dHasta);
        dDesde.setDate(dDesde.getDate() - 6);
        f.fecha_desde = formatFecha(dDesde);

        const data = await apiFetch('evolucion.php', f);
        _evolucionMeta = data.meta || null;
        renderChart(data.rows || []);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

let _evolucionMeta = null;

document.addEventListener('DOMContentLoaded', () => {
    initFiltros(cargarVista);
    attachInfoIcon('#info-icon', () => _evolucionMeta);
    cargarVista();
});
