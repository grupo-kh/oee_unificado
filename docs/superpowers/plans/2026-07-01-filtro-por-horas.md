# Filtro por horas (recálculo OEE desde tablas base) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir acotar el OEE (KPIs de cabecera + tabla de máquinas) por franja horaria, recalculando D/R/C/OEE desde tablas base cuando el filtro está activo, sin alterar el comportamiento por día actual.

**Architecture:** Un motor nuevo `lib/OeeHorario.php` produce las MISMAS columnas por máquina que `F_his_ct` (M, M_Teo, M_OKNOK_TEO, M_OK_TEO, PPERF, PCALIDAD, PNP). `oee_unificado.php` bifurca: si llegan `hora_desde`+`hora_hasta`, toma `$rows` del motor; si no, de `F_his_ct` (intacto). Todo el resto del endpoint (global/secciones/tabla) queda sin cambios porque opera sobre `$rows`.

**Tech Stack:** PHP 8.1 + PDO sqlsrv (MAPEX `mapexbp_Test`), HTML/JS vanilla. Verificación por curl + navegador; no hay tests automatizados.

## Global Constraints

- Idioma de TODO el código y textos visibles: **español (castellano)**.
- Reutilizar `_calcDRC()` de `api/oee_unificado.php` (o su equivalente en helpers) para que la fórmula sea idéntica a la vista por día. NO reimplementar la fórmula.
- Con el filtro OFF (sin `hora_desde`/`hora_hasta`), el endpoint debe devolver JSON **byte-idéntico** al actual (no-regresión).
- `PPERF = 0` y `PCALIDAD = 0` en este MAPEX (verificado); el motor los emite como 0 y deja comentario señalando la asunción.
- Filtrar por `Fecha_ini` real (NUNCA `Dia_productivo`).
- Formato hora HH:MM, regex `^([01]\d|2[0-3]):[0-5]\d$`. Cruce de medianoche si desde > hasta.
- Máquinas excluidas (igual que hoy): `'Improductivos','AUX000','AUXI1','SOLD4','SOLD5'`.
- Tras tocar servidor: `sudo systemctl restart oee-unificado-v2`.
- Acceso: `http://10.0.0.110:8091/oee_unificado_v2.html` (no localhost desde Chrome remoto).

---

## File Structure

- **Create `lib/OeeHorario.php`** — motor de recálculo. Método `OeeHorario::filasPorMaquina(string $fdesde, string $fhasta, string $horaDesde, string $horaHasta, array $turnos): array` que devuelve filas con las columnas de F_his_ct.
- **Modify `api/oee_unificado.php`** — bifurcación de la fuente de `$rows`.
- **Modify `oee_unificado_v2.html`** — checkbox + inputs de hora + params en las peticiones.

---

### Task 1: Motor `OeeHorario::filasPorMaquina()`

**Files:**
- Create: `lib/OeeHorario.php`
- Test (CLI): `tools/_oee_horario_smoke.php` (temporal)

**Interfaces:**
- Produces: `OeeHorario::filasPorMaquina($fdesde,$fhasta,$horaDesde,$horaHasta,$turnos): array`
  → filas `[ ['cod_maquina','maquina','M','M_Teo','M_OKNOK_TEO','M_OK_TEO','PPERF','PCALIDAD','PNP'], ... ]`
  (mismas claves que las que hoy salen del SELECT de F_his_ct en oee_unificado.php).

- [ ] **Step 1: Crear la clase con el cálculo desde tablas base**

