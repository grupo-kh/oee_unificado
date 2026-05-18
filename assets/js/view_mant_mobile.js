/* Webapp móvil de Mantenimiento · Operario */

const API_BASE = '../api';
const LS_OPERARIO = 'mant_last_operario';
const PER_DAYS = {
    DIARIO: 1, DIARIA: 1,
    SEMANAL: 7,
    QUINCENAL: 15,
    MENSUAL: 30,
    BIMESTRAL: 60, BIMENSUAL: 60,
    TRIMESTRAL: 90,
    CUATRIMESTRAL: 120,
    SEMESTRAL: 180,
    ANUAL: 365
};

let _operariosKnown = [];
let _currentOperario = '';
let _machinesCache = [];
let _currentMachine = null;
let _currentTasks = [];
let _selectedTask = null;
const _navStack = [];

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => document.querySelectorAll(sel);

function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmtFecha(iso) {
    if (!iso) return '—';
    const [y, m, d] = iso.split('-');
    return d + '/' + m + '/' + y;
}
function isoToday() { return new Date().toISOString().substring(0, 10); }
function addDaysIso(iso, days) {
    const t = new Date(iso + 'T00:00:00').getTime() + days * 86400000;
    return new Date(t).toISOString().substring(0, 10);
}

function showLoader(show = true) {
    const l = $('#mob-loader');
    if (l) l.hidden = !show;
}
function showToast(msg, type = 'info') {
    const t = $('#mob-toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'mob-toast show mob-toast-' + type;
    setTimeout(() => t.classList.remove('show'), 4000);
}

async function api(action, params = {}) {
    const url = new URL(API_BASE + '/mant_mobile.php', window.location.href);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([k, v]) => {
        if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, v);
    });
    const resp = await fetch(url, { cache: 'no-store' });
    const json = await resp.json();
    if (!resp.ok || !json.ok) throw new Error(json.error || `HTTP ${resp.status}`);
    return json.data;
}

async function apiPost(endpoint, payload) {
    const headers = { 'Content-Type': 'application/json' };
    if (window.__CSRF_TOKEN) headers['X-CSRF-Token'] = window.__CSRF_TOKEN;
    const resp = await fetch(API_BASE + '/' + endpoint, {
        method: 'POST',
        headers,
        body: JSON.stringify(payload)
    });
    const json = await resp.json();
    if (!resp.ok || !json.ok) throw new Error(json.error || `HTTP ${resp.status}`);
    return json.data;
}

/* ============== Navegación entre pantallas ============== */
function goTo(screenId, pushNav = true) {
    if (pushNav) {
        const current = document.querySelector('.mob-screen.mob-screen-active');
        if (current && current.id !== screenId) _navStack.push(current.id);
    }
    $$('.mob-screen').forEach(s => s.classList.remove('mob-screen-active'));
    const target = document.getElementById(screenId);
    if (!target) return;
    target.classList.add('mob-screen-active');
    target.scrollTop = 0;
    window.scrollTo(0, 0);
    updateTopbar(target);
}
function goBack() {
    if (!_navStack.length) return goTo('screen-machines', false);
    const prev = _navStack.pop();
    goTo(prev, false);
}
function updateTopbar(screen) {
    const back = $('#mob-back');
    if (back) back.hidden = (screen.id === 'screen-machines');
    const t = screen.dataset.title || 'Mantenimiento';
    const s = screen.dataset.subtitle || '';
    $('#mob-title').textContent = t || 'Mantenimiento';
    $('#mob-subtitle').textContent = s;
}

/* ============== Operario actual ============== */
function loadCurrentOperario() {
    try { _currentOperario = localStorage.getItem(LS_OPERARIO) || ''; } catch (e) {}
    renderOperarioBtn();
}
function saveCurrentOperario(op) {
    _currentOperario = op || '';
    try {
        if (op) localStorage.setItem(LS_OPERARIO, op);
        else    localStorage.removeItem(LS_OPERARIO);
    } catch (e) {}
    renderOperarioBtn();
}
function renderOperarioBtn() {
    const el = $('#mob-op-name');
    if (el) el.textContent = _currentOperario || 'Sin operario';
}

