/* =========================================================
   Common JS - compartido por todas las vistas
   ========================================================= */

// Detecta si estoy en home o en una vista
const isHome = !window.location.pathname.includes('/views/');
const API_BASE = isHome ? 'api' : '../api';

// ============ UTILS ============
function $(sel)  { return document.querySelector(sel); }
function $$(sel) { return document.querySelectorAll(sel); }

function showLoader(show = true) {
    const l = $('#loader');
    if (l) l.classList.toggle('active', show);
}

function showToast(msg, type = 'info') {
    const t = $('#toast');
    if (!t) return;
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.classList.remove('show'), 4000);
}

function formatFecha(d) {
    const date = new Date(d);
    return date.toISOString().substring(0, 10);
}

function formatFechaCorta(isoDate) {
    // '2026-04-20' → '20/04/2026'
    const [y, m, d] = isoDate.split('-');
    return `${d}/${m}/${y}`;
}

async function apiFetch(endpoint, params = {}, signal = null) {
    const url = new URL(`${API_BASE}/${endpoint}`, window.location.href);
    Object.entries(params).forEach(([k, v]) => {
        if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, v);
    });

    const resp = await fetch(url, { signal });
    if (resp.status === 401) {
        // Sesión no autenticada (típicamente en endpoints de Mantenimiento) →
        // redirige al login conservando la vista actual.
        const here = window.location.pathname.split('/').pop() || '';
        window.location.href = 'mant_login.php' + (here ? '?next=' + encodeURIComponent(here) : '');
        throw new Error('Sesión no autenticada');
    }
    if (!resp.ok) {
        const txt = await resp.text();
        throw new Error(`HTTP ${resp.status}: ${txt.substring(0, 150)}`);
    }
    const json = await resp.json();
    if (!json.ok) throw new Error(json.error || 'Error desconocido');
    return json.data;
}

function setFiltrosEnabled(enabled) {
    const fFecha = $('#f-fecha');
    const fTurno = $('#f-turno');
    if (fFecha) fFecha.disabled = !enabled;
    if (fTurno) fTurno.disabled = !enabled;
}

// ============ FILTROS (persistidos en localStorage) ============
const FILTROS_KEY = 'kh_plan_attainment_filtros';

function loadFiltros() {
    try {
        const raw = localStorage.getItem(FILTROS_KEY);
        if (raw) return JSON.parse(raw);
    } catch (e) {}
    return null;
}

function saveFiltros(filtros) {
    try {
        localStorage.setItem(FILTROS_KEY, JSON.stringify(filtros));
    } catch (e) {}
}

function getFiltrosActuales() {
    const fecha = $('#f-fecha')?.value;
    const turno = $('#f-turno')?.value;

    // Derivamos rango: última semana terminando en la fecha seleccionada
    let fecha_desde, fecha_hasta;
    if (fecha) {
        const d = new Date(fecha);
        fecha_hasta = formatFecha(d);
        d.setDate(d.getDate() - 6);
        fecha_desde = formatFecha(d);
    } else {
        const hoy = new Date();
        fecha_hasta = formatFecha(hoy);
        hoy.setDate(hoy.getDate() - 6);
        fecha_desde = formatFecha(hoy);
    }

    return { fecha_desde, fecha_hasta, turno, fecha };
}

function initFiltros(onChange) {
    // Valores iniciales: guardados o por defecto
    const saved = loadFiltros();
    const fFecha = $('#f-fecha');
    const fTurno = $('#f-turno');

    if (fFecha) {
        const ayer = new Date();
        ayer.setDate(ayer.getDate() - 1);
        fFecha.value = saved?.fecha || formatFecha(ayer);
    }
    if (fTurno) {
        fTurno.value = saved?.turno || '';
    }

    // Event listeners
    const handler = () => {
        const f = getFiltrosActuales();
        saveFiltros({ fecha: f.fecha, turno: f.turno });
        if (onChange) onChange(f);
    };

    fFecha?.addEventListener('change', handler);
    fTurno?.addEventListener('change', handler);
}

// ============ COLORES SEMAFÓRICOS ============
function semColor(pct) {
    if (pct >= 75) return '#10b981';  // verde
    if (pct >= 50) return '#f59e0b';  // ámbar
    return '#ef4444';                 // rojo
}