```php
<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Motor de recálculo D/R/C/OEE por FRANJA HORARIA desde tablas base
 * (his_prod + his_prod_paro), para cuando el filtro por horas está activo.
 *
 * F_his_ct solo agrega por día y no desglosa por hora (verificado); por eso el
 * filtro horario necesita este recálculo. Produce las MISMAS columnas por
 * máquina que el SELECT de F_his_ct en oee_unificado.php, de modo que el resto
 * del endpoint (global/secciones/tabla) no cambia.
 *
 * ASUNCIÓN (verificada en este MAPEX, semana 24/06-01/07): PPERF = 0 y
 * PCALIDAD = 0 siempre. Se emiten como 0. Si algún día MAPEX los poblara,
 * R y C se desviarían y habría que revisar este motor.
 */
class OeeHorario
{
    private const EXCLUIDAS = "'Improductivos','AUX000','AUXI1','SOLD4','SOLD5'";

    /**
     * Filas por máquina con las magnitudes teóricas, filtradas por rango de
     * fechas + franja horaria. horaDesde/horaHasta en HH:MM.
     */
    public static function filasPorMaquina(string $fdesde, string $fhasta,
        string $horaDesde, string $horaHasta, array $turnos): array
    {
        // Minutos desde medianoche de la franja
        $mIni = self::hhmmAMin($horaDesde);
        $mFin = self::hhmmAMin($horaHasta);
        $cruza = $mIni > $mFin; // franja que cruza medianoche

        // Condición de franja sobre un campo datetime dado (placeholder %F%)
        $franja = $cruza
            ? "((DATEPART(HOUR,%F%)*60 + DATEPART(MINUTE,%F%)) >= $mIni
                OR (DATEPART(HOUR,%F%)*60 + DATEPART(MINUTE,%F%)) < $mFin)"
            : "((DATEPART(HOUR,%F%)*60 + DATEPART(MINUTE,%F%)) >= $mIni
                AND (DATEPART(HOUR,%F%)*60 + DATEPART(MINUTE,%F%)) < $mFin)";

        // Filtro de turnos opcional (por Id_turno via cfg_turno) — se aplica igual
        // que en el resto del proyecto sólo si vienen turnos concretos.
        // (Turnos se filtran por hora ya implícitamente; aquí mantenemos simple:
        //  el filtro horario prevalece. Turnos no se re-aplican en el recálculo.)

        // 1) Marcha + unidades + ciclo por máquina
        $franjaHp = str_replace('%F%', 'hp.Fecha_ini', $franja);
        $sqlProd = "
            SELECT mq.Cod_maquina AS cod_maquina, mq.Desc_maquina AS maquina,
                   SUM(DATEDIFF(SECOND, hp.Fecha_ini, ISNULL(hp.Fecha_fin, hp.Fecha_ini))) AS seg_bruto,
                   SUM(ISNULL(hp.Unidades_ok,0))  AS u_ok,
                   SUM(ISNULL(hp.Unidades_nok,0)) AS u_nok,
                   MAX(COALESCE(NULLIF(hp.SegCicloNominal,0),
                        CASE WHEN hp.Rendimientonominal1 > 0 THEN 3600.0/hp.Rendimientonominal1 END, 0)) AS ciclo_seg
            FROM his_prod hp
            INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
            WHERE CAST(hp.Fecha_ini AS DATE) BETWEEN ? AND ?
              AND $franjaHp
              AND mq.Cod_maquina NOT IN (" . self::EXCLUIDAS . ")
            GROUP BY mq.Cod_maquina, mq.Desc_maquina";
        $prod = [];
        foreach (fetchAll('mapex', $sqlProd, [$fdesde, $fhasta]) as $r) {
            $prod[trim((string)$r['cod_maquina'])] = $r;
        }

        // 2) Paros por máquina (mismo rango + franja)
        $franjaP = str_replace('%F%', 'hpp.Fecha_ini', $franja);
        $sqlParo = "
            SELECT mq.Cod_maquina AS cod_maquina,
                   SUM(DATEDIFF(SECOND, hpp.Fecha_ini, ISNULL(hpp.Fecha_fin, hpp.Fecha_ini))) AS seg_paro
            FROM his_prod_paro hpp
            INNER JOIN his_prod hp    ON hp.Id_his_prod = hpp.Id_his_prod
            INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
            WHERE CAST(hpp.Fecha_ini AS DATE) BETWEEN ? AND ?
              AND $franjaP
              AND hpp.Fecha_fin IS NOT NULL
              AND mq.Cod_maquina NOT IN (" . self::EXCLUIDAS . ")
            GROUP BY mq.Cod_maquina";
        $paros = [];
        foreach (fetchAll('mapex', $sqlParo, [$fdesde, $fhasta]) as $r) {
            $paros[trim((string)$r['cod_maquina'])] = (int)$r['seg_paro'];
        }

        // 3) Ensamblar filas con las columnas de F_his_ct
        $filas = [];
        foreach ($prod as $cod => $r) {
            $segBruto = (int)$r['seg_bruto'];
            $segParo  = $paros[$cod] ?? 0;
            $M   = max(0, $segBruto - $segParo);   // marcha neta = bruto - paro
            $PNP = $segParo;
            $uOk = (int)$r['u_ok']; $uNok = (int)$r['u_nok'];
            $ciclo = (float)$r['ciclo_seg'];
            $mOkNokTeo = ($uOk + $uNok) * $ciclo;
            $mOkTeo    = $uOk * $ciclo;
            $filas[] = [
                'cod_maquina'  => $cod,
                'maquina'      => trim((string)$r['maquina']),
                'M'            => $M,
                'M_Teo'        => $M + $PNP,          // tiempo teórico total (informativo)
                'M_OKNOK_TEO'  => $mOkNokTeo,
                'M_OK_TEO'     => $mOkTeo,
                'PPERF'        => 0,                  // 0 en este MAPEX (verificado)
                'PCALIDAD'     => 0,
                'PNP'          => $PNP,
            ];
        }
        return $filas;
    }

    private static function hhmmAMin(string $hhmm): int
    {
        [$h,$m] = array_map('intval', explode(':', $hhmm));
        return $h * 60 + $m;
    }
}
```

