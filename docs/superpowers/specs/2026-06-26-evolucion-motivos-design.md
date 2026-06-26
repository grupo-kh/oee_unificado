# Evolución de motivos de disponibilidad — Diseño

**Fecha:** 2026-06-26
**Estado:** Aprobado (pendiente de implementación)

## Objetivo

Nuevo formulario con un gráfico de **línea temporal** para ver la evolución de las
horas de paro de cada **motivo de disponibilidad** dentro de un intervalo de fechas,
con escala seleccionable (día / semana / mes). Permite:

1. Elegir intervalo de fechas (hereda los filtros del formulario principal).
2. Ver la lista de motivos de paro que surgen en ese intervalo (ordenados por peso).
3. Clic en un motivo → dibuja su línea temporal (un motivo a la vez).
4. Clic en un punto de ruptura (bucket) → popup con el reparto de esas horas por
   las máquinas implicadas en ese motivo y periodo.

## Decisiones tomadas (con el usuario)

| Decisión | Elección |
|----------|----------|
| Métrica del eje Y | **Horas de paro** (coherente con Matriz 2 y los drills de disponibilidad) |
| Vista del motivo | **Un motivo a la vez** (clic en la lista pinta solo su serie) |
| Filtros | **Sección + turnos**, heredados del formulario principal |
| Granularidad | **Selector manual** (Día / Semana / Mes), arranca en Día |
| Preselección al abrir | Motivo de **más peso** preseleccionado (evita gráfico vacío) |
| Export a Excel | **Fuera de alcance** (no solicitado) |

## Arquitectura

Backend = única fuente de verdad (como Matriz 2). Dos endpoints nuevos + un
formulario integrado en `oee_unificado_v2.html`. Reutiliza patrones existentes:

- Granularidad día/semana/mes + etiquetas + bucket SQL → `oee_unificado_evolucion.php`.
- Agregación de paros por `Desc_paro` con serie continua → `oee_unificado_maq_motivo_temporal.php`.
- Reparto por máquina de un motivo → `oee_unificado_motivo_drill.php`.
- Filtros de paro (excluye CERRADA cód. 11 + actividad 1) y sección → `oee_unificado_matriz2.php`.
- Shell de popup a pantalla completa (`fullpop`) y helper `this.apex(...)` (ApexCharts) → `oee_unificado_v2.html`.

### Endpoint 1 · `api/oee_unificado_motivos_evolucion.php`

Una sola llamada alimenta lista de motivos y gráfico.

**GET:** `fecha_desde`, `fecha_hasta` (req), `seccion` (VARILLAS|TROQUELADOS|''),
`turnos` (CSV M,T,N), `granularidad` (`day`|`week`|`month`, req).

**Filtros de paro:** `Cod_paro <> 11`, `Id_actividad <> 1`, `Fecha_fin IS NOT NULL`.
Sección vía `PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT`.

**Bucket SQL** sobre `hp.Dia_productivo`:
- `day` → `CAST(Dia_productivo AS DATE)`
- `week` → `DATEADD(WEEK, DATEDIFF(WEEK, 0, Dia_productivo), 0)` (lunes)
- `month` → `DATEADD(MONTH, DATEDIFF(MONTH, 0, Dia_productivo), 0)`

**Devuelve:**
```json
{
  "granularidad": "day",
  "seccion": "VARILLAS",
  "buckets": [ {"key":"2026-01-01","label":"01/01"}, ... ],
  "motivos": [
    { "motivo":"PAUSA", "total_horas": 312.5,
      "serie": [ {"key":"2026-01-01","horas":4.2}, ... ] }
  ]
}
```

- `buckets`: eje X completo y continuo, generado iterando desde `fecha_desde` a
  `fecha_hasta` con el paso de la granularidad (relleno de huecos).
- `motivos`: ordenados por `total_horas` desc (peso). Cada `serie` tiene 1 punto por
  bucket, en el mismo orden que `buckets`, con 0 en buckets sin datos.
- `label` del bucket: día `d/m`, semana `S{W} (d/m)`, mes `Mmm YYYY`.

### Endpoint 2 · `api/oee_unificado_motivo_periodo_maquinas.php`

Reparto por máquina de un motivo en un bucket concreto (popup del clic en un punto).

