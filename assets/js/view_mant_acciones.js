/* Vista Mantenimiento · Acciones preventivas por máquina */
console.log('[acc] view_mant_acciones.js cargado v3 (filtro dinámico activo)');

const ACC_API = '../api/mant_acciones.php';

let _accMaquinas       = [];
let _accMaquinaActiva  = null;          // {cod, desc} de la máquina abierta
let _accTareas         = [];
let _accPeriodicidades = [];
let _accEditingTarea   = null;
let _accEditingMaquina = null;          // si null y modal máquina abierto → es alta
// Path de agrupación activa (estilo breadcrumb).
//   []                          → vista raíz
//   ['SECUENCIA']               → dentro de SECUENCIA (ve sub-grupos)
//   ['SECUENCIA','RACKS']       → dentro de RACKS (ve máquinas)
let _accPath = [];

// Estructura de agrupaciones. Una agrupación es:
//   - Hoja:    define `match(desc) → bool`. Sus miembros son máquinas.
//   - Wrapper: define `children: [...]`. Sus miembros son sub-agrupaciones.
//   - Dinámico: define `dynamicFamily(desc) → string|null`. Genera sub-grupos
//               sobre la marcha agrupando máquinas por la cadena que devuelve
//               (p. ej. "RACK CUSTODIAS TA RH" para los RACKs).
// `parent` indica que NO se muestra en la raíz (solo dentro de su padre).
const ACC_GROUPS = {
    SECUENCIA: {
        title:    'SECUENCIA',
        subtitle: 'Líneas de secuencia: E66, racks y plataformas',
        gradient: 'linear-gradient(135deg, #312e81, #6366f1)',
        children: ['E66', 'RACKS', 'PLATAFORMAS'],
    },
    E66: {
        title:    'E66',
        subtitle: 'Línea E66',
        gradient: 'linear-gradient(135deg, #4f46e5, #a78bfa)',
        match:    desc => /^E66\b/i.test(desc) || /^E66[_\s\-]/i.test(desc),
        parent:   'SECUENCIA',
    },
    RACKS: {
        title:    'RACKS',
        subtitle: 'Estanterías agrupadas por familia',
        gradient: 'linear-gradient(135deg, #0e7490, #06b6d4)',
        // Wrapper dinámico: cada máquina cuyo desc empieza por RACK genera
        // una familia (p. ej. "RACK CUSTODIAS TA RH"). El nº final " - NN"
        // se elimina para que todas las custodias TA RH queden bajo la
        // misma sub-tarjeta. La sub-familia es a su vez una hoja.
        dynamicFamily: desc => {
            const s = String(desc ?? '').trim();
            if (!/^RACK[\s\-]/i.test(s)) return null;
            // Quitar el sufijo " - NN" (con o sin espacios alrededor del guion).
            return s.replace(/\s*-\s*\d+\s*$/, '').trim().toUpperCase();
        },
        parent:   'SECUENCIA',
    },
    PLATAFORMAS: {
        title:    'PLATAFORMAS',
        subtitle: 'Plataformas de manejo',
        gradient: 'linear-gradient(135deg, #c2410c, #fb923c)',
        match:    desc => /^PLATAFORMA/i.test(desc),
        parent:   'SECUENCIA',
    },
};

// Prefijo usado para las keys de sub-familias dinámicas (RACKS).
const ACC_DYN_PREFIX = 'DYN:';

// Resuelve una "agrupación" — puede ser estática (en ACC_GROUPS) o dinámica
// (key con prefijo DYN:<padre>:<familia>). Devuelve la definición efectiva
// con `title`, `subtitle`, `gradient`, `match`, etc.
function resolveGroup(key) {
    if (key in ACC_GROUPS) return ACC_GROUPS[key];
    if (typeof key === 'string' && key.startsWith(ACC_DYN_PREFIX)) {
        const rest = key.slice(ACC_DYN_PREFIX.length); // "RACKS:RACK CUSTODIAS TA RH"
        const sep = rest.indexOf(':');
        if (sep < 0) return null;
        const parentKey = rest.slice(0, sep);
        const familyVal = rest.slice(sep + 1);
        const parentDef = ACC_GROUPS[parentKey];
        if (!parentDef || !parentDef.dynamicFamily) return null;
        return {
            title:    familyVal,
            subtitle: 'Familia · ' + parentDef.title,
            gradient: parentDef.gradient,
            match:    desc => parentDef.dynamicFamily(desc) === familyVal,
            parent:   parentKey,
            isDynamic: true,
        };
    }
    return null;
}

