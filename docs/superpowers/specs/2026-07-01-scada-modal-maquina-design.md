# Modal de detalle por máquina (SCADA) — Diseño

**Fecha:** 2026-07-01
**Proyecto:** oee_unificado (web "v2", systemd `oee-unificado-v2` en :8091)
**Rama:** feat/evolucion-motivos (continúa el trabajo del SCADA mural)
**Referencia visual:** `PLAN_ATTAINMENT/load/SCADA RESUMEN.PNG`, `SCADA PAROS.PNG`, `SCADA OFS.PNG`

## 1. Objetivo

Al clicar cualquier tarjeta del mural SCADA (`scada.html`), abrir un modal con el
detalle de esa máquina, organizado en tres pestañas — RESUMEN, PAROS, OFS —
replicando las tres imágenes de referencia. Toda la información proviene de MAPEX.

## 2. Alcance (decisiones tomadas)

- **Apertura:** clic en tarjeta → modal overlay con cabecera de máquina + 3 pestañas
  + botón "↻ Actualizar" + cierre (✕ / clic fuera / Esc).
- **Carga lazy:** cada pestaña consulta sus datos al abrirse. RESUMEN por defecto.
- **RESUMEN:** réplica fiel de la imagen (incluye "ritmo real vs teórico").
- **Endpoints:** uno por pestaña.
- Fuera de alcance: edición de datos, exportación, histórico > 7 días.

## 3. Arquitectura

Archivos NUEVOS + ampliación de la clase existente `lib/ScadaMural.php`. El modal
vive dentro de `scada.html` (misma página). No se modifica ningún otro archivo.

- **`api/scada_maquina_resumen.php?cod=`** — KPIs de resumen de la máquina.
- **`api/scada_maquina_paros.php?cod=&fecha=`** — paros del día indicado.
- **`api/scada_maquina_ofs.php?cod=&fecha=`** — OFs del día indicado.
- **`lib/ScadaMural.php`** — nuevos métodos `resumenMaquina()`, `parosMaquina()`,
  `ofsMaquina()`. Reutilizan `fetchAll('mapex',...)`, `calcDRC` y las queries ya
  verificadas del mural.
- **`scada.html`** — markup del modal + JS de apertura/pestañas/refresco.

Acceso: el modal se abre desde `http://10.0.0.110:8091/scada.html`.

## 4. Cabecera del modal (común a las 3 pestañas)

Igual que la tarjeta: `Desc_maquina` + `Cod_maquina` + badge de estado
(`Rt_Desc_actividad`) + OF (`Rt_Cod_of`) + producto. Datos ya disponibles en el
mural; el JS los tiene al clicar (no requiere consulta extra).

## 5. Pestaña RESUMEN

Endpoint `api/scada_maquina_resumen.php?cod=<Cod_maquina>` → JSON:

```json
{ "ok":true, "data":{
  "ritmo": { "real": 7, "teorico": 11, "desvio": -4 },
  "oee":   { "turno": 51, "of": 40, "rend_turno": 74 },
  "orden": { "ok": 39, "plan": 1040, "pct": 4, "faltan": 1001 },
  "unidades": { "ok": 39, "nok": 0, "rwk": 5 }
}}
```

Cálculo (todo desde `cfg_maquina` Rt_* + `F_his_ct`, misma máquina):
- **ritmo.real** = `Rt_Unidades_ok_turno`.
- **ritmo.teorico** = round(`Rt_Seg_produccion_turno` × `Rt_Rendimientonominal1` / 3600).
- **ritmo.desvio** = real − teorico.
- **oee.turno / oee.of** = `calcDRC` vía F_his_ct (como en el mural).
- **oee.rend_turno** = rendimiento del bloque de turno (ya lo da `calcDRC`).
- **orden**: ok=`Rt_Unidades_ok_of`, plan=`his_fase.Unidades_planning`,
  pct=round(ok/plan·100), faltan=max(0, plan−ok).
- **unidades**: ok/nok/rwk de turno (`Rt_Unidades_*_turno`).

Render: título "RITMO DEL TURNO · REAL vs TEÓRICO", número grande `real / teorico
esperadas` + barra + desvío en rojo; fila OEE (Turno/OF/Rend.turno) con barras;
"ORDEN EN CURSO" ok/plan·% + "faltan N" + barra; línea OK/NOK/RWK.

## 6. Pestaña PAROS

Endpoint `api/scada_maquina_paros.php?cod=<Cod_maquina>&fecha=YYYY-MM-DD` → JSON:

```json
{ "ok":true, "data":{
  "fecha":"2026-07-01", "total_seg": 10140,
  "paros":[ {"ini":"07:44","fin":"07:50","seg":370,"motivo":"NO JUSTIFICADO","of":"2026-..."} ]
}}
```

