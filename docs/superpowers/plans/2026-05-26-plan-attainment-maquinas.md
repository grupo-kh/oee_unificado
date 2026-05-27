# Plan Attainment · Módulos Máquinas + Detalle — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir dos módulos a la vista `views/plan_attainment.php` — un ranking horizontal de máquinas (Módulo 4) y un detalle Plan vs Producido por artículo (Módulo 5), ambos integrados al cross-filter existente. Ampliar la altura de los módulos 2 (Por Sección) y 3 (Evolución) en +50%.

**Architecture:** Se reutiliza el endpoint existente `api/por_maquina.php` (basado en `PlanAttainmentAgg::rangeByMaquina`). Se añade un endpoint nuevo `api/por_articulo_maquina.php` que invoca un método nuevo `PlanAttainmentAgg::rangeByMaquinaArticulo()`. El cross-filter del JS se amplía con `_selMaquina`. La selección de máquina **solo** afecta al módulo 5 (decisión deliberada — el gauge y la evolución no se filtran por máquina para mantener el scope acotado).

**Tech Stack:** PHP 8 (backend, sin framework), ApexCharts (gráficos), CSS plano, jQuery-style helper `$()` propio del proyecto. Sin test runner — la verificación es manual (curl + navegador).

**Reference spec:** [docs/superpowers/specs/2026-05-26-plan-attainment-maquinas-design.md](../specs/2026-05-26-plan-attainment-maquinas-design.md)

**Local URL base:** `http://localhost/PLAN_ATTAINMENT/`

---

## File Structure

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `lib/PlanAttainmentAgg.php` | Modificar | Añadir método estático `rangeByMaquinaArticulo()` |
| `api/por_articulo_maquina.php` | Crear | Endpoint que devuelve filas plan/prod por artículo para una máquina |
| `assets/css/style.css` | Modificar | Subir alturas de módulos 2/3 y añadir estilos módulos 4/5 |
| `views/plan_attainment.php` | Modificar | Añadir markup de módulos 4 y 5 |
| `assets/js/view_plan_attainment_full.js` | Modificar | Estado `_selMaquina`, renderers, handlers, integración |

---

## Task 1: Backend — método `rangeByMaquinaArticulo` en PlanAttainmentAgg

**Files:**
- Modify: `lib/PlanAttainmentAgg.php` (añadir método tras `rangeByMaquina`, ~línea 355)

- [ ] **Step 1: Añadir el método nuevo**

Insertar **tras** el método `rangeByMaquina` (que termina en línea ~355) y **antes** de `dayShiftDetailExt`:

```php
    /**
     * Desglose Plan vs Producido por artículo para una máquina concreta.
     * Recorre día×turno con dayShiftDetailExt() y suma plan/prod por cod_articulo
     * filtrando las claves "maquina|cod_articulo" cuyo prefijo coincida.
     *
     * @return array<int, array{cod_articulo:string, plan:float, prod:float, attain:float, plan_attainment:float}>
     */
    public static function rangeByMaquinaArticulo(
        string $fechaDesde,
        string $fechaHasta,
        array $turnos,
        string $maquinaName
    ): array {
        $byArt = [];
        $d  = new DateTime($fechaDesde);
        $fh = new DateTime($fechaHasta);
        while ($d <= $fh) {
            $ymd = $d->format('Y-m-d');
            foreach ($turnos as $t) {
                $r = self::dayShiftDetailExt($ymd, $t);
                $plan = $r['plan']; $prod = $r['prod'];
                $attain = self::attainWithFuzzyMatch($plan, $prod);
                $keys = array_unique(array_merge(array_keys($plan), array_keys($prod)));
                foreach ($keys as $k) {
                    [$maq, $art] = explode('|', $k, 2);
                    if ($maq !== $maquinaName) continue;
                    if (!isset($byArt[$art])) $byArt[$art] = ['plan'=>0,'prod'=>0,'attain'=>0];
                    $byArt[$art]['plan']   += (float)($plan[$k]   ?? 0);
                    $byArt[$art]['prod']   += (float)($prod[$k]   ?? 0);
                    $byArt[$art]['attain'] += (float)($attain[$k] ?? 0);
                }
            }
            $d->modify('+1 day');
        }
        $out = [];
        foreach ($byArt as $art => $v) {
            if ($v['plan'] == 0 && $v['prod'] == 0) continue;
            $pa = $v['plan'] > 0 ? ($v['attain'] / $v['plan']) * 100 : 0;
            $out[] = [
                'cod_articulo'    => $art,
                'plan'            => round($v['plan'], 0),
                'prod'            => round($v['prod'], 0),
                'attain'          => round($v['attain'], 0),
                'plan_attainment' => round($pa, 2),
            ];
        }
        usort($out, fn($a, $b) => $b['plan'] <=> $a['plan']);
        return $out;
    }
```