// Lista de claves hijas (estáticas o dinámicas) de un grupo. Para wrappers con
// `children` estáticas devuelve esas; para grupos con `dynamicFamily`, calcula
// las familias presentes en _accMaquinas y devuelve "DYN:<padre>:<familia>".
function resolveChildren(key, maquinasList) {
    const def = ACC_GROUPS[key];
    if (!def) return [];
    if (def.children && def.children.length) return def.children;
    if (def.dynamicFamily) {
        const families = new Set();
        for (const m of (maquinasList || [])) {
            const fam = def.dynamicFamily(m.desc_maquina);
            if (fam) families.add(fam);
        }
        return [...families].sort().map(f => ACC_DYN_PREFIX + key + ':' + f);
    }
    return [];
}

// Devuelve la agrupación HOJA (estática) a la que pertenece una máquina, o null.
// Las dinámicas no se consideran "raíz" — siempre van anidadas en su padre.
function groupOfMaquina(m) {
    const desc = String(m.desc_maquina ?? '');
    for (const [key, def] of Object.entries(ACC_GROUPS)) {
        if (def.match && def.match(desc)) return key;
        if (def.dynamicFamily && def.dynamicFamily(desc) !== null) return key;
    }
    return null;
}

// ¿La máquina pertenece a una agrupación dada (directamente o vía descendientes)?
function groupContainsMaquina(key, m) {
    const def = resolveGroup(key);
    if (!def) return false;
    if (def.match)         return def.match(String(m.desc_maquina ?? ''));
    if (def.children)      return def.children.some(child => groupContainsMaquina(child, m));
    if (def.dynamicFamily) return def.dynamicFamily(String(m.desc_maquina ?? '')) !== null;
    return false;
}

// Agrupaciones de primer nivel (sin padre).
function rootGroups() {
    return Object.keys(ACC_GROUPS).filter(k => !ACC_GROUPS[k].parent);
}

// ─── helpers ───
function fmtFecha(iso) {
    if (!iso) return '—';
    const [y, m, d] = String(iso).split('-');
    return d + '/' + m + '/' + y;
}
function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function periodicidadColor(p) {
    const map = {
        DIARIO:'#dc2626', SEMANAL:'#ea580c', QUINCENAL:'#d97706',
        MENSUAL:'#65a30d', BIMESTRAL:'#0891b2', BIMENSUAL:'#0891b2',
        TRIMESTRAL:'#0284c7', CUATRIMESTRAL:'#6366f1',
        SEMESTRAL:'#7c3aed', ANUAL:'#a855f7'
    };
    return map[(p || '').toUpperCase()] || '#3a6aa3';
}
function showModal(id)  { const m = document.getElementById(id); m.style.display = 'flex'; m.setAttribute('aria-hidden', 'false'); }
function hideModal(id)  { const m = document.getElementById(id); m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); }

async function apiCall(action, opts = {}) {
    let url = ACC_API + '?action=' + encodeURIComponent(action);
    if (opts.query) {
        for (const [k, v] of Object.entries(opts.query)) {
            url += `&${k}=${encodeURIComponent(v)}`;
        }
    }
    const method = opts.method || 'GET';
    const init = { method };
    const headers = {};
    if (opts.body) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(opts.body);
    }
    if (method !== 'GET' && method !== 'HEAD' && window.__CSRF_TOKEN) {
        headers['X-CSRF-Token'] = window.__CSRF_TOKEN;
    }
    if (Object.keys(headers).length) init.headers = headers;
    const r = await fetch(url, init);
    const j = await r.json();
    if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
    return j.data;
}

// ─── lista de máquinas (cuadrícula) ───
async function cargarMaquinas() {
    showLoader(true);
    try {
        const d = await apiCall('maquinas');
        _accMaquinas = d.maquinas || [];
        document.getElementById('acc-counter').textContent = _accMaquinas.length + ' máquinas';
        renderMaquinas();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
        document.getElementById('acc-machines').innerHTML =
            '<div class="acc-empty">Error cargando: ' + escHtml(e.message) + '</div>';
    } finally {
        showLoader(false);
    }
}

function renderMaquinaCard(m) {
    // Etiquetas de origen ('web' / contador de tareas añadidas) eliminadas
    // a petición del usuario — la procedencia ya no se distingue en la UI.
    const empty = m.task_count === 0 ? ' acc-card-empty' : '';
    return `
        <button type="button" class="acc-card${empty}" data-cod="${escHtml(m.cod_maquina_mant)}" data-desc="${escHtml(m.desc_maquina)}">
            <div class="acc-card-icon" style="background: linear-gradient(135deg, #3a6aa3, #5b8cc7)">
                <svg viewBox="0 0 32 32" width="26" height="26" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="5" y="9" width="22" height="16" rx="2"/>
                    <line x1="9" y1="14" x2="23" y2="14"/>
                    <line x1="9" y1="18" x2="20" y2="18"/>
                    <line x1="9" y1="22" x2="17" y2="22"/>
                </svg>
            </div>
            <div class="acc-card-body">
                <div class="acc-card-title">${escHtml(m.desc_maquina)}</div>
                <div class="acc-card-cod">${escHtml(m.cod_maquina_mant)}</div>
            </div>
            <div class="acc-card-footer">
                <span class="acc-card-badge acc-card-badge-count">${m.task_count} tareas</span>
            </div>
        </button>
    `;
}

