# Filtro por horas (recálculo OEE desde tablas base) — Diseño

**Fecha:** 2026-07-01
**Proyecto:** oee_unificado (web "v2", systemd `oee-unificado-v2` en :8091)
**Rama:** feat/evolucion-motivos

## 1. Objetivo

Añadir al filtro principal la posibilidad de acotar por franja horaria (desde una
hora hasta otra). Como `F_his_ct` (fuente actual del OEE) solo agrega por día y NO
desglosa por hora, cuando el filtro horario esté activo se recalculan D/R/C/OEE
desde tablas base (`his_prod` + `his_prod_paro`).

## 2. Alcance (decisiones tomadas)

- **UI opt-in:** checkbox "Filtrar por horas" en el filtro principal. Desactivado
  por defecto. Al activarlo aparecen inputs Desde (HH:MM) → Hasta (HH:MM).
- **Cruce de medianoche** soportado (ej. 22:00 → 06:00), como ya hace
  `oee_unificado_hist_maquina.php`.
- **Vistas afectadas en esta entrega:** los 4 KPI de cabecera (DISP/REND/CAL/OEE)
  y la tabla/gráfico de máquinas por métrica. Las vistas secundarias (Matriz, Top,
  Evolución, Excel) siguen por día de momento.
- **No-regresión:** con el filtro OFF, el comportamiento es idéntico al actual.
- Fuera de alcance: filtro horario en Matriz/Top/Evolución/Excel; persistencia del
  filtro entre sesiones.

## 3. Fundamento verificado (spike 2026-07-01)

Recalcular desde tablas base cuadra con `F_his_ct` (verificado DOBL10, 01/07):
- **M** (tiempo marcha F_his_ct) = Σ DATEDIFF(SECOND, his_prod.Fecha_ini, Fecha_fin)
  − Σ paros. (El tiempo de his_prod incluye el paro; se resta.) 36685−235=36450 ✓.
- **PNP** = Σ DATEDIFF(SECOND, his_prod_paro.Fecha_ini, Fecha_fin) = 235 ✓.
- **Unidades_OK/NOK** = SUM(his_prod.Unidades_ok/nok) ✓ exacto.
- **PPERF = 0 y PCALIDAD = 0 SIEMPRE** en este MAPEX (verificado en toda la planta,
  semana 24/06–01/07). Con eso, las fórmulas de `_calcDRC` se reducen a:
  - **D** = M / (M + PNP)
  - **R** = M_OKNOK_TEO / M,  con M_OKNOK_TEO = (uds_ok + uds_nok) × ciclo_nominal_seg
  - **C** = uds_ok / (uds_ok + uds_nok)
  - **OEE** = D × R × C
- **Ciclo nominal:** `his_prod.SegCicloNominal`; si es 0/NULL, derivar de
  `his_prod.Rendimientonominal1` (uds/h → seg/ud = 3600/Rendimientonominal1).

## 4. Arquitectura

- **`lib/OeeHorario.php`** (nuevo): motor de recálculo. Método público
  `OeeHorario::porMaquina(array $filtros): array` que devuelve, por máquina,
  las magnitudes M/PNP/uds y los D/R/C/OEE ya calculados, filtrando por franja
  horaria sobre `Fecha_ini` real.
- **`api/oee_unificado.php`** (modificar): al inicio, detectar `hora_desde` +
  `hora_hasta` (validados HH:MM). Si ambos válidos → rama `OeeHorario`; si no →
  rama actual `F_his_ct` SIN cambios. La forma del JSON de salida es idéntica en
  ambas ramas (el frontend no distingue).
- **`oee_unificado_v2.html`** (modificar): checkbox + inputs de hora; el JS
  (`App.common()` o equivalente) añade `hora_desde`/`hora_hasta` a las peticiones
  de KPIs y tabla de máquinas solo cuando el check está activo.

## 5. Motor `OeeHorario` — detalle

### Filtro horario (patrón de `hist_maquina`, verificado existente)
- Params `hora_desde`, `hora_hasta` en HH:MM (regex `^([01]\d|2[0-3]):[0-5]\d$`).
- Si `hora_desde > hora_hasta` → cruza medianoche (dos sub-rangos).
- Se filtra por `his_prod.Fecha_ini` / `his_prod_paro.Fecha_ini` real (NO
  `Dia_productivo`, que agrupa turnos).

