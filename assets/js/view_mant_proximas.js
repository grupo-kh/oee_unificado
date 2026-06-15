/* Vista Mantenimiento · Próximas Revisiones */

let gaugeMant = null;
let chartTopMaqProx = null;
// Filtro por rango de fechas (sustituye al antiguo desplegable "dias").
// Default: desde hoy hasta hoy+30 días.
let _mantFechaDesde = '';   // YYYY-MM-DD
let _mantFechaHasta = '';   // YYYY-MM-DD
let _mantCodMaquina = '';
let _mantPeriodicidad = '';
let _mantSoloVencidas = false;

function _hoyIso() { return new Date().toISOString().substring(0, 10); }
function _addDaysIso(iso, days) {
    const d = new Date(iso + 'T00:00:00');
    d.setDate(d.getDate() + days);
    return d.toISOString().substring(0, 10);
}
function _parseIsoDateLocal(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
    if (!m) return null;
    return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
}
function _formatIsoDateLocal(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}
function mantCurrentWeekRangeFrom(baseIso) {
    const base = _parseIsoDateLocal(baseIso) || new Date();
    const isoDay = base.getDay() === 0 ? 7 : base.getDay();
    const monday = new Date(base.getFullYear(), base.getMonth(), base.getDate());
    monday.setDate(base.getDate() - (isoDay - 1));
    const sunday = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate());
    sunday.setDate(monday.getDate() + 6);
    return {
        desde: _formatIsoDateLocal(monday),
        hasta: _formatIsoDateLocal(sunday),
    };
}
function mantNextWeekRangeFrom(baseIso) {
    const base = _parseIsoDateLocal(baseIso) || new Date();
    const isoDay = base.getDay() === 0 ? 7 : base.getDay();
    const nextMonday = new Date(base.getFullYear(), base.getMonth(), base.getDate());
    nextMonday.setDate(base.getDate() + (8 - isoDay));
    const nextSunday = new Date(nextMonday.getFullYear(), nextMonday.getMonth(), nextMonday.getDate());
    nextSunday.setDate(nextMonday.getDate() + 6);
    return {
        desde: _formatIsoDateLocal(nextMonday),
        hasta: _formatIsoDateLocal(nextSunday),
    };
}
let _operariosKnown = [];
// Catálogo de operarios ACTIVOS (los 8 actuales). Cada entrada:
// {numero: '881', nombre: 'Juan Navarro'}. Es lo que alimenta el desplegable
// del popup "marcar como hecha" — muestra el nombre, guarda el código.
let _operariosActivos = [];
// Map de filas consolidadas: idx → array de sub_tareas. Para marcar bulk.
let _consolRowsByIdx = {};
let _mantRowsActuales = [];
let _mantMachinesExpanded = new Set();
let _markPayload = null;
const LS_LAST_OPERARIO = 'mant_last_operario';

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

function renderGauge(value) {
    const v = Math.max(0, Math.min(100, parseFloat(value) || 0));
    const options = {
        chart: { type: 'radialBar', height: 320, background: 'transparent' },
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
                        formatter: () => v.toFixed(1) + '%'
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
        labels: ['Hechas / en plazo'],
        stroke: { lineCap: 'round' }
    };
    if (gaugeMant) gaugeMant.destroy();
    gaugeMant = new ApexCharts($('#gauge-mant'), options);
    gaugeMant.render();
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
function populatePeriodicidades(periodicidades, current) {
    const sel = $('#periodicidad-selector');
    if (!sel) return;
    sel.innerHTML = '<option value="">— Todas —</option>';
    periodicidades.forEach(p => {
        const o = document.createElement('option');
        o.value = p; o.textContent = p;
        sel.appendChild(o);
    });
    sel.value = current || '';
}

function fmtFecha(iso) {
    if (!iso) return '—';
    const [y, m, d] = iso.split('-');
    return d + '/' + m + '/' + y;
}
function fmtDias(n) {
    if (n < 0) return Math.abs(n) + 'd vencida';
    if (n === 0) return 'hoy';
    return 'en ' + n + 'd';
}
function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function renderTopMaquinas(arr) {
    const empty = $('#chart-top-maquinas-prox-empty');
    const cont  = $('#chart-top-maquinas-prox');
    if (!arr || !arr.length) {
        if (chartTopMaqProx) { chartTopMaqProx.destroy(); chartTopMaqProx = null; }
        if (cont) cont.innerHTML = '';
        if (empty) empty.style.display = '';
        return;
    }
    if (empty) empty.style.display = 'none';
    const data = arr.map(m => ({
        x: m.desc_maquina, cod: m.cod_maquina_mant,
        vencidas: m.vencidas, urgentes: m.urgentes,
        en_plazo: m.en_plazo ?? (m.total - m.vencidas - m.urgentes),
        total: m.total
    }));
    // Altura adaptativa: ~32 px por máquina + 80 px de chrome. Antes estaba
    // topada en 620 px, lo que hacía que con muchas máquinas se aplastaran.
    // Ahora crece libremente — el contenedor padre tendrá scroll si hace falta.
    const height = Math.max(220, 32 * data.length + 80);
    const options = {
        chart: {
            type: 'bar', stacked: true, height,
            background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    const cod = data[cfg.dataPointIndex] && data[cfg.dataPointIndex].cod;
                    if (cod) onTopMaquinaClick(cod);
                }
            }
        },
        series: [
            { name: 'Vencidas', data: data.map(d => d.vencidas) },
            { name: 'Próximas', data: data.map(d => d.urgentes) },
            { name: 'Hechas / en plazo', data: data.map(d => d.en_plazo) }
        ],
        xaxis: { categories: data.map(d => d.x), labels: { style: { colors: '#2d4d7a', fontSize: '11px' } } },
        yaxis: { labels: { style: { colors: '#1a2d4a', fontSize: '12px', fontWeight: 600 }, maxWidth: 160 } },
        plotOptions: { bar: { horizontal: true, barHeight: '60%', borderRadius: 0 } },
        states: { active: { filter: { type: 'none', value: 0 } } },
        colors: ['#c8102e', '#f59e0b', '#10b981'],
        dataLabels: {
            enabled: true,
            style: { colors: ['#ffffff'], fontFamily: 'Arial', fontSize: '11px', fontWeight: 700 },
            formatter: v => v > 0 ? v : ''
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { position: 'bottom', fontFamily: 'Arial', fontSize: '12px' },
        tooltip: {
            shared: false,
            intersect: true,
            custom: ({dataPointIndex}) => {
                const r = data[dataPointIndex];
                return `
                    <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:220px">
                        <div style="font-weight:700;margin-bottom:6px;font-size:13px">${escHtml(r.x)}</div>
                        <div style="color:#a3b8d1;font-size:11px;margin-bottom:6px">${escHtml(r.cod)}</div>
                        <div style="display:flex;justify-content:space-between;gap:12px;color:#fca5a5"><span>Vencidas</span><span>${r.vencidas}</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px;color:#fbbf24"><span>Próximas</span><span>${r.urgentes}</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px;color:#86efac"><span>Hechas / en plazo</span><span>${r.en_plazo}</span></div>
                        <div style="border-top:1px solid #3a5576;margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:13px">
                            <span>Total</span><span>${r.total}</span>
                        </div>
                        <div style="color:#a3b8d1;font-size:10px;margin-top:6px;text-align:center">Clic para filtrar</div>
                    </div>
                `;
            }
        }
    };
    if (chartTopMaqProx) chartTopMaqProx.destroy();
    chartTopMaqProx = new ApexCharts(cont, options);
    chartTopMaqProx.render();
}