- [ ] **Step 2: Verificar sintaxis PHP**

Run:
```powershell
php -l lib/PlanAttainmentAgg.php
```

Expected: `No syntax errors detected in lib/PlanAttainmentAgg.php`

- [ ] **Step 3: Commit**

```bash
git add lib/PlanAttainmentAgg.php
git commit -m "PlanAttainmentAgg: añadir rangeByMaquinaArticulo para detalle por artículo"
```

---

## Task 2: Backend — endpoint `api/por_articulo_maquina.php`

**Files:**
- Create: `api/por_articulo_maquina.php`

- [ ] **Step 1: Crear el endpoint**

Contenido completo del archivo nuevo:

```php
<?php
/**
 * API: Detalle Plan vs Producido por Artículo para una máquina concreta.
 *
 * Misma definición de PA que el resto del panel:
 *   PA = SUM(min(prod_ok, plan) por artículo) / SUM(plan)
 *
 * Solo devuelve filas de la máquina indicada (lista extendida VARILLAS+TROQUELADOS).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';
require_once __DIR__ . '/../lib/PanelMetaBuilder.php';

try {
    $fecha = getParam('fecha');
    if ($fecha && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $fechaDesde = $fecha;
        $fechaHasta = $fecha;
    } else {
        $fechaDesde = getParam('fecha_desde', date('Y-m-d', strtotime('-1 day')));
        $fechaHasta = getParam('fecha_hasta', date('Y-m-d', strtotime('-1 day')));
    }
    $turno   = getParam('turno');
    $maquina = trim((string)getParam('maquina', ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) jsonError('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) jsonError('fecha_hasta inválida');
    if ($maquina === '') jsonError('parámetro maquina requerido');
    if (!isset(PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$maquina])) {
        jsonError('máquina no válida: ' . $maquina);
    }

    ini_set('memory_limit', '2G');
    $turnosAgg = $turno && in_array($turno, ['M','T','N','C'], true)
        ? [$turno]
        : ['M','T','N'];

    $rows = PlanAttainmentAgg::rangeByMaquinaArticulo($fechaDesde, $fechaHasta, $turnosAgg, $maquina);

    $sumPlan = 0; $sumProd = 0; $sumAttain = 0;
    foreach ($rows as $r) {
        $sumPlan   += (float)($r['plan']   ?? 0);
        $sumProd   += (float)($r['prod']   ?? 0);
        $sumAttain += (float)($r['attain'] ?? 0);
    }
    $totales = [
        'plan'            => round($sumPlan, 0),
        'prod'            => round($sumProd, 0),
        'attain'          => round($sumAttain, 0),
        'plan_attainment' => $sumPlan > 0 ? round(($sumAttain / $sumPlan) * 100, 2) : 0,
    ];

    $meta = PanelMetaBuilder::buildPlanProdMeta([
        'panel'      => 'Detalle Plan vs Producido · ' . $maquina,
        'fechaDesde' => $fechaDesde,
        'fechaHasta' => $fechaHasta,
        'turnos'     => $turnosAgg,
        'whitelist'  => 'Detalle filtrado a una sola máquina (lista extendida MAQUINA_TO_SECCION_EXT).',
        'valores'    => ['plan' => $sumPlan, 'prod' => $sumProd, 'attain' => $sumAttain],
    ]);

    jsonOk(['rows' => $rows, 'totales' => $totales, 'meta' => $meta]);

} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
```

