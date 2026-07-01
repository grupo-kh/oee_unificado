# SCADA con menú, densidades y ordenación — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ampliar la ventana SCADA con apertura en pestaña nueva, barra de menú (contadores, densidad, zoom/fuente, filtros, ordenación) y tres densidades de tarjeta, manteniendo el modal de detalle en todas.

**Architecture:** El endpoint pasa a traer todas las máquinas activas con estado clasificado, categoría de paro y contadores. `scada.html` añade una barra de menú y render por densidad; filtrado/orden/zoom/persistencia en cliente. El modal existente se reutiliza sin cambios.

**Tech Stack:** PHP 8.1 (MAPEX + PostgreSQL vía Db), HTML/CSS/JS vanilla. Verificación por curl + navegador; sin tests automatizados.

## Global Constraints

- Idioma de TODO el código y textos visibles: **español (castellano)**.
- Reutilizar `jsonOk/jsonError`, `fetchAll('mapex',...)`, `Db::pgFetchAll(...)` (PostgreSQL) y el modal ya existente (`abrirModal`, endpoints scada_maquina_*).
- Universo: `WHERE cm.activo = 1 AND cm.Cod_maquina <> '--'` (todas las activas).
- FIN EST. OF = campo `of_kpi.fin_est` YA calculado por ScadaMural (no campos MAPEX de caducidad, que están sin poblar).
- Categoría/nivel de paro = PostgreSQL `cfg_paro_categoria (cod_paro → tipo_paro_1)`.
- No romper el modal ni el auto-refresco (12s); el refresco re-aplica menú/orden/filtros sin cerrar el modal.
- Colores estado: producción `#2e9e5b`, preparación `#d99000`, parada `#d23b3b`, cerrada `#c3cad3`.
- Tras tocar servidor: `sudo systemctl restart oee-unificado-v2`.
- Acceso: `http://10.0.0.110:8091/scada.html` (no localhost desde Chrome remoto).

---

## File Structure

- **Modify `lib/ScadaMural.php`** — filtro a todas las activas; `estado_cat`, `seg_paro`, `paro_categoria` por máquina; bloque `contadores` en `mural()`.
- **Modify `oee_unificado_v2.html`** — botón SCADA → `window.open`.
- **Modify `scada.html`** — barra de menú, 3 densidades, orden/filtros/zoom, persistencia.

---

### Task 1: Backend — todas las activas + estado_cat + contadores

**Files:**
- Modify: `lib/ScadaMural.php`

**Interfaces:**
- Produces: cada máquina del JSON añade `estado_cat` (produccion/preparacion/parada/cerrada/otra) y `seg_paro` (int). `mural()` añade `contadores` {total,produccion,preparacion,parada,cerrada}.

- [ ] **Step 1: Cambiar el filtro base a todas las activas**

En `maquinasOperativas()` (la query base del mural), sustituir el WHERE:

```php
            WHERE cm.activo = 1
              AND cm.Cod_maquina <> '--'
            ORDER BY cm.Cod_maquina";
```
(Se quita `AND cm.Rt_Id_actividad >= 2 AND cm.Rt_Cod_of NOT IN ('','--')`.)

- [ ] **Step 2: Añadir clasificación de estado y contadores en `mural()`**

En `mural()`, dentro del `foreach ($filas as $r)`, calcular `estado_cat` y acumular
contadores. Añadir antes del `$out[] = [...]`:

```php
            $idAct = (int)$r['Rt_Id_actividad'];
            $idParo = (int)$r['Rt_Id_paro'];
            if     ($idAct === 1)                 $estadoCat = 'cerrada';
            elseif ($idParo > 0)                  $estadoCat = 'parada';
            elseif (in_array($idAct, [2,20], true)) $estadoCat = 'produccion';
            elseif (in_array($idAct, [3,5],  true)) $estadoCat = 'preparacion';
            else                                  $estadoCat = 'otra';
```

Y en el array de la máquina añadir `'estado_cat' => $estadoCat,` y
`'seg_paro' => self::segDesde($r['Rt_Hora_inicio_paro'], $ahora),` (junto a `paro`).

Antes del `return`, construir contadores:
```php
        $contadores = ['total'=>count($out),'produccion'=>0,'preparacion'=>0,'parada'=>0,'cerrada'=>0];
        foreach ($out as $m) { $k=$m['estado_cat']; if(isset($contadores[$k])) $contadores[$k]++; }
        return ['ahora' => $ahora, 'maquinas' => $out, 'contadores' => $contadores];
```

