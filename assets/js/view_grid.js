/* =========================================================
   Vista GRID - Tabla pivote Plan/Producido por HORA
   Replica el grid del dashboard QlikView original:
   - Cabecera con Fch (fecha productiva) y Tur (turno)
   - Columnas: horas del turno seleccionado
   - Filas: máquina + cód. artículo, con subfilas Plan / Prod
   ========================================================= */

const TURNO_LABEL = { M: 'MAÑANA', T: 'TARDE', N: 'NOCHE', C: 'CENTRAL' };

const _gridFilters = { maquina: '', articulo: '' };
let _gridLastData = null;
let _gridMeta = null;

function renderGrid(data) {
    _gridLastData = data;

    const tbl   = $('#grid-table');
    const thead = tbl.querySelector('thead');
    const tbody = tbl.querySelector('tbody');

    // Preservar foco/cursor del input de filtro antes de redibujar
    const active = document.activeElement;
    const wasFiltering = active && active.classList && active.classList.contains('col-hdr-filter');
    const activeKey = wasFiltering ? active.dataset.filter : null;
    const activeSelStart = wasFiltering ? active.selectionStart : null;
    const activeSelEnd   = wasFiltering ? active.selectionEnd   : null;

    thead.innerHTML = '';
    tbody.innerHTML = '';

    const { horas, fecha, turno } = data;
    const fechaLabel = formatFechaCorta(fecha);
    const turnoLabel = TURNO_LABEL[turno] || turno;
    const nHoras = horas.length;

    // Aplicar filtros de cabecera (máquina / artículo)
    const fMq = _gridFilters.maquina.trim().toLowerCase();
    const fAr = _gridFilters.articulo.trim().toLowerCase();
    const filas = (data.filas || []).filter(f => {
        if (fMq && !String(f.maquina).toLowerCase().includes(fMq)) return false;
        if (fAr && !String(f.cod_articulo).toLowerCase().includes(fAr)) return false;
        return true;
    });

    // ============ CABECERA ============
    // Tres filas: Fch, Tur, Hor (labels). Las 3 primeras columnas hacen rowspan=3.
    const escMq = _gridFilters.maquina.replace(/"/g, '&quot;');
    const escAr = _gridFilters.articulo.replace(/"/g, '&quot;');
    const trFch = document.createElement('tr');
    trFch.className = 'header-meta';
    trFch.innerHTML = `
        <th rowspan="3" class="col-maquina">
            <div class="col-hdr-title">Máquina</div>
            <input type="text" class="col-hdr-filter" data-filter="maquina" placeholder="Filtrar…" value="${escMq}">
        </th>
        <th rowspan="3" class="col-articulo">
            <div class="col-hdr-title">Cód. Artículo</div>
            <input type="text" class="col-hdr-filter" data-filter="articulo" placeholder="Filtrar…" value="${escAr}">
        </th>
        <th rowspan="3" class="col-label">Hor</th>
        <th colspan="${nHoras}" class="header-fch">
            <span class="meta-dot"></span> Fch: ${fechaLabel}
        </th>
        <th rowspan="3" class="col-total">Total</th>`;
    thead.appendChild(trFch);

    const trTur = document.createElement('tr');
    trTur.className = 'header-meta';
    trTur.innerHTML = `<th colspan="${nHoras}" class="header-tur">Tur: ${turnoLabel}</th>`;
    thead.appendChild(trTur);

    const trHor = document.createElement('tr');
    trHor.className = 'header-hour';
    horas.forEach(h => {
        const th = document.createElement('th');
        th.innerHTML = `<div class="hour-date">${h.fecha}</div><div class="hour-time">${h.label}</div>`;
        trHor.appendChild(th);
    });
    thead.appendChild(trHor);

    // ============ CUERPO ============
    if (!filas.length) {
        const msg = (fMq || fAr)
            ? 'Sin coincidencias con el filtro actual'
            : 'Sin datos para el día y turno seleccionados';
        tbody.innerHTML = `
            <tr>
                <td colspan="${3 + nHoras + 1}"
                    style="text-align:center;padding:40px;color:#5b8cc7;font-size:14px">
                    ${msg}
                </td>
            </tr>`;
        attachHeaderFilterListeners(thead, activeKey, activeSelStart, activeSelEnd);
        return;
    }

    const fmt = new Intl.NumberFormat('es-ES');

    const renderCell = (val, pct) => {
        const td = document.createElement('td');
        td.className = 'cell-data';
        if (val === undefined || val === null) {
            td.classList.add('cell-empty');
            return td;
        }
        if (pct === null) {
            td.classList.add('cell-empty');
        } else {
            td.classList.add(semClass3(pct));
        }
        td.textContent = fmt.format(val);
        return td;
    };

    // Agrupar filas por máquina para aplicar rowspan a la celda Máquina
    const grupos = new Map();
    filas.forEach(f => {
        if (!grupos.has(f.maquina)) grupos.set(f.maquina, []);
        grupos.get(f.maquina).push(f);
    });

    grupos.forEach((items, maquina) => {
        items.forEach((fila, idx) => {
            // --- Fila PLAN ---
            const trPlan = document.createElement('tr');
            trPlan.className = 'row-plan';

            if (idx === 0) {
                const tdMaq = document.createElement('td');
                tdMaq.className = 'col-maquina';
                tdMaq.textContent = maquina;
                tdMaq.rowSpan = items.length * 2;
                trPlan.appendChild(tdMaq);
            }

            const tdArt = document.createElement('td');
            tdArt.className = 'col-articulo';
            tdArt.textContent = fila.cod_articulo;
            tdArt.rowSpan = 2;
            trPlan.appendChild(tdArt);

            const tdLblP = document.createElement('td');
            tdLblP.className = 'col-label label-plan';
            tdLblP.textContent = 'Plan';
            trPlan.appendChild(tdLblP);

            let totalPlan = 0, totalProd = 0;
            horas.forEach(h => {
                const plan = fila.plan[h.hora];
                const prod = fila.prod[h.hora];
                const hasPlan = plan !== undefined && plan !== null;
                const hasProd = prod !== undefined && prod !== null;
                const pct = (plan > 0 && hasProd) ? (prod / plan) * 100 : null;
                trPlan.appendChild(renderCell(hasPlan ? plan : null, pct));
                if (hasPlan) totalPlan += plan;
                if (hasProd) totalProd += prod;
            });
            // Total Plan (cell at end of plan row)
            const totalPct = (totalPlan > 0) ? (totalProd / totalPlan) * 100 : null;
            const tdTotPlan = renderCell(totalPlan || null, totalPct);
            tdTotPlan.classList.add('cell-total');
            trPlan.appendChild(tdTotPlan);
            tbody.appendChild(trPlan);

            // --- Fila PROD ---
            const trProd = document.createElement('tr');
            trProd.className = 'row-prod';

            const tdLblR = document.createElement('td');
            tdLblR.className = 'col-label label-prod';
            tdLblR.textContent = 'Prod';
            trProd.appendChild(tdLblR);

            horas.forEach(h => {
                const plan = fila.plan[h.hora];
                const prod = fila.prod[h.hora];
                const pct = (plan > 0 && prod !== undefined && prod !== null) ? (prod / plan) * 100 : null;
                trProd.appendChild(renderCell(prod, pct));
            });
            // Total Prod (cell at end of prod row)
            const tdTotProd = renderCell(totalProd || null, totalPct);
            tdTotProd.classList.add('cell-total');
            trProd.appendChild(tdTotProd);
            tbody.appendChild(trProd);
        });
    });

    attachHeaderFilterListeners(thead, activeKey, activeSelStart, activeSelEnd);
}

function attachHeaderFilterListeners(thead, restoreKey, selStart, selEnd) {
    thead.querySelectorAll('.col-hdr-filter').forEach(inp => {
        inp.addEventListener('input', (e) => {
            _gridFilters[e.target.dataset.filter] = e.target.value;
            if (_gridLastData) renderGrid(_gridLastData);
        });
        // Evitar que clicks en el input se propaguen al header (ordenación, etc.)
        inp.addEventListener('click', e => e.stopPropagation());
        if (restoreKey && inp.dataset.filter === restoreKey) {
            inp.focus();
            try { inp.setSelectionRange(selStart, selEnd); } catch (_) {}
        }
    });
}

let _gridAbort = null;
let _gridReqId = 0;

function showTableMessage(msg, color = '#5b8cc7') {
    const tbl = $('#grid-table');
    if (!tbl) return;
    tbl.querySelector('thead').innerHTML = '';
    tbl.querySelector('tbody').innerHTML =
        `<tr><td style="text-align:center;padding:60px 40px;color:${color};font-size:14px">
            ${msg}
         </td></tr>`;
}

async function cargarVista() {
    // Cancelar petición pendiente (si el usuario cambió de filtro muy rápido)
    if (_gridAbort) _gridAbort.abort();
    _gridAbort = new AbortController();
    const reqId = ++_gridReqId;

    const f = getFiltrosActuales();
    if (!f.turno) {
        showTableMessage('Selecciona un turno (Mañana / Tarde / Noche / Central) para ver el desglose horario', '#b45309');
        return;
    }

    // Feedback inmediato: vaciar tabla y mostrar "Cargando..."
    showTableMessage(`Cargando datos de ${formatFechaCorta(f.fecha)} — ${ {M:'MAÑANA',T:'TARDE',N:'NOCHE',C:'CENTRAL'}[f.turno] }…`);
    showLoader(true);
    setFiltrosEnabled(false);

    try {
        const data = await apiFetch('grid.php', { fecha: f.fecha, turno: f.turno }, _gridAbort.signal);
        // Ignorar respuesta si ya no es la petición vigente (carrera entre filtros)
        if (reqId !== _gridReqId) return;
        _gridMeta = data.meta || null;
        renderGrid(data);
    } catch (e) {
        if (e.name === 'AbortError') return; // petición cancelada por otra más reciente
        showToast('Error: ' + e.message, 'error');
        showTableMessage('Error cargando datos — revisa la consola', '#b91c1c');
        console.error(e);
    } finally {
        if (reqId === _gridReqId) {
            showLoader(false);
            setFiltrosEnabled(true);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initFiltros(cargarVista);
    attachInfoIcon('#info-icon', () => _gridMeta);
    cargarVista();
});
