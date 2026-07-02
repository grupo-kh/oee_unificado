# Recálculo de las 4 métricas (D/R/C/OEE) por franja horaria — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el filtro por horas afecte a las 4 métricas (Disp/Rend/Cal/OEE) por máquina y por referencia, recalculándolas desde tablas base cuando el filtro está activo, de forma coherente entre sí.

**Architecture:** Un helper `OeeHorario` produce, desde `his_prod`+`his_prod_paro` filtrando por franja, filas con las MISMAS columnas de magnitudes que F_his_ct (M, M_Teo, M_OKNOK_TEO, M_OK_TEO, PPERF, PCALIDAD, PNP), por máquina o por producto+máquina. `oee_unificado.php` y las funciones de rendimiento de `drill.php` bifurcan: con filtro horario usan el helper; sin él, F_his_ct. Todo pasa por el mismo `_calcDRC`.

**Tech Stack:** PHP 8.1 (MAPEX SQL Server), HTML/JS vanilla. Verificación por curl + navegador.

## Global Constraints

- Idioma de TODO el código y textos visibles: **español (castellano)**.
- El helper produce filas con claves EXACTAS: `cod_maquina, maquina, M, M_Teo, M_OKNOK_TEO, M_OK_TEO, PPERF, PCALIDAD, PNP` (+ `cod_referencia, referencia` en agrupación por producto). `M_Teo`=0 (no afecta a DRC), `PPERF`=`PCALIDAD`=0.
- Recálculo: M = Σ marcha his_prod (franja) − Σ paros (franja); M_OKNOK_TEO=(ok+nok)×ciclo; M_OK_TEO=ok×ciclo; PNP=Σ paros; ciclo=COALESCE(NULLIF(SegCicloNominal,0),3600/Rendimientonominal1,0).
- Filtro horario sobre `Fecha_ini` con `filtroFechaHora()` (helper existente).
- Con filtro horario, las 4 métricas de una pantalla vienen TODAS del recálculo (nunca mezclar con F_his_ct). Pasan por `_calcDRC` → D×R×C=OEE.
- Sin filtro horario: TODO con F_his_ct (exacto por día). No-regresión byte a byte.
- Rendimiento/OEE pueden superar 100% → mostrar tal cual (no capar).
- Máquinas excluidas: `'Improductivos','AUX000','AUXI1','SOLD4','SOLD5'`.
- Tras tocar servidor: `sudo systemctl restart oee-unificado-v2`.
- Acceso: `http://10.0.0.110:8091/oee_unificado_v2.html` (no localhost desde Chrome remoto).

---

## File Structure

- **Create `lib/OeeHorario.php`** — helper de magnitudes por franja horaria.
- **Modify `api/oee_unificado.php`** — bifurca la consulta 1 (KPIs + tabla máquina).
- **Modify `api/oee_unificado_drill.php`** — `_refsRendimiento` y `_motivosRendimiento` bifurcan a recálculo.

---

### Task 1: Helper `OeeHorario::magnitudesPorClave()`

**Files:**
- Create: `lib/OeeHorario.php`
- Test (CLI): `tools/_test_oeehorario.php` (temporal)

**Interfaces:**
- Produces: `OeeHorario::magnitudesPorClave(string $fdesde, string $fhasta, string $hDesde, string $hHasta, array $turnos, array $excl, string $agrupacion): array`
  → filas con claves `cod_maquina, maquina, [cod_referencia, referencia,] M, M_Teo, M_OKNOK_TEO, M_OK_TEO, PPERF, PCALIDAD, PNP`.
  `$agrupacion` ∈ {'maquina','maquina_producto'}.

- [ ] **Step 1: Crear la clase**

