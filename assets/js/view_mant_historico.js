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
    const desde = new Date(hoy.getTime() - 90 * 86400 * 1000);
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

// Render del histórico agrupado por máquina → tarea → intervenciones.
// Cada máquina es una tarjeta plegable; al desplegar muestra la lista de
// tareas preventivas y, dentro de cada tarea, las fechas e intervenciones
// (operario, tipo) en el rango filtrado.
function renderMachines(machines) {
    const wrap = $('#mant-machines-wrap');
    if (!wrap) return;
    if (!machines || !machines.length) {
        wrap.innerHTML = '<div class="mant-empty">Sin intervenciones para los filtros seleccionados</div>';
        return;
    }

    // Si hay solo una máquina (filtro por máquina aplicado), la abrimos
    // automáticamente para que el usuario vea las tareas de inmediato.
    const autoOpen = machines.length === 1;

    wrap.innerHTML = machines.map(m => {
        const tasksHtml = m.tasks.map(t => {
            const intsHtml = t.interventions.map(i => {
                const isMissed  = i.tipo === 'no_realizada';
                const isCatchup = i.tipo === 'recuperacion';
                const fecha = isMissed
                    ? `${fmtFecha(i.fecha_proxima_original)} <span class="mant-cod">(no realizada)</span>`
                    : fmtFecha(i.fecha_intervencion);
                let badge = '';
                if      (isMissed)  badge = ' <span class="mant-source-badge mant-source-missed">PEND</span>';
                else if (isCatchup) badge = ' <span class="mant-source-badge mant-source-catchup">RECUP</span>';
                const opStr = String(i.operario ?? '').trim();
                const operario = opStr !== ''
                    ? escHtml(opStr)
                    : '<span class="mant-cod" style="font-style:italic">(sin operario)</span>';
                const rowClass = isMissed ? 'mant-row-missed'
                               : isCatchup ? 'mant-row-catchup' : '';
                return `<tr class="${rowClass}">
                    <td class="mant-fecha" style="width:110px">${fecha}</td>
                    <td>${operario}${badge}</td>
                </tr>`;
            }).join('');
            return `
                <div class="mant-task-block">
                    <div class="mant-task-title">
                        <span class="mant-pill mant-pill-${(t.periodicidad||'').toLowerCase()}">${escHtml(t.periodicidad || '—')}</span>
                        <strong>Tarea ${escHtml(t.tarea)}</strong>
                        <span class="mant-cod">${escHtml(t.desc_tarea || t.desc_grupo || '')}</span>
                        <span class="mant-task-count">· ${t.total_intervenciones} intervención${t.total_intervenciones === 1 ? '' : 'es'}</span>
                    </div>
                    <table class="mant-task-table">
                        <tbody>${intsHtml}</tbody>
                    </table>
                </div>
            `;
        }).join('');

        return `
            <div class="mant-machine-card${autoOpen ? ' open' : ''}" data-cod="${escHtml(m.cod_maquina_mant)}">
                <div class="mant-machine-header" data-toggle>
                    <span class="mant-toggle">${autoOpen ? '▼' : '▶'}</span>
                    <strong>${escHtml(m.desc_maquina)}</strong>
                    <span class="mant-cod">(${escHtml(m.cod_maquina_mant)})</span>
                    <span class="mant-machine-count">${m.total_intervenciones} intervención${m.total_intervenciones === 1 ? '' : 'es'} · ${m.total_tareas} tarea${m.total_tareas === 1 ? '' : 's'}</span>
                </div>
                <div class="mant-machine-body" ${autoOpen ? '' : 'style="display:none"'}>
                    ${tasksHtml}
                </div>
            </div>
        `;
    }).join('');

    // Wire up del toggle. Click en cabecera abre/cierra el cuerpo de la
    // máquina (excepto si pulsas sobre los textos en negrita, que no
    // bloqueamos: que sea fácil).
    wrap.querySelectorAll('.mant-machine-header[data-toggle]').forEach(h => {
        h.addEventListener('click', () => {
            const card = h.closest('.mant-machine-card');
            const body = card.querySelector('.mant-machine-body');
            const tog  = card.querySelector('.mant-toggle');
            const isOpen = card.classList.toggle('open');
            body.style.display = isOpen ? '' : 'none';
            tog.textContent = isOpen ? '▼' : '▶';
        });
    });
}

async function cargarVista() {
    showLoader(true);
    try {
        const params = { fecha_desde: _fDesde, fecha_hasta: _fHasta };
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
        $('#stat-operarios').textContent = d.operarios_distintos;
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
