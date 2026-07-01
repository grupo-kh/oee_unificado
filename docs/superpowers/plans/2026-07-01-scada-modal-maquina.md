# Modal de detalle por máquina (SCADA) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Al clicar una tarjeta del mural SCADA, abrir un modal con 3 pestañas (RESUMEN, PAROS, OFS) que replican las imágenes de referencia, con datos en vivo de MAPEX.

**Architecture:** 3 endpoints PHP nuevos (uno por pestaña) que delegan en nuevos métodos de la clase `lib/ScadaMural.php`; el modal (markup + JS) se añade dentro de `scada.html`. Carga lazy por pestaña. No se modifica ningún archivo salvo `scada.html` y `lib/ScadaMural.php`.

**Tech Stack:** PHP 8.1 + PDO sqlsrv (MAPEX `mapexbp_Test`), HTML/CSS/JS vanilla. Verificación por ejecución (curl + navegador); no hay suite de tests automatizados.

## Global Constraints

- Idioma de TODO el código, comentarios y textos visibles: **español (castellano)**.
- Reutilizar `jsonOk()`/`jsonError()` (`includes/helpers.php`) y `fetchAll('mapex',$sql,$params)`.
- Solo consultas `SELECT`.
- `jsonOk($data)` envuelve en `{ok:true, data:{...}}` → el JS lee `resp.data`.
- Color corporativo KH: `#8c181a` (var `--kh` ya definida en scada.html).
- Tras tocar el servidor: `sudo systemctl restart oee-unificado-v2`.
- Acceso: `http://10.0.0.110:8091/scada.html` (NUNCA localhost desde Chrome remoto).
- No modificar archivos existentes salvo `scada.html` y `lib/ScadaMural.php`.
- El modal no debe cerrarse ni parpadear por el auto-refresco del mural de fondo.

---

## File Structure

- **Modify `lib/ScadaMural.php`** — añadir 3 métodos públicos: `resumenMaquina($cod)`,
  `parosMaquina($cod, $fecha, $dias=1)`, `ofsMaquina($cod, $fecha)`. Reutilizan la
  conexión, `calcDRC` y patrones de query ya presentes en la clase.
- **Create `api/scada_maquina_resumen.php`** — endpoint fino pestaña RESUMEN.
- **Create `api/scada_maquina_paros.php`** — endpoint fino pestaña PAROS.
- **Create `api/scada_maquina_ofs.php`** — endpoint fino pestaña OFS.
- **Modify `scada.html`** — markup del modal + CSS + JS (clic en tarjeta, pestañas,
  filtro de fecha, cierre, pausa del refresco de fondo).

---

### Task 1: `resumenMaquina()` + endpoint RESUMEN

**Files:**
- Modify: `lib/ScadaMural.php`
- Create: `api/scada_maquina_resumen.php`

**Interfaces:**
- Consumes: `calcDRC(...)`, `kpiTurnoTodas()`, `kpiOfTodas()` (ya existen en la clase).
- Produces: `ScadaMural::resumenMaquina(string $cod): array` con
  `['ritmo'=>['real','teorico','desvio'], 'oee'=>['turno','of','rend_turno'],
    'orden'=>['ok','plan','pct','faltan'], 'unidades'=>['ok','nok','rwk']]`.

- [ ] **Step 1: Añadir `resumenMaquina()` a la clase**

Insertar dentro de `class ScadaMural` (antes del cierre `}`):

