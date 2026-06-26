# Evolución de motivos de disponibilidad — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir un formulario nuevo con un gráfico de línea temporal de horas de paro por motivo de disponibilidad (escala día/semana/mes), con lista de motivos por peso y popup de reparto por máquina al clicar un punto.

**Architecture:** Dos endpoints PHP nuevos en `api/` (única fuente de verdad, como Matriz 2) + un formulario integrado en `oee_unificado_v2.html` que reutiliza los helpers existentes (`common`, `get`, `apex`, `barList`, shell `fullpop`). El Endpoint 1 alimenta lista y gráfico en una sola llamada; el Endpoint 2 alimenta el popup de reparto por máquina.

**Tech Stack:** PHP 8 (SQL Server vía `fetchAll('mapex', ...)`), JavaScript vanilla, ApexCharts (ya cargado), patrón de helpers de `oee_unificado_v2.html`.

## Global Constraints

- Todo el código, comentarios y textos visibles en **español (castellano)**. Nunca portugués.
- Filtros de paro idénticos a Matriz 2: `cp.Cod_paro <> 11`, `cp.Id_actividad <> 1`, `hpp.Fecha_fin IS NOT NULL`.
- Sección vía `PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$desc_maquina] === $seccion`.
- Helpers PHP existentes: `getParam`, `getListParam`, `jsonOk($data)`, `jsonError($msg,$code)`, `fetchAll('mapex',$sql,$params)`. Incluir siempre `config/database.php`, `includes/helpers.php`, `lib/PlanAttainmentAgg.php`.
- Granularidad: valores `day` | `week` | `month`. Bucket SQL sobre `hp.Dia_productivo`.
- Horas = `SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) / 3600`, redondeado a 2 decimales.
- No tocar `.gitignore` ni otras configuraciones como efecto colateral. El spec y el plan se versionan con `git add -f`.
- Commits sin `Co-Authored-By`.

---

## File Structure

- **Create** `api/oee_unificado_motivos_evolucion.php` — Endpoint 1: lista de motivos + series temporales por bucket.
- **Create** `api/oee_unificado_motivo_periodo_maquinas.php` — Endpoint 2: reparto por máquina de un motivo en un bucket.
- **Modify** `oee_unificado_v2.html` — botón de entrada, CSS mínimo y métodos `openEvolMotivos` / `evolMotRender` / `evolMotChart` / `evolMotMaquinas`.

---

### Task 1: Endpoint 1 — lista de motivos + series temporales

**Files:**
- Create: `api/oee_unificado_motivos_evolucion.php`
- Test: sonda CLI `scripts/probe_evol_motivos.php` (temporal, no se versiona)

**Interfaces:**
- Consumes: `fetchAll`, `getParam`, `getListParam`, `jsonOk`, `jsonError`, `PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT`.
- Produces: GET endpoint que devuelve `{granularidad, seccion, buckets:[{key,label}], motivos:[{motivo,total_horas,serie:[{key,horas}]}]}`. La función interna reutilizable es `motivosEvolucionData(): array`.

- [ ] **Step 1: Escribir la sonda que falla**

Crear `scripts/probe_evol_motivos.php`:

```php
<?php
$_GET['fecha_desde']  = $argv[1] ?? '2025-01-01';
$_GET['fecha_hasta']  = $argv[2] ?? '2025-03-31';
$_GET['seccion']      = $argv[3] ?? 'VARILLAS';
$_GET['granularidad'] = $argv[4] ?? 'day';
require '/home/aistudio/oee_unificado/api/oee_unificado_motivos_evolucion.php';
$d = motivosEvolucionData();

// 1) buckets continuos según granularidad
$g = $d['granularidad'];
$keys = array_column($d['buckets'], 'key');
$cont = true;
for ($i = 1; $i < count($keys); $i++) {
    $prev = new DateTime($keys[$i-1]); $cur = new DateTime($keys[$i]);
    $prev->modify($g === 'day' ? '+1 day' : ($g === 'week' ? '+7 days' : '+1 month'));
    if ($prev->format('Y-m-d') !== $cur->format('Y-m-d')) { $cont = false; break; }
}
echo "Buckets (".count($keys).", $g): ".($cont ? "✅ continuos" : "❌ DISCONTINUOS")."\n";

// 2) motivos ordenados por peso desc
$prev = INF; $ord = true;
foreach ($d['motivos'] as $m) { if ($m['total_horas'] > $prev + 1e-9) { $ord = false; break; } $prev = $m['total_horas']; }
echo "Motivos (".count($d['motivos'])."): ".($ord ? "✅ peso desc" : "❌ FUERA DE ORDEN")."\n";

// 3) cada serie tiene 1 punto por bucket
$okLen = true;
foreach ($d['motivos'] as $m) if (count($m['serie']) !== count($keys)) { $okLen = false; break; }
echo "Series alineadas con buckets: ".($okLen ? "✅" : "❌")."\n";

// 4) total_horas == suma de la serie
$okSum = true;
foreach ($d['motivos'] as $m) {
    $s = 0; foreach ($m['serie'] as $p) $s += $p['horas'];
    if (abs($s - $m['total_horas']) > 0.05) { $okSum = false; echo "  desc {$m['motivo']}: serie=$s total={$m['total_horas']}\n"; }
}
echo "total_horas == Σ serie: ".($okSum ? "✅" : "❌")."\n";

echo "\nTop 3 motivos:\n";
foreach (array_slice($d['motivos'], 0, 3) as $m) printf("  %-28s %8.2f h\n", $m['motivo'], $m['total_horas']);
```