### Cálculo por máquina (SQL sobre tablas base)
```sql
-- Marcha y unidades por máquina en el rango [fdesde,fhasta] y franja horaria
SELECT mq.Cod_maquina, mq.Desc_maquina,
       SUM(DATEDIFF(SECOND, hp.Fecha_ini, ISNULL(hp.Fecha_fin, hp.Fecha_ini))) AS seg_bruto,
       SUM(ISNULL(hp.Unidades_ok,0))  AS u_ok,
       SUM(ISNULL(hp.Unidades_nok,0)) AS u_nok,
       -- ciclo nominal en segundos por unidad (fallback a 3600/Rendimientonominal1)
       MAX(COALESCE(NULLIF(hp.SegCicloNominal,0),
                    CASE WHEN hp.Rendimientonominal1>0 THEN 3600.0/hp.Rendimientonominal1 END, 0)) AS ciclo_seg
FROM his_prod hp
INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
WHERE CAST(hp.Fecha_ini AS DATE) BETWEEN ? AND ?
  AND <filtro franja horaria sobre DATEPART(HOUR/MINUTE, hp.Fecha_ini)>
  AND mq.Cod_maquina NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')
GROUP BY mq.Cod_maquina, mq.Desc_maquina
```
```sql
-- Paros por máquina en el mismo rango y franja
SELECT mq.Cod_maquina,
       SUM(DATEDIFF(SECOND, hpp.Fecha_ini, ISNULL(hpp.Fecha_fin, hpp.Fecha_ini))) AS seg_paro
FROM his_prod_paro hpp
INNER JOIN his_prod hp    ON hp.Id_his_prod = hpp.Id_his_prod
INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
WHERE CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?
  AND <filtro franja horaria sobre hpp.Fecha_ini>
  AND hpp.Fecha_fin IS NOT NULL
GROUP BY mq.Cod_maquina
```

Nota sobre precisión del solape: en esta primera entrega se filtra por la HORA de
inicio del registro (`Fecha_ini` dentro de la franja), coherente con el patrón de
`hist_maquina`. El prorrateo fino de registros que cruzan el límite de la franja
queda como posible mejora futura (documentado, no en alcance).

### Ensamblado por máquina
```
M   = max(0, seg_bruto - seg_paro)
PNP = seg_paro
M_OKNOK_TEO = (u_ok + u_nok) * ciclo_seg
M_OK_TEO    = u_ok * ciclo_seg
D = M/(M+PNP)*100 ;  R = M_OKNOK_TEO/M*100 ;  C = u_ok/(u_ok+u_nok)*100 ;  OEE = D*R*C/10000
```
Se reutiliza la MISMA `_calcDRC(...)` existente (con PPERF=PCALIDAD=0) para que la
fórmula sea idéntica a la vista por día.

## 6. Filtro de franja horaria en SQL

Para franja normal (desde ≤ hasta):
```sql
AND (DATEPART(HOUR, hp.Fecha_ini)*60 + DATEPART(MINUTE, hp.Fecha_ini))
      BETWEEN ? AND ?     -- minutos desde medianoche: hDesde..hHasta
```
Para cruce de medianoche (desde > hasta): `>= hDesde OR < hHasta`.

## 7. Frontend

- Checkbox `#chkHoras` "Filtrar por horas" en el filtro principal.
- Inputs `#hDesde` `#hHasta` (`type=time`), ocultos hasta activar el check.
- En la función que arma los parámetros comunes de las peticiones (`App.common`),
  si `#chkHoras` está marcado y ambas horas son válidas, añadir
  `hora_desde`/`hora_hasta`. Aplicar solo a las llamadas de KPIs y tabla de
  máquinas.
- Indicador visual (en la línea de "RANGO … SECCIÓN …") de que el filtro horario
  está activo, con la franja.

## 8. Verificación (obligatoria)

1. **No-regresión (crítico):** capturar el JSON de
   `oee_unificado.php?fecha_desde=&fecha_hasta=&turnos=` SIN horas ANTES de tocar
   nada; tras implementar, el mismo request debe devolver el MISMO JSON.
2. **Motor cuadra:** con `hora_desde=00:00&hora_hasta=23:59` (día completo), los
   D/R/C/OEE recalculados deben aproximarse a los de `F_his_ct` para varias
   máquinas (documentar el desvío si lo hay).
3. **Franja parcial:** una franja (ej. 06:00–14:00) da valores plausibles y
   distintos al día completo.
4. **Cruce de medianoche:** 22:00–06:00 no rompe y agrupa las dos mitades.
5. **Visual:** en `http://10.0.0.110:8091/oee_unificado_v2.html`, activar el check,
   fijar horas, ver KPIs y tabla cambiar; desactivar y volver a los valores por día.

## 9. Riesgos / notas

- **Dependencia de PPERF=PCALIDAD=0:** el motor asume que valen 0 (verificado hoy).
  Si en el futuro MAPEX empezara a poblarlos, R y C se desviarían — dejar comentario
  en el código señalando esta asunción.
- **Ciclo nominal 0/NULL:** si una máquina no tiene ciclo ni rendimiento nominal,
  R no es calculable → se muestra 0 en R/OEE (D y C siguen válidos).
- **Precisión de franja:** filtro por hora de inicio, no prorrateo fino (ver §5).
- No tocar la rama `F_his_ct`: el filtro OFF debe ser byte-idéntico al actual.