- [ ] **Step 3: Verificar**

Run: `php -l lib/ScadaMural.php`
Run: `curl -s "http://127.0.0.1:8091/api/scada_mural.php" | python3 -c "import sys,json;d=json.load(sys.stdin)['data'];print('n=',len(d['maquinas']),'cont=',d['contadores']);print('estados=',{m['estado_cat'] for m in d['maquinas']})"`
Expected: ~21 máquinas; contadores con producción/parada/cerrada; aparece 'cerrada' en estados.

- [ ] **Step 4: Commit**

```bash
git add lib/ScadaMural.php
git commit -m "feat(scada): endpoint trae todas las activas con estado_cat + contadores"
```

---

### Task 2: Backend — categoría de paro (nivel Matriz2)

**Files:**
- Modify: `lib/ScadaMural.php`

**Interfaces:**
- Consumes: `Db::pgFetchAll` (requiere `require_once .../lib/Db.php`).
- Produces: cada máquina añade `paro_categoria` (string; '' si no aplica).

- [ ] **Step 1: Cargar el mapa cod_paro→categoría una vez**

En `mural()`, tras obtener `$filas` y antes del foreach, cargar el mapa (con
try/catch para que un fallo de PostgreSQL no rompa el mural):

```php
        // Categoría de paro (nivel Matriz2) desde PostgreSQL. Clave por descripción
        // de paro en mayúsculas (Rt_Desc_paro). Si PG falla, categorías vacías.
        $catParo = [];
        try {
            require_once __DIR__ . '/Db.php';
            foreach (Db::pgFetchAll('SELECT cod_paro, tipo_paro_1 FROM cfg_paro_categoria') as $c) {
                $catParo[strtoupper(trim((string)$c['cod_paro']))] = (string)$c['tipo_paro_1'];
            }
        } catch (\Throwable $e) { /* sin categorías */ }
```

- [ ] **Step 2: Asignar `paro_categoria` por máquina**

Dentro del foreach, tras calcular `$estadoCat`:
```php
            $motivoParo = trim((string)$r['Rt_Desc_paro']);
            $paroCategoria = ($idParo > 0) ? ($catParo[strtoupper($motivoParo)] ?? '') : '';
```
Y añadir `'paro_categoria' => $paroCategoria,` al array de la máquina.

- [ ] **Step 3: Verificar**

Run: `php -l lib/ScadaMural.php`
Run: `curl -s "http://127.0.0.1:8091/api/scada_mural.php" | python3 -c "import sys,json;d=json.load(sys.stdin)['data']['maquinas'];[print(m['cod_maquina'],m['estado_cat'],'|',m.get('paro_categoria')) for m in d if m['estado_cat']=='parada']"`
Expected: las máquinas paradas muestran su categoría (Interrupciones, Ajustes y Programacion, Esperas…).

- [ ] **Step 4: Commit**

```bash
git add lib/ScadaMural.php
git commit -m "feat(scada): categoría de paro (nivel Matriz2) por máquina"
```

---

### Task 3: Frontend — barra de menú (contadores + densidad + zoom/fuente)

**Files:**
- Modify: `scada.html`

**Interfaces:**
- Consumes: `data.contadores`, `estado_cat`.
- Produces (JS): `State` global con {densidad, orden, zoom, fz, soloProblemas, filtroEstado, ocultas[]}; `aplicarMenu()` que filtra+ordena+pinta.

- [ ] **Step 1: Añadir la barra de menú al HTML**

Tras el `<header class="barra">`, insertar:

```html
<div class="menu-scada">
  <div class="ms-fila">
    <div class="ms-cont" id="msContadores"></div>
    <div class="ms-dens">
      <button data-d="comoda">Cómoda</button>
      <button data-d="normal" class="on">Normal</button>
      <button data-d="compacta">Compacta</button>
    </div>
    <div class="ms-zoom">
      <button data-z="-">−</button><span id="msZoomV">100%</span><button data-z="+">+</button>
      <b>A</b><button data-f="-">−</button><span id="msFzV">100%</span><button data-f="+">+</button>
    </div>
  </div>
  <div class="ms-fila">
    <button class="ms-tog" id="msProblemas">● Solo problemas <b id="msProbN">0</b></button>
    <button class="ms-tog" id="msOcultas">👁 Ocultas <b id="msOcN">0</b></button>
    <div class="ms-orden" id="msOrden">
      <span class="ms-orden-lbl">ORDEN</span>
      <button data-o="urgencia">Urgencia</button>
      <button data-o="oee">OEE bajo primero</button>
      <button data-o="restante">Tiempo restante OF</button>
      <button data-o="prep">Preparación primero</button>
      <button data-o="prod" class="on">Producción primero</button>
      <button data-o="codigo">Código A-Z</button>
      <button data-o="motivo">Motivo de paro</button>
      <button data-o="finest">FIN EST. OF</button>
      <button data-o="tiempoparo">Tiempo de paro</button>
    </div>
  </div>
</div>
```

