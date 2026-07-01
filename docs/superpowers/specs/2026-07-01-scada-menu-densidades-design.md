# SCADA con menú, densidades y ordenación — Diseño

**Fecha:** 2026-07-01
**Proyecto:** oee_unificado (web "v2", systemd `oee-unificado-v2` en :8091)
**Rama:** feat/evolucion-motivos
**Referencia visual:** `PLAN_ATTAINMENT/load/MENU SCADA.PNG`, `SCADA COMPACTA.PNG`, `SCADA NORMAL.PNG`

## 1. Objetivo

Ampliar la ventana SCADA (`scada.html`) con: apertura en pestaña nueva, una barra
de menú (contadores por estado, densidad de vista, zoom/fuente, filtros y
ordenación) y tres densidades de tarjeta. El modal de detalle (RESUMEN/PAROS/OFS)
ya existente debe poder abrirse desde cualquier densidad.

## 2. Alcance (decisiones tomadas)

- **Ventana nueva:** el botón "SCADA en vivo" de oee_unificado_v2 usa
  `window.open('scada.html','_blank')`.
- **Universo de máquinas:** el endpoint pasa a devolver TODAS las activas
  (`activo=1`, excluyendo `--`), con estado real (incluidas CERRADAS).
- **Menú (todo en cliente, sobre datos ya cargados):**
  - Contadores clicables: Máquinas · Producción · Preparación · Paradas · Cerradas.
  - Densidad: Cómoda / Normal / Compacta.
  - Zoom (− %) y tamaño de fuente (A − +).
  - Solo problemas (toggle), Más filtros (sección/turno), Ocultas (manual + contador).
  - Ordenación: Urgencia · OEE bajo primero · Tiempo restante OF · Preparación
    primero · Producción primero · Código A-Z · Motivo de paro · FIN EST. OF
    (menor→mayor) · Tiempo de paro.
- **Densidades:** Compacta (mini), Normal (tarjeta actual), Cómoda (Normal grande).
- **Banda de paro:** muestra Categoría (nivel Matriz2) + motivo, con color por categoría.
- **Preferencias** (densidad, orden, zoom, fuente) persisten en `localStorage`.
- Fuera de alcance: exportación del mural, edición de datos.

## 3. Fundamento verificado

- **Contadores** (todas las activas, hoy): total 21, producción 13, paradas 2,
  cerradas 6 — clasificación por `Rt_Id_actividad` (1=CERRADA) y `Rt_Id_paro`>0.
- **FIN EST. OF** = el `fin_est` que YA calcula `ScadaMural` (inicio + plan/cadencia;
  puede ser fecha, "Completada" o "—"). Es el campo que el usuario llama "caducidad".
  (Los campos MAPEX `Fecha_entrega`/`FechaCaducidad` están sin poblar — no se usan.)
- **Tiempo de paro** = `Rt_Seg_paro` (segundos del paro actual) / `Rt_Hora_inicio_paro`.
- **Categoría/nivel de paro** = PostgreSQL `cfg_paro_categoria (cod_paro → tipo_paro_1)`
  (verificado: Interrupciones, Ajustes y Programacion, Calidad, Cambio MP/Utillajes,
  Esperas…). Misma fuente que Matriz 2 (`lib/Db.php::pgFetchAll`).

## 4. Arquitectura

- **`lib/ScadaMural.php`** — `mural()` cambia el filtro base a `activo=1` (todas),
  clasifica el estado de cada máquina y añade los campos para menú/orden.
- **`api/scada_mural.php`** — sin cambios estructurales (sigue devolviendo `mural()`).
- **`scada.html`** — barra de menú + 3 densidades + persistencia + banda de paro con
  categoría. Filtrado/orden/densidad/zoom en cliente. Auto-refresco respeta el menú.
- **`oee_unificado_v2.html`** — botón SCADA → `window.open('scada.html','_blank')`.

## 5. Cambios de datos (`ScadaMural::mural`)

### 5.1 Filtro base → todas las activas
`WHERE cm.activo = 1 AND cm.Cod_maquina <> '--'` (quita el `Rt_Id_actividad>=2`).

### 5.2 Estado clasificado por máquina (`estado_cat`)
```
Rt_Id_actividad == 1                → 'cerrada'
Rt_Id_paro > 0                      → 'parada'
Rt_Id_actividad in (2,20)           → 'produccion'
Rt_Id_actividad in (3,5)            → 'preparacion'
else                               → 'otra'
```

### 5.3 Categoría de paro (nivel Matriz2)
Cargar una vez el mapa `cod_paro → tipo_paro_1` desde PostgreSQL
(`Db::pgFetchAll('SELECT cod_paro, tipo_paro_1 FROM cfg_paro_categoria')`),
indexado por descripción/código de paro. Por máquina en paro:
`paro_categoria` = tipo_paro_1 del motivo actual (`Rt_Desc_paro` / `Rt_Id_paro`),
'' si no mapea.