- [ ] **Step 2: Script de verificación CLI**

```php
<?php
// tools/_oee_horario_smoke.php — verificación del motor por horas (temporal)
require_once __DIR__ . '/../lib/OeeHorario.php';
$dia = '2026-07-01';
echo "=== Día completo 00:00-23:59 (debe aproximar a F_his_ct) ===\n";
$filas = OeeHorario::filasPorMaquina($dia, $dia, '00:00', '23:59', []);
foreach ($filas as $f) {
    $D = ($f['M']+$f['PNP'])>0 ? $f['M']/($f['M']+$f['PNP'])*100 : 0;
    printf("  %-14s M=%-8s PNP=%-6s OK_TEO=%-10s D=%.1f%%\n",
        $f['cod_maquina'], $f['M'], $f['PNP'], round($f['M_OK_TEO']), $D);
}
echo "\n=== Franja 06:00-14:00 ===\n";
$f2 = OeeHorario::filasPorMaquina($dia, $dia, '06:00', '14:00', []);
echo "  máquinas con datos: " . count($f2) . "\n";
```

- [ ] **Step 3: Ejecutar y comparar con F_his_ct**

Run: `php -l lib/OeeHorario.php && php tools/_oee_horario_smoke.php`
Expected: día completo muestra M/PNP/D por máquina; contrastar DOBL10 con F_his_ct
(del spike: M≈36450, PNP≈235, D≈99.36%). Franja parcial da menos máquinas/valores.

- [ ] **Step 4: Commit**

```bash
git add lib/OeeHorario.php tools/_oee_horario_smoke.php
git commit -m "feat(oee-v2): motor de recálculo OEE por franja horaria desde tablas base"
```

---

### Task 2: Bifurcación en `api/oee_unificado.php`

**Files:**
- Modify: `api/oee_unificado.php`

**Interfaces:**
- Consumes: `OeeHorario::filasPorMaquina(...)`.
- El resto del endpoint (acumulación global/sección, `_calcDRC`, `$maqList`) NO cambia: sigue operando sobre `$rows`.

- [ ] **Step 1: Leer y validar los params de hora (tras leer turnos)**

Añadir tras la línea `$turnos = array_values(...)` (~línea 54):

```php
    // Filtro por horas opcional (recálculo desde tablas base). Requiere ambos.
    $horaDesde = (string) getParam('hora_desde', '');
    $horaHasta = (string) getParam('hora_hasta', '');
    $filtroHoras = (
        preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaDesde) &&
        preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaHasta) &&
        $horaDesde !== $horaHasta
    );
```