```php
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Recálculo de magnitudes OEE por FRANJA HORARIA desde tablas base
 * (his_prod + his_prod_paro), para cuando el filtro por horas está activo.
 * F_his_ct solo agrega por día; his_horaOEE está vacía. Este recálculo es
 * APROXIMADO respecto a F_his_ct (la marcha M no cuadra 1:1) pero COMPARABLE
 * entre franjas. Produce las MISMAS columnas que la consulta F_his_ct del
 * proyecto, para que el resto del código y _calcDRC no cambien.
 * ASUNCIÓN (verificada): PPERF=PCALIDAD=0 en este MAPEX.
 */
class OeeHorario
{
    private const EXCLUIDAS = "'Improductivos','AUX000','AUXI1','SOLD4','SOLD5'";

    public static function magnitudesPorClave(string $fdesde, string $fhasta,
        string $hDesde, string $hHasta, array $turnos, array $excl, string $agrupacion): array
    {
        $porProducto = ($agrupacion === 'maquina_producto');
        [$fFecha, $pFecha] = filtroFechaHora('hp.Fecha_ini', $fdesde, $fhasta, $hDesde, $hHasta);
        [$fParo,  $pParo]  = filtroFechaHora('hpp.Fecha_ini', $fdesde, $fhasta, $hDesde, $hHasta);

        // Filtro de turnos y exclusiones (comunes a ambas consultas).
        $extra = "AND mq.Cod_maquina NOT IN (" . self::EXCLUIDAS . ")";
        $pExtra = [];
        if (!empty($turnos)) {
            $ph = implode(',', array_fill(0, count($turnos), '?'));
            $extra .= " AND ct.Cod_turno IN ($ph)";
            $pExtra = array_merge($pExtra, $turnos);
        }
        if (!empty($excl)) {
            $ph = implode(',', array_fill(0, count($excl), '?'));
            $extra .= " AND mq.Cod_maquina NOT IN ($ph)";
            $pExtra = array_merge($pExtra, $excl);
        }

        // Cadena de joins para producto (solo si agrupamos por producto).
        $joinProd = $porProducto
            ? "LEFT JOIN his_of o ON o.Id_his_of = fa.Id_his_of
               LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto"
            : "";
        $selProd = $porProducto ? "LTRIM(RTRIM(prod.Cod_producto)) AS cod_referencia, MAX(prod.Desc_producto) AS referencia," : "";
        $grpProd = $porProducto ? ", LTRIM(RTRIM(prod.Cod_producto))" : "";
        $joinFase = ($porProducto ? "INNER JOIN his_fase fa ON fa.Id_his_fase = hp.Id_his_fase" : "");

        // 1) Producción: bruto + unidades + ciclo por clave
        $sqlProd = "
            SELECT mq.Cod_maquina AS cod_maquina, mq.Desc_maquina AS maquina,
                   $selProd
                   SUM(DATEDIFF(SECOND, hp.Fecha_ini, ISNULL(hp.Fecha_fin, hp.Fecha_ini))) AS seg_bruto,
                   SUM(ISNULL(hp.Unidades_ok,0))  AS u_ok,
                   SUM(ISNULL(hp.Unidades_nok,0)) AS u_nok,
                   MAX(COALESCE(NULLIF(hp.SegCicloNominal,0),
                        CASE WHEN hp.Rendimientonominal1>0 THEN 3600.0/hp.Rendimientonominal1 END,0)) AS ciclo_seg
            FROM his_prod hp
            INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
            INNER JOIN cfg_turno   ct ON ct.Id_turno   = hp.Id_turno
            $joinFase
            $joinProd
            WHERE $fFecha $extra
            GROUP BY mq.Cod_maquina, mq.Desc_maquina $grpProd";
        $prodRows = fetchAll('mapex', $sqlProd, array_merge($pFecha, $pExtra));

        // 2) Paros por clave (para restar de M y para PNP)
        $sqlParo = "
            SELECT mq.Cod_maquina AS cod_maquina, $selProd
                   SUM(DATEDIFF(SECOND, hpp.Fecha_ini, ISNULL(hpp.Fecha_fin, hpp.Fecha_ini))) AS seg_paro
            FROM his_prod_paro hpp
            INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
            INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
            INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
            $joinFase
            $joinProd
            WHERE $fParo AND hpp.Fecha_fin IS NOT NULL $extra
            GROUP BY mq.Cod_maquina $grpProd";
        $paroMap = [];
        foreach (fetchAll('mapex', $sqlParo, array_merge($pParo, $pExtra)) as $r) {
            $k = $porProducto ? trim((string)$r['cod_maquina']).'|'.trim((string)$r['cod_referencia']) : trim((string)$r['cod_maquina']);
            $paroMap[$k] = (int)$r['seg_paro'];
        }

        // 3) Ensamblar filas con formato F_his_ct
        $out = [];
        foreach ($prodRows as $r) {
            $cod = trim((string)$r['cod_maquina']);
            $ref = $porProducto ? trim((string)$r['cod_referencia']) : '';
            $k   = $porProducto ? "$cod|$ref" : $cod;
            $segParo = $paroMap[$k] ?? 0;
            $M   = max(0, (int)$r['seg_bruto'] - $segParo);
            $ciclo = (float)$r['ciclo_seg'];
            $uOk = (int)$r['u_ok']; $uNok = (int)$r['u_nok'];
            $fila = [
                'cod_maquina'  => $cod,
                'maquina'      => trim((string)$r['maquina']),
                'M'            => $M,
                'M_Teo'        => 0,
                'M_OKNOK_TEO'  => ($uOk + $uNok) * $ciclo,
                'M_OK_TEO'     => $uOk * $ciclo,
                'PPERF'        => 0,
                'PCALIDAD'     => 0,
                'PNP'          => $segParo,
            ];
            if ($porProducto) { $fila['cod_referencia']=$ref; $fila['referencia']=trim((string)$r['referencia']); }
            $out[] = $fila;
        }
        return $out;
    }
}
```