### 5.4 Campos nuevos en cada objeto máquina del JSON
Además de los actuales (desc_maquina, estado, of, producto, turno_kpi, of_kpi, paro…):
- `estado_cat` (string)
- `seg_paro` (int, = paro.seg ya calculado)
- `paro_categoria` (string)
Y en `mural()` un bloque `contadores: {total, produccion, preparacion, parada, cerrada}`.

## 6. Menú (frontend)

### 6.1 Contadores
Fila de contadores desde `data.contadores`. Clic en uno → filtra el mural por ese
`estado_cat` (toggle). "Máquinas" = quitar filtro.

### 6.2 Densidad
Tres botones. Cambian la clase del contenedor `#mural` (`dens-comoda` /
`dens-normal` / `dens-compacta`) y la función de render de tarjeta:
- **Compacta:** `tarjetaCompacta(m)` — nombre, código, referencia, estado, borde color.
- **Normal:** `tarjeta(m)` — la actual.
- **Cómoda:** `tarjeta(m)` con clase `.comoda` (mismo HTML, CSS más grande).

### 6.3 Zoom y fuente
Zoom = `transform: scale()` o `zoom` sobre `#mural` (con % mostrado). Fuente =
variable CSS `--fz` que escala los textos. Botones −/+ ajustan y muestran el valor.

### 6.4 Filtros
- **Solo problemas** (toggle): deja solo máquinas con `estado_cat='parada'` o
  `turno_kpi.oee < UMBRAL` (UMBRAL configurable, p.ej. 50).
- **Más filtros:** desplegable con checkboxes de sección (según prefijo de OF/máquina)
  y turno. (Mínimo viable; ampliable.)
- **Ocultas:** botón que gestiona una lista de códigos ocultados por el usuario
  (persistida en localStorage) + contador; cada tarjeta tiene un icono "ocultar".

### 6.5 Ordenación
Botones que fijan `State.orden` y reordenan el array en cliente:
| Orden | Criterio |
|---|---|
| Urgencia | parada primero, luego OEE turno asc |
| OEE bajo primero | `turno_kpi.oee` asc |
| Tiempo restante OF | `of_kpi.restante` asc (parseado a minutos; '—'/Completada al final) |
| Preparación primero | `estado_cat='preparacion'` primero |
| Producción primero | `estado_cat='produccion'` primero |
| Código A-Z | `cod_maquina` asc |
| Motivo de paro | por `paro_categoria` (orden de jerarquía Matriz2); sin paro al final |
| FIN EST. OF | `of_kpi.fin_est` fecha asc; 'Completada'/'—' al final |
| Tiempo de paro | `seg_paro` desc (más parado primero) |

## 7. Densidad Compacta (nueva tarjeta)

`tarjetaCompacta(m)` réplica de `SCADA COMPACTA.png`: caja con borde de color por
estado (verde=producción, ámbar=preparación, rojo=parada, gris=cerrada), nombre
grande, código, "REFERENCIA" + producto, "ESTADO" + punto de color + texto.
Clicable → abre el modal (mismo `abrirModal(m)`).

## 8. Banda de paro con categoría + color

En `tarjeta` (Normal/Cómoda), cuando `paro.motivo`, la banda muestra
`paro_categoria · motivo` y se colorea según la categoría (paleta por categoría,
como Matriz2). Si no hay categoría, comportamiento actual.

## 9. Persistencia

`localStorage` guarda: densidad, orden, zoom, tamaño fuente, lista de ocultas,
"solo problemas". Se restauran al abrir. El auto-refresco (12s) vuelve a pedir
datos y re-aplica menú/orden/filtros sin perder el estado ni cerrar el modal.

## 10. Verificación

- Endpoint: `curl` → devuelve todas las activas (21) con `estado_cat`,
  `paro_categoria`, `seg_paro` y bloque `contadores`.
- Visual (`http://10.0.0.110:8091/scada.html`, no localhost):
  - Contadores correctos; clic filtra.
  - Densidad Compacta/Normal/Cómoda cambian la tarjeta; clic abre el modal en las 3.
  - Cada orden reordena de forma coherente (probar los 3 nuevos con datos reales).
  - Zoom/fuente ajustan; persisten al recargar.
  - Máquinas cerradas aparecen atenuadas; "Solo problemas"/"Ocultas" funcionan.
  - `window.open` desde oee_unificado_v2 abre pestaña nueva.
- Tras tocar servidor: `sudo systemctl restart oee-unificado-v2`.

## 11. Riesgos / notas

- Traer todas las activas (incl. cerradas) aumenta ligeramente el payload; sigue
  siendo una sola consulta. Las cerradas no tienen OF → sus KPI van a 0/—.
- El mapa `cfg_paro_categoria` se carga una vez por request (PostgreSQL local, rápido).
- Zoom con `transform: scale` puede requerir ajustar el ancho del contenedor para
  no cortar; alternativa `zoom` (soportado en Chromium, que es el navegador de planta).
- El modal existente no debe romperse: sigue recibiendo el objeto máquina; los
  campos nuevos son aditivos.
