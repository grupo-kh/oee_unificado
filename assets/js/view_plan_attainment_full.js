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
    // El usuario pidió ver el detalle de esta máquina explícitamente:
    // forzamos la carga (saltándonos el lazy del módulo 5).
    cargarDetalleConAbort();
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
        const txtBase = 'Cumplimiento Global Producción';
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

    let _paChangeDebounce = null;
    const onAnyChange = () => {
        const f = getPaFiltros();
        // Coerción mínima de fechas: si hasta < desde, igualar hasta = desde
        if (f.fecha_hasta && f.fecha_desde && f.fecha_hasta < f.fecha_desde) {
            if (fHasta) fHasta.value = f.fecha_desde;
        }
        paPersist(getPaFiltros());
        // Debounce: si el usuario sigue tocando filtros rápido, esperamos a
        // que se quieten antes de disparar la cascada de fetches.
        clearTimeout(_paChangeDebounce);
        _paChangeDebounce = setTimeout(() => {
            if (onChange) onChange(getPaFiltros());
        }, 220);
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

// ───── Cache cliente con TTL ─────────────────────────────────────────
// Memoriza las respuestas por (endpoint + params) para que alternar filtros
// ya consultados sea instantáneo. El botón "Refrescar" vacía todo y fuerza
// un re-fetch real. Datos del día actual: TTL 30 s; datos completamente
// del pasado: TTL 5 min (no van a cambiar).
const _paCache = new Map();
let _paForceNextFetch = false;

function _paCacheKey(endpoint, params) {
    const ordered = {};
    Object.keys(params || {}).sort().forEach(k => { ordered[k] = params[k]; });
    return endpoint + '?' + JSON.stringify(ordered);
}
function _paCacheTTL(params) {
    const hoy = _paFmtDate(new Date());
    const hasta = (params && (params.fecha_hasta || params.fecha)) || '';
    if (!hasta || hasta >= hoy) return 30 * 1000;
    return 5 * 60 * 1000;
}
async function paApiFetch(endpoint, params, signal) {
    const key = _paCacheKey(endpoint, params);
    const now = Date.now();
    if (!_paForceNextFetch) {
        const hit = _paCache.get(key);
        if (hit && (now - hit.ts) < hit.ttl) return hit.data;
    }
    const data = await apiFetch(endpoint, params, signal);
    _paCache.set(key, { data, ts: now, ttl: _paCacheTTL(params) });
    return data;
}
function paInvalidarCache() {
    _paCache.clear();
    _paForceNextFetch = true;
}

// ───── Loaders ───────────────────────────────────────────────────────
// Optimización: en lugar de 4 fetches (gauge / seccion / evolucion / maquinas)
// hacemos UNA sola llamada al endpoint combinado pa_dashboard.php, que
// internamente comparte la cache in-memory de dayShiftDetail (en lugar de
// recalcular 4 veces lo mismo). El despacho de los 4 bloques se hace en
// pintarDashboard().

async function cargarDashboard(signal) {
    // Para el gauge usamos paApiParams() (respeta drill-down de fecha);
    // para evolución usamos siempre el rango completo de la cabecera porque
    // la línea temporal pierde sentido sobre un solo día.
    const p = paApiParams();
    const f = getPaFiltros();

    // Si hay drill-down de fecha → el dashboard se calcula para ese día
    // único; para evolución hacemos un fetch aparte con el rango completo
    // (pero sigue siendo solo 1 llamada extra, no 3).
    const dashboard = await paApiFetch('pa_dashboard.php',
        { ...p, seccion: _selSeccion }, signal);

    let evolucionRows = dashboard.evolucion || [];
    if (_selFecha) {
        // El dashboard se pidió para 1 día, pero la evolución necesita el
        // rango original; pedimos el bloque evolución aparte.
        const evo = await paApiFetch('pa_dashboard.php', {
            fecha_desde: f.fecha_desde,
            fecha_hasta: f.fecha_hasta,
            turnos:      f.turnos.join(','),
            seccion:     _selSeccion,
        }, signal);
        evolucionRows = evo.evolucion || [];
    }

    return { ...dashboard, evolucion: evolucionRows };
}

function pintarDashboard(data) {
    // 1) Gauge + 4 métricas OEE
    const g = data.gauge || {};
    _gaugeMeta = g.meta || null;
    renderGauge(g.plan_attainment ?? 0);
    $('#m-disp').textContent = (g.disponibilidad ?? 0).toFixed(1) + '%';
    $('#m-rend').textContent = (g.rendimiento   ?? 0).toFixed(1) + '%';
    $('#m-cal').textContent  = (g.calidad       ?? 0).toFixed(1) + '%';
    $('#m-oee').textContent  = (g.oee           ?? 0).toFixed(1) + '%';

    // 2) Por sección (NO filtramos client-side: queremos ver las 2 barras
    //    para poder cambiar/quitar el filtro)
    renderSeccion(data.por_seccion || []);

    // 3) Evolución
    renderEvolucion(data.evolucion || []);

    // 4) Por máquina (filtramos client-side por sección si procede)
    let rows = data.por_maquina || [];
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

// Construye el HTML de UN grid horario (una fecha + un turno). Devuelve
// string vacío si no hay datos relevantes (lo decide el caller filtrando).
function _buildDetalleHorarioHTML(horas, filas, fecha, turno) {
    if (!horas || !horas.length || !filas || !filas.length) return '';
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

            let rowProd = '<tr class="row-prod">';
            rowProd += `<td class="col-label label-prod">Prod</td>`;
            rowProd += cellsProd.join('');
            rowProd += renderCell(totalProd || null, totalPct, 'cell-total');
            rowProd += '</tr>';

            tbody += rowPlan + rowProd;
        });
    });

    return `
        <div class="grid-scroll" style="margin-bottom:16px">
            <table class="grid-table">
                <thead>${thead}</thead>
                <tbody>${tbody}</tbody>
            </table>
        </div>`;
}

