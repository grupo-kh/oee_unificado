/* Vista Mantenimiento · Histórico por Máquina
   Tabla simple (fecha, máquina, operario) filtrable por los selectores
   superiores. Los gráficos Top Máquinas / Top Operarios se han retirado. */

let _fDesde = '';
let _fHasta = '';
let _selMaq = '';
let _selOp  = '';
let _selPer = '';

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
function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function defaultFechas() {
    const hoy = new Date();
    const desde = new Date(hoy.getTime() - 365 * 86400 * 1000);
    const fmt = d => d.toISOString().substring(0, 10);
    return { desde: fmt(desde), hasta: fmt(hoy) };
}

function populate(selId, items, valueKey, textKey, current, placeholder) {
    const sel = document.getElementById(selId);
    if (!sel) return;
    sel.innerHTML = `<option value="">${placeholder}</option>`;
    items.forEach(it => {
        const o = document.createElement('option');
        if (typeof it === 'string') {
            o.value = it; o.textContent = it;
        } else {
            o.value = it[valueKey];
            o.textContent = textKey ? (it[textKey] + ' (' + it[valueKey] + ')') : it[valueKey];
        }
        sel.appendChild(o);
    });
    sel.value = current || '';
}

// Índice id→intervención para la edición rápida desde el popup.
// Cada vez que renderizamos el histórico repoblamos este mapa.
let _histInterventionsById = new Map();

// Formatea un tiempo en segundos a minutos enteros (redondeo a 30s).
// Los segundos no se muestran al usuario aunque siguen guardados en BD
// para mantener la variación ±5..10 entre intervenciones.
function fmtTiempoSeg(seg) {
    if (seg === null || seg === undefined || seg === '') return '—';
    const n = parseInt(seg, 10);
    if (isNaN(n) || n < 0) return '—';
    if (n >= 3600) {
        const h = Math.floor(n / 3600);
        const m = Math.round((n % 3600) / 60);
        return h + 'h ' + m + 'm';
    }
    const totalMin = Math.round(n / 60);
    return totalMin + ' min';
}

// Render del histórico agrupado por máquina → tarea → intervenciones.
// Cada máquina es una tarjeta plegable; al desplegar muestra la lista de
// tareas preventivas y, dentro de cada tarea, las fechas e intervenciones
// (operario, tipo) en el rango filtrado.
function _renderInterventionsHtml(interventions, ctx) {
    return interventions.map(i => {
        // Guardamos en el índice global (necesita ctx con datos de la tarea
        // padre para el contexto del popup: máquina, periodicidad, tarea, etc.)
        if (i.id) {
            _histInterventionsById.set(String(i.id), Object.assign({}, i, ctx || {}));
        }
        const isMissed  = i.tipo === 'no_realizada';
        const isCatchup = i.tipo === 'recuperacion';
        const isIncomp  = !!i.visita_incompleta;
        const fecha = isMissed
            ? `${fmtFecha(i.fecha_proxima_original)} <span class="mant-cod">(no realizada)</span>`
            : fmtFecha(i.fecha_intervencion);
        let badge = '';
        if      (isMissed)  badge = ' <span class="mant-source-badge mant-source-missed">PEND</span>';
        else if (isCatchup) badge = ' <span class="mant-source-badge mant-source-catchup">RECUP</span>';
        // Pildora INCOMPLETA — además del badge principal — para visitas
        // consolidadas en las que solo se hicieron algunas sub-tareas.
        if (isIncomp) badge += ' <span class="mant-source-badge mant-source-incomp" title="Visita consolidada parcial: no se hicieron todas las sub-tareas">INCOMPLETA</span>';
        const opStr = String(i.operario ?? '').trim();
        const operario = opStr !== ''
            ? escHtml(opStr)
            : '<span class="mant-cod" style="font-style:italic">(sin operario)</span>';
        const rowClass = isMissed ? 'mant-row-missed'
                       : isCatchup ? 'mant-row-catchup' : '';
        // Tiempo real registrado. Si no hay (intervención antigua sin
        // tiempo_estimado en su día) mostramos "—".
        const tiempoTxt = fmtTiempoSeg(i.tiempo_real_segundos);
        // Solo técnico: añadimos clase clickable + data-id para abrir popup.
        const isTecnico = (document.body.dataset.role || '') === 'tecnico';
        const clickAttrs = (isTecnico && i.id)
            ? ` class="${rowClass} mant-row-edit" data-id="${escHtml(String(i.id))}" title="Clic para editar la intervención"`
            : ` class="${rowClass}"`;
        return `<tr${clickAttrs}>
            <td class="mant-fecha" style="width:110px">${fecha}</td>
            <td>${operario}${badge}</td>
            <td class="mant-tiempo" style="width:90px;text-align:right">${tiempoTxt}</td>
        </tr>`;
    }).join('');
}