function onTopMaquinaClick(cod) {
    _mantCodMaquina = String(cod);
    const sel = $('#machine-selector');
    if (sel) sel.value = _mantCodMaquina;
    updateUrlParams({ cod_maquina_mant: _mantCodMaquina });
    cargarVista();
}

function mantRowCompleted(r) {
    if (Object.prototype.hasOwnProperty.call(r, 'marca_completada')) return !!r.marca_completada;
    const tipoMarca = String(r.tipo_marca || '');
    return !!r.ya_marcada && tipoMarca !== 'no_realizada';
}

function mantRowSummaryState(r) {
    if (mantRowCompleted(r)) return 'en_plazo';
    if (String(r.tipo_marca || '') === 'no_realizada') return 'vencida';
    return r.estado === 'vencida' || r.estado === 'urgente' ? r.estado : 'en_plazo';
}

function mantMachineKey(r, fallbackIdx) {
    const cod = String(r.cod_maquina_mant || '').trim();
    if (cod) return cod;
    const desc = String(r.desc_maquina || '').trim();
    return desc ? 'desc:' + desc : 'idx:' + fallbackIdx;
}

function mantNormalizeMachineText(value) {
    return String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toUpperCase();
}

function mantShouldGroupMachine(r) {
    const text = mantNormalizeMachineText((r.cod_maquina_mant || '') + ' ' + (r.desc_maquina || ''));
    const tokens = text.split(/[^A-Z0-9]+/).filter(Boolean);
    return tokens.some(t =>
        t.startsWith('RACK') ||
        t.startsWith('PLATFORM') ||
        t.startsWith('PLATAFORM') ||
        t.startsWith('PLATAFORMA') ||
        t.startsWith('TROLEY') ||
        t.startsWith('TROLLEY')
    );
}

function buildMantMachineGroups(rows) {
    const groups = [];
    const byKey = new Map();
    rows.forEach((entry, idx) => {
        const r = entry && entry.row ? entry.row : entry;
        const rowIndex = entry && Object.prototype.hasOwnProperty.call(entry, 'rowIndex') ? entry.rowIndex : idx;
        const key = mantMachineKey(r, rowIndex);
        let g = byKey.get(key);
        if (!g) {
            g = {
                key,
                cod_maquina_mant: r.cod_maquina_mant || '',
                desc_maquina: r.desc_maquina || r.cod_maquina_mant || 'Sin maquina',
                rows: [],
                rowIndexes: [],
                total: 0,
                pendientes: 0,
                hechas: 0,
                no_realizadas: 0,
                vencidas: 0,
                urgentes: 0,
                en_plazo: 0,
                periodicidades: [],
                proxima_revision: '',
                ultima_revision: '',
                estado: 'en_plazo',
            };
            byKey.set(key, g);
            groups.push(g);
        }

        const state = mantRowSummaryState(r);
        const completed = mantRowCompleted(r);
        const tipoMarca = String(r.tipo_marca || '');

        g.rows.push(r);
        g.rowIndexes.push(rowIndex);
        g.total++;
        if (completed) g.hechas++;
        else g.pendientes++;
        if (tipoMarca === 'no_realizada') g.no_realizadas++;
        if (state === 'vencida') g.vencidas++;
        else if (state === 'urgente') g.urgentes++;
        else g.en_plazo++;

        const per = String(r.periodicidad || '').trim();
        if (per && !g.periodicidades.includes(per)) g.periodicidades.push(per);

        const px = String(r.proxima_revision || '');
        if (px && (!g.proxima_revision || px < g.proxima_revision)) g.proxima_revision = px;
        const ul = String(r.ultima_revision || '');
        if (ul && (!g.ultima_revision || ul > g.ultima_revision)) g.ultima_revision = ul;

        if (g.vencidas > 0) g.estado = 'vencida';
        else if (g.urgentes > 0) g.estado = 'urgente';
        else g.estado = 'en_plazo';
    });
    return groups;
}

function buildMantTableItems(rows) {
    const groupedEntries = [];
    rows.forEach((r, idx) => {
        if (mantShouldGroupMachine(r)) {
            groupedEntries.push({ row: r, rowIndex: idx });
        }
    });

    const groupedByKey = new Map();
    buildMantMachineGroups(groupedEntries).forEach(g => groupedByKey.set(g.key, g));

    const emittedGroups = new Set();
    const items = [];
    rows.forEach((r, idx) => {
        if (!mantShouldGroupMachine(r)) {
            items.push({ type: 'row', row: r, rowIndex: idx });
            return;
        }

        const key = mantMachineKey(r, idx);
        const group = groupedByKey.get(key);
        if (group && !emittedGroups.has(key)) {
            items.push({ type: 'group', group });
            emittedGroups.add(key);
        }
    });
    return items;
}

function plural(n, one, many) {
    return String(n) + ' ' + (n === 1 ? one : many);
}

function groupEstadoLabel(g) {
    if (g.vencidas > 0) return plural(g.vencidas, 'vencida', 'vencidas');
    if (g.urgentes > 0) return plural(g.urgentes, 'proxima', 'proximas');
    return 'en plazo';
}

function mantGroupConsolIdx(key) {
    return 'group:' + String(key || '');
}

function mantGroupPendingRows(g) {
    // IMPORTANTE: para el marcado consolidado tenemos que devolver
    // SUB-TAREAS REALES (orden+tarea que existan en mant_plan), no las filas
    // del backend tal cual.
    //
    // El backend ya consolida RACK/PLATAFORMA/TROLEY: cada fila pendiente
    // r tiene r.consolidada=true, r.orden="CONSOL:<cod>", r.tarea="CONSOL"
    // y r.sub_tareas = [{orden, tarea, proxima_revision, ...}]. Si
    // mandáramos r al servidor tal cual el endpoint vería "CONSOL:..." y
    // fallaría (esa "tarea" virtual no existe en mant_plan).
    //
    // Aplanamos: por cada r pendiente, devolvemos sus sub_tareas reales.
    // Para filas no consolidadas (por si acaso entran aquí) las
    // convertimos a la misma shape para ser uniformes.
    const out = [];
    (g.rows || []).filter(r => !r.ya_marcada).forEach(r => {
        if (r.consolidada && Array.isArray(r.sub_tareas) && r.sub_tareas.length) {
            r.sub_tareas.forEach(s => out.push(s));
        } else {
            out.push({
                orden:            r.orden,
                tarea:            r.tarea,
                periodicidad:     r.periodicidad,
                desc_tarea:       r.desc_tarea,
                ultima_revision:  r.ultima_revision,
                proxima_revision: r.proxima_revision,
            });
        }
    });
    return out;
}

function renderMantGroupActionButton(g) {
    const pendingRows = mantGroupPendingRows(g);
    const count = pendingRows.length;
    const dataAttrs = [
        `data-row-idx="${escHtml(mantGroupConsolIdx(g.key))}"`,
        `data-orden="${escHtml('CONSOL:' + (g.cod_maquina_mant || g.key || ''))}"`,
        `data-tarea="CONSOL"`,
        `data-fecha-proxima="${escHtml(g.proxima_revision)}"`,
        `data-cod-maq="${escHtml(g.cod_maquina_mant)}"`,
        `data-desc-maq="${escHtml(g.desc_maquina)}"`,
        `data-desc-grupo="${escHtml('Revision completa')}"`,
        `data-periodicidad="${escHtml((g.periodicidades || []).join(', '))}"`,
        `data-desc-tarea="${escHtml('Revision completa de ' + plural(count || g.total, 'tarea', 'tareas'))}"`,
        `data-consolidada="1"`
    ].join(' ');
    if (!count) {
        return `<button type="button" class="mant-action-btn" ${dataAttrs} disabled style="background:#a3b8d1;color:#fff;opacity:0.85;cursor:default">Sin pendientes</button>`;
    }
    return `<button type="button" class="mant-action-btn" ${dataAttrs}>✓ Marcar las ${count} hechas</button>`;
}

