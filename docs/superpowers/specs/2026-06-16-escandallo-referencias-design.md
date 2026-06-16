# Página "Referencias · Escandallo / Centro / Máquina" — Diseño

Fecha: 2026-06-16
Estado: aprobado

## Objetivo
Página HTML autónoma (fuera de Plan Attainment) que lista las referencias **con
escandallo de componentes en SAGE** y, al seleccionar una, muestra su escandallo
(componentes) y su centro/máquina de fabricación.

## Hallazgo de datos (relevante)
- SAGE `Estructura_Escandallo` está casi sin usar: 7 nodos, **1 sola referencia con
  componente real** (`302580OR` → `516108 PINZA.EMBALAJE.VARILLAS.3MM`). La lista
  por tanto será corta (crece si se mantiene el escandallo en SAGE).
- Centro/máquina sí está completo en MAPEX (`cfg_fase` + `cfg_fase_maquina`).

## Entrega
- `escandallo_referencias.html` en la raíz del repo (estilo `oee_unificado_v2.html`).
- Endpoints JSON nuevos.

## Endpoints
1. `api/escandallo_lista.php` (SAGE). Lee `Estructura_Escandallo` agrupado por
   `CodigoProceso`; el padre = fila de menor `Orden`; componentes = filas con
   `CodigoArticulo` distinto al padre. Devuelve solo padres con ≥1 componente:
   `{ refs: [{ cod_producto, desc, referencia_edi, num_componentes }] }`.
2. `api/escandallo_detalle.php?cod_producto=…`:
   - `componentes` (SAGE): filas del `CodigoProceso` del padre con cod distinto →
     `[{ cod, desc, unidades, um }]`.
   - `fases` (MAPEX): `cfg_producto`(Cod_producto=cod) → `cfg_fase` (Activo) →
     `cfg_fase_maquina`(Activo) ⋈ `cfg_maquina` →
     `[{ cod_fase, desc_fase, orden, maquinas:[{ cod_maquina, desc_maquina,
        rend_nominal, seg_ciclo }] }]`.
   - `referencia`: `{ cod, desc, referencia_edi }` (SAGE `Articulos`).

## UI
- Cabecera: título + buscador.
- Izquierda: lista de referencias (desc SAGE + ReferenciaEdi_).
- Derecha: al seleccionar, dos bloques — "Escandallo · componentes" (tabla) y
  "Centro y máquina" (fases → máquinas).

## Verificación
- `escandallo_lista` devuelve 302580OR (1 componente) hoy.
- `escandallo_detalle?cod_producto=302580OR` → componente 516108 + fase Conformado
  con máquinas BT 3.2 / TBE RAPIDFORM.
- Página carga, lista, selección muestra ambos bloques. Sin errores en consola.