function _renderTasksHtml(tasks, machineCtx) {
    return tasks.map(t => {
        // Contexto que el popup necesita para mostrar de qué tarea/máquina hablamos
        const ctx = {
            cod_maquina_mant: machineCtx && machineCtx.cod_maquina_mant,
            desc_maquina:     machineCtx && machineCtx.desc_maquina,
            tarea_label:      t.consolidada ? 'Revisión completa' : ('Tarea ' + t.tarea),
            periodicidad:     t.periodicidad,
            desc_tarea:       t.desc_tarea,
        };
        const intsHtml = _renderInterventionsHtml(t.interventions, ctx);
        const isConsol = !!t.consolidada;
        // El tiempo estimado por tarea NO se muestra en la cabecera: cada
        // revisión individual lleva su tiempo real (con la variación ±5..10 s)
        // en la columna de la derecha de cada fila.
        const titleStrong = isConsol
            ? `<strong>Revisión completa</strong> <span class="mant-consol-badge">${t.subtareas_total} tareas</span>`
            : `<strong>Tarea ${escHtml(t.tarea)}</strong>`;
        const descHtml = isConsol
            ? `<div class="acc-desc-consol">${escHtml(t.desc_tarea || '')}</div>`
            : `<span class="mant-cod">${escHtml(t.desc_tarea || t.desc_grupo || '')}</span>`;
        const visitWord = isConsol ? 'visita' : 'intervención';
        const visitWordPl = isConsol ? 'visitas' : 'intervenciones';
        return `
            <div class="mant-task-block${isConsol ? ' mant-task-consolidada' : ''}">
                <div class="mant-task-title">
                    <span class="mant-pill mant-pill-${(t.periodicidad||'').toLowerCase()}">${escHtml(t.periodicidad || '—')}</span>
                    ${titleStrong}
                    ${descHtml}
                    <span class="mant-task-count">· ${t.total_intervenciones} ${t.total_intervenciones === 1 ? visitWord : visitWordPl}</span>
                </div>
                <table class="mant-task-table">
                    <tbody>${intsHtml}</tbody>
                </table>
            </div>
        `;
    }).join('');
}

function _renderMachineCard(m, autoOpen, extraClass) {
    return `
        <div class="mant-machine-card${autoOpen ? ' open' : ''}${extraClass ? ' ' + extraClass : ''}" data-cod="${escHtml(m.cod_maquina_mant)}">
            <div class="mant-machine-header" data-toggle>
                <span class="mant-toggle">${autoOpen ? '▼' : '▶'}</span>
                <strong>${escHtml(m.desc_maquina)}</strong>
                <span class="mant-cod">(${escHtml(m.cod_maquina_mant)})</span>
                <span class="mant-machine-count">${m.total_intervenciones} intervención${m.total_intervenciones === 1 ? '' : 'es'} · ${m.total_tareas} tarea${m.total_tareas === 1 ? '' : 's'}</span>
            </div>
            <div class="mant-machine-body" ${autoOpen ? '' : 'style="display:none"'}>
                ${_renderTasksHtml(m.tasks, m)}
            </div>
        </div>
    `;
}

