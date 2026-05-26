/* Vista Plan Attainment · panel unificado de 3 módulos.
   ─────────────────────────────────────────────────────────────────────
   1) Gauge global (Cumplimiento + 4 métricas OEE)
   2) Cumplimiento por sección  (clic en barra → filtra por sección)
   3) Evolución últimos 7 días  (clic en punto  → filtra por esa fecha)

   El estado de cross-filter es local a la vista:
     _selSeccion : 'VARILLAS' | 'TROQUELADOS' | ''
     _selFecha   : 'YYYY-MM-DD' | ''  (sobrescribe la fecha del header)
   Cuando cualquiera de los dos cambia, los 3 paneles se vuelven a cargar
   con esos parámetros para que se reflejen en todas las visualizaciones. */

let gaugeChart     = null;
let chartSeccion   = null;
let chartEvolucion = null;
let chartMaquina   = null;

let _selSeccion = '';
let _selFecha   = '';
let _selMaquina = '';

let _gaugeMeta = null;
let _maquinasRows = []; // cache de la última respuesta para auto-descarte

// ───── Render gauge ──────────────────────────────────────────────────
function renderGauge(valor) {
    const options = {
        chart: { type: 'radialBar', height: 380, background: 'transparent' },
        series: [valor],
        colors: ['#c8102e'],
        plotOptions: {
            radialBar: {
                startAngle: -135,
                endAngle: 135,
                hollow: { size: '58%', background: '#ffffff' },
                track: { background: '#e8eef5', strokeWidth: '100%', margin: 0 },
                dataLabels: {
                    name: {
                        show: true, color: '#5b8cc7', fontSize: '13px',
                        fontFamily: 'Arial', fontWeight: 700, offsetY: 40,
                        formatter: () => 'CUMPLIMIENTO GLOBAL'
                    },
                    value: {
                        show: true, color: '#1a2d4a', fontSize: '60px',
                        fontFamily: 'Arial', fontWeight: 700, offsetY: -5,
                        formatter: v => parseFloat(v).toFixed(2) + '%'
                    }
                }
            }
        },
        fill: {
            type: 'gradient',
            gradient: {
                shade: 'light', type: 'horizontal',
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

// ───── Render por sección ────────────────────────────────────────────
function renderSeccion(data) {
    const cont = $('#chart-seccion-big');
    if (!data.length) {
        cont.innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:40px;font-size:14px">Sin datos en el periodo seleccionado</div>';
        return;
    }
    const categorias = data.map(d => d.seccion);
    const valores    = data.map(d => parseFloat(d.plan_attainment));
    // Las barras de la sección actualmente seleccionada se destacan, el
    // resto se atenúan para reforzar visualmente el filtro.
    const colors = categorias.map((sec, i) => {
        const c = semColor(valores[i]);
        if (_selSeccion && _selSeccion !== sec) return c + '55'; // 33% opacidad
        return c;
    });
    const options = {
        chart: {
            type: 'bar', height: '100%', background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _c, cfg) => {
                    const idx = cfg.dataPointIndex;
                    const sec = categorias[idx];
                    onSeccionClick(sec);
                }
            }
        },
        series: [{ name: 'Plan Attainment', data: valores }],
        xaxis: {
            categories: categorias,
            labels: { style: { colors: '#1a2d4a', fontSize: '13px', fontWeight: 700 } },
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
                columnWidth: '55%', borderRadius: 6, borderRadiusApplication: 'end',
                distributed: true, dataLabels: { position: 'top' }
            }
        },
        colors: colors,
        dataLabels: {
            enabled: true, offsetY: -22,
            style: { colors: ['#1a2d4a'], fontFamily: 'Arial', fontSize: '14px', fontWeight: 700 },
            formatter: v => v.toFixed(1) + '%'
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3, yaxis: { lines: { show: true } } },
        legend: { show: false },
        tooltip: { y: { formatter: v => v.toFixed(2) + '%' } },
        states: {
            active: { allowMultipleDataPointsSelection: false, filter: { type: 'none' } },
            hover:  { filter: { type: 'lighten', value: 0.15 } }
        },
        annotations: {
            yaxis: [{
                y: 75,
                borderColor: '#10b981', borderWidth: 2, strokeDashArray: 6,
                label: {
                    text: 'Objetivo 75%', borderColor: '#10b981',
                    style: { color: '#ffffff', background: '#10b981', fontSize: '11px', fontWeight: 700 }
                }
            }]
        }
    };
    if (chartSeccion) chartSeccion.destroy();
    chartSeccion = new ApexCharts(cont, options);
    chartSeccion.render();
}

// ───── Render evolución ──────────────────────────────────────────────
function renderEvolucion(data) {
    const cont = $('#chart-evolucion-big');
    if (!data.length) {
        cont.innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:40px;font-size:14px">Sin datos en el periodo seleccionado</div>';
        return;
    }
    const series = [{
        name: 'Plan Attainment',
        data: data.map(d => ({ x: d.fecha, y: parseFloat(d.plan_attainment) }))
    }];
    const options = {
        chart: {
            type: 'line', height: '100%', background: 'transparent',
            toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'Arial',
            events: {
                markerClick: (_e, _c, cfg) => {
                    const idx = cfg.dataPointIndex;
                    const fecha = data[idx]?.fecha;
                    if (fecha) onFechaClick(fecha);
                }
            }
        },
        series: series,
        xaxis: {
            type: 'datetime',
            labels: {
                style: { colors: '#1a2d4a', fontSize: '12px', fontWeight: 600 },
                datetimeFormatter: { year: 'yyyy', month: "MMM 'yy", day: 'dd/MM/yyyy', hour: 'HH:mm' }
            },
            axisBorder: { color: '#a3b8d1' }, axisTicks: { color: '#a3b8d1' }
        },
        yaxis: {
            min: 0, max: 100,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '12px', fontWeight: 600 },
                formatter: v => v.toFixed(0) + '%'
            }
        },
        colors: ['#f4c430'],
        stroke: { curve: 'straight', width: 4 },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        dataLabels: {
            enabled: true, offsetY: -12,
            style: { colors: ['#1a2d4a'], fontFamily: 'Arial', fontSize: '12px', fontWeight: 700 },
            background: {
                enabled: true, foreColor: '#ffffff', padding: 4,
                borderRadius: 3, borderWidth: 1, borderColor: '#a3b8d1', opacity: 1
            },
            formatter: v => v.toFixed(0) + '%'
        },
        tooltip: { x: { format: 'dd/MM/yyyy' }, y: { formatter: v => v.toFixed(2) + '%' } },
        markers: {
            size: 7, colors: ['#f4c430'], strokeColors: '#1a2d4a', strokeWidth: 2,
            hover: { size: 11 }
        },
        annotations: {
            yaxis: [{
                y: 75,
                borderColor: '#10b981', borderWidth: 2, strokeDashArray: 6,
                label: {
                    text: 'Objetivo 75%', borderColor: '#10b981',
                    style: {
                        color: '#ffffff', background: '#10b981',
                        fontSize: '11px', fontWeight: 700,
                        padding: { left: 8, right: 8, top: 4, bottom: 4 }
                    }
                }
            }]
        }
    };
    if (chartEvolucion) chartEvolucion.destroy();
    chartEvolucion = new ApexCharts(cont, options);
    chartEvolucion.render();
}