function renderGrupoCard(key, members) {
    const def         = resolveGroup(key);
    if (!def) return '';
    // Es "wrapper" si tiene children estáticos o dinámicos (con sub-grupos por debajo).
    const isWrapper   = !!def.children || !!def.dynamicFamily;
    const totalTareas = members.reduce((acc, m) => acc + (parseInt(m.task_count, 10) || 0), 0);
    let subCount = 0;
    if (def.children) {
        subCount = def.children.length;
    } else if (def.dynamicFamily) {
        const fams = new Set();
        members.forEach(m => {
            const f = def.dynamicFamily(m.desc_maquina);
            if (f) fams.add(f);
        });
        subCount = fams.size;
    }
    const subBadge = isWrapper
        ? `<span class="acc-card-badge acc-card-badge-group">${subCount} sub-grupos</span>`
        : '';
    return `
        <button type="button" class="acc-card acc-card-group${isWrapper ? ' acc-card-wrapper' : ''}" data-group="${escHtml(key)}">
            <div class="acc-card-icon" style="background: ${def.gradient}">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                </svg>
            </div>
            <div class="acc-card-body">
                <div class="acc-card-title">${escHtml(def.title)}</div>
                <div class="acc-card-cod">${escHtml(def.subtitle)}</div>
            </div>
            <div class="acc-card-footer">
                ${subBadge}
                <span class="acc-card-badge acc-card-badge-group">${members.length} máquinas</span>
                <span class="acc-card-badge acc-card-badge-count">${totalTareas} tareas</span>
            </div>
        </button>
    `;
}

function renderMaquinas() {
    const cont      = document.getElementById('acc-machines');
    const counterEl = document.getElementById('acc-counter');
    const backBar   = document.getElementById('acc-back-bar');
    const q         = (document.getElementById('acc-search')?.value || '').trim().toLowerCase();
    const matchesQ  = m => !q
        || String(m.desc_maquina ?? '').toLowerCase().includes(q)
        || String(m.cod_maquina_mant ?? '').toLowerCase().includes(q);

    // ── Caso 1: vista raíz (path = []) ──
    if (_accPath.length === 0) {
        if (backBar) backBar.style.display = 'none';

        const ungrouped         = _accMaquinas.filter(m => groupOfMaquina(m) === null);
        const ungroupedFiltered = ungrouped.filter(matchesQ);
        const rootKeys          = rootGroups();
        const totalAgrup        = _accMaquinas.length - ungrouped.length;

        if (counterEl) {
            counterEl.textContent = q
                ? ungroupedFiltered.length + ' / ' + ungrouped.length + ' máquinas (+ agrupaciones)'
                : _accMaquinas.length + ' máquinas (' + totalAgrup + ' agrupadas)';
        }

        // 1) máquinas sueltas primero
        let html = ungroupedFiltered.map(renderMaquinaCard).join('');
        // 2) tarjetas-grupo de primer nivel después
        for (const key of rootKeys) {
            const members = _accMaquinas.filter(m => groupContainsMaquina(key, m));
            if (members.length) html += renderGrupoCard(key, members);
        }
        if (!html) {
            cont.innerHTML = '<div class="acc-empty">Sin máquinas que coincidan con "' + escHtml(q) + '".</div>';
            return;
        }
        cont.innerHTML = html;
        return;
    }

    // ── Caso 2/3: dentro de una agrupación ──
    const currentKey = _accPath[_accPath.length - 1];
    const def        = resolveGroup(currentKey);
    if (!def) {
        // Path inválido (p.ej. tras un cambio de configuración): reseteamos.
        _accPath = [];
        renderMaquinas();
        return;
    }

    if (backBar) {
        backBar.style.display = 'flex';
        // Para el breadcrumb, mostrar el nombre de la familia en vez del key DYN:…
        const crumbParts = _accPath.map(k => {
            const d = resolveGroup(k);
            return d ? d.title : k;
        });
        document.getElementById('acc-back-title').textContent = crumbParts.join(' › ');
    }

    // Caso 2: wrapper (estático o dinámico) → mostramos sub-grupos.
    const childKeys = resolveChildren(currentKey, _accMaquinas);
    if (childKeys.length && (def.children || def.dynamicFamily)) {
        let html = '';
        let visibles = 0;
        for (const childKey of childKeys) {
            const members = _accMaquinas.filter(m => groupContainsMaquina(childKey, m));
            if (!members.length) continue;
            // Con búsqueda activa, solo se muestra el sub-grupo si algún miembro coincide.
            if (q && !members.some(matchesQ)) continue;
            html += renderGrupoCard(childKey, members);
            visibles++;
        }
        if (counterEl) {
            counterEl.textContent = q
                ? visibles + ' / ' + childKeys.length + ' sub-grupos en ' + def.title
                : childKeys.length + ' sub-grupos en ' + def.title;
        }
        if (!html) {
            cont.innerHTML = '<div class="acc-empty">Sin sub-grupos que coincidan en ' + escHtml(def.title) + '.</div>';
            return;
        }
        cont.innerHTML = html;
        return;
    }

    // Caso 3: hoja (con `match`) → mostramos máquinas.
    const members = _accMaquinas.filter(m => groupContainsMaquina(currentKey, m));
    const list    = members.filter(matchesQ);
    if (counterEl) {
        counterEl.textContent = q
            ? list.length + ' / ' + members.length + ' en ' + def.title
            : members.length + ' máquinas en ' + def.title;
    }
    if (!list.length) {
        cont.innerHTML = '<div class="acc-empty">Sin máquinas que coincidan en ' + escHtml(def.title) + '.</div>';
        return;
    }
    cont.innerHTML = list.map(renderMaquinaCard).join('');
}