// Enumera todas las combinaciones (fecha YYYY-MM-DD, turno) dentro de un rango.
function _enumeraDetalleCombos(fechaDesde, fechaHasta, turnos) {
    const combos = [];
    const d  = new Date(fechaDesde + 'T00:00:00');
    const fh = new Date(fechaHasta + 'T00:00:00');
    while (d <= fh) {
        const dStr = _paFmtDate(d);
        turnos.forEach(t => combos.push({ fecha: dStr, turno: t }));
        d.setDate(d.getDate() + 1);
    }
    return combos;
}

async function cargarDetalle(signal) {
    const cont = $('#detalle-articulos');
    const m5info = $('#m5-info');
    const f = getPaFiltros();
    _paDetalleLoaded = true;
    _paDetalleNeedsReload = false;

    let scope;
    if (_selMaquina) scope = _selMaquina;
    else if (_selSeccion) scope = _selSeccion;
    else scope = 'GLOBAL';
    if (m5info) m5info.textContent = scope;

    // Si hay drill-down de fecha en evolución, fuerza día único.
    const fechaDesde = _selFecha || f.fecha_desde;
    const fechaHasta = _selFecha || f.fecha_hasta;

    const combos = _enumeraDetalleCombos(fechaDesde, fechaHasta, f.turnos);

    // Cap razonable para evitar disparar 100+ fetches con "Mes ant." × 3 turnos.
    const MAX_COMBOS = 21;   // p.ej. 7 días × 3 turnos
    let aviso = '';
    let listaCombos = combos;
    if (combos.length > MAX_COMBOS) {
        aviso = `<div class="pa-detalle-empty" style="color:#b45309">El rango × turnos seleccionado genera ${combos.length} grids horarios. Mostrando los ${MAX_COMBOS} primeros (orden cronológico). Reduce el rango o el número de turnos para verlos todos.</div>`;
        listaCombos = combos.slice(0, MAX_COMBOS);
    }

    if (!listaCombos.length) {
        cont.innerHTML = '<div class="pa-detalle-empty">Selecciona al menos un turno y un rango válido.</div>';
        return;
    }

    cont.innerHTML = aviso + '<div class="pa-detalle-empty">Cargando desglose horario…</div>';

    try {
        const results = await Promise.all(
            listaCombos.map(c =>
                paApiFetch('grid.php', { fecha: c.fecha, turno: c.turno }, signal)
                    .then(d => ({ combo: c, data: d }))
                    .catch(e => ({ combo: c, error: e }))
            )
        );

        // Filtros cliente: sección (vía _maquinasRows) y máquina (match exacto).
        const seccionByMaq = {};
        _maquinasRows.forEach(r => {
            if (r.seccion) seccionByMaq[r.maquina] = String(r.seccion).toUpperCase();
        });
        const filtrarFilas = filas => filas.filter(fila => {
            if (_selMaquina && fila.maquina !== _selMaquina) return false;
            if (_selSeccion && (seccionByMaq[fila.maquina] || '') !== _selSeccion) return false;
            return true;
        });

        const secciones = [];
        results.forEach(({ combo, data, error }) => {
            if (error || !data) return;
            const filas = filtrarFilas(data.filas || []);
            // Salta turnos sin filas (típicamente fines de semana sin plan)
            if (!filas.length) return;
            secciones.push(_buildDetalleHorarioHTML(
                data.horas || [],
                filas,
                data.fecha || combo.fecha,
                data.turno || combo.turno
            ));
        });

        if (!secciones.length) {
            cont.innerHTML = aviso + '<div class="pa-detalle-empty">Sin datos para ' + escapeHTML(scope) + ' en el periodo seleccionado.</div>';
        } else {
            cont.innerHTML = aviso + secciones.join('');
        }
    } catch (e) {
        cont.innerHTML = '<div class="pa-detalle-empty">Sin detalle disponible: ' + escapeHTML(e.message || '') + '</div>';
    }
}