- [ ] **Step 2: Verificar sintaxis PHP**

Run:
```powershell
php -l api/por_articulo_maquina.php
```

Expected: `No syntax errors detected in api/por_articulo_maquina.php`

- [ ] **Step 3: Probar el endpoint con curl**

Ejecutar (con XAMPP arrancado, fecha donde se sepa que hubo plan — por ejemplo el día anterior laborable):

```powershell
curl "http://localhost/PLAN_ATTAINMENT/api/por_articulo_maquina.php?fecha=2026-05-25&turno=M&maquina=TURBOBENDER"
```

Expected: JSON con `success: true`, `rows: [...]` con al menos un artículo (si hubo plan/producción de TURBOBENDER ese turno), y `totales` con campos `plan`, `prod`, `attain`, `plan_attainment`.

Si devuelve `success: false` con "máquina no válida" → comprobar que el nombre coincida exactamente con una clave de `MAQUINA_TO_SECCION_EXT`.

- [ ] **Step 4: Probar fallo controlado (máquina inexistente)**

```powershell
curl "http://localhost/PLAN_ATTAINMENT/api/por_articulo_maquina.php?fecha=2026-05-25&turno=M&maquina=NOEXISTE"
```

Expected: JSON `{"success":false,"error":"máquina no válida: NOEXISTE"}` con HTTP 400 (o lo que devuelva `jsonError` por defecto).

- [ ] **Step 5: Commit**

```bash
git add api/por_articulo_maquina.php
git commit -m "api: nuevo endpoint por_articulo_maquina para detalle Plan vs Producido"
```

---

## Task 3: CSS — alturas + estilos de módulos 4 y 5

**Files:**
- Modify: `assets/css/style.css` (línea ~885 para alturas; añadir bloques nuevos al final del fichero o en el grupo de módulos)

- [ ] **Step 1: Subir alturas de módulos 2 y 3**

Localizar las líneas:
```css
#chart-seccion-big   { width: 100%; height: 360px; }
#chart-evolucion-big { width: 100%; height: 360px; }
```

Sustituir por:
```css
#chart-seccion-big   { width: 100%; height: 540px; }
#chart-evolucion-big { width: 100%; height: 540px; }
```

- [ ] **Step 2: Añadir estilos de módulo 4 y 5**

Tras el bloque de `.pa-module-3 .view-card-header` (línea ~882), añadir:

```css
/* Módulo 4 — ranking por máquina, tono lila suave */
.pa-module-4 .view-card-body { background: #f4f1fa; }
.pa-module-4 .view-card-header { background: linear-gradient(180deg, #4a3b87 0%, #6e5cad 100%); }
#chart-maquina-big { width: 100%; min-height: 540px; }

/* Módulo 5 — detalle plan vs producido, tono rosa suave */
.pa-module-5 .view-card-body { background: #fbf4f4; }
.pa-module-5 .view-card-header { background: linear-gradient(180deg, #87413b 0%, #ad6e5c 100%); }

/* Tabla del módulo 5 */
.pa-detalle-table {
    width: 100%;
    border-collapse: collapse;
    font-family: Arial, sans-serif;
    font-size: 13px;
}
.pa-detalle-table th {
    background: #f0e6e6;
    color: #5a2a25;
    font-weight: 700;
    text-align: left;
    padding: 10px 12px;
    border-bottom: 2px solid #c8a098;
}
.pa-detalle-table td {
    padding: 8px 12px;
    border-bottom: 1px solid #ead4d0;
    color: #1a2d4a;
}
.pa-detalle-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
.pa-detalle-table tfoot td {
    font-weight: 700;
    background: #f7eaea;
    border-top: 2px solid #c8a098;
    border-bottom: none;
}
.pa-detalle-table .pa-bar {
    display: inline-block;
    width: 80px;
    height: 8px;
    background: #e8eef5;
    border-radius: 4px;
    overflow: hidden;
    vertical-align: middle;
    margin-right: 6px;
}
.pa-detalle-table .pa-bar-fill {
    display: block;
    height: 100%;
    border-radius: 4px;
}
.pa-detalle-empty {
    text-align: center;
    color: #5b8cc7;
    padding: 40px;
    font-size: 14px;
}
```