- [ ] **Step 2: CSS de la barra**

En el `<style>`, añadir (paleta KH, chips):
```css
  .menu-scada{background:#fff;border-bottom:1px solid var(--linea);padding:8px 16px;display:flex;flex-direction:column;gap:8px}
  .ms-fila{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .ms-cont{display:flex;gap:8px}
  .ms-cont .c{border:1px solid var(--linea);border-radius:16px;padding:4px 10px;font-size:12px;cursor:pointer;font-weight:700}
  .ms-cont .c.on{background:#8c181a;color:#fff;border-color:#8c181a}
  .ms-cont .c b{font-size:14px;margin-right:4px}
  .ms-dens{margin-left:auto;display:flex;gap:4px}
  .ms-dens button,.ms-orden button,.ms-tog{font-size:12px;font-weight:600;padding:5px 10px;border:1px solid var(--linea);border-radius:6px;background:#fff;cursor:pointer;color:#374151}
  .ms-dens button.on,.ms-orden button.on,.ms-tog.on{background:#8c181a;border-color:#8c181a;color:#fff}
  .ms-zoom{display:flex;align-items:center;gap:4px;font-size:12px}
  .ms-zoom button{width:24px;height:24px;border:1px solid var(--linea);border-radius:4px;background:#fff;cursor:pointer}
  .ms-orden{display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin-left:auto}
  .ms-orden-lbl{font-size:10px;color:var(--gris);font-weight:800;margin-right:4px}
```

- [ ] **Step 3: Estado y render de contadores**

Al principio del `<script>`, definir `State` y helpers:
```javascript
const PREF_KEY='scada_prefs';
const State=Object.assign({densidad:'normal',orden:'prod',zoom:100,fz:100,soloProblemas:false,filtroEstado:null,ocultas:[]},
  JSON.parse(localStorage.getItem(PREF_KEY)||'{}'));
function guardarPrefs(){localStorage.setItem(PREF_KEY,JSON.stringify(State));}

function renderContadores(c){
  const defs=[['total','Máquinas',null],['produccion','Producción','#2e9e5b'],['preparacion','Preparación','#3b7fd2'],['parada','Paradas','#d23b3b'],['cerrada','Cerradas','#6b7280']];
  const est={total:null,produccion:'produccion',preparacion:'preparacion',parada:'parada',cerrada:'cerrada'};
  document.getElementById('msContadores').innerHTML=defs.map(([k,lbl,col])=>
    `<span class="c ${State.filtroEstado===est[k]?'on':''}" data-est="${est[k]||''}"><b style="${col?'color:'+col:''}">${c[k]||0}</b>${lbl}</span>`).join('');
  document.querySelectorAll('.ms-cont .c').forEach(el=>el.onclick=()=>{
    const e=el.dataset.est||null; State.filtroEstado=(State.filtroEstado===e?null:e); guardarPrefs(); aplicarMenu();
  });
}
```

- [ ] **Step 4: Wire densidad + zoom + fuente**

```javascript
function wireMenu(){
  document.querySelectorAll('.ms-dens button').forEach(b=>b.onclick=()=>{State.densidad=b.dataset.d;guardarPrefs();aplicarMenu();});
  document.querySelectorAll('.ms-orden button').forEach(b=>b.onclick=()=>{State.orden=b.dataset.o;guardarPrefs();aplicarMenu();});
  document.getElementById('msProblemas').onclick=function(){State.soloProblemas=!State.soloProblemas;guardarPrefs();aplicarMenu();};
  document.querySelectorAll('[data-z]').forEach(b=>b.onclick=()=>{State.zoom=Math.max(50,Math.min(150,State.zoom+(b.dataset.z==='+'?10:-10)));guardarPrefs();aplicarZoom();});
  document.querySelectorAll('[data-f]').forEach(b=>b.onclick=()=>{State.fz=Math.max(70,Math.min(160,State.fz+(b.dataset.f==='+'?10:-10)));guardarPrefs();aplicarZoom();});
}
function aplicarZoom(){
  const m=document.getElementById('mural');
  m.style.zoom=State.zoom/100; m.style.setProperty('--fz',State.fz/100);
  document.getElementById('msZoomV').textContent=State.zoom+'%';
  document.getElementById('msFzV').textContent=State.fz+'%';
}
```

