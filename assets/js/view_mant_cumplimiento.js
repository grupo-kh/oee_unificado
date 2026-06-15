/* Vista Mantenimiento · Cumplimiento Preventivo */

let gaugeCumpl = null;
let chartPer  = null;
let chartMeses = null;
let chartMesesPer = null;
let _mesActivo = null;             // mes seleccionado en el drill-down de tareas (YYYY-MM)
let _mesActivoOrigen = null;       // 'global' | 'periodicidad' (de qué chart vino el clic)
let _mantCodMaquina = '';
let _fDesde = '';
let _fHasta = '';
let _drillPer = null;            // periodicidad actualmente abierta en el drill-down
let _periodicidadesSoportadas = [];
let _editTask = null;            // tarea actualmente en edición (modal)
const PER_DAYS = {
    DIARIO:1, SEMANAL:7, QUINCENAL:15, MENSUAL:30, BIMESTRAL:60,
    TRIMESTRAL:90, CUATRIMESTRAL:120, SEMESTRAL:180, ANUAL:365
};

function getQueryParam(name) {
    const u = new URLSearchParams(window.location.search);
    return u.get(name);
}
function updateUrlParams(updates) {
    const u = new URL(window.location.href);
    Object.entries(updates).forEach(([k, v]) => {
        if (v) u.searchParams.set(k, v);
        else   u.searchParams.delete(k);
    });
    history.replaceState(null, '', u.pathname + (u.search || '') + u.hash);
}

function renderGauge(value, label) {
    const v = Math.max(0, Math.min(100, parseFloat(value) || 0));
    const options = {
        chart: { type: 'radialBar', height: 340, background: 'transparent' },
        series: [v],
        plotOptions: {
            radialBar: {
                startAngle: -135, endAngle: 135,
                hollow: { size: '62%' },
                track: { background: '#e8eef5', strokeWidth: '100%' },
                dataLabels: {
                    name: { show: true, offsetY: -8, color: '#5b8cc7', fontSize: '13px', fontWeight: 600 },
                    value: {
                        show: true, offsetY: 8,
                        color: '#1a2d4a', fontSize: '40px', fontWeight: 700,
                        formatter: () => v.toFixed(2) + '%'
                    }
                }
            }
        },
        fill: {
            type: 'gradient',
            gradient: {
                shade: 'light', type: 'horizontal',
                shadeIntensity: 0.4,
                gradientToColors: [semColor(Math.min(100, v + 10))],
                opacityFrom: 1, opacityTo: 1, stops: [0, 100]
            }
        },
        colors: [semColor(v)],
        labels: [label || 'Cumplimiento'],
        stroke: { lineCap: 'round' }
    };
    if (gaugeCumpl) gaugeCumpl.destroy();
    gaugeCumpl = new ApexCharts($('#gauge-cumpl'), options);
    gaugeCumpl.render();
}

function renderPeriodicidades(arr) {
    if (!arr || !arr.length) {
        const c = $('#chart-periodicidades');
        if (c) c.innerHTML = '<div class="drill-down-empty">Sin datos</div>';
        return;
    }
    const data = arr.map(p => ({
        x: p.periodicidad, y: parseFloat(p.cumplimiento),
        cumple: p.cumple, no_cumple: p.no_cumple, total: p.total
    }));
    const height = Math.max(220, Math.min(700, 36 * data.length + 100));
    const highlight = _drillPer;
    const strokeWidths = data.map(d => d.x === highlight ? 4 : 0);
    const options = {
        chart: {
            type: 'bar', height, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    const per = data[cfg.dataPointIndex] && data[cfg.dataPointIndex].x;
                    if (per) onPeriodicidadClickFromChart(per);
                }
            }
        },
        series: [{ name: 'Cumplimiento', data: data.map(d => d.y) }],
        xaxis: {
            categories: data.map(d => d.x), max: 100,
            labels: { style: { colors: '#2d4d7a', fontSize: '11px' }, formatter: v => v + '%' }
        },
        yaxis: { labels: { style: { colors: '#1a2d4a', fontSize: '13px', fontWeight: 700 } } },
        plotOptions: {
            bar: {
                horizontal: true, barHeight: '60%', borderRadius: 4,
                borderRadiusApplication: 'end', distributed: true
            }
        },
        states: { active: { filter: { type: 'none', value: 0 } } },
        stroke: { show: true, width: strokeWidths, colors: ['#1a2d4a'] },
        colors: data.map(d => semColor(d.y)),
        dataLabels: {
            enabled: true,
            style: { colors: ['#ffffff'], fontFamily: 'Arial', fontSize: '13px', fontWeight: 700 },
            formatter: v => v.toFixed(0) + '%'
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { show: false },
        tooltip: {
            custom: ({dataPointIndex}) => {
                const r = data[dataPointIndex];
                return `
                    <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:220px">
                        <div style="font-weight:700;margin-bottom:6px;font-size:13px">${r.x}</div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>En plazo</span><span>${r.cumple}</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Vencidas</span><span>${r.no_cumple}</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px"><span>Total</span><span>${r.total}</span></div>
                        <div style="border-top:1px solid #3a5576;margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:13px">
                            <span>Cumplimiento</span><span>${r.y.toFixed(1)}%</span>
                        </div>
                        <div style="color:#a3b8d1;font-size:10px;margin-top:6px;text-align:center">Clic para filtrar</div>
                    </div>
                `;
            }
        },
        annotations: {
            xaxis: [{
                x: 75, borderColor: '#10b981', strokeDashArray: 6,
                label: { text: 'Objetivo 75%', borderColor: '#10b981',
                    style: { color: '#fff', background: '#10b981', fontSize: '11px', fontWeight: 700 } }
            }]
        }
    };
    if (chartPer) chartPer.destroy();
    chartPer = new ApexCharts($('#chart-periodicidades'), options);
    chartPer.render();
}