function onMaquinaClick(maq) {
    if (_selMaquina === maq) _selMaquina = '';
    else _selMaquina = maq;
    refreshActiveFilterBar();
    // Solo re-renderizamos máquinas (para destacar/atenuar) y detalle.
    // El gauge, sección y evolución no se filtran por máquina.
    renderMaquinas(_maquinasRows);
    cargarDetalle();
}

// ───── Render máquinas (horizontal bars) ─────────────────────────────
function renderMaquinas(rows) {
    const cont = $('#chart-maquina-big');
    if (!rows.length) {
        cont.innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:40px;font-size:14px">Sin máquinas en el periodo seleccionado</div>';
        if (chartMaquina) { chartMaquina.destroy(); chartMaquina = null; }
        return;
    }
    // Orden descendente por % attainment
    rows = rows.slice().sort((a, b) => parseFloat(b.plan_attainment) - parseFloat(a.plan_attainment));
    const categorias = rows.map(r => r.maquina);
    const valores    = rows.map(r => parseFloat(r.plan_attainment));
    const colors = categorias.map((maq, i) => {
        const c = semColor(valores[i]);
        if (_selMaquina && _selMaquina !== maq) return c + '55';
        return c;
    });
    const altura = Math.max(540, 36 * rows.length + 80);
    const options = {
        chart: {
            type: 'bar', height: altura, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _c, cfg) => {
                    const idx = cfg.dataPointIndex;
                    const maq = categorias[idx];
                    onMaquinaClick(maq);
                }
            }
        },
        series: [{ name: 'Plan Attainment', data: valores }],
        plotOptions: {
            bar: {
                horizontal: true,
                barHeight: '70%',
                borderRadius: 4, borderRadiusApplication: 'end',
                distributed: true,
                dataLabels: { position: 'top' }
            }
        },
        colors: colors,
        xaxis: {
            categories: categorias,
            max: 100, min: 0,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '12px', fontWeight: 600 },
                formatter: v => (typeof v === 'number' ? v.toFixed(0) + '%' : v)
            },
            axisBorder: { color: '#a3b8d1' }, axisTicks: { color: '#a3b8d1' }
        },
        yaxis: {
            labels: {
                style: { colors: '#1a2d4a', fontSize: '12px', fontWeight: 700 },
                maxWidth: 220
            }
        },
        dataLabels: {
            enabled: true,
            offsetX: 30,
            style: { colors: ['#1a2d4a'], fontFamily: 'Arial', fontSize: '12px', fontWeight: 700 },
            formatter: v => v.toFixed(1) + '%'
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3, xaxis: { lines: { show: true } } },
        legend: { show: false },
        tooltip: {
            y: { formatter: (v, opts) => {
                const r = rows[opts.dataPointIndex];
                return `Plan: ${r.plan_total} · Producido: ${r.prod_total} · PA: ${v.toFixed(2)}%`;
            } }
        },
        states: {
            active: { allowMultipleDataPointsSelection: false, filter: { type: 'none' } },
            hover:  { filter: { type: 'lighten', value: 0.15 } }
        },
        annotations: {
            xaxis: [{
                x: 75,
                borderColor: '#10b981', borderWidth: 2, strokeDashArray: 6,
                label: {
                    text: 'Objetivo 75%', borderColor: '#10b981',
                    style: { color: '#ffffff', background: '#10b981', fontSize: '11px', fontWeight: 700 }
                }
            }]
        }
    };
    if (chartMaquina) chartMaquina.destroy();
    chartMaquina = new ApexCharts(cont, options);
    chartMaquina.render();
}