function renderMantTaskActionButton(r, idx) {
    const subCount = Array.isArray(r.sub_tareas) ? r.sub_tareas.length : 0;
    const dataAttrs = [
        `data-row-idx="${idx}"`,
        `data-orden="${escHtml(r.orden)}"`,
        `data-tarea="${escHtml(r.tarea)}"`,
        `data-fecha-proxima="${escHtml(r.proxima_revision)}"`,
        `data-cod-maq="${escHtml(r.cod_maquina_mant)}"`,
        `data-desc-maq="${escHtml(r.desc_maquina)}"`,
        `data-desc-grupo="${escHtml(r.desc_grupo)}"`,
        `data-periodicidad="${escHtml(r.periodicidad)}"`,
        `data-desc-tarea="${escHtml(r.desc_tarea)}"`,
        `data-consolidada="${r.consolidada ? '1' : '0'}"`
    ].join(' ');
    const tipoMarca = String(r.tipo_marca || '');
    const esNoRealizada = r.ya_marcada && tipoMarca === 'no_realizada';
    const btnLabel = r.ya_marcada
        ? (esNoRealizada ? 'NO REALIZADA' : '✓ HECHA')
        : (r.consolidada ? `✓ Marcar las ${subCount} hechas` : '✓ Marcar hecha');
    const btnDisabled = r.ya_marcada ? 'disabled' : '';
    const btnStyle = r.ya_marcada
        ? (esNoRealizada
            ? 'background:#c8102e;color:#fff;opacity:0.85;cursor:default'
            : 'background:#10b981;color:#fff;opacity:0.85;cursor:default')
        : '';
    return `<button type="button" class="mant-action-btn" ${dataAttrs} ${btnDisabled} style="${btnStyle}">${btnLabel}</button>`;
}

function renderMantTaskRow(r, idx) {
    const cls = 'mant-row mant-row-' + r.estado
              + (r.consolidada ? ' mant-row-consolidada' : '')
              + (r.ya_marcada  ? ' mant-row-hecha'       : '');
    const diasCls = 'mant-dias mant-dias-' + r.estado;
    const subList = r.consolidada && Array.isArray(r.sub_tareas) && r.sub_tareas.length
        ? `<details class="mant-subtareas"><summary>Ver ${r.sub_tareas.length} tareas que incluye</summary><ul>` +
          r.sub_tareas.map(s => `<li><strong>${escHtml(s.tarea)}</strong>: ${escHtml(s.desc_tarea || '')}</li>`).join('') +
          `</ul></details>`
        : '';
    const subCount = Array.isArray(r.sub_tareas) ? r.sub_tareas.length : 0;
    const tareaCol = r.consolidada
        ? `<span class="mant-consol-badge" title="Revision consolidada">${subCount} tareas</span>`
        : `${escHtml(r.desc_grupo)}<br><span class="mant-cod">tarea ${escHtml(r.tarea)}</span>`;
    return `
        <tr class="${cls}">
            <td class="mant-fecha">${fmtFecha(r.proxima_revision)}</td>
            <td class="${diasCls}">${escHtml(fmtDias(r.dias_restantes))}</td>
            <td><span class="mant-pill mant-pill-${(r.periodicidad||'').toLowerCase()}">${escHtml(r.periodicidad)}</span></td>
            <td>${tareaCol}</td>
            <td class="mant-desc">${escHtml(r.desc_tarea)}${subList}</td>
            <td class="mant-fecha">${fmtFecha(r.ultima_revision)}</td>
        </tr>
    `;
}

function renderMantStandaloneTaskRow(r, idx) {
    const cls = 'mant-row mant-row-' + r.estado
              + (r.consolidada ? ' mant-row-consolidada' : '')
              + (r.ya_marcada  ? ' mant-row-hecha'       : '');
    const diasCls = 'mant-dias mant-dias-' + r.estado;
    const subList = r.consolidada && Array.isArray(r.sub_tareas) && r.sub_tareas.length
        ? `<details class="mant-subtareas"><summary>Ver ${r.sub_tareas.length} tareas que incluye</summary><ul>` +
          r.sub_tareas.map(s => `<li><strong>${escHtml(s.tarea)}</strong>: ${escHtml(s.desc_tarea || '')}</li>`).join('') +
          `</ul></details>`
        : '';
    const subCount = Array.isArray(r.sub_tareas) ? r.sub_tareas.length : 0;
    const tareaCol = r.consolidada
        ? `<span class="mant-consol-badge" title="Revision consolidada">${subCount} tareas</span>`
        : `${escHtml(r.desc_grupo)}<br><span class="mant-cod">tarea ${escHtml(r.tarea)}</span>`;
    const tiemposBtn = ` <button type="button" class="mant-tiempos-btn" data-cod-maq="${escHtml(r.cod_maquina_mant || '')}" data-desc-maq="${escHtml(r.desc_maquina || '')}" title="Ver tiempo total estimado de esta maquina">⏱</button>`;
    return `
        <tr class="${cls}">
            <td class="mant-fecha">${fmtFecha(r.proxima_revision)}</td>
            <td class="${diasCls}">${escHtml(fmtDias(r.dias_restantes))}</td>
            <td><strong>${escHtml(r.desc_maquina || r.cod_maquina_mant || 'Sin maquina')}</strong> <span class="mant-cod">(${escHtml(r.cod_maquina_mant || 'sin codigo')})</span>${tiemposBtn}</td>
            <td><span class="mant-pill mant-pill-${(r.periodicidad||'').toLowerCase()}">${escHtml(r.periodicidad)}</span></td>
            <td>${tareaCol}</td>
            <td class="mant-desc">${escHtml(r.desc_tarea)}${subList}</td>
            <td class="mant-fecha">${fmtFecha(r.ultima_revision)}</td>
            <td>${renderMantTaskActionButton(r, idx)}</td>
        </tr>
    `;
}