```php
    /** Datos de la pestaña RESUMEN del modal para una máquina. */
    public static function resumenMaquina(string $cod): array
    {
        // Fila rt_* de esa máquina (reutiliza el filtro/joins del mural pero por 1 máquina)
        $sql = "
            SELECT cm.Cod_maquina, cm.Rt_Cod_of,
                   cm.Rt_Rendimientonominal1,
                   cm.Rt_Unidades_ok_turno, cm.Rt_Unidades_nok_turno, cm.Rt_Unidades_repro_turno,
                   cm.Rt_Unidades_ok_of, cm.Rt_Seg_produccion_turno,
                   fa.Unidades_planning AS plan_of
            FROM cfg_maquina cm
            LEFT JOIN his_fase fa ON fa.Id_his_fase = cm.Rt_Id_his_fase
            WHERE cm.Cod_maquina = ?";
        $r = fetchAll('mapex', $sql, [$cod])[0] ?? null;
        if (!$r) return [
            'ritmo'=>['real'=>0,'teorico'=>0,'desvio'=>0],
            'oee'=>['turno'=>0,'of'=>0,'rend_turno'=>0],
            'orden'=>['ok'=>0,'plan'=>0,'pct'=>0,'faltan'=>0],
            'unidades'=>['ok'=>0,'nok'=>0,'rwk'=>0],
        ];

        $uh      = (float)$r['Rt_Rendimientonominal1'];
        $segProd = (int)$r['Rt_Seg_produccion_turno'];
        $okT     = (int)$r['Rt_Unidades_ok_turno'];
        $teorico = $uh > 0 ? (int)round($segProd * $uh / 3600) : 0;

        // KPI en lote (mismas 2 consultas que el mural) y se toma esta máquina.
        $kt = self::kpiTurnoTodas()[$cod] ?? self::calcDRC(0,0,0,0,0,0);
        $of = trim((string)$r['Rt_Cod_of']);
        $ko = self::kpiOfTodas([$of])["$cod|$of"] ?? self::calcDRC(0,0,0,0,0,0);

        $okOf = (int)$r['Rt_Unidades_ok_of'];
        $plan = (int)($r['plan_of'] ?? 0);

        return [
            'ritmo' => ['real'=>$okT, 'teorico'=>$teorico, 'desvio'=>$okT-$teorico],
            'oee'   => ['turno'=>$kt['oee'], 'of'=>$ko['oee'], 'rend_turno'=>$kt['rendimiento']],
            'orden' => ['ok'=>$okOf, 'plan'=>$plan,
                        'pct'=>$plan>0?(int)round($okOf*100/$plan):0,
                        'faltan'=>max(0,$plan-$okOf)],
            'unidades' => ['ok'=>$okT, 'nok'=>(int)$r['Rt_Unidades_nok_turno'],
                           'rwk'=>(int)$r['Rt_Unidades_repro_turno']],
        ];
    }
```

- [ ] **Step 2: Crear el endpoint**

`api/scada_maquina_resumen.php`:

```php
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/ScadaMural.php';

/** RESUMEN de una máquina para el modal SCADA. GET: cod (Cod_maquina). */
try {
    $cod = trim((string)($_GET['cod'] ?? ''));
    if ($cod === '') jsonError('cod requerido');
    jsonOk(ScadaMural::resumenMaquina($cod));
} catch (Throwable $e) {
    error_log('scada_maquina_resumen: ' . $e->getMessage());
    jsonError('No se pudo cargar el resumen', 500);
}
```

- [ ] **Step 3: Verificar**

Run: `php -l lib/ScadaMural.php && php -l api/scada_maquina_resumen.php`
Expected: sin errores.

Run: `curl -s "http://127.0.0.1:8091/api/scada_maquina_resumen.php?cod=DOBL13" | python3 -m json.tool | head -30`
Expected: JSON con `ritmo`, `oee`, `orden` (plan 35490 aprox), `unidades`.

- [ ] **Step 4: Commit**

```bash
git add lib/ScadaMural.php api/scada_maquina_resumen.php
git commit -m "feat(scada): endpoint RESUMEN del modal por máquina"
```

---

### Task 2: `parosMaquina()` + endpoint PAROS

**Files:**
- Modify: `lib/ScadaMural.php`
- Create: `api/scada_maquina_paros.php`

**Interfaces:**
- Produces: `ScadaMural::parosMaquina(string $cod, string $fecha, int $dias=1): array`
  con `['fecha','total_seg','paros'=>[['ini','fin','seg','motivo','of'],...]]`.

- [ ] **Step 1: Añadir `parosMaquina()` a la clase**