- [ ] **Step 2: Ejecutar la sonda y verificar que falla**

Run: `php scripts/probe_evol_motivos.php`
Expected: FAIL — `require(...oee_unificado_motivos_evolucion.php): Failed to open stream` (el endpoint aún no existe).

- [ ] **Step 3: Crear el endpoint**

Crear `api/oee_unificado_motivos_evolucion.php`:

```php
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Evolución temporal de motivos de paro (disponibilidad).
 *
 * Para el rango/sección/turnos dados, agrega las horas de paro por MOTIVO
 * (cp.Desc_paro) y por BUCKET temporal (día/semana/mes, elegido por el usuario).
 * Devuelve, en una sola llamada, la lista de motivos (ordenada por peso = horas
 * totales desc) y la serie temporal de cada motivo (un punto por bucket, con 0
 * en los buckets sin datos para que la línea sea continua).
 *
 * Filtros idénticos a Matriz 2: excluye paro 11 (CERRADA) y actividad 1 (CERRADA).
 *
 * GET: fecha_desde, fecha_hasta (req), seccion (VARILLAS|TROQUELADOS|''),
 *      turnos (CSV M,T,N), granularidad (day|week|month, req).
 */
function motivosEvolucionData(): array
{
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');
    $seccion = strtoupper((string) getParam('seccion', ''));
    $gran = (string) getParam('granularidad', 'day');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) throw new Exception('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) throw new Exception('fecha_hasta inválida');
    if ($fdesde > $fhasta) throw new Exception('fecha_desde no puede ser posterior a fecha_hasta');
    if (!in_array($gran, ['day','week','month'], true)) throw new Exception('granularidad inválida (day|week|month)');
    if ($seccion !== '' && !in_array($seccion, ['VARILLAS','TROQUELADOS'], true)) throw new Exception('seccion inválida');
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    // Bucket SQL según granularidad (mismo patrón que oee_unificado_evolucion.php),
    // pero sobre hp.Dia_productivo (el campo de los paros).
    if ($gran === 'day') {
        $bucketSQL = "CAST(hp.Dia_productivo AS DATE)";
    } elseif ($gran === 'week') {
        $bucketSQL = "DATEADD(WEEK, DATEDIFF(WEEK, 0, hp.Dia_productivo), 0)";
    } else {
        $bucketSQL = "DATEADD(MONTH, DATEDIFF(MONTH, 0, hp.Dia_productivo), 0)";
    }

    $where  = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "cp.Id_actividad <> 1",
        "hpp.Fecha_fin IS NOT NULL",
    ];
    $params = [$fdesde, $fhasta];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    $sql = "
        SELECT
            $bucketSQL AS bucket_start,
            COALESCE(NULLIF(LTRIM(RTRIM(cp.Desc_paro)), ''), '--') AS motivo,
            mq.Desc_maquina AS maquina,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY $bucketSQL, cp.Desc_paro, mq.Desc_maquina
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
    ";
    $rows = fetchAll('mapex', $sql, $params);

    // Acumular por motivo y bucket, filtrando por sección en PHP (igual que Matriz 2).
    $porMotivo = [];   // motivo => [bucketKey => horas]
    $pesoMotivo = [];  // motivo => horas totales
    foreach ($rows as $r) {
        $maq = (string) $r['maquina'];
        if ($seccion !== '' && (PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$maq] ?? null) !== $seccion) continue;
        $motivo = (string) $r['motivo'];
        $bk = substr((string) $r['bucket_start'], 0, 10);
        $h = (int) $r['segundos'] / 3600.0;
        $porMotivo[$motivo][$bk] = ($porMotivo[$motivo][$bk] ?? 0) + $h;
        $pesoMotivo[$motivo] = ($pesoMotivo[$motivo] ?? 0) + $h;
    }

    // Eje X: buckets continuos desde fdesde a fhasta con el paso de la granularidad.
    $buckets = motivosEvolucionBuckets($fdesde, $fhasta, $gran);

    // Motivos ordenados por peso desc (desempate alfabético para estabilidad).
    $motivosOrden = array_keys($pesoMotivo);
    usort($motivosOrden, function ($a, $b) use ($pesoMotivo) {
        $pa = $pesoMotivo[$a]; $pb = $pesoMotivo[$b];
        return $pa === $pb ? strcmp($a, $b) : $pb <=> $pa;
    });

    $motivos = [];
    foreach ($motivosOrden as $m) {
        $serie = [];
        foreach ($buckets as $b) {
            $serie[] = ['key' => $b['key'], 'horas' => round($porMotivo[$m][$b['key']] ?? 0, 2)];
        }
        $motivos[] = ['motivo' => $m, 'total_horas' => round($pesoMotivo[$m], 2), 'serie' => $serie];
    }

    return [
        'granularidad' => $gran,
        'seccion'      => $seccion ?: null,
        'buckets'      => $buckets,
        'motivos'      => $motivos,
    ];
}

/**
 * Genera la lista continua de buckets [{key:YYYY-MM-DD, label}] desde $fdesde a
 * $fhasta con el paso de la granularidad. El primer bucket de semana/mes se ancla
 * al inicio del periodo que contiene $fdesde (lunes / día 1), igual que el bucket SQL.
 */
function motivosEvolucionBuckets(string $fdesde, string $fhasta, string $gran): array
{
    $meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    $ini = new DateTime($fdesde);
    if ($gran === 'week') {
        // Anclar al lunes de la semana de fdesde (N: 1=Lun..7=Dom).
        $ini->modify('-' . ((int)$ini->format('N') - 1) . ' days');
    } elseif ($gran === 'month') {
        $ini->modify('first day of this month');
    }
    $fin = new DateTime($fhasta);
    $out = [];
    $cur = clone $ini;
    while ($cur <= $fin) {
        $key = $cur->format('Y-m-d');
        if ($gran === 'day') {
            $label = $cur->format('d/m');
        } elseif ($gran === 'week') {
            $label = 'S' . $cur->format('W') . ' (' . $cur->format('d/m') . ')';
        } else {
            $label = $meses[(int)$cur->format('n') - 1] . ' ' . $cur->format('Y');
        }
        $out[] = ['key' => $key, 'label' => $label];
        $cur->modify($gran === 'day' ? '+1 day' : ($gran === 'week' ? '+7 days' : '+1 month'));
    }
    return $out;
}

// Endpoint JSON (no se dispara si el archivo se incluye desde una sonda/otro script).
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        jsonOk(motivosEvolucionData());
    } catch (Exception $e) {
        jsonError('Error: ' . $e->getMessage(), 500);
    }
}
```

