# Drill-down por Sección en Rendimiento Global

**Fecha:** 2026-04-28
**Vista afectada:** `views/rendimiento_global.php`
**Estado:** aprobado para planificación

## Objetivo

Permitir, desde el gráfico "Por Sección" de la vista *Rendimiento · Global + Sección*, hacer clic sobre la barra de **VARILLAS** o **TROQUELADOS** y ver dos gráficos adicionales que descomponen ese rendimiento por:

- **Artículo** (una barra por `Cod_producto` de la sección).
- **Máquina** (una barra por `WorkGroup` de la sección).

El propósito es ofrecer un drill-down de diagnóstico análogo al patrón ya existente en la vista cuando se selecciona una máquina y se ve el rendimiento por artículo.

## Alcance

**Sí incluye:**

- Modificar `views/rendimiento_global.php` para añadir el bloque drill-down.
- Modificar `assets/js/view_rendimiento_global.js` para añadir interactividad y los nuevos gráficos.
- Crear nuevo endpoint `api/rendimiento_seccion_detalle.php`.
- Añadir reglas CSS para el bloque drill-down en `assets/css/style.css`.

**No incluye:**

- Cambios en `api/rendimiento_global.php` (queda intacto).
- Cambios en otras vistas (`por_seccion.php`, `por_maquina.php`, `plan_attainment.php`, `grid.php`, etc.).
- Cambios en la lógica del gauge o de los selectores existentes.
- Funcionalidad de exportación de los nuevos gráficos.

## Comportamiento detallado

### Estado inicial

El usuario abre `rendimiento_global.php`. La vista se comporta exactamente como hoy:

- Selectores de máquina y artículo.
- Gauge global de rendimiento.
- Gráfico horizontal "Por Sección" con dos barras (VARILLAS, TROQUELADOS).

El bloque de drill-down está oculto.

### Apertura del drill-down

Al hacer clic sobre una barra del gráfico "Por Sección":

1. Se establece el estado interno `_seccionDrillDown = 'VARILLAS'` (o `'TROQUELADOS'`).
2. La barra clickeada se resalta visualmente: se le aplica un `stroke` más grueso y oscuro (borde de 3 px en color `#1a2d4a`); las barras no seleccionadas mantienen su aspecto actual.
3. Aparece debajo del gráfico de secciones un bloque `#drill-down-block` con:
   - Pequeño encabezado: `Desglose · {SECCION}` y un botón `× Cerrar desglose` alineado a la derecha.
   - Dos contenedores en grid 2 columnas (50/50, con `gap` razonable):
     - Izquierda: `#chart-articulos-seccion` — gráfico horizontal de barras, una por artículo.
     - Derecha: `#chart-maquinas-seccion` — gráfico horizontal de barras, una por máquina.
4. Se llama al nuevo endpoint `api/rendimiento_seccion_detalle.php` con `fecha`, `turno`, `seccion` y, si están activos, `cod_maquina` / `cod_articulo` (los filtros existentes se respetan en el detalle).
5. Mientras se carga, se muestra el loader global existente.

### Datos mostrados

Para cada artículo y cada máquina de la sección, se calcula el mismo rendimiento que el gauge:

```
R = (M_OKNOK_TEO + PCALIDAD) / (M + PPERF + PCALIDAD) × 100
```

Las barras se ordenan **alfabéticamente** por código (`cod_articulo` para artículos, `Desc_maquina` o `cod_maquina` para máquinas), no por rendimiento.

No hay límite en el número de barras de artículos: la altura de cada gráfico se ajusta dinámicamente al número de barras (por ejemplo, 26 px por barra + márgenes; el bloque se adapta a la altura mayor de los dos).

### Interacción con las nuevas barras

- **Clic sobre barra de artículo:** se actualiza `_selCodArticulo`, se sincroniza el `<select id="article-selector">`, se actualiza la URL (`updateUrlParams`), y se recarga la vista (`cargarVista`). El drill-down permanece abierto y se vuelve a cargar para reflejar el filtro.
- **Clic sobre barra de máquina:** análogo con `_selCodMaquina` y `<select id="machine-selector">`.

### Cierre del drill-down

Cualquiera de estas acciones cierra el bloque y limpia el resaltado:

- Clic sobre la **misma** barra de sección que está actualmente activa.
- Clic sobre el botón `× Cerrar desglose`.

El cierre **no** modifica los selectores ni los filtros activos.

### Cambio de sección