// ───── Click handlers (cross-filter QW-style) ────────────────────────
function onSeccionClick(sec) {
    // Toggle: clic en la sección ya seleccionada → desactiva el filtro
    if (_selSeccion === sec) _selSeccion = '';
    else _selSeccion = sec;
    refreshActiveFilterBar();
    cargarTodo();
}

function onFechaClick(fecha) {
    if (_selFecha === fecha) _selFecha = '';
    else _selFecha = fecha;
    refreshActiveFilterBar();
    cargarTodo();
}

function refreshActiveFilterBar() {
    const bar   = $('#pa-active-filter');
    const chips = $('#pa-active-filter-chips');
    const m2Info = $('#m2-info');
    const m1Title = $('#m1-title');
    if (!bar) return;

    const partes = [];
    if (_selSeccion) partes.push(`<span class="pa-active-filter-chip">SECCIÓN · ${_selSeccion}</span>`);
    if (_selFecha) {
        const [y, m, d] = _selFecha.split('-');
        partes.push(`<span class="pa-active-filter-chip">FECHA · ${d}/${m}/${y}</span>`);
    }
    if (_selMaquina) partes.push(`<span class="pa-active-filter-chip">MÁQUINA · ${_selMaquina}</span>`);
    if (partes.length) {
        chips.innerHTML = partes.join('');
        bar.style.display = '';
    } else {
        bar.style.display = 'none';
    }
    // Reflejar selección en cabeceras
    if (m1Title) {
        const txtBase = 'Plan Attainment Global';
        const suf = _selSeccion ? ' · ' + _selSeccion : '';
        m1Title.firstChild.nodeValue = txtBase + suf;
    }
    if (m2Info) {
        m2Info.textContent = _selSeccion ? `Filtrado · ${_selSeccion}` : 'VARILLAS + TROQUELADOS';
    }
}

