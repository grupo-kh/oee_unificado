# Recálculo de las 4 métricas (D/R/C/OEE) por franja horaria — Diseño

**Fecha:** 2026-07-02
**Proyecto:** oee_unificado (web "v2", systemd `oee-unificado-v2` en :8091)
**Rama:** main

## 1. Objetivo

Que el filtro por horas de la cabecera afecte a las CUATRO métricas
(Disponibilidad, Rendimiento, Calidad, OEE) en las vistas por máquina y por
referencia, de forma coherente entre sí, para poder comparar franjas. Hoy solo
Disponibilidad y Calidad respetan el filtro; Rendimiento y OEE (que salen de
F_his_ct) lo ignoran.

## 2. Contexto y limitación (verificado)

- `F_his_ct` solo agrega por DÍA (no desglosa por hora). La tabla `his_horaOEE`
  (que tendría OEE por hora) está VACÍA en este MAPEX. No hay fuente exacta.
- **Decisión del usuario:** con filtro horario, recalcular las 4 métricas desde
  tablas base (`his_prod` + `his_prod_paro`). Es APROXIMADO respecto a la vista por
  día (Rendimiento el más desviado, puede superar 100%), pero COMPARABLE entre
  franjas porque todas usan la misma fuente.
- **Regla de coherencia:** con filtro horario, las 4 métricas vienen del MISMO
  recálculo y pasan por el MISMO `_calcDRC`, de modo que D×R×C = OEE en pantalla.
- Sin filtro horario: TODO sigue con F_his_ct (exacto por día). No-regresión.
- PPERF = PCALIDAD = 0 en este MAPEX (verificado) → fórmulas se simplifican.

## 3. Fórmulas del recálculo (desde tablas base, por clave)

Por cada clave de agrupación (cod_maquina, o cod_maquina+cod_producto):
- **M** (marcha) = Σ DATEDIFF(SECOND, his_prod.Fecha_ini, Fecha_fin) prorrateado a
  la franja − Σ paros en la franja.
- **PNP** = Σ DATEDIFF(SECOND, his_prod_paro.Fecha_ini, Fecha_fin) en la franja.
- **M_OKNOK_TEO** = (uds_ok + uds_nok) × ciclo_seg ; **M_OK_TEO** = uds_ok × ciclo_seg.
- ciclo_seg = COALESCE(NULLIF(SegCicloNominal,0), 3600/Rendimientonominal1, 0).
- PPERF = 0, PCALIDAD = 0.
- Se pasan a `_calcDRC(M, 0, M_OKNOK_TEO, M_OK_TEO, 0, 0, PNP)` → D/R/C/OEE.

Filtro horario sobre `Fecha_ini` con el helper `filtroFechaHora` (ya existe, con
cruce de medianoche).

## 4. Componentes

### 4.1 `lib/OeeHorario.php` (nuevo)
Método `magnitudesPorClave(array $filtros): array` que devuelve
`[clave => ['M','M_OKNOK_TEO','M_OK_TEO','PPERF','PCALIDAD','PNP','cod_maquina','maquina','cod_producto']]`.
Parámetros: fdesde, fhasta, hDesde, hHasta, turnos, excl, codMaqs (opc), agrupación
('maquina' | 'maquina_producto'). Dos sub-consultas (producción y paros) unidas por
clave, filtradas por franja horaria. Reutiliza `filtroFechaHora`.

### 4.2 `api/oee_unificado.php`
En la consulta 1 (F_his_ct agrupada por WorkGroup, ~línea 84-97) que alimenta
`$rows` → global + tabla por máquina: si hay `hora_desde`/`hora_hasta` válidas,
tomar `$rows` de `OeeHorario::magnitudesPorClave(agrupación='maquina')` en lugar de
F_his_ct. El resto (acumulación global, secciones, `_calcDRC`, `maquinas_activas`)
NO cambia (opera sobre `$rows` con las mismas claves M/MT/MOT/MOKT/PP/PC/PNP).
NOTA: MT (M_Teo) no lo da el recálculo → se pasa 0 (solo informativo, no afecta DRC).
Los contadores num_ofs/num_refs (que ya filtran por Dia_productivo) se dejan igual.

### 4.3 `api/oee_unificado_drill.php`
Las funciones de RENDIMIENTO que hoy usan F_his_ct:
- `_refsRendimiento` (rendimiento por referencia)
- `_motivosRendimiento` (motivos/pérdidas de rendimiento por máquina)
Cuando hay filtro horario, usar el recálculo por hora (magnitudes desde tablas
base agrupadas por producto/máquina) en vez de F_his_ct. Disponibilidad
(`_motivosParos`, `_refsParos`) y Calidad (`_motivosCalidad`) YA filtran por hora
(hecho en la corrección anterior). Rendimiento es lo que falta aquí.

### 4.4 Frontend
Sin cambios estructurales: el check ya existe y `common()` ya propaga
`hora_desde`/`hora_hasta`. Rendimiento/OEE pueden superar 100% → se muestran tal
cual (el aviso "con filtro las cifras son aproximadas" ya está en la cabecera).

## 5. Verificación

- Helper: CLI, comparar magnitudes de una máquina-día con la PoC ya hecha.
- `oee_unificado.php` con filtro horario: los 4 KPI globales y la tabla por máquina
  cambian con la franja; D×R×C ≈ OEE (coherencia). Sin filtro = idéntico a hoy.
- `drill.php`: Rendimiento por referencia y motivos de rendimiento cambian con la
  franja. Disponibilidad/Calidad siguen filtrando (no-regresión de lo ya hecho).
- Comparabilidad: dos franjas distintas del mismo día dan valores distintos y
  coherentes (Σ de las franjas ≈ día, con desvío esperado por el recálculo).
- No-regresión: filtro OFF ⇒ mismos números que hoy en las 4 métricas.
- Tras tocar servidor: `sudo systemctl restart oee-unificado-v2`.

## 6. Riesgos / notas

- El recálculo de M no cuadra con F_his_ct (documentado); Rendimiento sobre-estima.
  Aceptado: prioriza comparabilidad entre franjas, no paridad con la vista por día.
- Coherencia interna (D×R×C=OEE) garantizada al usar el mismo `_calcDRC` para las 4.
- Ciclo nominal 0/NULL en una máquina → su Rendimiento sale 0 (no rompe).
- No mezclar fuentes: con filtro horario, las 4 métricas de una misma pantalla
  vienen todas del recálculo (nunca unas de F_his_ct y otras del recálculo).