- [ ] **Step 5: Verificar (con aplicarMenu de Task 4)**

Este task depende de `aplicarMenu()` (Task 4). Tras implementar Task 4, verificar
en navegador que los contadores aparecen y el clic marca/filtra, la densidad
cambia clase y zoom/fuente ajustan. (Commit conjunto al final de Task 4.)

---

### Task 4: Frontend — filtrado, ordenación y densidades de render

**Files:**
- Modify: `scada.html`

**Interfaces:**
- Consumes: `State`, campos de máquina (estado_cat, seg_paro, paro_categoria, turno_kpi.oee, of_kpi.fin_est/restante, cod_maquina).
- Produces (JS): `aplicarMenu()`, `tarjetaCompacta(m)`, `ordenar(arr)`, `parseMin(txt)`.

- [ ] **Step 1: Función de orden**

```javascript
// minutos desde un texto "Xd Yh" / "Yh" / "Zm" / "—" → número grande si no parseable
function parseMin(t){
  if(!t||t==='—'||t==='Completada') return Infinity;
  let m=0; const d=/(\d+)d/.exec(t),h=/(\d+)h/.exec(t),mi=/(\d+)m/.exec(t);
  if(d)m+=+d[1]*1440; if(h)m+=+h[1]*60; if(mi)m+=+mi[1]; return m||Infinity;
}
// fecha "dd/mm HH:MM" → timestamp comparable; Completada/— al final
function parseFin(t){
  if(!t||t==='—'||t==='Completada') return Infinity;
  const m=/(\d{2})\/(\d{2}) (\d{2}):(\d{2})/.exec(t); if(!m) return Infinity;
  return (+m[2])*1e6+(+m[1])*1e4+(+m[3])*100+(+m[4]);
}
// Categorías reales (cfg_paro_categoria), ordenadas por criticidad. Las no listadas van al final.
const ORDEN_CAT=['Mantenimiento','Calidad','Cambio MP/Utillajes','Ajustes y Programacion','Esperas','Interrupciones','Mejoras'];
function ordenar(arr){
  const o=State.orden, byCod=(a,b)=>a.cod_maquina.localeCompare(b.cod_maquina);
  const cp={parada:0,preparacion:1,produccion:2,otra:3,cerrada:4};
  const A=arr.slice();
  if(o==='codigo') A.sort(byCod);
  else if(o==='oee') A.sort((a,b)=>(a.turno_kpi.oee)-(b.turno_kpi.oee)||byCod(a,b));
  else if(o==='urgencia') A.sort((a,b)=>(cp[a.estado_cat]-cp[b.estado_cat])||(a.turno_kpi.oee-b.turno_kpi.oee));
  else if(o==='prep') A.sort((a,b)=>((a.estado_cat==='preparacion'?0:1)-(b.estado_cat==='preparacion'?0:1))||byCod(a,b));
  else if(o==='prod') A.sort((a,b)=>((a.estado_cat==='produccion'?0:1)-(b.estado_cat==='produccion'?0:1))||byCod(a,b));
  else if(o==='restante') A.sort((a,b)=>parseMin(a.of_kpi.restante)-parseMin(b.of_kpi.restante));
  else if(o==='finest') A.sort((a,b)=>parseFin(a.of_kpi.fin_est)-parseFin(b.of_kpi.fin_est));
  else if(o==='tiempoparo') A.sort((a,b)=>(b.seg_paro||0)-(a.seg_paro||0));
  else if(o==='motivo') A.sort((a,b)=>{
    const ia=a.paro_categoria?ORDEN_CAT.indexOf(a.paro_categoria):99, ib=b.paro_categoria?ORDEN_CAT.indexOf(b.paro_categoria):99;
    return (ia<0?98:ia)-(ib<0?98:ib)||byCod(a,b);
  });
  document.querySelectorAll('.ms-orden button').forEach(b=>b.classList.toggle('on',b.dataset.o===o));
  return A;
}
```