**GET:** filtros comunes + `motivo` (req, Desc_paro) + `bucket` (req, YYYY-MM-DD =
inicio del bucket) + `granularidad` (define el ancho del bucket).

El rango efectivo del reparto = `[inicio_bucket, fin_bucket] ∩ [fecha_desde, fecha_hasta]`
(evita contar días fuera del intervalo en buckets de borde parciales).

**Devuelve:**
```json
{
  "motivo":"PAUSA", "bucket":"2026-01-06", "granularidad":"week",
  "rango": {"desde":"2026-01-06","hasta":"2026-01-12"},
  "total_horas": 38.4,
  "maquinas": [ {"cod_maquina":"M12","maquina":"...","horas":12.1,"pct":31.5} ]
}
```
`maquinas` ordenadas por horas desc; `pct` = horas_máquina / total_horas * 100.

## UI y flujo (frontend, `oee_unificado_v2.html`)

**Entrada:** botón `📈 Evolución motivos` en la barra de acciones (junto a
`🧮 Matriz 2`, ~línea 395), llama a `App.openEvolMotivos()`.

**Layout** (`fullpop`, mismo shell que Matriz 2):

```
┌─ 📈 Evolución de motivos · [Sección] · [desde] a [hasta] ──────── [✕] ─┐
│  Granularidad:  [ Día ] [ Semana ] [ Mes ]      (Día activo por defecto)  │
├──────────────┬──────────────────────────────────────────────────────────┤
│ MOTIVOS      │              GRÁFICO DE LÍNEAS (ApexCharts, type:line)     │
│ (peso desc)  │   eje X = buckets · eje Y = horas de paro                  │
│ ▸ PAUSA 312h │   etiqueta de valor en cada punto (regla abajo)           │
│   AJUSTE 180h│   marcadores clicables → popup reparto por máquina         │
└──────────────┴──────────────────────────────────────────────────────────┘
```

**Flujo:**
1. Abrir → Endpoint 1 con granularidad `day`. Pinta lista (peso desc) y
   **preselecciona el motivo de más peso**, dibujando su serie.
2. Clic en motivo → marca activo y repinta solo su serie (sin llamada nueva: la
   serie ya vino en el Endpoint 1).
3. Clic en Día/Semana/Mes → re-llama Endpoint 1 con la nueva granularidad,
   conserva el motivo seleccionado y repinta. La lista no cambia de contenido.
4. Clic en un punto/marcador → popup (`fullpop` encima) que llama al Endpoint 2
   con `motivo + bucket + granularidad` y muestra el reparto por máquina (tabla +
   barra horizontal, horas y %, desc; total del periodo en cabecera).

**Regla de etiquetas de valor:**
- ≤ 31 buckets → `dataLabels.enabled = true` (etiqueta visible en cada punto).
- > 31 buckets → etiquetas ocultas (se solaparían); marcadores siempre visibles y
  clicables, valor en el tooltip. Mismo patrón que `dataLabels:{enabled:cats.length<=N}`
  ya usado en el código.

**Librería:** ApexCharts (`type:'line'`), ya cargada. Colores de la paleta `PALETTE`.

## Casos límite y errores

- Sin motivos en el intervalo → lista muestra "Sin paros para el filtro
  seleccionado"; no se dibuja gráfico.
- Motivo sin datos en un bucket → serie con 0 (línea continua, sin huecos).
- Punto con 0 horas → popup defensivo "Sin paros de este motivo en este periodo".
- Bucket de semana/mes parcial en bordes → Endpoint 2 acota al cruce con el rango.
- Errores backend/fetch → bloque `.error` con mensaje (patrón Matriz 2).
- Validación: fechas con regex; `granularidad ∈ {day,week,month}`;
  `seccion ∈ {VARILLAS,TROQUELADOS,''}`; `motivo`/`bucket` requeridos en Endpoint 2.

## Verificación

Sonda PHP por CLI (inyecta `$_GET`, ejecuta contra MAPEX real), valida:
1. Buckets continuos y bien etiquetados.
2. Motivos ordenados por peso (horas) desc.
3. Cuadre cruzado: Σ horas por máquina del Endpoint 2 == horas del punto del
   Endpoint 1 para ese motivo+bucket.

Más verificación visual en el navegador (lista → clic motivo → clic punto → popup).

## Fuera de alcance

Export a Excel, comparación multi-motivo, persistencia de la selección.