- [ ] **Step 2: Bifurcar la obtención de `$rows`**

Localizar donde hoy se ejecuta `$rows = fetchAll('mapex', $sql, $allParams);`
(~línea 102) y envolverlo:

```php
    if ($filtroHoras) {
        require_once __DIR__ . '/../lib/OeeHorario.php';
        // Recálculo por franja horaria desde tablas base. Devuelve las mismas
        // columnas que el SELECT de F_his_ct, así que el resto del código sigue igual.
        $rows = OeeHorario::filasPorMaquina($fdesde, $fhasta, $horaDesde, $horaHasta, $turnos);
    } else {
        // Los dos primeros params del F_his_ct son fdesde/fhasta también
        $allParams = array_merge([$fdesde, $fhasta], $params);
        $rows = fetchAll('mapex', $sql, $allParams);
    }
```

- [ ] **Step 3: Exponer el filtro horario en la respuesta (para el front)**

Localizar el array final de respuesta (donde están `'fecha_desde'`, `'fecha_hasta'`)
y añadir:

```php
        'hora_desde'  => $filtroHoras ? $horaDesde : null,
        'hora_hasta'  => $filtroHoras ? $horaHasta : null,
```

- [ ] **Step 4: Verificar NO-REGRESIÓN (crítico)**

ANTES de este task se debió capturar el baseline. Ejecutar:

Run:
```
php -l api/oee_unificado.php
curl -s "http://127.0.0.1:8091/api/oee_unificado.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01&turnos=M,T,N" > /tmp/oee_despues.json
diff <(curl -s "http://127.0.0.1:8091/api/oee_unificado.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01&turnos=M,T,N") /tmp/oee_despues.json && echo "ESTABLE"
```
Expected: sin `hora_desde`/`hora_hasta`, la respuesta es la misma que antes de tocar
(salvo los dos campos nuevos `hora_desde:null`/`hora_hasta:null`, que son aditivos).
Comparar los KPI globales con la versión previa (deben ser idénticos).

- [ ] **Step 5: Verificar la rama por horas**

Run:
```
curl -s "http://127.0.0.1:8091/api/oee_unificado.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01&hora_desde=00:00&hora_hasta=23:59" | python3 -c "import sys,json;d=json.load(sys.stdin);g=d.get('data',d)['global'];print('D',g['disponibilidad'],'R',g['rendimiento'],'C',g['calidad'],'OEE',g['oee'])"
```
Expected: D/R/C/OEE plausibles y cercanos a los del día completo por F_his_ct.

- [ ] **Step 6: Commit**

```bash
git add api/oee_unificado.php
git commit -m "feat(oee-v2): oee_unificado bifurca a recálculo horario cuando llega hora_desde/hora_hasta"
```

---

### Task 3: UI del filtro por horas en `oee_unificado_v2.html`

**Files:**
- Modify: `oee_unificado_v2.html`

**Interfaces:**
- Consumes: params `hora_desde`/`hora_hasta` en las peticiones de KPIs y tabla de máquinas.

- [ ] **Step 1: Añadir el check + inputs en el filtro principal**

Localizar el bloque `<div class="turno-row">` (donde están los checks de turno) e
insertar después un bloque de horas:

```html
    <div class="turno-row" id="horasRow">
      <label class="chk"><input type="checkbox" id="chkHoras"> Filtrar por horas</label>
      <span id="horasCampos" style="display:none;align-items:center;gap:8px">
        <label>Desde <input type="time" id="hDesde" value="06:00"></label>
        <label>Hasta <input type="time" id="hHasta" value="14:00"></label>
      </span>
    </div>
```

- [ ] **Step 2: Mostrar/ocultar los inputs y recargar al cambiar**

Añadir en el `<script>` (junto a los listeners de filtros existentes):

