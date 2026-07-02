# Filtro por horas en el menú principal (vistas de paro) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir al filtro principal un check "Filtrar por horas" (Desde/Hasta) que acote a esa franja las consultas de PAROS de las vistas del dashboard.

**Architecture:** Un helper compartido genera el fragmento WHERE de filtro fecha+hora (con cruce de medianoche). En cada endpoint de paro se aplica ese fragmento sobre `hpp.Fecha_ini` en la consulta de paros. La UI de la cabecera añade el check y `common()` propaga los params. Las partes basadas en F_his_ct (OEE/KPI) no filtran por hora (no es posible) y se avisa.

**Tech Stack:** PHP 8.1 (MAPEX SQL Server), HTML/JS vanilla. Verificación por curl + navegador.

## Global Constraints

- Idioma de TODO el código y textos visibles: **español (castellano)**.
- Filtro horario SOLO sobre `hpp.Fecha_ini` (paros con timestamp real). F_his_ct (OEE) NO filtra por hora — se deja por día y se avisa en la UI.
- El helper vive en `includes/helpers.php`; los endpoints lo llaman.
- Formato hora HH:MM; cruce de medianoche soportado (hDesde > hHasta).
- No-regresión: sin `hora_desde`/`hora_hasta`, cada endpoint devuelve lo mismo que hoy (el helper sin horas = solo el filtro de fecha existente).
- `common()` en oee_unificado_v2.html ya soporta añadir params extra (URLSearchParams p.set).
- Tras tocar servidor: `sudo systemctl restart oee-unificado-v2`.
- Acceso: `http://10.0.0.110:8091/oee_unificado_v2.html` (no localhost desde Chrome remoto).

---

## File Structure

- **Modify `includes/helpers.php`** — nueva función `filtroFechaHora()`.
- **Modify** los 6 endpoints de paro: aplican el helper sobre `hpp.Fecha_ini` en su consulta de paros.
- **Modify `oee_unificado_v2.html`** — check + inputs de hora en la fila de fechas; `common()` añade params; aviso + indicador.

---

### Task 1: Helper `filtroFechaHora()` en helpers.php

**Files:**
- Modify: `includes/helpers.php`
- Test (CLI): `tools/_test_filtro_hora.php` (temporal)

**Interfaces:**
- Produces: `filtroFechaHora(string $col, string $fdesde, string $fhasta, string $hDesde='', string $hHasta=''): array` → `[sqlFragment, paramsArray]`.

- [ ] **Step 1: Añadir la función al final de helpers.php**

```php
if (!function_exists('filtroFechaHora')) {
    /**
     * Fragmento WHERE + params para filtrar una columna datetime por rango de
     * fechas y (opcional) franja horaria. Soporta franja que cruza medianoche.
     * Sin horas válidas → solo filtro de fecha (equivalente al actual).
     * @return array{0:string,1:array}
     */
    function filtroFechaHora(string $col, string $fdesde, string $fhasta, string $hDesde = '', string $hHasta = ''): array
    {
        $horaOk = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hDesde)
               && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hHasta)
               && $hDesde !== $hHasta;
        if (!$horaOk) {
            return ["CAST($col AS DATE) BETWEEN ? AND ?", [$fdesde, $fhasta]];
        }
        $hh = "CONVERT(varchar(5), $col, 108)";
        if ($hDesde < $hHasta) {
            return [
                "(CAST($col AS DATE) BETWEEN ? AND ? AND $hh >= ? AND $hh < ?)",
                [$fdesde, $fhasta, $hDesde, $hHasta],
            ];
        }
        $fdesdeP1 = date('Y-m-d', strtotime($fdesde . ' +1 day'));
        $fhastaP1 = date('Y-m-d', strtotime($fhasta . ' +1 day'));
        return [
            "((CAST($col AS DATE) BETWEEN ? AND ? AND $hh >= ?)"
            . " OR (CAST($col AS DATE) BETWEEN ? AND ? AND $hh < ?))",
            [$fdesde, $fhasta, $hDesde, $fdesdeP1, $fhastaP1, $hHasta],
        ];
    }
}
```

- [ ] **Step 2: Test CLI de los 3 casos**

```php
<?php
require_once __DIR__ . '/../includes/helpers.php';
[$s1,$p1]=filtroFechaHora('hpp.Fecha_ini','2026-07-01','2026-07-01');
[$s2,$p2]=filtroFechaHora('hpp.Fecha_ini','2026-07-01','2026-07-01','06:00','14:00');
[$s3,$p3]=filtroFechaHora('hpp.Fecha_ini','2026-07-01','2026-07-01','22:00','06:00');
echo "sin hora: $s1 | ".json_encode($p1)."\n";
echo "normal:   $s2 | ".json_encode($p2)."\n";
echo "cruza:    $s3 | ".json_encode($p3)."\n";
```