```php
    /** Paros de una máquina en un día (o rango de $dias hacia atrás). */
    public static function parosMaquina(string $cod, string $fecha, int $dias = 1): array
    {
        $dias = max(1, $dias);
        $ini = date('Y-m-d', strtotime("$fecha -" . ($dias - 1) . " days"));
        $sql = "
            SELECT hpp.Fecha_ini, hpp.Fecha_fin,
                   DATEDIFF(SECOND, hpp.Fecha_ini, ISNULL(hpp.Fecha_fin, GETDATE())) AS seg,
                   cp.Desc_paro AS motivo, o.Cod_of AS ofx
            FROM his_prod_paro hpp
            INNER JOIN his_prod hp     ON hp.Id_his_prod = hpp.Id_his_prod
            INNER JOIN cfg_maquina mq  ON mq.Id_maquina  = hp.Id_maquina
            LEFT  JOIN cfg_paro cp     ON cp.Id_paro     = hpp.Id_paro
            LEFT  JOIN his_fase fa     ON fa.Id_his_fase = hp.Id_his_fase
            LEFT  JOIN his_of o        ON o.Id_his_of    = fa.Id_his_of
            WHERE mq.Cod_maquina = ?
              AND CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?
            ORDER BY hpp.Fecha_ini DESC";
        $rows = fetchAll('mapex', $sql, [$cod, $ini, $fecha]);
        $paros = []; $tot = 0;
        foreach ($rows as $r) {
            $seg = (int)$r['seg']; $tot += $seg;
            $paros[] = [
                'ini'    => date('d/m H:i', strtotime((string)$r['Fecha_ini'])),
                'fin'    => $r['Fecha_fin'] ? date('d/m H:i', strtotime((string)$r['Fecha_fin'])) : '—',
                'seg'    => $seg,
                'motivo' => trim((string)($r['motivo'] ?? 'DESCONOCIDO')),
                'of'     => trim((string)($r['ofx'] ?? '')),
            ];
        }
        return ['fecha'=>$fecha, 'total_seg'=>$tot, 'paros'=>$paros];
    }
```

- [ ] **Step 2: Crear el endpoint**

`api/scada_maquina_paros.php`:

```php
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/ScadaMural.php';

/** PAROS de una máquina para el modal. GET: cod, fecha (YYYY-MM-DD), dias (opt). */
try {
    $cod = trim((string)($_GET['cod'] ?? ''));
    if ($cod === '') jsonError('cod requerido');
    $fecha = trim((string)($_GET['fecha'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');
    $dias = (int)($_GET['dias'] ?? 1);
    jsonOk(ScadaMural::parosMaquina($cod, $fecha, $dias));
} catch (Throwable $e) {
    error_log('scada_maquina_paros: ' . $e->getMessage());
    jsonError('No se pudieron cargar los paros', 500);
}
```

- [ ] **Step 3: Verificar**

Run: `php -l lib/ScadaMural.php && php -l api/scada_maquina_paros.php`
Expected: sin errores.

Run: `curl -s "http://127.0.0.1:8091/api/scada_maquina_paros.php?cod=SOLD8&fecha=2026-07-01" | python3 -m json.tool | head -30`
Expected: JSON con `total_seg` y `paros` (motivos como "NO JUSTIFICADO").

- [ ] **Step 4: Commit**

```bash
git add lib/ScadaMural.php api/scada_maquina_paros.php
git commit -m "feat(scada): endpoint PAROS del modal por máquina"
```

---

### Task 3: `ofsMaquina()` + endpoint OFS

**Files:**
- Modify: `lib/ScadaMural.php`
- Create: `api/scada_maquina_ofs.php`

**Interfaces:**
- Produces: `ScadaMural::ofsMaquina(string $cod, string $fecha): array`
  con `['fecha','ofs'=>[['of','producto','plan','ok','rwk','pct'],...]]`.

- [ ] **Step 1: Añadir `ofsMaquina()` a la clase**