- [ ] **Step 4: Validar sintaxis y ejecutar la sonda**

Run: `php -l api/oee_unificado_motivos_evolucion.php && php scripts/probe_evol_motivos.php`
Expected: `No syntax errors detected`, y luego todas las comprobaciones en ✅ (buckets continuos, peso desc, series alineadas, total == Σ serie). Probar también `php scripts/probe_evol_motivos.php 2025-01-01 2025-12-31 VARILLAS week` y `... month`.

- [ ] **Step 5: Commit**

```bash
git add api/oee_unificado_motivos_evolucion.php
git commit -m "feat(evol-motivos): endpoint de evolución temporal de motivos (lista + series)"
```

---

### Task 2: Endpoint 2 — reparto por máquina de un motivo en un bucket

**Files:**
- Create: `api/oee_unificado_motivo_periodo_maquinas.php`
- Test: sonda CLI `scripts/probe_motivo_periodo.php` (temporal, no se versiona)

**Interfaces:**
- Consumes: `fetchAll`, `getParam`, `getListParam`, `jsonOk`, `jsonError`, `PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT`, `motivosEvolucionBuckets` NO se usa aquí (el ancho del bucket se calcula localmente).
- Produces: GET endpoint que devuelve `{motivo, bucket, granularidad, rango:{desde,hasta}, total_horas, maquinas:[{cod_maquina,maquina,horas,pct}]}`. Función interna `motivoPeriodoMaquinasData(): array`.

- [ ] **Step 1: Escribir la sonda que falla**

Crear `scripts/probe_motivo_periodo.php`:

```php
<?php
$_GET['fecha_desde']  = $argv[1] ?? '2025-01-01';
$_GET['fecha_hasta']  = $argv[2] ?? '2025-12-31';
$_GET['seccion']      = $argv[3] ?? 'VARILLAS';
$_GET['granularidad'] = $argv[4] ?? 'day';
$_GET['motivo']       = $argv[5] ?? 'PAUSA';
$_GET['bucket']       = $argv[6] ?? '2025-01-02';
require '/home/aistudio/oee_unificado/api/oee_unificado_motivo_periodo_maquinas.php';
$d = motivoPeriodoMaquinasData();
echo "Motivo {$d['motivo']} · bucket {$d['bucket']} ({$d['granularidad']}) · rango {$d['rango']['desde']}..{$d['rango']['hasta']}\n";
echo "Total: {$d['total_horas']} h · ".count($d['maquinas'])." máquinas\n";
$prev = INF; $ord = true; $sum = 0;
foreach ($d['maquinas'] as $m) {
    printf("  %-28s %8.2f h  %5.1f%%\n", $m['maquina'], $m['horas'], $m['pct']);
    if ($m['horas'] > $prev + 1e-9) $ord = false; $prev = $m['horas']; $sum += $m['horas'];
}
echo "Orden horas desc: ".($ord ? "✅" : "❌")."\n";
echo "Σ horas máquinas == total: ".(abs($sum - $d['total_horas']) < 0.05 ? "✅" : "❌ ($sum vs {$d['total_horas']})")."\n";
```

- [ ] **Step 2: Ejecutar la sonda y verificar que falla**

Run: `php scripts/probe_motivo_periodo.php`
Expected: FAIL — `Failed to open stream` (el endpoint aún no existe).

- [ ] **Step 3: Crear el endpoint**

Crear `api/oee_unificado_motivo_periodo_maquinas.php`:

```php
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/PlanAttainmentAgg.php';

/**
 * Reparto por máquina de un motivo de paro dentro de un bucket temporal concreto.
 * Alimenta el popup que se abre al clicar un punto del gráfico de evolución de
 * motivos. El rango efectivo del reparto es la intersección entre el bucket
 * (1 día / 7 días / 1 mes desde `bucket`) y el intervalo global [fecha_desde,
 * fecha_hasta], para no contar días fuera del intervalo en buckets de borde.
 *
 * Filtros idénticos a Matriz 2 (excluye paro 11 y actividad 1) + el motivo dado.
 *
 * GET: fecha_desde, fecha_hasta (req), seccion, turnos (CSV),
 *      motivo (req, Desc_paro), bucket (req, YYYY-MM-DD inicio del bucket),
 *      granularidad (day|week|month, req).
 */
function motivoPeriodoMaquinasData(): array
{
    $fdesde = (string) getParam('fecha_desde');
    $fhasta = (string) getParam('fecha_hasta');
    $seccion = strtoupper((string) getParam('seccion', ''));
    $gran = (string) getParam('granularidad', 'day');
    $motivo = (string) getParam('motivo', '');
    $bucket = (string) getParam('bucket', '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdesde)) throw new Exception('fecha_desde inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fhasta)) throw new Exception('fecha_hasta inválida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bucket)) throw new Exception('bucket inválido');
    if (!in_array($gran, ['day','week','month'], true)) throw new Exception('granularidad inválida');
    if ($seccion !== '' && !in_array($seccion, ['VARILLAS','TROQUELADOS'], true)) throw new Exception('seccion inválida');
    if ($motivo === '') throw new Exception('motivo requerido');
    $turnos = array_values(array_filter(getListParam('turnos'), fn($t) => in_array($t, ['M','T','N'], true)));

    // Ancho del bucket → fin (inclusive). El reparto se acota a la intersección
    // con el intervalo global.
    $ini = new DateTime($bucket);
    $fin = clone $ini;
    if ($gran === 'day')       $fin->modify('+0 day');
    elseif ($gran === 'week')  $fin->modify('+6 days');
    else                       $fin->modify('last day of this month');
    $desde = max($bucket, $fdesde);
    $hasta = min($fin->format('Y-m-d'), $fhasta);

    $where  = [
        "CAST(hp.Dia_productivo AS DATE) BETWEEN ? AND ?",
        "cp.Cod_paro <> 11",
        "cp.Id_actividad <> 1",
        "hpp.Fecha_fin IS NOT NULL",
        "cp.Desc_paro = ?",
    ];
    $params = [$desde, $hasta, $motivo];
    if (!empty($turnos)) {
        $ph = implode(',', array_fill(0, count($turnos), '?'));
        $where[] = "ct.Cod_turno IN ($ph)";
        $params = array_merge($params, $turnos);
    }

    $sql = "
        SELECT
            mq.Cod_maquina  AS cod_maquina,
            mq.Desc_maquina AS maquina,
            SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) AS segundos
        FROM his_prod_paro hpp
        INNER JOIN cfg_paro    cp ON cp.Id_paro     = hpp.Id_paro
        INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
        INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
        INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
        WHERE " . implode(' AND ', $where) . "
        GROUP BY mq.Cod_maquina, mq.Desc_maquina
        HAVING SUM(DATEDIFF(SECOND, hpp.Fecha_ini, hpp.Fecha_fin)) > 0
    ";
    $rows = fetchAll('mapex', $sql, $params);

    $maquinas = [];
    $total = 0.0;
    foreach ($rows as $r) {
        $maq = (string) $r['maquina'];
        if ($seccion !== '' && (PlanAttainmentAgg::MAQUINA_TO_SECCION_EXT[$maq] ?? null) !== $seccion) continue;
        $h = (int) $r['segundos'] / 3600.0;
        $maquinas[] = ['cod_maquina' => (string) $r['cod_maquina'], 'maquina' => $maq ?: (string) $r['cod_maquina'], 'horas' => $h];
        $total += $h;
    }
    usort($maquinas, fn($a, $b) => $b['horas'] <=> $a['horas']);
    foreach ($maquinas as &$m) {
        $m['pct'] = $total > 0 ? round($m['horas'] / $total * 100, 1) : 0;
        $m['horas'] = round($m['horas'], 2);
    }
    unset($m);

    return [
        'motivo'       => $motivo,
        'bucket'       => $bucket,
        'granularidad' => $gran,
        'rango'        => ['desde' => $desde, 'hasta' => $hasta],
        'total_horas'  => round($total, 2),
        'maquinas'     => $maquinas,
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    try {
        jsonOk(motivoPeriodoMaquinasData());
    } catch (Exception $e) {
        jsonError('Error: ' . $e->getMessage(), 500);
    }
}
```