function renderMantMachineGroup(g) {
    const expanded = _mantMachinesExpanded.has(g.key);
    const stateCls = 'mant-dias mant-dias-' + g.estado;
    const perPills = g.periodicidades.length
        ? g.periodicidades.map(p => `<span class="mant-pill mant-pill-${p.toLowerCase()}">${escHtml(p)}</span>`).join(' ')
        : '<span class="mant-cod">—</span>';
    const detailRows = expanded
        ? `
            <tr class="mant-machine-detail-row">
                <td colspan="8">
                    <div class="mant-machine-detail">
                        <table class="mant-table mant-machine-detail-table">
                            <thead>
                                <tr>
                                    <th style="width:96px">Proxima</th>
                                    <th style="width:120px">Dias</th>
                                    <th style="width:110px">Periodicidad</th>
                                    <th>Tarea</th>
                                    <th>Descripcion</th>
                                    <th style="width:96px">Ultima</th>
                                </tr>
                            </thead>
                            <tbody>${g.rows.map((r, i) => renderMantTaskRow(r, g.rowIndexes[i])).join('')}</tbody>
                        </table>
                    </div>
                </td>
            </tr>
        `
        : '';
    const tiemposBtn = ` <button type="button" class="mant-tiempos-btn" data-cod-maq="${escHtml(g.cod_maquina_mant)}" data-desc-maq="${escHtml(g.desc_maquina)}" title="Ver tiempo total estimado de esta maquina">⏱</button>`;

    return `
        <tr class="mant-machine-group-row mant-machine-group-${g.estado}" data-group-key="${escHtml(g.key)}" aria-expanded="${expanded ? 'true' : 'false'}">
            <td class="mant-fecha">${fmtFecha(g.proxima_revision)}</td>
            <td class="${stateCls}">${escHtml(groupEstadoLabel(g))}</td>
            <td>
                <button type="button" class="mant-group-toggle" data-group-key="${escHtml(g.key)}" aria-label="${expanded ? 'Ocultar tareas' : 'Mostrar tareas'}">${expanded ? '-' : '+'}</button>
                <strong>${escHtml(g.desc_maquina)}</strong> <span class="mant-cod">(${escHtml(g.cod_maquina_mant || 'sin codigo')})</span>${tiemposBtn}
            </td>
            <td>${perPills}</td>
            <td>
                <span class="mant-grp-pill mant-grp-pill-total">${plural(g.total, 'tarea', 'tareas')}</span>
                <span class="mant-grp-pill mant-grp-pill-pend">${plural(g.pendientes, 'pendiente', 'pendientes')}</span>
                <span class="mant-grp-pill mant-grp-pill-ok">${plural(g.hechas, 'hecha', 'hechas')}</span>
                ${g.no_realizadas ? `<span class="mant-grp-pill mant-grp-pill-ko">${plural(g.no_realizadas, 'no realizada', 'no realizadas')}</span>` : ''}
            </td>
            <td class="mant-desc">Usa + para ver el detalle; el marcado se hace siempre en bloque</td>
            <td class="mant-fecha">${fmtFecha(g.ultima_revision)}</td>
            <td>${renderMantGroupActionButton(g)}</td>
        </tr>
        ${detailRows}
    `;
}

function renderMantTableItem(item) {
    if (item.type === 'group') return renderMantMachineGroup(item.group);
    return renderMantStandaloneTaskRow(item.row, item.rowIndex);
}

function toggleMantMachineGroup(key) {
    if (_mantMachinesExpanded.has(key)) _mantMachinesExpanded.delete(key);
    else _mantMachinesExpanded.add(key);
    renderTabla(_mantRowsActuales);
}

function renderTabla(rows) {
    const tb = $('#mant-tbody');
    if (!tb) return;
    _mantRowsActuales = rows || [];
    if (!rows.length) {
        tb.innerHTML = '<tr><td colspan="8" class="mant-empty">Sin tareas para los filtros seleccionados</td></tr>';
        return;
    }
    const items = buildMantTableItems(rows);
    const html = items.map(renderMantTableItem).join('');
    tb.innerHTML = html;
    tb.querySelectorAll('.mant-machine-group-row').forEach(row => {
        row.addEventListener('click', (e) => {
            if (e.target.closest('button, a, input, select, textarea')) return;
            toggleMantMachineGroup(row.dataset.groupKey);
        });
    });
    tb.querySelectorAll('.mant-group-toggle, .mant-group-open-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleMantMachineGroup(btn.dataset.groupKey);
        });
    });
    tb.querySelectorAll('.mant-tiempos-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            abrirModalTiempos(btn.dataset.codMaq, btn.dataset.descMaq);
        });
    });

    // Guardamos las sub-tareas por idx para usar al marcar consolidadas
    _consolRowsByIdx = {};
    rows.forEach((r, idx) => {
        if (r.consolidada) _consolRowsByIdx[idx] = r.sub_tareas || [];
    });
    items.forEach(item => {
        if (item.type === 'group') {
            _consolRowsByIdx[mantGroupConsolIdx(item.group.key)] = mantGroupPendingRows(item.group);
        }
    });

    // Wire up botones
    tb.querySelectorAll('.mant-action-btn').forEach(btn => {
        btn.addEventListener('click', () => abrirModalMarcar(btn.dataset));
    });
}

function buildMantOperatorAutoPayloads(markPayload) {
    if (!markPayload) return [];
    if (markPayload.consolidada && Array.isArray(markPayload.sub_tareas) && markPayload.sub_tareas.length) {
        return markPayload.sub_tareas
            .map(s => ({
                orden: s.orden,
                tarea: s.tarea,
                fecha_proxima_original: s.proxima_revision,
                tipo: 'completada',
            }))
            .filter(p => p.orden && p.tarea && p.fecha_proxima_original);
    }
    return [{
        orden: markPayload.orden,
        tarea: markPayload.tarea,
        fecha_proxima_original: markPayload.fecha_proxima_original,
        tipo: 'completada',
    }].filter(p => p.orden && p.tarea && p.fecha_proxima_original);
}