Si está abierto el drill-down de VARILLAS y el usuario hace clic sobre TROQUELADOS, se cambia el estado a `'TROQUELADOS'`, se actualiza el resaltado y se vuelve a cargar el contenido del drill-down con los datos de la nueva sección.

### Recarga de la vista

Cuando `cargarVista()` se reejecuta (por cambio de fecha/turno/selectores), si `_seccionDrillDown` está activo el bloque drill-down debe refrescarse también con los datos actuales.

## Componentes y cambios

### 1. Vista `views/rendimiento_global.php`

Después del bloque `oee-fab-global-grid` (cierre del `</div>` que contiene gauge + secciones), añadir antes del cierre del `view-card-body`:

```html
<div id="drill-down-block" class="drill-down-block" style="display:none">
    <div class="drill-down-header">
        <span class="drill-down-title">Desglose · <span id="drill-down-seccion-label">—</span></span>
        <button id="drill-down-close" class="drill-down-close" type="button">× Cerrar desglose</button>
    </div>
    <div class="drill-down-grid">
        <div class="drill-down-col">
            <div class="oee-detalle-subtitle">Artículos</div>
            <div id="chart-articulos-seccion"></div>
        </div>
        <div class="drill-down-col">
            <div class="oee-detalle-subtitle">Máquinas</div>
            <div id="chart-maquinas-seccion"></div>
        </div>
    </div>
</div>
```

No se modifica nada más en la vista.

### 2. JavaScript `assets/js/view_rendimiento_global.js`

Cambios:

- Añadir variables a nivel de módulo:
  ```js
  let chartArticulosSeccion = null;
  let chartMaquinasSeccion  = null;
  let _seccionDrillDown     = null; // 'VARILLAS' | 'TROQUELADOS' | null
  ```
- En `renderSecciones`, añadir:
  - `chart.events.dataPointSelection` que dispara `toggleDrillDown(seccion)`.
  - Lógica para resaltar la barra activa según `_seccionDrillDown` (`stroke` 3 px `#1a2d4a` en la barra activa, 0 px en las demás, vía `plotOptions.bar.colors.ranges` o más simple: en la propiedad `stroke` del config a nivel serie con un array por barra).
- Nueva función `toggleDrillDown(seccion)`:
  - Si `_seccionDrillDown === seccion`, cerrarla (estado a `null`, ocultar `#drill-down-block`, re-render del gráfico de secciones para quitar resalte).
  - Si no, abrirla: estado a `seccion`, mostrar `#drill-down-block`, llamar a `cargarDrillDown()`, re-render del gráfico de secciones.
- Nueva función `cargarDrillDown()`:
  - Llama a `apiFetch('rendimiento_seccion_detalle.php', { fecha, turno, seccion, cod_maquina?, cod_articulo? })`.
  - Llama a `renderDrillDownArticulos(d.articulos)` y `renderDrillDownMaquinas(d.maquinas)`.
  - Actualiza `#drill-down-seccion-label` con el nombre de la sección.
- `renderDrillDownArticulos(arr)` y `renderDrillDownMaquinas(arr)`:
  - Gráficos ApexCharts horizontales con la misma estética que `renderSecciones`.
  - `categories` ordenadas alfabéticamente (el backend ya las devuelve ordenadas; el front no reordena).
  - Tooltip con minutos en marcha (M_min) y PPERF_min, y % de rendimiento con 1 decimal.
  - `chart.events.dataPointSelection` que dispara filtro:
    - Para artículos: `_selCodArticulo = cod_articulo; sincroniza select; updateUrlParams; cargarVista()`.
    - Para máquinas: `_selCodMaquina = cod_maquina; sincroniza select; updateUrlParams; cargarVista()`.
  - Altura calculada como `Math.max(180, 26 * data.length + 80)` para que escale.
- En `cargarVista()`, después de `renderSecciones`, si `_seccionDrillDown` está activo, llamar a `cargarDrillDown()` para que el desglose refleje los filtros actuales.
- Listener de clic para `#drill-down-close` que llama a `toggleDrillDown(_seccionDrillDown)` (cierre).

### 3. Endpoint nuevo `api/rendimiento_seccion_detalle.php`

Parámetros:

- `fecha` (requerido, formato `YYYY-MM-DD`).
- `turno` (opcional, `M`/`T`/`N`).
- `seccion` (requerido, `VARILLAS` o `TROQUELADOS`).
- `cod_maquina` (opcional).
- `cod_articulo` (opcional).