- [ ] **Step 3: Verificar visualmente (sin tocar nada más todavía)**

Abrir `http://localhost/PLAN_ATTAINMENT/views/plan_attainment.php` en el navegador.

Expected:
- Los módulos 2 (Por Sección) y 3 (Evolución) son visiblemente más altos (540px en vez de 360px).
- Aún no hay módulos 4 ni 5 (no se han añadido al HTML aún) — eso es normal.

- [ ] **Step 4: Commit**

```bash
git add assets/css/style.css
git commit -m "css: ampliar altura módulos 2/3 a 540px y añadir estilos módulos 4 y 5"
```

---

## Task 4: HTML — añadir markup de módulos 4 y 5 a la vista

**Files:**
- Modify: `views/plan_attainment.php` (insertar tras el módulo 3, antes del `</main>`)

- [ ] **Step 1: Añadir markup**

Localizar el cierre del módulo 3 y la etiqueta `</main>`:

```html
        <div class="view-card-body pa-module-body">
            <div id="chart-evolucion-big"></div>
        </div>
    </div>

</main>
```

Insertar **entre** el cierre del módulo 3 (`</div>` antes de `</main>`) y el `</main>`:

```html

    <!-- ════════════════════════════════════════════════════════════════
         MÓDULO 4 · RANKING POR MÁQUINA (clic en una barra abre detalle)
         ════════════════════════════════════════════════════════════════ -->
    <div class="view-card pa-module pa-module-4">
        <div class="view-card-header">
            <h2>Ranking por Máquina
                <span class="pa-hint">· clic en una barra para ver detalle</span>
            </h2>
            <span class="view-card-info" id="m4-info">VARILLAS + TROQUELADOS</span>
        </div>
        <div class="view-card-body pa-module-body">
            <div id="chart-maquina-big"></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         MÓDULO 5 · DETALLE PLAN vs PRODUCIDO POR ARTÍCULO
         (solo visible cuando hay una máquina seleccionada)
         ════════════════════════════════════════════════════════════════ -->
    <div class="view-card pa-module pa-module-5" id="pa-module-5" style="display:none">
        <div class="view-card-header">
            <h2>Detalle Plan vs Producido
                <span class="pa-hint" id="m5-subtitle">· por artículo</span>
            </h2>
            <span class="view-card-info" id="m5-info">—</span>
        </div>
        <div class="view-card-body pa-module-body">
            <div id="detalle-articulos"></div>
        </div>
    </div>
```

- [ ] **Step 2: Verificar en navegador**

Recargar `http://localhost/PLAN_ATTAINMENT/views/plan_attainment.php`.

Expected:
- Aparece la cabecera "Ranking por Máquina" (módulo 4) con el body vacío (sin gráfico aún).
- El módulo 5 NO se ve (tiene `display:none` por defecto).
- Sin errores en consola.

- [ ] **Step 3: Commit**

```bash
git add views/plan_attainment.php
git commit -m "view: añadir markup módulos 4 (Máquinas) y 5 (Detalle) a plan_attainment"
```

---

## Task 5: JS — estado + render del Módulo 4 (Máquinas)

**Files:**
- Modify: `assets/js/view_plan_attainment_full.js`

- [ ] **Step 1: Añadir variables de estado**

Localizar las declaraciones existentes (líneas 13-20):

```js
let gaugeChart    = null;
let chartSeccion  = null;
let chartEvolucion = null;

let _selSeccion = '';
let _selFecha   = '';

let _gaugeMeta = null;
```

Sustituir por:

```js
let gaugeChart     = null;
let chartSeccion   = null;
let chartEvolucion = null;
let chartMaquina   = null;

let _selSeccion = '';
let _selFecha   = '';
let _selMaquina = '';

let _gaugeMeta = null;
let _maquinasRows = []; // cache de la última respuesta para auto-descarte
```

- [ ] **Step 2: Añadir renderMaquinas tras renderEvolucion**