// Event delegation: clic sobre tarjeta. Si es grupo → drill-in (push al path); si es máquina → modal de tareas.
document.addEventListener('click', e => {
    const card = e.target.closest('.acc-card');
    if (!card || !document.getElementById('acc-machines')?.contains(card)) return;
    if (card.classList.contains('acc-card-group')) {
        _accPath.push(card.dataset.group);
        const searchEl = document.getElementById('acc-search');
        if (searchEl) searchEl.value = '';
        renderMaquinas();
        // Lleva la vista al inicio del grid para que se note el cambio.
        document.getElementById('acc-machines')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        abrirModalTareas(card.dataset.cod, card.dataset.desc);
    }
});

// ─── modal tareas ───
async function abrirModalTareas(cod, desc) {
    _accMaquinaActiva = { cod, desc };
    document.getElementById('acc-modal-title').textContent = desc;
    document.getElementById('acc-modal-cod').textContent   = '(' + cod + ')';
    document.getElementById('acc-tareas-tbody').innerHTML  = '<tr><td colspan="7" class="acc-empty">Cargando…</td></tr>';
    document.getElementById('acc-tareas-count').textContent = '— tareas';
    showModal('acc-modal');

    try {
        const d = await apiCall('tareas', { query: { cod } });
        _accTareas         = d.tareas || [];
        _accPeriodicidades = d.periodicidades || [];
        document.getElementById('acc-tareas-count').textContent = _accTareas.length + ' tareas';
        renderTareas();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
        document.getElementById('acc-tareas-tbody').innerHTML =
            '<tr><td colspan="7" class="acc-empty">Error: ' + escHtml(e.message) + '</td></tr>';
    }
}

function renderTareas() {
    const tb = document.getElementById('acc-tareas-tbody');
    if (!_accTareas.length) {
        tb.innerHTML = '<tr><td colspan="7" class="acc-empty">Sin tareas. Añade la primera con el botón <strong>+ Añadir tarea</strong>.</td></tr>';
        return;
    }
    tb.innerHTML = _accTareas.map(t => {
        const per = (t.periodicidad || '').toUpperCase();
        const perPill = per
            ? `<span class="acc-pill" style="background:${periodicidadColor(per)}">${escHtml(per)}</span>`
            : `<span class="acc-pill acc-tag-nopen" title="Sin periodicidad asignada — pulsa Editar para asignarla">SIN ASIGNAR</span>`;
        // Etiqueta de origen 'web' eliminada — la procedencia no se muestra.
        const originBadge = '';
        // Nuevas pills (migracion 006)
        const altaBaja = (t.alta_baja || 'ALTA').toUpperCase();
        const altaPill = altaBaja === 'BAJA'
            ? '<span class="acc-tag acc-tag-baja" title="Tarea dada de BAJA — no se planifica">BAJA</span>'
            : '';
        const ipPill = t.ip_interna
            ? `<span class="acc-tag acc-tag-ip" title="IP Interna">${escHtml(t.ip_interna)}</span>`
            : '';
        const tMantPill = t.tipo_mantenimiento
            ? `<span class="acc-tag acc-tag-${t.tipo_mantenimiento.toLowerCase()}" title="Tipo de mantenimiento">${escHtml(t.tipo_mantenimiento.toUpperCase())}</span>`
            : '';
        const tRealPill = t.tipo_realizacion
            ? `<span class="acc-tag acc-tag-${t.tipo_realizacion.toLowerCase()}" title="Realizado en">${escHtml(t.tipo_realizacion.toUpperCase())}</span>`
            : '';
        // Badge "PAUSADA · desde DD/MM/YYYY" si la tarea tiene fecha_pausado.
        const pausadaPill = t.fecha_pausado
            ? `<span class="acc-tag acc-tag-pausada" title="Tarea pausada desde ${escHtml(fmtFecha(t.fecha_pausado))}">PAUSADA · ${escHtml(fmtFecha(t.fecha_pausado))}</span>`
            : '';
        const meta = [pausadaPill, altaPill, ipPill, tMantPill, tRealPill].filter(Boolean).join(' ');
        const descCell = `${escHtml(t.desc_tarea || '—')}${meta ? '<div class="acc-cell-meta">' + meta + '</div>' : ''}`;
        const rowCls = t.fecha_pausado ? 'acc-row-pausada' : '';
        const pauseBtn = t.fecha_pausado
            ? `<button type="button" class="acc-btn-mini acc-btn-resume" data-action="resume" title="Reanudar tarea (limpia fecha de pausado)">▶ Reanudar</button>`
            : `<button type="button" class="acc-btn-mini acc-btn-pause"  data-action="pause"  title="Pausar tarea (introduces fecha)">⏸ Pausar</button>`;
        return `
            <tr data-id="${t.id}" class="${rowCls}">
                <td><strong>${escHtml(t.tarea)}</strong>${originBadge}</td>
                <td>${perPill}</td>
                <td class="acc-cell-desc">${descCell}</td>
                <td class="acc-cell-fecha">${fmtFecha(t.ultima_revision)}</td>
                <td class="acc-cell-fecha">${fmtFecha(t.proxima_revision)}</td>
                <td class="acc-cell-num">${t.intervenciones || 0}</td>
                <td>
                    <button type="button" class="acc-btn-mini acc-btn-edit"   data-action="edit">✎ Editar</button>
                    ${pauseBtn}
                    <button type="button" class="acc-btn-mini acc-btn-delete" data-action="delete">× Borrar</button>
                </td>
            </tr>
        `;
    }).join('');
}