```php
    /** OFs producidas por una máquina en un día. */
    public static function ofsMaquina(string $cod, string $fecha): array
    {
        $sql = "
            SELECT o.Cod_of, prod.Desc_producto AS producto,
                   MAX(fa.Unidades_planning)        AS plan_of,
                   SUM(ISNULL(hp.Unidades_ok,0))    AS ok,
                   SUM(ISNULL(hp.Unidades_repro,0)) AS rwk
            FROM his_prod hp
            INNER JOIN cfg_maquina mq  ON mq.Id_maquina  = hp.Id_maquina
            INNER JOIN his_fase fa     ON fa.Id_his_fase = hp.Id_his_fase
            INNER JOIN his_of o        ON o.Id_his_of    = fa.Id_his_of
            LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
            WHERE mq.Cod_maquina = ?
              AND CAST(hp.Dia_productivo AS DATE) = ?
              AND o.Cod_of <> '--'
            GROUP BY o.Cod_of, prod.Desc_producto
            ORDER BY o.Cod_of";
        $ofs = [];
        foreach (fetchAll('mapex', $sql, [$cod, $fecha]) as $r) {
            $plan = (int)$r['plan_of']; $ok = (int)$r['ok'];
            $ofs[] = [
                'of'       => trim((string)$r['Cod_of']),
                'producto' => trim((string)($r['producto'] ?? '')),
                'plan'     => $plan, 'ok' => $ok, 'rwk' => (int)$r['rwk'],
                'pct'      => $plan > 0 ? (int)round($ok*100/$plan) : 0,
            ];
        }
        return ['fecha'=>$fecha, 'ofs'=>$ofs];
    }
```

- [ ] **Step 2: Crear el endpoint**

`api/scada_maquina_ofs.php`:

```php
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/ScadaMural.php';

/** OFS de una máquina para el modal. GET: cod, fecha (YYYY-MM-DD). */
try {
    $cod = trim((string)($_GET['cod'] ?? ''));
    if ($cod === '') jsonError('cod requerido');
    $fecha = trim((string)($_GET['fecha'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');
    jsonOk(ScadaMural::ofsMaquina($cod, $fecha));
} catch (Throwable $e) {
    error_log('scada_maquina_ofs: ' . $e->getMessage());
    jsonError('No se pudieron cargar las OFs', 500);
}
```

- [ ] **Step 3: Verificar**

Run: `php -l lib/ScadaMural.php && php -l api/scada_maquina_ofs.php`
Expected: sin errores.

Run: `curl -s "http://127.0.0.1:8091/api/scada_maquina_ofs.php?cod=SOLD8&fecha=2026-07-01" | python3 -m json.tool | head -30`
Expected: JSON con `ofs` (código, producto, plan, ok, rwk, pct).

- [ ] **Step 4: Commit**

```bash
git add lib/ScadaMural.php api/scada_maquina_ofs.php
git commit -m "feat(scada): endpoint OFS del modal por máquina"
```

---

### Task 4: Modal en `scada.html` — estructura, apertura y pestaña RESUMEN

**Files:**
- Modify: `scada.html`

**Interfaces:**
- Consumes: `api/scada_maquina_resumen.php?cod=`.
- Produces (JS, en el scope de la página): `abrirModal(m)` (m = objeto máquina del
  mural), `cerrarModal()`, `cargarPestana(nombre)` con `nombre ∈ {resumen,paros,ofs}`.

- [ ] **Step 1: Añadir CSS del modal**

Insertar en el `<style>` de `scada.html` (antes de `</style>`):