function abrirModalOperario() {
    const sel = $('#mob-op-modal-select');
    sel.innerHTML = '<option value="">— Sin operario —</option>';
    _operariosKnown.forEach(op => {
        const o = document.createElement('option');
        o.value = op; o.textContent = op;
        sel.appendChild(o);
    });
    const otro = document.createElement('option');
    otro.value = '__otro__'; otro.textContent = 'Otro…';
    sel.appendChild(otro);

    if (_currentOperario && _operariosKnown.includes(_currentOperario)) {
        sel.value = _currentOperario;
        $('#mob-op-modal-otro-wrap').hidden = true;
        $('#mob-op-modal-otro').value = '';
    } else if (_currentOperario) {
        sel.value = '__otro__';
        $('#mob-op-modal-otro-wrap').hidden = false;
        $('#mob-op-modal-otro').value = _currentOperario;
    } else {
        sel.value = '';
        $('#mob-op-modal-otro-wrap').hidden = true;
        $('#mob-op-modal-otro').value = '';
    }

    $('#mob-op-modal').hidden = false;
    setTimeout(() => sel.focus(), 50);
}
function cerrarModalOperario() {
    $('#mob-op-modal').hidden = true;
}
function confirmarOperario() {
    const sel = $('#mob-op-modal-select');
    let op = sel.value || '';
    if (op === '__otro__') op = ($('#mob-op-modal-otro').value || '').trim();
    if (!op) {
        saveCurrentOperario('');
    } else {
        saveCurrentOperario(op);
    }
    cerrarModalOperario();
    showToast(_currentOperario ? ('Operario: ' + _currentOperario) : 'Operario sin definir', 'success');
}

/* ============== Pantalla 1 · Máquinas ============== */
async function cargarMaquinas() {
    showLoader(true);
    try {
        const q = ($('#mob-search-input').value || '').trim();
        const d = await api('machines', { q });
        _machinesCache = d.machines || [];
        renderMaquinas(_machinesCache, d.total_pending, d.dias_horizonte);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
        $('#mob-machines-list').innerHTML = '<div class="mob-empty">Error cargando máquinas</div>';
    } finally {
        showLoader(false);
    }
}
function renderMaquinas(arr, totalPending, diasHor) {
    const wrap = $('#mob-machines-list');
    const sum  = $('#mob-summary');
    if (sum) {
        if (totalPending === 0) {
            sum.innerHTML = '<span class="mob-summary-good">✓ Sin tareas pendientes</span>';
        } else {
            sum.innerHTML = `<strong>${totalPending}</strong> tarea${totalPending !== 1 ? 's' : ''} pendiente${totalPending !== 1 ? 's' : ''} · próximos ${diasHor} día${diasHor !== 1 ? 's' : ''}`;
        }
    }
    if (!arr || !arr.length) {
        wrap.innerHTML = '<div class="mob-empty">Sin máquinas con tareas pendientes</div>';
        return;
    }
    wrap.innerHTML = arr.map(m => {
        const badges = [];
        if (m.pendientes) badges.push(`<span class="mob-pill mob-pill-pend">🚩 ${m.pendientes}</span>`);
        if (m.vencidas)   badges.push(`<span class="mob-pill mob-pill-venc">${m.vencidas} venc</span>`);
        if (m.urgentes)   badges.push(`<span class="mob-pill mob-pill-urg">${m.urgentes} urg</span>`);
        return `
            <button type="button" class="mob-list-item" data-cod="${escHtml(m.cod_maquina_mant)}" data-desc="${escHtml(m.desc_maquina)}">
                <div class="mob-list-main">
                    <div class="mob-list-title">${escHtml(m.desc_maquina)}</div>
                    <div class="mob-list-meta">cod: ${escHtml(m.cod_maquina_mant)}</div>
                </div>
                <div class="mob-list-side">
                    <div class="mob-list-count">${m.pending_count}</div>
                    <div class="mob-list-badges">${badges.join('')}</div>
                </div>
                <svg class="mob-list-arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
        `;
    }).join('');
    wrap.querySelectorAll('.mob-list-item').forEach(it => {
        it.addEventListener('click', () => {
            seleccionarMaquina(it.dataset.cod, it.dataset.desc);
        });
    });
}

