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
    const chips = $('#pa-active-filter-chips');
    const clearBtn = $('#pa-clear-filter');
    const m2Info = $('#m2-info');
    const m1Title = $('#m1-title');
    if (!chips) return;

    const chip = (kind, label, data) =>
        `<span class="pa-active-filter-chip">${kind} · ${escapeHTML(label)}<button type="button" class="pa-chip-x" data-clear="${data}" aria-label="Quitar filtro">×</button></span>`;

    const partes = [];
    if (_selSeccion) partes.push(chip('SECCIÓN', _selSeccion, 'seccion'));
    if (_selFecha) {
        const [y, m, d] = _selFecha.split('-');
        partes.push(chip('FECHA', `${d}/${m}/${y}`, 'fecha'));
    }
    if (_selMaquina) partes.push(chip('MÁQUINA', _selMaquina, 'maquina'));

    chips.innerHTML = partes.join('');
    if (clearBtn) clearBtn.style.display = partes.length ? '' : 'none';

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

function onChipClear(kind) {
    if (kind === 'seccion') _selSeccion = '';
    else if (kind === 'fecha') _selFecha = '';
    else if (kind === 'maquina') _selMaquina = '';
    refreshActiveFilterBar();
    cargarTodo();
}

function onClearFilter() {
    _selSeccion = '';
    _selFecha   = '';
    _selMaquina = '';
    refreshActiveFilterBar();
    cargarTodo();
}

// ───── Filtros propios de plan_attainment (rango fechas + multi-turno) ─
const PA_FILTROS_KEY = 'pa_filtros_v2';

// Formateo local YYYY-MM-DD (evita el shift de timezone de toISOString)
function _paFmtDate(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

// Calcula desde/hasta para un atajo: 'ayer' | 'semana' | 'mes'
function paPresetRange(name) {
    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    if (name === 'ayer') {
        const d = new Date(hoy); d.setDate(d.getDate() - 1);
        const s = _paFmtDate(d);
        return { desde: s, hasta: s };
    }
    if (name === 'semana') {
        // Semana anterior completa, lunes→domingo (week starts Monday).
        const dow = hoy.getDay();                         // 0 = domingo
        const diasDesdeLunes = (dow === 0) ? 6 : dow - 1; // lunes de ESTA semana
        const lunesEsta = new Date(hoy);
        lunesEsta.setDate(hoy.getDate() - diasDesdeLunes);
        const lunesAnt = new Date(lunesEsta); lunesAnt.setDate(lunesEsta.getDate() - 7);
        const domAnt   = new Date(lunesEsta); domAnt.setDate(lunesEsta.getDate() - 1);
        return { desde: _paFmtDate(lunesAnt), hasta: _paFmtDate(domAnt) };
    }
    if (name === 'mes') {
        // Día 1 → último día del mes natural anterior.
        const primero = new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1);
        const ultimo  = new Date(hoy.getFullYear(), hoy.getMonth(), 0);
        return { desde: _paFmtDate(primero), hasta: _paFmtDate(ultimo) };
    }
    return null;
}

function paSavedFiltros() {
    try {
        const raw = localStorage.getItem(PA_FILTROS_KEY);
        if (!raw) return null;
        const o = JSON.parse(raw);
        if (typeof o !== 'object' || !o) return null;
        return o;
    } catch { return null; }
}

function paPersist(f) {
    try { localStorage.setItem(PA_FILTROS_KEY, JSON.stringify(f)); } catch {}
}

function getPaFiltros() {
    const desde = $('#pa-f-desde')?.value || '';
    const hasta = $('#pa-f-hasta')?.value || desde;
    const turnos = Array.from(document.querySelectorAll('.pa-turno-btn.active'))
        .map(b => b.dataset.turno)
        .filter(t => ['M','T','N','C'].includes(t));
    return { fecha_desde: desde, fecha_hasta: hasta || desde, turnos };
}

