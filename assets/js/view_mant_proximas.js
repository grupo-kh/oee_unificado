/* Vista Mantenimiento · Próximas Revisiones */

let gaugeMant = null;
let chartTopMaqProx = null;
let _mantDias = 30;
let _mantCodMaquina = '';
let _mantPeriodicidad = '';
let _mantSoloVencidas = false;
let _operariosKnown = [];
// Map de filas consolidadas: idx → array de sub_tareas. Para marcar bulk.
let _consolRowsByIdx = {};
let _markPayload = null;
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

function renderGauge(value) {
    const v = Math.max(0, Math.min(100, parseFloat(value) || 0));
    const options = {
        chart: { type: 'radialBar', height: 320, background: 'transparent' },
        series: [v],
        plotOptions: {
            radialBar: {
                startAngle: -135, endAngle: 135,
                hollow: { size: '62%' },
                track: { background: '#e8eef5', strokeWidth: '100%' },
                dataLabels: {
                    name: { show: true, offsetY: -8, color: '#5b8cc7', fontSize: '13px', fontWeight: 600 },
                    value: {
                        show: true, offsetY: 8,
                        color: '#1a2d4a', fontSize: '40px', fontWeight: 700,
                        formatter: () => v.toFixed(1) + '%'
                    }
                }
            }
        },
        fill: {
            type: 'gradient',
            gradient: {
                shade: 'light', type: 'horizontal',
                shadeIntensity: 0.4,
                gradientToColors: [semColor(Math.min(100, v + 10))],
                opacityFrom: 1, opacityTo: 1, stops: [0, 100]
            }
        },
        colors: [semColor(v)],
        labels: ['En plazo'],
        stroke: { lineCap: 'round' }
    };
    if (gaugeMant) gaugeMant.destroy();
    gaugeMant = new ApexCharts($('#gauge-mant'), options);
    gaugeMant.render();
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

function renderTopMaquinas(arr) {
    const empty = $('#chart-top-maquinas-prox-empty');
    const cont  = $('#chart-top-maquinas-prox');
    if (!arr || !arr.length) {
        if (chartTopMaqProx) { chartTopMaqProx.destroy(); chartTopMaqProx = null; }
        if (cont) cont.innerHTML = '';
        if (empty) empty.style.display = '';
        return;
    }
    if (empty) empty.style.display = 'none';
    const data = arr.map(m => ({
        x: m.desc_maquina, cod: m.cod_maquina_mant,
        vencidas: m.vencidas, urgentes: m.urgentes,
        en_plazo: m.total - m.vencidas - m.urgentes,
        total: m.total
    }));
    const height = Math.max(220, Math.min(620, 32 * data.length + 80));
    const options = {
        chart: {
            type: 'bar', stacked: true, height,
            background: 'transparent', toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _ctx, cfg) => {
                    const cod = data[cfg.dataPointIndex] && data[cfg.dataPointIndex].cod;
                    if (cod) onTopMaquinaClick(cod);
                }
            }
        },
        series: [
            { name: 'Vencidas', data: data.map(d => d.vencidas) },
            { name: 'Pendientes', data: data.map(d => d.urgentes) },
            { name: 'En plazo', data: data.map(d => d.en_plazo) }
        ],
        xaxis: { categories: data.map(d => d.x), labels: { style: { colors: '#2d4d7a', fontSize: '11px' } } },
        yaxis: { labels: { style: { colors: '#1a2d4a', fontSize: '12px', fontWeight: 600 }, maxWidth: 160 } },
        plotOptions: { bar: { horizontal: true, barHeight: '60%', borderRadius: 0 } },
        states: { active: { filter: { type: 'none', value: 0 } } },
        colors: ['#c8102e', '#f59e0b', '#10b981'],
        dataLabels: {
            enabled: true,
            style: { colors: ['#ffffff'], fontFamily: 'Arial', fontSize: '11px', fontWeight: 700 },
            formatter: v => v > 0 ? v : ''
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3 },
        legend: { position: 'bottom', fontFamily: 'Arial', fontSize: '12px' },
        tooltip: {
            shared: false,
            intersect: true,
            custom: ({dataPointIndex}) => {
                const r = data[dataPointIndex];
                return `
                    <div style="padding:10px 12px;background:#1a2d4a;color:#fff;font-family:Arial;font-size:12px;min-width:220px">
                        <div style="font-weight:700;margin-bottom:6px;font-size:13px">${escHtml(r.x)}</div>
                        <div style="color:#a3b8d1;font-size:11px;margin-bottom:6px">${escHtml(r.cod)}</div>
                        <div style="display:flex;justify-content:space-between;gap:12px;color:#fca5a5"><span>Vencidas</span><span>${r.vencidas}</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px;color:#fbbf24"><span>Pendientes</span><span>${r.urgentes}</span></div>
                        <div style="display:flex;justify-content:space-between;gap:12px;color:#86efac"><span>En plazo</span><span>${r.en_plazo}</span></div>
                        <div style="border-top:1px solid #3a5576;margin-top:6px;padding-top:6px;display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:13px">
                            <span>Total</span><span>${r.total}</span>
                        </div>
                        <div style="color:#a3b8d1;font-size:10px;margin-top:6px;text-align:center">Clic para filtrar</div>
                    </div>
                `;
            }
        }
    };
    if (chartTopMaqProx) chartTopMaqProx.destroy();
    chartTopMaqProx = new ApexCharts(cont, options);
    chartTopMaqProx.render();
}