function semClass(pct) {
    if (pct >= 100) return 'cell-ok';
    if (pct >=  75) return 'cell-ok-soft';
    if (pct >=  50) return 'cell-warn';
    if (pct >     0) return 'cell-bad-soft';
    return 'cell-bad';
}

// Variante de 3 niveles: Cumplido (≥100) / Parcial (50-99) / Incumplido (<50)
function semClass3(pct) {
    if (pct >= 100) return 'cell-ok';
    if (pct >=  50) return 'cell-warn';
    return 'cell-bad';
}

// ============ MODAL: "Cómo se ha leído esta info" ============
// Compartido por los paneles que cruzan MAPEX + Excel.
// Cada API devuelve un campo `meta` con secciones; este modal lo renderiza.

function _escHtmlMeta(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => (
        {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]
    ));
}

function _ensureInfoModalEl() {
    let m = document.getElementById('info-modal');
    if (m) return m;
    m = document.createElement('div');
    m.id = 'info-modal';
    m.className = 'info-modal';
    m.setAttribute('aria-hidden', 'true');
    m.innerHTML = `
        <div class="info-modal-backdrop"></div>
        <div class="info-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="info-modal-title">
            <div class="info-modal-header">
                <span id="info-modal-title">Cómo se ha leído esta información</span>
                <button type="button" class="info-modal-close" aria-label="Cerrar">×</button>
            </div>
            <div class="info-modal-body"></div>
            <div class="info-modal-footer">
                <button type="button" class="info-modal-btn-ok">Entendido</button>
            </div>
        </div>
    `;
    document.body.appendChild(m);
    const close = () => closeInfoModal();
    m.querySelector('.info-modal-close').addEventListener('click', close);
    m.querySelector('.info-modal-btn-ok').addEventListener('click', close);
    m.querySelector('.info-modal-backdrop').addEventListener('click', close);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && m.classList.contains('open')) close();
    });
    return m;
}

function closeInfoModal() {
    const m = document.getElementById('info-modal');
    if (!m) return;
    m.classList.remove('open');
    m.setAttribute('aria-hidden', 'true');
}

function showInfoModal(meta) {
    const m = _ensureInfoModalEl();
    const title = (meta && meta.panel) ? meta.panel : 'Información del panel';
    m.querySelector('#info-modal-title').textContent = 'Cómo se lee · ' + title;
    m.querySelector('.info-modal-body').innerHTML = _renderMetaHtml(meta);
    m.classList.add('open');
    m.setAttribute('aria-hidden', 'false');
}

function _renderMetaHtml(meta) {
    if (!meta || !Array.isArray(meta.secciones)) {
        return '<p class="info-empty">Sin metadatos disponibles para este panel.</p>';
    }
    const parts = [];
    if (meta.generado) {
        parts.push(`<p class="info-generado">Datos generados: <code>${_escHtmlMeta(meta.generado)}</code></p>`);
    }
    meta.secciones.forEach(sec => {
        let html = `<section class="info-section"><h4 class="info-section-title">${_escHtmlMeta(sec.titulo || '')}</h4>`;
        if (Array.isArray(sec.items) && sec.items.length) {
            html += '<dl class="info-kv">';
            sec.items.forEach(it => {
                html += `<dt>${_escHtmlMeta(it.label || '')}</dt>`;
                html += `<dd>${_escHtmlMeta(it.value || '')}</dd>`;
            });
            html += '</dl>';
        }
        if (Array.isArray(sec.notas) && sec.notas.length) {
            html += '<ul class="info-notas">';
            sec.notas.forEach(n => { html += `<li>${_escHtmlMeta(n)}</li>`; });
            html += '</ul>';
        }
        html += '</section>';
        parts.push(html);
    });
    return parts.join('');
}

// Conecta el icono ⓘ del header de cualquier vista al modal.
// El JS de la vista debe guardar la última `meta` recibida del API en una
// variable accesible y llamar a attachInfoIcon('#info-icon-id', () => meta).
function attachInfoIcon(selector, getMetaFn) {
    const btn = document.querySelector(selector);
    if (!btn) return;
    btn.addEventListener('click', () => {
        const m = getMetaFn ? getMetaFn() : null;
        showInfoModal(m);
    });
}