function _renderFamilyCard(m, autoOpen) {
    const childrenHtml = m.children.map(c => _renderMachineCard(c, false, 'mant-machine-card-child')).join('');
    return `
        <div class="mant-machine-card mant-family-card${autoOpen ? ' open' : ''}" data-family="${escHtml(m.family_key)}">
            <div class="mant-machine-header" data-toggle>
                <span class="mant-toggle">${autoOpen ? '▼' : '▶'}</span>
                <span class="mant-family-badge">FAMILIA</span>
                <strong>${escHtml(m.desc_maquina)}</strong>
                <span class="mant-machine-count">${m.total_maquinas} máquina${m.total_maquinas === 1 ? '' : 's'} · ${m.total_intervenciones} intervención${m.total_intervenciones === 1 ? '' : 'es'}</span>
            </div>
            <div class="mant-machine-body" ${autoOpen ? '' : 'style="display:none"'}>
                ${childrenHtml}
            </div>
        </div>
    `;
}

// Render del histórico agrupado por máquina → tarea → intervenciones.
// Cada máquina es una tarjeta plegable; al desplegar muestra la lista de
// tareas preventivas y, dentro de cada tarea, las fechas e intervenciones
// (operario, tipo) en el rango filtrado.
function renderMachines(machines) {
    const wrap = $('#mant-machines-wrap');
    if (!wrap) return;
    // Reset del índice antes de cada render
    _histInterventionsById = new Map();
    if (!machines || !machines.length) {
        wrap.innerHTML = '<div class="mant-empty">Sin intervenciones para los filtros seleccionados</div>';
        return;
    }

    // Si hay solo una entrada (filtro por máquina aplicado), la abrimos
    // automáticamente para que el usuario vea las tareas de inmediato.
    const autoOpen = machines.length === 1;

    wrap.innerHTML = machines.map(m => m.is_family
        ? _renderFamilyCard(m, autoOpen)
        : _renderMachineCard(m, autoOpen)
    ).join('');

    // Click delegado en filas editables (solo técnico — el HTML solo añade
    // la clase mant-row-edit si data-role="tecnico").
    wrap.querySelectorAll('tr.mant-row-edit').forEach(tr => {
        tr.addEventListener('click', (ev) => {
            ev.stopPropagation();
            const id = tr.dataset.id;
            if (!id) return;
            const inter = _histInterventionsById.get(String(id));
            if (!inter) { showToast('No se ha encontrado la intervención', 'error'); return; }
            abrirPopupEdicionIntervencion(inter);
        });
    });

    // Wire up del toggle: clic en cabecera abre/cierra el cuerpo. Funciona
    // tanto para tarjetas de máquina como para familias (que contienen
    // máquinas hijas con su propio toggle independiente).
    wrap.querySelectorAll('.mant-machine-header[data-toggle]').forEach(h => {
        h.addEventListener('click', (ev) => {
            ev.stopPropagation();
            const card = h.parentElement;
            const body = card.querySelector(':scope > .mant-machine-body');
            const tog  = h.querySelector('.mant-toggle');
            const isOpen = card.classList.toggle('open');
            body.style.display = isOpen ? '' : 'none';
            tog.textContent = isOpen ? '▼' : '▶';
        });
    });
}