function onTopMaquinaClick(cod) {
    _mantCodMaquina = String(cod);
    const sel = $('#machine-selector');
    if (sel) sel.value = _mantCodMaquina;
    updateUrlParams({ cod_maquina_mant: _mantCodMaquina });
    cargarVista();
}

function renderTabla(rows) {
    const tb = $('#mant-tbody');
    if (!tb) return;
    if (!rows.length) {
        tb.innerHTML = '<tr><td colspan="8" class="mant-empty">Sin tareas para los filtros seleccionados</td></tr>';
        return;
    }
    const html = rows.map((r, idx) => {
        const cls = 'mant-row mant-row-' + r.estado + (r.consolidada ? ' mant-row-consolidada' : '');
        const diasCls = 'mant-dias mant-dias-' + r.estado;
        const dataAttrs = [
            `data-row-idx="${idx}"`,
            `data-orden="${escHtml(r.orden)}"`,
            `data-tarea="${escHtml(r.tarea)}"`,
            `data-fecha-proxima="${escHtml(r.proxima_revision)}"`,
            `data-cod-maq="${escHtml(r.cod_maquina_mant)}"`,
            `data-desc-maq="${escHtml(r.desc_maquina)}"`,
            `data-desc-grupo="${escHtml(r.desc_grupo)}"`,
            `data-periodicidad="${escHtml(r.periodicidad)}"`,
            `data-desc-tarea="${escHtml(r.desc_tarea)}"`,
            `data-consolidada="${r.consolidada ? '1' : '0'}"`
        ].join(' ');
        // Para filas consolidadas mostramos las sub-tareas como lista expandida
        const subList = r.consolidada && Array.isArray(r.sub_tareas) && r.sub_tareas.length
            ? `<details class="mant-subtareas"><summary>Ver ${r.sub_tareas.length} tareas que incluye</summary><ul>` +
              r.sub_tareas.map(s => `<li><strong>${escHtml(s.tarea)}</strong>: ${escHtml(s.desc_tarea || '')}</li>`).join('') +
              `</ul></details>`
            : '';
        const tareaCol = r.consolidada
            ? `<span class="mant-consol-badge" title="Revisión consolidada · todas las tareas de esta máquina con la misma periodicidad se realizan a la vez">⛓ Consol. · ${r.sub_tareas.length} tareas</span>`
            : `${escHtml(r.desc_grupo)}<br><span class="mant-cod">tarea ${escHtml(r.tarea)}</span>`;
        const btnLabel = r.consolidada ? `✓ Marcar las ${r.sub_tareas.length} hechas` : '✓ Marcar hecha';
        return `
            <tr class="${cls}">
                <td class="mant-fecha">${fmtFecha(r.proxima_revision)}</td>
                <td class="${diasCls}">${escHtml(fmtDias(r.dias_restantes))}</td>
                <td><strong>${escHtml(r.desc_maquina)}</strong> <span class="mant-cod">(${escHtml(r.cod_maquina_mant)})</span></td>
                <td><span class="mant-pill mant-pill-${(r.periodicidad||'').toLowerCase()}">${escHtml(r.periodicidad)}</span></td>
                <td>${tareaCol}</td>
                <td class="mant-desc">${escHtml(r.desc_tarea)}${subList}</td>
                <td class="mant-fecha">${fmtFecha(r.ultima_revision)}</td>
                <td><button type="button" class="mant-action-btn" ${dataAttrs}>${btnLabel}</button></td>
            </tr>
        `;
    }).join('');
    tb.innerHTML = html;

    // Guardamos las sub-tareas por idx para usar al marcar consolidadas
    _consolRowsByIdx = {};
    rows.forEach((r, idx) => {
        if (r.consolidada) _consolRowsByIdx[idx] = r.sub_tareas || [];
    });

    // Wire up botones
    tb.querySelectorAll('.mant-action-btn').forEach(btn => {
        btn.addEventListener('click', () => abrirModalMarcar(btn.dataset));
    });
}

