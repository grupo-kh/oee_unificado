/* ─────────────────────────────────────────────────────────────────────────
   Vista Calendario laboral · año completo
   ─────────────────────────────────────────────────────────────────────────
   Grid 4×3 con los 12 meses. Cada día es una celda clicable:
     - verde   → laborable
     - rojo    → no laborable (S/D, festivo CV, o NO_LABORABLE BD)
     - borde naranja → tiene una excepción BD activa
     - badge azul → nº de tareas planificadas en ese día

   Al clic, se abre un modal corto que permite anotar un motivo y aceptar.
   El recálculo de próximas revisiones es automático (lo gestiona el API).
   Solo rol técnico.
   ───────────────────────────────────────────────────────────────────────── */
(function () {
    const $ = (s, root = document) => root.querySelector(s);

    // Estado
    let _anyo  = new Date().getFullYear();
    let _data  = null;
    let _idxFecha = {};   // 'YYYY-MM-DD' → objeto día
    let _pendiente = null; // { fecha, dia, accion: 'set'|'delete', tipo? }

    // Helpers
    const esc = s => String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const pad = n => String(n).padStart(2, '0');
    const fmtFechaUI = iso => {
        const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})$/);
        return m ? `${m[3]}/${m[2]}/${m[1]}` : iso;
    };
    const MESES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

    async function api(action, { method='GET', query={}, body=null } = {}) {
        const params = new URLSearchParams({ action, ...query });
        const url = '../api/mant_calendario.php?' + params.toString();
        const headers = { 'Accept': 'application/json' };
        if (method !== 'GET' && method !== 'HEAD' && window.__CSRF_TOKEN) {
            headers['X-CSRF-Token'] = window.__CSRF_TOKEN;
        }
        if (body != null) headers['Content-Type'] = 'application/json';
        const r = await fetch(url, {
            method, headers,
            body: body != null ? JSON.stringify(body) : null,
        });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error((j && j.error) || `HTTP ${r.status}`);
        return j.data;
    }

    // ── Carga + render del año ──────────────────────────────────────────
    async function cargar() {
        showLoader(true);
        try {
            _data = await api('year', { query: { y: _anyo } });
            _idxFecha = {};
            Object.values(_data.meses || {}).forEach(arr =>
                arr.forEach(d => { _idxFecha[d.fecha] = d; })
            );
            render();
        } catch (e) {
            showToast('Error: ' + e.message, 'error');
        } finally {
            showLoader(false);
        }
    }

    function render() {
        $('#cal-anyo-label').textContent = String(_anyo);
        $('#cal-info').textContent = 'Año ' + _anyo;
        $('#cal-stats').innerHTML =
            `<strong>${_data.dias_habiles_anyo}</strong> días hábiles · ` +
            `<strong>${_data.excepciones_cnt}</strong> excepciones`;
        const grid = $('#cal-year-grid');
        grid.innerHTML = '';
        for (let m = 1; m <= 12; m++) {
            const dias = (_data.meses && _data.meses[m]) || [];
            grid.appendChild(renderMes(m, dias));
        }
    }

    function renderMes(m, dias) {
        const div = document.createElement('div');
        div.className = 'cal-month';
        const title = MESES[m-1] + ' ' + _anyo;
        const html = [];
        html.push(`<div class="cal-month-title">${title}</div>`);
        html.push(`<div class="cal-month-dow">
            <span>L</span><span>M</span><span>X</span><span>J</span><span>V</span>
            <span class="weekend">S</span><span class="weekend">D</span>
        </div>`);

        html.push('<div class="cal-month-grid">');
        // Padding del primer día (lun=1)
        const first = dias[0];
        if (first) {
            for (let i = 1; i < first.dow; i++) {
                html.push('<div class="cal-day-cell empty"></div>');
            }
        }
        const hoyIso = ymdLocal(new Date());
        dias.forEach(d => {
            const cls = ['cal-day-cell'];
            if (!d.habil) cls.push('no-habil');
            if (d.excepcion) cls.push('excepcion');
            if (d.fecha === hoyIso) cls.push('today');
            const tasksBadge = d.tareas > 0
                ? `<span class="cal-day-tasks" title="${d.tareas} tareas">${d.tareas > 99 ? '99+' : d.tareas}</span>`
                : '';
            const tooltip = `${fmtFechaUI(d.fecha)} · ${d.habil ? 'laborable' : 'NO laborable'}`
                + (d.excepcion ? ` (${d.excepcion.tipo})` : '')
                + (d.tareas ? ` · ${d.tareas} tareas` : '');
            html.push(
                `<div class="${cls.join(' ')}" data-fecha="${d.fecha}" title="${esc(tooltip)}">`
                + d.dia
                + tasksBadge
                + '</div>'
            );
        });
        html.push('</div>');
        div.innerHTML = html.join('');
        return div;
    }

    function ymdLocal(d) {
        return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
    }

    // ── Modal: confirmar cambio + motivo ────────────────────────────────
    function abrirModal(fecha) {
        const d = _idxFecha[fecha];
        if (!d) return;
        // Determinamos la acción según el estado actual:
        //  · Si es hábil:
        //      - hábil por excepción LABORABLE_EXTRA → borrar excepción (vuelve a sáb/fest)
        //      - hábil por regla por defecto         → poner NO_LABORABLE
        //  · Si NO es hábil:
        //      - no hábil por excepción NO_LABORABLE → borrar excepción
        //      - no hábil por regla por defecto       → poner LABORABLE_EXTRA
        let accion, tipo, futuroEstado;
        if (d.habil) {
            // pasa a NO laborable
            if (d.excepcion && d.excepcion.tipo === 'LABORABLE_EXTRA') {
                accion = 'delete'; tipo = null;
            } else {
                accion = 'set';    tipo = 'NO_LABORABLE';
            }
            futuroEstado = 'NO laborable';
        } else {
            // pasa a laborable
            if (d.excepcion && d.excepcion.tipo === 'NO_LABORABLE') {
                accion = 'delete'; tipo = null;
            } else {
                accion = 'set';    tipo = 'LABORABLE_EXTRA';
            }
            futuroEstado = 'LABORABLE';
        }
        _pendiente = { fecha, dia: d, accion, tipo, futuroEstado };

        // Render del modal
        $('#cal-modal-title').textContent =
            (accion === 'delete')
                ? `Quitar excepción del ${fmtFechaUI(fecha)}`
                : `Marcar ${fmtFechaUI(fecha)} como ${futuroEstado}`;
        let info = '';
        if (accion === 'delete') {
            info = `El día vuelve a su regla por defecto: <strong>${d.base ? 'laborable (L-V)' : 'no laborable (S/D/festivo)'}</strong>.`;
        } else if (tipo === 'NO_LABORABLE') {
            info = `Las tareas planificadas en este día <strong>se moverán al día hábil siguiente</strong>.`;
            if (d.tareas > 0) {
                info += `<br>Hay <strong>${d.tareas}</strong> tarea${d.tareas===1?'':'s'} planificada${d.tareas===1?'':'s'} en este día.`;
            }
        } else if (tipo === 'LABORABLE_EXTRA') {
            info = `El día queda disponible como laborable extra. <strong>No se mueven tareas</strong>: solo se libera para que puedas planificar revisiones aquí si quieres.`;
        }
        $('#cal-modal-info').innerHTML = info;
        $('#cal-motivo').value = (d.excepcion && d.excepcion.motivo) ? d.excepcion.motivo : '';
        $('#cal-modal-bg').classList.add('open');
    }
    function cerrarModal() {
        $('#cal-modal-bg').classList.remove('open');
        _pendiente = null;
    }

    async function aceptar() {
        if (!_pendiente) return;
        const { fecha, accion, tipo } = _pendiente;
        const motivo = ($('#cal-motivo').value || '').trim();
        showLoader(true);
        try {
            if (accion === 'delete') {
                await api('delete', { method: 'POST', body: { fecha } });
                showToast(`Excepción quitada del ${fmtFechaUI(fecha)}`, 'success');
            } else {
                const r = await api('set', { method: 'POST', body: { fecha, tipo, motivo } });
                const mov = r.tareas_movidas || 0;
                let msg = `${fmtFechaUI(fecha)} marcado como ${tipo === 'NO_LABORABLE' ? 'NO laborable' : 'laborable extra'}`;
                if (mov > 0) msg += `. ${mov} tarea${mov===1?'':'s'} movida${mov===1?'':'s'} al día hábil siguiente`;
                showToast(msg, 'success');
            }
            cerrarModal();
            await cargar();
        } catch (e) {
            showToast('Error: ' + e.message, 'error');
        } finally {
            showLoader(false);
        }
    }

    // ── Navegación ──────────────────────────────────────────────────────
    function nav(delta) {
        _anyo += delta;
        cargar();
    }

    // ── Init ────────────────────────────────────────────────────────────
    function init() {
        $('#cal-prev').addEventListener('click', () => nav(-1));
        $('#cal-next').addEventListener('click', () => nav(+1));
        $('#cal-today').addEventListener('click', () => {
            _anyo = new Date().getFullYear();
            cargar();
        });

        // Delegación: clic en celda
        $('#cal-year-grid').addEventListener('click', e => {
            const cell = e.target.closest('.cal-day-cell[data-fecha]');
            if (!cell || cell.classList.contains('empty')) return;
            abrirModal(cell.dataset.fecha);
        });

        // Modal
        $('#cal-modal-close').addEventListener('click', cerrarModal);
        $('#cal-modal-cancel').addEventListener('click', cerrarModal);
        $('#cal-modal-bg').addEventListener('click', e => {
            if (e.target === $('#cal-modal-bg')) cerrarModal();
        });
        $('#cal-modal-save').addEventListener('click', aceptar);
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && $('#cal-modal-bg').classList.contains('open')) cerrarModal();
            if (e.key === 'Enter' && $('#cal-modal-bg').classList.contains('open')) aceptar();
        });

        cargar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
