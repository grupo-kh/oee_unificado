# Plan Attainment · Módulos Máquinas + Detalle por Artículo

Fecha: 2026-05-26
Vista afectada: `views/plan_attainment.php`

## Objetivo

Ampliar la vista unificada de Plan Attainment con dos módulos nuevos al final del panel:

- **Módulo 4 · Máquinas** — ranking horizontal de máquinas por % de Plan Attainment, integrado al cross-filter existente (sección + fecha).
- **Módulo 5 · Detalle Plan vs Producido** — tabla artículo-a-artículo de la máquina seleccionada, visible solo cuando hay máquina activa.

Adicionalmente se amplía la altura de los módulos 2 (Por Sección) y 3 (Evolución) en un +50% (360px → 540px) para dar más espacio visual al panel ampliado.

## Arquitectura

### Layout final

```
Módulo 1 · Gauge Global               (sin cambios)
Módulo 2 · Cumplimiento por Sección   (altura 360 → 540px)
Módulo 3 · Evolución 7 días           (altura 360 → 540px)
Módulo 4 · Ranking por Máquina        (NUEVO · barras horizontales)
Módulo 5 · Detalle Plan vs Producido  (NUEVO · solo si hay máquina seleccionada)
```

### Estado de cross-filter (JS local de la vista)

Se amplía el estado actual:

```js
let _selSeccion = '';   // existente
let _selFecha   = '';   // existente
let _selMaquina = '';   // NUEVO
```

### Reglas de propagación

| Acción del usuario | Gauge | Por Sección | Evolución | Máquinas | Detalle |
|---|---|---|---|---|---|
| Click en barra de sección | recarga | toggle visual | recarga | recarga (filtrado cliente) | limpiar y ocultar |
| Click en punto de evolución | recarga (fecha puntual) | recarga | sin cambio (ventana 7d) | recarga (fecha puntual) | limpiar y ocultar |
| Click en barra de máquina | sin cambio | sin cambio | sin cambio | toggle visual | recarga y mostrar |
| Cambio fecha/turno en header | recarga | recarga | recarga | recarga | limpiar y ocultar |
| Botón "Limpiar" | limpia todo | limpia | limpia | limpia | limpia y oculta |

**Decisión deliberada**: la selección de máquina **no** recarga gauge ni evolución. Solo el módulo 5 reacciona. Justificación: el API del gauge y la evolución no aceptan filtro por máquina hoy y abrirlo expandiría el scope (requiere tocar `plan_attainment.php`, `evolucion.php` y el helper de métricas OEE). El detalle por artículo es suficiente para responder la pregunta "qué pasó en esa máquina".

### Auto-descarte

Si tras una recarga la máquina seleccionada deja de aparecer en el ranking (porque cambió la sección o la fecha y esa máquina no tiene plan ni producción), se limpia `_selMaquina` silenciosamente y se oculta el módulo 5.

## Componentes

### 1. `api/por_maquina.php` (existente · sin cambios)

Ya existe y devuelve `rangeByMaquina(fechaDesde, fechaHasta, turnos)` con campos `maquina`, `seccion`, `plan_total`, `prod_total`, `attain`, `plan_attainment`.

- El filtro por sección lo aplica el cliente (cada fila incluye `seccion`).
- El filtro por fecha se hace a nivel de querystring (`fecha=...&turno=...`).

### 2. `api/por_articulo_maquina.php` (NUEVO)

Endpoint que devuelve el desglose plan-vs-producido por artículo para una máquina concreta.

**Input** (querystring):
- `fecha` — YYYY-MM-DD (single day, replica formato existente)
- `turno` — M / T / N / C / (vacío = todos)
- `maquina` — Desc_maquina (ej. "BUCH GRANDE", "TURBOBENDER")

**Salida** (`jsonOk`):
```json
{
  "rows": [
    {
      "cod_articulo": "...",
      "plan": 1000,
      "producido": 1200,
      "attain": 1000,
      "plan_attainment": 100.0
    }
  ],
  "totales": { "plan": ..., "prod": ..., "attain": ..., "pa": ... },
  "meta": { ... }
}
```

Filas ordenadas por `plan` descendente. Se incluyen artículos con `plan > 0` o `producido > 0` (artículos no planificados que aparecieron se incluyen también, con `attain=0`).

### 3. `lib/PlanAttainmentAgg.php` — nuevo método

```php
public static function rangeByMaquinaArticulo(
    string $fechaDesde,
    string $fechaHasta,
    array $turnos,
    string $maquinaName
): array
```

Itera por día × turno usando `dayShiftDetailExt()` (que ya devuelve plan y prod por clave `maquina|cod_articulo`). Suma plan/prod/attain por `cod_articulo` filtrando claves cuyo prefijo coincida con `$maquinaName`. Devuelve `[['cod_articulo', 'plan', 'prod', 'attain'], …]`.

### 4. `views/plan_attainment.php` — markup adicional