function onClearFilter() {
    _selSeccion = '';
    _selFecha   = '';
    _selMaquina = '';
    refreshActiveFilterBar();
    cargarTodo();
}

// ───── Loaders ───────────────────────────────────────────────────────
function efectivaFiltroFechas(f) {
    // Si hay fecha clicada en evolución, la usamos como fecha del día.
    // Si no, se respeta la fecha seleccionada en el header.
    const fechaDia = _selFecha || f.fecha;
    return { fechaDia, turno: f.turno };
}

async function cargarGauge() {
    const f = getFiltrosActuales();
    const { fechaDia, turno } = efectivaFiltroFechas(f);
    const data = await apiFetch('plan_attainment.php', {
        fecha: fechaDia, turno, seccion: _selSeccion
    });
    _gaugeMeta = data.meta || null;
    renderGauge(data.plan_attainment);
    $('#m-disp').textContent = data.disponibilidad.toFixed(1) + '%';
    $('#m-rend').textContent = data.rendimiento.toFixed(1) + '%';
    $('#m-cal').textContent  = data.calidad.toFixed(1) + '%';
    $('#m-oee').textContent  = data.oee.toFixed(1) + '%';
}

async function cargarSeccion() {
    const f = getFiltrosActuales();
    const { fechaDia, turno } = efectivaFiltroFechas(f);
    // Importante: NO mandamos seccion aquí, queremos seguir viendo las 2 barras
    // (con la seleccionada destacada) para poder cambiar/quitar el filtro.
    const data = await apiFetch('por_seccion.php', { fecha: fechaDia, turno });
    renderSeccion(data.rows || []);
}

async function cargarEvolucion() {
    const f = getFiltrosActuales();
    // La evolución mantiene siempre los últimos 7 días desde la fecha_hasta
    // del header (no la fecha clicada — eso es solo para el día puntual).
    const dHasta = new Date(f.fecha_hasta);
    const dDesde = new Date(dHasta);
    dDesde.setDate(dDesde.getDate() - 6);
    const data = await apiFetch('evolucion.php', {
        fecha_desde: formatFecha(dDesde),
        fecha_hasta: formatFecha(dHasta),
        turno:       f.turno,
        seccion:     _selSeccion,
    });
    renderEvolucion(data.rows || []);
}

async function cargarMaquinas() {
    const f = getFiltrosActuales();
    const { fechaDia, turno } = efectivaFiltroFechas(f);
    const data = await apiFetch('por_maquina.php', { fecha: fechaDia, turno });
    let rows = data.rows || [];
    // Filtrado por sección (cliente — la API devuelve seccion en cada fila)
    if (_selSeccion) {
        rows = rows.filter(r => (r.seccion || '').toUpperCase() === _selSeccion);
    }
    _maquinasRows = rows;
    // Auto-descarte ANTES de renderizar: si la máquina seleccionada ya no
    // aparece, limpiamos para que renderMaquinas no atenúe todas las barras.
    // Módulo 5 no se oculta — cargarDetalle (llamado después en cargarTodo)
    // lo refresca con el scope global/sección.
    if (_selMaquina && !rows.some(r => r.maquina === _selMaquina)) {
        _selMaquina = '';
        refreshActiveFilterBar();
    }
    renderMaquinas(rows);
    const info = $('#m4-info');
    if (info) info.textContent = _selSeccion ? `Filtrado · ${_selSeccion}` : 'VARILLAS + TROQUELADOS';
}