async function marcarDirectoOperario(markPayload) {
    const payloads = buildMantOperatorAutoPayloads(markPayload);
    if (!payloads.length) {
        showToast('No hay tareas para marcar', 'error');
        return;
    }

    showLoader(true);
    try {
        const headers = { 'Content-Type': 'application/json' };
        if (window.__CSRF_TOKEN) headers['X-CSRF-Token'] = window.__CSRF_TOKEN;
        const results = await Promise.allSettled(payloads.map(p =>
            fetch('../api/mant_marcar_hecha.php', { method: 'POST', headers, body: JSON.stringify(p) })
                .then(async r => {
                    const j = await r.json();
                    if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
                    return j;
                })
        ));
        const ok = results.filter(r => r.status === 'fulfilled').length;
        if (ok !== payloads.length) {
            const errMsg = results.find(r => r.status === 'rejected')?.reason?.message || 'error desconocido';
            throw new Error(`${ok}/${payloads.length} marcadas. Errores: ${errMsg}`);
        }
        showToast(payloads.length > 1 ? `${ok} tareas marcadas como hechas` : 'Revision marcada como hecha', 'success');
        _markPayload = null;
        cargarVista();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

function abrirModalMarcar(d) {
    // Resuelve sub_tareas si es fila consolidada (data-consolidada="1")
    const esConsol = d.consolidada === '1';
    const idx = d.rowIdx !== undefined ? d.rowIdx : null;
    const subTareas = (esConsol && idx !== null && _consolRowsByIdx[idx]) ? _consolRowsByIdx[idx] : null;
    console.log('[prox] abrirModalMarcar', { esConsol, idx, subTareas: subTareas ? subTareas.length : 0 });
    _markPayload = {
        orden: d.orden, tarea: d.tarea,
        fecha_proxima_original: d.fechaProxima,
        cod_maquina_mant: d.codMaq,
        desc_maquina: d.descMaq,
        desc_grupo: d.descGrupo,
        periodicidad: d.periodicidad,
        desc_tarea: d.descTarea,
        consolidada: esConsol,
        sub_tareas: subTareas, // [{orden, tarea, periodicidad, desc_tarea, proxima_revision}, …]
    };
    if (window.__IS_OPERARIO) {
        marcarDirectoOperario(_markPayload);
        return;
    }
    const consolNote = esConsol && subTareas
        ? `<br><span class="mant-cod" style="color:#c8102e;font-weight:bold">⛓ Acción consolidada: se marcarán las ${subTareas.length} tareas a la vez</span>`
        : '';
    const summary = `<strong>${d.descMaq || d.codMaq}</strong> · ${d.periodicidad}<br>` +
        `<span class="mant-cod">${d.descGrupo}</span><br>` +
        `${escHtml(d.descTarea)}${consolNote}<br>` +
        `<span class="mant-cod">Próxima programada: ${fmtFecha(d.fechaProxima)}</span>`;
    $('#mark-modal-summary').innerHTML = summary;

    // Render informativo de subtareas (solo si es consolidada). El marcado es
    // indivisible: todas las subtareas se registran en la misma accion.
    const subWrap = $('#mark-subtareas-wrap');
    const subList = $('#mark-subtareas-list');
    if (esConsol && subTareas && subList && subWrap) {
        subList.innerHTML = subTareas.map((s, i) => `
            <label class="mant-subtarea-check">
                <span class="mant-cod" style="min-width:24px;font-weight:700">${i + 1}</span>
                <span><strong>${escHtml(s.tarea || '')}</strong>
                    ${s.periodicidad ? `<span class="mant-pill mant-pill-${(s.periodicidad||'').toLowerCase()}" style="margin-left:6px">${escHtml(s.periodicidad)}</span>` : ''}
                    ${s.desc_tarea ? `<br><span class="mant-cod" style="font-size:11px">${escHtml(s.desc_tarea)}</span>` : ''}
                </span>
            </label>
        `).join('');
        subWrap.style.display = '';
    } else if (subWrap) {
        subWrap.style.display = 'none';
        if (subList) subList.innerHTML = '';
    }
    $('#mark-fecha').value = new Date().toISOString().substring(0, 10);
    // Hora actual prerrellenada (operario puede ajustarla si empezó antes)
    const horaInput = $('#mark-hora-inicio');
    if (horaInput) {
        const now = new Date();
        horaInput.value = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
    }
    $('#mark-observaciones').value = '';
    // Reset selector tipo a "completada" y motivo
    const tipoRadios = document.querySelectorAll('input[name="mark-tipo"]');
    tipoRadios.forEach(r => { r.checked = (r.value === 'completada'); });
    const motivoSel = $('#mark-motivo');
    if (motivoSel) motivoSel.value = '';
    _aplicarTipoMarcado('completada');

    // Desplegable Operario: solo los ACTIVOS del catálogo (8 operarios).
    // Se muestra el NOMBRE como texto y se guarda el CÓDIGO como value.
    // Si la API no devolvió operarios_activos (instancia antigua), caemos al
    // listado plano de códigos vistos en histórico.
    const sel = $('#mark-operario');
    sel.innerHTML = '<option value="">— Sin operario —</option>';
    const usaActivos = Array.isArray(_operariosActivos) && _operariosActivos.length > 0;
    const codigosActivos = usaActivos ? _operariosActivos.map(o => String(o.numero)) : [];
    if (usaActivos) {
        _operariosActivos.forEach(op => {
            const o = document.createElement('option');
            o.value = String(op.numero);
            o.textContent = op.nombre;
            sel.appendChild(o);
        });
    } else {
        _operariosKnown.forEach(op => {
            const o = document.createElement('option');
            o.value = op; o.textContent = op;
            sel.appendChild(o);
        });
    }
    // "Otro…" solo se ofrece como válvula de escape cuando NO hay catálogo
    // de activos (instancia antigua). Si el catálogo está cargado, forzamos
    // al usuario a elegir entre los 8 operarios oficiales.
    if (!usaActivos) {
        const otroOpt = document.createElement('option');
        otroOpt.value = '__otro__'; otroOpt.textContent = 'Otro…';
        sel.appendChild(otroOpt);
    }
    // Si el usuario ha entrado como OPERARIO con su numero, lo preseleccionamos
    // automáticamente — su numero es a la vez `mant_user` en sesión y `value`
    // del option en el dropdown. Para técnicos seguimos usando el último
    // operario marcado (localStorage) como sugerencia.
    let inicial = '';
    if (window.__IS_OPERARIO && window.__USER_NAME) {
        inicial = String(window.__USER_NAME);
    }
    if (!inicial) {
        try { inicial = localStorage.getItem(LS_LAST_OPERARIO) || ''; } catch(e) {}
    }
    const last = inicial;
    const lastEsActivo = usaActivos ? codigosActivos.includes(last) : _operariosKnown.includes(last);
    if (last && lastEsActivo) {
        sel.value = last;
    } else if (last) {
        sel.value = '__otro__';
        $('#mark-operario-otro-wrap').style.display = '';
        $('#mark-operario-otro').value = last;
    } else {
        sel.value = '';
        $('#mark-operario-otro-wrap').style.display = 'none';
        $('#mark-operario-otro').value = '';
    }

    const modal = $('#mark-modal');
    modal.style.display = '';
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(() => $('#mark-observaciones').focus(), 50);
}

function cerrarModalMarcar() {
    const modal = $('#mark-modal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    _markPayload = null;
}

// Ajusta visibilidad/etiqueta del modal según el tipo seleccionado.
// - 'completada': muestra hora de inicio, oculta motivo, label del botón verde.
// - 'no_realizada': oculta hora de inicio (no aplica), muestra motivo,
//   etiqueta del botón cambia a "Marcar como no realizada" en rojo.
function _aplicarTipoMarcado(tipo) {
    const isNo = tipo === 'no_realizada';
    const motivoWrap = $('#mark-motivo-wrap');
    const horaWrap   = $('#mark-hora-wrap');
    const btn        = $('#mark-modal-ok');
    if (motivoWrap) motivoWrap.style.display = isNo ? '' : 'none';
    if (horaWrap)   horaWrap.style.display   = isNo ? 'none' : '';
    if (btn) {
        btn.textContent = isNo ? '✕ Marcar como no realizada' : '✓ Marcar como hecha';
        btn.style.background = isNo ? '#c8102e' : '#10b981';
    }
}

async function confirmarMarcar() {
    if (!_markPayload) return;
    const sel = $('#mark-operario');
    let op = sel.value || '';
    if (op === '__otro__') op = ($('#mark-operario-otro').value || '').trim();
    // Validación: si hay operario, debe ser solo dígitos (código numérico).
    // No queremos nombres de operario en BD para preservar privacidad/anonimato.
    if (op !== '' && !/^\d+$/.test(op)) {
        showToast('El operario debe ser un código numérico (ej. 1004). Sin letras ni nombre.', 'error');
        return;
    }
    const obs = ($('#mark-observaciones').value || '').trim();
    const fechaInt = $('#mark-fecha').value || new Date().toISOString().substring(0, 10);
    const tipoSel = document.querySelector('input[name="mark-tipo"]:checked');
    const tipo = tipoSel ? tipoSel.value : 'completada';
    const motivo = $('#mark-motivo')?.value || '';
    const horaInicio = $('#mark-hora-inicio')?.value || '';

    if (tipo === 'no_realizada' && !motivo) {
        showToast('Selecciona el motivo de no realización', 'error');
        return;
    }

    // Construye los payloads. Si es consolidada, uno por cada sub-tarea
    // (la fecha_proxima_original es la propia de cada sub-tarea); si no,
    // un único payload con los datos del row.
    const payloads = [];
    const baseExtra = (() => {
        if (tipo === 'completada') {
            const x = { fecha_intervencion: fechaInt };
            if (horaInicio) x.hora_inicio = horaInicio;
            return x;
        }
        return { motivo_no_realizada: motivo };
    })();
    if (_markPayload.consolidada) {
        // Fila virtual (RACK/PLATAFORMA/TROLEY): SIEMPRE debe expandirse a
        // sus sub-tareas reales. Si no tenemos sub_tareas no podemos mandar
        // orden="CONSOL:..." al servidor porque esa fila no existe en
        // mant_plan — abortamos con un mensaje de cliente claro.
        if (!Array.isArray(_markPayload.sub_tareas) || !_markPayload.sub_tareas.length) {
            showToast(
                'No se pueden marcar: esta es una fila consolidada sin sub-tareas. '
                + 'Refresca la página (Ctrl+F5) y vuelve a intentarlo.',
                'error'
            );
            return;
        }
        _markPayload.sub_tareas.forEach(s => {
            payloads.push({
                orden: s.orden,
                tarea: s.tarea,
                fecha_proxima_original: s.proxima_revision,
                tipo,
                operario: op,
                observaciones: obs,
                visita_incompleta: false,
                ...baseExtra,
            });
        });
    } else {
        payloads.push({
            orden: _markPayload.orden,
            tarea: _markPayload.tarea,
            fecha_proxima_original: _markPayload.fecha_proxima_original,
            tipo,
            operario: op,
            observaciones: obs,
            ...baseExtra,
        });
    }

    showLoader(true);
    try {
        const headers = { 'Content-Type': 'application/json' };
        if (window.__CSRF_TOKEN) headers['X-CSRF-Token'] = window.__CSRF_TOKEN;
        // Envíos en paralelo: si falla alguno mostramos cuántos pasaron.
        const results = await Promise.allSettled(payloads.map(p =>
            fetch('../api/mant_marcar_hecha.php', { method: 'POST', headers, body: JSON.stringify(p) })
                .then(async r => {
                    const j = await r.json();
                    if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
                    return j;
                })
        ));
        const ok = results.filter(r => r.status === 'fulfilled').length;
        const ko = results.length - ok;
        if (ko > 0) {
            const errMsg = results.find(r => r.status === 'rejected')?.reason?.message || 'error desconocido';
            throw new Error(`${ok}/${results.length} marcadas. Errores: ${errMsg}`);
        }

        try { localStorage.setItem(LS_LAST_OPERARIO, op); } catch(e) {}
        const palabra = tipo === 'no_realizada' ? 'no realizadas' : 'marcadas como hechas';
        showToast(payloads.length > 1 ? `${ok} tareas ${palabra}` : (tipo === 'no_realizada' ? 'Marcada como no realizada' : 'Revisión marcada como hecha'), 'success');
        cerrarModalMarcar();
        cargarVista();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

async function cargarVista() {
    showLoader(true);
    try {
        const params = {
            fecha_desde: _mantFechaDesde,
            fecha_hasta: _mantFechaHasta,
        };
        if (_mantCodMaquina)   params.cod_maquina_mant = _mantCodMaquina;
        if (_mantPeriodicidad) params.periodicidad     = _mantPeriodicidad;
        if (_mantSoloVencidas) params.solo_vencidas    = 1;

        const d = await apiFetch('mant_proximas.php', params);

        populateMaquinas(d.maquinas || [], _mantCodMaquina);
        populatePeriodicidades(d.periodicidades || [], _mantPeriodicidad);
        _operariosKnown   = d.operarios || [];
        _operariosActivos = Array.isArray(d.operarios_activos) ? d.operarios_activos : [];

        // Validar filtros actuales contra los disponibles
        const okMaq = !_mantCodMaquina   || (d.maquinas || []).some(m => m.cod_maquina_mant === _mantCodMaquina);
        const okPer = !_mantPeriodicidad || (d.periodicidades || []).includes(_mantPeriodicidad);
        if (!okMaq) { _mantCodMaquina = '';   $('#machine-selector').value = ''; updateUrlParams({ cod_maquina_mant: '' }); }
        if (!okPer) { _mantPeriodicidad = ''; $('#periodicidad-selector').value = ''; updateUrlParams({ periodicidad: '' }); }

        const btn = $('#filter-clear');
        // Mostrar "× Quitar filtros" si hay cualquier filtro distinto del default.
        const defDesde = _addDaysIso(_hoyIso(), -90);
        const defHasta = _addDaysIso(_hoyIso(), 30);
        const hayRangoNoDefault = (_mantFechaDesde !== defDesde) || (_mantFechaHasta !== defHasta);
        if (btn) btn.style.display = (_mantCodMaquina || _mantPeriodicidad || _mantSoloVencidas || hayRangoNoDefault) ? '' : 'none';

        const scopeBits = [];
        if (_mantCodMaquina) {
            const m = (d.maquinas || []).find(x => x.cod_maquina_mant === _mantCodMaquina);
            scopeBits.push('máq: ' + (m ? m.desc_maquina : _mantCodMaquina));
        }
        if (_mantPeriodicidad) scopeBits.push('per: ' + _mantPeriodicidad);
        if (_mantSoloVencidas) scopeBits.push('solo vencidas');
        $('#header-scope').textContent = scopeBits.length ? '· ' + scopeBits.join(' · ') : '';

        // info-line refleja el rango activo + total RESULTANTE del filtro,
        // para que el gauge y los stats sean claramente del subconjunto filtrado.
        const filtroBits = [];
        if (_mantCodMaquina)   filtroBits.push('máquina');
        if (_mantPeriodicidad) filtroBits.push('periodicidad');
        if (_mantSoloVencidas) filtroBits.push('solo vencidas');
        const filtroTxt = filtroBits.length
            ? ' · filtro: ' + filtroBits.join(' + ')
            : ' · sin filtros';
        $('#info-line').textContent =
            'Hoy ' + fmtFecha(d.hoy) + ' · ' +
            fmtFecha(d.fecha_desde) + ' → ' + fmtFecha(d.fecha_hasta) +
            ' · ' + d.total + ' tareas en el rango' + filtroTxt;
        $('#stat-vencidas').textContent = d.vencidas;
        $('#stat-urgentes').textContent = d.urgentes;
        $('#stat-en-plazo').textContent = d.en_plazo;
        $('#stat-total').textContent    = d.total;
        $('#footer-actualizado').textContent = 'Fichero actualizado: ' + (d.fichero_actualizado || '—');

        renderGauge(d.pct_en_plazo);
        renderTopMaquinas(d.top_maquinas || []);
        renderTabla(d.rows || []);

    } catch (e) {
        showToast('Error: ' + e.message, 'error');
        const tb = $('#mant-tbody');
        if (tb) tb.innerHTML = '<tr><td colspan="7" class="mant-empty">Error cargando datos</td></tr>';
    } finally {
        showLoader(false);
    }
}

function onFechaDesdeChange() { _mantFechaDesde = $('#fecha-desde').value || _addDaysIso(_hoyIso(), -90); updateUrlParams({ fecha_desde: _mantFechaDesde }); cargarVista(); }
function onFechaHastaChange() { _mantFechaHasta = $('#fecha-hasta').value || _addDaysIso(_hoyIso(), 30); updateUrlParams({ fecha_hasta: _mantFechaHasta }); cargarVista(); }
function onMachineChange()      { _mantCodMaquina = $('#machine-selector').value || ''; updateUrlParams({ cod_maquina_mant: _mantCodMaquina }); cargarVista(); }
function onPeriodicidadChange() { _mantPeriodicidad = $('#periodicidad-selector').value || ''; updateUrlParams({ periodicidad: _mantPeriodicidad }); cargarVista(); }
function onSoloVencidasChange() { _mantSoloVencidas = $('#solo-vencidas').checked; updateUrlParams({ solo_vencidas: _mantSoloVencidas ? '1' : '' }); cargarVista(); }
function applyMantDateRange(range) {
    _mantFechaDesde = range.desde;
    _mantFechaHasta = range.hasta;
    $('#fecha-desde').value = _mantFechaDesde;
    $('#fecha-hasta').value = _mantFechaHasta;
    updateUrlParams({ fecha_desde: _mantFechaDesde, fecha_hasta: _mantFechaHasta });
    cargarVista();
}
function onCurrentWeekFilterClick() {
    applyMantDateRange(mantCurrentWeekRangeFrom(_formatIsoDateLocal(new Date())));
}
function onNextWeekFilterClick() {
    applyMantDateRange(mantNextWeekRangeFrom(_formatIsoDateLocal(new Date())));
}
function onClearFilters() {
    _mantFechaDesde = _addDaysIso(_hoyIso(), -90);
    _mantFechaHasta = _addDaysIso(_hoyIso(), 30);
    _mantCodMaquina = ''; _mantPeriodicidad = ''; _mantSoloVencidas = false;
    $('#fecha-desde').value = _mantFechaDesde;
    $('#fecha-hasta').value = _mantFechaHasta;
    $('#machine-selector').value = '';
    $('#periodicidad-selector').value = '';
    $('#solo-vencidas').checked = false;
    updateUrlParams({ fecha_desde: '', fecha_hasta: '', cod_maquina_mant: '', periodicidad: '', solo_vencidas: '' });
    cargarVista();
}


document.addEventListener('DOMContentLoaded', () => {
    // Default: 90 días atrás → 30 días adelante. El "atrás" garantiza que las
    // tareas ya vencidas se vean al cargar la vista; el "adelante" muestra
    // próximas. El usuario siempre puede acotar más.
    _mantFechaDesde    = getQueryParam('fecha_desde') || _addDaysIso(_hoyIso(), -90);
    _mantFechaHasta    = getQueryParam('fecha_hasta') || _addDaysIso(_hoyIso(), 30);
    _mantCodMaquina    = getQueryParam('cod_maquina_mant') || '';
    _mantPeriodicidad  = getQueryParam('periodicidad') || '';
    _mantSoloVencidas  = getQueryParam('solo_vencidas') === '1';

    $('#fecha-desde').value = _mantFechaDesde;
    $('#fecha-hasta').value = _mantFechaHasta;
    $('#solo-vencidas').checked = _mantSoloVencidas;

    $('#fecha-desde').addEventListener('change', onFechaDesdeChange);
    $('#fecha-hasta').addEventListener('change', onFechaHastaChange);
    $('#machine-selector').addEventListener('change', onMachineChange);
    $('#periodicidad-selector').addEventListener('change', onPeriodicidadChange);
    $('#solo-vencidas').addEventListener('change', onSoloVencidasChange);
    const currentWeekBtn = $('#filter-current-week'); if (currentWeekBtn) currentWeekBtn.addEventListener('click', onCurrentWeekFilterClick);
    const nextWeekBtn = $('#filter-next-week'); if (nextWeekBtn) nextWeekBtn.addEventListener('click', onNextWeekFilterClick);
    const c = $('#filter-clear'); if (c) c.addEventListener('click', onClearFilters);

    // ⏱ Tiempos por máquina (XLSX): único botón de descarga.
    // Pasa el rango de fechas actual para que el export refleje sólo las
    // tareas que cumplen plazo de revisión en ese intervalo.
    const tiemposBtn = $('#prox-tiempos-xlsx');
    if (tiemposBtn) tiemposBtn.addEventListener('click', () => {
        const p = new URLSearchParams();
        if (_mantFechaDesde) p.set('fecha_desde', _mantFechaDesde);
        if (_mantFechaHasta) p.set('fecha_hasta', _mantFechaHasta);
        const q = p.toString();
        window.location.href = '../api/mant_proximas_tiempos_export.php'
            + (q ? '?' + q : '');
    });
    // Modal tiempos
    $('#tiempos-modal-close')?.addEventListener('click', cerrarModalTiempos);
    $('#tiempos-modal-backdrop')?.addEventListener('click', cerrarModalTiempos);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const m = document.getElementById('tiempos-modal');
            if (m && m.style.display !== 'none') cerrarModalTiempos();
        }
    });

    // Modal marcar
    $('#mark-modal-close').addEventListener('click', cerrarModalMarcar);
    $('#mark-modal-cancel').addEventListener('click', cerrarModalMarcar);
    $('#mark-modal-backdrop').addEventListener('click', cerrarModalMarcar);
    $('#mark-modal-ok').addEventListener('click', confirmarMarcar);
    // Botones "Marcar todas / Desmarcar todas" para las sub-tareas consolidadas
    const subAll = $('#mark-subtareas-all');
    const subNone = $('#mark-subtareas-none');
    if (subAll)  subAll.addEventListener('click',  () => {
        document.querySelectorAll('#mark-subtareas-list input[type="checkbox"]').forEach(cb => cb.checked = true);
    });
    if (subNone) subNone.addEventListener('click', () => {
        document.querySelectorAll('#mark-subtareas-list input[type="checkbox"]').forEach(cb => cb.checked = false);
    });
    // Radios de tipo (realizada / no realizada): aplica visibilidad de
    // motivo + hora_inicio y cambia label/color del botón principal.
    document.querySelectorAll('input[name="mark-tipo"]').forEach(r => {
        r.addEventListener('change', () => _aplicarTipoMarcado(r.value));
    });
    $('#mark-operario').addEventListener('change', () => {
        const isOtro = $('#mark-operario').value === '__otro__';
        $('#mark-operario-otro-wrap').style.display = isOtro ? '' : 'none';
        if (isOtro) setTimeout(() => $('#mark-operario-otro').focus(), 50);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && $('#mark-modal').style.display !== 'none') cerrarModalMarcar();
    });

    initFiltros(cargarVista);
    cargarVista();
});

/* ════════════ Modal · Tiempos por máquina ════════════
   Abre un popup con el desglose de tiempos estimados de una máquina:
   - Plan completo (suma de todas las tareas activas)
   - Pendiente ahora (suma solo de las que tienen proxima_revision <= hoy)
   - Desglose por periodicidad
   - Lista detallada de tareas
═══════════════════════════════════════════════════════ */
function _fmtMin(min) {
    min = parseInt(min, 10) || 0;
    if (min <= 0) return '0 min';
    const h = Math.floor(min / 60);
    const m = min % 60;
    if (h === 0) return min + ' min';
    if (m === 0) return h + ' h';
    return h + ' h ' + m + ' min';
}

function abrirModalTiempos(codMaq, descMaq) {
    const modal = document.getElementById('tiempos-modal');
    if (!modal) return;
    document.getElementById('tiempos-modal-title').textContent = descMaq + ' · ' + codMaq;
    const body = document.getElementById('tiempos-modal-body');
    body.innerHTML = '<div class="mant-empty">Cargando…</div>';
    modal.style.display = '';
    modal.setAttribute('aria-hidden', 'false');

    // Propagamos el rango de fechas activo en la vista para que el popup
    // sólo cuente las tareas con próxima revisión dentro de ese intervalo.
    const params = new URLSearchParams({ cod_maquina_mant: codMaq });
    if (_mantFechaDesde) params.set('fecha_desde', _mantFechaDesde);
    if (_mantFechaHasta) params.set('fecha_hasta', _mantFechaHasta);

    fetch('../api/mant_proximas_tiempos.php?' + params.toString(), { cache: 'no-store' })
        .then(r => r.json())
        .then(j => {
            if (!j.ok) throw new Error(j.error || 'Error');
            const m = (j.data.maquinas && j.data.maquinas[0]) || null;
            if (!m) {
                body.innerHTML = '<div class="mant-empty">Sin tareas en el intervalo seleccionado para esta máquina.</div>';
                return;
            }
            body.innerHTML = _renderModalTiemposHtml(m, j.data);
        })
        .catch(e => {
            body.innerHTML = '<div class="mant-empty" style="color:#c8102e">Error: ' + escHtml(e.message || e) + '</div>';
        });
}

function _renderModalTiemposHtml(m, meta) {
    meta = meta || {};
    const usaIntervalo = !!meta.usa_intervalo;
    const rDesde = meta.rango_desde || '';
    const rHasta = meta.rango_hasta || '';

    const planH = _fmtMin(m.plan_total_minutos);
    const pendH = _fmtMin(m.pend_total_minutos);
    const pctPend = m.plan_total_minutos > 0
        ? Math.round(m.pend_total_minutos / m.plan_total_minutos * 100)
        : 0;

    // Botón de descarga XLSX de SOLO esta máquina, propagando el intervalo
    // activo para que el XLSX refleje el mismo ámbito que el popup.
    const xlsxParams = new URLSearchParams({ cod_maquina_mant: m.cod_maquina_mant });
    if (usaIntervalo) {
        xlsxParams.set('fecha_desde', rDesde);
        xlsxParams.set('fecha_hasta', rHasta);
    }
    const xlsxUrl = '../api/mant_proximas_tiempos_export.php?' + xlsxParams.toString();
    const downloadBtn = `
        <div style="display:flex;justify-content:flex-end;margin-bottom:10px">
            <a href="${xlsxUrl}" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:6px;background:#10b981;color:#fff;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:700;text-decoration:none;box-shadow:0 1px 3px rgba(16,185,129,0.30)">
                ⬇ Descargar XLSX de esta máquina
            </a>
        </div>
    `;

    // Banner del rango aplicado (sólo cuando hay intervalo)
    const banner = usaIntervalo ? `
        <div style="background:#fff8e6;border:1px solid #f0c674;padding:8px 12px;border-radius:6px;margin-bottom:10px;font-size:12px;color:#7a5b1b">
            <strong>Ámbito:</strong> tareas con próxima revisión entre
            <strong>${escHtml(fmtFecha(rDesde))}</strong> y
            <strong>${escHtml(fmtFecha(rHasta))}</strong>.
        </div>
    ` : '';

    // Etiquetas de las tarjetas — cambian según ámbito
    const tit1 = usaIntervalo ? 'En el intervalo' : 'Plan completo';
    const sub1 = usaIntervalo
        ? `${m.plan_total_tareas} tarea${m.plan_total_tareas === 1 ? '' : 's'} a revisar`
        : `${m.plan_total_tareas} tarea${m.plan_total_tareas === 1 ? '' : 's'} activa${m.plan_total_tareas === 1 ? '' : 's'}`;
    const tit2 = usaIntervalo ? 'Vencidas en el intervalo' : 'Pendiente ahora';
    const sub2 = usaIntervalo
        ? `${m.pend_total_tareas} tarea${m.pend_total_tareas === 1 ? '' : 's'} con plazo cumplido · ${pctPend}% del intervalo`
        : `${m.pend_total_tareas} tarea${m.pend_total_tareas === 1 ? '' : 's'} · ${pctPend}% del plan`;

    // Tarjetas resumen
    const stats = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
            <div style="background:#eef3f8;border-left:4px solid #1a4a7a;padding:10px 14px;border-radius:6px">
                <div style="font-size:11px;font-weight:700;color:#1a4a7a;text-transform:uppercase;letter-spacing:0.4px">${escHtml(tit1)}</div>
                <div style="font-size:22px;font-weight:800;color:#1a2d4a;margin-top:2px">${escHtml(planH)}</div>
                <div style="font-size:12px;color:#5b6f86">${sub1}</div>
            </div>
            <div style="background:#fbe6e7;border-left:4px solid #c8102e;padding:10px 14px;border-radius:6px">
                <div style="font-size:11px;font-weight:700;color:#c8102e;text-transform:uppercase;letter-spacing:0.4px">${escHtml(tit2)}</div>
                <div style="font-size:22px;font-weight:800;color:#c8102e;margin-top:2px">${escHtml(pendH)}</div>
                <div style="font-size:12px;color:#7c5050">${sub2}</div>
            </div>
        </div>
    `;

    // Desglose por periodicidad
    let perBlock = '';
    const pers = Object.keys(m.por_periodicidad || {});
    if (pers.length) {
        const rows = pers.map(p => {
            const d = m.por_periodicidad[p];
            return `<tr>
                <td style="font-weight:700;color:#c8102e">${escHtml(p)}</td>
                <td style="text-align:right">${d.plan_n}</td>
                <td style="text-align:right">${escHtml(_fmtMin(d.plan_min))}</td>
                <td style="text-align:right;color:${d.pend_n > 0 ? '#c8102e' : '#888'}">${d.pend_n}</td>
                <td style="text-align:right;color:${d.pend_min > 0 ? '#c8102e' : '#888'}">${escHtml(_fmtMin(d.pend_min))}</td>
            </tr>`;
        }).join('');
        perBlock = `
            <h4 style="font-size:13px;color:#1a2d4a;margin:14px 0 6px;text-transform:uppercase;letter-spacing:0.5px">Desglose por periodicidad</h4>
            <table class="mant-table tiempos-tbl-per" style="margin-bottom:14px">
                <thead><tr>
                    <th>Periodicidad</th>
                    <th>Tareas (plan)</th>
                    <th>Tiempo (plan)</th>
                    <th>Tareas (pend.)</th>
                    <th>Tiempo (pend.)</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    // Lista de tareas
    let tareasBlock = '';
    if (Array.isArray(m.tareas) && m.tareas.length) {
        const rows = m.tareas.map(t => {
            const pendCls = t.es_pendiente ? 'background:#fff5f5' : '';
            const pendBadge = t.es_pendiente
                ? '<span style="background:#c8102e;color:#fff;font-size:10px;padding:1px 6px;border-radius:8px;font-weight:700">PENDIENTE</span>'
                : '';
            const pxStr = t.proxima_revision ? fmtFecha(t.proxima_revision) : '—';
            return `<tr style="${pendCls}">
                <td><strong>${escHtml(t.tarea)}</strong></td>
                <td><span class="mant-pill mant-pill-${(t.periodicidad||'').toLowerCase()}">${escHtml(t.periodicidad)}</span></td>
                <td>${escHtml(t.desc_tarea)}</td>
                <td style="text-align:right;font-weight:600">${t.tiempo_min ? t.tiempo_min + ' min' : '—'}</td>
                <td style="text-align:center">${pxStr}</td>
                <td style="text-align:center">${pendBadge}</td>
            </tr>`;
        }).join('');
        tareasBlock = `
            <h4 style="font-size:13px;color:#1a2d4a;margin:14px 0 6px;text-transform:uppercase;letter-spacing:0.5px">Tareas activas (${m.tareas.length})</h4>
            <table class="mant-table tiempos-tbl-task">
                <thead><tr>
                    <th>Tarea</th>
                    <th>Periodicidad</th>
                    <th>Descripción</th>
                    <th>Estimado</th>
                    <th>Próxima</th>
                    <th>Estado</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    return downloadBtn + banner + stats + perBlock + tareasBlock;
}

function cerrarModalTiempos() {
    const modal = document.getElementById('tiempos-modal');
    if (!modal) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}
