# OEE Unificado — Distribución horaria del motivo seleccionado

**Fecha:** 2026-05-11
**Vista afectada:** `views/oee_unificado.php` (no congelada)
**Estado:** Diseño aprobado pendiente de plan de implementación.

## Contexto

En la vista *OEE Unificado* (`views/oee_unificado.php`), tras elegir sección → métrica (D / R / C / OEE) se muestran dos gráficos: por máquina y un Pareto de motivos. Al clicar un motivo del Pareto se abre `#motivo-drill-block` con un desglose **por máquina** de ese motivo concreto.

El operador necesita poder ver, además, **a qué hora del día** ha pasado ese motivo, para discernir patrones temporales (p. ej. siempre tras la comida, al final del turno de noche) y evaluar mejor la causa raíz.

## Decisiones de diseño (cerradas)

| Decisión | Valor |
|---|---|
| Alcance del nuevo gráfico | Solo el motivo seleccionado (ya dentro de `#motivo-drill-block`) |
| Eje temporal | Hora del día agregada 00–23 sobre todo el rango Desde–Hasta |
| Visualización | Barras apiladas — segmentos por máquina (top 5 + "Otras") |
| Integración API | Extender `api/oee_unificado_motivo_drill.php` con un campo `por_hora` |
| Granularidad para rendimiento | `F_his_ct(...,'HOUR',...)` (con smoke test al iniciar implementación) |
| Filtros respetados | Sección, métrica, motivo, rango fechas, turnos |

## Cambios en la UI

`views/oee_unificado.php` — dentro del bloque `#motivo-drill-block`, **debajo** de `#chart-motivo-maquinas`, añadir:

```html
<div class="oee-detalle-subtitle" style="margin-top:18px">
    Distribución horaria <small>(hora del día 00–23, agregada sobre el rango)</small>
</div>
<div id="chart-motivo-hora"></div>
```

Sin más cambios en la vista. El bloque ya respeta filtros y se abre/cierra con el handler existente.

## Cambios en la API — `api/oee_unificado_motivo_drill.php`

### Forma del JSON

Añadir el campo `por_hora` al objeto retornado por `jsonOk(...)`. El resto (`seccion`, `metrica`, `motivo`, `detalle`) no cambia.

```json
{
  "seccion": "VARILLAS",
  "metrica": "disponibilidad",
  "motivo":  "CAMBIO BOBINA",
  "detalle": [ ... ],
  "por_hora": {
    "unidad": "h",
    "maquinas": [
      { "cod_maquina": "BT1",       "maquina": "BT 1.1" },
      { "cod_maquina": "DOBL3",     "maquina": "DOBL 3" },
      { "cod_maquina": "__OTRAS__", "maquina": "Otras"  }
    ],
    "horas": [
      { "h": 0,  "BT1": 0.0, "DOBL3": 0.0, "__OTRAS__": 0.0 },
      { "h": 1,  "BT1": 0.0, "DOBL3": 0.0, "__OTRAS__": 0.0 },
      { "h": 14, "BT1": 0.42, "DOBL3": 1.10, "__OTRAS__": 0.05 }
    ]
  }
}
```

Reglas:

- `unidad`: `"h"` para disponibilidad / rendimiento / oee; `"uds"` para calidad.
- `maquinas`: top 5 por valor total + opcional `"__OTRAS__"` (solo si hay ≥6 máquinas con valor > 0). Cada elemento aparece como key en cada fila de `horas`.
- `horas`: siempre 24 entradas (`h` 0..23), aunque tengan todas las series a 0.
- Si `por_hora.horas` queda vacío (p. ej. rendimiento sin datos), el front muestra mensaje de "Sin desglose horario disponible".

### SQL — disponibilidad / OEE (paros)

Cada `his_prod_paro` tiene `Fecha_ini`/`Fecha_fin` precisos. Para repartir un paro que cruza horas, prorrateamos por solape — mismo patrón que `api/grid.php` (97–128), pero generando los slots **a partir de la hora truncada de `Fecha_ini` y extendiéndose tantas horas como dure el paro**. Esto maneja correctamente paros que cruzan medianoche.