- [ ] **Step 4: Validar sintaxis y ejecutar la sonda**

Run: `php -l api/oee_unificado_motivo_periodo_maquinas.php && php scripts/probe_motivo_periodo.php`
Expected: `No syntax errors detected`, orden horas desc ✅, Σ horas == total ✅. Para elegir un motivo/bucket con datos reales, tomar el top motivo de la sonda de la Task 1 y un `bucket` con horas > 0 de su serie.

- [ ] **Step 5: Cuadre cruzado entre los dos endpoints**

Run (sustituir MOTIVO y BUCKET por un punto real con horas>0 de la Task 1):
`php scripts/probe_motivo_periodo.php 2025-01-01 2025-12-31 VARILLAS day "PAUSA" "2025-01-02"`
Expected: el `total_horas` del Endpoint 2 coincide (±0.05) con el `horas` del punto `2025-01-02` en la serie de `PAUSA` del Endpoint 1 (mismos filtros). Anotar el cuadre en el mensaje del commit.

- [ ] **Step 6: Commit y limpiar sondas**

```bash
rm -f scripts/probe_evol_motivos.php scripts/probe_motivo_periodo.php
git add api/oee_unificado_motivo_periodo_maquinas.php
git commit -m "feat(evol-motivos): endpoint de reparto por máquina de un motivo en un bucket"
```

---

### Task 3: Frontend — botón, CSS y apertura del formulario con lista de motivos

**Files:**
- Modify: `oee_unificado_v2.html` (botón en la barra ~línea 395; CSS junto a `.mtx2-*`; método `openEvolMotivos` y `evolMotRender` en el objeto `App`, junto a `openMatriz2`/`matriz2Tabla` ~línea 1836).

**Interfaces:**
- Consumes: `this.common({seccion})`, `this.get(endpoint,params)`, `this.loadingHtml()`, `this.esc`/inline `e`, `fDesde.value`, `fHasta.value`, `State.seccion`, `PALETTE`, `this.apex(el,opts)`, `this.barList(...)`.
- Produces: `App.openEvolMotivos()` (abre el `fullpop`), `App._evolMot` (estado: `{d, gran, motivoSel}`), `App.evolMotRender(ov)` (pinta lista + gráfico según estado).

- [ ] **Step 1: Añadir el botón de entrada**

En `oee_unificado_v2.html`, tras la línea del botón de Matriz 2 (`<button class="btn btn-matriz" onclick="App.openMatriz2()">🧮 Matriz 2</button>`), añadir:

```html
      <button class="btn btn-evolmot" onclick="App.openEvolMotivos()">📈 Evolución motivos</button>
```

Y junto a las reglas `.btn-matriz` (~línea 65), añadir el CSS del botón y del formulario:

```css
.btn-evolmot{background:#0f766e;color:#fff;border-color:#0c5d57}
.btn-evolmot:hover{background:#0c5d57}
/* Evolución de motivos: layout lista + gráfico */
.evm-wrap{display:flex;gap:14px;height:100%;min-height:0}
.evm-list{flex:0 0 260px;overflow:auto;border-right:1px solid #e5dada;padding-right:10px}
.evm-mot{display:flex;justify-content:space-between;gap:8px;padding:8px 10px;border-radius:7px;cursor:pointer;font-size:13px;border:1px solid transparent}
.evm-mot:hover{background:#f1f5f4}
.evm-mot.active{background:#0f766e;color:#fff;font-weight:700}
.evm-mot small{opacity:.8;font-variant-numeric:tabular-nums}
.evm-chart{flex:1 1 auto;min-width:0}
.evm-gran{display:flex;gap:6px;align-items:center}
.evm-gran button{padding:6px 12px;border:1px solid #cbd5d3;background:#fff;border-radius:7px;font-size:13px;cursor:pointer;font-weight:600;color:#334}
.evm-gran button.active{background:#0f766e;color:#fff;border-color:#0c5d57}
```

- [ ] **Step 2: Añadir `openEvolMotivos` (abre el shell y carga datos)**

Tras el método `matriz2Tabla(d){...}` (cierre `},` ~línea 1956), añadir dentro del objeto `App`:

```javascript
  async openEvolMotivos(){
    const ov=document.createElement('div'); ov.className='fullpop';
    ov.innerHTML=`
      <div class="fullpop-head">
        <h2>📈 Evolución de motivos · Disponibilidad<small>${State.seccion} · ${fDesde.value} a ${fHasta.value} · horas de paro</small></h2>
        <div class="evm-gran">
          <span style="font-size:12px;color:#6b7280;font-weight:600">Granularidad:</span>
          <button data-g="day" class="active">Día</button>
          <button data-g="week">Semana</button>
          <button data-g="month">Mes</button>
          <button class="evmClose" style="background:#f3f4f6;border:none;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;color:#6b7280;margin-left:10px">✕ Cerrar</button>
        </div>
      </div>
      <div class="fullpop-body"><div class="evm-wrap"><div class="evm-list">${this.loadingHtml()}</div><div class="evm-chart"></div></div></div>`;
    document.body.appendChild(ov);
    ov.querySelector('.evmClose').onclick=()=>ov.remove();
    this._evolMot={ov, gran:'day', motivoSel:null, d:null};
    // Botones de granularidad: recargan con la nueva escala conservando el motivo.
    ov.querySelectorAll('.evm-gran button[data-g]').forEach(b=>{
      b.onclick=()=>{
        ov.querySelectorAll('.evm-gran button[data-g]').forEach(x=>x.classList.remove('active'));
        b.classList.add('active');
        this._evolMot.gran=b.dataset.g;
        this.evolMotLoad();
      };
    });
    this.evolMotLoad();
  },
  async evolMotLoad(){
    const ov=this._evolMot.ov;
    const list=ov.querySelector('.evm-list'); const chart=ov.querySelector('.evm-chart');
    list.innerHTML=this.loadingHtml(); chart.innerHTML='';
    try{
      const d=await this.get('oee_unificado_motivos_evolucion.php',
        this.common({seccion:State.seccion, granularidad:this._evolMot.gran}));
      this._evolMot.d=d;
      if(!d.motivos.length){list.innerHTML='<div class="empty">Sin paros para el filtro seleccionado</div>';return;}
      // Conservar el motivo seleccionado si sigue existiendo; si no, el de más peso.
      const sel=this._evolMot.motivoSel;
      this._evolMot.motivoSel=(sel&&d.motivos.some(m=>m.motivo===sel))?sel:d.motivos[0].motivo;
      this.evolMotRender();
    }catch(e){list.innerHTML=`<div class="error">${e.message}</div>`;}
  },
```

- [ ] **Step 3: Validar sintaxis JS abriendo el formulario (manual/navegador)**

Por ahora `evolMotRender` no existe; el archivo es HTML (no hay linter JS de CLI fiable aquí). Verificación: en la Task 4 se añade `evolMotRender` y se valida en navegador. En este paso basta con que `php -l` NO aplique (es HTML) y revisar visualmente que no hay llaves sin cerrar en el bloque añadido.

- [ ] **Step 4: Commit**

```bash
git add oee_unificado_v2.html
git commit -m "feat(evol-motivos): botón, shell del formulario y carga de datos (lista + granularidad)"
```

---

### Task 4: Frontend — render de lista + gráfico de líneas con etiquetas y clic en punto

**Files:**
- Modify: `oee_unificado_v2.html` (añadir `evolMotRender` y `evolMotMaquinas` tras `evolMotLoad`).