Run: `php -l includes/helpers.php && php tools/_test_filtro_hora.php`
Expected: caso "sin hora" = `CAST(...) BETWEEN ? AND ?` con 2 params; "normal" con 4 params (incluye 06:00/14:00); "cruza" con 6 params.

- [ ] **Step 3: Commit**

```bash
git add includes/helpers.php tools/_test_filtro_hora.php
git commit -m "feat(oee-v2): helper filtroFechaHora (fecha+franja horaria con cruce de medianoche)"
```

---

### Task 2: `oee_unificado_drill.php` — filtro horario en la consulta de motivos

**Files:**
- Modify: `api/oee_unificado_drill.php`

**Interfaces:**
- Consumes: `filtroFechaHora()`.

- [ ] **Step 1: Leer las horas (tras leer fdesde/fhasta, ~línea 41)**

```php
    $horaDesde = (string) getParam('hora_desde', '');
    $horaHasta = (string) getParam('hora_hasta', '');
```

- [ ] **Step 2: Aplicar el helper en la consulta de PAROS (la del GROUP BY cp.Desc_paro, ~línea 213-227)**

Esa consulta hoy filtra la fecha por `hp.Dia_productivo` o dentro de su `$where`.
Localizar el `CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?` (línea ~178) que
alimenta la parte de paros, y para el filtro HORARIO usar `hpp.Fecha_ini`. Como el
filtro horario requiere la hora real del paro, añadir al `$where` de la consulta de
paros el fragmento del helper sobre `hpp.Fecha_ini` cuando lleguen horas:

```php
    // Filtro horario adicional sobre la hora real del paro (solo si viene).
    [$hSql, $hParams] = filtroFechaHora('hpp.Fecha_ini', $fdesde, $fhasta, $horaDesde, $horaHasta);
    // Si hay filtro horario, añadirlo como condición extra en la consulta de paros.
    if ($horaDesde !== '' && $horaHasta !== '' && $horaDesde !== $horaHasta) {
        $whereParos[] = $hSql;                 // $whereParos = array de condiciones de la consulta de paros
        $parosParams  = array_merge($parosParams, $hParams);
    }
```

NOTA de implementación: cada endpoint arma su WHERE de forma distinta. El
implementador debe: (a) localizar la consulta que agrupa paros (`FROM his_prod_paro
hpp`), (b) identificar su array de condiciones y su array de params en orden, (c)
insertar `$hSql`/`$hParams` respetando el orden de los `?`. Si la consulta de paros
NO tiene un `$where` separado (usa string fijo), añadir `" AND $hSql"` al SQL y
`$hParams` a los params en la posición correcta.

- [ ] **Step 3: Verificar**

Run: `php -l api/oee_unificado_drill.php`
Run: `curl -s "http://127.0.0.1:8091/api/oee_unificado_drill.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01&metrica=disponibilidad&hora_desde=06:00&hora_hasta=08:00" | python3 -c "import sys,json;d=json.load(sys.stdin);print('motivos franja:',len(d.get('data',d).get('motivos',[])),'total h:',round(sum(m['horas'] for m in d.get('data',d).get('motivos',[])),1))"`
Run (sin hora, no-regresión): mismo curl sin hora_* → más horas de motivos.
Expected: con franja 06-08, menos horas totales que sin filtro.

- [ ] **Step 4: Commit**

```bash
git add api/oee_unificado_drill.php
git commit -m "feat(oee-v2): drill (motivos generales) filtra paros por franja horaria"
```

---

### Task 3: `oee_unificado_top_analisis.php` — filtro horario

**Files:**
- Modify: `api/oee_unificado_top_analisis.php`

- [ ] **Step 1: Leer horas + aplicar helper en las consultas de paros**

Leer `hora_desde`/`hora_hasta` (tras fdesde/fhasta). En cada consulta que hace
`FROM his_prod_paro hpp` (líneas ~91, ~122), añadir la condición del helper sobre
`hpp.Fecha_ini` cuando lleguen horas (mismo patrón que Task 2). Respetar orden de `?`.

```php
    $horaDesde = (string) getParam('hora_desde', '');
    $horaHasta = (string) getParam('hora_hasta', '');
    [$hSql, $hParams] = filtroFechaHora('hpp.Fecha_ini', $fdesde, $fhasta, $horaDesde, $horaHasta);
    $filtroHoras = ($horaDesde !== '' && $horaHasta !== '' && $horaDesde !== $horaHasta);
    // ... y donde se arma cada consulta de paros: si $filtroHoras, añadir " AND $hSql" + $hParams.
```