Lógica:

1. Validar `fecha` y que `seccion ∈ {VARILLAS, TROQUELADOS}`.
2. Construir el WHERE común igual que `api/rendimiento_global.php` (incluyendo el blacklist `WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')`).
3. Aplicar `cod_maquina` y `cod_articulo` si vienen.
4. Hacer dos consultas a `F_his_ct('WORKCENTER','DAY','TURNOS, PRODUCTOS', ...)` con el join a `cfg_maquina`:
   - **Por artículo:** `GROUP BY oee.Cod_producto, MAX(Desc_producto)`. Aplicar el filtro de sección en PHP usando `PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[Desc_maquina] === seccion`. Solo mantener filas con `M + PPERF + PCALIDAD > 0` y `Cod_producto IS NOT NULL` y `<> '--'`. Calcular rendimiento.
   - **Por máquina:** `GROUP BY oee.WorkGroup, mq.Desc_maquina`. Filtrar por sección igual. Calcular rendimiento.
5. Ordenar ambos arrays alfabéticamente:
   - artículos por `cod_articulo`,
   - máquinas por `maquina` (caída a `cod_maquina` si nula).
6. Devolver:
   ```json
   {
     "fecha": "...", "turno": "...", "seccion": "...",
     "cod_maquina": "...", "cod_articulo": "...",
     "articulos": [
       {"cod_articulo": "...", "desc_articulo": "...",
        "rendimiento": 87.4, "M_min": 1234, "PPERF_min": 56}
     ],
     "maquinas":  [
       {"cod_maquina": "...", "maquina": "...",
        "rendimiento": 91.2, "M_min": 2345, "PPERF_min": 78}
     ]
   }
   ```

Reutiliza `seccionDeDesc()` de `api/rendimiento_global.php` (se duplica la pequeña función o se mueve a `lib/PlanAttainmentAgg.php` como método estático). **Decisión preferida:** duplicar la función dentro del nuevo endpoint para no tocar la librería compartida y respetar el alcance.

### 4. CSS `assets/css/style.css`

Añadir un bloque al final del archivo con clases prefijadas `drill-down-*`:

- `.drill-down-block` — separador superior (margen + línea divisoria), padding interno.
- `.drill-down-header` — flex entre título y botón cerrar.
- `.drill-down-title` — tipografía consistente con `view-card` headers.
- `.drill-down-close` — botón discreto similar a `.machine-selector-clear`.
- `.drill-down-grid` — `display: grid; grid-template-columns: 1fr 1fr; gap: 24px`.
- `.drill-down-col` — contenedor de cada gráfico.
- Media query `@media (max-width: 900px)` → `grid-template-columns: 1fr` (apilado).

No se tocan reglas existentes (`.metric-legend*` y similares quedan intactas).

## Errores y casos límite

- **Sección sin máquinas/sin artículos** en el día/turno → endpoint devuelve arrays vacíos; el front muestra el contenedor vacío con un mensaje "Sin datos para esta sección".
- **Filtro de máquina activa que no pertenece a la sección clickeada** → el endpoint responderá con arrays vacíos (porque la intersección está vacía). Lo mostramos igual con "Sin datos para esta sección" — es información útil al usuario.
- **Rendimiento > 120%** → se cappea visualmente en el gráfico al igual que se hace en `renderGauge` y `renderSecciones`.
- **Error de API** → se usa `showToast('Error: ' + e.message, 'error')` igual que el resto del código; el bloque drill-down permanece visible pero vacío.

## Pruebas manuales

1. Abrir vista, clic en VARILLAS → bloque aparece con dos gráficos.
2. Clic en TROQUELADOS → contenido cambia.
3. Clic en VARILLAS otra vez → se cierra.
4. Botón "× Cerrar desglose" → se cierra.
5. Con drill-down abierto, clic en una barra de artículo → selector se actualiza, gauge se filtra, drill-down se mantiene.
6. Mismo con barra de máquina.
7. Cambiar fecha/turno con drill-down abierto → ambos gráficos se refrescan.
8. Sección sin datos en el día → mensaje "Sin datos".
9. Mobile (< 900 px) → gráficos apilados.

## Memorias de proyecto

Las memorias `frozen` actuales protegen `por_seccion.php`, `plan_attainment.php` y `grid.php`. Esta especificación **no toca esos archivos**. Tras la implementación, considerar añadir una memoria `feedback_rendimiento_global_frozen.md` si el usuario quiere proteger esta vista también.