**Interfaces:**
- Consumes: `this._evolMot` (`{ov, gran, motivoSel, d}`), `PALETTE`, `this.apex`, `this.barList`, `this.get`, `this.common`, `State.seccion`.
- Produces: `App.evolMotRender()` (pinta lista + serie del motivo seleccionado), `App.evolMotMaquinas(bucketKey)` (popup de reparto por máquina).

- [ ] **Step 1: Añadir `evolMotRender` (lista + gráfico)**

Tras `evolMotLoad(){...}`, añadir:

```javascript
  evolMotRender(){
    const {ov,d,motivoSel}=this._evolMot;
    const e=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const list=ov.querySelector('.evm-list'); const chartBox=ov.querySelector('.evm-chart');
    // Lista de motivos (peso desc). El activo resaltado.
    list.innerHTML=d.motivos.map(m=>
      `<div class="evm-mot${m.motivo===motivoSel?' active':''}" data-motivo="${e(m.motivo)}">
         <span>${e(m.motivo)}</span><small>${m.total_horas.toFixed(2)} h</small></div>`).join('');
    list.querySelectorAll('.evm-mot').forEach(row=>{
      row.onclick=()=>{ this._evolMot.motivoSel=row.dataset.motivo; this.evolMotRender(); };
    });
    // Serie del motivo seleccionado.
    const mot=d.motivos.find(m=>m.motivo===motivoSel);
    if(!mot){chartBox.innerHTML='<div class="hint">Selecciona un motivo</div>';return;}
    const cats=d.buckets.map(b=>b.label);
    const vals=mot.serie.map(p=>p.horas);
    const keys=d.buckets.map(b=>b.key);
    chartBox.innerHTML='<div class="evm-apx"></div>';
    const muchos=cats.length>31;   // regla de etiquetas: ocultas si hay demasiados puntos
    this.apex(chartBox.querySelector('.evm-apx'),{
      chart:{type:'line',height:Math.max(420,ov.querySelector('.fullpop-body').clientHeight-40),
        toolbar:{show:true,tools:{download:true,zoom:true,pan:true,reset:true}},animations:{enabled:false},
        events:{
          // Clic en un marcador → popup de reparto por máquina de ese bucket.
          markerClick:(ev,ctx,{dataPointIndex})=>{ if(dataPointIndex!=null&&keys[dataPointIndex]!=null) this.evolMotMaquinas(keys[dataPointIndex]); },
          dataPointSelection:(ev,ctx,{dataPointIndex})=>{ if(dataPointIndex!=null&&keys[dataPointIndex]!=null) this.evolMotMaquinas(keys[dataPointIndex]); }
        }},
      series:[{name:mot.motivo,data:vals}],
      colors:[PALETTE[0]],
      stroke:{width:3,curve:'straight'},
      markers:{size:muchos?3:5,hover:{sizeOffset:3}},
      dataLabels:{enabled:!muchos,formatter:v=>v>0?v.toFixed(1):'',background:{enabled:true,opacity:.9},style:{fontSize:'10px'}},
      xaxis:{categories:cats,labels:{rotate:-45,rotateAlways:cats.length>12,style:{fontSize:'11px'}}},
      yaxis:{min:0,title:{text:'Horas de paro'},labels:{formatter:v=>(Math.round(v*10)/10)}},
      tooltip:{y:{formatter:v=>v.toFixed(2)+' h'}},
      legend:{show:false},
      noData:{text:'Sin datos'}
    });
  },
  async evolMotMaquinas(bucketKey){
    // Popup (fullpop encima) con el reparto por máquina del motivo en ese bucket.
    const {motivoSel,gran}=this._evolMot;
    const e=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const ov=document.createElement('div'); ov.className='fullpop';
    ov.innerHTML=`
      <div class="fullpop-head">
        <h2>🏭 Reparto por máquina · ${e(motivoSel)}<small>${e(bucketKey)} · ${gran==='day'?'día':gran==='week'?'semana':'mes'} · horas de paro</small></h2>
        <button class="evmmClose" style="background:#f3f4f6;border:none;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;color:#6b7280">✕ Cerrar</button>
      </div>
      <div class="fullpop-body"><div class="evmm-body">${this.loadingHtml()}</div></div>`;
    document.body.appendChild(ov);
    ov.querySelector('.evmmClose').onclick=()=>ov.remove();
    const body=ov.querySelector('.evmm-body');
    try{
      const d=await this.get('oee_unificado_motivo_periodo_maquinas.php',
        this.common({seccion:State.seccion, granularidad:gran, motivo:motivoSel, bucket:bucketKey}));
      if(!d.maquinas.length){body.innerHTML='<div class="empty">Sin paros de este motivo en este periodo</div>';return;}
      const max=Math.max(...d.maquinas.map(m=>m.horas));
      body.innerHTML=`<div class="breadcrumb">Reparto de <b>${e(motivoSel)}</b> en <b>${e(d.rango.desde)}</b> a <b>${e(d.rango.hasta)}</b> · total <b>${d.total_horas.toFixed(2)} h</b> · ${d.maquinas.length} máquinas.</div>`
        +this.barList(d.maquinas,{name:m=>m.maquina,val:m=>m.horas,unit:'h',max,
          extraCols:[{label:'%',val:m=>m.pct+'%'}],color:()=>'#0f766e',clickable:false});
    }catch(e2){body.innerHTML=`<div class="error">${e2.message}</div>`;}
  },
```

