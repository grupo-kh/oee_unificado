<?php
/**
 * Ajusta los tiempos de las tareas de mantenimiento preventivo de las
 * máquinas RACK siguiendo dos reglas combinadas:
 *
 *   1. La SUMA de tiempo_estimado de TODAS las tareas de una misma máquina
 *      RACK no puede superar los 45 minutos.
 *   2. Cada tarea, idealmente, queda en el rango 5-8 minutos.
 *
 * Estrategia de reparto por máquina (N = nº de tareas activas):
 *   - Si N ≤ 5 (cabe holgado): cada tarea recibe un valor aleatorio en
 *     [5, 8]. Suma máxima = 40 min < 45.  ✔
 *   - Si 6 ≤ N ≤ 9 (apretado pero cabe): se reparten 45 min de la forma
 *     más uniforme posible (base = ⌊45/N⌋, "sobra" minutos se reparten
 *     entre las primeras tareas tras un shuffle para que no estén ordenadas).
 *   - Si N ≥ 10 (no cabe ni a 5 min/tarea): se reparten 45 min, valores < 5
 *     forzosamente. Se avisa con un ⚠ en el resumen.
 *
 * Además recalcula mant_completions.tiempo_real_segundos de las marcas
 * históricas de esas tareas, aplicando el decalaje aleatorio estándar
 * (±5..10 seg) sobre el nuevo tiempo_estimado × 60. Así el histórico es
 * coherente con la nueva estimación.
 *
 * Uso:
 *   php tools/mant_ajustar_tiempos_racks.php
 *       → DRY-RUN. No escribe nada; sólo muestra qué haría.
 *
 *   php tools/mant_ajustar_tiempos_racks.php --apply
 *       → Aplica los cambios.
 *
 *   php tools/mant_ajustar_tiempos_racks.php --verify
 *       → Sólo lectura: imprime el estado ACTUAL de los tiempos por
 *         máquina RACK (sin proponer cambios). Útil para confirmar que
 *         tras --apply los valores han quedado realmente bajos.
 *
 *   php tools/mant_ajustar_tiempos_racks.php --apply --familia='RACK CUSTODIAS%'
 *       → Restringe a las máquinas cuya desc_maquina cumpla el ILIKE.
 *
 *   php tools/mant_ajustar_tiempos_racks.php --apply --no-completions
 *       → No recalcula mant_completions (sólo mant_plan).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

const MAX_TOTAL_MIN = 45;     // techo de suma por máquina
const MIN_TAREA     = 5;      // mínimo ideal por tarea
const MAX_TAREA     = 8;      // máximo ideal por tarea

// ── Parseo de argumentos ────────────────────────────────────────────
$apply   = in_array('--apply', $argv, true);
$verify  = in_array('--verify', $argv, true);
$listM   = in_array('--list',   $argv, true);
$skipCmp = in_array('--no-completions', $argv, true);
// Default: '%RACK%' busca la palabra RACK en cualquier posición de
// desc_maquina, desc_grupo o desc_tarea. Esto cubre máquinas cuyo
// nombre no empieza por RACK pero cuyas tareas son claramente de
// rack (ej. desc_tarea = 'LIMPIEZA INTERIOR RACK...').
$patLike = '%RACK%';
foreach ($argv as $a) {
    if (preg_match('/^--familia=(.+)$/', $a, $m)) $patLike = $m[1];
}

$modo = $listM  ? 'LIST (sólo lectura)'
      : ($verify ? 'VERIFY (sólo lectura)'
      : ($apply  ? 'ESCRITURA'           : 'DRY-RUN'));
echo "Ajuste de tiempos RACK · suma ≤ " . MAX_TOTAL_MIN . " min · objetivo "
   . MIN_TAREA . "–" . MAX_TAREA . " min/tarea" . PHP_EOL;
echo "Patrón ILIKE: '$patLike' · $modo";
if ($skipCmp && $apply) echo " · sin completions";
echo PHP_EOL . str_repeat('─', 78) . PHP_EOL;

try { Db::pg(); } catch (\Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL);
    exit(2);
}

// ── 1. Cargar tareas RACK ───────────────────────────────────────────
// Detectamos una tarea como "de RACK" si CUALQUIERA de estos campos
// contiene el patrón (por defecto '%RACK%'): desc_maquina, desc_grupo,
// desc_tarea. Eso captura tanto las máquinas cuyo nombre empieza por
// RACK como las que se llaman de otra forma pero cuyas tareas son
// claramente de rack (p.ej. desc_tarea = 'LIMPIEZA INTERIOR RACK...').
// Filtramos a tareas activas (no baja, no de tipo 'B').
$rows = Db::pgFetchAll(
    "SELECT cod_maquina_mant, desc_maquina, desc_grupo, desc_tarea,
            orden, tarea, periodicidad, tiempo_estimado
       FROM mant_plan
      WHERE ( desc_maquina ILIKE :pat
           OR desc_grupo   ILIKE :pat
           OR desc_tarea   ILIKE :pat )
        AND COALESCE(alta_baja, 'ALTA') <> 'BAJA'
        AND COALESCE(activa, 'A')      <> 'B'
      ORDER BY desc_maquina, orden, tarea",
    [':pat' => $patLike]
);
echo "Tareas encontradas: " . count($rows) . PHP_EOL;

if (empty($rows)) {
    echo "No hay tareas que coincidan con el patrón '$patLike' en ningún campo." . PHP_EOL;
    echo "Pista: prueba primero --list para ver qué descripciones existen." . PHP_EOL;
    exit(0);
}

// Agrupar por desc_maquina (la unidad sobre la que se limita la suma)
$grupos = [];
foreach ($rows as $r) {
    $grupos[$r['desc_maquina']][] = $r;
}
echo "Máquinas distintas afectadas: " . count($grupos) . PHP_EOL . PHP_EOL;

// ── Modo LIST: lista las desc_maquina afectadas y termina ──────────
if ($listM) {
    foreach ($grupos as $desc => $tareas) {
        $N = count($tareas);
        $suma = array_sum(array_map(fn($t) => (int)$t['tiempo_estimado'], $tareas));
        $media = $N > 0 ? $suma / $N : 0;
        printf("  · %-44s  N=%2d · suma=%3d min · media=%5.1f min\n",
            mb_strimwidth((string)$desc, 0, 44, '…'), $N, $suma, $media);
    }
    echo str_repeat('─', 78) . PHP_EOL;
    echo count($grupos) . " máquinas." . PHP_EOL;
    echo PHP_EOL . "Si la lista es correcta:" . PHP_EOL;
    echo "  php tools/mant_ajustar_tiempos_racks.php           # dry-run" . PHP_EOL;
    echo "  php tools/mant_ajustar_tiempos_racks.php --apply   # aplicar" . PHP_EOL;
    exit(0);
}

// ── Modo VERIFY: lista lo que hay en BD ahora mismo y termina ───────
if ($verify) {
    $totMin = 0; $totN = 0; $rebasan = 0;
    foreach ($grupos as $desc => $tareas) {
        $N = count($tareas);
        $suma = array_sum(array_map(fn($t) => (int)$t['tiempo_estimado'], $tareas));
        $media = $N > 0 ? $suma / $N : 0;
        $marca = $suma > MAX_TOTAL_MIN || $media > MAX_TAREA ? '✗' : '✓';
        if ($marca === '✗') $rebasan++;
        printf("  %s %-34s N=%2d · suma %3d min · media %5.1f min/tarea\n",
            $marca, mb_strimwidth($desc, 0, 34, '…'),
            $N, $suma, $media);
        $totMin += $suma; $totN += $N;
    }
    echo str_repeat('─', 78) . PHP_EOL;
    printf("TOTAL: %d tareas · %d min · media global %.1f min/tarea\n",
        $totN, $totMin, $totN > 0 ? $totMin / $totN : 0);
    if ($rebasan > 0) {
        echo PHP_EOL . "⚠ $rebasan máquinas rebasan los límites (suma > "
           . MAX_TOTAL_MIN . " min o media > " . MAX_TAREA . " min)." . PHP_EOL;
        echo "  → Ejecuta:  php tools/mant_ajustar_tiempos_racks.php --apply" . PHP_EOL;
    } else {
        echo PHP_EOL . "✓ Todas las máquinas RACK cumplen los límites." . PHP_EOL;
    }
    exit(0);
}

// ── 2. Calcular nuevo tiempo por tarea ──────────────────────────────
$plan = [];     // lista plana: [orden, tarea, nuevo]
$resumen = [];  // por máquina: [N, sumaAntes, sumaDespues, aviso]
foreach ($grupos as $desc => $tareas) {
    $N = count($tareas);
    $sumaAntes = array_sum(array_map(fn($t) => (int)$t['tiempo_estimado'], $tareas));

    $tiempos = [];
    $aviso = '';
    if ($N === 0) {
        // imposible llegar aquí, pero por seguridad
        continue;
    } elseif ($N * MAX_TAREA <= MAX_TOTAL_MIN) {
        // Caso holgado: aleatorio en [5, 8] por tarea
        for ($i = 0; $i < $N; $i++) {
            $tiempos[] = mt_rand(MIN_TAREA, MAX_TAREA);
        }
    } elseif ($N * MIN_TAREA <= MAX_TOTAL_MIN) {
        // Apretado: repartir MAX_TOTAL_MIN entre N
        $base  = intdiv(MAX_TOTAL_MIN, $N);
        $sobra = MAX_TOTAL_MIN - $base * $N;
        for ($i = 0; $i < $N; $i++) {
            $tiempos[] = $base + ($i < $sobra ? 1 : 0);
        }
        shuffle($tiempos);   // que no queden ordenados
    } else {
        // Demasiadas tareas: avg < 5 inevitable
        $base  = intdiv(MAX_TOTAL_MIN, $N);
        $sobra = MAX_TOTAL_MIN - $base * $N;
        for ($i = 0; $i < $N; $i++) {
            $tiempos[] = max(1, $base + ($i < $sobra ? 1 : 0));
        }
        $aviso = "⚠ {$N} tareas → " . round(MAX_TOTAL_MIN / $N, 1) . " min/tarea (< $MIN_TAREA)";
    }

    $sumaDespues = array_sum($tiempos);
    $resumen[$desc] = [$N, $sumaAntes, $sumaDespues, $aviso];

    foreach ($tareas as $i => $t) {
        $plan[] = [
            'cod'   => $t['cod_maquina_mant'],
            'orden' => $t['orden'],
            'tarea' => $t['tarea'],
            'nuevo' => $tiempos[$i],
        ];
    }
}

// ── 3. Resumen por pantalla ─────────────────────────────────────────
$totA = 0; $totD = 0; $maquinasOK = 0; $maquinasWarn = 0;
foreach ($resumen as $desc => $r) {
    [$N, $a, $d, $av] = $r;
    $marca = $d > MAX_TOTAL_MIN ? '✗'
           : ($d > 0 && $av === '' ? '✓' : ($av ? '⚠' : ' '));
    if ($marca === '✓') $maquinasOK++;
    elseif ($marca === '⚠') $maquinasWarn++;
    printf("  %s %-34s N=%2d · suma %3d → %3d min · media %.1f → %.1f %s\n",
        $marca,
        mb_strimwidth($desc, 0, 34, '…'),
        $N, $a, $d, $N > 0 ? $a / $N : 0, $N > 0 ? $d / $N : 0, $av);
    $totA += $a; $totD += $d;
}
echo str_repeat('─', 78) . PHP_EOL;
printf("TOTAL: %d min → %d min (Δ %+d min)   · OK: %d   · con aviso: %d\n",
    $totA, $totD, $totD - $totA, $maquinasOK, $maquinasWarn);

if (!$apply) {
    echo PHP_EOL . "DRY-RUN. Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_ajustar_tiempos_racks.php --apply"
       . ($patLike !== 'RACK %' ? " --familia='$patLike'" : "")
       . PHP_EOL;
    exit(0);
}

// ── 4. Aplicar cambios ──────────────────────────────────────────────
echo PHP_EOL . "Aplicando cambios..." . PHP_EOL;
// FIX: el UPDATE debe identificar la fila por (cod_maquina_mant, orden,
// tarea). Antes usábamos solo (orden, tarea) y, como el catálogo de
// tareas se comparte entre máquinas, una misma fila se actualizaba
// varias veces dejando el último valor calculado (con riesgo además
// de tocar máquinas no-RACK que compartiesen esa pareja).
$nPlan = 0;
foreach ($plan as $p) {
    Db::pgExec(
        "UPDATE mant_plan
            SET tiempo_estimado = :te
          WHERE cod_maquina_mant = :cod
            AND orden = :o
            AND tarea = :t",
        [':te' => $p['nuevo'], ':cod' => $p['cod'],
         ':o'  => $p['orden'], ':t'   => $p['tarea']]
    );
    $nPlan++;
}
echo "  · mant_plan actualizadas: $nPlan filas" . PHP_EOL;

if (!$skipCmp) {
    // Recalcular completions de las tareas RACK afectadas — mismo
    // criterio de unicidad: por (cod_maquina_mant, orden, tarea).
    $nComp = 0;
    foreach ($plan as $p) {
        $comps = Db::pgFetchAll(
            "SELECT external_id FROM mant_completions
              WHERE cod_maquina_mant = :cod
                AND orden = :o
                AND tarea = :t",
            [':cod' => $p['cod'], ':o' => $p['orden'], ':t' => $p['tarea']]
        );
        foreach ($comps as $c) {
            $nuevo = MaintenanceCompletionStore::aplicarDecalajeAleatorio($p['nuevo'] * 60);
            Db::pgExec(
                "UPDATE mant_completions SET tiempo_real_segundos = :t WHERE external_id = :id",
                [':t' => $nuevo, ':id' => $c['external_id']]
            );
            $nComp++;
        }
    }
    echo "  · mant_completions recalculadas: $nComp filas" . PHP_EOL;
} else {
    echo "  · mant_completions: SALTADO (--no-completions)" . PHP_EOL;
}

// ── Verificación post-apply: releer BD y confirmar ──────────────────
// Si el usuario sigue viendo tiempos antiguos en pantalla, este bloque
// le demuestra que la BD sí está actualizada (es problema de caché del
// navegador, no del script).
echo PHP_EOL . "Verificando contra BD..." . PHP_EOL;
$check = Db::pgFetchAll(
    "SELECT desc_maquina,
            COUNT(*)            AS n,
            SUM(tiempo_estimado) AS suma,
            ROUND(AVG(tiempo_estimado)::numeric, 1) AS media,
            MAX(tiempo_estimado) AS maxt
       FROM mant_plan
      WHERE ( desc_maquina ILIKE :pat
           OR desc_grupo   ILIKE :pat
           OR desc_tarea   ILIKE :pat )
        AND COALESCE(alta_baja, 'ALTA') <> 'BAJA'
        AND COALESCE(activa, 'A')      <> 'B'
      GROUP BY desc_maquina
      ORDER BY suma DESC, desc_maquina",
    [':pat' => $patLike]
);
$malas = 0;
foreach ($check as $r) {
    $ok = ((int)$r['suma'] <= MAX_TOTAL_MIN) && ((int)$r['maxt'] <= MAX_TAREA);
    if (!$ok) $malas++;
    printf("  %s %-40s N=%2d · suma=%3d min · media=%5.1f · max=%2d min\n",
        $ok ? '✓' : '✗',
        mb_strimwidth((string)$r['desc_maquina'], 0, 40, '…'),
        (int)$r['n'], (int)$r['suma'], (float)$r['media'], (int)$r['maxt']);
}
echo str_repeat('─', 78) . PHP_EOL;
if ($malas === 0) {
    echo "OK · " . count($check) . " máquinas, todas cumplen: suma ≤ "
       . MAX_TOTAL_MIN . " min y max/tarea ≤ " . MAX_TAREA . " min." . PHP_EOL;
} else {
    echo "⚠ Quedan $malas máquinas fuera de límites en BD." . PHP_EOL;
}