Tras el cierre de `renderEvolucion()` (~línea 217) y antes de la sección `// ───── Click handlers`, añadir:

```js
// ───── Render máquinas (horizontal bars) ─────────────────────────────
function renderMaquinas(rows) {
    const cont = $('#chart-maquina-big');
    if (!rows.length) {
        cont.innerHTML = '<div style="text-align:center;color:#5b8cc7;padding:40px;font-size:14px">Sin máquinas en el periodo seleccionado</div>';
        if (chartMaquina) { chartMaquina.destroy(); chartMaquina = null; }
        return;
    }
    // Orden descendente por % attainment
    rows = rows.slice().sort((a, b) => parseFloat(b.plan_attainment) - parseFloat(a.plan_attainment));
    const categorias = rows.map(r => r.maquina);
    const valores    = rows.map(r => parseFloat(r.plan_attainment));
    const colors = categorias.map((maq, i) => {
        const c = semColor(valores[i]);
        if (_selMaquina && _selMaquina !== maq) return c + '55';
        return c;
    });
    const altura = Math.max(540, 36 * rows.length + 80);
    const options = {
        chart: {
            type: 'bar', height: altura, background: 'transparent',
            toolbar: { show: false }, fontFamily: 'Arial',
            events: {
                dataPointSelection: (_e, _c, cfg) => {
                    const idx = cfg.dataPointIndex;
                    const maq = categorias[idx];
                    onMaquinaClick(maq);
                }
            }
        },
        series: [{ name: 'Plan Attainment', data: valores }],
        plotOptions: {
            bar: {
                horizontal: true,
                barHeight: '70%',
                borderRadius: 4, borderRadiusApplication: 'end',
                distributed: true,
                dataLabels: { position: 'top' }
            }
        },
        colors: colors,
        xaxis: {
            max: 100, min: 0,
            labels: {
                style: { colors: '#2d4d7a', fontSize: '12px', fontWeight: 600 },
                formatter: v => v.toFixed(0) + '%'
            },
            axisBorder: { color: '#a3b8d1' }, axisTicks: { color: '#a3b8d1' }
        },
        yaxis: {
            labels: { style: { colors: '#1a2d4a', fontSize: '12px', fontWeight: 700 } }
        },
        dataLabels: {
            enabled: true,
            offsetX: 30,
            style: { colors: ['#1a2d4a'], fontFamily: 'Arial', fontSize: '12px', fontWeight: 700 },
            formatter: v => v.toFixed(1) + '%'
        },
        grid: { borderColor: '#d5dfe8', strokeDashArray: 3, xaxis: { lines: { show: true } } },
        legend: { show: false },
        tooltip: {
            y: { formatter: (v, opts) => {
                const r = rows[opts.dataPointIndex];
                return `Plan: ${r.plan_total} · Producido: ${r.prod_total} · PA: ${v.toFixed(2)}%`;
            } }
        },
        states: {
            active: { allowMultipleDataPointsSelection: false, filter: { type: 'none' } },
            hover:  { filter: { type: 'lighten', value: 0.15 } }
        },
        annotations: {
            xaxis: [{
                x: 75,
                borderColor: '#10b981', borderWidth: 2, strokeDashArray: 6,
                label: {
                    text: 'Objetivo 75%', borderColor: '#10b981',
                    style: { color: '#ffffff', background: '#10b981', fontSize: '11px', fontWeight: 700 }
                }
            }]
        }
    };
    if (chartMaquina) chartMaquina.destroy();
    chartMaquina = new ApexCharts(cont, options);
    chartMaquina.render();
}
```

- [ ] **Step 3: Añadir cargarMaquinas tras cargarEvolucion**

Tras el cierre de `cargarEvolucion()` (~línea 317) y antes de `async function cargarTodo()`, añadir:

```js
async function cargarMaquinas() {
    const f = getFiltrosActuales();
    const { fechaDia, turno } = efectivaFiltroFechas(f);
    const data = await apiFetch('por_maquina.php', { fecha: fechaDia, turno });
    let rows = data.rows || [];
    // Filtrado por sección (cliente — la API devuelve seccion en cada fila)
    if (_selSeccion) {
        rows = rows.filter(r => (r.seccion || '').toUpperCase() === _selSeccion);
    }
    _maquinasRows = rows;
    renderMaquinas(rows);
    // Auto-descarte: si la máquina seleccionada ya no aparece, limpiar
    if (_selMaquina && !rows.some(r => r.maquina === _selMaquina)) {
        _selMaquina = '';
        refreshActiveFilterBar();
        ocultarDetalle();
    }
    const info = $('#m4-info');
    if (info) info.textContent = _selSeccion ? `Filtrado · ${_selSeccion}` : 'VARILLAS + TROQUELADOS';
}
```

- [ ] **Step 4: Añadir helper `ocultarDetalle` (provisional, contenido real en Task 6)**

Tras `cargarMaquinas`, añadir un placeholder que se rellenará en la tarea 6:

```js
function ocultarDetalle() {
    const m5 = document.getElementById('pa-module-5');
    if (m5) m5.style.display = 'none';
}
```

- [ ] **Step 5: Integrar `cargarMaquinas` en `cargarTodo`**

Localizar:

```js
async function cargarTodo() {
    showLoader(true);
    try {
        // Carga en paralelo
        await Promise.all([cargarGauge(), cargarSeccion(), cargarEvolucion()]);
```

Sustituir por:

```js
async function cargarTodo() {
    showLoader(true);
    try {
        // Carga en paralelo
        await Promise.all([cargarGauge(), cargarSeccion(), cargarEvolucion(), cargarMaquinas()]);
```

- [ ] **Step 6: Verificar en navegador**

Recargar la vista. Expected:
- El módulo 4 muestra barras horizontales con todas las máquinas activas, ordenadas por % descendente.
- Etiqueta y tooltip muestran Plan/Producido/PA.
- Click en una sección (módulo 2) → módulo 4 filtra a esa sección y muestra "Filtrado · VARILLAS" o "Filtrado · TROQUELADOS" en el chip de info.
- Sin errores en consola.

- [ ] **Step 7: Commit**

```bash
git add assets/js/view_plan_attainment_full.js
git commit -m "view_plan_attainment: render módulo 4 (Máquinas) con cross-filter por sección"
```

---

## Task 6: JS — Módulo 5 (Detalle por artículo)

**Files:**
- Modify: `assets/js/view_plan_attainment_full.js`

- [ ] **Step 1: Sustituir `ocultarDetalle` y añadir el render real**

Sustituir el placeholder `ocultarDetalle` por estas tres funciones (justo después de `cargarMaquinas`):

```js
function ocultarDetalle() {
    const m5 = document.getElementById('pa-module-5');
    if (m5) m5.style.display = 'none';
}

function renderDetalle(rows, totales, maquina) {
    const cont = $('#detalle-articulos');
    if (!rows || !rows.length) {
        cont.innerHTML = '<div class="pa-detalle-empty">Sin datos para ' + (maquina || '') + ' en este turno.</div>';
        return;
    }
    const fila = r => {
        const pa = parseFloat(r.plan_attainment);
        const color = semColor(pa);
        const pct = Math.min(100, Math.max(0, pa));
        return `
            <tr>
                <td>${escapeHTML(r.cod_articulo)}</td>
                <td class="num">${Number(r.plan).toLocaleString('es-ES')}</td>
                <td class="num">${Number(r.prod).toLocaleString('es-ES')}</td>
                <td class="num">${Number(r.attain).toLocaleString('es-ES')}</td>
                <td>
                    <span class="pa-bar"><span class="pa-bar-fill" style="width:${pct}%;background:${color}"></span></span>
                    ${pa.toFixed(1)}%
                </td>
            </tr>`;
    };
    const tot = totales || {};
    const totPa = parseFloat(tot.plan_attainment || 0);
    cont.innerHTML = `
        <table class="pa-detalle-table">
            <thead>
                <tr>
                    <th>Artículo</th>
                    <th style="text-align:right">Plan</th>
                    <th style="text-align:right">Producido</th>
                    <th style="text-align:right">Attain</th>
                    <th>% Plan Attainment</th>
                </tr>
            </thead>
            <tbody>${rows.map(fila).join('')}</tbody>
            <tfoot>
                <tr>
                    <td>TOTAL · ${escapeHTML(maquina || '')}</td>
                    <td class="num">${Number(tot.plan || 0).toLocaleString('es-ES')}</td>
                    <td class="num">${Number(tot.prod || 0).toLocaleString('es-ES')}</td>
                    <td class="num">${Number(tot.attain || 0).toLocaleString('es-ES')}</td>
                    <td>${totPa.toFixed(2)}%</td>
                </tr>
            </tfoot>
        </table>
    `;
}

async function cargarDetalle() {
    if (!_selMaquina) {
        ocultarDetalle();
        return;
    }
    const f = getFiltrosActuales();
    const { fechaDia, turno } = efectivaFiltroFechas(f);
    try {
        const data = await apiFetch('por_articulo_maquina.php', {
            fecha: fechaDia, turno, maquina: _selMaquina
        });
        renderDetalle(data.rows || [], data.totales || {}, _selMaquina);
        const m5 = document.getElementById('pa-module-5');
        const m5info = $('#m5-info');
        if (m5info) m5info.textContent = _selMaquina;
        if (m5) {
            m5.style.display = '';
            m5.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    } catch (e) {
        const cont = $('#detalle-articulos');
        if (cont) cont.innerHTML = '<div class="pa-detalle-empty">Sin detalle disponible: ' + escapeHTML(e.message || '') + '</div>';
        const m5 = document.getElementById('pa-module-5');
        if (m5) m5.style.display = '';
    }
}
```