// ───── Estado de carga (abort + lazy módulo 5) ───────────────────────
let _paAbortTodo    = null;   // AbortController de los 4 módulos superiores
let _paAbortDetalle = null;   // AbortController del módulo 5 (independiente)
let _paDetalleLoaded     = false;  // ¿se ha cargado alguna vez con los filtros actuales?
let _paDetalleNeedsReload = true;  // ¿hay que recargar la próxima vez que entre en viewport?
let _paDetalleObserver = null;

function paShowLoading(yes) {
    const el = $('#pa-filterbar-loading');
    if (el) el.style.display = yes ? '' : 'none';
}

function resetDetallePerezoso() {
    const cont = $('#detalle-articulos');
    const m5info = $('#m5-info');
    if (!cont) return;
    _paDetalleLoaded = false;
    _paDetalleNeedsReload = true;
    if (m5info) m5info.textContent = '—';
    cont.innerHTML = `
        <div class="pa-detalle-empty pa-detalle-cta">
            <p>El desglose horario plan vs producido puede ser pesado en rangos amplios.<br>
               Cárgalo cuando lo necesites (o haz clic en una máquina para ver solo la suya).</p>
            <button type="button" id="pa-detalle-load" class="pa-detalle-load-btn">Cargar desglose</button>
        </div>`;
    $('#pa-detalle-load')?.addEventListener('click', () => cargarDetalleConAbort());

    // Si el módulo 5 ya está visible (el usuario llegó haciendo scroll antes
    // de cambiar el filtro), disparamos la carga directamente — el
    // IntersectionObserver no re-emite mientras el elemento sigue en viewport.
    const m5 = document.getElementById('pa-module-5');
    if (m5) {
        const r = m5.getBoundingClientRect();
        const vh = window.innerHeight || document.documentElement.clientHeight;
        const visible = r.top < vh && r.bottom > 0;
        if (visible) {
            _paDetalleNeedsReload = false;
            cargarDetalleConAbort();
        }
    }
}

async function cargarDetalleConAbort() {
    if (_paAbortDetalle) _paAbortDetalle.abort();
    _paAbortDetalle = new AbortController();
    const sig = _paAbortDetalle.signal;
    paShowLoading(true);
    try {
        await cargarDetalle(sig);
    } catch (e) {
        if (e.name !== 'AbortError') showToast('Error: ' + e.message, 'error');
    } finally {
        if (!sig.aborted) paShowLoading(false);
    }
}

function setupDetalleAutoload() {
    if (_paDetalleObserver) _paDetalleObserver.disconnect();
    const m5 = document.getElementById('pa-module-5');
    if (!m5 || !('IntersectionObserver' in window)) return;
    _paDetalleObserver = new IntersectionObserver(entries => {
        for (const e of entries) {
            if (e.isIntersecting && _paDetalleNeedsReload) {
                _paDetalleNeedsReload = false;  // evita re-disparos rápidos
                cargarDetalleConAbort();
            }
        }
    }, { rootMargin: '200px' });
    _paDetalleObserver.observe(m5);
}

async function cargarTodo() {
    // 1) Abortar cualquier petición en vuelo (la previa quedó obsoleta).
    if (_paAbortTodo)    _paAbortTodo.abort();
    if (_paAbortDetalle) _paAbortDetalle.abort();
    _paAbortTodo = new AbortController();
    const sig = _paAbortTodo.signal;

    paShowLoading(true);

    // 2) UNA sola petición al endpoint combinado pa_dashboard.php — devuelve
    //    los 4 bloques (gauge/seccion/evolucion/maquinas) tras una única
    //    iteración del rango (con memoización in-process de dayShiftDetail).
    try {
        const data = await cargarDashboard(sig);
        if (sig.aborted) return;
        pintarDashboard(data);
    } catch (e) {
        if (sig.aborted || e?.name === 'AbortError') return;
        showToast('Error: ' + (e?.message || e), 'error');
    }

    paShowLoading(false);

    // 3) Una vez consumido el ciclo, los siguientes fetches (incluido el
    //    módulo 5) vuelven a comportamiento normal: usar cache si vale.
    _paForceNextFetch = false;

    // 4) Módulo 5: vuelve a estado perezoso. Si está en pantalla, el
    //    IntersectionObserver disparará la recarga automáticamente.
    resetDetallePerezoso();
}

// Acción del botón "Refrescar": vacía cache y recarga todos los módulos.
// Si el módulo 5 ya estaba cargado y visible, se recarga también.
function onRefrescarClick() {
    paInvalidarCache();
    const m5 = document.getElementById('pa-module-5');
    const detalleEstabaCargado = !!_paDetalleLoaded;
    cargarTodo().then(() => {
        if (detalleEstabaCargado) {
            cargarDetalleConAbort();
        }
    });
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
    $('#pa-refresh-btn')?.addEventListener('click', onRefrescarClick);
    // Delegación: cualquier ✕ dentro de chips
    $('#pa-active-filter-chips')?.addEventListener('click', (e) => {
        const btn = e.target.closest('.pa-chip-x');
        if (!btn) return;
        onChipClear(btn.dataset.clear);
    });
    refreshActiveFilterBar();
    setupDetalleAutoload();   // IntersectionObserver para el módulo 5
    cargarTodo();
});