function paApiParams() {
    const f = getPaFiltros();
    // Si el usuario clicó una fecha en evolución, ese drill-down se impone
    // como día único sobre el rango.
    const desde = _selFecha || f.fecha_desde;
    const hasta = _selFecha || f.fecha_hasta;
    return {
        fecha_desde: desde,
        fecha_hasta: hasta,
        turnos:      f.turnos.join(','),
    };
}

function initPaFiltros(onChange) {
    const ayer = new Date();
    ayer.setDate(ayer.getDate() - 1);
    const ayerStr = _paFmtDate(ayer);

    const saved = paSavedFiltros();
    const desde = saved?.fecha_desde || ayerStr;
    const hasta = saved?.fecha_hasta || desde;
    const turnos = Array.isArray(saved?.turnos) && saved.turnos.length
        ? saved.turnos.filter(t => ['M','T','N','C'].includes(t))
        : ['M','T','N'];

    const fDesde = $('#pa-f-desde');
    const fHasta = $('#pa-f-hasta');
    if (fDesde) fDesde.value = desde;
    if (fHasta) fHasta.value = hasta;
    document.querySelectorAll('.pa-turno-btn').forEach(b => {
        if (turnos.includes(b.dataset.turno)) b.classList.add('active');
        else b.classList.remove('active');
    });

    const onAnyChange = () => {
        const f = getPaFiltros();
        // Coerción mínima de fechas: si hasta < desde, igualar hasta = desde
        if (f.fecha_hasta && f.fecha_desde && f.fecha_hasta < f.fecha_desde) {
            if (fHasta) fHasta.value = f.fecha_desde;
        }
        paPersist(getPaFiltros());
        if (onChange) onChange(getPaFiltros());
    };

    if (fDesde) fDesde.addEventListener('change', onAnyChange);
    if (fHasta) fHasta.addEventListener('change', onAnyChange);
    document.querySelectorAll('.pa-turno-btn').forEach(b => {
        b.addEventListener('click', () => {
            const others = Array.from(document.querySelectorAll('.pa-turno-btn.active'));
            // No permitir dejar 0 turnos activos
            if (b.classList.contains('active') && others.length <= 1) return;
            b.classList.toggle('active');
            onAnyChange();
        });
    });
    // Atajos rápidos: ayer / semana ant. / mes ant.
    document.querySelectorAll('.pa-preset-btn').forEach(b => {
        b.addEventListener('click', () => {
            const r = paPresetRange(b.dataset.preset);
            if (!r) return;
            if (fDesde) fDesde.value = r.desde;
            if (fHasta) fHasta.value = r.hasta;
            onAnyChange();
        });
    });
}

// ───── Loaders ───────────────────────────────────────────────────────

async function cargarGauge() {
    const p = paApiParams();
    const data = await apiFetch('plan_attainment.php', { ...p, seccion: _selSeccion });
    _gaugeMeta = data.meta || null;
    renderGauge(data.plan_attainment);
    $('#m-disp').textContent = data.disponibilidad.toFixed(1) + '%';
    $('#m-rend').textContent = data.rendimiento.toFixed(1) + '%';
    $('#m-cal').textContent  = data.calidad.toFixed(1) + '%';
    $('#m-oee').textContent  = data.oee.toFixed(1) + '%';
}

async function cargarSeccion() {
    const p = paApiParams();
    // Importante: NO mandamos seccion aquí, queremos seguir viendo las 2 barras
    // (con la seleccionada destacada) para poder cambiar/quitar el filtro.
    const data = await apiFetch('por_seccion.php', p);
    renderSeccion(data.rows || []);
}

async function cargarEvolucion() {
    // Evolución usa SIEMPRE el rango configurado en la cabecera
    // (ignora el drill-down _selFecha, que solo aplica a los módulos diarios).
    const f = getPaFiltros();
    const data = await apiFetch('evolucion.php', {
        fecha_desde: f.fecha_desde,
        fecha_hasta: f.fecha_hasta,
        turnos:      f.turnos.join(','),
        seccion:     _selSeccion,
    });
    renderEvolucion(data.rows || []);
}

