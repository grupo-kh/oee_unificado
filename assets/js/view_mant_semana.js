/* Vista Mantenimiento · Preventivos previstos por semana */

let _semDesde = '';
let _semHasta = '';
let _semCodMaquina = '';
let _semPeriodicidad = '';
let _semCache = null;
let _operariosKnown = [];
let _markPayload = null;
let _pendPayload = null;
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

function isoToday() {
    return new Date().toISOString().substring(0, 10);
}
function addDaysIso(iso, days) {
    const t = new Date(iso + 'T00:00:00').getTime() + days * 86400000;
    return new Date(t).toISOString().substring(0, 10);
}
function mondayOfWeek(iso) {
    const d = new Date(iso + 'T00:00:00');
    const dow = d.getDay(); // 0=dom 1=lun … 6=sab
    const offsetToMon = (dow === 0) ? -6 : (1 - dow);
    return addDaysIso(iso, offsetToMon);
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

function rowDataAttrs(t, g) {
    return [
        `data-orden="${escHtml(t.orden)}"`,
        `data-tarea="${escHtml(t.tarea)}"`,
        `data-fecha-proxima="${escHtml(t.proxima_revision)}"`,
        `data-cod-maq="${escHtml(g.cod_maquina_mant)}"`,
        `data-desc-maq="${escHtml(g.desc_maquina)}"`,
        `data-desc-grupo="${escHtml(t.desc_grupo)}"`,
        `data-periodicidad="${escHtml(t.periodicidad)}"`,
        `data-desc-tarea="${escHtml(t.desc_tarea)}"`,
        `data-pendiente="${t.is_pendiente ? '1' : '0'}"`
    ].join(' ');
}

function renderGroups(groups) {
    const wrap = $('#groups-wrap');
    if (!wrap) return;
    if (!groups || !groups.length) {
        wrap.innerHTML = '<div class="mant-empty-block">Sin tareas previstas para los filtros seleccionados</div>';
        return;
    }
    const html = groups.map(g => {
        const subt = [];
        if (g.pendientes) subt.push(`<span class="mant-grp-pill mant-grp-pill-pend">${g.pendientes} pendiente${g.pendientes !== 1 ? 's' : ''}</span>`);
        if (g.vencidas)   subt.push(`<span class="mant-grp-pill mant-grp-pill-venc">${g.vencidas} vencida${g.vencidas !== 1 ? 's' : ''}</span>`);
        if (g.urgentes)   subt.push(`<span class="mant-grp-pill mant-grp-pill-urg">${g.urgentes} urgente${g.urgentes !== 1 ? 's' : ''}</span>`);
        if (g.en_plazo)   subt.push(`<span class="mant-grp-pill mant-grp-pill-enp">${g.en_plazo} en plazo</span>`);

        const filas = g.tareas.map(t => {
            const cls = ['mant-row', 'mant-row-' + t.estado];
            if (t.is_pendiente) cls.push('mant-row-pendiente');
            if (t.fuera_de_rango) cls.push('mant-row-fuera-rango');
            const diasCls = 'mant-dias mant-dias-' + t.estado;
            const attrs = rowDataAttrs(t, g);

            const flag = t.is_pendiente
                ? `<span class="mant-pendiente-flag" title="Pendiente de revisar${t.pendiente_nota ? ' · ' + t.pendiente_nota.replace(/"/g,'&quot;') : ''}">✖</span>`
                : '';
            const fueraRangoBadge = t.fuera_de_rango
                ? `<span class="mant-fuera-rango-badge" title="Fuera del rango seleccionado">fuera de rango</span>`
                : '';

            // El operario solo puede registrar fechas de revisión (Hecha).
            // El botón "Pendiente" es de gestión y queda oculto.
            const btnPendiente = window.__IS_OPERARIO
                ? ''
                : (t.is_pendiente
                    ? `<button type="button" class="mant-action-btn mant-action-quitar-pendiente role-tecnico-only" ${attrs}>✓ Revisada</button>`
                    : `<button type="button" class="mant-action-btn mant-action-pendiente role-tecnico-only" ${attrs}>🚩 Pendiente</button>`);
            const btnHecha = `<button type="button" class="mant-action-btn mant-action-hecha" ${attrs}>✓ Hecha</button>`;

            return `
                <tr class="${cls.join(' ')}">
                    <td class="mant-flag-cell">${flag}</td>
                    <td class="mant-fecha">${fmtFecha(t.proxima_revision)} ${fueraRangoBadge}</td>
                    <td class="${diasCls}">${escHtml(fmtDias(t.dias_restantes))}</td>
                    <td><span class="mant-pill mant-pill-${(t.periodicidad || '').toLowerCase()}">${escHtml(t.periodicidad)}</span></td>
                    <td>${escHtml(t.desc_grupo)}<br><span class="mant-cod">tarea ${escHtml(t.tarea)}</span></td>
                    <td class="mant-desc">${escHtml(t.desc_tarea)}</td>
                    <td class="mant-fecha">${fmtFecha(t.ultima_revision)}</td>
                    <td class="mant-actions-cell mant-no-print">${btnHecha} ${btnPendiente}</td>
                </tr>
            `;
        }).join('');

        return `
            <div class="mant-group-card mant-group-checked" data-cod-maq="${escHtml(g.cod_maquina_mant)}">
                <div class="mant-group-header">
                    <div class="mant-group-title">
                        <label class="mant-group-check-label mant-no-print" title="Incluir esta máquina al imprimir">
                            <input type="checkbox" class="mant-group-check" checked>
                        </label>
                        <strong>${escHtml(g.desc_maquina)}</strong>
                        <span class="mant-cod">(${escHtml(g.cod_maquina_mant)})</span>
                    </div>
                    <div class="mant-group-summary">
                        <span class="mant-grp-total">${g.total} tarea${g.total !== 1 ? 's' : ''}</span>
                        ${subt.join(' ')}
                    </div>
                </div>
                <div class="mant-table-wrap">
                    <table class="mant-table mant-table-zebra">
                        <thead>
                            <tr>
                                <th style="width:38px"></th>
                                <th style="width:140px">Próxima</th>
                                <th style="width:110px">Días</th>
                                <th style="width:110px">Periodicidad</th>
                                <th>Grupo / Tarea</th>
                                <th>Descripción</th>
                                <th style="width:96px">Última</th>
                                <th style="width:200px" class="mant-no-print">Acción</th>
                            </tr>
                        </thead>
                        <tbody>${filas}</tbody>
                    </table>
                </div>
            </div>
        `;
    }).join('');
    wrap.innerHTML = html;

    wrap.querySelectorAll('.mant-action-hecha').forEach(btn => {
        btn.addEventListener('click', () => abrirModalMarcar(btn.dataset));
    });
    wrap.querySelectorAll('.mant-action-pendiente').forEach(btn => {
        btn.addEventListener('click', () => abrirModalPendiente(btn.dataset));
    });
    wrap.querySelectorAll('.mant-action-quitar-pendiente').forEach(btn => {
        btn.addEventListener('click', () => quitarPendiente(btn.dataset));
    });
    wrap.querySelectorAll('.mant-group-check').forEach(chk => {
        chk.addEventListener('change', () => {
            const card = chk.closest('.mant-group-card');
            if (card) card.classList.toggle('mant-group-checked', chk.checked);
            updateSelectAllState();
        });
    });
    updateSelectAllState();
}

function updateSelectAllState() {
    const all = document.querySelectorAll('.mant-group-check');
    const checkedCount = document.querySelectorAll('.mant-group-check:checked').length;
    const master = $('#print-select-all');
    const count  = $('#print-select-count');
    if (count) count.textContent = checkedCount + '/' + all.length;
    if (!master) return;
    if (all.length === 0) {
        master.checked = false; master.indeterminate = false; master.disabled = true;
    } else {
        master.disabled = false;
        if (checkedCount === all.length)      { master.checked = true;  master.indeterminate = false; }
        else if (checkedCount === 0)          { master.checked = false; master.indeterminate = false; }
        else                                  { master.checked = false; master.indeterminate = true;  }
    }
}

function onSelectAllToggle() {
    const checked = $('#print-select-all').checked;
    document.querySelectorAll('.mant-group-check').forEach(chk => {
        chk.checked = checked;
        const card = chk.closest('.mant-group-card');
        if (card) card.classList.toggle('mant-group-checked', checked);
    });
    updateSelectAllState();
}

async function cargarVista() {
    showLoader(true);
    try {
        const params = { desde: _semDesde, hasta: _semHasta };
        if (_semCodMaquina)   params.cod_maquina_mant = _semCodMaquina;
        if (_semPeriodicidad) params.periodicidad     = _semPeriodicidad;

        const d = await apiFetch('mant_semana.php', params);
        _semCache = d;
        _operariosKnown = d.operarios || [];

        populateMaquinas(d.maquinas || [], _semCodMaquina);
        populatePeriodicidades(d.periodicidades || [], _semPeriodicidad);

        const okMaq = !_semCodMaquina   || (d.maquinas || []).some(m => m.cod_maquina_mant === _semCodMaquina);
        const okPer = !_semPeriodicidad || (d.periodicidades || []).includes(_semPeriodicidad);
        if (!okMaq) { _semCodMaquina = '';   $('#machine-selector').value = ''; updateUrlParams({ cod_maquina_mant: '' }); }
        if (!okPer) { _semPeriodicidad = ''; $('#periodicidad-selector').value = ''; updateUrlParams({ periodicidad: '' }); }

        const btn = $('#filter-clear');
        if (btn) btn.style.display = (_semCodMaquina || _semPeriodicidad) ? '' : 'none';

        const scopeBits = [];
        if (_semCodMaquina) {
            const m = (d.maquinas || []).find(x => x.cod_maquina_mant === _semCodMaquina);
            scopeBits.push('máq: ' + (m ? m.desc_maquina : _semCodMaquina));
        }
        if (_semPeriodicidad) scopeBits.push('per: ' + _semPeriodicidad);
        $('#header-scope').textContent = scopeBits.length ? '· ' + scopeBits.join(' · ') : '';

        const t = d.totales || {};
        const rangoTxt = fmtFecha(d.desde) + ' → ' + fmtFecha(d.hasta) + ' (' + d.dias_rango + ' día' + (d.dias_rango !== 1 ? 's' : '') + ')';
        $('#info-line').textContent = 'Hoy ' + fmtFecha(d.hoy) + ' · ' + rangoTxt + ' · ' + (t.total || 0) + ' tareas';
        $('#stat-pendientes').textContent = t.pendientes ?? 0;
        $('#stat-vencidas').textContent   = t.vencidas ?? 0;
        $('#stat-urgentes').textContent   = t.urgentes ?? 0;
        $('#stat-en-plazo').textContent   = t.en_plazo ?? 0;
        $('#stat-total').textContent      = t.total ?? 0;
        $('#stat-maquinas').textContent   = t.maquinas ?? 0;
        $('#footer-actualizado').textContent = 'Fichero actualizado: ' + (d.fichero_actualizado || '—');

        const ph = $('#print-range');
        if (ph) {
            const parts = [rangoTxt];
            if (_semCodMaquina) {
                const m = (d.maquinas || []).find(x => x.cod_maquina_mant === _semCodMaquina);
                parts.push('Máquina: ' + (m ? m.desc_maquina + ' (' + m.cod_maquina_mant + ')' : _semCodMaquina));
            }
            if (_semPeriodicidad) parts.push('Periodicidad: ' + _semPeriodicidad);
            ph.innerHTML = parts.map(escHtml).join(' · ');
        }

        renderGroups(d.groups || []);

    } catch (e) {
        showToast('Error: ' + e.message, 'error');
        const wrap = $('#groups-wrap');
        if (wrap) wrap.innerHTML = '<div class="mant-empty-block">Error cargando datos</div>';
    } finally {
        showLoader(false);
    }
}

/* ============== Modal · Marcar como hecha ============== */
function abrirModalMarcar(d) {
    _markPayload = {
        orden: d.orden, tarea: d.tarea,
        fecha_proxima_original: d.fechaProxima,
        cod_maquina_mant: d.codMaq,
        desc_maquina: d.descMaq,
        desc_grupo: d.descGrupo,
        periodicidad: d.periodicidad,
        desc_tarea: d.descTarea
    };
    const summary = `<strong>${escHtml(d.descMaq || d.codMaq)}</strong> · ${escHtml(d.periodicidad)}<br>` +
        `<span class="mant-cod">${escHtml(d.descGrupo)}</span><br>` +
        `${escHtml(d.descTarea)}<br>` +
        `<span class="mant-cod">Próxima programada: ${fmtFecha(d.fechaProxima)}</span>`;
    $('#mark-modal-summary').innerHTML = summary;
    $('#mark-fecha').value = isoToday();
    $('#mark-observaciones').value = '';

    const sel = $('#mark-operario');
    sel.innerHTML = '<option value="">— Sin operario —</option>';
    _operariosKnown.forEach(op => {
        const o = document.createElement('option'); o.value = op; o.textContent = op;
        sel.appendChild(o);
    });
    const otroOpt = document.createElement('option'); otroOpt.value = '__otro__'; otroOpt.textContent = 'Otro…';
    sel.appendChild(otroOpt);
    const last = (function() { try { return localStorage.getItem(LS_LAST_OPERARIO) || ''; } catch(e) { return ''; } })();
    if (last && _operariosKnown.includes(last)) {
        sel.value = last;
        $('#mark-operario-otro-wrap').style.display = 'none';
        $('#mark-operario-otro').value = '';
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

async function confirmarMarcar() {
    if (!_markPayload) return;
    const sel = $('#mark-operario');
    let op = sel.value || '';
    if (op === '__otro__') op = ($('#mark-operario-otro').value || '').trim();
    const obs = ($('#mark-observaciones').value || '').trim();
    const fechaInt = $('#mark-fecha').value || isoToday();

    showLoader(true);
    try {
        const body = JSON.stringify({
            orden: _markPayload.orden,
            tarea: _markPayload.tarea,
            fecha_proxima_original: _markPayload.fecha_proxima_original,
            fecha_intervencion: fechaInt,
            operario: op,
            observaciones: obs
        });
        const headers = { 'Content-Type': 'application/json' };
        if (window.__CSRF_TOKEN) headers['X-CSRF-Token'] = window.__CSRF_TOKEN;
        const resp = await fetch('../api/mant_marcar_hecha.php', {
            method: 'POST',
            headers,
            body
        });
        const json = await resp.json();
        if (!resp.ok || !json.ok) throw new Error(json.error || `HTTP ${resp.status}`);

        try { if (op) localStorage.setItem(LS_LAST_OPERARIO, op); } catch(e) {}
        showToast('Revisión marcada como hecha', 'success');
        cerrarModalMarcar();
        cargarVista();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

/* ============== Modal · Marcar pendiente ============== */
function abrirModalPendiente(d) {
    _pendPayload = {
        orden: d.orden, tarea: d.tarea,
        fecha_proxima_original: d.fechaProxima,
        desc_maquina: d.descMaq,
        cod_maquina_mant: d.codMaq,
        periodicidad: d.periodicidad,
        desc_grupo: d.descGrupo,
        desc_tarea: d.descTarea
    };
    const summary = `<strong>${escHtml(d.descMaq || d.codMaq)}</strong> · ${escHtml(d.periodicidad)}<br>` +
        `<span class="mant-cod">${escHtml(d.descGrupo)}</span><br>` +
        `${escHtml(d.descTarea)}<br>` +
        `<span class="mant-cod">Próxima programada: ${fmtFecha(d.fechaProxima)}</span>`;
    $('#pend-modal-summary').innerHTML = summary;
    $('#pend-nota').value = '';
    const modal = $('#pend-modal');
    modal.style.display = '';
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(() => $('#pend-nota').focus(), 50);
}
function cerrarModalPendiente() {
    const modal = $('#pend-modal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    _pendPayload = null;
}
async function confirmarPendiente() {
    if (!_pendPayload) return;
    const nota = ($('#pend-nota').value || '').trim();
    showLoader(true);
    try {
        const body = JSON.stringify({
            orden: _pendPayload.orden,
            tarea: _pendPayload.tarea,
            fecha_proxima_original: _pendPayload.fecha_proxima_original,
            pendiente: 1,
            nota
        });
        const headers = { 'Content-Type': 'application/json' };
        if (window.__CSRF_TOKEN) headers['X-CSRF-Token'] = window.__CSRF_TOKEN;
        const resp = await fetch('../api/mant_set_pendiente.php', {
            method: 'POST',
            headers,
            body
        });
        const json = await resp.json();
        if (!resp.ok || !json.ok) throw new Error(json.error || `HTTP ${resp.status}`);
        showToast('Tarea marcada como pendiente', 'success');
        cerrarModalPendiente();
        cargarVista();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

async function quitarPendiente(d) {
    if (!confirm('¿Quitar la marca de pendiente? La tarea volverá al flujo normal según su fecha próxima.')) return;
    showLoader(true);
    try {
        const body = JSON.stringify({
            orden: d.orden,
            tarea: d.tarea,
            fecha_proxima_original: d.fechaProxima,
            pendiente: 0
        });
        const headers = { 'Content-Type': 'application/json' };
        if (window.__CSRF_TOKEN) headers['X-CSRF-Token'] = window.__CSRF_TOKEN;
        const resp = await fetch('../api/mant_set_pendiente.php', {
            method: 'POST',
            headers,
            body
        });
        const json = await resp.json();
        if (!resp.ok || !json.ok) throw new Error(json.error || `HTTP ${resp.status}`);
        showToast('Pendiente revisado · check rojo retirado', 'success');
        cargarVista();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

/* ============== Filtros ============== */
function onDesdeChange() {
    _semDesde = $('#fecha-desde').value || isoToday();
    if (_semHasta < _semDesde) {
        _semHasta = _semDesde;
        $('#fecha-hasta').value = _semHasta;
    }
    updateUrlParams({ desde: _semDesde, hasta: _semHasta });
    cargarVista();
}
function onHastaChange() {
    _semHasta = $('#fecha-hasta').value || _semDesde;
    if (_semHasta < _semDesde) {
        _semHasta = _semDesde;
        $('#fecha-hasta').value = _semHasta;
    }
    updateUrlParams({ hasta: _semHasta });
    cargarVista();
}
function onMachineChange()      { _semCodMaquina = $('#machine-selector').value || ''; updateUrlParams({ cod_maquina_mant: _semCodMaquina }); cargarVista(); }
function onPeriodicidadChange() { _semPeriodicidad = $('#periodicidad-selector').value || ''; updateUrlParams({ periodicidad: _semPeriodicidad }); cargarVista(); }
function onClearFilters() {
    _semCodMaquina = ''; _semPeriodicidad = '';
    $('#machine-selector').value = '';
    $('#periodicidad-selector').value = '';
    updateUrlParams({ cod_maquina_mant: '', periodicidad: '' });
    cargarVista();
}
function onQuickRange(range) {
    const today = isoToday();
    if (range === 'week') {
        _semDesde = mondayOfWeek(today);
        _semHasta = addDaysIso(_semDesde, 6);
    } else {
        const n = parseInt(range, 10) || 7;
        _semDesde = today;
        _semHasta = addDaysIso(today, n - 1);
    }
    $('#fecha-desde').value = _semDesde;
    $('#fecha-hasta').value = _semHasta;
    updateUrlParams({ desde: _semDesde, hasta: _semHasta });
    cargarVista();
}

/* ============== Export CSV ============== */
function exportCsv() {
    if (!_semCache || !_semCache.groups) {
        showToast('Sin datos para exportar', 'warn');
        return;
    }
    const sep = ';';
    const headers = [
        'Máquina', 'Cod. Máquina', 'Periodicidad',
        'Grupo', 'Tarea', 'Descripción',
        'Última revisión', 'Próxima revisión', 'Días restantes', 'Estado',
        'Pendiente', 'Nota pendiente'
    ];
    const lines = [headers.join(sep)];
    const esc = (v) => {
        const s = String(v ?? '');
        if (s.includes(sep) || s.includes('"') || s.includes('\n') || s.includes('\r')) {
            return '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    };
    _semCache.groups.forEach(g => {
        g.tareas.forEach(t => {
            lines.push([
                g.desc_maquina, g.cod_maquina_mant, t.periodicidad,
                t.desc_grupo, t.tarea, t.desc_tarea,
                t.ultima_revision || '', t.proxima_revision || '',
                t.dias_restantes, t.estado,
                t.is_pendiente ? 'SI' : '', t.is_pendiente ? (t.pendiente_nota || '') : ''
            ].map(esc).join(sep));
        });
    });
    const csv = lines.join('\r\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'preventivos_' + _semDesde + '_a_' + _semHasta + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function printView() {
    const checkedCount = document.querySelectorAll('.mant-group-checked').length;
    const totalCount   = document.querySelectorAll('.mant-group-card').length;
    if (totalCount > 0 && checkedCount === 0) {
        showToast('Marca al menos una máquina para imprimir', 'warn');
        return;
    }
    const ph = $('#print-header');
    if (ph) ph.style.display = '';
    setTimeout(() => {
        window.print();
        if (ph) ph.style.display = 'none';
    }, 50);
}

document.addEventListener('DOMContentLoaded', () => {
    const today = isoToday();
    _semDesde = getQueryParam('desde') || today;
    _semHasta = getQueryParam('hasta') || addDaysIso(_semDesde, 6);
    if (_semHasta < _semDesde) _semHasta = _semDesde;
    _semCodMaquina   = getQueryParam('cod_maquina_mant') || '';
    _semPeriodicidad = getQueryParam('periodicidad') || '';

    $('#fecha-desde').value = _semDesde;
    $('#fecha-hasta').value = _semHasta;

    $('#fecha-desde').addEventListener('change', onDesdeChange);
    $('#fecha-hasta').addEventListener('change', onHastaChange);
    $('#machine-selector').addEventListener('change', onMachineChange);
    $('#periodicidad-selector').addEventListener('change', onPeriodicidadChange);
    const c = $('#filter-clear'); if (c) c.addEventListener('click', onClearFilters);
    document.querySelectorAll('.mant-quick-btn').forEach(btn => {
        btn.addEventListener('click', () => onQuickRange(btn.dataset.range));
    });

    $('#btn-export-csv').addEventListener('click', exportCsv);
    $('#btn-print').addEventListener('click', printView);
    $('#print-select-all').addEventListener('change', onSelectAllToggle);

    // Modal · marcar hecha
    $('#mark-modal-close').addEventListener('click', cerrarModalMarcar);
    $('#mark-modal-cancel').addEventListener('click', cerrarModalMarcar);
    $('#mark-modal-backdrop').addEventListener('click', cerrarModalMarcar);
    $('#mark-modal-ok').addEventListener('click', confirmarMarcar);
    $('#mark-operario').addEventListener('change', () => {
        const isOtro = $('#mark-operario').value === '__otro__';
        $('#mark-operario-otro-wrap').style.display = isOtro ? '' : 'none';
        if (isOtro) setTimeout(() => $('#mark-operario-otro').focus(), 50);
    });

    // Modal · marcar pendiente
    $('#pend-modal-close').addEventListener('click', cerrarModalPendiente);
    $('#pend-modal-cancel').addEventListener('click', cerrarModalPendiente);
    $('#pend-modal-backdrop').addEventListener('click', cerrarModalPendiente);
    $('#pend-modal-ok').addEventListener('click', confirmarPendiente);

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if ($('#mark-modal').style.display !== 'none') cerrarModalMarcar();
        else if ($('#pend-modal').style.display !== 'none') cerrarModalPendiente();
    });

    initFiltros(cargarVista);
    cargarVista();
});