- [ ] **Step 2: Test CLI (comparar con F_his_ct del día)**

```php
<?php
require_once __DIR__ . '/../lib/OeeHorario.php';
$rows = OeeHorario::magnitudesPorClave('2026-07-01','2026-07-01','00:00','23:59',[],[],'maquina');
echo "filas: ".count($rows)."\n";
foreach ($rows as $r) if (in_array($r['cod_maquina'],['DOBL10','DOBL13'])) {
  $D=($r['M']+$r['PNP'])>0?$r['M']/($r['M']+$r['PNP'])*100:0;
  printf("  %-8s M=%-7s PNP=%-6s MONT=%-8s D=%.1f%%\n",$r['cod_maquina'],$r['M'],$r['PNP'],round($r['M_OKNOK_TEO']),$D);
}
```

Run: `php -l lib/OeeHorario.php && php tools/_test_oeehorario.php`
Expected: ~19 filas; DOBL10/DOBL13 con M/PNP plausibles (comparar con la PoC: D≈61-78%). Franja parcial da menos.

- [ ] **Step 3: Commit**

```bash
git add lib/OeeHorario.php tools/_test_oeehorario.php
git commit -m "feat(oee-v2): helper OeeHorario (magnitudes D/R/C/OEE por franja horaria desde tablas base)"
```

---

### Task 2: Bifurcación en `oee_unificado.php` (KPIs + tabla máquina)

**Files:**
- Modify: `api/oee_unificado.php`

**Interfaces:**
- Consumes: `OeeHorario::magnitudesPorClave(...)`.

- [ ] **Step 1: Leer las horas (tras leer turnos, ~línea 54)**

```php
    $horaDesde = (string) getParam('hora_desde', '');
    $horaHasta = (string) getParam('hora_hasta', '');
    $filtroHoras = ($horaDesde !== '' && $horaHasta !== '' && $horaDesde !== $horaHasta);
```

- [ ] **Step 2: Bifurcar la obtención de `$rows` (~línea 100-102)**

```php
    if ($filtroHoras) {
        require_once __DIR__ . '/../lib/OeeHorario.php';
        // Recálculo por franja horaria; mismas columnas que F_his_ct.
        $rows = OeeHorario::magnitudesPorClave($fdesde, $fhasta, $horaDesde, $horaHasta, $turnos, $excl, 'maquina');
    } else {
        $allParams = array_merge([$fdesde, $fhasta], $params);
        $rows = fetchAll('mapex', $sql, $allParams);
    }
```

- [ ] **Step 3: Exponer el filtro en la respuesta (junto a fecha_desde/fecha_hasta)**

```php
        'hora_desde' => $filtroHoras ? $horaDesde : null,
        'hora_hasta' => $filtroHoras ? $horaHasta : null,
```

- [ ] **Step 4: Verificar no-regresión + filtro**

Run: `php -l api/oee_unificado.php`
Run (sin filtro, baseline vs actual):
`curl -s "http://127.0.0.1:8091/api/oee_unificado.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01&turnos=M,T,N" | python3 -c "import sys,json;g=json.load(sys.stdin)['data']['global'];print('D',g['disponibilidad'],'R',g['rendimiento'],'C',g['calidad'],'OEE',g['oee'])"`
Expected: sin hora, mismos KPI que hoy.
Run (con filtro):
`curl -s "http://127.0.0.1:8091/api/oee_unificado.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01&hora_desde=09:00&hora_hasta=14:00" | python3 -c "import sys,json;g=json.load(sys.stdin)['data']['global'];print('D',g['disponibilidad'],'R',g['rendimiento'],'C',g['calidad'],'OEE',g['oee'])"`
Expected: D/R/C/OEE distintos (franja) y coherentes (D×R×C≈OEE).

- [ ] **Step 5: Commit**

```bash
git add api/oee_unificado.php
git commit -m "feat(oee-v2): KPIs y tabla por máquina se recalculan por franja horaria cuando el filtro está activo"
```

---

### Task 3: Rendimiento por hora en `drill.php`

**Files:**
- Modify: `api/oee_unificado_drill.php`

**Interfaces:**
- Consumes: `OeeHorario::magnitudesPorClave(...)`.

- [ ] **Step 1: `_refsRendimiento` — bifurcar a recálculo con filtro horario**

En `_refsRendimiento` (~línea 267), tras leer fdesde/fhasta, detectar horas y, si
hay filtro, obtener las filas (por producto+máquina) del recálculo en vez de
F_his_ct. La consolidación por referencia posterior NO cambia (opera sobre las
mismas columnas). Añadir al inicio de la función:

```php
    $hDesde = (string) getParam('hora_desde', '');
    $hHasta = (string) getParam('hora_hasta', '');
    if ($hDesde !== '' && $hHasta !== '' && $hDesde !== $hHasta) {
        require_once __DIR__ . '/../lib/OeeHorario.php';
        $rows = OeeHorario::magnitudesPorClave($fdesde, $fhasta, $hDesde, $hHasta, $turnos, [], 'maquina_producto');
        // saltar la construcción/ejecución del SQL F_his_ct: usar $rows directamente
    } else {
        // ... (bloque F_his_ct actual que arma $sql y hace $rows = fetchAll(...))
    }
```
NOTA implementación: envolver el bloque actual (que arma `$where`, `$sql` y hace
`$rows = fetchAll(...)`) en el `else`. La parte posterior de consolidación por
referencia (que usa `$rows` con cod_referencia/cod_maquina/M/...) queda fuera del
if/else y funciona igual, porque el helper devuelve esas mismas claves.

- [ ] **Step 2: `_motivosRendimiento` — mismo patrón**

`_motivosRendimiento` (~línea 473+) usa F_his_ct para pérdidas de rendimiento por
máquina. Con filtro horario, obtener las magnitudes por máquina del recálculo:
```php
    $hDesde = (string) getParam('hora_desde', '');
    $hHasta = (string) getParam('hora_hasta', '');
    if ($hDesde !== '' && $hHasta !== '' && $hDesde !== $hHasta) {
        require_once __DIR__ . '/../lib/OeeHorario.php';
        $rows = OeeHorario::magnitudesPorClave($fdesde, $fhasta, $hDesde, $hHasta, $turnos, [], 'maquina');
    } else {
        // ... bloque F_his_ct actual ...
    }
```
Verificar que la parte posterior (cálculo de pérdidas / _calcDRC / formato de
salida) usa las columnas M/M_OKNOK_TEO/M_OK_TEO/PNP que el helper provee. Si
`_motivosRendimiento` produce un desglose que F_his_ct da y el recálculo no
(p.ej. por producto dentro de máquina), documentar que con filtro horario ese
desglose se agrega a nivel máquina.

- [ ] **Step 3: Verificar**

Run: `php -l api/oee_unificado_drill.php`
Run: `curl -s "http://127.0.0.1:8091/api/oee_unificado_drill.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01&metrica=rendimiento&por=referencia&hora_desde=09:00&hora_hasta=14:00" | head -c 200`
Run (sin filtro, no-regresión): mismo sin hora_* → resultado como hoy.
Expected: con filtro, JSON válido y valores de rendimiento acotados a la franja.

- [ ] **Step 4: Commit**

```bash
git add api/oee_unificado_drill.php
git commit -m "feat(oee-v2): rendimiento (por referencia y motivos) se recalcula por franja horaria"
```

---

### Task 4: Verificación de coherencia + limpieza

**Files:**
- Delete: `tools/_test_oeehorario.php`

- [ ] **Step 1: Coherencia D×R×C=OEE con filtro (navegador)**

Navegar a `http://10.0.0.110:8091/oee_unificado_v2.html`, activar filtro horario
09:00-14:00, fecha 01/07. Verificar:
- Los 4 KPI cambian respecto a sin filtro.
- D×R×C ≈ OEE (mismo _calcDRC).
- Tabla por máquina y "por referencia" en las 4 métricas responden a la franja.
- Rendimiento puede >100% (se muestra tal cual).
- Desactivar filtro → vuelve a los valores por día exactos (F_his_ct).

- [ ] **Step 2: Comparabilidad entre franjas**

Comparar 06:00-09:00 vs 09:00-14:00 (mismo día): valores distintos y plausibles;
la suma aproximada de franjas se acerca al día (con el desvío esperado).

- [ ] **Step 3: Eliminar test temporal + commit**

```bash
git rm tools/_test_oeehorario.php
git commit -m "chore(oee-v2): limpieza del test temporal de OeeHorario"
```

---

## Notas de verificación

- **No-regresión (crítico):** filtro OFF ⇒ los 4 KPI y tablas idénticos a hoy (F_his_ct).
- Con filtro: las 4 métricas del MISMO recálculo → D×R×C=OEE coherente; comparables entre franjas.
- Rendimiento/OEE >100% se muestran tal cual (aproximación aceptada).
- Riesgo: `_motivosRendimiento`/`_refsRendimiento` tienen post-proceso propio; verificar
  que consumen las columnas del helper sin romperse (probar con curl con y sin filtro).
- Tras cambios de servidor: `sudo systemctl restart oee-unificado-v2`.