```sql
WITH paros AS (
    SELECT
        mq.Cod_maquina  AS cod_maquina,
        mq.Desc_maquina AS maquina,
        hpp.Fecha_ini, hpp.Fecha_fin
    FROM his_prod_paro hpp
    INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
    INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
    INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
    INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
    WHERE CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?
      AND cp.Cod_paro <> 11
      AND hpp.Fecha_fin IS NOT NULL
      AND cp.Desc_paro = ?
      AND mq.Cod_maquina IN (...)   -- cod_maquinas de la sección
      /* AND ct.Cod_turno IN (...)   -- si hay turnos */
),
hour_slots AS (
    SELECT
        p.cod_maquina, p.maquina, p.Fecha_ini, p.Fecha_fin,
        DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) AS slot_ini
    FROM paros p
    CROSS JOIN (VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),
                       (12),(13),(14),(15),(16),(17),(18),(19),(20),(21),(22),(23)) n(h)
    WHERE DATEADD(HOUR, DATEDIFF(HOUR, 0, p.Fecha_ini) + n.h, 0) < p.Fecha_fin
)
SELECT
    cod_maquina, maquina,
    DATEPART(HOUR, slot_ini) AS hora,
    SUM(DATEDIFF(SECOND,
        CASE WHEN Fecha_ini > slot_ini                       THEN Fecha_ini ELSE slot_ini END,
        CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini)     THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
    )) AS segundos
FROM hour_slots
GROUP BY cod_maquina, maquina, DATEPART(HOUR, slot_ini)
HAVING SUM(DATEDIFF(SECOND,
        CASE WHEN Fecha_ini > slot_ini                   THEN Fecha_ini ELSE slot_ini END,
        CASE WHEN Fecha_fin < DATEADD(HOUR, 1, slot_ini) THEN Fecha_fin ELSE DATEADD(HOUR, 1, slot_ini) END
    )) > 0
```

Notas:

- `VALUES (0..23)` solo limita la duración máxima de un paro a 24 h. Si en producción aparecen paros más largos (raro), ampliar a (0..47) o usar una tabla de números.
- Cuando un paro cruza medianoche, la `DATEPART(HOUR, slot_ini)` re-agrega correctamente las dos horas a sus buckets 00-23 respectivos (hora-del-día agregada — coherente con la decisión cerrada).
- Resultado en **segundos**; se convierten a horas con 2 decimales en PHP antes del JSON.

### SQL — calidad (rechazos)

`his_prod_defecto` no tiene timestamp propio; los rechazos se atribuyen a la hora de inicio del `his_prod` padre (`DATEPART(HOUR, hp.Fecha_ini)`).

```sql
SELECT
    mq.Cod_maquina AS cod_maquina,
    mq.Desc_maquina AS maquina,
    DATEPART(HOUR, hp.Fecha_ini) AS hora,
    SUM(hpd.Unidades) AS unidades
FROM his_prod_defecto hpd
INNER JOIN cfg_defecto df ON df.Id_defecto    = hpd.Id_defecto
INNER JOIN his_prod    hp ON hp.Id_his_prod   = hpd.Id_his_prod
INNER JOIN cfg_maquina mq ON mq.Id_maquina    = hp.Id_maquina
INNER JOIN cfg_turno   ct ON ct.Id_turno      = hp.Id_turno
WHERE CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?
  AND hpd.Activo = 1
  AND df.esNOK   = 1
  AND df.Desc_defecto = ?
  AND mq.Cod_maquina IN (...)
  /* AND ct.Cod_turno IN (...) */
GROUP BY mq.Cod_maquina, mq.Desc_maquina, DATEPART(HOUR, hp.Fecha_ini)
HAVING SUM(hpd.Unidades) > 0
```

### SQL — rendimiento (pérdidas vs teórico)

Idéntico al SQL actual de `_perdidaRendPorMaquina` pero con `'HOUR'` en lugar de `'DAY'` y agrupando por `DATEPART(HOUR, oee.TimePeriod)`.

```sql
SELECT
    oee.WorkGroup AS cod_maquina,
    mq.Desc_maquina AS maquina,
    DATEPART(HOUR, oee.TimePeriod) AS hora,
    SUM(oee.M) - SUM(oee.M_OKNOK_TEO) AS perdida_seg
FROM F_his_ct('WORKCENTER','HOUR','TURNOS, PRODUCTOS',
              ? + ' 00:00:00', ? + ' 23:59:59', 16) oee
LEFT JOIN cfg_maquina mq ON mq.Cod_maquina = oee.WorkGroup
WHERE CAST(oee.TimePeriod AS DATE) BETWEEN ? AND ?
  AND oee.WorkGroup NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')
  AND oee.Cod_producto IS NOT NULL
  AND oee.Cod_producto <> '--'
  AND (oee.Desc_producto = ? OR oee.Cod_producto = ?)
  AND oee.WorkGroup IN (...)
  /* AND oee.Cod_turno IN (...) */
GROUP BY oee.WorkGroup, mq.Desc_maquina, DATEPART(HOUR, oee.TimePeriod)
HAVING SUM(oee.M) - SUM(oee.M_OKNOK_TEO) > 0
```

**Smoke test obligatorio antes de codificar el resto**: ejecutar manualmente la query anterior contra la BD `mapex`. Si `F_his_ct(...,'HOUR',...)` falla o devuelve 0 filas para un rango con datos conocidos, **se vuelve al spec** para revisar la decisión (no se continúa con la implementación).

### Post-procesado en PHP

Para cada métrica:

1. Ejecutar la query → array `[cod_maquina => [hora => valor]]`.
2. Calcular total por máquina; ordenar DESC.
3. Tomar las 5 primeras como `maquinas` individuales; agregar el resto a `__OTRAS__` (solo si hay ≥6).
4. Construir las 24 filas `horas`, rellenando con 0 los huecos.
5. Convertir segundos→horas (round 2) para D / R / OEE; uds para calidad.

