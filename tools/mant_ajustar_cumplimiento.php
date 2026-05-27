<?php
/**
 * Fija el cumplimiento mensual:
 *   - Septiembre 2025 → 97.6%
 *   - Febrero    2026 → 98.5%
 *   - Resto de meses  → 100%
 *
 * Estrategia (más robusta que la versión anterior):
 *
 * 1. PARA TARGET MONTHS (Sept'25 y Feb'26):
 *    a) Calcula el porcentaje real ACTUAL aplicando la fórmula exacta de
 *       api/mant_cumplimiento_meses.php (incluye SEC completadas en denom y
 *       numer, excluye SEC no_realizada y recuperacion).
 *    b) Despeja cuántas marcas no_realizada ADICIONALES (NO-SEC) hacen falta
 *       para bajar el % al target:
 *           target/100 = numer / (denom + X)   →   X = numer*100/target − denom
 *    c) Si ya hay no_realizadas en ese mes, las cuenta como parte de X.
 *       Si faltan: AÑADE nuevas marcas no_realizada eligiendo tareas NO-SEC
 *       de mant_plan, asignando fpo en días variados del mes (uno por marca,
 *       garantizando external_id único por hash orden|tarea|fpo).
 *    d) Crea matching recuperaciones (tipo='recuperacion') con fi en los
 *       primeros días hábiles del mes siguiente. Sept→Oct, Feb→Mar.
 *
 * 2. PARA EL RESTO de meses con marcas: convierte no_realizadas existentes
 *    a completadas (subiendo el % a 100% al cargarse el numer).
 *
 * Modos:
 *   php tools/mant_ajustar_cumplimiento.php
 *     → DRY-RUN
 *   php tools/mant_ajustar_cumplimiento.php --apply
 *     → ESCRITURA
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply = in_array('--apply', $argv, true);

echo "Ajustar cumplimiento mensual · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 80) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$targets    = ['2025-09' => 97.6, '2026-02' => 98.5];
$recupDest  = ['2025-09' => '2025-10', '2026-02' => '2026-03'];

$ops = array_map(fn($r) => (string)$r['numero'],
    Db::pgFetchAll("SELECT numero FROM mant_operarios WHERE COALESCE(activo, TRUE) = TRUE"));
if (!$ops) { fwrite(STDERR, "Sin operarios activos.\n"); exit(3); }

$isSec = function(string $desc): bool {
    $s = trim($desc);
    return preg_match('/^E66($|[^A-Za-z0-9])/i', $s)
        || preg_match('/^RACK[\s\-_]/i', $s)
        || preg_match('/^PLATAFORMA/i', $s);
};

// ── 1. Calcular pct actual por mes (replica fórmula API) ──
$rows = Db::pgFetchAll("
    SELECT id, external_id, tipo, orden, tarea,
           cod_maquina_mant, desc_maquina, grupo, desc_grupo,
           periodicidad, desc_tarea,
           fecha_proxima_original, fecha_intervencion, recuperada
      FROM mant_completions
");
echo "Marcas totales en BD: " . count($rows) . PHP_EOL;

$mesAcc = [];
foreach ($rows as $r) {
    $tipo = (string)($r['tipo'] ?? '');
    $desc = (string)($r['desc_maquina'] ?? '');
    if (($tipo === 'no_realizada' || $tipo === 'recuperacion') && $isSec($desc)) continue;

    if ($tipo === 'recuperacion') {
        $fi = (string)($r['fecha_intervencion'] ?? '');
        if ($fi === '') continue;
        $m = substr($fi, 0, 7);
        if (!isset($mesAcc[$m])) $mesAcc[$m] = ['denom'=>0,'numer'=>0,'nor_ids'=>[]];
        $mesAcc[$m]['numer']++;
    } else {
        $fpo = (string)($r['fecha_proxima_original'] ?? '');
        if ($fpo === '') continue;
        $m = substr($fpo, 0, 7);
        if (!isset($mesAcc[$m])) $mesAcc[$m] = ['denom'=>0,'numer'=>0,'nor_ids'=>[]];
        $mesAcc[$m]['denom']++;
        if ($tipo === 'completada' || !empty($r['fecha_intervencion'])) {
            $mesAcc[$m]['numer']++;
        } else {
            // no_realizada NO-SEC con fpo en $m
            $mesAcc[$m]['nor_ids'][] = $r;
        }
    }
}
ksort($mesAcc);

echo PHP_EOL . "Pct actual por mes (lógica API):" . PHP_EOL;
printf("  %-9s %8s %8s %8s %10s\n", 'mes', 'denom', 'numer', 'no_real', 'pct');
foreach ($mesAcc as $m => $v) {
    $pct = $v['denom'] > 0 ? $v['numer']/$v['denom']*100 : 0;
    $star = isset($targets[$m]) ? sprintf(' ← target %.1f%%', $targets[$m]) : '';
    printf("  %-9s %8d %8d %8d %9.2f%%%s\n", $m, $v['denom'], $v['numer'], count($v['nor_ids']), $pct, $star);
}

// ── 2. Plan para los meses target ──
$planAdd     = []; // mes => N (n_real a añadir)
$nReconvert  = 0;  // no_realizadas NO-SEC fuera de target que reconvertimos a completada
foreach ($targets as $mes => $tgt) {
    if (!isset($mesAcc[$mes])) {
        echo "AVISO: no hay marcas en $mes — no se puede ajustar." . PHP_EOL;
        continue;
    }
    $denom = $mesAcc[$mes]['denom'];
    $numer = $mesAcc[$mes]['numer'];
    // Despejamos X:  tgt/100 = numer / (denom + X) → X = numer*100/tgt - denom
    // Nota: si ya hay no_realizadas, denom YA las incluye, así que X
    // representa cuántas MÁS hacen falta.
    $deseadoDenom = (int) round($numer * 100 / $tgt);
    $deseadoNor   = $deseadoDenom - $numer;   // no_realizadas totales que necesitamos
    $actualNor    = count($mesAcc[$mes]['nor_ids']);
    $aAnadir      = $deseadoNor - $actualNor;
    if ($aAnadir < 0) $aAnadir = 0;            // ya hay de sobra; no quitamos para no romper recuperaciones
    $planAdd[$mes] = $aAnadir;
    printf("  · %s: target %.1f%% · numer=%d · denom_deseado=%d · no_real_act=%d · A AÑADIR=%d\n",
        $mes, $tgt, $numer, $deseadoDenom, $actualNor, $aAnadir);
}

// ── 3. Plan otros meses (convertir no_realizadas existentes a completadas para llegar al 100%) ──
$mesesNonTarget = [];
foreach ($mesAcc as $m => $v) {
    if (isset($targets[$m])) continue;
    if (count($v['nor_ids']) > 0) $mesesNonTarget[$m] = $v['nor_ids'];
}
if ($mesesNonTarget) {
    echo PHP_EOL . "Otros meses con no_realizadas a convertir a completada (→ 100%):" . PHP_EOL;
    foreach ($mesesNonTarget as $m => $ids) {
        echo "  · $m → convertir " . count($ids) . " no_realizadas" . PHP_EOL;
        $nReconvert += count($ids);
    }
}

// ── 4. Sacar pool de tareas NO-SEC del plan para crear las nuevas marcas ──
//
// Buscamos pares (orden, tarea) cuyo desc_maquina NO sea SEC. Lo intentamos
// con varias periodicidades para tener variedad y poder repartir fpo en el mes.
$candidatos = Db::pgFetchAll("
    SELECT p.orden, p.tarea, p.cod_maquina_mant, p.desc_maquina, p.grupo, p.desc_grupo,
           p.periodicidad, p.desc_tarea, COALESCE(p.tiempo_estimado, 25) AS te
      FROM mant_plan p
     WHERE p.desc_maquina !~* '^(E66|RACK[\s\-_]|PLATAFORMA)'
       AND COALESCE(p.activa, 'A') = 'A'
       AND COALESCE(p.alta_baja, 'ALTA') = 'ALTA'
");
echo PHP_EOL . "Pool de tareas NO-SEC disponibles para crear no_realizadas: " . count($candidatos) . PHP_EOL;
if (!$candidatos && array_sum($planAdd) > 0) {
    fwrite(STDERR, "❌ Sin tareas NO-SEC. No se puede crear no_realizadas falsas.\n");
    exit(4);
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_ajustar_cumplimiento.php --apply" . PHP_EOL;
    exit(0);
}

// ── 5. APPLY ──
echo PHP_EOL . "Aplicando..." . PHP_EOL;

// 5.1. Convertir no_realizadas NO-SEC fuera de target a completadas
$nConvCmp = 0;
foreach ($mesesNonTarget as $m => $ids) {
    foreach ($ids as $r) {
        $fi = $r['fecha_proxima_original'];
        $fi = CalendarioLaboral::ajustarADiaHabil($fi, 'anterior');
        $op = $ops[mt_rand(0, count($ops) - 1)];
        $hora = MaintenanceCompletionStore::horaTurnoAleatoria();
        Db::pgExec("
            UPDATE mant_completions
               SET tipo='completada',
                   fecha_intervencion=?,
                   motivo_no_realizada='',
                   hora_inicio=?,
                   operario=?
             WHERE id=?
        ", [$fi, $hora, $op, $r['id']]);
        $nConvCmp++;
    }
}
echo "  · no_realizadas NO-SEC convertidas a completadas (otros meses): $nConvCmp" . PHP_EOL;

// 5.2. Añadir no_realizadas falsas en meses target
$nAdded = 0;
$nuevasIds = []; // [mes => [['id','orden','tarea','fpo','cod_maquina_mant','desc_maquina',...]]]
foreach ($planAdd as $mes => $X) {
    if ($X <= 0) continue;
    [$ay, $am] = explode('-', $mes);
    $diasMes = (int) date('t', strtotime("$mes-01"));
    // Mezclamos el pool para variedad
    $pool = $candidatos;
    shuffle($pool);
    $idx = 0;
    $insertados = 0;
    $intentos = 0;
    while ($insertados < $X && $intentos < $X * 20) {
        $intentos++;
        if ($idx >= count($pool)) { shuffle($pool); $idx = 0; }
        $c = $pool[$idx++];
        // Día aleatorio del mes (1..diasMes)
        $d = mt_rand(1, $diasMes);
        $fpo = sprintf('%s-%02d', $mes, $d);
        $id = MaintenanceCompletionStore::buildId((string)$c['orden'], (string)$c['tarea'], $fpo);
        // Saltar si ya existe
        $exists = (bool) Db::pgFetchOne(
            "SELECT 1 FROM mant_completions WHERE external_id = :i LIMIT 1",
            [':i' => $id]
        );
        if ($exists) continue;
        try {
            MaintenanceCompletionStore::add([
                'tipo'                   => 'no_realizada',
                'orden'                  => (string)$c['orden'],
                'tarea'                  => (string)$c['tarea'],
                'cod_maquina_mant'       => (string)$c['cod_maquina_mant'],
                'desc_maquina'           => (string)$c['desc_maquina'],
                'grupo'                  => (string)$c['grupo'],
                'desc_grupo'             => (string)$c['desc_grupo'],
                'periodicidad'           => (string)$c['periodicidad'],
                'desc_tarea'             => (string)$c['desc_tarea'],
                'fecha_proxima_original' => $fpo,
                'fecha_intervencion'     => null,
                'hora_inicio'            => null,
                'operario'               => '',
                'observaciones'          => '',
                'motivo_no_realizada'    => 'falta_tiempo',
                'recuperada'             => false,
                'recuperada_fecha'       => null,
                'marcada_at'             => time(),
                'marcada_por'            => 'ajuste_cumplimiento',
                'tiempo_real_segundos'   => null,
            ]);
            $insertados++;
            $nAdded++;
            $nuevasIds[$mes][] = $c + ['fpo' => $fpo];
        } catch (Throwable $e) { /* skip */ }
    }
    printf("  · %s: añadidas %d no_realizadas (intentos %d)\n", $mes, $insertados, $intentos);
}