// ───── Helper: escape HTML (si common.js no lo provee) ───────────────
// (Si common.js ya define escapeHTML, este bloque puede eliminarse.)
function escapeHTML(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function renderDetalle(rows, totales, maquina) {
    const cont = $('#detalle-articulos');
    if (!rows || !rows.length) {
        cont.innerHTML = '<div class="pa-detalle-empty">Sin datos para ' + escapeHTML(maquina || '') + ' en este turno.</div>';
        return;
    }
    const fila = r => {
        const pa = parseFloat(r.plan_attainment);
        const color = semColor(pa);
        const pct = Math.min(100, Math.max(0, pa));
        return `
            <tr>
                <td>${escapeHTML(r.cod_articulo)}</td>
                <td class="num">${Number(r.plan).toLocaleString('es-ES')}</td>
                <td class="num">${Number(r.prod).toLocaleString('es-ES')}</td>
                <td class="num">${Number(r.attain).toLocaleString('es-ES')}</td>
                <td>
                    <span class="pa-bar"><span class="pa-bar-fill" style="width:${pct}%;background:${color}"></span></span>
                    ${pa.toFixed(1)}%
                </td>
            </tr>`;
    };
    const tot = totales || {};
    const totPa = parseFloat(tot.plan_attainment || 0);
    cont.innerHTML = `
        <table class="pa-detalle-table">
            <thead>
                <tr>
                    <th>Artículo</th>
                    <th style="text-align:right">Plan</th>
                    <th style="text-align:right">Producido</th>
                    <th style="text-align:right">Attain</th>
                    <th>% Plan Attainment</th>
                </tr>
            </thead>
            <tbody>${rows.map(fila).join('')}</tbody>
            <tfoot>
                <tr>
                    <td>TOTAL · ${escapeHTML(maquina || '')}</td>
                    <td class="num">${Number(tot.plan || 0).toLocaleString('es-ES')}</td>
                    <td class="num">${Number(tot.prod || 0).toLocaleString('es-ES')}</td>
                    <td class="num">${Number(tot.attain || 0).toLocaleString('es-ES')}</td>
                    <td>${totPa.toFixed(2)}%</td>
                </tr>
            </tfoot>
        </table>
    `;
}

async function cargarDetalle() {
    const f = getFiltrosActuales();
    const { fechaDia, turno } = efectivaFiltroFechas(f);
    // Scope: máquina > sección > global
    const params = { fecha: fechaDia, turno };
    let scope;
    if (_selMaquina) {
        params.maquina = _selMaquina;
        scope = _selMaquina;
    } else if (_selSeccion) {
        params.seccion = _selSeccion;
        scope = _selSeccion;
    } else {
        scope = 'GLOBAL';
    }
    try {
        const data = await apiFetch('por_articulo_maquina.php', params);
        renderDetalle(data.rows || [], data.totales || {}, scope);
        const m5info = $('#m5-info');
        if (m5info) m5info.textContent = scope;
    } catch (e) {
        const cont = $('#detalle-articulos');
        if (cont) cont.innerHTML = '<div class="pa-detalle-empty">Sin detalle disponible: ' + escapeHTML(e.message || '') + '</div>';
        const m5info = $('#m5-info');
        if (m5info) m5info.textContent = scope;
    }
}

async function cargarTodo() {
    showLoader(true);
    try {
        // Paralelo para los 4 módulos superiores
        await Promise.all([cargarGauge(), cargarSeccion(), cargarEvolucion(), cargarMaquinas()]);
        // El detalle (módulo 5) corre después porque cargarMaquinas puede haber
        // hecho auto-descarte sobre _selMaquina y cargarDetalle depende de él.
        await cargarDetalle();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

// ───── Init ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initFiltros(() => {
        // Al cambiar fecha/turno del header se limpian TODOS los drill-downs.
        // El cross-filter es opcional desde ese estado base.
        _selSeccion = '';
        _selFecha   = '';
        _selMaquina = '';
        refreshActiveFilterBar();
        cargarTodo();
    });
    attachInfoIcon('#info-icon', () => _gaugeMeta);
    $('#pa-clear-filter')?.addEventListener('click', onClearFilter);
    refreshActiveFilterBar();
    cargarTodo();
});
