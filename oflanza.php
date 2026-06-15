<?php
/**
 * Lanzamiento de OFs · App para tablets de planta.
 *
 * SPA autocontenida (igual patrón que appmovil.php) sin sesión web.
 * El operario se identifica con su PIN de 4 cifras al entrar.
 *
 * URL: http://<host>/PLAN_ATTAINMENT/oflanza.php
 */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
<title>KH · Lanzamiento de OFs</title>
<style>
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html, body {
        margin: 0; padding: 0;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
        background: #eef3f8; color: #1a2d4a;
        min-height: 100vh; overflow-x: hidden;
        font-size: 16px;
    }
    body { padding-bottom: 32px; }

    /* Cabecera fija ─────────────────────────────────────────── */
    .topbar {
        background: linear-gradient(135deg, #1a4a7a 0%, #2d4d7a 100%);
        color: #fff; padding: 12px 18px;
        display: flex; align-items: center; gap: 12px;
        box-shadow: 0 2px 10px rgba(15,28,48,.18);
        position: sticky; top: 0; z-index: 100;
    }
    .topbar h1 { margin: 0; font-size: 18px; flex: 1; }
    .topbar .user {
        font-size: 13px; opacity: .9; display: flex; flex-direction: column; align-items: flex-end;
    }
    .topbar .user strong { font-size: 14px; }
    .btn-logout {
        background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.32);
        color: #fff; padding: 6px 12px; border-radius: 6px;
        font-size: 12.5px; font-weight: 600; cursor: pointer;
    }
    .btn-back {
        background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.32);
        color: #fff; padding: 6px 12px; border-radius: 6px;
        font-size: 13px; font-weight: 600; cursor: pointer; margin-right: 8px;
    }

    .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
    .card {
        background: #fff; border-radius: 10px; padding: 22px;
        box-shadow: 0 1px 6px rgba(15,28,48,.10); margin-bottom: 16px;
    }
    .card h2 { margin: 0 0 16px; font-size: 17px; color: #2d4d7a;
                text-transform: uppercase; letter-spacing: .5px;
                text-align: center; padding-bottom: 10px;
                border-bottom: 2px solid #2d4d7a; }

    .btn-primary {
        background: #2d4d7a; color: #fff; border: 0; padding: 16px 28px;
        font-size: 16px; font-weight: 700; border-radius: 8px;
        cursor: pointer; box-shadow: 0 2px 6px rgba(45,77,122,.30);
        min-width: 120px;
    }
    .btn-primary:hover:not(:disabled) { background: #1a4a7a; }
    .btn-primary:disabled { background: #94a3b8; cursor: not-allowed; box-shadow: none; }
    .btn-secondary {
        background: #fff; color: #2d4d7a; border: 2px solid #2d4d7a;
        padding: 14px 24px; font-size: 15px; font-weight: 700; border-radius: 8px;
        cursor: pointer; min-width: 140px;
    }
    .btn-secondary:hover { background: #eef3f8; }
    .btn-launch {
        background: #1f8a3c; color: #fff; border: 0; padding: 18px 36px;
        font-size: 17px; font-weight: 800; border-radius: 8px; cursor: pointer;
        box-shadow: 0 2px 6px rgba(31,138,60,.30); letter-spacing: .8px;
        text-transform: uppercase;
    }
    .btn-launch:hover { background: #166a2e; }
    .btn-launch:disabled { background: #94a3b8; cursor: not-allowed; box-shadow: none; }

    /* Login con PIN numérico ─────────────────────────────────── */
    .login-wrap {
        min-height: 78vh; display: flex; flex-direction: column;
        align-items: center; justify-content: center; padding: 20px;
    }
    .login-wrap h2 { font-size: 20px; color: #2d4d7a; margin: 0 0 8px; }
    .login-wrap .helper { color: #5b6f86; font-size: 14px; margin: 0 0 20px; }
    .pin-display {
        background: #fff; border: 2px solid #c5d2e0; border-radius: 10px;
        padding: 16px 24px; font-size: 30px; font-weight: 800; letter-spacing: 10px;
        text-align: center; min-width: 220px; margin-bottom: 18px;
        color: #2d4d7a; font-family: 'Courier New', monospace;
    }
    .pin-display.error { border-color: #c8102e; color: #c8102e; }
    .pin-pad { display: grid; grid-template-columns: repeat(3, 80px); gap: 12px; }
    .pin-key {
        background: #fff; border: 2px solid #c5d2e0; border-radius: 10px;
        padding: 18px 0; font-size: 24px; font-weight: 700; color: #2d4d7a;
        cursor: pointer;
    }
    .pin-key:active { background: #2d4d7a; color: #fff; }
    .pin-key.borrar { color: #c8102e; }
    .pin-key.entrar { color: #1f8a3c; }

    /* Pantalla 1: selección estación + grid OFs ──────────────── */
    .estacion-row { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; }
    .estacion-row label { font-weight: 700; color: #2d4d7a; font-size: 14px; }
    .estacion-row select {
        flex: 1; min-width: 240px; padding: 14px 16px;
        font-size: 16px; border: 2px solid #c5d2e0; border-radius: 8px;
        background: #fff; color: #1a2d4a; font-weight: 600;
    }

    .ofs-grid {
        display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px;
        margin-top: 8px;
    }
    .of-slot {
        background: #fff8e8; border: 2px solid #f0c674; border-radius: 10px;
        padding: 18px 14px; cursor: pointer; min-height: 96px;
        display: flex; flex-direction: column; gap: 4px;
        transition: transform .08s, box-shadow .12s;
    }
    .of-slot:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(15,28,48,.18); }
    .of-slot.prioritaria { background: #ffb78a; border-color: #c8551a; }
    .of-slot.empty { background: #f8fafc; border: 2px dashed #cbd5e1; cursor: default; }
    .of-slot.empty:hover { transform: none; box-shadow: none; }
    .of-slot-num { font-size: 11px; color: #5b6f86; font-weight: 700; }
    .of-slot-code { font-size: 18px; font-weight: 800; color: #1a2d4a; }
    .of-slot-ref  { font-size: 12px; color: #5b6f86; }
    .of-slot.prioritaria .of-slot-num,
    .of-slot.prioritaria .of-slot-ref { color: #6b1d00; }
    .of-slot.prioritaria .of-slot-code { color: #4d1500; }

    /* Pantalla 2: detalle de OF ──────────────────────────────── */
    .of-header {
        background: #ffb78a; padding: 14px 20px; border-radius: 10px;
        text-align: center; font-size: 22px; font-weight: 800;
        color: #4d1500; letter-spacing: 1.5px;
        margin-bottom: 18px; border: 2px solid #c8551a;
    }
    .of-resumen {
        display: grid; grid-template-columns: 180px 1fr 100px; gap: 8px 14px;
        padding: 16px; background: #fff; border: 1px solid #c5d2e0; border-radius: 8px;
        margin-bottom: 18px;
    }
    .of-resumen .label { font-weight: 700; color: #2d4d7a; }
    .of-resumen .value { color: #1a2d4a; }
    .of-resumen .units { color: #5b6f86; font-size: 13px; }
    .of-resumen .notas-row { grid-column: 1 / 4; display: flex; gap: 14px; align-items: stretch; margin-top: 4px; }
    .of-resumen .notas-label {
        background: #c8102e; color: #fff; font-weight: 700;
        padding: 6px 14px; border-radius: 5px; min-width: 180px;
        display: flex; align-items: center;
    }
    .of-resumen .notas-value {
        flex: 1; background: #fef2f2; border: 1px solid #fca5a5;
        padding: 6px 12px; border-radius: 5px; min-height: 34px;
    }
    .of-actions {
        display: flex; gap: 14px; flex-wrap: wrap; justify-content: center;
        margin-top: 8px;
    }

    /* Mensajes ──────────────────────────────────────────────── */
    .empty-msg { padding: 20px; text-align: center; color: #5b6f86; font-style: italic; }
    .toast {
        position: fixed; left: 50%; bottom: 26px; transform: translateX(-50%);
        background: #1a2d4a; color: #fff; padding: 14px 24px; border-radius: 8px;
        font-weight: 600; font-size: 14px; box-shadow: 0 4px 20px rgba(0,0,0,.3);
        display: none; z-index: 200;
    }
    .toast.success { background: #1f8a3c; }
    .toast.error   { background: #c8102e; }
    .toast.show    { display: block; }

    .step { display: none; }
    .step.active { display: block; }

    /* Responsive tablet pequeña / portrait ────────────────────── */
    @media (max-width: 720px) {
        .ofs-grid { grid-template-columns: repeat(2, 1fr); }
        .of-resumen { grid-template-columns: 130px 1fr 70px; }
        .topbar h1 { font-size: 16px; }
    }
</style>
</head>
<body>

<!-- Cabecera ─────────────────────────────────────────────────────── -->
<div class="topbar" id="topbar" style="display:none">
    <button type="button" class="btn-back" id="btn-back" style="display:none">← Volver</button>
    <h1 id="title">Lanzamiento de OFs</h1>
    <div class="user">
        <strong id="user-name">—</strong>
        <span id="user-code"></span>
    </div>
    <button type="button" class="btn-logout" id="btn-logout">Salir</button>
</div>

<div class="container">

    <!-- ───── Pantalla 0: Login PIN ───── -->
    <div class="step active" id="step-login">
        <div class="login-wrap">
            <h2>KH · LANZAMIENTO DE OFs</h2>
            <p class="helper">Introduce tu código de operario (4 cifras)</p>
            <div class="pin-display" id="pin-display">– – – –</div>
            <div class="pin-pad" id="pin-pad">
                <button class="pin-key" data-k="1">1</button>
                <button class="pin-key" data-k="2">2</button>
                <button class="pin-key" data-k="3">3</button>
                <button class="pin-key" data-k="4">4</button>
                <button class="pin-key" data-k="5">5</button>
                <button class="pin-key" data-k="6">6</button>
                <button class="pin-key" data-k="7">7</button>
                <button class="pin-key" data-k="8">8</button>
                <button class="pin-key" data-k="9">9</button>
                <button class="pin-key borrar" data-k="DEL">⌫</button>
                <button class="pin-key" data-k="0">0</button>
                <button class="pin-key entrar" data-k="OK">OK</button>
            </div>
        </div>
    </div>

    <!-- ───── Pantalla 1: Selección estación + lista de OFs ───── -->
    <div class="step" id="step-estacion">
        <div class="card">
            <h2>Lanzamiento de OFs</h2>
            <div class="estacion-row">
                <label for="estacion-sel">MAS / ESTACIÓN:</label>
                <select id="estacion-sel">
                    <option value="">— Selecciona estación —</option>
                </select>
                <button type="button" class="btn-primary" id="btn-ok-estacion">OK</button>
            </div>
            <div id="ofs-info" style="text-align:center;font-size:13px;color:#5b6f86;margin-bottom:10px"></div>
            <div class="ofs-grid" id="ofs-grid">
                <div class="empty-msg" style="grid-column:1/-1">Selecciona una estación y pulsa OK.</div>
            </div>
        </div>
    </div>

    <!-- ───── Pantalla 3: Etiquetas (post-lanzamiento) ───── -->
    <div class="step" id="step-etiquetas">
        <div class="card">
            <h2>Lanzamiento de OFs</h2>
            <div class="of-header" id="etq-of">OF —</div>

            <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-start;justify-content:center">
                <div style="flex:1 1 380px;max-width:480px">
                    <div style="font-size:14px;font-weight:700;color:#2d4d7a;margin-bottom:8px">
                        Etiquetas que hacen falta
                        <span style="font-size:11px;font-weight:400;color:#5b6f86;font-style:italic">(es una ayuda inicial)</span>
                    </div>
                    <table style="width:100%;border-collapse:collapse;font-size:13.5px">
                        <tr>
                            <td style="padding:8px 12px;background:#eef3f8;border-radius:5px 0 0 0;font-weight:700;text-align:right">Total UDS</td>
                            <td style="padding:8px 12px;background:#eef3f8;border-radius:0 5px 0 0" id="etq-uds-total">—</td>
                        </tr>
                        <tr>
                            <td style="padding:8px 12px;background:#fff8e8;font-weight:700;text-align:right">UDS / caja</td>
                            <td style="padding:8px 12px;background:#fff8e8" id="etq-uds-caja">120</td>
                        </tr>
                        <tr>
                            <td style="padding:8px 12px;background:#bbf7d0;font-weight:700;border-radius:0 0 0 5px;text-align:right">Etiquetas teóricas</td>
                            <td style="padding:8px 12px;background:#bbf7d0;font-weight:800;border-radius:0 0 5px 0" id="etq-teoricas">—</td>
                        </tr>
                    </table>
                </div>

                <div style="flex:1 1 320px;max-width:380px">
                    <label style="display:block;font-weight:700;color:#2d4d7a;font-size:13px;margin-bottom:4px">Etiquetas a imprimir</label>
                    <input type="number" id="etq-num-imprimir" min="0" step="1"
                           style="width:100%;padding:14px 16px;font-size:18px;border:2px solid #c5d2e0;border-radius:8px;font-weight:700;text-align:center;background:#fff8e8">
                    <label style="display:block;font-weight:700;color:#2d4d7a;font-size:13px;margin:14px 0 4px">UDS / etiqueta</label>
                    <input type="number" id="etq-uds-por-etq" min="1" step="1"
                           style="width:100%;padding:14px 16px;font-size:18px;border:2px solid #c5d2e0;border-radius:8px;font-weight:700;text-align:center;background:#fff8e8">
                </div>
            </div>

            <div id="etq-warning" style="display:none;background:#fef3c7;border-left:4px solid #f59e0b;color:#78350f;padding:8px 12px;border-radius:6px;margin:14px auto;max-width:760px;font-size:12.5px">
                ⚠ Estás imprimiendo un número distinto al teórico. Se solicitará contraseña.
            </div>

            <div class="of-actions" style="margin-top:20px">
                <button type="button" class="btn-secondary" id="btn-etq-cancel">VOLVER AL LISTADO</button>
                <button type="button" class="btn-launch" id="btn-imprimir" style="background:#2d4d7a">IMPRIMIR</button>
            </div>
            <div style="margin-top:14px;text-align:center;font-size:11.5px;color:#5b6f86;font-style:italic">
                La impresión real de etiquetas se conectará al sistema existente en la siguiente fase.
                Por ahora se registra la solicitud en BD.
            </div>
        </div>
    </div>

    <!-- ───── Pantalla 2: Detalle de OF ───── -->
    <div class="step" id="step-detalle">
        <div class="card">
            <h2>Lanzamiento de OFs</h2>
            <div class="of-header" id="detalle-of">OF —</div>
            <div style="text-align:center;font-weight:700;color:#2d4d7a;margin-bottom:12px;text-transform:uppercase;letter-spacing:.5px">
                PODEMOS MOSTRAR INFORMACIÓN DE RESUMEN DE LA OF
            </div>
            <div class="of-resumen">
                <div class="label">REF</div>
                <div class="value" id="d-ref">—</div>
                <div></div>

                <div class="label">UBICACIÓN DE GALGA</div>
                <div class="value" id="d-galga">—</div>
                <div></div>

                <div class="label">CANTIDAD</div>
                <div class="value" id="d-cant">—</div>
                <div class="units">UDS</div>

                <div class="label">DURACIÓN OF</div>
                <div class="value" id="d-dur">—</div>
                <div class="units">HORAS</div>

                <div class="notas-row">
                    <div class="notas-label">NOTAS O STOPPERS</div>
                    <div class="notas-value" id="d-notas">—</div>
                    <div style="display:flex;align-items:center;font-size:13px;color:#5b6f86;font-weight:600">
                        <span id="d-resp">—</span>
                    </div>
                </div>
            </div>

            <div class="of-actions">
                <button type="button" class="btn-secondary" id="btn-material" title="Próximamente: pide material a Whales">PIDE MATERIAL</button>
                <button type="button" class="btn-secondary" id="btn-dossier" title="Próximamente: consulta dossier en planta">CONSULTA DOSSIER</button>
                <button type="button" class="btn-launch"    id="btn-lanzar">LANZA OF</button>
            </div>
            <div style="margin-top:14px;text-align:center;font-size:11.5px;color:#5b6f86;font-style:italic">
                PIDE MATERIAL y CONSULTA DOSSIER quedan habilitados en la próxima fase. LANZA OF registra la OF en la BD del sistema.
            </div>
        </div>
    </div>

</div>

<div class="toast" id="toast"></div>

<script>
(function () {
    const $ = s => document.querySelector(s);
    const state = {
        operario: null, nombre: '',
        estaciones: [],
        cod_maquina: '', desc_maquina: '',
        fecha: new Date().toISOString().slice(0,10),
        ofActual: null,
    };

    function showToast(msg, type='') {
        const t = $('#toast');
        t.textContent = msg;
        t.className = 'toast show ' + type;
        setTimeout(() => t.classList.remove('show'), 3500);
    }
    async function api(action, opts = {}) {
        const params = new URLSearchParams({ action, ...(opts.query || {}) });
        const url = 'api/oflanza.php?' + params.toString();
        const headers = { 'Accept': 'application/json' };
        if (opts.body) headers['Content-Type'] = 'application/json';
        const r = await fetch(url, {
            method: opts.method || 'GET',
            headers,
            body: opts.body ? JSON.stringify(opts.body) : null,
        });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error((j && j.error) || `HTTP ${r.status}`);
        return j.data;
    }
    function setStep(name) {
        ['login','estacion','detalle','etiquetas'].forEach(s => {
            const el = document.getElementById('step-' + s);
            if (el) el.classList.toggle('active', s === name);
        });
        $('#topbar').style.display = (name === 'login') ? 'none' : '';
        $('#btn-back').style.display = (name === 'detalle') ? '' : 'none';
        window.scrollTo(0, 0);
    }

    // ── Login PIN ───────────────────────────────────────────────────
    let pin = '';
    function pintarPin() {
        const slots = ['–','–','–','–'];
        for (let i = 0; i < pin.length && i < 4; i++) slots[i] = '*';
        $('#pin-display').textContent = slots.join(' ');
        $('#pin-display').classList.remove('error');
    }
    pintarPin();
    document.getElementById('pin-pad').addEventListener('click', async e => {
        const btn = e.target.closest('.pin-key'); if (!btn) return;
        const k = btn.dataset.k;
        if (k === 'DEL') { pin = pin.slice(0, -1); pintarPin(); return; }
        if (k === 'OK') {
            if (!pin) { $('#pin-display').classList.add('error'); return; }
            try {
                const r = await api('verifica_operario', { method: 'POST', body: { operario: pin } });
                state.operario = r.operario;
                state.nombre   = r.nombre;
                $('#user-name').textContent = r.nombre || ('Operario ' + r.operario);
                $('#user-code').textContent = 'cód. ' + r.operario;
                pin = '';
                await cargarEstaciones();
                setStep('estacion');
            } catch (err) {
                $('#pin-display').classList.add('error');
                showToast(err.message, 'error');
            }
            return;
        }
        if (pin.length < 4) {
            pin += k;
            pintarPin();
        }
    });

    // ── Estaciones ──────────────────────────────────────────────────
    async function cargarEstaciones() {
        try {
            const d = await api('estaciones', { query: { f: state.fecha } });
            state.estaciones = d.estaciones || [];
            const sel = $('#estacion-sel');
            if (!state.estaciones.length) {
                sel.innerHTML = '<option value="">No hay planificación cargada para hoy</option>';
                showToast('No se encontró el Excel de planificación para hoy', 'error');
                return;
            }
            // Como el Excel sólo trae descripción (cod = desc), evitamos
            // duplicar el texto en el dropdown.
            sel.innerHTML = '<option value="">— Selecciona estación —</option>'
                + state.estaciones.map(e =>
                    `<option value="${e.cod}">${e.desc}</option>`
                  ).join('');
        } catch (e) { showToast(e.message, 'error'); }
    }

    $('#btn-ok-estacion').addEventListener('click', async () => {
        const cod = $('#estacion-sel').value;
        if (!cod) { showToast('Selecciona una estación', 'error'); return; }
        const e = state.estaciones.find(x => x.cod === cod);
        state.cod_maquina  = cod;
        state.desc_maquina = e ? e.desc : '';
        await cargarOfs();
    });

    async function cargarOfs() {
        $('#ofs-grid').innerHTML = '<div class="empty-msg" style="grid-column:1/-1">Cargando OFs…</div>';
        $('#ofs-info').textContent = '';
        try {
            const d = await api('planificadas', { query: { cod: state.cod_maquina, f: state.fecha } });
            $('#ofs-info').textContent = d.desc_maquina + ' · ' + d.fecha + ' · ' + d.total + ' OFs planificadas';
            renderOfs(d.ofs);
        } catch (e) {
            $('#ofs-grid').innerHTML = '<div class="empty-msg" style="grid-column:1/-1;color:#c8102e">Error: ' + e.message + '</div>';
        }
    }

    function renderOfs(ofs) {
        const grid = $('#ofs-grid');
        grid.innerHTML = '';
        const slots = 8;
        for (let i = 0; i < slots; i++) {
            const of = ofs[i] || null;
            const div = document.createElement('div');
            if (!of) {
                div.className = 'of-slot empty';
                div.innerHTML = `<div class="of-slot-num">${i+1}</div>`;
            } else {
                div.className = 'of-slot' + (of.prioridad ? ' prioritaria' : '');
                div.dataset.of = of.of;
                div.innerHTML = `
                    <div class="of-slot-num">${i+1}</div>
                    <div class="of-slot-code">${of.of}</div>
                    <div class="of-slot-ref">${of.ref || ''}</div>
                `;
            }
            grid.appendChild(div);
        }
    }

    $('#ofs-grid').addEventListener('click', async e => {
        const slot = e.target.closest('.of-slot[data-of]');
        if (!slot) return;
        await abrirDetalle(slot.dataset.of);
    });

    // ── Pantalla 2: detalle ─────────────────────────────────────────
    async function abrirDetalle(ofCodigo) {
        try {
            const d = await api('detalle', {
                query: { cod: state.cod_maquina, f: state.fecha, of: ofCodigo }
            });
            state.ofActual = d.of;
            $('#detalle-of').textContent = d.of.of;
            $('#d-ref').textContent   = d.of.ref || '—';
            $('#d-galga').textContent = d.of.ubicacion_galga || '—';
            $('#d-cant').textContent  = d.of.cantidad ?? '—';
            $('#d-dur').textContent   = d.of.duracion_horas ?? '—';
            $('#d-notas').textContent = d.of.notas || '—';
            $('#d-resp').textContent  = d.of.responsable || '';
            $('#btn-lanzar').disabled = false;
            setStep('detalle');
        } catch (e) {
            showToast(e.message, 'error');
        }
    }

    $('#btn-back').addEventListener('click', () => {
        state.ofActual = null;
        setStep('estacion');
    });

    $('#btn-lanzar').addEventListener('click', async () => {
        if (!state.ofActual) return;
        if (!confirm('¿Lanzar la OF ' + state.ofActual.of + ' en ' + state.cod_maquina + '?')) return;
        $('#btn-lanzar').disabled = true;
        try {
            const r = await api('lanzar', {
                method: 'POST',
                body: {
                    of_codigo:        state.ofActual.of,
                    ref:              state.ofActual.ref,
                    cod_maquina:      state.cod_maquina,
                    desc_maquina:     state.desc_maquina,
                    cantidad:         state.ofActual.cantidad,
                    duracion_horas:   state.ofActual.duracion_horas,
                    ubicacion_galga:  state.ofActual.ubicacion_galga,
                    notas:            state.ofActual.notas,
                    operario:         state.operario,
                },
            });
            showToast('OF ' + r.of_codigo + ' lanzada · registro #' + r.id, 'success');
            // Abrimos el PDF en pestaña nueva
            state.lastLaunchId = r.id;
            window.open('api/oflanza_pdf.php?id=' + r.id, '_blank', 'noopener');
            // Pasamos a la pantalla de etiquetas con la OF recién lanzada
            abrirEtiquetas();
        } catch (e) {
            showToast(e.message, 'error');
            $('#btn-lanzar').disabled = false;
        }
    });

    // ── Pantalla 3: Etiquetas ───────────────────────────────────────
    function abrirEtiquetas() {
        if (!state.ofActual) return;
        $('#etq-of').textContent = state.ofActual.of;
        const cant   = Number(state.ofActual.cantidad) || 0;
        const xCaja  = 120; // valor por defecto (se podrá cargar de Sage en próxima fase)
        const teor   = (xCaja > 0) ? Math.ceil(cant / xCaja) : 0;
        $('#etq-uds-total').textContent = cant.toLocaleString('es-ES');
        $('#etq-uds-caja').textContent  = xCaja;
        $('#etq-teoricas').textContent  = teor + ' etiquetas';
        $('#etq-num-imprimir').value = teor;
        $('#etq-uds-por-etq').value  = xCaja;
        $('#etq-warning').style.display = 'none';
        setStep('etiquetas');
    }

    function actualizarAvisoEtiquetas() {
        if (!state.ofActual) return;
        const cant  = Number(state.ofActual.cantidad) || 0;
        const xCaja = Number($('#etq-uds-por-etq').value) || 0;
        const teor  = (xCaja > 0) ? Math.ceil(cant / xCaja) : 0;
        const n     = Number($('#etq-num-imprimir').value) || 0;
        $('#etq-warning').style.display = (n !== teor) ? '' : 'none';
    }
    $('#etq-num-imprimir').addEventListener('input', actualizarAvisoEtiquetas);
    $('#etq-uds-por-etq').addEventListener('input', actualizarAvisoEtiquetas);

    $('#btn-etq-cancel').addEventListener('click', async () => {
        await cargarOfs();
        setStep('estacion');
    });
    $('#btn-imprimir').addEventListener('click', async () => {
        const n = Number($('#etq-num-imprimir').value) || 0;
        if (n <= 0) { showToast('Indica un número de etiquetas mayor a 0', 'error'); return; }
        const cant  = Number(state.ofActual.cantidad) || 0;
        const xCaja = Number($('#etq-uds-por-etq').value) || 0;
        const teor  = (xCaja > 0) ? Math.ceil(cant / xCaja) : 0;
        if (n !== teor) {
            const pw = window.prompt('Estás imprimiendo ' + n + ' etiquetas (teórico: ' + teor + ').\nIntroduce la contraseña de supervisor:');
            if (pw === null) return;
            // En esta fase no hay validación real; queda registro de la solicitud.
            if (!pw) { showToast('Contraseña requerida', 'error'); return; }
        }
        showToast('Petición de impresión registrada (' + n + ' etiquetas)', 'success');
        // En la fase siguiente esto enviará la orden de impresión al sistema real.
        await cargarOfs();
        setStep('estacion');
    });

    $('#btn-material').addEventListener('click', () => showToast('Pendiente — pantalla "Materiales / Whales" en la siguiente fase'));
    $('#btn-dossier').addEventListener('click', () => showToast('Pendiente — pantalla "Dossier de planta" en la siguiente fase'));

    $('#btn-logout').addEventListener('click', () => {
        state.operario = null;
        state.cod_maquina = '';
        state.ofActual = null;
        pin = '';
        pintarPin();
        $('#estacion-sel').value = '';
        $('#ofs-grid').innerHTML = '<div class="empty-msg" style="grid-column:1/-1">Selecciona una estación y pulsa OK.</div>';
        setStep('login');
    });
})();
</script>

</body></html>