```javascript
document.getElementById('chkHoras').addEventListener('change', function(){
  document.getElementById('horasCampos').style.display = this.checked ? 'inline-flex' : 'none';
  App.loadMain();   // recarga KPIs + tabla (loadMain llama a loadMachines)
});
['hDesde','hHasta'].forEach(id=>document.getElementById(id).addEventListener('change', ()=>{
  if(document.getElementById('chkHoras').checked) App.loadMain();
}));
```

Verificado: la recarga principal es `App.loadMain()` (línea ~599), que renderiza
KPIs (`renderKpis`) y luego `loadMachines()`. Ambas usan `common()`, así que los
params de hora llegan a KPIs y tabla automáticamente.

- [ ] **Step 3: Añadir los params de hora a las peticiones**

`common(extra={})` está en la línea ~584. Dentro, al construir el objeto de
parámetros, añadir el filtro horario (antes del `return`/merge con `extra`):

```javascript
    // Filtro por horas: sólo si el check está activo y ambas horas presentes.
    const _chk = document.getElementById('chkHoras');
    if (_chk && _chk.checked) {
      const hd = document.getElementById('hDesde').value, hh = document.getElementById('hHasta').value;
      if (hd && hh) { p.set('hora_desde', hd); p.set('hora_hasta', hh); }
    }
```

(Ajustar `p.set(...)` al mecanismo real de `common()` — si construye
`URLSearchParams`, usar `.set`; si es objeto, asignar propiedades. Verificado que
`common()` YA acepta `hora_desde/hora_hasta` en otra llamada del archivo —línea
~1220—, así que el patrón existe.) Como `loadMain` y `loadMachines` usan
`common()`, el filtro llega a KPIs y tabla sin más cambios.

- [ ] **Step 4: Indicador visual del filtro activo**

En la línea de resumen (RANGO … SECCIÓN … TURNOS …), añadir tras cargar datos: si
la respuesta trae `hora_desde`, mostrar "· HORAS hh:mm–hh:mm". Localizar dónde se
pinta esa línea (`grep -n "RANGO\|iRango\|resumen" oee_unificado_v2.html`) y añadir
el fragmento condicional usando `data.hora_desde`/`data.hora_hasta`.

- [ ] **Step 5: Verificar en navegador**

Run: `sudo systemctl restart oee-unificado-v2`
Navegar a `http://10.0.0.110:8091/oee_unificado_v2.html`:
- Con el check OFF: KPIs y tabla como siempre.
- Activar check, Desde 06:00 Hasta 14:00: KPIs y tabla cambian; indicador muestra la franja.
- Probar 22:00–06:00 (cruce medianoche): no rompe.
- Desactivar: vuelve a los valores por día.

- [ ] **Step 6: Commit**

```bash
git add oee_unificado_v2.html
git commit -m "feat(oee-v2): UI del filtro por horas (check + Desde/Hasta) en el filtro principal"
```

---

### Task 4: Limpieza y validación final

**Files:**
- Delete: `tools/_oee_horario_smoke.php`

- [ ] **Step 1: Eliminar el script temporal**

```bash
git rm tools/_oee_horario_smoke.php
```

- [ ] **Step 2: Validación de no-regresión definitiva**

Run:
```
php -l lib/OeeHorario.php && php -l api/oee_unificado.php
curl -s "http://127.0.0.1:8091/api/oee_unificado.php?fecha_desde=2026-06-30&fecha_hasta=2026-06-30&turnos=M" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('data',d)['global']['oee'])"
```
Expected: coincide con el OEE que daba antes del cambio para ese día/turno.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "chore(oee-v2): limpieza del script temporal del filtro por horas"
```

---

## Notas de verificación

- **No-regresión es la prueba número uno:** filtro OFF ⇒ mismos números que hoy.
- Contrastar el motor a día completo con F_his_ct (spike: DOBL10 D≈99.36%).
- Visual en `http://10.0.0.110:8091/oee_unificado_v2.html` (no localhost).
- Tras cambios de servidor: `sudo systemctl restart oee-unificado-v2`.
- El filtro horario aplica SOLO a KPIs de cabecera + tabla de máquinas (alcance).
