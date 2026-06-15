# Export "Matriz" · OEE Unificado — Diseño

Fecha: 2026-06-15
Estado: aprobado (pendiente de implementación)

## Objetivo

Generar un XLSX **"Matriz"** que cruce, en una sola hoja, **motivos de paro** (columnas)
contra **máquinas y sus referencias** (filas), mostrando las **horas de paro** atribuidas
a cada referencia por cada motivo, más totales y la **% de disponibilidad por máquina**.

Es una tabla cruzada (pivot) de los mismos datos que ya produce el export OEE actual,
presentados de forma visual para leer "qué motivos paran qué máquinas y por qué referencias".

## Entrega

- **Endpoint nuevo:** `api/oee_unificado_matriz_export.php` (no se modifica `oee_unificado_export.php`).
- **Botón "Matriz"** en la barra de export de `views/oee_unificado.php`, junto al export actual,
  que descarga el fichero pasando los **filtros activos** de la vista.

## Ámbito (filtros)

Mismos filtros que el export actual:
- `fecha_desde`, `fecha_hasta` (YYYY-MM-DD), obligatorios.
- `turnos` (CSV M,T,N), opcional.
- `seccion` (opcional): si viene, limita a las máquinas de esa sección; si no, todas.
- Exclusiones de máquina (mismo criterio que el export actual, si aplica).

Las **columnas** = todos los motivos de paro (`cfg_paro.Desc_paro`, `Cod_paro <> 11`) presentes
en los paros del filtro. Las **referencias** = solo las que causaron algún paro
(atribución `his_prod_paro → his_fase → his_of → cfg_producto`).

## Fuentes de datos (reutilización)

1. **Árbol de paros** — patrón SQL de `_exportMotivoMaqRef()` en
   `api/oee_unificado_export.php:88`. Devuelve filas `(motivo, máquina, cod_ref, desc_ref, segundos)`.
   En el nuevo endpoint se re-pivota a una estructura indexada por **(máquina, referencia) × motivo**.
   Atribución de segundos de paro: `his_prod_paro` → `cfg_paro` / `his_prod` / `cfg_maquina` /
   `cfg_turno` y `LEFT JOIN his_fase → his_of → cfg_producto`. `Cod_paro <> 11`, `Fecha_fin IS NOT NULL`.

2. **% Disponibilidad por máquina** — patrón SQL OEE por máquina de
   `api/oee_unificado_export.php:442` (`SUM(M)`, `SUM(PNP)`, …) + `_calcDRCExport()`
   (`d = M / (M + PNP) * 100`).

## Estructura de la hoja "Matriz"

Filas 1-2: título + filtros aplicados (reutilizar `writeFilterHeader` / estilo equivalente).
Fila de cabecera de tabla:

```
Máquina / Referencia | Motivo A | Motivo B | … | TOTAL paro (h) | Disponib. %
```

- **Fila de máquina** (negrita, sombreada): por cada motivo, subtotal = suma de horas de sus
  referencias; TOTAL paro = suma de la fila; **Disponib. %** solo en esta fila (valor por máquina).
- **Filas de referencia** (indentadas bajo su máquina): horas de paro del cruce referencia × motivo
  (decimal, 2 dec.); celda vacía = sin paro de ese motivo; TOTAL paro = suma de la fila.
- **Fila final TOTAL**: por cada motivo, suma de todas las máquinas; TOTAL paro global.

Orden: máquinas alfabéticas (o por total de paro desc — a decidir en implementación, por defecto
alfabético como el export actual); referencias dentro de cada máquina por horas de paro desc.

## Estilo

Reutilizar de `oee_unificado_export.php`: `styleHeader()` (cabecera azul `2D4D7A`),
filas 1-2 de contexto, bordes finos, anchos auto. Filas de máquina con relleno claro
(`F4F7FB`); referencias con sangría visual (texto con prefijo de indentación o columna A
con sangría de celda).

## Fuera de alcance (YAGNI)

- No se añade hoja de detalle plano adicional (ya existe equivalente en el export actual).
- No se modifican vistas/APIs congeladas ni `oee_unificado_export.php`.
- Cada celda lleva **solo horas** (no minutos ni nº de paros), según lo confirmado.

## Verificación

- Endpoint devuelve XLSX válido (descarga, abre en Excel sin reparar).
- Suma de la fila TOTAL por motivo = suma de subtotales de máquina por ese motivo.
- TOTAL paro de cada máquina = suma de horas de sus referencias.
- % Disponibilidad por máquina coincide con la del export OEE actual para el mismo filtro.
- Probar con: filtro con sección, sin sección, con turnos, rango con y sin paros.
