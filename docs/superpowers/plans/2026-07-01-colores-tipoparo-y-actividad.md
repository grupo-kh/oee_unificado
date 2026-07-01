# Colores por tipo de paro + actividad en histograma — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Distinguir por color los tipos de paro en la gráfica de motivos generales, y añadir la actividad al tooltip del histograma (con CERRADA en gris claro).

**Architecture:** Dos endpoints añaden una columna cada uno (`tipo_oee` en drill, `actividad` en histograma) reutilizando joins existentes. El frontend colorea barras según tipo y enriquece el tooltip del histograma. Solo colores/tooltip: los valores numéricos no cambian.

**Tech Stack:** PHP 8.1 + PDO sqlsrv (MAPEX), HTML/JS vanilla, Chart.js (gráfica motivos) y ApexCharts (histograma). Verificación por curl + navegador.

## Global Constraints

- Idioma de TODO el código y textos visibles: **español (castellano)**.
- Clasificación de tipo de paro = `cfg_paro.Id_TipoparoOEE` (verificado: 1=Disponibilidad, 2=Paro Planificado; coincide con ConsultaTipoParos.xlsx).
- Colores: Disponibilidad `#2d4d7a`, Paro planificado `#8c181a`, CERRADA `#c3cad3`.
- Match de CERRADA: `motivo.trim().toUpperCase() === 'CERRADA'`, prevalece sobre el tipo.
- `Id_TipoparoOEE` NULL → tratar como Disponibilidad (azul).
- No cambiar valores (horas, %, orden). Solo color/tooltip.
- Tras tocar servidor: `sudo systemctl restart oee-unificado-v2`.
- Acceso: `http://10.0.0.110:8091/oee_unificado_v2.html` (no localhost desde Chrome remoto).

---

## File Structure

- **Modify `api/oee_unificado_drill.php`** — añadir `tipo_oee` por motivo (join cfg_paro ya existe).
- **Modify `api/oee_unificado_hist_maquina.php`** — añadir `actividad` por evento (join cfg_actividad nuevo, his_prod ya presente).
- **Modify `oee_unificado_v2.html`** — colores+leyenda en `renderGeneralMotivos`; actividad y color CERRADA en `renderHistMaqs`.

---

### Task 1: `oee_unificado_drill.php` — añadir `tipo_oee` por motivo

**Files:**
- Modify: `api/oee_unificado_drill.php` (SELECT de motivos ~línea 213-227; salida ~239-245)

**Interfaces:**
- Produces: cada objeto de `motivos[]` incluye `tipo_oee` (int: 1 Disponibilidad, 2 Paro planificado; 0 si NULL).

- [ ] **Step 1: Añadir la columna al SELECT**

En la consulta de motivos (la que hace `GROUP BY cp.Desc_paro`), añadir `MAX(cp.Id_TipoparoOEE)`:

```php
    $sql = "
        SELECT
            cp.Desc_paro AS motivo,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos,
            MAX(cp.Id_TipoparoOEE) AS tipo_oee
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro cp ON cp.Id_paro = hpp.Id_paro
        INNER JOIN his_prod hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
        INNER JOIN cfg_turno ct ON ct.Id_turno = hp.Id_turno
        $extraJoin
        WHERE $whereSQL
        GROUP BY cp.Desc_paro
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
        ORDER BY segundos DESC
    ";
```

- [ ] **Step 2: Exponer `tipo_oee` en la salida**

En el `foreach ($rows as $r)` que construye `$out[]`, añadir el campo:

```php
        $out[] = [
            'motivo'   => $r['motivo'] ?: '(sin nombre)',
            'horas'    => round($seg / 3600, 2),
            'minutos'  => round($seg / 60, 1),
            'pct'      => round($pct, 2),
            'pct_acum' => round(min($acum, 100), 2),
            'tipo_oee' => (int)($r['tipo_oee'] ?? 0),
        ];
```

- [ ] **Step 3: Verificar**

Run: `php -l api/oee_unificado_drill.php`
Expected: sin errores.

Run: `curl -s "http://127.0.0.1:8091/api/oee_unificado_drill.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01" | python3 -c "import sys,json;d=json.load(sys.stdin);[print(m['motivo'],'->',m.get('tipo_oee')) for m in d.get('data',d).get('motivos',[])[:12]]"`
Expected: NO JUSTIFICADO/MICRO PARO → 1; PAUSA/PREVENTIVO/CERRADA → 2.

- [ ] **Step 4: Commit**