/* ============== Pantalla 2 · Tareas de la máquina ============== */
async function seleccionarMaquina(cod, desc) {
    _currentMachine = { cod, desc };
    const screen = $('#screen-tasks');
    screen.dataset.title = desc;
    showLoader(true);
    try {
        const d = await api('tasks', { cod_maquina_mant: cod });
        _currentTasks = d.tasks || [];
        renderTareas(_currentTasks);
        goTo('screen-tasks');
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}
function renderTareas(arr) {
    const wrap = $('#mob-tasks-list');
    if (!arr || !arr.length) {
        wrap.innerHTML = '<div class="mob-empty">Sin tareas pendientes para esta máquina</div>';
        return;
    }
    wrap.innerHTML = arr.map((t, i) => {
        const estCls = 'mob-pill-' + (t.estado === 'vencida' ? 'venc' : (t.estado === 'urgente' ? 'urg' : 'enp'));
        const estLab = t.estado === 'vencida' ? `${Math.abs(t.dias_restantes)}d vencida`
                      : t.estado === 'urgente' ? `en ${t.dias_restantes}d`
                      : `en ${t.dias_restantes}d`;
        const flag = t.is_pendiente ? '<span class="mob-flag-red" title="Pendiente">✖</span>' : '';
        return `
            <button type="button" class="mob-list-item mob-task-item ${t.is_pendiente ? 'mob-task-pendiente' : ''}" data-idx="${i}">
                <div class="mob-list-main">
                    <div class="mob-list-title">${flag}${escHtml(t.desc_grupo)}</div>
                    <div class="mob-task-desc">${escHtml(t.desc_tarea)}</div>
                    <div class="mob-list-meta">${escHtml(t.periodicidad)} · próx. ${fmtFecha(t.proxima_revision)}</div>
                </div>
                <div class="mob-list-side">
                    <span class="mob-pill ${estCls}">${escHtml(estLab)}</span>
                </div>
                <svg class="mob-list-arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
        `;
    }).join('');
    wrap.querySelectorAll('.mob-task-item').forEach(it => {
        it.addEventListener('click', () => {
            const idx = parseInt(it.dataset.idx, 10);
            seleccionarTarea(_currentTasks[idx]);
        });
    });
}

/* ============== Pantalla 3 · Form ============== */
function seleccionarTarea(t) {
    if (!t) return;
    _selectedTask = t;
    const card = $('#mob-task-card');
    const dias = PER_DAYS[(t.periodicidad || '').toUpperCase()] || null;
    const proximaTras = dias ? addDaysIso(isoToday(), dias) : null;
    card.innerHTML = `
        <div class="mob-task-card-machine">${escHtml(_currentMachine.desc)}</div>
        <div class="mob-task-card-cod">cod ${escHtml(_currentMachine.cod)}</div>
        <div class="mob-task-card-title">${escHtml(t.desc_grupo)}</div>
        <div class="mob-task-card-desc">${escHtml(t.desc_tarea)}</div>
        <div class="mob-task-card-info">
            <span><strong>Periodicidad:</strong> ${escHtml(t.periodicidad)}</span>
            <span><strong>Última:</strong> ${fmtFecha(t.ultima_revision)}</span>
            <span><strong>Próxima programada:</strong> ${fmtFecha(t.proxima_revision)}</span>
        </div>
        ${proximaTras ? `<div class="mob-task-card-future">Tras enviar, próxima → <strong>${fmtFecha(proximaTras)}</strong></div>` : ''}
    `;
    const screen = $('#screen-form');
    screen.dataset.title = t.desc_grupo || 'Tarea';

    // Pre-fill operario
    const sel = $('#mob-operario');
    sel.innerHTML = '<option value="">— Selecciona —</option>';
    _operariosKnown.forEach(op => {
        const o = document.createElement('option'); o.value = op; o.textContent = op;
        sel.appendChild(o);
    });
    const otro = document.createElement('option'); otro.value = '__otro__'; otro.textContent = 'Otro…';
    sel.appendChild(otro);
    if (_currentOperario && _operariosKnown.includes(_currentOperario)) {
        sel.value = _currentOperario;
        $('#mob-operario-otro-wrap').hidden = true;
        $('#mob-operario-otro').value = '';
    } else if (_currentOperario) {
        sel.value = '__otro__';
        $('#mob-operario-otro-wrap').hidden = false;
        $('#mob-operario-otro').value = _currentOperario;
    } else {
        sel.value = '';
        $('#mob-operario-otro-wrap').hidden = true;
        $('#mob-operario-otro').value = '';
    }

    $('#mob-fecha').value = isoToday();
    $('#mob-observaciones').value = '';
    goTo('screen-form');
}

async function enviarFormulario() {
    if (!_selectedTask) return;
    const sel = $('#mob-operario');
    let op = sel.value || '';
    if (op === '__otro__') op = ($('#mob-operario-otro').value || '').trim();
    if (!op) {
        showToast('Indica el operario', 'warn');
        sel.focus();
        return;
    }
    const fechaInt = $('#mob-fecha').value || isoToday();
    const obs = ($('#mob-observaciones').value || '').trim();

    showLoader(true);
    try {
        const t = _selectedTask;
        await apiPost('mant_marcar_hecha.php', {
            orden: t.orden,
            tarea: t.tarea,
            fecha_proxima_original: t.proxima_revision,
            fecha_intervencion: fechaInt,
            operario: op,
            observaciones: obs
        });
        saveCurrentOperario(op);

        const dias = PER_DAYS[(t.periodicidad || '').toUpperCase()] || null;
        const proximaNueva = dias ? addDaysIso(fechaInt, dias) : null;

        $('#success-detail').innerHTML = `
            <div class="mob-success-task">${escHtml(t.desc_grupo)}</div>
            <div class="mob-success-task-desc">${escHtml(t.desc_tarea)}</div>
            <div class="mob-success-meta">
                <div><strong>Operario:</strong> ${escHtml(op)}</div>
                <div><strong>Fecha:</strong> ${fmtFecha(fechaInt)}</div>
                ${proximaNueva
                    ? `<div class="mob-success-next">📅 Próxima programada: <strong>${fmtFecha(proximaNueva)}</strong></div>`
                    : '<div class="mob-success-next mob-success-next-warn">⚠ Sin reprogramación automática (periodicidad desconocida)</div>'
                }
            </div>
        `;

        // Reset stack: tras éxito, "Continuar" vuelve siempre a la lista de máquinas
        _navStack.length = 0;
        _navStack.push('screen-machines');
        _selectedTask = null;
        goTo('screen-success', false);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

/* ============== Init ============== */
async function cargarOperarios() {
    try {
        const d = await api('operarios');
        _operariosKnown = d.operarios || [];
    } catch (e) {
        _operariosKnown = [];
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    loadCurrentOperario();

    $('#mob-back').addEventListener('click', goBack);
    $('#mob-op-btn').addEventListener('click', abrirModalOperario);

    $('#mob-op-modal-cancel').addEventListener('click', cerrarModalOperario);
    $('#mob-op-modal-ok').addEventListener('click', confirmarOperario);
    $('#mob-op-modal').addEventListener('click', (e) => {
        if (e.target.classList.contains('mob-modal-backdrop')) cerrarModalOperario();
    });
    $('#mob-op-modal-select').addEventListener('change', () => {
        const isOtro = $('#mob-op-modal-select').value === '__otro__';
        $('#mob-op-modal-otro-wrap').hidden = !isOtro;
        if (isOtro) setTimeout(() => $('#mob-op-modal-otro').focus(), 50);
    });

    let searchTimer = null;
    $('#mob-search-input').addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(cargarMaquinas, 250);
    });

    $('#mob-operario').addEventListener('change', () => {
        const isOtro = $('#mob-operario').value === '__otro__';
        $('#mob-operario-otro-wrap').hidden = !isOtro;
        if (isOtro) setTimeout(() => $('#mob-operario-otro').focus(), 50);
    });
    $('#mob-submit').addEventListener('click', enviarFormulario);

    $('#success-back').addEventListener('click', async () => {
        await cargarMaquinas();
        goTo('screen-machines', false);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (!$('#mob-op-modal').hidden) cerrarModalOperario();
            else if ($('.mob-screen.mob-screen-active')?.id !== 'screen-machines') goBack();
        }
    });

    await cargarOperarios();
    await cargarMaquinas();
    updateTopbar($('.mob-screen.mob-screen-active'));
});