## Cambios en el front — `assets/js/view_oee_unificado.js`

1. Variable global nueva `let chartMotivoHora = null;`.
2. En `cerrarDrillMotivo()` añadir `if (chartMotivoHora) { chartMotivoHora.destroy(); chartMotivoHora = null; }`.
3. En `abrirDrillMotivo()`, después de la llamada existente `renderChartMotivoDet(d.detalle || [], d.metrica, motivoNombre);`, añadir:

   ```js
   renderChartMotivoHora(d.por_hora || null, d.metrica, motivoNombre);
   ```

4. Nueva función `renderChartMotivoHora(porHora, metrica, motivoNombre)`:
   - Si `porHora` es null o `porHora.horas` vacío → `innerHTML = '<div class="drill-down-empty">Sin desglose horario disponible</div>'`, return.
   - Construir `series` = lista de objetos `{ name: maquina.maquina, data: [v0, v1, ..., v23] }` (una por máquina + Otras).
   - Categorías X = `['00','01',...,'23']`.
   - ApexCharts:
     ```js
     {
       chart: { type: 'bar', stacked: true, height: 280, toolbar: { show: false }, fontFamily: 'Arial' },
       plotOptions: { bar: { columnWidth: '70%', borderRadius: 2 } },
       dataLabels: { enabled: false },
       xaxis: { categories: cats, title: { text: 'Hora del día' } },
       yaxis: { title: { text: porHora.unidad === 'h' ? 'Horas' : 'Unidades' },
                labels: { formatter: v => porHora.unidad === 'h' ? v.toFixed(1)+'h' : Math.round(v) } },
       tooltip: { shared: true, intersect: false,
                  y: { formatter: v => porHora.unidad === 'h' ? v.toFixed(2)+' h' : Math.round(v)+' uds' } },
       legend: { position: 'bottom', fontSize: '11px' },
       grid: { borderColor: '#e0e8f0', strokeDashArray: 3 },
       colors: [/* paleta cíclica, "Otras" en gris fijo #9aa7b8 */]
     }
     ```
   - Asignar a `chartMotivoHora` y `.render()`.

5. Sin cambios en el resto del flujo.

## Archivos tocados

| Archivo | Cambio |
|---|---|
| `api/oee_unificado_motivo_drill.php` | Añadir cálculo `por_hora` (3 SQLs) y campo en JSON; función `_porHoraSeccion(...)` |
| `views/oee_unificado.php` | Añadir subtítulo y `<div id="chart-motivo-hora">` dentro de `#motivo-drill-block` |
| `assets/js/view_oee_unificado.js` | Nueva función `renderChartMotivoHora()`, llamada en `abrirDrillMotivo()`, destroy en `cerrarDrillMotivo()`, variable global `chartMotivoHora` |

No se tocan archivos de las vistas congeladas (`grid`, `por_seccion`, `plan_attainment`).

## Casos límite

- **Rango de un solo día**: el chart son las 24 horas de ese día, comportamiento natural.
- **Filtro de turno activo**: las horas fuera del turno aparecen a 0 (la query ya filtra por `Cod_turno`).
- **Una sola máquina con datos**: una sola serie, sin "Otras", sin leyenda confusa.
- **Paro que cruza medianoche**: el SQL de paros genera slots desde la hora de `Fecha_ini`, así que las dos porciones (antes y después de las 00:00) caen en sus buckets correctos al agruparlas por `DATEPART(HOUR, slot_ini)`.
- **Smoke test `F_his_ct(HOUR)` falla**: parar implementación, revisar este spec.

## Plan de verificación manual

Tras implementación:

1. Abrir OEE Unificado, rango "Hoy", todos los turnos → sección VARILLAS → clic en *Disponibilidad* → clic en el motivo con más horas. El chart horario debe mostrar barras en las horas razonables (turno productivo).
2. Cambiar a rango "Mes", repetir → el chart se mantiene en 00–23 (agregado) y muestra valores mayores.
3. Repetir el flujo para *Rendimiento* y *Calidad*. Validar unidades en la Y (h vs uds).
4. Marcar solo turno "M": las horas 14–22 y 22–06 deben quedarse a 0.
5. Cerrar el drill → al abrir otro motivo, el chart anterior debe destruirse limpiamente.

## Fuera de alcance

- Cualquier cambio en las vistas/APIs congeladas.
- Línea de tiempo real (fecha+hora) o vista por turno — la decisión cerrada es hora-del-día agregada.
- Filtro adicional "solo esta máquina" dentro del propio chart horario (ya existe `_maqFiltro` un nivel más arriba; no se propaga aquí porque desvirtúa el "por máquina" del chart).
- Export Excel/PDF del nuevo chart — los exportadores existentes no incluyen el drill de motivo.