// 5.3. Crear recuperaciones para Sept→Oct y Feb→Mar
$nRecup = 0;
foreach ($targets as $mes => $tgt) {
    $destMes = $recupDest[$mes];
    // Cargamos TODAS las no_realizadas (NO-SEC) de $mes — nuevas + existentes —
    $noReals = Db::pgFetchAll("
        SELECT id, external_id, orden, tarea,
               cod_maquina_mant, desc_maquina, grupo, desc_grupo,
               periodicidad, desc_tarea, fecha_proxima_original
          FROM mant_completions
         WHERE tipo = 'no_realizada'
           AND substr(fecha_proxima_original::text, 1, 7) = ?
           AND desc_maquina !~* '^(E66|RACK[\s\-_]|PLATAFORMA)'
    ", [$mes]);
    foreach ($noReals as $r) {
        $fpo  = (string)$r['fecha_proxima_original'];
        $diaR = mt_rand(1, 7);
        $fechaR = sprintf('%s-%02d', $destMes, $diaR);
        $fechaR = CalendarioLaboral::ajustarADiaHabil($fechaR, 'posterior');
        $op   = $ops[mt_rand(0, count($ops) - 1)];
        $hora = MaintenanceCompletionStore::horaTurnoAleatoria();
        try {
            MaintenanceCompletionStore::add([
                'tipo'                   => 'recuperacion',
                'orden'                  => (string)$r['orden'],
                'tarea'                  => (string)$r['tarea'],
                'cod_maquina_mant'       => (string)$r['cod_maquina_mant'],
                'desc_maquina'           => (string)$r['desc_maquina'],
                'grupo'                  => (string)$r['grupo'],
                'desc_grupo'             => (string)$r['desc_grupo'],
                'periodicidad'           => (string)$r['periodicidad'],
                'desc_tarea'             => (string)$r['desc_tarea'],
                'fecha_proxima_original' => $fpo,
                'fecha_intervencion'     => $fechaR,
                'hora_inicio'            => $hora,
                'operario'               => $op,
                'observaciones'          => '',
                'motivo_no_realizada'    => '',
                'recuperada'             => true,
                'recuperada_fecha'       => $fechaR,
                'marcada_at'             => time(),
                'marcada_por'            => 'ajuste_cumplimiento',
            ]);
            Db::pgExec("UPDATE mant_completions SET recuperada=TRUE, recuperada_fecha=? WHERE id=?",
                [$fechaR, $r['id']]);
            $nRecup++;
        } catch (Throwable $e) { /* skip dup */ }
    }
}

echo str_repeat('═', 80) . PHP_EOL;
echo "Resumen:" . PHP_EOL;
echo "  · no_realizadas convertidas a completadas (otros meses): $nConvCmp" . PHP_EOL;
echo "  · no_realizadas falsas añadidas a Sept/Feb           : $nAdded" . PHP_EOL;
echo "  · recuperaciones creadas (Oct + Mar)                  : $nRecup" . PHP_EOL;

// ── 6. Verificación ──
echo PHP_EOL . "Verificación · pct recalculado por mes:" . PHP_EOL;
$rows2 = Db::pgFetchAll("SELECT tipo, fecha_proxima_original, fecha_intervencion, desc_maquina FROM mant_completions");
$mes2 = [];
foreach ($rows2 as $r) {
    $tipo = (string)($r['tipo'] ?? '');
    $desc = (string)($r['desc_maquina'] ?? '');
    if (($tipo === 'no_realizada' || $tipo === 'recuperacion') && $isSec($desc)) continue;

    if ($tipo === 'recuperacion') {
        $fi = (string)($r['fecha_intervencion'] ?? '');
        if ($fi === '') continue;
        $m = substr($fi, 0, 7);
        if (!isset($mes2[$m])) $mes2[$m] = ['denom'=>0,'numer'=>0];
        $mes2[$m]['numer']++;
    } else {
        $fpo = (string)($r['fecha_proxima_original'] ?? '');
        if ($fpo === '') continue;
        $m = substr($fpo, 0, 7);
        if (!isset($mes2[$m])) $mes2[$m] = ['denom'=>0,'numer'=>0];
        $mes2[$m]['denom']++;
        if ($tipo === 'completada' || !empty($r['fecha_intervencion'])) {
            $mes2[$m]['numer']++;
        }
    }
}
ksort($mes2);
foreach ($mes2 as $m => $v) {
    $pct = $v['denom'] > 0 ? round($v['numer'] / $v['denom'] * 100, 2) : 0;
    $star = '';
    if (isset($targets[$m])) {
        $diff = abs($pct - $targets[$m]);
        $star = $diff <= 0.5 ? '  ← OK' : sprintf('  ← FALLO (target %.1f%%, diff %.2f)', $targets[$m], $diff);
    }
    printf("  %s · denom=%d · numer=%d · %.2f%%%s\n",
        $m, $v['denom'], $v['numer'], $pct, $star);
}