// Delegación clic dentro de la tabla de tareas
document.addEventListener('click', e => {
    const btn = e.target.closest('.acc-btn-mini');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (!tr || !tr.dataset.id) return;
    const t = _accTareas.find(x => x.id === parseInt(tr.dataset.id, 10));
    if (!t) return;
    if (btn.dataset.action === 'edit')   abrirFormularioEdicionTarea(t);
    if (btn.dataset.action === 'delete') borrarTarea(t);
    if (btn.dataset.action === 'pause')  pausarTarea(t);
    if (btn.dataset.action === 'resume') reanudarTarea(t);
});

function cerrarModalTareas() {
    hideModal('acc-modal');
    _accMaquinaActiva = null;
    _accTareas = [];
}

// ─── alta / edición de TAREA ───
function poblarPeriodicidades(selected) {
    const sel = document.getElementById('acc-f-periodicidad');
    sel.innerHTML = '<option value="">— Selecciona —</option>' +
        _accPeriodicidades.map(p => `<option value="${escHtml(p)}">${escHtml(p)}</option>`).join('');
    sel.value = selected || '';
}

function abrirFormularioAltaTarea() {
    if (!_accMaquinaActiva) return;
    _accEditingTarea = null;
    document.getElementById('acc-form-title').textContent = 'Nueva tarea';
    document.getElementById('acc-form-summary').innerHTML =
        `Para <strong>${escHtml(_accMaquinaActiva.desc)}</strong> <span class="acc-modal-cod">(${escHtml(_accMaquinaActiva.cod)})</span>`;
    document.getElementById('acc-f-tarea').value = '';
    document.getElementById('acc-f-desc').value  = '';
    document.getElementById('acc-f-primera').value = new Date().toISOString().substring(0, 10);
    poblarPeriodicidades('');
    // Campos nuevos: defaults
    document.getElementById('acc-f-alta-baja').value  = 'ALTA';
    document.getElementById('acc-f-ip-interna').value = '';
    document.getElementById('acc-f-tipo-mant').value  = 'Preventivo';
    document.getElementById('acc-f-tipo-real').value  = '';

    document.getElementById('acc-f-primera-wrap').style.display = '';
    const fr1 = document.getElementById('acc-f-fechas-row');
    if (fr1) fr1.style.display = 'none';
    // Campo pausa no aplica en alta de nueva tarea — se oculta y se vacía.
    const pwrap = document.getElementById('acc-f-pausado-wrap');
    if (pwrap) pwrap.style.display = 'none';
    const pInput = document.getElementById('acc-f-pausado');
    if (pInput) pInput.value = '';
    showModal('acc-form-modal');
    setTimeout(() => document.getElementById('acc-f-tarea').focus(), 50);
}

