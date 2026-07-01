# Colores por tipo de paro + actividad en histograma — Diseño

**Fecha:** 2026-07-01
**Proyecto:** oee_unificado (web "v2", systemd `oee-unificado-v2` en :8091)
**Rama:** feat/evolucion-motivos
**Referencia:** `PLAN_ATTAINMENT/load/ConsultaTipoParos.xlsx` (clasificación de paros)

## 1. Objetivo

Tres mejoras visuales/informativas sobre paros:
1. En la gráfica "Motivos de paro generales", pintar cada barra según el **tipo de
   paro OEE** (Disponibilidad vs Paro Planificado) para distinguirlos.
2. En el "Histograma" (cronograma de paros por máquina), añadir al tooltip la
   **actividad** en la que ocurrió cada paro (producción, preparación, ajustes,
   mantenimiento, mejoras, prototipos…).
3. En el histograma, los tramos con motivo/actividad **CERRADA** se pintan en
   **gris claro**.

## 2. Fundamento verificado

- **Clasificación tipo de paro** = `cfg_paro.Id_TipoparoOEE` (verificado que coincide
  con el Excel ConsultaTipoParos.xlsx):
  - `Id_TipoparoOEE = 1` → **Disponibilidad** (NO JUSTIFICADO, MICRO PARO, …).
  - `Id_TipoparoOEE = 2` → **Paro Planificado** (PAUSA, PREVENTIVO, PROTOTIPOS,
    MEJORA PROCESO, CERRADA, …).
  - Sin conflictos (113 descripciones únicas del Excel mapean 1:1).
- **Actividad del paro** = `his_prod.Id_actividad` → `cfg_actividad.Desc_actividad`
  (verificado: cada paro de `his_prod_paro` tiene su `his_prod` con `Id_actividad`;
  ej. PRODUCCION=2, PREPARACION=3, MANTENIMIENTO=4, AJUSTES=5, MEJORAS=20,
  PROTOTIPOS=21, CERRADA=1).
- La gráfica de motivos generales se alimenta de `api/oee_unificado_drill.php`
  (`renderGeneralMotivos`), que ya hace `INNER JOIN cfg_paro`.
- El histograma se alimenta de `api/oee_unificado_hist_maquina.php`.

## 3. Colores (acordados)

- **Disponibilidad** (tipo 1): `#2d4d7a` (azul actual de la barra).
- **Paro planificado** (tipo 2): `#8c181a` (granate corporativo KH).
- **CERRADA**: `#c3cad3` (gris claro) — prevalece sobre el tipo, tanto en la
  gráfica de motivos como en el histograma.

## 4. Cambio 1 — Gráfica "Motivos de paro generales"

### Backend: `api/oee_unificado_drill.php`
En el SELECT que agrupa motivos (el que produce `motivos[]` con `motivo` + valor),
añadir `MAX(cp.Id_TipoparoOEE) AS tipo_oee` y devolverlo por motivo. (El join
`cfg_paro cp` ya existe; solo se añade la columna y al GROUP BY si aplica.)

Resultado por motivo: `{ motivo, horas/valor, tipo_oee }`.

### Frontend: `oee_unificado_v2.html` → `renderGeneralMotivos`
Al construir el dataset de barras (hoy `backgroundColor:'#2d4d7a'` fijo), pasar a
un array de colores por barra:
```
color(m) =
  UPPER(m.motivo) == 'CERRADA'        → '#c3cad3'
  m.tipo_oee == 2 (Paro Planificado)  → '#8c181a'
  else (Disponibilidad)               → '#2d4d7a'
```
Añadir una **leyenda** bajo el título con los tres colores y sus etiquetas
(Disponibilidad / Paro planificado / Cerrada).

## 5. Cambio 2 — Histograma: actividad en el tooltip

### Backend: `api/oee_unificado_hist_maquina.php`
En la(s) consulta(s) que devuelven los tramos de paro, añadir
`ac.Desc_actividad AS actividad` con `LEFT JOIN cfg_actividad ac ON ac.Id_actividad
= hp.Id_actividad` (hp = his_prod ya presente en la consulta). Exponer `actividad`
por tramo en el JSON.

### Frontend: tooltip del cronograma
Donde se arma el texto del tooltip de cada tramo (motivo, horas, inicio/fin),
añadir una línea "Actividad: <actividad>" cuando el dato exista.

## 6. Cambio 3 — Histograma: CERRADA en gris claro

### Frontend: color de tramo del cronograma
En el render del cronograma (Gantt), el color de cada tramo:
```
UPPER(tramo.motivo) == 'CERRADA'  (o actividad CERRADA) → '#c3cad3'
resto → color de motivo actual (sin cambios)
```

## 7. Componentes (archivos)

- **Modify `api/oee_unificado_drill.php`** — añadir `tipo_oee` por motivo.
- **Modify `api/oee_unificado_hist_maquina.php`** — añadir `actividad` por tramo.
- **Modify `oee_unificado_v2.html`** — colores de barras + leyenda
  (renderGeneralMotivos); tooltip con actividad + color CERRADA (histograma).

## 8. Verificación

- Backend drill: `curl` y comprobar que cada motivo trae `tipo_oee` (PAUSA/PREVENTIVO
  → 2; NO JUSTIFICADO → 1).
- Backend histograma: `curl` y comprobar `actividad` por tramo.
- Visual: en `http://10.0.0.110:8091/oee_unificado_v2.html`:
  - Gráfica de motivos: barras de Paro planificado en granate, Disponibilidad en
    azul, CERRADA en gris; leyenda visible.
  - Histograma: tooltip muestra la actividad; tramos CERRADA en gris claro.
- No-regresión: motivos y sus valores numéricos no cambian (solo el color) y el
  resto del histograma sigue igual.
- Tras tocar servidor: `sudo systemctl restart oee-unificado-v2`.

## 9. Riesgos / notas

- `Id_TipoparoOEE` puede ser NULL en algún paro raro → tratar como Disponibilidad
  (color azul por defecto).
- El match de "CERRADA" se hace por `UPPER(TRIM(motivo)) === 'CERRADA'` para ser
  robusto a espacios/mayúsculas.
- Solo cambian colores/tooltip; los valores (horas, % acumulado) no se tocan.