```css
  .modal-overlay{position:fixed;inset:0;background:rgba(20,24,32,.55);
       display:none;align-items:flex-start;justify-content:center;z-index:50;padding:30px 16px;overflow:auto}
  .modal-overlay.abierto{display:flex}
  .modal{background:#fff;border-radius:14px;width:min(920px,100%);
       border-top:5px solid var(--kh);box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden}
  .modal .m-cab{padding:16px 20px;background:#eef6f0}
  .modal .m-nom{font-size:24px;font-weight:800}
  .modal .m-cod{font-size:13px;color:var(--gris);font-weight:700;margin-left:8px}
  .modal .m-of{display:flex;gap:8px;align-items:center;margin-top:8px;font-size:12px}
  .modal .m-of .cod{background:#fff;border:1px solid var(--linea);border-radius:6px;padding:4px 8px;font-weight:700}
  .modal .m-of .prod{color:var(--kh);font-weight:800;font-size:14px}
  .modal .m-tabs{display:flex;gap:22px;align-items:center;padding:0 20px;border-bottom:1px solid var(--linea)}
  .modal .m-tab{padding:12px 2px;font-size:13px;font-weight:700;color:var(--gris);
       background:none;border:none;border-bottom:3px solid transparent;cursor:pointer}
  .modal .m-tab.activa{color:var(--texto);border-bottom-color:var(--kh)}
  .modal .m-actualizar{margin-left:auto;font-size:12px;color:var(--gris);cursor:pointer;background:none;border:none}
  .modal .m-cerrar{position:absolute;top:14px;right:18px;font-size:22px;color:var(--gris);cursor:pointer;background:none;border:none}
  .modal .m-body{padding:18px 20px;min-height:220px}
  .m-tit{font-size:11px;color:var(--gris);font-weight:800;letter-spacing:.5px;margin:4px 0 8px}
  .ritmo-num{font-size:40px;font-weight:800}
  .ritmo-num small{font-size:16px;color:var(--gris);font-weight:600}
  .ritmo-desvio{float:right;color:var(--rojo);font-weight:800}
  .oee3{display:flex;gap:24px;margin-top:6px}
  .oee3 .it{flex:1}
  .oee3 .et{font-size:11px;color:var(--gris)}
  .oee3 .v{font-size:22px;font-weight:800;float:right}
  .fecha-fil{display:flex;gap:8px;align-items:center;margin-bottom:12px;font-size:13px}
  .fecha-fil input{padding:5px 8px;border:1px solid var(--linea);border-radius:6px}
  .fecha-fil .b{padding:5px 10px;border:1px solid var(--linea);border-radius:6px;background:#fff;cursor:pointer;font-size:12px}
  .paro-row{display:grid;grid-template-columns:auto auto auto 1fr auto;gap:14px;align-items:center;
       padding:10px 12px;border:1px solid var(--linea);border-radius:8px;margin-bottom:8px;font-size:13px}
  .paro-row .t{color:var(--gris);font-variant-numeric:tabular-nums}
  .paro-row .of{color:var(--gris);font-size:12px}
  .of-card{border:1px solid var(--linea);border-radius:10px;padding:12px 14px;margin-bottom:10px}
  .of-card .cod{font-weight:800}
  .of-card .prod{color:var(--gris);font-size:13px;margin:4px 0}
  .of-card .nums{font-size:13px}
  .of-card .nums .pl{color:var(--azul);font-weight:700}
  .of-card .nums .ok{color:var(--verde);font-weight:700}
  .of-card .pct{float:right;font-weight:800}
  .m-cargando{color:var(--gris);padding:30px;text-align:center}
```

- [ ] **Step 2: Añadir el markup del modal** (antes de `</body>`, tras el `#mural`)

```html
<div class="modal-overlay" id="modalOverlay">
  <div class="modal" id="modal" style="position:relative">
    <button class="m-cerrar" id="mCerrar">×</button>
    <div class="m-cab">
      <div><span class="m-nom" id="mNom"></span><span class="m-cod" id="mCod"></span>
        <span class="badge" id="mBadge" style="margin-left:10px"></span></div>
      <div class="m-of"><span class="cod" id="mOf"></span><span class="prod" id="mProd"></span></div>
    </div>
    <div class="m-tabs">
      <button class="m-tab activa" data-p="resumen">RESUMEN</button>
      <button class="m-tab" data-p="paros">PAROS</button>
      <button class="m-tab" data-p="ofs">OFS</button>
      <button class="m-actualizar" id="mActualizar">↻ Actualizar</button>
    </div>
    <div class="m-body" id="mBody"><div class="m-cargando">Cargando…</div></div>
  </div>
</div>
```

- [ ] **Step 3: Añadir el JS de apertura + pestaña RESUMEN**

Insertar antes de `cargar();` (al final del `<script>`). Guarda la máquina actual
y monta el modal:

```javascript
let modalMaq = null, modalPestana = 'resumen', modalAbierto = false;

function badgeEstadoClase(m){ return badgeEstado(m.id_actividad, m.estado); }

function abrirModal(m){
  modalMaq = m; modalPestana = 'resumen'; modalAbierto = true;
  document.getElementById('mNom').textContent = m.desc_maquina;
  document.getElementById('mCod').textContent = m.cod_maquina;
  const b = document.getElementById('mBadge');
  b.className = 'badge ' + badgeEstadoClase(m); b.textContent = m.estado;
  document.getElementById('mOf').textContent = m.of;
  document.getElementById('mProd').textContent = m.producto;
  document.querySelectorAll('.m-tab').forEach(t=>t.classList.toggle('activa', t.dataset.p==='resumen'));
  document.getElementById('modalOverlay').classList.add('abierto');
  cargarPestana('resumen');
}
function cerrarModal(){ modalAbierto=false; document.getElementById('modalOverlay').classList.remove('abierto'); }

function barra(v,color){ return `<div class="barra"><span style="width:${pct(v)}%;background:${color||colorPct(v)}"></span></div>`; }

async function cargarPestana(nombre){
  modalPestana = nombre;
  const body = document.getElementById('mBody');
  body.innerHTML = '<div class="m-cargando">Cargando…</div>';
  try{
    if(nombre==='resumen'){
      const r = await fetch(`api/scada_maquina_resumen.php?cod=${encodeURIComponent(modalMaq.cod_maquina)}`,{cache:'no-store'});
      const j = await r.json(); if(!j.ok) throw 0; body.innerHTML = renderResumen(j.data);
    } else if(nombre==='paros'){
      renderFiltroFecha(body, 'paros');
    } else {
      renderFiltroFecha(body, 'ofs');
    }
  }catch(e){ body.innerHTML = '<div class="m-cargando">No se pudieron cargar los datos.</div>'; }
}

function renderResumen(d){
  const desvioTxt = d.ritmo.desvio<0 ? `${d.ritmo.desvio} bajo el ritmo`
                   : d.ritmo.desvio>0 ? `+${d.ritmo.desvio} sobre el ritmo` : 'en ritmo';
  return `
    <div class="m-tit">RITMO DEL TURNO · REAL vs TEÓRICO</div>
    <div><span class="ritmo-num">${d.ritmo.real}<small> / ${d.ritmo.teorico} esperadas</small></span>
      <span class="ritmo-desvio">${desvioTxt}</span></div>
    ${barra(d.ritmo.teorico?Math.min(100,d.ritmo.real*100/d.ritmo.teorico):0,'var(--rojo)')}
    <div class="m-tit" style="margin-top:18px">OEE</div>
    <div class="oee3">
      <div class="it"><span class="v" style="color:${colorPct(d.oee.turno)}">${d.oee.turno}%</span><div class="et">Turno</div>${barra(d.oee.turno)}</div>
      <div class="it"><span class="v" style="color:${colorPct(d.oee.of)}">${d.oee.of}%</span><div class="et">OF</div>${barra(d.oee.of)}</div>
      <div class="it"><span class="v" style="color:${colorPct(d.oee.rend_turno)}">${d.oee.rend_turno}%</span><div class="et">Rend. turno</div>${barra(d.oee.rend_turno)}</div>
    </div>
    <div class="m-tit" style="margin-top:18px">ORDEN EN CURSO</div>
    <div><span class="ritmo-num" style="font-size:28px">${d.orden.ok} / ${d.orden.plan}</span>
      <small style="color:var(--gris)"> · ${d.orden.pct}%</small>
      <span class="ritmo-desvio" style="color:var(--ambar)">faltan ${d.orden.faltan}</span></div>
    ${barra(d.orden.pct)}
    <div style="margin-top:10px;font-size:13px">
      <span style="color:var(--verde);font-weight:700">OK ${d.unidades.ok}</span> &nbsp;
      NOK ${d.unidades.nok} &nbsp; RWK ${d.unidades.rwk}</div>`;
}
```

- [ ] **Step 4: Enganchar clic en tarjeta + cierre + pestañas**

En la función `pinta(data)` existente, tras `mural.innerHTML = ...`, añadir el
enlace tarjeta→máquina. Como `tarjeta(m)` genera HTML string, se añade `data-cod`
en la `.tarjeta` y un listener delegado. Modificar la línea de `.tarjeta` en
`tarjeta(m)`:

```javascript
  // en tarjeta(m): cambiar la apertura del div
  return `
  <div class="tarjeta" data-cod="${m.cod_maquina}" style="cursor:pointer">
```

Y añadir al final del `<script>` (antes de `cargar();`) los listeners:

```javascript
document.getElementById('mural').addEventListener('click', e=>{
  const t = e.target.closest('.tarjeta'); if(!t) return;
  const m = (window._ultimoMural||[]).find(x=>x.cod_maquina===t.dataset.cod);
  if(m) abrirModal(m);
});
document.getElementById('mCerrar').addEventListener('click', cerrarModal);
document.getElementById('modalOverlay').addEventListener('click', e=>{ if(e.target.id==='modalOverlay') cerrarModal(); });
document.addEventListener('keydown', e=>{ if(e.key==='Escape') cerrarModal(); });
document.querySelectorAll('.m-tab').forEach(t=>t.addEventListener('click', ()=>{
  document.querySelectorAll('.m-tab').forEach(x=>x.classList.remove('activa'));
  t.classList.add('activa'); cargarPestana(t.dataset.p);
}));
document.getElementById('mActualizar').addEventListener('click', ()=>cargarPestana(modalPestana));
```

En `pinta(data)` guardar la lista para el buscador del clic:

```javascript
  window._ultimoMural = data.maquinas;
```

- [ ] **Step 5: Evitar que el refresco del mural interfiera con el modal**

En la función `cargar()`, envolver el repintado para no perder el modal: el modal
es un overlay independiente del `#mural`, así que repintar `#mural` no lo cierra.
Solo hay que asegurar que `window._ultimoMural` se actualiza (Step 4 ya lo hace) y
que el clic sigue funcionando (listener delegado, sobrevive al re-render). No se
requiere pausar el refresco. Verificar en el Step 6.

- [ ] **Step 6: Verificar RESUMEN en navegador**

Run: `sudo systemctl restart oee-unificado-v2` (no imprescindible para HTML, pero refresca).
Navegar a `http://10.0.0.110:8091/scada.html`, clicar una tarjeta. Verificar:
modal abre con cabecera correcta, pestaña RESUMEN muestra ritmo/OEE/orden/unidades,
cierre con ✕/Esc/clic-fuera funciona, y que tras un ciclo de refresco de fondo el
modal sigue abierto.

- [ ] **Step 7: Commit**

```bash
git add scada.html
git commit -m "feat(scada): modal por máquina con pestaña RESUMEN"
```

---

### Task 5: Pestañas PAROS y OFS en el modal (con filtro de fecha)

**Files:**
- Modify: `scada.html`

**Interfaces:**
- Consumes: `api/scada_maquina_paros.php?cod=&fecha=&dias=`, `api/scada_maquina_ofs.php?cod=&fecha=`.

- [ ] **Step 1: Añadir `renderFiltroFecha` y render de PAROS/OFS**

Añadir al `<script>` (junto a `renderResumen`):