```bash
git add api/oee_unificado_drill.php
git commit -m "feat(oee-v2): drill devuelve tipo_oee por motivo (Disponibilidad/Planificado)"
```

---

### Task 2: `renderGeneralMotivos` — color por tipo + leyenda

**Files:**
- Modify: `oee_unificado_v2.html` (`renderGeneralMotivos`, ~línea 692-733)

**Interfaces:**
- Consumes: `motivos[].tipo_oee` (Task 1).

- [ ] **Step 1: Función de color por motivo**

Dentro de `renderGeneralMotivos`, antes de crear el Chart (tras `const vals=...`), añadir:

```javascript
    // Color por tipo de paro OEE: Disponibilidad=azul, Planificado=granate,
    // CERRADA=gris claro (prevalece). tipo_oee: 1=Disp, 2=Planificado.
    const colorMotivo = m => {
      if ((m.motivo||'').trim().toUpperCase() === 'CERRADA') return '#c3cad3';
      return m.tipo_oee === 2 ? '#8c181a' : '#2d4d7a';
    };
    const barColors = motivos.map(colorMotivo);
```

- [ ] **Step 2: Usar el array de colores en el dataset de barras**

Cambiar `backgroundColor:'#2d4d7a'` (línea ~727) por `backgroundColor:barColors`:

```javascript
      {type:'bar',label:this.motivoValLabel(),data:vals,backgroundColor:barColors,yAxisID:'y',order:2,
        datalabels:{anchor:'end',align:'top',color:'#3a2426',formatter:v=>Math.round(v*10)/10}},
```

- [ ] **Step 3: Añadir leyenda de colores bajo el título**

En el template HTML de `renderGeneralMotivos`, tras la línea `</span>` del `mg-title`
(dentro de `.mg-head` o justo bajo `.mg-body`), añadir una leyenda:

```html
          <div class="mg-leyenda" style="display:flex;gap:16px;font-size:11px;margin:6px 0 0">
            <span><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#2d4d7a;margin-right:4px"></i>Disponibilidad</span>
            <span><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#8c181a;margin-right:4px"></i>Paro planificado</span>
            <span><i style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#c3cad3;margin-right:4px"></i>Cerrada</span>
          </div>
```

(Insertar dentro del `generalBody.innerHTML`, entre `</div>` del `mg-head` y el `<div class="mg-body">`.)

- [ ] **Step 4: Verificar en navegador**

Run: `sudo systemctl restart oee-unificado-v2` (opcional para HTML).
Navegar a `http://10.0.0.110:8091/oee_unificado_v2.html`. En "Motivos de paro
generales": barras de PAUSA/PREVENTIVO/PROTOTIPOS en granate, resto (NO
JUSTIFICADO…) en azul, CERRADA en gris claro. Leyenda visible. Valores/orden sin cambios.

- [ ] **Step 5: Commit**

```bash
git add oee_unificado_v2.html
git commit -m "feat(oee-v2): color por tipo de paro + leyenda en motivos generales"
```

---

### Task 3: `oee_unificado_hist_maquina.php` — añadir `actividad` por evento

**Files:**
- Modify: `api/oee_unificado_hist_maquina.php` (SELECT ~205-224; construcción de $eventos ~238-255)

**Interfaces:**
- Produces: cada objeto de `eventos[]` incluye `actividad` (string; '' si NULL).

- [ ] **Step 1: Añadir columna y join al SELECT de tramos**

En el `$sql` de eventos, añadir la columna `ac.Desc_actividad AS actividad` y el
LEFT JOIN a `cfg_actividad` (his_prod `hp` ya está en el FROM):

```php
    $sql = "SELECT TOP $LIMITE_EVENTOS
                   cp.Desc_paro    AS motivo,
                   mq.Cod_maquina  AS cod_maquina,
                   mq.Desc_maquina AS desc_maquina,
                   prod.Cod_producto  AS cod_referencia,
                   prod.Desc_producto AS referencia,
                   ac.Desc_actividad  AS actividad,
                   hpp.Fecha_ini   AS fecha_ini,
                   hpp.Fecha_fin   AS fecha_fin,
                   DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) AS segundos
            FROM his_prod_paro hpp
            INNER JOIN cfg_paro    cp  ON cp.Id_paro     = hpp.Id_paro
            INNER JOIN his_prod    hp  ON hp.Id_his_prod = hpp.Id_his_prod
            INNER JOIN cfg_maquina mq  ON mq.Id_maquina  = hp.Id_maquina
            INNER JOIN cfg_turno   ct  ON ct.Id_turno    = hp.Id_turno
            LEFT  JOIN cfg_actividad ac ON ac.Id_actividad = hp.Id_actividad
            LEFT  JOIN his_fase    fa  ON fa.Id_his_fase = hp.Id_his_fase
            LEFT  JOIN his_of      o   ON o.Id_his_of    = fa.Id_his_of
            LEFT  JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto
            WHERE " . implode(' AND ', $where) . "
              AND DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin) > 0
            ORDER BY hpp.Fecha_ini";
```

