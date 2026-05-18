/* Vista Por Máquina - barras verticales con porcentajes encima */

let chartMaquina = null;

function renderChart(data) {
    if (!data.length) {
        $('#chart-maquina-big').innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:40px;font-size:14px">Sin datos en el periodo seleccionado</div>';
        $('#maq-count').textContent = '0 máquinas';
        return;
    }

    $('#maq-count').textContent = data.length + ' máquinas';

    const categorias = data.map(d => d.maquina || d.cod_maquina);
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
                style: { colors: '#1a2d4a', fontSize: '12px', fontWeight: 700 },
                rotate: -35,
                rotateAlways: categorias.length > 10
            },
            axisBorder: { color: '#a3b8d1' },
            axisTicks:  { color: '#a3b8d1' }
        },
        yaxis: {
            max: 100,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '12px' },
                formatter: v => v.toFixed(0) + '%'
            }
        },
        plotOptions: {
            bar: {
                columnWidth: '60%',
                borderRadius: 4,
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
                fontSize: '12px',
                fontWeight: 700
            },
            formatter: v => v.toFixed(1) + '%'
        },
        grid: {
            borderColor: '#d5dfe8',
            strokeDashArray: 3
        },
        legend: { show: false },
        tooltip: {
            custom: function({series, seriesIndex, dataPointIndex}) {
                const row = data[dataPointIndex];
                return `
                    <div style="padding:10px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px">
                        <div style="font-weight:700;margin-bottom:4px">${row.maquina}</div>
                        <div style="color:#a3b8d1;font-size:11px">${row.seccion || 'Sin sección'}</div>
                        <div style="margin-top:6px;font-size:14px;font-weight:700">${series[seriesIndex][dataPointIndex].toFixed(2)}%</div>
                    </div>
                `;
            }
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

    if (chartMaquina) chartMaquina.destroy();
    chartMaquina = new ApexCharts($('#chart-maquina-big'), options);
    chartMaquina.render();
}

let _maquinaMeta = null;

async function cargarVista() {
    showLoader(true);
    try {
        const f = getFiltrosActuales();
        const data = await apiFetch('por_maquina.php', { fecha: f.fecha, turno: f.turno });
        _maquinaMeta = data.meta || null;
        renderChart(data.rows || []);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initFiltros(cargarVista);
    attachInfoIcon('#info-icon', () => _maquinaMeta);
    cargarVista();
});