```javascript
function hoyISO(){ return new Date().toISOString().slice(0,10); }
function dmy(iso){ const [y,m,d]=iso.split('-'); return `${d}/${m}/${y}`; }
function durSeg(s){ s=Number(s)||0; const h=Math.floor(s/3600), m=Math.floor(s%3600/60), x=s%60;
  return h>0?`${h}h ${m}m`:(m>0?`${m}m ${x<10?'0':''}${x}s`:`${x}s`); }

// Estado de fecha por pestaña
let fechaModal = null;

function renderFiltroFecha(body, pestana){
  if(!fechaModal) fechaModal = hoyISO();
  body.innerHTML = `
    <div class="fecha-fil">
      <span>Fecha:</span>
      <input type="date" id="mFecha" value="${fechaModal}">
      <button class="b" data-r="hoy">Hoy</button>
      <button class="b" data-r="ayer">Ayer</button>
      <button class="b" data-r="7dias">7 días</button>
    </div>
    <div id="mLista"><div class="m-cargando">Cargando…</div></div>`;
  const recargar = (dias=1)=> pestana==='paros' ? cargarParos(dias) : cargarOfs();
  body.querySelector('#mFecha').addEventListener('change', e=>{ fechaModal=e.target.value; recargar(); });
  body.querySelectorAll('.b').forEach(b=>b.addEventListener('click', ()=>{
    const r=b.dataset.r;
    if(r==='hoy'){ fechaModal=hoyISO(); recargar(1); }
    else if(r==='ayer'){ const d=new Date(); d.setDate(d.getDate()-1); fechaModal=d.toISOString().slice(0,10); recargar(1); }
    else { fechaModal=hoyISO(); recargar(7); }
    body.querySelector('#mFecha').value = fechaModal;
  }));
  recargar();
}

async function cargarParos(dias=1){
  const cont = document.getElementById('mLista');
  cont.innerHTML='<div class="m-cargando">Cargando…</div>';
  try{
    const r=await fetch(`api/scada_maquina_paros.php?cod=${encodeURIComponent(modalMaq.cod_maquina)}&fecha=${fechaModal}&dias=${dias}`,{cache:'no-store'});
    const j=await r.json(); if(!j.ok) throw 0; const d=j.data;
    if(!d.paros.length){ cont.innerHTML='<div class="m-cargando">Sin paros en el periodo.</div>'; return; }
    cont.innerHTML = `<div class="m-tit">PAROS DEL DÍA · ${dmy(d.fecha)} · ${durSeg(d.total_seg)} EN TOTAL</div>` +
      d.paros.map(p=>`<div class="paro-row"><span class="t">${p.ini}</span><span class="t">${p.fin}</span>
        <span class="t"><b>${durSeg(p.seg)}</b></span><span>${p.motivo}</span><span class="of">${p.of}</span></div>`).join('');
  }catch(e){ cont.innerHTML='<div class="m-cargando">No se pudieron cargar los paros.</div>'; }
}

async function cargarOfs(){
  const cont = document.getElementById('mLista');
  cont.innerHTML='<div class="m-cargando">Cargando…</div>';
  try{
    const r=await fetch(`api/scada_maquina_ofs.php?cod=${encodeURIComponent(modalMaq.cod_maquina)}&fecha=${fechaModal}`,{cache:'no-store'});
    const j=await r.json(); if(!j.ok) throw 0; const d=j.data;
    if(!d.ofs.length){ cont.innerHTML='<div class="m-cargando">Sin OFs en el día.</div>'; return; }
    cont.innerHTML = `<div class="m-tit">OFS DEL DÍA · ${dmy(d.fecha)}</div>` +
      d.ofs.map(o=>`<div class="of-card"><span class="pct" style="color:${colorPct(o.pct)}">${o.pct}%</span>
        <div class="cod">${o.of}</div><div class="prod">${o.producto}</div>
        <div class="nums"><span class="pl">PLAN ${o.plan}</span> <span class="ok">OK ${o.ok}</span> RWK ${o.rwk}</div>
        ${barra(o.pct)}</div>`).join('');
  }catch(e){ cont.innerHTML='<div class="m-cargando">No se pudieron cargar las OFs.</div>'; }
}
```

- [ ] **Step 2: Resetear fecha al abrir el modal**

En `abrirModal(m)`, añadir `fechaModal = hoyISO();` para que cada apertura empiece
en el día de hoy.

- [ ] **Step 3: Verificar en navegador**

Navegar a `scada.html`, abrir una tarjeta, ir a PAROS: ver lista con total y filtro
(Hoy/Ayer/7 días cambia los datos). Ir a OFS: ver tarjetas de OF con PLAN/OK/RWK y %.
Comparar con `SCADA PAROS.png` y `SCADA OFS.png`.

- [ ] **Step 4: Commit**

```bash
git add scada.html
git commit -m "feat(scada): pestañas PAROS y OFS del modal con filtro de fecha"
```

---

## Notas de verificación (sin suite automatizada)

- Endpoints: `curl` local a `127.0.0.1:8091` con máquina real (DOBL13, SOLD8).
- Visual: navegador por `http://10.0.0.110:8091/scada.html` (no localhost);
  screenshot; si el screenshot da timeout CDP tras redibujar, reintentar.
- Contrastar cada pestaña con su imagen de referencia.
- Tras cambios de servidor: `sudo systemctl restart oee-unificado-v2`.