Añadir tras el módulo 3 actual:

```html
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

### 5. `assets/js/view_plan_attainment_full.js` — funciones nuevas

- `renderMaquinas(rows)` — ApexCharts horizontal bar, color semáforo, atenuación de no seleccionada, click handler.
- `renderDetalle(rows, totales)` — tabla HTML con barra mini en columna %.
- `onMaquinaClick(maq)` — toggle + `refreshActiveFilterBar()` + `cargarMaquinas()` + `cargarDetalle()` (no recarga gauge/sección/evolución).
- `cargarMaquinas()` — fetch `por_maquina.php` con `fecha=<fechaDia>&turno=<turno>` y filtra cliente por `_selSeccion` si aplica. Si tras el filtro `_selMaquina` no aparece en `rows`, auto-descarta.
- `cargarDetalle()` — si `_selMaquina === ''`, oculta módulo 5 y retorna. Si no, fetch `por_articulo_maquina.php?fecha=…&turno=…&maquina=…`, renderiza y muestra el módulo.
- Integración en `cargarTodo()`: añadir ambas a `Promise.all([...])`.
- `refreshActiveFilterBar()`: añadir chip "MÁQUINA · {nombre}" cuando `_selMaquina !== ''`.
- `onClearFilter()`: limpiar también `_selMaquina`.
- Listener de cambio de filtros del header (`initFiltros`): limpiar también `_selMaquina`.

### 6. `assets/css/style.css` — cambios

- `#chart-seccion-big` → `height: 540px` (era 360).
- `#chart-evolucion-big` → `height: 540px` (era 360).
- `#chart-maquina-big` → `width: 100%; min-height: 540px;`. La altura final del chart la fija el JS al construir ApexCharts (≈ 36px por fila × nº máquinas, con un mínimo de 540px).
- `.pa-module-4 .view-card-body { background: #f4f1fa; }` (tono lila suave).
- `.pa-module-4 .view-card-header { background: linear-gradient(180deg, #4a3b87 0%, #6e5cad 100%); }`
- `.pa-module-5 .view-card-body { background: #fbf4f4; }` (tono rosa suave).
- `.pa-module-5 .view-card-header { background: linear-gradient(180deg, #87413b 0%, #ad6e5c 100%); }`
- `.pa-detalle-table` — tabla con barra mini de %, footer en negrita.

## Flujo de datos

```
DOMContentLoaded
   └─ cargarTodo()
        ├─ cargarGauge()           [gauge + 4 OEE]
        ├─ cargarSeccion()         [bar chart 2 secciones]
        ├─ cargarEvolucion()       [line chart 7d]
        ├─ cargarMaquinas()        [horizontal bars]
        └─ cargarDetalle()         [tabla — solo si _selMaquina]

Click en barra de máquina
   └─ onMaquinaClick(maq)
        ├─ _selMaquina = (toggle)
        ├─ refreshActiveFilterBar()
        ├─ cargarMaquinas()        [re-render atenuando no-seleccionadas]
        └─ cargarDetalle()         [fetch + render + scroll suave a módulo 5]
```

## Manejo de errores

- Fallo de `por_maquina.php` → toast de error, módulo 4 muestra mensaje "Sin datos en el periodo".
- Fallo de `por_articulo_maquina.php` → módulo 5 muestra "Sin detalle disponible para esta máquina" en lugar de la tabla, sin tirar abajo el resto del panel.
- Si `cargarMaquinas` devuelve cero filas (sección sin máquinas, fecha sin plan), módulo 4 muestra "Sin máquinas en el periodo seleccionado" y módulo 5 queda oculto.
- Si `_selMaquina` apunta a una máquina que ya no está, se descarta sin avisar (UX: el usuario no necesita un error, solo ver el ranking actualizado).

## Testing manual

- Carga inicial: 5 módulos visibles excepto el 5 (oculto).
- Click en VARILLAS en módulo 2 → módulo 4 filtra a máquinas VARILLAS, gauge se filtra (comportamiento ya existente).
- Click en TURBOBENDER en módulo 4 → módulo 5 aparece con artículos. Gauge y evolución no cambian.
- Click en otra máquina → módulo 5 se recarga con la nueva.
- Click otra vez en TURBOBENDER (ya seleccionada) → toggle off, módulo 5 desaparece.
- Click en punto de evolución → módulo 4 se actualiza a ese día, si la máquina seleccionada sigue existiendo allí se mantiene, si no se descarta.
- Cambio de turno en header → todo limpio incluyendo `_selMaquina`.
- Botón "Limpiar" → vuelve a estado inicial.

## Out of scope

- No se filtra el gauge ni la evolución por máquina (decisión explícita, ver arriba).
- No se añade búsqueda/filtro de texto sobre el listado de máquinas (ranking razonable con 14–18 máquinas).
- No se modifican la vista standalone `views/por_maquina.php` ni `view_maquina.js` (siguen funcionando como hoy).