- [ ] **Step 2: `aplicarMenu()` — filtra, ordena y pinta**

```javascript
function aplicarMenu(){
  let ms=(window._ultimoMural||[]).slice();
  // ocultas
  ms=ms.filter(m=>!State.ocultas.includes(m.cod_maquina));
  // filtro por estado (contador)
  if(State.filtroEstado) ms=ms.filter(m=>m.estado_cat===State.filtroEstado);
  // solo problemas: parada u OEE turno < 50
  if(State.soloProblemas) ms=ms.filter(m=>m.estado_cat==='parada'||(m.turno_kpi.oee||0)<50);
  ms=ordenar(ms);
  // marcar botones densidad/problemas
  document.querySelectorAll('.ms-dens button').forEach(b=>b.classList.toggle('on',b.dataset.d===State.densidad));
  document.getElementById('msProblemas').classList.toggle('on',State.soloProblemas);
  document.getElementById('msProbN').textContent=(window._ultimoMural||[]).filter(m=>m.estado_cat==='parada'||(m.turno_kpi.oee||0)<50).length;
  document.getElementById('msOcN').textContent=State.ocultas.length;
  // densidad → clase del mural + render
  const mural=document.getElementById('mural');
  mural.className=''; mural.classList.add('dens-'+State.densidad);
  const render=State.densidad==='compacta'?tarjetaCompacta:tarjeta;
  mural.innerHTML=ms.length?ms.map(render).join(''):'<div class="vacio">Sin máquinas para este filtro.</div>';
}
```

- [ ] **Step 3: `tarjetaCompacta(m)` (densidad compacta)**

```javascript
function tarjetaCompacta(m){
  const col={produccion:'#2e9e5b',preparacion:'#d99000',parada:'#d23b3b',cerrada:'#c3cad3',otra:'#6b7280'}[m.estado_cat]||'#6b7280';
  return `<div class="tarjeta compacta" data-cod="${m.cod_maquina}" style="cursor:pointer;border-top:4px solid ${col}">
    <div class="cab"><div><div class="nom">${m.desc_maquina}</div><div class="cod">${m.cod_maquina}</div></div></div>
    <div class="c-ref"><div class="et">REFERENCIA</div>${fmt(m.producto)}</div>
    <div class="c-est"><div class="et">ESTADO</div><span style="color:${col};font-weight:800">● ${m.estado}</span></div>
  </div>`;
}
```
CSS mínimo:
```css
  #mural.dens-compacta{grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}
  .tarjeta.compacta{gap:6px}
  .tarjeta.compacta .c-ref,.tarjeta.compacta .c-est{font-size:12px}
  .tarjeta.compacta .et{font-size:9px;color:var(--gris);font-weight:800;letter-spacing:.4px}
  #mural.dens-comoda{grid-template-columns:repeat(auto-fill,minmax(440px,1fr))}
  #mural{--fz:1}
  .tarjeta .nom{font-size:calc(20px*var(--fz))}
```

- [ ] **Step 4: Banda de paro con categoría + color (densidad Normal/Cómoda)**

En `tarjeta(m)`, donde hoy se pinta la banda de paro (`enParo`), anteponer la
categoría y colorear. Localizar el bloque `bandaParo` y cambiarlo por:
```javascript
  const catCol={ 'Mantenimiento':'#8c181a','Calidad':'#b5179e','Cambio MP/Utillajes':'#d99000','Ajustes y Programacion':'#3b7fd2','Esperas':'#6b7280','Interrupciones':'#c9a227','Mejoras':'#2e9e5b' };
  const cCol=catCol[m.paro_categoria]||'#d99000';
  const bandaParo = enParo ? `
    <div class="banda-paro" style="background:${cCol}22;border-left:4px solid ${cCol}">
      <span class="tag" style="border-color:${cCol};color:${cCol}">EN PARO</span>
      ${m.paro_categoria?`<b style="color:${cCol}">${m.paro_categoria}</b> · `:''}${m.paro.motivo}
      <span class="t">${dur(m.paro.seg)}</span></div>` : '';
```

- [ ] **Step 5: Enganchar todo en `cargar()` + init**