- [ ] **Step 2: Verificación visual en el navegador**

Servir el proyecto (o usar el host Apache existente) y abrir `oee_unificado_v2.html`. Pasos a comprobar con screenshots ANTES y DESPUÉS de cada acción:
1. Seleccionar un intervalo con datos y sección VARILLAS → clic en `📈 Evolución motivos`.
2. Aparece la lista de motivos (peso desc) y el gráfico del motivo de más peso, con etiquetas de valor visibles (rango corto).
3. Clic en otro motivo → la línea cambia a ese motivo, el activo se resalta.
4. Clic en `Semana` y `Mes` → el eje X re-agrupa, el motivo seleccionado se conserva.
5. Clic en un punto del gráfico → popup de reparto por máquina con horas y % (suma = valor del punto).
6. Rango largo a granularidad Día (>31 puntos) → etiquetas ocultas, marcadores clicables, tooltip con valor.

- [ ] **Step 3: Verificar `extraCols` de `barList`**

Confirmar que `barList` acepta `extraCols:[{label,val}]` (se usa en el código existente, ver `machBody.innerHTML=this.barList(items,{...,extraCols,...})`). Si la firma difiere, ajustar la llamada del popup para mostrar el % como sufijo del nombre o en una columna soportada. Inspeccionar:

Run: `grep -n "extraCols" oee_unificado_v2.html`
Expected: ver la definición de `barList` y el formato exacto de `extraCols`; alinear la llamada del Step 1 a esa firma.

- [ ] **Step 4: Commit**

```bash
git add oee_unificado_v2.html
git commit -m "feat(evol-motivos): gráfico de líneas con etiquetas, clic en punto y popup de reparto por máquina"
```

---

## Self-Review

**Spec coverage:**
- Intervalo de fechas + sección + turnos → heredados vía `this.common({seccion})` (Tasks 3-4) y filtrados en backend (Tasks 1-2). ✅
- Granularidad manual día/semana/mes → botones (Task 3) + bucket SQL (Task 1). ✅
- Lista de motivos del intervalo, por peso → Endpoint 1 `motivos` desc (Task 1), render lista (Task 4). ✅
- Clic en motivo → un motivo a la vez → `evolMotRender` repinta una serie (Task 4). ✅
- Línea temporal con etiqueta de valor en cada punto → `dataLabels` con regla ≤31 (Task 4). ✅
- Clic en punto → popup reparto por máquina → `evolMotMaquinas` + Endpoint 2 (Tasks 2,4). ✅
- Buckets parciales de borde → intersección de rango en Endpoint 2 (Task 2). ✅
- Preselección del motivo de más peso → `evolMotLoad` (Task 3). ✅
- Verificación (buckets continuos, peso desc, cuadre cruzado) → sondas (Tasks 1,2). ✅

**Placeholder scan:** sin TBD/TODO; todo el código está completo. El Step 3 de la Task 4 es una verificación de firma real (`extraCols`), no un placeholder.

**Type consistency:** claves JSON consistentes entre endpoints y frontend: `buckets[{key,label}]`, `motivos[{motivo,total_horas,serie:[{key,horas}]}]`, `maquinas[{cod_maquina,maquina,horas,pct}]`, `rango{desde,hasta}`. Métodos: `openEvolMotivos`/`evolMotLoad`/`evolMotRender`/`evolMotMaquinas` referenciados de forma idéntica en todas las tasks. `_evolMot={ov,gran,motivoSel,d}` usado consistentemente.

## Notas de ejecución

- No hay framework de tests automatizados en el proyecto; la verificación de backend es por sonda CLI contra MAPEX real (patrón ya usado en Matriz 2) y la de frontend es visual en navegador (screenshots antes/después).
- Las sondas se crean en `scripts/` y se eliminan en el commit final de cada endpoint (no se versionan).
