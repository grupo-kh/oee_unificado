/* ─────────────────────────────────────────────────────────────────────────
   Vista Gestión de Operarios
   ─────────────────────────────────────────────────────────────────────────
   CRUD del catálogo `mant_operarios` con sus capacitaciones. Solo rol técnico.
   Endpoint: api/mant_operarios.php
   ───────────────────────────────────────────────────────────────────────── */

(function () {
    const $ = (s, root = document) => root.querySelector(s);

    // Estado local
    let _operarios = [];
    let _puestos   = [];     // [{key, label}]
    let _caps      = [];     // [{key, label}]
    let _editing   = null;   // { numero } cuando se edita, null en alta

    // ── Helpers ─────────────────────────────────────────────────────────
    const esc = s => String(s ?? '').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmtFecha = iso => {
        if (!iso) return '—';
        const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})$/);
        return m ? `${m[3]}/${m[2]}/${m[1]}` : iso;
    };
    // Heurística: si el texto original tiene varias palabras separadas
    // por espacios, devuelve {nombre, apellidos} donde "nombre" = primera
    // palabra y "apellidos" = el resto. Útil cuando los operarios viejos
    // tienen nombre+apellidos juntos en un solo campo.
    function splitNombreApellidos(texto) {
        const partes = String(texto || '').trim().split(/\s+/).filter(Boolean);
        if (partes.length <= 1) return { nombre: partes[0] || '', apellidos: '' };
        return { nombre: partes[0], apellidos: partes.slice(1).join(' ') };
    }

    async function api(action, { method = 'GET', query = {}, body = null } = {}) {
        const params = new URLSearchParams({ action, ...query });
        const url = '../api/mant_operarios.php?' + params.toString();
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

    // ── Carga inicial: catálogos + listado ──────────────────────────────
    async function cargar() {
        showLoader(true);
        try {
            // Catálogos: solo una vez.
            if (!_puestos.length) {
                const cat = await api('catalog');
                _puestos = cat.puestos || [];
                _caps    = cat.capacitaciones || [];
                buildSelectPuesto();
                buildCapsCheckboxes();
            }
            const soloActivos = $('#ops-only-active').checked ? '1' : '';
            const d = await api('list', { query: { solo_activos: soloActivos } });
            _operarios = d.operarios || [];
            renderTabla();
        } catch (e) {
            showToast('Error: ' + e.message, 'error');
        } finally {
            showLoader(false);
        }
    }

    function buildSelectPuesto() {
        const sel = $('#ops-f-puesto');
        sel.innerHTML = '<option value="">— Sin asignar —</option>'
            + _puestos.map(p => `<option value="${esc(p.key)}">${esc(p.label)}</option>`).join('');
    }

    function buildCapsCheckboxes() {
        const cont = $('#ops-caps-grid');
        cont.innerHTML = _caps.map(c => `
            <label class="ops-cap-check">
                <input type="checkbox" class="ops-cap-cb" value="${esc(c.key)}">
                ${esc(c.label)}
            </label>
        `).join('');
    }

    // ── Pills de capacitación en la tabla ───────────────────────────────
    // Mostramos SOLO el nivel porcentual MÁS ALTO (top) — al ser acumulativo
    // ya implica todos los inferiores. Si además tiene Racks (independiente),
    // lo añadimos como pill separado.
    function renderCapPills(caps) {
        if (!caps || !caps.length) return '<span style="color:#9aa6b6;font-style:italic">—</span>';
        const cls = {
            P25:    'ops-cap-p25',
            P50:    'ops-cap-p50',
            P75:    'ops-cap-p75',
            P100:   'ops-cap-p100',
            TALLER: 'ops-cap-taller',
        };
        const lbl = {
            P25:'25%', P50:'50%', P75:'75%', P100:'100%', TALLER:'Racks',
        };
        // Buscamos el TOP de los porcentuales y lo pintamos. Luego, si tiene
        // Racks, lo añadimos al lado como pill independiente.
        const ord = ['P25','P50','P75','P100'];
        let top = null;
        ord.forEach(k => { if (caps.includes(k)) top = k; });
        const out = [];
        if (top) out.push(`<span class="ops-cap-pill ${cls[top]}">${lbl[top]}</span>`);
        if (caps.includes('TALLER')) out.push(`<span class="ops-cap-pill ${cls.TALLER}">${lbl.TALLER}</span>`);
        return '<div class="ops-cap-pills">' + out.join('') + '</div>';
    }

    function puestoLabel(key) {
        if (!key) return '—';
        const p = _puestos.find(x => x.key === key);
        return p ? `<span class="ops-puesto-pill">${esc(p.label)}</span>` : esc(key);
    }

    // ── Render tabla + búsqueda ─────────────────────────────────────────
    function renderTabla() {
        const tb = $('#ops-tbody');
        const q  = ($('#ops-search').value || '').toLowerCase().trim();
        let rows = _operarios;
        if (q) {
            rows = rows.filter(o =>
                (o.numero||'').toLowerCase().includes(q) ||
                (o.apellidos||'').toLowerCase().includes(q) ||
                (o.nombre||'').toLowerCase().includes(q) ||
                (o.puesto||'').toLowerCase().includes(q)
            );
        }
        $('#ops-counter').textContent = rows.length + ' operario' + (rows.length === 1 ? '' : 's');
        $('#ops-info').textContent = 'Total catálogo: ' + _operarios.length;

        if (!rows.length) {
            tb.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:24px;color:#5b6f86;font-style:italic">Sin resultados</td></tr>`;
            return;
        }
        tb.innerHTML = rows.map(o => {
            const baja = !!o.fecha_baja;
            const rowCls = baja ? 'ops-row-baja' : '';
            const bajaBadge = baja ? ' <span class="ops-baja-badge">BAJA</span>' : '';
            // Vista: si en BD no hay apellidos pero el "nombre" tiene varias
            // palabras (operarios viejos como "CRISTOBAL TENORIO SELMA"),
            // los partimos visualmente para que se vean repartidos. La BD
            // no se modifica hasta que el técnico pulse Guardar.
            // Forzamos a String para defenderlos de null/numéricos en BD.
            let nomShow = String(o.nombre    ?? '');
            let apeShow = String(o.apellidos ?? '');
            if (!apeShow && nomShow.trim().includes(' ')) {
                const s = splitNombreApellidos(nomShow);
                nomShow = s.nombre;
                apeShow = s.apellidos;
            }
            return `
                <tr class="${rowCls}" data-num="${esc(o.numero)}">
                    <td><strong>${esc(o.numero)}</strong></td>
                    <td>${esc(apeShow || '—')}${bajaBadge}</td>
                    <td>${esc(nomShow || '—')}</td>
                    <td>${fmtFecha(o.fecha_alta)}</td>
                    <td>${fmtFecha(o.fecha_baja)}</td>
                    <td>${puestoLabel(o.puesto)}</td>
                    <td>${renderCapPills(o.capacitaciones)}</td>
                    <td style="text-align:right;white-space:nowrap">
                        <button type="button" class="ops-btn ops-btn-icon" data-act="edit"   data-num="${esc(o.numero)}" title="Editar">✎</button>
                        <button type="button" class="ops-btn ops-btn-icon danger" data-act="delete" data-num="${esc(o.numero)}" title="Borrar (solo sin intervenciones)">×</button>
                    </td>
                </tr>
            `;
        }).join('');

        // (El wire-up de botones por delegación está en DOMContentLoaded —
        //  un único listener en #ops-tbody que lee data-act y data-num del
        //  botón clicado. Así no hay que reconectar listeners en cada render.)
    }

    // ── Modal: alta / edición ───────────────────────────────────────────
    function openCrear() {
        _editing = null;
        $('#ops-modal-title').textContent = 'Nuevo operario';
        $('#ops-f-numero').disabled  = false;
        $('#ops-f-numero').value     = '';
        $('#ops-f-puesto').value     = '';
        $('#ops-f-apellidos').value  = '';
        $('#ops-f-nombre').value     = '';
        $('#ops-f-alta').value       = new Date().toISOString().slice(0,10);
        $('#ops-f-baja').value       = '';
        document.querySelectorAll('.ops-cap-cb').forEach(cb => cb.checked = false);
        abrirModal();
    }

    function openEditar(numero) {
        const op = _operarios.find(o => String(o.numero) === String(numero));
        if (!op) {
            showToast('No se encontró el operario en el listado actual', 'error');
            return;
        }
        _editing = { numero: op.numero };
        $('#ops-modal-title').textContent = `Editar operario ${op.numero}`;
        $('#ops-f-numero').disabled = true; // PK, no editable
        $('#ops-f-numero').value     = op.numero;
        $('#ops-f-puesto').value     = op.puesto || '';
        // Auto-divide nombre completo si los apellidos están vacíos:
        // "CRISTOBAL TENORIO SELMA" → nombre "CRISTOBAL", apellidos "TENORIO SELMA".
        // Solo se pre-rellena el formulario (no se guarda hasta que el técnico
        // pulse Guardar). Si ya hay apellidos en BD, dejamos los valores tal cual.
        let nombreSugerido    = String(op.nombre    ?? '');
        let apellidosSugerido = String(op.apellidos ?? '');
        if (!apellidosSugerido && nombreSugerido.trim().includes(' ')) {
            const s = splitNombreApellidos(nombreSugerido);
            nombreSugerido    = s.nombre;
            apellidosSugerido = s.apellidos;
        }
        $('#ops-f-apellidos').value  = apellidosSugerido;
        $('#ops-f-nombre').value     = nombreSugerido;
        $('#ops-f-alta').value       = op.fecha_alta || '';
        $('#ops-f-baja').value       = op.fecha_baja || '';
        document.querySelectorAll('.ops-cap-cb').forEach(cb => {
            cb.checked = (op.capacitaciones || []).includes(cb.value);
        });
        abrirModal();
    }

    function abrirModal()  { $('#ops-modal-backdrop').classList.add('open'); }
    function cerrarModal() { $('#ops-modal-backdrop').classList.remove('open'); _editing = null; }

    async function guardar() {
        const numero = ($('#ops-f-numero').value || '').trim();
        if (!_editing && !numero) {
            showToast('El código es obligatorio', 'error'); return;
        }
        const body = {
            numero,
            apellidos: ($('#ops-f-apellidos').value || '').trim(),
            nombre:    ($('#ops-f-nombre').value    || '').trim(),
            fecha_alta: $('#ops-f-alta').value || null,
            fecha_baja: $('#ops-f-baja').value || null,
            puesto:    $('#ops-f-puesto').value || null,
            capacitaciones: Array.from(document.querySelectorAll('.ops-cap-cb:checked')).map(cb => cb.value),
        };
        showLoader(true);
        try {
            if (_editing) {
                await api('update', { method: 'POST', query: { numero: _editing.numero }, body });
                showToast('Operario actualizado', 'success');
            } else {
                await api('create', { method: 'POST', body });
                showToast('Operario creado', 'success');
            }
            cerrarModal();
            await cargar();
        } catch (e) {
            showToast('Error: ' + e.message, 'error');
        } finally {
            showLoader(false);
        }
    }

    async function borrar(numero) {
        const num = String(numero ?? '').trim();
        if (!num) {
            showToast('No se pudo determinar el código del operario', 'error');
            return;
        }
        // Comparación robusta por si o.numero llegara como número desde JSON.
        const op = _operarios.find(o => String(o.numero) === num);
        const apellidos = String(op?.apellidos ?? '').trim();
        const nombre    = String(op?.nombre    ?? '').trim();
        const etiqueta  = (apellidos + ' ' + nombre).trim() || num;
        const ok = window.confirm(
            `¿Borrar al operario "${etiqueta}" (código ${num})?\n\n` +
            `Si tiene intervenciones registradas, el sistema lo impedirá y te ` +
            `sugerirá dar de baja en su lugar (Fecha baja).`
        );
        if (!ok) return;
        showLoader(true);
        try {
            await api('delete', { method: 'POST', query: { numero: num } });
            showToast('Operario borrado', 'success');
            await cargar();
        } catch (e) {
            // El endpoint devuelve mensaje claro si tiene intervenciones
            // (HTTP 409). Lo mostramos textual para que el técnico sepa qué hacer.
            showToast('No se ha podido borrar: ' + (e.message || 'error desconocido'), 'error');
        } finally {
            showLoader(false);
        }
    }

    // ── Event listeners ─────────────────────────────────────────────────
    function init() {
        $('#ops-new-btn').addEventListener('click', openCrear);
        $('#ops-modal-close').addEventListener('click', cerrarModal);
        $('#ops-cancel-btn').addEventListener('click', cerrarModal);
        $('#ops-modal-backdrop').addEventListener('click', e => {
            if (e.target === $('#ops-modal-backdrop')) cerrarModal();
        });
        $('#ops-save-btn').addEventListener('click', guardar);
        $('#ops-search').addEventListener('input', renderTabla);
        $('#ops-only-active').addEventListener('change', cargar);
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && $('#ops-modal-backdrop').classList.contains('open')) cerrarModal();
        });
        // Delegación: un único listener sobre #ops-tbody captura los clicks
        // en los botones ✎ y ×. Sobrevive a los re-renders sin que tengamos
        // que reconectar listeners cada vez.
        const tb = $('#ops-tbody');
        if (tb) {
            tb.addEventListener('click', e => {
                const btn = e.target.closest('button[data-act]');
                if (!btn || !tb.contains(btn)) return;
                e.stopPropagation();
                const num = btn.dataset.num || btn.closest('tr')?.dataset.num;
                if (!num) {
                    showToast('No se pudo identificar el operario', 'error');
                    return;
                }
                if (btn.dataset.act === 'edit')   openEditar(num);
                if (btn.dataset.act === 'delete') borrar(num);
            });
        }
        cargar();
    }
    // El JS se carga con el resto del <body> ya pintado en muchos casos,
    // por lo que comprobamos el readyState para iniciar siempre.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
