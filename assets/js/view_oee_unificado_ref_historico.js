/* =========================================================
   Histórico por referencia (vista independiente)
   Bloque 1: filas por OF (totales sin fecha) — máquinas como chips
   Bloque 2: comparativa OFs × máquinas — chart vertical + chart NOK invertido + panel
   Popup: distribución horaria 00-23 de la OF (agregada)
   ========================================================= */

let _refHistList = [];
let _refHistAbort = null;
let _refHistLoadingList = false;
let _refHistOfs = [];       // ofs cargadas (block 1) — para popup
let _refHistRange = { desde: null, hasta: null, cod: null };
let _refHistChart = null;
let _refCompAbort = null;
let _refCompMachineCharts = []; // [{ up: ApexCharts, dn: ApexCharts }, …]

const REFHIST_STORE_KEY = 'kh_oee_unificado_refhist';

function loadRefHistState() {
    try {
        const raw = localStorage.getItem(REFHIST_STORE_KEY);
        if (raw) return JSON.parse(raw);
    } catch (e) {}
    return null;
}
function saveRefHistState(s) {
    try { localStorage.setItem(REFHIST_STORE_KEY, JSON.stringify(s)); } catch (e) {}
}

function refHistFmtUds(n) { return (Number(n) || 0).toLocaleString('es-ES'); }
function refHistFmtFecha(iso) {
    if (!iso) return '—';
    const [y, m, d] = String(iso).split('-');
    return `${d}/${m}/${y}`;
}
function refHistEscape(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// ───── Listado de referencias ─────

async function refHistLoadList() {
    if (_refHistLoadingList) return;
    _refHistLoadingList = true;
    try {
        const d = await apiFetch('oee_unificado_ref_lista.php');
        _refHistList = Array.isArray(d.refs) ? d.refs : [];
        refHistRenderList($('#ref-hist-search').value);
        const scope = $('#ref-hist-scope');
        if (scope && d.desde) {
            scope.textContent = `Solo referencias con producción desde ${refHistFmtFecha(d.desde)} (${_refHistList.length})`;
        }
        const cnt = Number(d.multi_count) || _refHistList.filter(r => Number(r.num_maquinas) > 1).length;
        const cntEl = $('#ref-hist-multi-count');
        if (cntEl) cntEl.textContent = refHistFmtUds(cnt);
    } catch (e) {
        const sel = $('#ref-hist-select');
        if (sel) sel.innerHTML = '<option value="">— Error cargando referencias —</option>';
        showToast('Error referencias: ' + e.message, 'error');
    } finally {
        _refHistLoadingList = false;
    }
}

function refHistRenderList(filter) {
    const sel = $('#ref-hist-select');
    if (!sel) return;
    const q = String(filter ?? '').toLowerCase().trim();
    const onlyMulti = !!$('#ref-hist-multi-cb')?.checked;
    const prev = sel.value;

    let list = _refHistList;
    if (onlyMulti) list = list.filter(r => Number(r.num_maquinas) > 1);
    if (q) list = list.filter(r =>
        String(r.cod_producto  ?? '').toLowerCase().includes(q) ||
        String(r.desc_producto ?? '').toLowerCase().includes(q));

    const placeholder = list.length
        ? `<option value="">— Elige una referencia (${list.length}) —</option>`
        : '<option value="">— Sin coincidencias —</option>';
    sel.innerHTML = placeholder + list.map(r => {
        const n = Number(r.num_maquinas) || 0;
        const tag = n > 1 ? ` [${n} máq]` : '';
        const cls = n > 1 ? ' class="opt-multi"' : '';
        return `<option value="${refHistEscape(r.cod_producto)}" title="${refHistEscape(r.cod_producto)} · ${n} máquina${n === 1 ? '' : 's'}"${cls}>${refHistEscape(r.desc_producto)}${tag}</option>`;
    }).join('');
    if (prev && Array.from(sel.options).some(o => o.value === prev)) {
        sel.value = prev;
    } else {
        sel.value = '';
    }
}

// ───── Rango fechas ─────

function refHistSetRange(kind) {
    const today = new Date();
    const ymd = (d) => d.toISOString().slice(0, 10);
    const desde = new Date(today);
    const hasta = new Date(today);
    if (kind === 'month')   desde.setMonth(today.getMonth() - 1);
    if (kind === '3months') desde.setMonth(today.getMonth() - 3);
    if (kind === '6months') desde.setMonth(today.getMonth() - 6);
    if (kind === 'year')    desde.setFullYear(today.getFullYear() - 1);
    $('#ref-hist-desde').value = ymd(desde);
    $('#ref-hist-hasta').value = ymd(hasta);
}

function refHistValidRange(desde, hasta) {
    if (!desde || !hasta) return false;
    if (desde > hasta) {
        showToast('La fecha "Desde" no puede ser posterior a "Hasta"', 'error');
        return false;
    }
    const diffDays = Math.round((new Date(hasta) - new Date(desde)) / (1000 * 60 * 60 * 24));
    if (diffDays > 366) {
        showToast('El rango máximo es de 1 año (366 días)', 'error');
        return false;
    }
    return true;
}

// ───── Carga + render ─────

async function refHistRecargar() {
    const cod   = $('#ref-hist-select').value;
    const desde = $('#ref-hist-desde').value;
    const hasta = $('#ref-hist-hasta').value;

    saveRefHistState({ cod, desde, hasta, q: $('#ref-hist-search').value || '' });

    const enable = Boolean(cod && desde && hasta);
    $('#btn-ref-hist-xlsx').disabled = !enable;
    $('#btn-ref-hist-pdf').disabled  = !enable;

    if (!enable) { refHistRenderEmpty(); refCompRenderEmpty(); return; }
    if (!refHistValidRange(desde, hasta)) { refHistRenderEmpty(); refCompRenderEmpty(); return; }

    _refHistRange = { desde, hasta, cod };

    if (_refHistAbort) _refHistAbort.abort();
    _refHistAbort = new AbortController();

    try {
        const d = await apiFetch('oee_unificado_ref_historico.php', {
            cod_producto: cod,
            fecha_desde:  desde,
            fecha_hasta:  hasta,
        }, _refHistAbort.signal);
        refHistRender(d);
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error histórico: ' + e.message, 'error');
        refHistRenderEmpty();
    }

    refCompRecargar(cod, desde, hasta);
}

function refHistRenderEmpty() {
    _refHistOfs = [];
    $('#ref-hist-resumen').style.display    = 'none';
    $('#ref-hist-tabla-wrap').style.display = 'none';
    $('#ref-hist-empty').style.display      = 'none';
    $('#ref-hist-tbody').innerHTML = '';
}

function refHistRender(d) {
    const ofs = Array.isArray(d.ofs) ? d.ofs : [];
    const tot = d.totales || {};
    _refHistOfs = ofs;

    $('#ref-hist-tot-ofs').textContent  = refHistFmtUds(tot.num_ofs);
    $('#ref-hist-tot-maqs').textContent = refHistFmtUds(tot.num_maquinas);
    $('#ref-hist-tot-dias').textContent = refHistFmtUds(tot.dias);
    $('#ref-hist-tot-ok').textContent   = refHistFmtUds(tot.unidades_ok);
    $('#ref-hist-tot-nok').textContent  = refHistFmtUds(tot.unidades_nok);
    $('#ref-hist-resumen').style.display = '';

    if (!ofs.length) {
        $('#ref-hist-tabla-wrap').style.display = 'none';
        $('#ref-hist-empty').style.display      = '';
        $('#ref-hist-tbody').innerHTML = '';
        return;
    }
    $('#ref-hist-empty').style.display      = 'none';
    $('#ref-hist-tabla-wrap').style.display = '';
    $('#ref-hist-tbody').innerHTML = ofs.map((of, idx) => {
        const codOf = String(of.cod_of ?? '');
        const dis   = (!codOf || codOf === '—') ? ' disabled aria-disabled="true"' : '';
        const ofTitle = `${refHistFmtUds(of.unidades_ok)} OK · ${refHistFmtUds(of.unidades_nok)} NOK · ${refHistFmtUds(of.uds_h)} uds/h — clic para ver desglose horario`;
        const ofChip = `<button type="button" class="of-chip" data-idx="${idx}"${dis} title="${refHistEscape(ofTitle)}">${refHistEscape(codOf || '—')}</button>`;
        const maqChips = (of.maquinas || []).map(m =>
            `<span class="of-maq-chip" title="OK: ${refHistFmtUds(m.unidades_ok)} · NOK: ${refHistFmtUds(m.unidades_nok)} · ${refHistFmtUds(m.uds_h)} uds/h">${refHistEscape(m.maquina)}</span>`
        ).join('');
        return `<tr>
            <td>${ofChip}</td>
            <td><div class="of-cell-chips">${maqChips}</div></td>
            <td class="num">${refHistFmtUds(of.num_dias)}</td>
            <td class="num">${refHistFmtUds(of.unidades_ok)}</td>
            <td class="num">${refHistFmtUds(of.unidades_nok)}</td>
        </tr>`;
    }).join('');
}

// ───── Comparativa: charts vertical OK + NOK invertido + panel ─────

async function refCompRecargar(cod, desde, hasta) {
    if (_refCompAbort) _refCompAbort.abort();
    _refCompAbort = new AbortController();
    try {
        const d = await apiFetch('oee_unificado_ref_comparativa.php', {
            cod_producto: cod,
            fecha_desde:  desde,
            fecha_hasta:  hasta,
        }, _refCompAbort.signal);
        refCompRender(d);
    } catch (e) {
        if (e.name === 'AbortError') return;
        showToast('Error comparativa: ' + e.message, 'error');
        refCompRenderEmpty();
    }
}

function refCompDestroyCharts() {
    _refCompMachineCharts.forEach(c => {
        if (c.up) { try { c.up.destroy(); } catch(e){} }
        if (c.dn) { try { c.dn.destroy(); } catch(e){} }
    });
    _refCompMachineCharts = [];
}

function refCompRenderEmpty() {
    refCompDestroyCharts();
    $('#ref-comp-machines').innerHTML = '';
    $('#ref-comp-overall').style.display = 'none';
    $('#ref-comp-empty').style.display = '';
}

function refCompRender(d) {
    refCompDestroyCharts();
    const ofs = Array.isArray(d.ofs) ? d.ofs : [];
    const maqs = Array.isArray(d.maquinas_distintas) ? d.maquinas_distintas : [];
    const stats = d.stats || {};
    const ranking = Array.isArray(d.maquina_ranking) ? d.maquina_ranking : [];

    if (!ofs.length || !maqs.length) {
        refCompRenderEmpty();
        return;
    }
    $('#ref-comp-empty').style.display = 'none';

    // Etiqueta "Mejor máquina del rango" (sumando todas las OFs)
    if (ranking.length > 0) {
        const top = ranking[0];
        const isOnly = ranking.length === 1;
        $('#ref-comp-overall-detail').innerHTML = isOnly
            ? `<strong>${refHistEscape(top.maquina)}</strong> es la única máquina que ha fabricado esta referencia en el rango ·
               ${refHistFmtUds(top.uds_h)} uds/h ·
               ${refHistFmtUds(top.unidades_ok)} OK en ${refHistFmtUds(top.horas)} h ·
               ${refHistFmtUds(top.num_ofs)} OF${top.num_ofs === 1 ? '' : 's'}`
            : `<strong>${refHistEscape(top.maquina)}</strong> con <strong>${refHistFmtUds(top.uds_h)} uds/h</strong> ·
               ${refHistFmtUds(top.unidades_ok)} OK en ${refHistFmtUds(top.horas)} h ·
               ${refHistFmtUds(top.num_ofs)} OF${top.num_ofs === 1 ? '' : 's'} ·
               ${(top.nok_pct || 0).toFixed(2)}% NOK`;

        // Ranking compacto: resto de máquinas con porcentaje relativo al mejor
        if (ranking.length > 1) {
            $('#ref-comp-overall-ranking').innerHTML = '<div class="ref-comp-overall-rank-title">Ranking completo:</div>' +
                ranking.map((m, i) => {
                    const pct = (m.pct_vs_best || 0).toFixed(1);
                    const cls = i === 0 ? 'is-best' : '';
                    return `<div class="ref-comp-overall-rank-item ${cls}">
                        <span class="rk-pos">${i + 1}${i === 0 ? ' 🏅' : ''}</span>
                        <span class="rk-name">${refHistEscape(m.maquina)}</span>
                        <span class="rk-val">${refHistFmtUds(m.uds_h)} uds/h</span>
                        <span class="rk-pct">${pct}% vs mejor</span>
                    </div>`;
                }).join('');
        } else {
            $('#ref-comp-overall-ranking').innerHTML = '';
        }
        $('#ref-comp-overall').style.display = '';
    } else {
        $('#ref-comp-overall').style.display = 'none';
    }

    // Para cada máquina: contenedor + 1 chart unificado + panel stats
    const container = $('#ref-comp-machines');
    container.innerHTML = maqs.map(m => {
        const safeId = String(m.cod_maquina).replace(/[^a-zA-Z0-9_-]/g, '_');
        return `
            <section class="ref-comp-machine" data-cod-maq="${refHistEscape(m.cod_maquina)}">
                <div class="ref-comp-machine-head">
                    <span class="ref-comp-machine-axis">MÁQUINA</span>
                    <h4 class="ref-comp-machine-name">${refHistEscape(m.maquina)}</h4>
                </div>
                <div class="ref-comp-machine-row">
                    <div class="ref-comp-machine-charts">
                        <div class="ref-comp-axis-label">piezas / hora</div>
                        <div id="cmp-chart-up-${safeId}" class="ref-comp-chart-half"></div>
                        <div class="ref-comp-axis-label">total piezas OK / NOK por OF</div>
                        <div id="cmp-chart-dn-${safeId}" class="ref-comp-chart-half"></div>
                    </div>
                    <aside class="ref-comp-machine-stats" id="cmp-stats-${safeId}"></aside>
                </div>
            </section>
        `;
    }).join('');

    // Render por máquina
    maqs.forEach(m => {
        const safeId = String(m.cod_maquina).replace(/[^a-zA-Z0-9_-]/g, '_');
        // Recolectar OFs en las que esta máquina tiene producción
        const cells = ofs.map(of => {
            const cell = (of.maquinas || []).find(x => x.cod_maquina === m.cod_maquina);
            return cell ? { cod_of: of.cod_of, ...cell } : null;
        }).filter(Boolean);

        if (!cells.length) {
            $('#cmp-chart-up-' + safeId).innerHTML = '<div class="ref-comp-machine-empty">Sin OFs para esta máquina</div>';
            $('#cmp-chart-dn-' + safeId).innerHTML = '';
            $('#cmp-stats-' + safeId).innerHTML = '';
            return;
        }

        const cats   = cells.map(c => c.cod_of);
        const udsH   = cells.map(c => Number(c.uds_h));
        const okArr  = cells.map(c => Number(c.unidades_ok));
        const nokArr = cells.map(c => Number(c.unidades_nok));

        // Stats agregados de la máquina
        const valid = udsH.filter(v => v > 0);
        const maxV  = valid.length ? Math.max(...valid) : 0;
        const minV  = valid.length ? Math.min(...valid) : 0;
        const avgV  = valid.length ? (valid.reduce((a, b) => a + b, 0) / valid.length) : 0;
        const totOk = okArr.reduce((a, b) => a + b, 0);
        const totNok = nokArr.reduce((a, b) => a + b, 0);
        const totAll = totOk + totNok;
        const okPct  = totAll > 0 ? (totOk / totAll * 100)  : 0;
        const nokPct = totAll > 0 ? (totNok / totAll * 100) : 0;

        // Antes era un único chart combinado: piezas/hora positivas arriba y OK+NOK
        // negativas abajo compartiendo eje Y. Como OK/NOK suelen estar 1-2 órdenes de
        // magnitud por encima de uds/h, las barras de piezas/hora quedaban aplastadas.
        // Ahora separamos en DOS charts apilados verticalmente, cada uno con su propia
        // escala Y → ambas series respiran y se leen sin esfuerzo.
        const COL_WIDTH = '38%';

        // === Chart superior: piezas/hora ===
        const optsUp = {
            chart: {
                type: 'bar', height: 320,
                toolbar: { show: false }, animations: { enabled: false }, fontFamily: 'Arial',
                parentHeightOffset: 0,
            },
            series: [{ name: 'piezas/hora', data: udsH }],
            colors: ['#3a6aa3'],
            plotOptions: { bar: { columnWidth: COL_WIDTH, borderRadius: 2, borderRadiusApplication: 'end' } },
            xaxis: {
                categories: cats,
                position: 'top',
                axisBorder: { show: true, color: '#1a2d4a' },
                labels: { style: { fontSize: '12px', fontWeight: 700, colors: '#1a2d4a' } },
            },
            yaxis: {
                forceNiceScale: true,
                min: 0,
                title: { text: 'piezas / hora', style: { fontSize: '11px', fontWeight: 700 } },
                labels: {
                    minWidth: 70, maxWidth: 70,
                    formatter: v => refHistFmtUds(Math.round(v)),
                },
            },
            dataLabels: {
                enabled: cats.length <= 14,
                formatter: v => v > 0 ? refHistFmtUds(Math.round(v)) : '',
                style: { fontSize: '10px', fontWeight: 700, colors: ['#ffffff'] },
            },
            legend: { show: false },
            grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
            tooltip: { y: { formatter: v => refHistFmtUds(Math.round(v)) + ' uds/h' } },
        };

        // === Chart inferior: OK + NOK apilados ===
        const optsDn = {
            chart: {
                type: 'bar', stacked: true, height: 320,
                toolbar: { show: false }, animations: { enabled: false }, fontFamily: 'Arial',
                parentHeightOffset: 0,
            },
            series: [
                { name: 'OK',  data: okArr  },
                { name: 'NOK', data: nokArr },
            ],
            colors: ['#10b981', '#c8102e'],
            plotOptions: { bar: { columnWidth: COL_WIDTH, borderRadius: 2, borderRadiusApplication: 'end' } },
            xaxis: {
                categories: cats,
                axisBorder: { show: true, color: '#1a2d4a' },
                labels: { style: { fontSize: '12px', fontWeight: 700, colors: '#1a2d4a' } },
            },
            yaxis: {
                forceNiceScale: true,
                min: 0,
                title: { text: 'unidades OK / NOK', style: { fontSize: '11px', fontWeight: 700 } },
                labels: {
                    minWidth: 70, maxWidth: 70,
                    formatter: v => refHistFmtUds(Math.round(v)),
                },
            },
            dataLabels: { enabled: false },
            legend: { position: 'bottom', fontSize: '11px' },
            grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
            tooltip: {
                shared: true, intersect: false,
                y: { formatter: v => refHistFmtUds(Math.round(v)) + ' uds' },
            },
        };

        const chartUp = new ApexCharts($('#cmp-chart-up-' + safeId), optsUp);
        const chartDn = new ApexCharts($('#cmp-chart-dn-' + safeId), optsDn);
        chartUp.render();
        chartDn.render();
        _refCompMachineCharts.push({ up: chartUp, dn: chartDn });

        // Panel stats lateral
        const fmt   = v => refHistFmtUds(v);
        const fmt2  = v => Number(v).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        $('#cmp-stats-' + safeId).innerHTML = `
            <div class="cmp-stat cmp-stat-max">
                <div class="cmp-stat-label">Máximo piezas/hora</div>
                <div class="cmp-stat-value">${fmt2(maxV)}</div>
                <div class="cmp-stat-sub">total OF's</div>
            </div>
            <div class="cmp-stat cmp-stat-min">
                <div class="cmp-stat-label">Mínimo piezas/hora</div>
                <div class="cmp-stat-value">${fmt2(minV)}</div>
                <div class="cmp-stat-sub">total OF's</div>
            </div>
            <div class="cmp-stat cmp-stat-avg">
                <div class="cmp-stat-label">Promedio piezas/hora</div>
                <div class="cmp-stat-value">${fmt2(avgV)}</div>
                <div class="cmp-stat-sub">total OF's</div>
            </div>
            <div class="cmp-stat-divider"></div>
            <div class="cmp-stat cmp-stat-ok">
                <div class="cmp-stat-label">Total piezas OK OF's</div>
                <div class="cmp-stat-value">${fmt(totOk)}</div>
            </div>
            <div class="cmp-stat cmp-stat-nok">
                <div class="cmp-stat-label">Total piezas NOK OF's</div>
                <div class="cmp-stat-value">${fmt(totNok)}</div>
            </div>
            <div class="cmp-stat cmp-stat-pct">
                <div class="cmp-stat-label">% OK · NOK</div>
                <div class="cmp-stat-pct-row">
                    <span class="cmp-stat-pct-ok">${fmt2(okPct)}%</span>
                    <span class="cmp-stat-pct-sep">/</span>
                    <span class="cmp-stat-pct-nok">${fmt2(nokPct)}%</span>
                </div>
            </div>
        `;
    });
}

// ───── Export ─────

function refHistExport(fmt) {
    const cod   = $('#ref-hist-select').value;
    const desde = $('#ref-hist-desde').value;
    const hasta = $('#ref-hist-hasta').value;
    if (!cod) { showToast('Selecciona una referencia', 'error'); return; }
    if (!refHistValidRange(desde, hasta)) return;
    const p = new URLSearchParams({ cod_producto: cod, fecha_desde: desde, fecha_hasta: hasta });
    const endpoint = fmt === 'pdf'
        ? 'oee_unificado_ref_historico_export_pdf.php'
        : 'oee_unificado_ref_historico_export.php';
    window.location.href = `${API_BASE}/${endpoint}?${p}`;
}

// ───── Popup horas por OF (agregado) ─────

function refHistOpenPopup(idx) {
    const of = _refHistOfs[idx];
    if (!of) return;
    const dlg = $('#of-popup');
    $('#of-popup-title').textContent = `OF ${of.cod_of}`;
    $('#of-popup-sub').innerHTML = `
        <strong>Referencia:</strong> ${refHistEscape(_refHistRange.cod || '—')}
        &nbsp;·&nbsp; <strong>Máquinas:</strong> ${(of.maquinas || []).length}
        &nbsp;·&nbsp; <strong>Días:</strong> ${refHistFmtUds(of.num_dias)}
    `;
    $('#of-popup-empty').style.display = 'none';
    if (_refHistChart) { try { _refHistChart.destroy(); } catch(e){} _refHistChart = null; }
    $('#of-popup-chart').innerHTML = '<div class="of-popup-loading">Cargando…</div>';
    dlg.hidden = false;

    apiFetch('oee_unificado_ref_historico_horas.php', {
        cod_of:       of.cod_of,
        cod_producto: _refHistRange.cod,
        fecha_desde:  _refHistRange.desde,
        fecha_hasta:  _refHistRange.hasta,
    }).then(d => {
        const horas = Array.isArray(d.horas) ? d.horas : [];
        const sumOk  = (d.totales?.unidades_ok)  || 0;
        const sumNok = (d.totales?.unidades_nok) || 0;
        $('#of-popup-sub').innerHTML += `&nbsp;·&nbsp; <strong>OK:</strong> ${refHistFmtUds(sumOk)} &nbsp;·&nbsp; <strong>NOK:</strong> ${refHistFmtUds(sumNok)}`;
        if (!sumOk && !sumNok) {
            $('#of-popup-chart').innerHTML = '';
            $('#of-popup-empty').style.display = '';
            return;
        }
        $('#of-popup-chart').innerHTML = '';
        const opts = {
            chart: { type: 'bar', height: 380, stacked: false, toolbar: { show: false }, animations: { enabled: false } },
            series: [
                { name: 'OK',  data: horas.map(h => h.unidades_ok)  },
                { name: 'NOK', data: horas.map(h => h.unidades_nok) },
            ],
            colors: ['#10b981', '#c8102e'],
            xaxis: {
                categories: horas.map(h => h.hora),
                title: { text: 'Hora del día' },
                labels: { rotate: 0, style: { fontSize: '12px', fontWeight: 600 } },
            },
            yaxis: { title: { text: 'Unidades' }, labels: { formatter: v => refHistFmtUds(Math.round(v)) } },
            plotOptions: { bar: { columnWidth: '75%' } },
            dataLabels: { enabled: false },
            tooltip: {
                x: { formatter: v => 'Hora ' + v + ':00' },
                y: { formatter: v => refHistFmtUds(v) },
            },
            legend: { position: 'top' },
        };
        _refHistChart = new ApexCharts($('#of-popup-chart'), opts);
        _refHistChart.render();
    }).catch(e => {
        $('#of-popup-chart').innerHTML = '';
        $('#of-popup-empty').textContent = 'Error: ' + e.message;
        $('#of-popup-empty').style.display = '';
    });
}

function refHistClosePopup() {
    const dlg = $('#of-popup');
    if (!dlg) return;
    dlg.hidden = true;
    if (_refHistChart) { try { _refHistChart.destroy(); } catch(e){} _refHistChart = null; }
    $('#of-popup-chart').innerHTML = '';
}

// ───── Init ─────

document.addEventListener('DOMContentLoaded', () => {
    const saved = loadRefHistState();
    if (saved && saved.desde && saved.hasta) {
        $('#ref-hist-desde').value = saved.desde;
        $('#ref-hist-hasta').value = saved.hasta;
        if (saved.q) $('#ref-hist-search').value = saved.q;
    } else {
        refHistSetRange('month');
    }

    refHistLoadList().then(() => {
        if (saved && saved.cod) {
            const sel = $('#ref-hist-select');
            if (Array.from(sel.options).some(o => o.value === saved.cod)) {
                sel.value = saved.cod;
                refHistRecargar();
            }
        }
    });

    $('#ref-hist-search').addEventListener('input', (e) => refHistRenderList(e.target.value));
    $('#ref-hist-multi-cb').addEventListener('change', () => refHistRenderList($('#ref-hist-search').value));
    $('#ref-hist-select').addEventListener('change', refHistRecargar);
    $('#ref-hist-desde').addEventListener('change',  refHistRecargar);
    $('#ref-hist-hasta').addEventListener('change',  refHistRecargar);
    document.querySelectorAll('.ref-hist-quick').forEach(btn => {
        btn.addEventListener('click', () => {
            refHistSetRange(btn.dataset.range);
            refHistRecargar();
        });
    });
    $('#btn-ref-hist-xlsx').addEventListener('click', () => refHistExport('xlsx'));
    $('#btn-ref-hist-pdf').addEventListener('click',  () => refHistExport('pdf'));

    // Click en chip OF → popup horario agregado
    $('#ref-hist-tbody').addEventListener('click', (e) => {
        const b = e.target.closest('.of-chip');
        if (!b || b.disabled) return;
        const idx = parseInt(b.dataset.idx, 10);
        if (!Number.isNaN(idx)) refHistOpenPopup(idx);
    });

    $('#of-popup-close').addEventListener('click', refHistClosePopup);
    $('#of-popup .of-popup-overlay').addEventListener('click', refHistClosePopup);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !$('#of-popup').hidden) refHistClosePopup();
    });
});