- [ ] **Step 2: Verificar**

Run: `php -l api/oee_unificado_top_analisis.php`
Run: `curl -s "http://127.0.0.1:8091/api/oee_unificado_top_analisis.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01&hora_desde=06:00&hora_hasta=08:00" | head -c 200`
Expected: JSON válido; con franja, valores menores que sin filtro.

- [ ] **Step 3: Commit**

```bash
git add api/oee_unificado_top_analisis.php
git commit -m "feat(oee-v2): top análisis filtra paros por franja horaria"
```

---

### Task 4: `oee_unificado_motivo_drill.php` — filtro horario

**Files:**
- Modify: `api/oee_unificado_motivo_drill.php`

- [ ] **Step 1: Leer horas + aplicar helper**

Mismo patrón: leer `hora_desde`/`hora_hasta`, y en cada consulta con `FROM his_prod_paro hpp`
(líneas ~157, ~199+) añadir la condición del helper sobre `hpp.Fecha_ini` cuando
haya horas, respetando el orden de params.

```php
    $horaDesde = (string) getParam('hora_desde', '');
    $horaHasta = (string) getParam('hora_hasta', '');
    [$hSql, $hParams] = filtroFechaHora('hpp.Fecha_ini', $fdesde, $fhasta, $horaDesde, $horaHasta);
    $filtroHoras = ($horaDesde !== '' && $horaHasta !== '' && $horaDesde !== $horaHasta);
```

- [ ] **Step 2: Verificar**

Run: `php -l api/oee_unificado_motivo_drill.php`
Run: `curl -s "http://127.0.0.1:8091/api/oee_unificado_motivo_drill.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01&metrica=disponibilidad&motivo=NO%20JUSTIFICADO&hora_desde=06:00&hora_hasta=08:00" | head -c 200`
Expected: JSON válido.

- [ ] **Step 3: Commit**

```bash
git add api/oee_unificado_motivo_drill.php
git commit -m "feat(oee-v2): motivo drill filtra paros por franja horaria"
```

---

### Task 5: `matriz2`, `maq_motivo_temporal`, `of_paros` — filtro horario

**Files:**
- Modify: `api/oee_unificado_matriz2.php`
- Modify: `api/oee_unificado_maq_motivo_temporal.php`
- Modify: `api/oee_unificado_of_paros.php`

**Interfaces:**
- Consumes: `filtroFechaHora()`.

- [ ] **Step 1: matriz2 — aplicar helper en la consulta de paros**

`matriz2.php` filtra por `CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?` (línea 53)
sobre `his_prod_paro hpp` + `his_prod hp`. Leer horas y AÑADIR la condición horaria
sobre `hpp.Fecha_ini` al `$where` (línea ~53) cuando lleguen horas:
```php
    $horaDesde=(string)getParam('hora_desde',''); $horaHasta=(string)getParam('hora_hasta','');
    if ($horaDesde!=='' && $horaHasta!=='' && $horaDesde!==$horaHasta) {
        [$hSql,$hParams]=filtroFechaHora('hpp.Fecha_ini',$fdesde,$fhasta,$horaDesde,$horaHasta);
        $where[]=$hSql; $params=array_merge($params,$hParams);
    }
```
(Insertar tras construir `$where`/`$params` iniciales y antes de montar el SQL, para
que los params queden en orden. Verificar el orden real en el archivo.)

- [ ] **Step 2: maq_motivo_temporal — igual**

Filtra por `CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?` (línea 80). Añadir la
condición horaria sobre `hpp.Fecha_ini` en su `$where`/params cuando haya horas
(mismo patrón). Ojo: este archivo tiene dos consultas (líneas 68 y 114); aplicar a
la que lista paros por hora.

- [ ] **Step 3: of_paros — igual**

`$w2` (línea 50) lista condiciones de paros. Añadir la condición horaria sobre
`hpp.Fecha_ini` a `$w2` y sus params (`$p2`) cuando haya horas.

- [ ] **Step 4: Verificar los tres**

Run: `php -l api/oee_unificado_matriz2.php && php -l api/oee_unificado_maq_motivo_temporal.php && php -l api/oee_unificado_of_paros.php`
Run: `curl -s "http://127.0.0.1:8091/api/oee_unificado_matriz2.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01&hora_desde=06:00&hora_hasta=08:00" | head -c 200`
Expected: JSON válido en los tres; con franja, menos horas de paro que sin filtro.

- [ ] **Step 5: Commit**