function fmtMesEs(yyyymm) {
    if (!yyyymm) return '—';
    const [y, m] = yyyymm.split('-');
    const meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    return meses[parseInt(m, 10) - 1] + ' ' + y.slice(-2);
}

function colorPctMes(pct) {
    if (pct === null || pct === undefined) return '#a3b8d1';
    if (pct >= 100)  return '#10b981';   // verde (incluye recuperación)
    if (pct >= 95)   return '#3a6aa3';   // azul KH (en plazo)
    if (pct >= 85)   return '#f59e0b';   // naranja
    return '#c8102e';                    // rojo
}

function buildMesesChartOptions(arr, height, onClickMes) {
    const data = arr.map(m => ({
        x: fmtMesEs(m.mes),
        y: m.cumplimiento === null ? 0 : parseFloat(m.cumplimiento),
        raw: m
    }));
    const colors = data.map(d => colorPctMes(d.raw.cumplimiento));
    return {
        chart: {
            type: 'bar', height, background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    const mes = data[cfg.dataPointIndex] && data[cfg.dataPointIndex].raw.mes;
                    if (mes && typeof onClickMes === 'function') onClickMes(mes);
                }
            }
        },
        series: [{ name: 'Cumplimiento', data: data.map(d => d.y) }],
        plotOptions: {
            bar: { borderRadius: 4, borderRadiusApplication: 'end', columnWidth: '55%', distributed: true }
        },
        colors,
        states: { active: { filter: { type: 'none', value: 0 } } },
        legend: { show: false },
        dataLabels: {
            enabled: true,
            style: { colors: ['#1a2d4a'], fontFamily: 'Arial', fontSize: '12px', fontWeight: 700 },
            formatter: (_v, { dataPointIndex }) => {
                const r = data[dataPointIndex].raw;
                return r.cumplimiento === null ? '—' : r.cumplimiento.toFixed(1) + '%';
            },
            offsetY: -22
        },
        xaxis: {
            categories: data.map(d => d.x),
            labels: { style: { colors: '#1a2d4a', fontSize: '12px', fontWeight: 700 } }
        },
        yaxis: {
            min: 0,
            max: Math.max(110, Math.ceil(Math.max(...data.map(d => d.y)) / 5) * 5),
            labels: { style: { colors: '#2d4d7a', fontSize: '11px' }, formatter: v => v.toFixed(0) + '%' }
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        annotations: {
            yaxis: [
                { y: 100, borderColor: '#10b981', strokeDashArray: 4,
                  label: { text: 'objetivo 100 %', borderColor: '#10b981',
                           style: { color: '#fff', background: '#10b981', fontSize: '10px', fontWeight: 700 } } }
            ]
        },
        tooltip: {
            custom: ({ dataPointIndex }) => {
                const r = data[dataPointIndex].raw;
                const pct = r.cumplimiento === null ? '—' : r.cumplimiento.toFixed(2) + ' %';
                return `
                    <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:230px">
                        <div style="font-weight:700;margin-bottom:6px;font-size:13px">${fmtMesEs(r.mes)}</div>
                        <div style="display:flex;justify-content:space-between"><span>Programadas</span><span>${r.denom}</span></div>
                        <div style="display:flex;justify-content:space-between"><span>Realizadas</span><span>${r.completadas}</span></div>
                        <div style="display:flex;justify-content:space-between"><span>No realizadas</span><span>${r.no_realizadas}</span></div>
                        <div style="display:flex;justify-content:space-between"><span>Recuperadas</span><span>${r.recuperaciones}</span></div>
                        <div style="border-top:1px solid #3a5576;margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;font-weight:700">
                            <span>Cumplimiento</span><span>${pct}</span>
                        </div>
                    </div>`;
            }
        }
    };
}

function renderMeses(arr) {
    const c = $('#chart-cumpl-meses');
    if (!c) return;
    if (!arr || !arr.length) {
        if (chartMeses) { chartMeses.destroy(); chartMeses = null; }
        c.innerHTML = '<div class="drill-down-empty">Sin datos por mes</div>';
        return;
    }
    const opts = buildMesesChartOptions(arr, 260, mes => onMesClick(mes, 'global'));
    if (chartMeses) chartMeses.destroy();
    chartMeses = new ApexCharts(c, opts);
    chartMeses.render();
}

function renderMesesPer(arr) {
    const c = $('#chart-cumpl-meses-per');
    if (!c) return;
    if (!arr || !arr.length) {
        if (chartMesesPer) { chartMesesPer.destroy(); chartMesesPer = null; }
        c.innerHTML = '<div class="drill-down-empty">Sin datos para esta periodicidad</div>';
        return;
    }
    const opts = buildMesesChartOptions(arr, 260, mes => onMesClick(mes, 'periodicidad'));
    if (chartMesesPer) chartMesesPer.destroy();
    chartMesesPer = new ApexCharts(c, opts);
    chartMesesPer.render();
}

