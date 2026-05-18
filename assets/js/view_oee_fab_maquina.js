/* Vista OEE FAB por Máquina – replica del panel Night Letter (QV) */

let chartOeeFabMaq = null;

function renderChart(data) {
    const el = $('#chart-oee-fab-maq');
    if (!data.length) {
        el.innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:40px;font-size:14px">Sin datos en el periodo seleccionado</div>';
        $('#oee-count').textContent = '0 máquinas';
        return;
    }
    $('#oee-count').textContent = data.length + ' máquinas';

    const categorias = data.map(d => d.maquina || d.cod_maquina);
    const valores    = data.map(d => parseFloat(d.oee));

    const options = {
        chart: {
            type: 'bar',
            height: '100%',
            background: 'transparent',
            toolbar: { show: false },
            fontFamily: 'Arial',
            events: {
                dataPointSelection: (ev, ctx, cfg) => {
                    const idx = cfg.dataPointIndex;
                    const row = data[idx];
                    if (!row) return;
                    const f = getFiltrosActuales();
                    const params = new URLSearchParams({
                        cod_maquina: row.cod_maquina,
                        maquina: row.maquina
                    });
                    if (f.fecha) params.set('fecha', f.fecha);
                    if (f.turno) params.set('turno', f.turno);
                    window.location.href = 'oee_fab_maquina_detalle.php?' + params.toString();
                }
            }
        },
        series: [{ name: 'OEE', data: valores }],
        xaxis: {
            categories: categorias,
            labels: {
                style: { colors: '#1a2d4a', fontSize: '11px', fontWeight: 700 },
                rotate: -35,
                rotateAlways: categorias.length > 8,
                trim: false,
                hideOverlappingLabels: false
            },
            axisBorder: { color: '#a3b8d1' },
            axisTicks:  { color: '#a3b8d1' }
        },
        yaxis: {
            max: 100, min: 0,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '12px' },
                formatter: v => v.toFixed(0) + '%'
            }
        },
        plotOptions: {
            bar: {
                columnWidth: '55%',
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
            style: { colors: ['#1a2d4a'], fontFamily: 'Arial', fontSize: '12px', fontWeight: 700 },
            formatter: v => v.toFixed(0) + '%'
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            custom: function({series, seriesIndex, dataPointIndex}) {
                const r = data[dataPointIndex];
                return `
                    <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:180px">
                        <div style="font-weight:700;margin-bottom:6px;font-size:13px">${r.maquina}</div>
                        <div style="color:#a3b8d1;font-size:10px;margin-bottom:6px">(${r.cod_maquina})</div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Disponibilidad</span><span>${parseFloat(r.disponibilidad).toFixed(1)}%</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Rendimiento</span><span>${parseFloat(r.rendimiento).toFixed(1)}%</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Calidad</span><span>${parseFloat(r.calidad).toFixed(1)}%</span></div>
                        <div style="border-top:1px solid #3a5576;margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:13px">
                            <span>OEE</span><span>${parseFloat(r.oee).toFixed(1)}%</span>
                        </div>
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

    if (chartOeeFabMaq) chartOeeFabMaq.destroy();
    chartOeeFabMaq = new ApexCharts(el, options);
    chartOeeFabMaq.render();

    // Cursor pointer sobre las barras para indicar que son clicables
    setTimeout(() => {
        el.querySelectorAll('.apexcharts-bar-area').forEach(b => b.style.cursor = 'pointer');
    }, 100);
}

async function cargarVista() {
    showLoader(true);
    try {
        const f = getFiltrosActuales();
        const data = await apiFetch('oee_fab_maquina.php', { fecha: f.fecha, turno: f.turno });
        renderChart(data);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initFiltros(cargarVista);
    cargarVista();
});