- [ ] **Step 2: Exponer `actividad` en $eventos**

En el `foreach ($rows as $r)` que construye `$eventos[]`, añadir:

```php
        $eventos[] = [
            'maquina'     => $nMaq,
            'motivo'      => $mot,
            'referencia'  => $ref,
            'actividad'   => trim((string)($r['actividad'] ?? '')),
            'inicio'      => $ini,
            'fin'         => $fin,
            'segundos'    => $seg,
        ];
```

- [ ] **Step 3: Verificar**

Run: `php -l api/oee_unificado_hist_maquina.php`
Expected: sin errores.

Run: `curl -s "http://127.0.0.1:8091/api/oee_unificado_hist_maquina.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01" | python3 -c "import sys,json;d=json.load(sys.stdin);[print(e['motivo'],'|',e.get('actividad')) for e in d.get('data',d).get('eventos',[])[:8]]"`
Expected: cada evento con su actividad (PRODUCCION, PREPARACION, …).

- [ ] **Step 4: Commit**

```bash
git add api/oee_unificado_hist_maquina.php
git commit -m "feat(oee-v2): histograma devuelve la actividad de cada paro"
```

---

### Task 4: `renderHistMaqs` — actividad en tooltip + CERRADA en gris

**Files:**
- Modify: `oee_unificado_v2.html` (`renderHistMaqs`, ~línea 1728-1752)

**Interfaces:**
- Consumes: `eventos[].actividad` (Task 3).

- [ ] **Step 1: Propagar `actividad` al dato de cada punto de la serie**

En la construcción de `series` (línea ~1737-1738), añadir `actividad` al objeto del punto:

```javascript
      const series=motivos.map(m=>({name:m,data:byMot[m].map(e=>({
        x:e.maquina,y:[this.ts(e.inicio),this.ts(e.fin)],inicio:e.inicio,fin:e.fin,
        segundos:e.segundos,actividad:e.actividad||''}))}));
```

- [ ] **Step 2: Color de serie con CERRADA en gris**

Cambiar `colors:motivos.map((m,i)=>PALETTE[i%PALETTE.length])` (línea ~1744) por
una función que respete CERRADA:

```javascript
        series, colors:motivos.map((m,i)=> (m||'').trim().toUpperCase()==='CERRADA' ? '#c3cad3' : PALETTE[i%PALETTE.length]),
```

- [ ] **Step 3: Añadir actividad al tooltip**

En el `tooltip.custom` (línea ~1747-1750), añadir la línea de actividad cuando exista:

```javascript
        tooltip:{custom:({seriesIndex,dataPointIndex,w})=>{
          const p=w.config.series[seriesIndex].data[dataPointIndex];
          const act=p.actividad?`<br>Actividad: <b>${p.actividad}</b>`:'';
          return `<div style="padding:8px;font-size:12px"><b>${w.config.series[seriesIndex].name}</b><br>${p.x}<br>${p.inicio} → ${p.fin}<br><b>${(p.segundos/60).toFixed(1)} min</b>${act}</div>`;
        }}
```

- [ ] **Step 4: Verificar en navegador**

Navegar a `http://10.0.0.110:8091/oee_unificado_v2.html` → menú Opciones →
Histograma. Pasar el ratón por un tramo: el tooltip muestra "Actividad: …". Los
tramos con motivo CERRADA aparecen en gris claro.

- [ ] **Step 5: Commit**

```bash
git add oee_unificado_v2.html
git commit -m "feat(oee-v2): actividad en tooltip del histograma + CERRADA en gris claro"
```

---

## Notas de verificación

- **No-regresión:** los valores de motivos (horas, %) y del histograma (tramos,
  duraciones) NO cambian; solo color y tooltip.
- Contrastar `tipo_oee` con el Excel: PAUSA/PREVENTIVO/PROTOTIPOS/MEJORA/CERRADA → 2.
- Visual por `http://10.0.0.110:8091/oee_unificado_v2.html` (no localhost).
- Tras cambios de servidor: `sudo systemctl restart oee-unificado-v2`.