async function cargarVista() {
    showLoader(true);
    try {
        const params = { fecha_desde: _fDesde, fecha_hasta: _fHasta, limit: 20000 };
        if (_selMaq) params.cod_maquina_mant = _selMaq;
        if (_selOp)  params.operario         = _selOp;
        if (_selPer) params.periodicidad     = _selPer;

        const d = await apiFetch('mant_historico.php', params);

        populate('machine-selector',     d.maquinas       || [], 'cod_maquina_mant', 'desc_maquina', _selMaq, '— Todas —');
        populate('operario-selector',    d.operarios      || [], null, null, _selOp,  '— Todos —');
        populate('periodicidad-selector', d.periodicidades || [], null, null, _selPer, '— Todas —');

        const okMaq = !_selMaq || (d.maquinas || []).some(m => m.cod_maquina_mant === _selMaq);
        const okOp  = !_selOp  || (d.operarios || []).includes(_selOp);
        const okPer = !_selPer || (d.periodicidades || []).includes(_selPer);
        if (!okMaq) { _selMaq = ''; $('#machine-selector').value = ''; updateUrlParams({ cod_maquina_mant: '' }); }
        if (!okOp)  { _selOp  = ''; $('#operario-selector').value = ''; updateUrlParams({ operario: '' }); }
        if (!okPer) { _selPer = ''; $('#periodicidad-selector').value = ''; updateUrlParams({ periodicidad: '' }); }

        const btn = $('#filter-clear');
        if (btn) btn.style.display = (_selMaq || _selOp || _selPer) ? '' : 'none';

        const scopeBits = [];
        if (_selMaq) {
            const m = (d.maquinas || []).find(x => x.cod_maquina_mant === _selMaq);
            scopeBits.push('máq: ' + (m ? m.desc_maquina : _selMaq));
        }
        if (_selOp)  scopeBits.push('op: ' + _selOp);
        if (_selPer) scopeBits.push('per: ' + _selPer);
        $('#header-scope').textContent = scopeBits.length ? '· ' + scopeBits.join(' · ') : '';

        $('#info-line').textContent = fmtFecha(d.fecha_desde) + ' → ' + fmtFecha(d.fecha_hasta) + ' · ' + d.total + ' intervenciones';
        $('#stat-total').textContent     = d.total;
        $('#stat-maquinas').textContent  = d.maquinas_distintas;
        $('#footer-actualizado').textContent = 'Fichero actualizado: ' + (d.fichero_actualizado || '—');
        $('#mant-table-count').textContent = d.truncado
            ? `(mostrando ${d.mostrados} de ${d.total})`
            : `(${d.total})`;

        const trunc = $('#mant-truncado');
        if (d.truncado) {
            trunc.style.display = '';
            trunc.textContent = `Mostrando las primeras ${d.mostrados} de ${d.total} filas. Ajusta filtros para ver el resto.`;
        } else {
            trunc.style.display = 'none';
        }

        renderMachines(d.machines || []);

    } catch (e) {
        showToast('Error: ' + e.message, 'error');
        const wrap = $('#mant-machines-wrap');
        if (wrap) wrap.innerHTML = '<div class="mant-empty">Error cargando datos</div>';
    } finally {
        showLoader(false);
    }
}

function onDesdeChange() { _fDesde = $('#f-desde').value; updateUrlParams({ fecha_desde: _fDesde }); cargarVista(); }
function onHastaChange() { _fHasta = $('#f-hasta').value; updateUrlParams({ fecha_hasta: _fHasta }); cargarVista(); }
function onMaqChange()   { _selMaq = $('#machine-selector').value || ''; updateUrlParams({ cod_maquina_mant: _selMaq }); cargarVista(); }
function onOpChange()    { _selOp  = $('#operario-selector').value || ''; updateUrlParams({ operario: _selOp }); cargarVista(); }
function onPerChange()   { _selPer = $('#periodicidad-selector').value || ''; updateUrlParams({ periodicidad: _selPer }); cargarVista(); }
function onClearFilters() {
    _selMaq = ''; _selOp = ''; _selPer = '';
    $('#machine-selector').value = '';
    $('#operario-selector').value = '';
    $('#periodicidad-selector').value = '';
    updateUrlParams({ cod_maquina_mant: '', operario: '', periodicidad: '' });
    cargarVista();
}

// ─── Popup de edición de intervención (solo técnico) ───────────────────────
let _histEditingId = null;
let _histEditingTipo = null;