En `pinta(data)` (o donde se recibe el mural), en vez de pintar directo, guardar y
delegar en `aplicarMenu`:
```javascript
function pinta(data){
  window._ultimoMural=data.maquinas;
  if(data.contadores) renderContadores(data.contadores);
  aplicarMenu();
}
```
En el arranque (antes de `cargar()`), llamar `wireMenu(); aplicarZoom();` y ajustar
los botones a `State` (densidad/orden). El listener de clic en tarjeta (delegado en
`#mural`) ya existe y sirve para las 3 densidades.

- [ ] **Step 6: Verificar en navegador (todo junto)**

Run: `sudo systemctl restart oee-unificado-v2` (opcional HTML).
Navegar a `http://10.0.0.110:8091/scada.html`:
- Contadores correctos; clic filtra por estado.
- Densidad Compacta → mini-tarjetas; Normal/Cómoda → completas; clic abre modal en las 3.
- Cada orden reordena (probar OEE bajo, Tiempo de paro, Motivo, FIN EST.).
- "Solo problemas" y "Ocultas" filtran; zoom/fuente ajustan; recargar mantiene prefs.
- Banda de paro muestra categoría + color.

- [ ] **Step 7: Commit (Tasks 3+4)**

```bash
git add scada.html
git commit -m "feat(scada): barra de menú (contadores, densidad, zoom, filtros, orden) + densidad compacta"
```

---

### Task 5: Botón de ocultar por tarjeta + ventana nueva

**Files:**
- Modify: `scada.html`
- Modify: `oee_unificado_v2.html`

- [ ] **Step 1: Icono ocultar en la tarjeta**

En `tarjeta(m)` y `tarjetaCompacta(m)`, añadir en la cabecera un botón:
```html
<button class="t-ocultar" data-ocultar="${m.cod_maquina}" title="Ocultar" onclick="event.stopPropagation()">✕</button>
```
CSS: `.t-ocultar{margin-left:auto;background:none;border:none;color:var(--gris);cursor:pointer;font-size:14px}`

Listener (delegado, junto al de clic de tarjeta):
```javascript
document.getElementById('mural').addEventListener('click',e=>{
  const oc=e.target.closest('[data-ocultar]');
  if(oc){ e.stopPropagation(); const c=oc.dataset.ocultar;
    if(!State.ocultas.includes(c)) State.ocultas.push(c); guardarPrefs(); aplicarMenu(); return; }
  const t=e.target.closest('.tarjeta'); if(!t) return;
  const m=(window._ultimoMural||[]).find(x=>x.cod_maquina===t.dataset.cod); if(m) abrirModal(m);
});
```
Y el botón "Ocultas" del menú, al clicar, restaura todas:
```javascript
document.getElementById('msOcultas').onclick=()=>{ State.ocultas=[]; guardarPrefs(); aplicarMenu(); };
```

- [ ] **Step 2: Ventana nueva desde oee_unificado_v2**

En `oee_unificado_v2.html`, el enlace `<a href="scada.html" ...>SCADA en vivo →</a>`
cambiarlo por un botón que abre pestaña nueva:
```html
<button class="btn" style="margin-left:auto;background:#8c181a;color:#fff;font-weight:700;padding:8px 14px;border-radius:8px"
  onclick="window.open('scada.html','_blank')">SCADA en vivo →</button>
```

- [ ] **Step 3: Verificar**

- En oee_unificado_v2: clic en "SCADA en vivo" abre pestaña nueva.
- En scada: ✕ oculta una máquina (baja el contador visible), "Ocultas" la restaura;
  persiste al recargar.

- [ ] **Step 4: Commit**

```bash
git add scada.html oee_unificado_v2.html
git commit -m "feat(scada): ocultar máquinas por tarjeta + abrir SCADA en pestaña nueva"
```

---

## Notas de verificación

- No romper el modal (RESUMEN/PAROS/OFS): sigue abriéndose con clic en tarjeta en
  las 3 densidades; los campos nuevos son aditivos.
- El auto-refresco (12s) llama a `pinta()` → `aplicarMenu()`, que re-aplica el estado
  del menú sin cerrar el modal (overlay independiente).
- Visual por `http://10.0.0.110:8091/scada.html` (no localhost); screenshot.
- Tras cambios de servidor: `sudo systemctl restart oee-unificado-v2`.
- Órdenes con dato verificado: OEE, tiempo de paro (seg_paro), motivo (paro_categoria),
  FIN EST. (of_kpi.fin_est), restante (of_kpi.restante).