Fuente (verificada):
```sql
SELECT hpp.Fecha_ini, hpp.Fecha_fin,
       DATEDIFF(SECOND, hpp.Fecha_ini, ISNULL(hpp.Fecha_fin, GETDATE())) AS seg,
       cp.Desc_paro AS motivo, o.Cod_of AS ofx
FROM his_prod_paro hpp
INNER JOIN his_prod hp     ON hp.Id_his_prod = hpp.Id_his_prod
INNER JOIN cfg_maquina mq  ON mq.Id_maquina  = hp.Id_maquina
LEFT  JOIN cfg_paro cp     ON cp.Id_paro     = hpp.Id_paro
LEFT  JOIN his_fase fa     ON fa.Id_his_fase = hp.Id_his_fase
LEFT  JOIN his_of o        ON o.Id_his_of    = fa.Id_his_of
WHERE mq.Cod_maquina = ? AND CAST(hpp.Fecha_ini AS DATE) = ?
ORDER BY hpp.Fecha_ini DESC
```

`total_seg` = suma de `seg`. Render: filtro fecha (input date + botones Hoy/Ayer/
7 días), "PAROS DEL DÍA · fecha · Xh Ym EN TOTAL", lista de filas
(ini, fin, duración formateada, motivo, OF a la derecha).

Nota "7 días": el filtro fecha se mantiene como día único (input date). El botón
"7 días" consulta el rango [fecha-6, fecha] (se añade parámetro opcional
`dias=7` al endpoint; por defecto 1 día). Los tres botones fijan fecha/rango.

## 7. Pestaña OFS

Endpoint `api/scada_maquina_ofs.php?cod=<Cod_maquina>&fecha=YYYY-MM-DD` → JSON:

```json
{ "ok":true, "data":{
  "fecha":"2026-07-01",
  "ofs":[ {"of":"2026-SEC09-1882-2026-3868","producto":"F175.FR...","plan":416,
           "ok":39,"rwk":5,"pct":4} ]
}}
```

Fuente (verificada; se amplía con OK/RWK sumando `his_prod` de esa OF+día):
```sql
SELECT o.Cod_of, prod.Desc_producto AS producto,
       MAX(fa.Unidades_planning) AS plan_of,
       SUM(ISNULL(hp.Unidades_ok,0))    AS ok,
       SUM(ISNULL(hp.Unidades_repro,0)) AS rwk
FROM his_prod hp
INNER JOIN cfg_maquina mq  ON mq.Id_maquina  = hp.Id_maquina
INNER JOIN his_fase fa     ON fa.Id_his_fase = hp.Id_his_fase
INNER JOIN his_of o        ON o.Id_his_of    = fa.Id_his_of
LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
WHERE mq.Cod_maquina = ? AND CAST(hp.Dia_productivo AS DATE) = ?
  AND o.Cod_of <> '--'
GROUP BY o.Cod_of, prod.Desc_producto
```

`pct` = round(ok/plan·100) si plan>0, si no 0. Render: filtro fecha (igual que
PAROS), "OFS DEL DÍA · fecha", tarjetas por OF (código en negrita, producto,
`PLAN n · OK n · RWK n` con PLAN en azul/OK en verde, % a la derecha, barra).

## 8. Frontend (dentro de `scada.html`)

- Cada `.tarjeta` recibe `data-cod` y un handler de clic que abre el modal.
- Modal: overlay + panel; cabecera (datos de la tarjeta en memoria); barra de
  pestañas (RESUMEN/PAROS/OFS) + "↻ Actualizar"; cuerpo que hace `fetch` del
  endpoint de la pestaña activa.
- Cierre: ✕, clic en overlay, tecla Esc.
- Filtro de fecha en PAROS/OFS: input date + botones Hoy/Ayer/7 días.
- Manejo de error por pestaña: mensaje "no se pudieron cargar los datos" sin
  romper el modal.
- El auto-refresco del mural de fondo NO debe repintar/!cerrar el modal abierto
  (pausar el re-render del mural mientras el modal está abierto, o repintar solo
  el fondo sin afectar al overlay).

## 9. Verificación

- Endpoints: `curl` a los 3 con una máquina real (p.ej. SOLD8) y contrastar
  contra las imágenes (motivos de paro, OFs, ritmo).
- Visual: abrir `http://10.0.0.110:8091/scada.html`, clicar una tarjeta, recorrer
  las 3 pestañas y los filtros de fecha; comparar con las 3 imágenes.
- Tras tocar servidor: `sudo systemctl restart oee-unificado-v2`.

## 10. Riesgos / notas

- El producto en PAROS/OFS usa `Desc_producto` (la descripción técnica); en la
  cabecera del modal se mantiene coherencia con la tarjeta.
- "Ritmo teórico" depende de la cadencia `Rt_Rendimientonominal1`; si es 0, se
  muestra "—" (no divide por cero).
- El re-render del mural de fondo no debe interferir con el modal (ver §8).