function abrirPopupEdicionIntervencion(inter) {
    if (!inter || !inter.id) return;
    const modal = document.getElementById('hist-edit-modal');
    if (!modal) return;
    _histEditingId   = inter.id;
    _histEditingTipo = inter.tipo || 'completada';

    // Cabecera resumen
    const periodPill = inter.periodicidad
        ? `<span class="mant-pill mant-pill-${(inter.periodicidad||'').toLowerCase()}">${escHtml(inter.periodicidad)}</span> `
        : '';
    const summary = `${periodPill}<strong>${escHtml(inter.desc_maquina || inter.cod_maquina_mant || '')}</strong>`
                  + `<br><span class="mant-cod">${escHtml(inter.tarea_label || '')} · ${escHtml(inter.desc_tarea || '')}</span>`
                  + `<br><span class="mant-cod">programada: ${fmtFecha(inter.fecha_proxima_original)}</span>`;
    document.getElementById('hist-edit-summary').innerHTML = summary;

    // Visibilidad de campos según tipo
    const isMissed = (_histEditingTipo === 'no_realizada');
    document.getElementById('hist-edit-fecha-wrap').style.display  = isMissed ? 'none' : '';
    document.getElementById('hist-edit-hora-wrap').style.display   = isMissed ? 'none' : '';
    document.getElementById('hist-edit-motivo-wrap').style.display = isMissed ? '' : 'none';

    // Valores actuales
    document.getElementById('hist-edit-fecha').value    = inter.fecha_intervencion || '';
    document.getElementById('hist-edit-hora').value     = inter.hora_inicio        || '';
    document.getElementById('hist-edit-operario').value = inter.operario           || '';
    document.getElementById('hist-edit-obs').value      = inter.observaciones      || '';
    document.getElementById('hist-edit-motivo').value   = inter.motivo_no_realizada || '';
    const incEl = document.getElementById('hist-edit-incompleta');
    if (incEl) incEl.checked = !!inter.visita_incompleta;

    // Tiempo en minutos (input visible). Los segundos se guardan en un
    // hidden para preservarlos si el usuario no cambia el minuto.
    const seg = inter.tiempo_real_segundos;
    if (seg !== null && seg !== undefined && seg !== '') {
        const n = parseInt(seg, 10) || 0;
        document.getElementById('hist-edit-tiempo-min').value = Math.round(n / 60);
        document.getElementById('hist-edit-tiempo-seg').value = n;  // segundos totales originales
    } else {
        document.getElementById('hist-edit-tiempo-min').value = '';
        document.getElementById('hist-edit-tiempo-seg').value = '';
    }

    modal.style.display = '';
    modal.setAttribute('aria-hidden', 'false');
}

function cerrarPopupEdicionIntervencion() {
    const modal = document.getElementById('hist-edit-modal');
    if (modal) { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); }
    _histEditingId   = null;
    _histEditingTipo = null;
}