```bash
git add api/oee_unificado_matriz2.php api/oee_unificado_maq_motivo_temporal.php api/oee_unificado_of_paros.php
git commit -m "feat(oee-v2): matriz2, maq_motivo_temporal y of_paros filtran paros por franja horaria"
```

---

### Task 6: UI — check "Filtrar por horas" en la cabecera + aviso

**Files:**
- Modify: `oee_unificado_v2.html`

**Interfaces:**
- Consumes: los endpoints con soporte horario (Tasks 2-5).

- [ ] **Step 1: Añadir check + inputs en la fila de fechas**

Localizar la fila de fechas (Desde/Hasta) en la cabecera (`<div class="filters">`,
donde están `#fDesde`/`#fHasta`). Añadir un grupo:

```html
      <div class="fg"><label>Horario</label>
        <div class="secc-row">
          <label class="chk"><input type="checkbox" id="chkHoras"> Filtrar por horas</label>
          <span id="horasCampos" style="display:none;gap:6px;align-items:center">
            <input type="time" id="hDesde" value="06:00">
            <input type="time" id="hHasta" value="14:00">
          </span>
        </div>
        <small style="font-size:10px;color:#9ca3af">Aplica a los paros; el OEE se calcula por día</small>
      </div>
```

- [ ] **Step 2: Mostrar/ocultar campos y recargar**

En el `<script>`, junto a los listeners de filtros:
```javascript
document.getElementById('chkHoras').addEventListener('change',function(){
  document.getElementById('horasCampos').style.display=this.checked?'inline-flex':'none';
  App.loadMain();
});
['hDesde','hHasta'].forEach(id=>document.getElementById(id).addEventListener('change',()=>{
  if(document.getElementById('chkHoras').checked) App.loadMain();
}));
```

- [ ] **Step 3: Añadir los params en `common()`**

En `common(extra={})` (~línea 584), antes del `return p;`, añadir:
```javascript
    const _ch=document.getElementById('chkHoras');
    if(_ch && _ch.checked){
      const hd=document.getElementById('hDesde').value, hh=document.getElementById('hHasta').value;
      if(hd && hh){ p.set('hora_desde',hd); p.set('hora_hasta',hh); }
    }
```

- [ ] **Step 4: Indicador en la línea RANGO**

En `renderInfo(d)`, tras `iRango.textContent=...`, si el check está activo añadir la
franja al texto:
```javascript
    if(document.getElementById('chkHoras')?.checked){
      iRango.textContent += ` · ${document.getElementById('hDesde').value}–${document.getElementById('hHasta').value}`;
    }
```

- [ ] **Step 5: Verificar en navegador**

Run: `sudo systemctl restart oee-unificado-v2`
Navegar a `http://10.0.0.110:8091/oee_unificado_v2.html`:
- Activar "Filtrar por horas" 06:00-08:00 → la gráfica de motivos y las vistas de
  paro (matriz2, top motivos) reducen sus horas; el OEE global NO cambia (esperado).
- Desactivar → vuelve a los valores por día.
- Indicador de franja visible en la línea RANGO.

- [ ] **Step 6: Commit**

```bash
git add oee_unificado_v2.html
git commit -m "feat(oee-v2): UI del filtro por horas en la cabecera (check + Desde/Hasta + aviso)"
```

---

### Task 7: Limpieza

**Files:**
- Delete: `tools/_test_filtro_hora.php`

- [ ] **Step 1: Eliminar el test temporal**

```bash
git rm tools/_test_filtro_hora.php
```

- [ ] **Step 2: Verificación final de no-regresión**

Run: `curl -s "http://127.0.0.1:8091/api/oee_unificado_drill.php?fecha_desde=2026-07-01&fecha_hasta=2026-07-01&metrica=disponibilidad" | python3 -c "import sys,json;print(len(json.load(sys.stdin).get('data',{}).get('motivos',[])),'motivos (sin filtro)')"`
Expected: mismos motivos que antes del cambio (el filtro OFF no altera nada).

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "chore(oee-v2): limpieza del test temporal del filtro horario"
```

---

## Notas de verificación

- **Riesgo principal:** encajar el fragmento del helper en el WHERE/params de cada
  endpoint respetando el orden de los `?`. Verificar CADA endpoint con curl con y
  sin filtro horario.
- **No-regresión:** filtro OFF ⇒ mismos resultados que hoy (el helper sin horas =
  solo el filtro de fecha).
- El OEE global (F_his_ct) NO filtra por hora (limitación conocida) — la UI lo avisa.
- Tras cambios de servidor: `sudo systemctl restart oee-unificado-v2`.