function abrirFormularioEdicionTarea(t) {
    _accEditingTarea = t;
    document.getElementById('acc-form-title').textContent = 'Editar tarea';
    document.getElementById('acc-form-summary').innerHTML =
        `<strong>${escHtml(_accMaquinaActiva.desc)}</strong> <span class="acc-modal-cod">(${escHtml(_accMaquinaActiva.cod)})</span>` +
        `<br><span class="acc-modal-cod">id ${t.id} · orden ${escHtml(t.orden)}</span>` +
        (t.intervenciones ? `<br><span class="acc-warning">⚠ Esta tarea tiene ${t.intervenciones} intervenciones registradas. Editar la periodicidad o las fechas no afecta al histórico.</span>` : '');
    document.getElementById('acc-f-tarea').value = t.tarea || '';
    document.getElementById('acc-f-desc').value  = t.desc_tarea || '';
    poblarPeriodicidades(t.periodicidad || '');
    // Campos nuevos
    document.getElementById('acc-f-alta-baja').value  = (t.alta_baja || 'ALTA').toUpperCase();
    document.getElementById('acc-f-ip-interna').value = t.ip_interna || '';
    document.getElementById('acc-f-tipo-mant').value  = t.tipo_mantenimiento || '';
    document.getElementById('acc-f-tipo-real').value  = t.tipo_realizacion || '';

    document.getElementById('acc-f-primera-wrap').style.display = 'none';
    const fr2 = document.getElementById('acc-f-fechas-row');
    if (fr2) fr2.style.display = '';
    document.getElementById('acc-f-ultima').value  = t.ultima_revision  || '';
    document.getElementById('acc-f-proxima').value = t.proxima_revision || '';

    // Pausa: visible solo en edición; vacío = activa.
    const pwrap = document.getElementById('acc-f-pausado-wrap');
    if (pwrap) pwrap.style.display = '';
    const pInput = document.getElementById('acc-f-pausado');
    if (pInput) pInput.value = t.fecha_pausado || '';

    showModal('acc-form-modal');
    setTimeout(() => document.getElementById('acc-f-tarea').focus(), 50);
}