async function cargarMaquinas() {
    const p = paApiParams();
    const data = await apiFetch('por_maquina.php', p);
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

// ───── Render detalle horario (grid Plan/Prod estilo grid.php) ──────
const _TURNO_LABEL_DET = { M: 'MAÑANA', T: 'TARDE', N: 'NOCHE', C: 'CENTRAL' };

// Tabla agregada por artículo (cuando hay rango o multi-turno y no podemos
// usar el grid horario por hora).
function renderDetalleAgregado(rows, totales, scope) {
    const cont = $('#detalle-articulos');
    if (!rows || !rows.length) {
        cont.innerHTML = '<div class="pa-detalle-empty">Sin datos para ' + escapeHTML(scope || '') + ' en el periodo seleccionado.</div>';
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
                    <td>TOTAL · ${escapeHTML(scope || '')}</td>
                    <td class="num">${Number(tot.plan || 0).toLocaleString('es-ES')}</td>
                    <td class="num">${Number(tot.prod || 0).toLocaleString('es-ES')}</td>
                    <td class="num">${Number(tot.attain || 0).toLocaleString('es-ES')}</td>
                    <td>${totPa.toFixed(2)}%</td>
                </tr>
            </tfoot>
        </table>`;
}

function renderDetalle(horas, filas, fecha, turno, scope) {
    const cont = $('#detalle-articulos');
    if (!horas || !horas.length || !filas || !filas.length) {
        cont.innerHTML = '<div class="pa-detalle-empty">Sin datos para ' + escapeHTML(scope || '') + ' en este turno.</div>';
        return;
    }
    const fechaLabel = (typeof formatFechaCorta === 'function') ? formatFechaCorta(fecha) : fecha;
    const turnoLabel = _TURNO_LABEL_DET[turno] || turno;
    const nHoras = horas.length;
    const fmt = new Intl.NumberFormat('es-ES');

    const renderCell = (val, pct, extraCls = '') => {
        if (val === undefined || val === null || val === 0) {
            return `<td class="cell-data cell-empty ${extraCls}"></td>`;
        }
        const sem = pct === null ? 'cell-empty' : semClass3(pct);
        return `<td class="cell-data ${sem} ${extraCls}">${fmt.format(val)}</td>`;
    };

    // Agrupar por máquina para rowspan
    const grupos = new Map();
    filas.forEach(f => {
        if (!grupos.has(f.maquina)) grupos.set(f.maquina, []);
        grupos.get(f.maquina).push(f);
    });

    let thead = `
        <tr class="header-meta">
            <th rowspan="3" class="col-maquina"><div class="col-hdr-title">Máquina</div></th>
            <th rowspan="3" class="col-articulo"><div class="col-hdr-title">Cód. Artículo</div></th>
            <th rowspan="3" class="col-label">Hor</th>
            <th colspan="${nHoras}" class="header-fch"><span class="meta-dot"></span> Fch: ${escapeHTML(fechaLabel)}</th>
            <th rowspan="3" class="col-total">Total</th>
        </tr>
        <tr class="header-meta">
            <th colspan="${nHoras}" class="header-tur">Tur: ${escapeHTML(turnoLabel)}</th>
        </tr>
        <tr class="header-hour">`;
    horas.forEach(h => {
        thead += `<th><div class="hour-date">${escapeHTML(h.fecha || '')}</div><div class="hour-time">${escapeHTML(h.label)}</div></th>`;
    });
    thead += '</tr>';

    let tbody = '';
    grupos.forEach((items, maquina) => {
        items.forEach((fila, idx) => {
            // Fila PLAN
            let rowPlan = '<tr class="row-plan">';
            if (idx === 0) {
                rowPlan += `<td class="col-maquina" rowspan="${items.length * 2}">${escapeHTML(maquina)}</td>`;
            }
            rowPlan += `<td class="col-articulo" rowspan="2">${escapeHTML(fila.cod_articulo)}</td>`;
            rowPlan += `<td class="col-label label-plan">Plan</td>`;

            let totalPlan = 0, totalProd = 0;
            const cellsPlan = [];
            const cellsProd = [];
            horas.forEach(h => {
                const plan = fila.plan[h.hora];
                const prod = fila.prod[h.hora];
                const hasPlan = plan !== undefined && plan !== null;
                const hasProd = prod !== undefined && prod !== null;
                const pct = (plan > 0 && hasProd) ? (prod / plan) * 100 : null;
                cellsPlan.push(renderCell(hasPlan ? plan : null, pct));
                cellsProd.push(renderCell(hasProd ? prod : null, pct));
                if (hasPlan) totalPlan += plan;
                if (hasProd) totalProd += prod;
            });
            const totalPct = totalPlan > 0 ? (totalProd / totalPlan) * 100 : null;
            rowPlan += cellsPlan.join('');
            rowPlan += renderCell(totalPlan || null, totalPct, 'cell-total');
            rowPlan += '</tr>';

            // Fila PROD
            let rowProd = '<tr class="row-prod">';
            rowProd += `<td class="col-label label-prod">Prod</td>`;
            rowProd += cellsProd.join('');
            rowProd += renderCell(totalProd || null, totalPct, 'cell-total');
            rowProd += '</tr>';

            tbody += rowPlan + rowProd;
        });
    });

    cont.innerHTML = `
        <div class="grid-scroll">
            <table class="grid-table">
                <thead>${thead}</thead>
                <tbody>${tbody}</tbody>
            </table>
        </div>`;
}

async function cargarDetalle() {
    const cont = $('#detalle-articulos');
    const m5info = $('#m5-info');
    const f = getPaFiltros();

    let scope;
    if (_selMaquina) scope = _selMaquina;
    else if (_selSeccion) scope = _selSeccion;
    else scope = 'GLOBAL';
    if (m5info) m5info.textContent = scope;

    // Modo grid horario: requiere un día único y un solo turno activo.
    // El drill-down de evolución impone día único aunque el rango sea mayor.
    const dia = _selFecha || (f.fecha_desde === f.fecha_hasta ? f.fecha_desde : null);
    const turno = (f.turnos.length === 1) ? f.turnos[0] : null;
    const modoHorario = !!(dia && turno);

    try {
        if (modoHorario) {
            const data = await apiFetch('grid.php', { fecha: dia, turno });
            const seccionByMaq = {};
            _maquinasRows.forEach(r => {
                if (r.seccion) seccionByMaq[r.maquina] = String(r.seccion).toUpperCase();
            });
            const filas = (data.filas || []).filter(fila => {
                if (_selMaquina && fila.maquina !== _selMaquina) return false;
                if (_selSeccion && (seccionByMaq[fila.maquina] || '') !== _selSeccion) return false;
                return true;
            });
            renderDetalle(data.horas || [], filas, data.fecha, data.turno, scope);
        } else {
            // Modo agregado: rango o multi-turno. Pedimos al endpoint que
            // ya sabe responder con o sin máquina/sección.
            const p = paApiParams();
            const params = { ...p };
            if (_selMaquina) params.maquina = _selMaquina;
            else if (_selSeccion) params.seccion = _selSeccion;
            const data = await apiFetch('por_articulo_maquina.php', params);
            renderDetalleAgregado(data.rows || [], data.totales || {}, scope);
        }
    } catch (e) {
        if (cont) cont.innerHTML = '<div class="pa-detalle-empty">Sin detalle disponible: ' + escapeHTML(e.message || '') + '</div>';
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
    initPaFiltros(() => {
        // Al cambiar rango/turnos de la cabecera propia se limpian TODOS los
        // drill-downs. El cross-filter es opcional desde ese estado base.
        _selSeccion = '';
        _selFecha   = '';
        _selMaquina = '';
        refreshActiveFilterBar();
        cargarTodo();
    });
    attachInfoIcon('#info-icon', () => _gaugeMeta);
    $('#pa-clear-filter')?.addEventListener('click', onClearFilter);
    // Delegación: cualquier ✕ dentro de chips
    $('#pa-active-filter-chips')?.addEventListener('click', (e) => {
        const btn = e.target.closest('.pa-chip-x');
        if (!btn) return;
        onChipClear(btn.dataset.clear);
    });
    refreshActiveFilterBar();
    cargarTodo();
});