- [ ] **Step 2: Verificar que `escapeHTML` existe en common.js**

Run:
```powershell
Select-String -Path assets/js/common.js -Pattern "function escapeHTML|escapeHTML\s*="
```

Expected: al menos una coincidencia. Si NO existe, añadir al inicio de `view_plan_attainment_full.js` esta utilidad mínima:

```js
function escapeHTML(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
```

- [ ] **Step 3: Commit**

```bash
git add assets/js/view_plan_attainment_full.js
git commit -m "view_plan_attainment: render módulo 5 (Detalle por artículo)"
```

---

## Task 7: JS — Integración cross-filter completo (handlers + clear + header)

**Files:**
- Modify: `assets/js/view_plan_attainment_full.js`

- [ ] **Step 1: Añadir handler `onMaquinaClick`**

En la sección `// ───── Click handlers`, tras `onFechaClick`, añadir:

```js
function onMaquinaClick(maq) {
    if (_selMaquina === maq) _selMaquina = '';
    else _selMaquina = maq;
    refreshActiveFilterBar();
    // Solo re-renderizamos máquinas (para destacar/atenuar) y detalle.
    // El gauge, sección y evolución no se filtran por máquina.
    renderMaquinas(_maquinasRows);
    cargarDetalle();
}
```

- [ ] **Step 2: Ampliar `refreshActiveFilterBar` con chip de máquina**

Localizar el bloque dentro de `refreshActiveFilterBar`:

```js
    const partes = [];
    if (_selSeccion) partes.push(`<span class="pa-active-filter-chip">SECCIÓN · ${_selSeccion}</span>`);
    if (_selFecha) {
        const [y, m, d] = _selFecha.split('-');
        partes.push(`<span class="pa-active-filter-chip">FECHA · ${d}/${m}/${y}</span>`);
    }
```

Sustituir por:

```js
    const partes = [];
    if (_selSeccion) partes.push(`<span class="pa-active-filter-chip">SECCIÓN · ${_selSeccion}</span>`);
    if (_selFecha) {
        const [y, m, d] = _selFecha.split('-');
        partes.push(`<span class="pa-active-filter-chip">FECHA · ${d}/${m}/${y}</span>`);
    }
    if (_selMaquina) partes.push(`<span class="pa-active-filter-chip">MÁQUINA · ${_selMaquina}</span>`);
```

- [ ] **Step 3: Ampliar `onClearFilter`**

Localizar:
```js
function onClearFilter() {
    _selSeccion = '';
    _selFecha   = '';
    refreshActiveFilterBar();
    cargarTodo();
}
```