async function guardarTarea() {
    const tarea = document.getElementById('acc-f-tarea').value.trim();
    const per   = document.getElementById('acc-f-periodicidad').value;
    const desc  = document.getElementById('acc-f-desc').value.trim();
    // Campos nuevos
    const altaBaja = document.getElementById('acc-f-alta-baja').value || 'ALTA';
    const ip       = document.getElementById('acc-f-ip-interna').value.trim();
    const tMant    = document.getElementById('acc-f-tipo-mant').value;
    const tReal    = document.getElementById('acc-f-tipo-real').value;

    if (!tarea) { showToast('Falta el campo Tarea', 'error'); return; }
    if (!per)   { showToast('Selecciona una periodicidad', 'error'); return; }
    if (!desc)  { showToast('Falta la descripción', 'error'); return; }

    showLoader(true);
    try {
        if (_accEditingTarea) {
            const fechaPausadoInput = document.getElementById('acc-f-pausado');
            const fechaPausadoVal   = fechaPausadoInput ? (fechaPausadoInput.value || null) : null;
            await apiCall('update', {
                method: 'POST',
                body: {
                    id:                 _accEditingTarea.id,
                    tarea:              tarea,
                    desc_tarea:         desc,
                    periodicidad:       per,
                    ultima_revision:    document.getElementById('acc-f-ultima').value || null,
                    proxima_revision:   document.getElementById('acc-f-proxima').value || null,
                    alta_baja:          altaBaja,
                    ip_interna:         ip,
                    tipo_realizacion:   tReal,
                    tipo_mantenimiento: tMant,
                    fecha_pausado:      fechaPausadoVal,
                }
            });
            showToast('Tarea actualizada', 'success');
        } else {
            const primera = document.getElementById('acc-f-primera').value;
            if (!primera) { showToast('Falta la fecha de primera revisión', 'error'); showLoader(false); return; }
            await apiCall('create', {
                method: 'POST',
                body: {
                    cod_maquina_mant:       _accMaquinaActiva.cod,
                    tarea:                  tarea,
                    periodicidad:           per,
                    desc_tarea:             desc,
                    fecha_primera_revision: primera,
                    alta_baja:              altaBaja,
                    ip_interna:             ip,
                    tipo_realizacion:       tReal,
                    tipo_mantenimiento:     tMant,
                }
            });
            showToast('Tarea creada', 'success');
        }
        hideModal('acc-form-modal');
        _accEditingTarea = null;
        await abrirModalTareas(_accMaquinaActiva.cod, _accMaquinaActiva.desc);
        cargarMaquinas();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

async function pausarTarea(t) {
    const hoy = new Date().toISOString().substring(0, 10);
    const f = prompt(
        `Pausar la tarea "${t.tarea}".\n\nIntroduce la fecha de pausado (YYYY-MM-DD).\nLa tarea no se planificará ni computará cumplimiento hasta que la reanudes.`,
        hoy
    );
    if (f === null) return; // cancel
    const fechaPausado = f.trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(fechaPausado)) {
        showToast('Fecha inválida (formato YYYY-MM-DD)', 'error');
        return;
    }
    showLoader(true);
    try {
        await apiCall('update', { method: 'POST', body: { id: t.id, fecha_pausado: fechaPausado } });
        showToast('Tarea pausada desde ' + fechaPausado, 'success');
        await abrirModalTareas(_accMaquinaActiva.cod, _accMaquinaActiva.desc);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

async function reanudarTarea(t) {
    if (!confirm(`¿Reanudar la tarea "${t.tarea}"?\nVolverá al plan vigente y computará para cumplimiento.`)) return;
    showLoader(true);
    try {
        // null vacía la columna en BD (la tarea queda activa)
        await apiCall('update', { method: 'POST', body: { id: t.id, fecha_pausado: null } });
        showToast('Tarea reanudada', 'success');
        await abrirModalTareas(_accMaquinaActiva.cod, _accMaquinaActiva.desc);
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

async function borrarTarea(t) {
    let msg = `¿Borrar la tarea "${t.tarea}"?`;
    if (t.intervenciones > 0) {
        msg += `\n\nATENCIÓN: tiene ${t.intervenciones} intervenciones registradas. Las intervenciones SE CONSERVAN como histórico, pero la tarea desaparecerá del plan.`;
    }
    if (!confirm(msg)) return;

    showLoader(true);
    try {
        await apiCall('delete', { method: 'POST', body: { id: t.id } });
        showToast('Tarea borrada', 'success');
        await abrirModalTareas(_accMaquinaActiva.cod, _accMaquinaActiva.desc);
        cargarMaquinas();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

// ─── alta / edición de MÁQUINA ───
function abrirFormularioAltaMaquina() {
    _accEditingMaquina = null;
    document.getElementById('acc-machine-title').textContent = 'Crear nueva máquina';
    document.getElementById('acc-m-cod').value   = '';
    document.getElementById('acc-m-desc').value  = '';
    document.getElementById('acc-m-notas').value = '';
    document.getElementById('acc-m-cod').disabled = false;
    showModal('acc-machine-modal');
    setTimeout(() => document.getElementById('acc-m-cod').focus(), 50);
}

function abrirFormularioEdicionMaquina() {
    if (!_accMaquinaActiva) return;
    _accEditingMaquina = _accMaquinaActiva;
    document.getElementById('acc-machine-title').textContent = 'Editar máquina';
    document.getElementById('acc-m-cod').value     = _accMaquinaActiva.cod;
    document.getElementById('acc-m-cod').disabled  = true;  // el código no se cambia
    document.getElementById('acc-m-desc').value    = _accMaquinaActiva.desc;
    document.getElementById('acc-m-notas').value   = '';
    showModal('acc-machine-modal');
    setTimeout(() => document.getElementById('acc-m-desc').focus(), 50);
}

async function guardarMaquina() {
    const cod   = document.getElementById('acc-m-cod').value.trim();
    const desc  = document.getElementById('acc-m-desc').value.trim();
    const notas = document.getElementById('acc-m-notas').value.trim();

    if (!cod)  { showToast('Falta el código', 'error'); return; }
    if (!desc) { showToast('Falta la descripción', 'error'); return; }

    showLoader(true);
    try {
        if (_accEditingMaquina) {
            const d = await apiCall('update_maquina', {
                method: 'POST',
                body: { cod_maquina_mant: cod, desc_maquina: desc, notas }
            });
            showToast('Máquina actualizada', 'success');
            hideModal('acc-machine-modal');
            // Si estamos en su modal de tareas, actualiza la cabecera
            if (_accMaquinaActiva && _accMaquinaActiva.cod === cod) {
                _accMaquinaActiva.desc = desc;
                document.getElementById('acc-modal-title').textContent = desc;
            }
            await cargarMaquinas();
        } else {
            const d = await apiCall('create_maquina', {
                method: 'POST',
                body: { cod_maquina_mant: cod, desc_maquina: desc, notas }
            });
            showToast('Máquina creada', 'success');
            hideModal('acc-machine-modal');
            await cargarMaquinas();
            // Abre directamente su modal de tareas para que pueda añadir
            abrirModalTareas(cod, desc);
        }
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

async function borrarMaquina() {
    if (!_accMaquinaActiva) return;
    showLoader(true);
    try {
        const d = await apiCall('delete_maquina_impact', { query: { cod: _accMaquinaActiva.cod } });
        const imp = d.impact;
        document.getElementById('acc-del-desc').textContent          = imp.desc_maquina || _accMaquinaActiva.desc;
        document.getElementById('acc-del-cod').textContent           = '(' + imp.cod_maquina_mant + ')';
        document.getElementById('acc-del-tareas').textContent         = imp.tareas;
        document.getElementById('acc-del-intervenciones').textContent = imp.intervenciones;
        document.getElementById('acc-del-pendientes').textContent     = imp.pendientes;
        document.getElementById('acc-del-overrides').textContent      = imp.overrides;
        showModal('acc-delete-confirm-modal');
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

async function confirmarBorradoMaquinaCascade() {
    if (!_accMaquinaActiva) { hideModal('acc-delete-confirm-modal'); return; }
    showLoader(true);
    try {
        const d = await apiCall('delete_maquina', {
            method: 'POST',
            body: { cod_maquina_mant: _accMaquinaActiva.cod, cascade: true }
        });
        const del = d.deleted || {};
        showToast(`Máquina borrada (${del.tareas||0} tareas · ${del.intervenciones||0} intervenciones)`, 'success');
        hideModal('acc-delete-confirm-modal');
        cerrarModalTareas();
        await cargarMaquinas();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

// ─── eventos ───
document.addEventListener('DOMContentLoaded', () => {
    const searchEl = document.getElementById('acc-search');
    if (searchEl) {
        // Belt-and-suspenders: input cubre el caso normal, keyup cubre IMEs raros,
        // search cubre el clic en la X de limpiar del input type=search.
        searchEl.addEventListener('input',  renderMaquinas);
        searchEl.addEventListener('keyup',  renderMaquinas);
        searchEl.addEventListener('search', renderMaquinas);
    }

    // Botón "+ Crear nueva máquina"
    document.getElementById('acc-new-machine-btn').addEventListener('click', abrirFormularioAltaMaquina);

    // Botón "← Volver" para subir un nivel en el breadcrumb de agrupaciones
    const backBtn = document.getElementById('acc-back-btn');
    if (backBtn) {
        backBtn.addEventListener('click', () => {
            if (_accPath.length > 0) _accPath.pop();
            const searchEl = document.getElementById('acc-search');
            if (searchEl) searchEl.value = '';
            renderMaquinas();
            document.getElementById('acc-machines')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    // Modal tareas
    document.getElementById('acc-modal-close').addEventListener('click', cerrarModalTareas);
    document.getElementById('acc-modal-backdrop').addEventListener('click', cerrarModalTareas);
    document.getElementById('acc-add-btn').addEventListener('click', abrirFormularioAltaTarea);
    document.getElementById('acc-export-btn').addEventListener('click', () => {
        if (!_accMaquinaActiva) { showToast('Selecciona una máquina primero', 'error'); return; }
        window.location.href = ACC_API.replace('mant_acciones.php', 'mant_acciones_export.php')
            + '?cod=' + encodeURIComponent(_accMaquinaActiva.cod);
    });
    document.getElementById('acc-edit-machine-btn').addEventListener('click', abrirFormularioEdicionMaquina);
    document.getElementById('acc-delete-machine-btn').addEventListener('click', borrarMaquina);

    // Modal alta/edición tarea
    document.getElementById('acc-form-close').addEventListener('click', () => hideModal('acc-form-modal'));
    document.getElementById('acc-form-backdrop').addEventListener('click', () => hideModal('acc-form-modal'));
    document.getElementById('acc-form-cancel').addEventListener('click', () => hideModal('acc-form-modal'));
    document.getElementById('acc-form-save').addEventListener('click', guardarTarea);

    // Modal alta/edición máquina
    document.getElementById('acc-machine-close').addEventListener('click', () => hideModal('acc-machine-modal'));
    document.getElementById('acc-machine-backdrop').addEventListener('click', () => hideModal('acc-machine-modal'));
    document.getElementById('acc-machine-cancel').addEventListener('click', () => hideModal('acc-machine-modal'));
    document.getElementById('acc-machine-save').addEventListener('click', guardarMaquina);

    // Modal de confirmación de borrado en cascada
    document.getElementById('acc-delete-confirm-close')   .addEventListener('click', () => hideModal('acc-delete-confirm-modal'));
    document.getElementById('acc-delete-confirm-backdrop').addEventListener('click', () => hideModal('acc-delete-confirm-modal'));
    document.getElementById('acc-delete-confirm-cancel')  .addEventListener('click', () => hideModal('acc-delete-confirm-modal'));
    document.getElementById('acc-delete-confirm-ok')      .addEventListener('click', confirmarBorradoMaquinaCascade);

    // Escape cierra el modal más interno (de más interno a más externo)
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if      (document.getElementById('acc-delete-confirm-modal').style.display === 'flex') hideModal('acc-delete-confirm-modal');
        else if (document.getElementById('acc-machine-modal').style.display        === 'flex') hideModal('acc-machine-modal');
        else if (document.getElementById('acc-form-modal').style.display           === 'flex') hideModal('acc-form-modal');
        else if (document.getElementById('acc-modal').style.display                === 'flex') cerrarModalTareas();
    });

    cargarMaquinas();
});