function abrirModalMarcar(d) {
    // Resuelve sub_tareas si es fila consolidada (data-consolidada="1")
    const esConsol = d.consolidada === '1';
    const idx = d.rowIdx !== undefined ? d.rowIdx : null;
    const subTareas = (esConsol && idx !== null && _consolRowsByIdx[idx]) ? _consolRowsByIdx[idx] : null;
    console.log('[prox] abrirModalMarcar', { esConsol, idx, subTareas: subTareas ? subTareas.length : 0 });
    _markPayload = {
        orden: d.orden, tarea: d.tarea,
        fecha_proxima_original: d.fechaProxima,
        cod_maquina_mant: d.codMaq,
        desc_maquina: d.descMaq,
        desc_grupo: d.descGrupo,
        periodicidad: d.periodicidad,
        desc_tarea: d.descTarea,
        consolidada: esConsol,
        sub_tareas: subTareas, // [{orden, tarea, periodicidad, desc_tarea, proxima_revision}, …]
    };
    const consolNote = esConsol && subTareas
        ? `<br><span class="mant-cod" style="color:#8c181a;font-weight:bold">⛓ Acción consolidada: elige qué sub-tareas se han hecho</span>`
        : '';
    const summary = `<strong>${d.descMaq || d.codMaq}</strong> · ${d.periodicidad}<br>` +
        `<span class="mant-cod">${d.descGrupo}</span><br>` +
        `${escHtml(d.descTarea)}${consolNote}<br>` +
        `<span class="mant-cod">Próxima programada: ${fmtFecha(d.fechaProxima)}</span>`;
    $('#mark-modal-summary').innerHTML = summary;

    // Render de checkboxes para subtareas (solo si es consolidada).
    const subWrap = $('#mark-subtareas-wrap');
    const subList = $('#mark-subtareas-list');
    if (esConsol && subTareas && subList && subWrap) {
        subList.innerHTML = subTareas.map((s, i) => `
            <label class="mant-subtarea-check">
                <input type="checkbox" data-sub-idx="${i}" checked>
                <span><strong>${escHtml(s.tarea || '')}</strong>
                    ${s.periodicidad ? `<span class="mant-pill mant-pill-${(s.periodicidad||'').toLowerCase()}" style="margin-left:6px">${escHtml(s.periodicidad)}</span>` : ''}
                    ${s.desc_tarea ? `<br><span class="mant-cod" style="font-size:11px">${escHtml(s.desc_tarea)}</span>` : ''}
                </span>
            </label>
        `).join('');
        subWrap.style.display = '';
    } else if (subWrap) {
        subWrap.style.display = 'none';
        if (subList) subList.innerHTML = '';
    }
    $('#mark-fecha').value = new Date().toISOString().substring(0, 10);
    // Hora actual prerrellenada (operario puede ajustarla si empezó antes)
    const horaInput = $('#mark-hora-inicio');
    if (horaInput) {
        const now = new Date();
        horaInput.value = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
    }
    $('#mark-observaciones').value = '';
    // Reset selector tipo a "completada" y motivo
    const tipoRadios = document.querySelectorAll('input[name="mark-tipo"]');
    tipoRadios.forEach(r => { r.checked = (r.value === 'completada'); });
    const motivoSel = $('#mark-motivo');
    if (motivoSel) motivoSel.value = '';
    _aplicarTipoMarcado('completada');

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

// Ajusta visibilidad/etiqueta del modal según el tipo seleccionado.
// - 'completada': muestra hora de inicio, oculta motivo, label del botón verde.
// - 'no_realizada': oculta hora de inicio (no aplica), muestra motivo,
//   etiqueta del botón cambia a "Marcar como no realizada" en rojo.
function _aplicarTipoMarcado(tipo) {
    const isNo = tipo === 'no_realizada';
    const motivoWrap = $('#mark-motivo-wrap');
    const horaWrap   = $('#mark-hora-wrap');
    const btn        = $('#mark-modal-ok');
    if (motivoWrap) motivoWrap.style.display = isNo ? '' : 'none';
    if (horaWrap)   horaWrap.style.display   = isNo ? 'none' : '';
    if (btn) {
        btn.textContent = isNo ? '✕ Marcar como no realizada' : '✓ Marcar como hecha';
        btn.style.background = isNo ? '#8c181a' : '#10b981';
    }
}

async function confirmarMarcar() {
    if (!_markPayload) return;
    const sel = $('#mark-operario');
    let op = sel.value || '';
    if (op === '__otro__') op = ($('#mark-operario-otro').value || '').trim();
    // Validación: si hay operario, debe ser solo dígitos (código numérico).
    // No queremos nombres de operario en BD para preservar privacidad/anonimato.
    if (op !== '' && !/^\d+$/.test(op)) {
        showToast('El operario debe ser un código numérico (ej. 1004). Sin letras ni nombre.', 'error');
        return;
    }
    const obs = ($('#mark-observaciones').value || '').trim();
    const fechaInt = $('#mark-fecha').value || new Date().toISOString().substring(0, 10);
    const tipoSel = document.querySelector('input[name="mark-tipo"]:checked');
    const tipo = tipoSel ? tipoSel.value : 'completada';
    const motivo = $('#mark-motivo')?.value || '';
    const horaInicio = $('#mark-hora-inicio')?.value || '';

    if (tipo === 'no_realizada' && !motivo) {
        showToast('Selecciona el motivo de no realización', 'error');
        return;
    }

    // Construye los payloads. Si es consolidada, uno por cada sub-tarea
    // (la fecha_proxima_original es la propia de cada sub-tarea); si no,
    // un único payload con los datos del row.
    const payloads = [];
    const baseExtra = (() => {
        if (tipo === 'completada') {
            const x = { fecha_intervencion: fechaInt };
            if (horaInicio) x.hora_inicio = horaInicio;
            return x;
        }
        return { motivo_no_realizada: motivo };
    })();
    if (_markPayload.consolidada && Array.isArray(_markPayload.sub_tareas) && _markPayload.sub_tareas.length) {
        // Filtrar las subtareas según los checkboxes activos. Las desmarcadas
        // se quedan pendientes en el plan y reaparecen en la próxima visita.
        const checkboxes = document.querySelectorAll('#mark-subtareas-list input[type="checkbox"]');
        const selectedIdx = new Set();
        checkboxes.forEach(cb => {
            if (cb.checked) selectedIdx.add(parseInt(cb.dataset.subIdx, 10));
        });
        if (selectedIdx.size === 0) {
            showToast('Selecciona al menos una sub-tarea (o usa "Cancelar")', 'error');
            return;
        }
        // Si NO se han marcado todas las sub-tareas, la visita es parcial:
        // las marcas creadas llevarán visita_incompleta=true para que
        // aparezcan etiquetadas como "INCOMPLETA" en el histórico.
        const visitaIncompleta = (selectedIdx.size < _markPayload.sub_tareas.length);
        _markPayload.sub_tareas.forEach((s, i) => {
            if (!selectedIdx.has(i)) return;  // sub-tarea desmarcada → queda pendiente
            payloads.push({
                orden: s.orden,
                tarea: s.tarea,
                fecha_proxima_original: s.proxima_revision,
                tipo,
                operario: op,
                observaciones: obs,
                visita_incompleta: visitaIncompleta,
                ...baseExtra,
            });
        });
    } else {
        payloads.push({
            orden: _markPayload.orden,
            tarea: _markPayload.tarea,
            fecha_proxima_original: _markPayload.fecha_proxima_original,
            tipo,
            operario: op,
            observaciones: obs,
            ...baseExtra,
        });
    }

    showLoader(true);
    try {
        const headers = { 'Content-Type': 'application/json' };
        if (window.__CSRF_TOKEN) headers['X-CSRF-Token'] = window.__CSRF_TOKEN;
        // Envíos en paralelo: si falla alguno mostramos cuántos pasaron.
        const results = await Promise.allSettled(payloads.map(p =>
            fetch('../api/mant_marcar_hecha.php', { method: 'POST', headers, body: JSON.stringify(p) })
                .then(async r => {
                    const j = await r.json();
                    if (!r.ok || !j.ok) throw new Error(j.error || `HTTP ${r.status}`);
                    return j;
                })
        ));
        const ok = results.filter(r => r.status === 'fulfilled').length;
        const ko = results.length - ok;
        if (ko > 0) {
            const errMsg = results.find(r => r.status === 'rejected')?.reason?.message || 'error desconocido';
            throw new Error(`${ok}/${results.length} marcadas. Errores: ${errMsg}`);
        }

        try { localStorage.setItem(LS_LAST_OPERARIO, op); } catch(e) {}
        const palabra = tipo === 'no_realizada' ? 'no realizadas' : 'marcadas como hechas';
        showToast(payloads.length > 1 ? `${ok} tareas ${palabra}` : (tipo === 'no_realizada' ? 'Marcada como no realizada' : 'Revisión marcada como hecha'), 'success');
        cerrarModalMarcar();
        cargarVista();
    } catch (e) {
        showToast('Error: ' + e.message, 'error');
    } finally {
        showLoader(false);
    }
}

async function cargarVista() {
    showLoader(true);
    try {
        const params = { dias: _mantDias };
        if (_mantCodMaquina)   params.cod_maquina_mant = _mantCodMaquina;
        if (_mantPeriodicidad) params.periodicidad     = _mantPeriodicidad;
        if (_mantSoloVencidas) params.solo_vencidas    = 1;

        const d = await apiFetch('mant_proximas.php', params);

        populateMaquinas(d.maquinas || [], _mantCodMaquina);
        populatePeriodicidades(d.periodicidades || [], _mantPeriodicidad);
        _operariosKnown = d.operarios || [];

        // Validar filtros actuales contra los disponibles
        const okMaq = !_mantCodMaquina   || (d.maquinas || []).some(m => m.cod_maquina_mant === _mantCodMaquina);
        const okPer = !_mantPeriodicidad || (d.periodicidades || []).includes(_mantPeriodicidad);
        if (!okMaq) { _mantCodMaquina = '';   $('#machine-selector').value = ''; updateUrlParams({ cod_maquina_mant: '' }); }
        if (!okPer) { _mantPeriodicidad = ''; $('#periodicidad-selector').value = ''; updateUrlParams({ periodicidad: '' }); }

        const btn = $('#filter-clear');
        if (btn) btn.style.display = (_mantCodMaquina || _mantPeriodicidad || _mantSoloVencidas || _mantDias != 30) ? '' : 'none';

        const scopeBits = [];
        if (_mantCodMaquina) {
            const m = (d.maquinas || []).find(x => x.cod_maquina_mant === _mantCodMaquina);
            scopeBits.push('máq: ' + (m ? m.desc_maquina : _mantCodMaquina));
        }
        if (_mantPeriodicidad) scopeBits.push('per: ' + _mantPeriodicidad);
        if (_mantSoloVencidas) scopeBits.push('solo vencidas');
        $('#header-scope').textContent = scopeBits.length ? '· ' + scopeBits.join(' · ') : '';

        $('#info-line').textContent = 'Hoy ' + fmtFecha(d.hoy) + ' · ventana ' + d.dias + ' días · ' + d.total + ' tareas';
        $('#stat-vencidas').textContent = d.vencidas;
        $('#stat-urgentes').textContent = d.urgentes;
        $('#stat-en-plazo').textContent = d.en_plazo;
        $('#stat-total').textContent    = d.total;
        $('#footer-actualizado').textContent = 'Fichero actualizado: ' + (d.fichero_actualizado || '—');

        renderGauge(d.pct_en_plazo);
        renderTopMaquinas(d.top_maquinas || []);
        renderTabla(d.rows || []);

    } catch (e) {
        showToast('Error: ' + e.message, 'error');
        const tb = $('#mant-tbody');
        if (tb) tb.innerHTML = '<tr><td colspan="7" class="mant-empty">Error cargando datos</td></tr>';
    } finally {
        showLoader(false);
    }
}

function onDiasChange()         { _mantDias = parseInt($('#dias-selector').value || '30', 10); updateUrlParams({ dias: _mantDias }); cargarVista(); }
function onMachineChange()      { _mantCodMaquina = $('#machine-selector').value || ''; updateUrlParams({ cod_maquina_mant: _mantCodMaquina }); cargarVista(); }
function onPeriodicidadChange() { _mantPeriodicidad = $('#periodicidad-selector').value || ''; updateUrlParams({ periodicidad: _mantPeriodicidad }); cargarVista(); }
function onSoloVencidasChange() { _mantSoloVencidas = $('#solo-vencidas').checked; updateUrlParams({ solo_vencidas: _mantSoloVencidas ? '1' : '' }); cargarVista(); }
function onClearFilters() {
    _mantDias = 30; _mantCodMaquina = ''; _mantPeriodicidad = ''; _mantSoloVencidas = false;
    $('#dias-selector').value = '30';
    $('#machine-selector').value = '';
    $('#periodicidad-selector').value = '';
    $('#solo-vencidas').checked = false;
    updateUrlParams({ dias: '', cod_maquina_mant: '', periodicidad: '', solo_vencidas: '' });
    cargarVista();
}

// Descarga el calendario para los operarios respetando los filtros actuales
// (ventana de días, máquina, periodicidad, solo vencidas). El servidor genera
// el archivo en el formato pedido (xlsx | pdf) y el navegador lo descarga
// directamente. Abrimos en pestaña nueva para que un eventual error PHP del
// endpoint sea visible sin perder el estado de esta vista.
function descargarCalendario(fmt) {
    console.log('[prox] descargarCalendario invocado', fmt);
    const params = new URLSearchParams();
    params.set('fmt', fmt === 'pdf' ? 'pdf' : 'xlsx');
    params.set('dias', String(_mantDias || 30));
    if (_mantCodMaquina)   params.set('cod_maquina_mant', _mantCodMaquina);
    if (_mantPeriodicidad) params.set('periodicidad', _mantPeriodicidad);
    if (_mantSoloVencidas) params.set('solo_vencidas', '1');
    const url = '../api/mant_proximas_export.php?' + params.toString();
    console.log('[prox] descargando ->', url);
    // Usamos un <a download> efímero — funciona tanto si la respuesta es un
    // archivo binario con Content-Disposition como si el endpoint devuelve un
    // JSON de error (en cuyo caso el browser muestra el JSON en pestaña nueva).
    const a = document.createElement('a');
    a.href   = url;
    a.target = '_blank';
    a.rel    = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
}

document.addEventListener('DOMContentLoaded', () => {
    _mantDias          = parseInt(getQueryParam('dias') || '30', 10);
    _mantCodMaquina    = getQueryParam('cod_maquina_mant') || '';
    _mantPeriodicidad  = getQueryParam('periodicidad') || '';
    _mantSoloVencidas  = getQueryParam('solo_vencidas') === '1';

    $('#dias-selector').value = String(_mantDias);
    $('#solo-vencidas').checked = _mantSoloVencidas;

    $('#dias-selector').addEventListener('change', onDiasChange);
    $('#machine-selector').addEventListener('change', onMachineChange);
    $('#periodicidad-selector').addEventListener('change', onPeriodicidadChange);
    $('#solo-vencidas').addEventListener('change', onSoloVencidasChange);
    const c = $('#filter-clear'); if (c) c.addEventListener('click', onClearFilters);

    // Descarga del calendario para operarios (respeta los filtros activos)
    const xlsxBtn = $('#prox-export-xlsx');
    if (xlsxBtn) xlsxBtn.addEventListener('click', () => descargarCalendario('xlsx'));
    const pdfBtn  = $('#prox-export-pdf');
    if (pdfBtn)  pdfBtn.addEventListener('click', () => descargarCalendario('pdf'));

    // Modal marcar
    $('#mark-modal-close').addEventListener('click', cerrarModalMarcar);
    $('#mark-modal-cancel').addEventListener('click', cerrarModalMarcar);
    $('#mark-modal-backdrop').addEventListener('click', cerrarModalMarcar);
    $('#mark-modal-ok').addEventListener('click', confirmarMarcar);
    // Botones "Marcar todas / Desmarcar todas" para las sub-tareas consolidadas
    const subAll = $('#mark-subtareas-all');
    const subNone = $('#mark-subtareas-none');
    if (subAll)  subAll.addEventListener('click',  () => {
        document.querySelectorAll('#mark-subtareas-list input[type="checkbox"]').forEach(cb => cb.checked = true);
    });
    if (subNone) subNone.addEventListener('click', () => {
        document.querySelectorAll('#mark-subtareas-list input[type="checkbox"]').forEach(cb => cb.checked = false);
    });
    // Radios de tipo (realizada / no realizada): aplica visibilidad de
    // motivo + hora_inicio y cambia label/color del botón principal.
    document.querySelectorAll('input[name="mark-tipo"]').forEach(r => {
        r.addEventListener('change', () => _aplicarTipoMarcado(r.value));
    });
    $('#mark-operario').addEventListener('change', () => {
        const isOtro = $('#mark-operario').value === '__otro__';
        $('#mark-operario-otro-wrap').style.display = isOtro ? '' : 'none';
        if (isOtro) setTimeout(() => $('#mark-operario-otro').focus(), 50);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && $('#mark-modal').style.display !== 'none') cerrarModalMarcar();
    });

    initFiltros(cargarVista);
    cargarVista();
});
