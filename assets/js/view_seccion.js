/* Vista Por Sección */

let chartSeccion = null;

function renderChart(data) {
    if (!data.length) {
        $('#chart-seccion-big').innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:40px;font-size:14px">Sin datos en el periodo seleccionado</div>';
        return;
    }

    const categorias = data.map(d => d.seccion);
    const valores    = data.map(d => parseFloat(d.plan_attainment));

    const options = {
        chart: {
            type: 'bar',
            height: '100%',
            background: 'transparent',
            toolbar: { show: false },
            fontFamily: 'Arial'
        },
        series: [{ name: 'Plan Attainment', data: valores }],
        xaxis: {
            categories: categorias,
            labels: {
                style: { colors: '#1a2d4a', fontSize: '13px', fontWeight: 700 }
            },
            axisBorder: { color: '#a3b8d1' },
            axisTicks:  { color: '#a3b8d1' }
        },
        yaxis: {
            max: 100,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '12px', fontWeight: 600 },
                formatter: v => v.toFixed(0) + '%'
            }
        },
        plotOptions: {
            bar: {
                columnWidth: '55%',
                borderRadius: 6,
                borderRadiusApplication: 'end',
                distributed: true,
                dataLabels: { position: 'top' }
            }
        },
        colors: valores.map(v => semColor(v)),
        dataLabels: {
            enabled: true,
            offsetY: -22,
            style: {
                colors: ['#1a2d4a'],
                fontFamily: 'Arial',
                fontSize: '14px',
                fontWeight: 700
            },
            formatter: v => v.toFixed(1) + '%'
        },
        grid: {
            borderColor: '#d5dfe8',
            strokeDashArray: 3,
            yaxis: { lines: { show: true } }
        },
        legend: { show: false },
        tooltip: {
            y: { formatter: v => v.toFixed(2) + '%' }
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
                    style: { color: '#ffffff', background: '#10b981', fontSize: '11px', fontWeight: 700 }
                }
            }]
        }
    };

    if (chartSeccion) chartSeccion.destroy();
    chartSeccion = new ApexCharts($('#chart-seccion-big'), options);
    chartSeccion.render();
}

let _seccionMeta = null;

async function cargarVista() {
    showLoader(true);
    try {
        const f = getFiltrosActuales();
        const data = await apiFetch('por_seccion.php', { fecha: f.fecha, turno: f.turno });
        _seccionMeta = data.meta || null;
        renderChart(data.rows || []);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initFiltros(cargarVista);
    attachInfoIcon('#info-icon', () => _seccionMeta);
    cargarVista();
});