Sustituir por:
```js
function onClearFilter() {
    _selSeccion = '';
    _selFecha   = '';
    _selMaquina = '';
    refreshActiveFilterBar();
    ocultarDetalle();
    cargarTodo();
}
```

- [ ] **Step 4: Ampliar el reset por cambio de header**

Localizar dentro de `document.addEventListener('DOMContentLoaded', () => { ... })`:

```js
    initFiltros(() => {
        // Al cambiar fecha/turno del header se limpia el drill-down de fecha
        // (no tiene sentido mantener una fecha clicada de otro día).
        _selFecha = '';
        refreshActiveFilterBar();
        cargarTodo();
    });
```

Sustituir por:

```js
    initFiltros(() => {
        // Al cambiar fecha/turno del header se limpian los drill-downs.
        _selFecha   = '';
        _selMaquina = '';
        ocultarDetalle();
        refreshActiveFilterBar();
        cargarTodo();
    });
```

- [ ] **Step 5: Verificar el flujo completo en navegador**

Recargar `http://localhost/PLAN_ATTAINMENT/views/plan_attainment.php` y comprobar:

1. **Carga inicial** — 4 módulos visibles (1, 2, 3, 4). Módulo 5 oculto.
2. **Click en una sección (módulo 2)** — módulo 4 se reduce a las máquinas de esa sección, chip "SECCIÓN · VARILLAS" aparece en la barra.
3. **Click en una máquina (módulo 4)** — aparece módulo 5 con tabla de artículos, scroll suave hasta él. Chip "MÁQUINA · …" aparece. Gauge y evolución NO se filtran.
4. **Click otra vez en la misma máquina** — módulo 5 desaparece, chip de máquina se quita.
5. **Click en otra máquina** — módulo 5 se recarga con la nueva. Solo una máquina destacada en módulo 4.
6. **Click en un punto de evolución** — todos los módulos (incluido 4) se actualizan a ese día.
7. **Cambio de turno en header** — `_selMaquina` y `_selFecha` se limpian, módulo 5 se oculta.
8. **Botón "Limpiar"** — todo vuelve a estado inicial.
9. **Sin errores en consola**.

- [ ] **Step 6: Commit final**

```bash
git add assets/js/view_plan_attainment_full.js
git commit -m "view_plan_attainment: cross-filter completo con _selMaquina y módulo 5"
```

---

## Verification matrix

| Caso | Módulo 1 (Gauge) | Módulo 2 (Sección) | Módulo 3 (Evolución) | Módulo 4 (Máquinas) | Módulo 5 (Detalle) |
|---|---|---|---|---|---|
| Carga inicial | Datos del día/turno header | 2 barras | Línea 7d | Ranking completo | Oculto |
| Click sección VARILLAS | Filtrado | Toggle visual | Filtrado | Solo VARILLAS | Oculto |
| Click fecha en evolución | Día clicado | Día clicado | Sin cambio (rango 7d) | Día clicado | Oculto |
| Click máquina X | Sin cambio | Sin cambio | Sin cambio | X destacada | X visible con tabla |
| Click X de nuevo | Sin cambio | Sin cambio | Sin cambio | Todas normales | Oculto |
| Cambio turno header | Recarga | Recarga | Recarga | Recarga | Oculto |
| Botón Limpiar | Inicial | Inicial | Inicial | Inicial | Oculto |

## Notas para el ejecutor

- **Sin tests automatizados** — la verificación es manual con curl + navegador. Los pasos "Run" son curl/PHP -l.
- **Memory note de la vista frozen**: el usuario autorizó explícitamente esta modificación; ignorar el aviso para esta tarea.
- **No tocar** `api/por_seccion.php`, `api/evolucion.php`, `api/plan_attainment.php`, `views/por_maquina.php`, ni `assets/js/view_maquina.js`. Solo los ficheros listados en File Structure.
- Si `apiFetch`, `getFiltrosActuales`, `formatFecha`, `semColor`, `showLoader`, `showToast`, `$()` no están claros, revisar `assets/js/common.js` — son helpers compartidos del proyecto.