function populateMaquinas(maquinas, current) {
    const sel = $('#machine-selector');
    if (!sel) return;
    sel.innerHTML = '<option value="">— Todas —</option>';
    maquinas.forEach(m => {
        const o = document.createElement('option');
        o.value = m.cod_maquina_mant;
        o.textContent = m.desc_maquina + ' (' + m.cod_maquina_mant + ')';
        sel.appendChild(o);
    });
    sel.value = current || '';
}
async function cargarVista() {
    showLoader(true);
    try {
        const params = {};
        if (_fDesde)           params.fecha_desde      = _fDesde;
        if (_fHasta)           params.fecha_hasta      = _fHasta;
        if (_mantCodMaquina)   params.cod_maquina_mant = _mantCodMaquina;

        const d = await apiFetch('mant_cumplimiento.php', params);

        populateMaquinas(d.maquinas || [], _mantCodMaquina);

        const okMaq = !_mantCodMaquina || (d.maquinas || []).some(m => m.cod_maquina_mant === _mantCodMaquina);
        if (!okMaq) { _mantCodMaquina = ''; $('#machine-selector').value = ''; updateUrlParams({ cod_maquina_mant: '' }); }

        const btn = $('#filter-clear');
        if (btn) btn.style.display = _mantCodMaquina ? '' : 'none';

        const scopeBits = [];
        if (_mantCodMaquina) {
            const m = (d.maquinas || []).find(x => x.cod_maquina_mant === _mantCodMaquina);
            scopeBits.push('máq: ' + (m ? m.desc_maquina : _mantCodMaquina));
        }
        $('#header-scope').textContent = scopeBits.length ? '· ' + scopeBits.join(' · ') : '';

        // Sincroniza inputs de fecha con la respuesta (por si el backend
        // ha resuelto los defaults).
        if (d.global.fecha_desde) {
            _fDesde = d.global.fecha_desde;
            $('#f-desde').value = _fDesde;
        }
        if (d.global.fecha_hasta) {
            _fHasta = d.global.fecha_hasta;
            $('#f-hasta').value = _fHasta;
        }

        const fmtSpan = iso => {
            if (!iso) return '—';
            const [y, m, dd] = iso.split('-');
            return dd + '/' + m + '/' + y;
        };
        $('#info-line').textContent =
            fmtSpan(d.global.fecha_desde) + ' → ' + fmtSpan(d.global.fecha_hasta) + ' · ' +
            (d.global.realizadas ?? 0) + ' realizadas · ' +
            (d.global.previstas  ?? 0) + ' previstas · ' +
            (d.global.atrasadas  ?? 0) + ' atrasadas';
        $('#footer-actualizado').textContent = 'Fichero actualizado: ' + (d.fichero_actualizado || '—');

        const labelGauge = scopeBits.length ? scopeBits.join(' · ') : 'Cumplimiento';
        renderGauge(parseFloat(d.global.cumplimiento), labelGauge);
        renderMeses(d.meses_data || []);

        // Título dinámico del gauge según el rango filtrado:
        //   - si abarca un único mes (caso por defecto, mes en curso)
        //     → "Cumplimiento mes (Mayo 2026)"
        //   - si abarca varios meses → "Cumplimiento del rango (DD/MM/AAAA → DD/MM/AAAA)"
        actualizarTituloGauge(d.global, d.meses_data || []);

        // Leyenda explicativa debajo del gauge.
        actualizarLeyendaGauge(d.global, d.meses_data || []);

    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

function onMachineChange() { _mantCodMaquina = $('#machine-selector').value || ''; updateUrlParams({ cod_maquina_mant: _mantCodMaquina }); cargarVista(); }
function onDesdeChange()   { _fDesde = $('#f-desde').value; updateUrlParams({ fecha_desde: _fDesde }); cargarVista(); }
function onHastaChange()   { _fHasta = $('#f-hasta').value; updateUrlParams({ fecha_hasta: _fHasta }); cargarVista(); }
function onPeriodicidadClickFromChart(per) {
    // Toggle drill-down: clic en la misma cierra; clic en otra cambia.
    if (_drillPer === per) {
        cerrarDrillDown();
    } else {
        _drillPer = String(per);
        abrirDrillDown();
        cargarTareasPer();
        cargarMesesPorPeriodicidad();
    }
    // Re-render del chart de periodicidades para refrescar resalte.
    cargarVista();
}

function abrirDrillDown() {
    const b = $('#tareas-block');
    if (b) b.style.display = '';
    const m = $('#meses-drill-block');
    if (m) m.style.display = '';
}
function cerrarDrillDown() {
    _drillPer = null;
    const b = $('#tareas-block');
    if (b) b.style.display = 'none';
    const m = $('#meses-drill-block');
    if (m) m.style.display = 'none';
    if (chartMesesPer) { chartMesesPer.destroy(); chartMesesPer = null; }
    cerrarMesDetalle();
}

async function cargarMesesPorPeriodicidad() {
    if (!_drillPer) return;
    const lbl = $('#meses-drill-per');
    if (lbl) lbl.textContent = _drillPer;
    try {
        const params = { periodicidad: _drillPer };
        if (_fDesde)         params.fecha_desde      = _fDesde;
        if (_fHasta)         params.fecha_hasta      = _fHasta;
        if (_mantCodMaquina) params.cod_maquina_mant = _mantCodMaquina;
        const d = await apiFetch('mant_cumplimiento_meses.php', params);
        renderMesesPer(d.meses_data || []);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    }
}

function onMesClick(mes, origen) {
    if (!mes) return;
    if (_mesActivo === mes && _mesActivoOrigen === origen) {
        cerrarMesDetalle();
        return;
    }
    _mesActivo = mes;
    _mesActivoOrigen = origen;
    abrirMesDetalle();
    cargarMesDetalle();
}

function abrirMesDetalle() {
    const b = $('#mes-detalle-block');
    if (b) b.style.display = '';
}
function cerrarMesDetalle() {
    _mesActivo = null;
    _mesActivoOrigen = null;
    const b = $('#mes-detalle-block');
    if (b) b.style.display = 'none';
}

async function cargarMesDetalle() {
    if (!_mesActivo) return;
    const tbody = $('#mes-detalle-tbody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="8" class="mant-empty">Cargando…</td></tr>';
    $('#mes-detalle-label').textContent = fmtMesEs(_mesActivo);

    try {
        const params = { mes: _mesActivo };
        // Si el clic vino del drill-down de una periodicidad, conservamos
        // ese filtro al consultar el detalle del mes.
        const perFilter = _mesActivoOrigen === 'periodicidad' ? _drillPer : null;
        if (perFilter)        params.periodicidad     = perFilter;
        if (_mantCodMaquina)  params.cod_maquina_mant = _mantCodMaquina;
        // Pasamos también el rango de fechas del panel principal para
        // que el detalle solo muestre las tareas con fecha efectiva
        // (intervención o programada) dentro del rango.
        if (_fDesde)          params.fecha_desde      = _fDesde;
        if (_fHasta)          params.fecha_hasta      = _fHasta;

        const d = await apiFetch('mant_cumplimiento_mes_detalle.php', params);

        const scopeBits = [];
        if (perFilter) scopeBits.push('periodicidad ' + perFilter);
        if (_mantCodMaquina) scopeBits.push('máq ' + _mantCodMaquina);
        $('#mes-detalle-scope').textContent = scopeBits.length ? '· ' + scopeBits.join(' · ') : '';

        const t = d.totales || {};
        const cumpl = d.cumplimiento === null || d.cumplimiento === undefined ? '—' : d.cumplimiento.toFixed(2) + ' %';
        $('#mes-detalle-stats').innerHTML = `
            <span class="mant-stat-pill"><b>${t.total ?? 0}</b> tareas</span>
            <span class="mant-stat-pill mant-stat-pill-ok"><b>${t.realizadas ?? 0}</b> realizadas</span>
            <span class="mant-stat-pill mant-stat-pill-ko"><b>${t.no_realizadas ?? 0}</b> no realizadas</span>
            <span class="mant-stat-pill mant-stat-pill-ko"><b>${t.vencidas_sin_marcar ?? 0}</b> pendientes</span>
            <span class="mant-stat-pill mant-stat-pill-rec"><b>${t.recuperaciones ?? 0}</b> recuperadas</span>
            <span class="mant-stat-pill mant-stat-pill-cumpl">cumplimiento <b>${cumpl}</b></span>
        `;

        renderMesDetalleTabla(d.rows || []);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
        if (tbody) tbody.innerHTML = '<tr><td colspan="8" class="mant-empty">Error cargando datos</td></tr>';
    }
}

function renderMesDetalleTabla(rows) {
    const tb = $('#mes-detalle-tbody');
    if (!tb) return;
    if (!rows.length) {
        tb.innerHTML = '<tr><td colspan="8" class="mant-empty">Sin tareas en este mes con los filtros aplicados</td></tr>';
        return;
    }
    tb.innerHTML = rows.map(r => {
        let badge;
        if      (r.tipo === 'no_realizada')        badge = '<span class="mant-source-badge mant-source-missed">NO REALIZADA</span>';
        else if (r.tipo === 'recuperacion')        badge = '<span class="mant-source-badge mant-source-catchup">RECUPERACIÓN</span>';
        else if (r.tipo === 'vencida_sin_marcar') badge = '<span class="mant-source-badge mant-source-missed">PENDIENTE</span>';
        else                                       badge = '<span class="mant-source-badge mant-source-web">REALIZADA</span>';

        // Resumimos a "Realizada / Pendiente / Recuperada":
        //   - Si la tarea no se hizo, mostramos "Pendiente" (con motivo si lo hay).
        //   - Vencida sin marcar = "Pendiente · sin imputar" (ni siquiera se marcó como no_realizada).
        //   - Para realizadas/recuperadas mostramos "Realizada" salvo que haya
        //     observaciones del operario que no sean el seed automático del import.
        const obsTxt    = String(r.observaciones || '').trim();
        const obsIsSeed = /sembrado\s+automatico/i.test(obsTxt);
        let obs;
        if (r.tipo === 'no_realizada') {
            const motivo = String(r.motivo_no_realizada || '').trim();
            obs = motivo
                ? `<span class="mant-pill-status mant-pill-pendiente">Pendiente</span> <span class="mant-cod">· ${escHtml(motivo)}</span>`
                : `<span class="mant-pill-status mant-pill-pendiente">Pendiente</span>`;
        } else if (r.tipo === 'vencida_sin_marcar') {
            obs = `<span class="mant-pill-status mant-pill-pendiente">Pendiente</span>`;
        } else if (obsTxt && !obsIsSeed) {
            obs = `<span class="mant-pill-status mant-pill-realizada">Realizada</span> <span class="mant-cod">· ${escHtml(obsTxt)}</span>`;
        } else {
            obs = `<span class="mant-pill-status mant-pill-realizada">Realizada</span>`;
        }

        const rowClass = (r.tipo === 'no_realizada' || r.tipo === 'vencida_sin_marcar') ? 'mant-row-missed'
                       : r.tipo === 'recuperacion' ? 'mant-row-catchup'
                       : '';

        // Si es consolidada (varias tareas de la misma máquina hechas el
        // mismo día), mostramos badge con el contador y un <details> con
        // todas las sub-tareas. Para RACK/PLATAFORMA/TROLEY mantenemos
        // el texto "Revisión completa"; para el resto la etiqueta es
        // simplemente "Tareas agrupadas".
        const isConsol = !!r.consolidada;
        const isClasica = !!r.consolidacion_clasica;
        const subTotal = r.subtareas_total || (r.sub_tareas ? r.sub_tareas.length : 0);
        const perPills = isConsol && Array.isArray(r.periodicidades) && r.periodicidades.length
            ? r.periodicidades.map(p =>
                `<span class="mant-pill mant-pill-${String(p).toLowerCase()}">${escHtml(p)}</span>`
              ).join(' ')
            : `<span class="mant-pill mant-pill-${(r.periodicidad||'').toLowerCase()}">${escHtml(r.periodicidad)}</span>`;
        const consolTitulo = isClasica ? 'Revisión completa' : 'Tareas agrupadas';
        const tareaCol = isConsol
            ? `<strong>${consolTitulo}</strong> <span class="mant-consol-badge">${subTotal} tareas</span>` +
              (Array.isArray(r.sub_tareas) && r.sub_tareas.length
                ? `<details class="mant-subtareas" style="margin-top:4px">
                       <summary style="cursor:pointer;font-size:11px;color:#5b6f86">Ver las ${subTotal} tareas</summary>
                       <ul style="margin:6px 0 0 16px;padding:0;font-size:11.5px;color:#5b6f86">
                           ${r.sub_tareas.map(s =>
                               `<li><strong>${escHtml(s.tarea)}</strong>${s.desc_tarea ? ': ' + escHtml(s.desc_tarea) : ''}</li>`
                           ).join('')}
                       </ul>
                   </details>`
                : '')
            : `${escHtml(r.desc_grupo)}<br><span class="mant-cod">tarea ${escHtml(r.tarea)} · ${escHtml(r.desc_tarea)}</span>`;

        return `
            <tr class="${rowClass}${isConsol ? ' mant-row-consolidada' : ''}">
                <td>${badge}</td>
                <td><strong>${escHtml(r.desc_maquina)}</strong><br><span class="mant-cod">(${escHtml(r.cod_maquina_mant)})</span></td>
                <td>${perPills}</td>
                <td>${tareaCol}</td>
                <td class="mant-fecha">${fmtFecha(r.fecha_proxima_original)}</td>
                <td class="mant-fecha">${fmtFecha(r.fecha_intervencion)}</td>
                <td><span class="mant-operario">${escHtml(r.operario || '—')}</span></td>
                <td>${obs || '<span class="mant-cod">—</span>'}</td>
            </tr>
        `;
    }).join('');
}

function fmtFecha(iso) {
    if (!iso) return '—';
    const [y, m, d] = iso.split('-');
    return d + '/' + m + '/' + y;
}
function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmtDias(n) {
    if (n === null || n === undefined) return '—';
    if (n < 0) return Math.abs(n) + 'd vencida';
    if (n === 0) return 'hoy';
    return 'en ' + n + 'd';
}

async function cargarTareasPer() {
    if (!_drillPer) return;
    const tbody = $('#tareas-tbody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="mant-empty">Cargando…</td></tr>';
    $('#tareas-per-label').textContent = _drillPer;
    try {
        const params = { periodicidad: _drillPer };
        if (_mantCodMaquina) params.cod_maquina_mant = _mantCodMaquina;
        const d = await apiFetch('mant_tareas.php', params);
        _periodicidadesSoportadas = d.periodicidades_soportadas || [];
        renderTareasTabla(d.rows || []);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
        if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="mant-empty">Error cargando datos</td></tr>';
    }
}

function renderTareasTabla(rows) {
    const tb = $('#tareas-tbody');
    if (!tb) return;
    if (!rows.length) {
        tb.innerHTML = '<tr><td colspan="7" class="mant-empty">Sin tareas con esta periodicidad</td></tr>';
        return;
    }
    tb.innerHTML = rows.map(r => {
        const stateCls = 'mant-dias-' + (r.estado || 'en_plazo');
        const stateTxt = fmtDias(r.dias_restantes);
        const overrideBadge = r.has_override
            ? `<span class="mant-source-badge mant-source-web" title="Periodicidad original: ${escHtml(r.periodicidad_original || '')}">OVERRIDE</span>`
            : '';
        const recalcMark = r.proxima_recalculada
            ? '<span class="mant-cod" title="Recalculada según nueva periodicidad">🔄</span>'
            : '';
        const dataAttrs = [
            `data-orden="${escHtml(r.orden)}"`,
            `data-tarea="${escHtml(r.tarea)}"`,
            `data-cod-maq="${escHtml(r.cod_maquina_mant)}"`,
            `data-desc-maq="${escHtml(r.desc_maquina)}"`,
            `data-desc-grupo="${escHtml(r.desc_grupo)}"`,
            `data-desc-tarea="${escHtml(r.desc_tarea)}"`,
            `data-periodicidad-actual="${escHtml(r.periodicidad)}"`,
            `data-periodicidad-original="${escHtml(r.periodicidad_original || r.periodicidad)}"`,
            `data-has-override="${r.has_override ? '1' : '0'}"`,
            `data-ultima="${escHtml(r.ultima_revision || '')}"`
        ].join(' ');
        return `
            <tr>
                <td><strong>${escHtml(r.desc_maquina)}</strong> <span class="mant-cod">(${escHtml(r.cod_maquina_mant)})</span></td>
                <td>${escHtml(r.desc_grupo)}<br><span class="mant-cod">tarea ${escHtml(r.tarea)}</span> ${overrideBadge}</td>
                <td class="mant-desc">${escHtml(r.desc_tarea)}</td>
                <td class="mant-fecha">${fmtFecha(r.ultima_revision)}</td>
                <td class="mant-fecha">${fmtFecha(r.proxima_revision)} ${recalcMark}</td>
                <td class="mant-dias ${stateCls}">${escHtml(stateTxt)}</td>
                <td>${window.__IS_OPERARIO ? '<span class="mant-cod">—</span>' : `<button type="button" class="mant-action-btn mant-action-edit role-tecnico-only" ${dataAttrs}>✎ Cambiar</button>`}</td>
            </tr>
        `;
    }).join('');
    tb.querySelectorAll('.mant-action-edit').forEach(btn => {
        btn.addEventListener('click', () => abrirModalEditar(btn.dataset));
    });
}

function abrirModalEditar(d) {
    _editTask = {
        orden: d.orden, tarea: d.tarea,
        cod_maq: d.codMaq, desc_maq: d.descMaq,
        desc_grupo: d.descGrupo, desc_tarea: d.descTarea,
        periodicidad_actual: d.periodicidadActual,
        periodicidad_original: d.periodicidadOriginal,
        has_override: d.hasOverride === '1',
        ultima: d.ultima
    };
    const summary = `<strong>${escHtml(d.descMaq)}</strong> <span class="mant-cod">(${escHtml(d.codMaq)})</span><br>
                     ${escHtml(d.descGrupo)}<br>
                     <span class="mant-cod">tarea ${escHtml(d.tarea)} · ${escHtml(d.descTarea)}</span><br>
                     <span class="mant-cod">Periodicidad actual: <b>${escHtml(d.periodicidadActual)}</b>` +
                     (_editTask.has_override ? ` (override; original: ${escHtml(d.periodicidadOriginal)})` : '') +
                     `</span>`;
    $('#per-modal-summary').innerHTML = summary;

    // Construir desplegable
    const sel = $('#per-select');
    sel.innerHTML = '';
    _periodicidadesSoportadas.forEach(p => {
        const o = document.createElement('option');
        o.value = p; o.textContent = p;
        sel.appendChild(o);
    });
    if (_editTask.has_override) {
        const og = document.createElement('option');
        og.value = 'ORIGINAL'; og.textContent = '↩ Volver a la del Excel (' + _editTask.periodicidad_original + ')';
        sel.appendChild(og);
    }
    sel.value = _editTask.periodicidad_actual;
    $('#per-restore-wrap').style.display = _editTask.has_override ? '' : 'none';
    $('#per-nota').value = '';
    actualizarPreview();

    const modal = $('#per-modal');
    modal.style.display = '';
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(() => sel.focus(), 50);
}

function actualizarPreview() {
    const wrap = $('#per-preview-wrap');
    const box  = $('#per-preview');
    if (!_editTask || !_editTask.ultima) {
        wrap.style.display = 'none';
        return;
    }
    const sel = $('#per-select').value;
    let perPreview = sel;
    if (sel === 'ORIGINAL') perPreview = _editTask.periodicidad_original;
    const dias = PER_DAYS[perPreview];
    if (!dias) {
        wrap.style.display = 'none';
        return;
    }
    const ts = Date.parse(_editTask.ultima);
    if (isNaN(ts)) { wrap.style.display = 'none'; return; }
    const next = new Date(ts + dias * 86400 * 1000);
    const iso = next.toISOString().substring(0, 10);
    box.innerHTML = `Próxima revisión recalculada → <b>${fmtFecha(iso)}</b>
                     <span class="mant-cod">(última ${fmtFecha(_editTask.ultima)} + ${dias} días)</span>`;
    wrap.style.display = '';
}

function cerrarModalEditar() {
    const modal = $('#per-modal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    _editTask = null;
}

async function confirmarEditar() {
    if (!_editTask) return;
    const sel = $('#per-select').value;
    const nota = ($('#per-nota').value || '').trim();
    showLoader(true);
    try {
        const body = JSON.stringify({
            orden: _editTask.orden,
            tarea: _editTask.tarea,
            periodicidad: sel,
            nota
        });
        const headers = { 'Content-Type': 'application/json' };
        if (window.__CSRF_TOKEN) headers['X-CSRF-Token'] = window.__CSRF_TOKEN;
        const resp = await fetch('../api/mant_set_periodicidad.php', {
            method: 'POST',
            headers,
            body
        });
        const json = await resp.json();
        if (!resp.ok || !json.ok) throw new Error(json.error || `HTTP ${resp.status}`);
        showToast(json.data.action === 'removed' ? 'Override eliminado' : 'Periodicidad actualizada', 'success');
        cerrarModalEditar();
        // Recargar vista global y drill-down (la tarea editada puede haber salido
        // de la periodicidad actual y aparecer en otra).
        await cargarVista();
        if (_drillPer) await cargarTareasPer();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}
function onClearFilters() {
    _mantCodMaquina = '';
    $('#machine-selector').value = '';
    updateUrlParams({ cod_maquina_mant: '' });
    // Tras limpiar, el rango se deja en "día anterior" (decisión del usuario).
    // Si la función está disponible (definida dentro del IIFE de init), la usamos.
    const btn = document.querySelector('.cumpl-quick[data-range="dia_ant"]');
    if (typeof window._aplicarRangoCumpl === 'function') {
        window._aplicarRangoCumpl('dia_ant', btn);
    } else {
        cargarVista();
    }
}

// ─── Título dinámico del gauge según el rango filtrado ────────────────────
function actualizarTituloGauge(g, mesesData) {
    const el = document.getElementById('gauge-title');
    if (!el) return;
    const fd = g && g.fecha_desde, fh = g && g.fecha_hasta;
    if (!fd || !fh) { el.textContent = 'Cumplimiento mes'; return; }
    const mesD = fd.substring(0, 7), mesH = fh.substring(0, 7);
    if (mesD === mesH) {
        // Un solo mes — mostramos su nombre.
        el.textContent = 'Cumplimiento mes (' + nombreMesLargo(mesD) + ')';
    } else {
        const fmt = iso => {
            const [y, m, dd] = iso.split('-');
            return dd + '/' + m + '/' + y;
        };
        el.textContent = 'Cumplimiento del rango (' + fmt(fd) + ' → ' + fmt(fh) + ')';
    }
}

// ─── Leyenda explicativa debajo del gauge ─────────────────────────────────
// El gauge muestra el cumplimiento AGREGADO del rango filtrado. Cada barra
// del gráfico inferior muestra el cumplimiento de UN mes concreto. Cuando el
// rango abarca varios meses, el gauge ≠ cada barra individual; es la media
// ponderada por tareas. Cuando el rango es un solo mes, coinciden.
function actualizarLeyendaGauge(g, mesesData) {
    const el = document.getElementById('gauge-legend');
    if (!el) return;
    if (!g || !g.fecha_desde) { el.textContent = '—'; return; }
    const mesD = g.fecha_desde.substring(0, 7);
    const mesH = g.fecha_hasta.substring(0, 7);
    const unSoloMes = (mesD === mesH);

    const realizadas        = g.numer ?? g.realizadas ?? 0;
    const completadas       = g.completadas ?? 0;
    const recuperaciones    = g.recuperaciones ?? 0;
    const noRealizadas      = g.no_realizadas ?? 0;
    const vencSinMarcar     = g.vencidas_sin_marcar ?? 0;
    const denom             = g.denom ?? (realizadas + noRealizadas + vencSinMarcar);
    const pct               = g.cumplimiento ?? 0;

    const formulaLine = `<strong>Cómo se calcula:</strong> realizadas ${realizadas} `
        + `/ (realizadas ${realizadas} + no realizadas ${noRealizadas} + pendientes ${vencSinMarcar})`
        + ` = <strong>${Number(pct).toFixed(2)} %</strong>.`;

    let porQue;
    if (unSoloMes) {
        porQue = `Como el rango filtrado es un único mes (<strong>${nombreMesLargo(mesD)}</strong>), `
               + `este valor coincide exactamente con la barra de ese mes en el gráfico inferior.`;
    } else {
        const nMeses = (mesesData || []).length;
        porQue = `El rango filtrado abarca <strong>${nMeses || 'varios'} meses</strong>, así que este `
               + `valor es la media ponderada por tareas: suma de todas las realizadas dividida por la `
               + `suma de todos los denominadores. Por eso <strong>puede no coincidir con una barra `
               + `concreta</strong> del gráfico inferior, que muestra solo un mes. Para ver el `
               + `cumplimiento de un único mes, filtra desde el día 1 al último día de ese mes.`;
    }

    const detalleBreakdown = recuperaciones > 0
        ? ` Las recuperaciones (${recuperaciones}) cuentan en el mes en que se hicieron, no en el mes original.`
        : '';

    el.innerHTML = formulaLine + '<br>' + porQue + detalleBreakdown;
}

function nombreMesLargo(ym) {
    const meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                   'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    const [y, m] = ym.split('-');
    const idx = parseInt(m, 10) - 1;
    return (meses[idx] || m) + ' ' + y;
}

// Descarga el informe de cumplimiento respetando los filtros vivos
// (fecha_desde, fecha_hasta, cod_maquina_mant). Abrimos en pestaña nueva
// para que cualquier error PHP del endpoint sea visible.
function descargarInforme(fmt) {
    console.log('[cumpl] descargarInforme invocado', fmt);
    const params = new URLSearchParams();
    params.set('fmt', fmt === 'pdf' ? 'pdf' : 'xlsx');
    if (_fDesde)         params.set('fecha_desde',     _fDesde);
    if (_fHasta)         params.set('fecha_hasta',     _fHasta);
    if (_mantCodMaquina) params.set('cod_maquina_mant', _mantCodMaquina);
    const url = '../api/mant_cumplimiento_export.php?' + params.toString();
    console.log('[cumpl] descargando ->', url);
    const a = document.createElement('a');
    a.href   = url;
    a.target = '_blank';
    a.rel    = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
}

document.addEventListener('DOMContentLoaded', () => {
    _mantCodMaquina = getQueryParam('cod_maquina_mant') || '';
    _fDesde         = getQueryParam('fecha_desde') || '';
    _fHasta         = getQueryParam('fecha_hasta') || '';

    // Default: del primer día del mes en curso al último día del mismo mes.
    // Esto hace que la métrica realizadas/(realizadas+previstas+atrasadas)
    // refleje la progresión del mes actual desde el inicio.
    if (!_fDesde || !_fHasta) {
        const hoy = new Date();
        const fmt = d => d.toISOString().substring(0, 10);
        const primer = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
        const ultimo = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0);
        _fDesde = _fDesde || fmt(primer);
        _fHasta = _fHasta || fmt(ultimo);
    }
    $('#f-desde').value = _fDesde;
    $('#f-hasta').value = _fHasta;

    $('#f-desde').addEventListener('change', onDesdeChange);
    $('#f-hasta').addEventListener('change', onHastaChange);
    $('#machine-selector').addEventListener('change', onMachineChange);
    const c = $('#filter-clear'); if (c) c.addEventListener('click', onClearFilters);

    // Calcula el rango para un kind dado. Componentes locales (no toISOString)
    // para que el día 1 no se vaya al último del mes anterior por zona horaria.
    function _rangoCumplRapido(kind) {
        const pad = n => String(n).padStart(2, '0');
        const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
        const hoy = new Date();
        if (kind === 'dia_ant') {
            const ayer = new Date(hoy); ayer.setDate(hoy.getDate() - 1);
            return { desde: fmt(ayer), hasta: fmt(ayer) };
        }
        if (kind === 'sem_ant') {
            // ISO: lunes=1 .. domingo=7. Lunes de ESTA semana = hoy - (dow-1).
            const dow = hoy.getDay() === 0 ? 7 : hoy.getDay();
            const lunesEsta = new Date(hoy); lunesEsta.setDate(hoy.getDate() - (dow - 1));
            const lunesAnt  = new Date(lunesEsta); lunesAnt.setDate(lunesEsta.getDate() - 7);
            const domingoAnt= new Date(lunesAnt);  domingoAnt.setDate(lunesAnt.getDate() + 6);
            return { desde: fmt(lunesAnt), hasta: fmt(domingoAnt) };
        }
        // 1m / 3m / 6m → día 1 del mes resultante → hoy
        let monthsBack = 0;
        if      (kind === '3m') monthsBack = 2;
        else if (kind === '6m') monthsBack = 5;
        const desde = new Date(hoy.getFullYear(), hoy.getMonth() - monthsBack, 1);
        return { desde: fmt(desde), hasta: fmt(hoy) };
    }

    function _aplicarRangoCumpl(kind, btnActivo) {
        const r = _rangoCumplRapido(kind);
        _fDesde = r.desde;
        _fHasta = r.hasta;
        $('#f-desde').value = _fDesde;
        $('#f-hasta').value = _fHasta;
        updateUrlParams({ fecha_desde: _fDesde, fecha_hasta: _fHasta });
        // Resaltado visual del botón activo (si lo hay)
        document.querySelectorAll('.cumpl-quick').forEach(b => {
            const on = (btnActivo && b === btnActivo);
            b.style.background  = on ? '#2d4d7a' : '#eef2f6';
            b.style.color       = on ? '#fff'    : '#2d4d7a';
            b.style.borderColor = on ? '#2d4d7a' : '#c5d2e0';
        });
        cargarVista();
    }
    // Exponemos para que onClearFilters pueda invocarlo
    window._aplicarRangoCumpl = _aplicarRangoCumpl;

    document.querySelectorAll('.cumpl-quick').forEach(btn => {
        btn.addEventListener('click', () => _aplicarRangoCumpl(btn.dataset.range, btn));
    });

    // Descarga del informe de cumplimiento (respeta filtros activos)
    const xlsxBtn = $('#cumpl-export-xlsx');
    if (xlsxBtn) xlsxBtn.addEventListener('click', () => descargarInforme('xlsx'));
    const pdfBtn  = $('#cumpl-export-pdf');
    if (pdfBtn)  pdfBtn.addEventListener('click', () => descargarInforme('pdf'));

    // Drill-down + modal edición periodicidad
    const closeBtn = $('#tareas-close'); if (closeBtn) closeBtn.addEventListener('click', cerrarDrillDown);
    const closeMD  = $('#meses-drill-close'); if (closeMD) closeMD.addEventListener('click', cerrarDrillDown);
    const closeMes = $('#mes-detalle-close'); if (closeMes) closeMes.addEventListener('click', cerrarMesDetalle);
    $('#per-modal-close').addEventListener('click', cerrarModalEditar);
    $('#per-modal-cancel').addEventListener('click', cerrarModalEditar);
    $('#per-modal-backdrop').addEventListener('click', cerrarModalEditar);
    $('#per-modal-ok').addEventListener('click', confirmarEditar);
    $('#per-select').addEventListener('change', actualizarPreview);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && $('#per-modal').style.display !== 'none') cerrarModalEditar();
    });

    initFiltros(cargarVista);
    cargarVista();
});