async function guardarEdicionIntervencion() {
    if (!_histEditingId) return;
    const isMissed = (_histEditingTipo === 'no_realizada');

    // Reconstruir tiempo en segundos:
    //   - El hidden "hist-edit-tiempo-seg" guarda los segundos totales que
    //     tenía la intervención al abrir el modal (ej. 605s para 10 min).
    //   - Si el usuario no cambia el campo minutos, mantenemos los segundos
    //     originales para no perder la variación.
    //   - Si los minutos visibles ya no encajan con los segundos guardados
    //     (cambio del usuario), recalculamos con un jitter ±5s sobre los
    //     nuevos minutos para mantener la apariencia natural.
    const minRaw = document.getElementById('hist-edit-tiempo-min').value;
    const segOrig = document.getElementById('hist-edit-tiempo-seg').value;
    let tiempoSeg = null;
    if (minRaw !== '') {
        const m = parseInt(minRaw, 10);
        if (isNaN(m) || m < 0) {
            showToast('Tiempo inválido (minutos enteros ≥ 0)', 'error'); return;
        }
        const segOrigNum = parseInt(segOrig || '0', 10) || 0;
        const minDelOrig = Math.round(segOrigNum / 60);
        if (m === minDelOrig && segOrigNum > 0) {
            // Sin cambios visibles → preservamos segundos originales
            tiempoSeg = segOrigNum;
        } else {
            // El usuario cambió los minutos → nuevos segundos con jitter ±5s
            const jitter = Math.floor(Math.random() * 11) - 5;
            tiempoSeg = m * 60 + jitter;
            if (tiempoSeg < 0) tiempoSeg = 0;
        }
        if (tiempoSeg > 36000) { showToast('Tiempo máximo 10 horas', 'error'); return; }
    }

    // Validación operario: solo dígitos (código numérico), no nombres.
    const opVal = (document.getElementById('hist-edit-operario').value || '').trim();
    if (opVal !== '' && !/^\d+$/.test(opVal)) {
        showToast('El operario debe ser un código numérico (ej. 1004), no un nombre', 'error');
        return;
    }
    const incEl = document.getElementById('hist-edit-incompleta');
    const body = {
        id:                   _histEditingId,
        operario:             opVal,
        observaciones:        document.getElementById('hist-edit-obs').value,
        tiempo_real_segundos: tiempoSeg,
        visita_incompleta:    incEl ? !!incEl.checked : false,
    };
    if (isMissed) {
        body.motivo_no_realizada = document.getElementById('hist-edit-motivo').value;
    } else {
        body.fecha_intervencion = document.getElementById('hist-edit-fecha').value;
        body.hora_inicio        = document.getElementById('hist-edit-hora').value;
    }

    showLoader(true);
    try {
        const url = '../api/mant_historico_update.php';
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': (window.__CSRF_TOKEN || '')
            },
            body: JSON.stringify(body),
        });
        const j = await res.json();
        if (!res.ok || !j.ok) {
            const err = (j && j.error) || ('HTTP ' + res.status);
            throw new Error(err);
        }
        showToast('Intervención actualizada', 'success');
        cerrarPopupEdicionIntervencion();
        // Recarga el listado para reflejar los cambios
        cargarVista();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const def = defaultFechas();
    _fDesde = getQueryParam('fecha_desde') || def.desde;
    _fHasta = getQueryParam('fecha_hasta') || def.hasta;
    _selMaq = getQueryParam('cod_maquina_mant') || '';
    _selOp  = getQueryParam('operario') || '';
    _selPer = getQueryParam('periodicidad') || '';

    $('#f-desde').value = _fDesde;
    $('#f-hasta').value = _fHasta;

    $('#f-desde').addEventListener('change', onDesdeChange);
    $('#f-hasta').addEventListener('change', onHastaChange);
    $('#machine-selector').addEventListener('change', onMaqChange);
    $('#operario-selector').addEventListener('change', onOpChange);
    $('#periodicidad-selector').addEventListener('change', onPerChange);
    const c = $('#filter-clear'); if (c) c.addEventListener('click', onClearFilters);

    const btnCsv  = $('#btn-export-csv');
    const btnXlsx = $('#btn-export-xlsx');
    if (btnCsv)  btnCsv.addEventListener('click',  () => exportarHistorico('csv'));
    if (btnXlsx) btnXlsx.addEventListener('click', () => exportarHistorico('xlsx'));

    // Listeners del popup de edición (solo se disparan si existen los elementos
    // — los elementos están envueltos en role-tecnico-only por CSS).
    const cb = $('#hist-edit-close');    if (cb) cb.addEventListener('click', cerrarPopupEdicionIntervencion);
    const can = $('#hist-edit-cancel');  if (can) can.addEventListener('click', cerrarPopupEdicionIntervencion);
    const bd = $('#hist-edit-backdrop'); if (bd) bd.addEventListener('click', cerrarPopupEdicionIntervencion);
    const sv = $('#hist-edit-save');     if (sv) sv.addEventListener('click', guardarEdicionIntervencion);
    document.addEventListener('keydown', (e) => {
        const m = document.getElementById('hist-edit-modal');
        if (e.key === 'Escape' && m && m.style.display !== 'none') cerrarPopupEdicionIntervencion();
    });

    initFiltros(cargarVista);
    cargarVista();
});

// Export del histórico aplicando los filtros actuales del formulario.
// Abre la URL del endpoint con los filtros como query string — el navegador
// dispara la descarga directamente.
function exportarHistorico(formato) {
    const params = new URLSearchParams({
        formato:     formato,
        fecha_desde: _fDesde,
        fecha_hasta: _fHasta,
    });
    if (_selMaq) params.set('cod_maquina_mant', _selMaq);
    if (_selOp)  params.set('operario',         _selOp);
    if (_selPer) params.set('periodicidad',     _selPer);
    const url = '../api/mant_historico_export.php?' + params.toString();
    // Abre como descarga (el endpoint marca Content-Disposition: attachment).
    window.location.href = url;
}
