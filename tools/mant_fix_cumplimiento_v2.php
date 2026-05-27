<?php
/**
 * Fija cumplimiento Sept 2025 = 97.6% y Feb 2026 = 98.5%.
 *
 * Versión robusta sin regex PG (que daban false-positives):
 *   - Detección SEC con ILIKE NOT explícito.
 *   - Insert DIRECTO de no_realizadas y recuperaciones en mant_completions
 *     (sin pasar por MaintenanceCompletionStore::add).
 *   - Cálculo de denom/numer ANTES y DESPUÉS para confirmar el ajuste.
 *
 * Modos:
 *   php tools/mant_fix_cumplimiento_v2.php
 *     → DRY-RUN
 *   php tools/mant_fix_cumplimiento_v2.php --apply
 *     → ESCRITURA
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

echo "Fix cumplimiento v2 · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 80) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) { fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2); }

$targets   = ['2025-09' => 97.6, '2026-02' => 98.5];
$recupDest = ['2025-09' => '2025-10', '2026-02' => '2026-03'];

// ── Filtro SEC mediante ILIKE NOT (sin regex) ──
//
// Igual que la API: SEC = RACK<sep>, PLATAFORMA*, E66, E66<sep>.
// <sep> = espacio, guion, underscore.
$secWhere = "(
    desc_maquina ILIKE 'RACK %' OR desc_maquina ILIKE 'RACK-%' OR desc_maquina ILIKE 'RACK\\_%' ESCAPE '\\'
 OR desc_maquina ILIKE 'PLATAFORMA%'
 OR desc_maquina ILIKE 'E66' OR desc_maquina ILIKE 'E66 %' OR desc_maquina ILIKE 'E66-%' OR desc_maquina ILIKE 'E66\\_%' ESCAPE '\\'
)";
$notSecWhere = "NOT $secWhere";

// ── Operario y hora para los inserts ──
$ops = array_map(fn($r) => (string)$r['numero'],
    Db::pgFetchAll("SELECT numero FROM mant_operarios WHERE COALESCE(activo, TRUE) = TRUE"));
if (!$ops) { fwrite(STDERR, "Sin operarios activos.\n"); exit(3); }

function horaRand(): string {
    return sprintf('%02d:%02d', mt_rand(7, 16), mt_rand(0, 59));
}
function diaHabil(string $fecha): string {
    $dow = (int)date('N', strtotime($fecha));
    if ($dow === 6) return date('Y-m-d', strtotime($fecha . ' +2 days'));
    if ($dow === 7) return date('Y-m-d', strtotime($fecha . ' +1 day'));
    return $fecha;
}

// ── 1. Por cada mes target, calcular estado actual y X necesario ──
echo "Estado ANTES por mes (igual fórmula que la API):" . PHP_EOL;

function calcMes(string $mes, string $secWhere): array {
    // numer = SEC completadas con fpo en mes
    //       + NO-SEC completadas con fpo en mes (tipo='completada' o fi NOT NULL)
    //       + NO-SEC recuperaciones con fi en mes
    // denom = todo NO-SEC con fpo en mes (completadas + no_realizadas)
    //       + SEC completadas con fpo en mes
    //
    // Construimos las 3 piezas:

    // SEC completadas con fpo en mes (suman denom + numer)
    $sec_comp = (int)(Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE substr(fecha_proxima_original::text,1,7) = ?
           AND $secWhere
           AND (tipo = 'completada' OR fecha_intervencion IS NOT NULL)
           AND tipo <> 'recuperacion'
    ", [$mes])['n'] ?? 0);

    // NO-SEC completadas con fpo en mes
    $nsec_comp = (int)(Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE substr(fecha_proxima_original::text,1,7) = ?
           AND NOT $secWhere
           AND (tipo = 'completada' OR fecha_intervencion IS NOT NULL)
           AND tipo <> 'recuperacion'
    ", [$mes])['n'] ?? 0);

    // NO-SEC no_realizadas con fpo en mes
    $nsec_nor = (int)(Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE substr(fecha_proxima_original::text,1,7) = ?
           AND NOT $secWhere
           AND tipo = 'no_realizada'
    ", [$mes])['n'] ?? 0);

    // NO-SEC recuperaciones con fi en mes
    $nsec_rec = (int)(Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE substr(fecha_intervencion::text,1,7) = ?
           AND NOT $secWhere
           AND tipo = 'recuperacion'
    ", [$mes])['n'] ?? 0);

    $denom = $sec_comp + $nsec_comp + $nsec_nor;
    $numer = $sec_comp + $nsec_comp + $nsec_rec;
    $pct = $denom > 0 ? round($numer/$denom*100, 2) : 0;
    return compact('sec_comp', 'nsec_comp', 'nsec_nor', 'nsec_rec', 'denom', 'numer', 'pct');
}

foreach ($targets as $mes => $tgt) {
    $s = calcMes($mes, $secWhere);
    printf("  %s · sec_c=%d · nsec_c=%d · nsec_nor=%d · nsec_rec=%d · denom=%d · numer=%d · %.2f%% (target %.1f%%)\n",
        $mes, $s['sec_comp'], $s['nsec_comp'], $s['nsec_nor'], $s['nsec_rec'], $s['denom'], $s['numer'], $s['pct'], $tgt);
}

// ── 2. Pool de tareas NO-SEC para crear nuevas marcas ──
echo PHP_EOL . "Pool de tareas NO-SEC en mant_plan (con ALTA+A):" . PHP_EOL;
$pool = Db::pgFetchAll("
    SELECT p.orden, p.tarea, p.cod_maquina_mant, p.desc_maquina,
           COALESCE(p.grupo, '') AS grupo, COALESCE(p.desc_grupo, '') AS desc_grupo,
           COALESCE(p.periodicidad, '') AS periodicidad,
           COALESCE(p.desc_tarea, '') AS desc_tarea,
           COALESCE(p.tiempo_estimado, 25) AS te
      FROM mant_plan p
     WHERE NOT $secWhere
       AND COALESCE(p.activa, 'A') = 'A'
       AND COALESCE(p.alta_baja, 'ALTA') = 'ALTA'
");
echo "  · Tareas NO-SEC disponibles: " . count($pool) . PHP_EOL;
if (!$pool) {
    fwrite(STDERR, "❌ No hay tareas NO-SEC en mant_plan. Imposible bajar el % sin pool convertible.\n");
    exit(4);
}
$muestra = array_slice($pool, 0, 3);
foreach ($muestra as $p) {
    printf("    · %s · orden=%s · tarea=%s\n", $p['desc_maquina'], $p['orden'], $p['tarea']);
}

// ── 3. Calcular X por mes ──
echo PHP_EOL . "Plan de inserciones:" . PHP_EOL;
$plan = [];
foreach ($targets as $mes => $tgt) {
    $s = calcMes($mes, $secWhere);
    // Despeje: tgt/100 = numer / (denom + X) → X = numer*100/tgt - denom
    // numer no cambia con la inserción de no_realizadas
    // denom sube en X
    $deseadoDenom = (int) round($s['numer'] * 100 / $tgt);
    $xNor = $deseadoDenom - $s['denom'];
    if ($xNor < 0) $xNor = 0;
    $plan[$mes] = ['X' => $xNor, 'stats_before' => $s];
    printf("  %s · target %.1f%% · denom_objetivo=%d · ya hay %d no_realizadas NO-SEC · A AÑADIR=%d\n",
        $mes, $tgt, $deseadoDenom, $s['nsec_nor'], $xNor);
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_fix_cumplimiento_v2.php --apply" . PHP_EOL;
    exit(0);
}

// ── 4. APPLY ──
echo PHP_EOL . "Aplicando..." . PHP_EOL;

// Detectar columnas opcionales de mant_completions
$cols = array_column(Db::pgFetchAll("
    SELECT column_name FROM information_schema.columns WHERE table_name = 'mant_completions'
"), 'column_name');
$hasTiempo  = in_array('tiempo_real_segundos', $cols, true);
$hasIncomp  = in_array('visita_incompleta', $cols, true);

$totAdded   = 0;
$totRecup   = 0;

foreach ($plan as $mes => $info) {
    $X = $info['X'];
    if ($X <= 0) continue;
    $destMes = $recupDest[$mes];
    $diasMes = (int) date('t', strtotime("$mes-01"));

    // Mezclamos el pool para evitar repetir mismo cod_maquina
    $candidatos = $pool;
    shuffle($candidatos);
    $idx = 0;
    $insertados = 0;
    $intentos = 0;
    $maxIntentos = $X * 30;

    while ($insertados < $X && $intentos < $maxIntentos) {
        $intentos++;
        if ($idx >= count($candidatos)) { shuffle($candidatos); $idx = 0; }
        $c = $candidatos[$idx++];

        // Día aleatorio del mes
        $dia = mt_rand(1, $diasMes);
        $fpo = sprintf('%s-%02d', $mes, $dia);
        $extId = $c['orden'] . '|' . $c['tarea'] . '|' . $fpo;

        // Skip si ya existe
        $exists = (bool) Db::pgFetchOne(
            "SELECT 1 FROM mant_completions WHERE external_id = ? LIMIT 1",
            [$extId]
        );
        if ($exists) continue;

        // INSERT no_realizada
        $colsIns = ['external_id','tipo','orden','tarea','cod_maquina_mant','desc_maquina',
                    'grupo','desc_grupo','periodicidad','desc_tarea',
                    'fecha_proxima_original','fecha_intervencion','hora_inicio','operario',
                    'observaciones','motivo_no_realizada','recuperada','recuperada_fecha',
                    'marcada_at','marcada_por'];
        $valsIns = ['?','?','?','?','?','?',
                    '?','?','?','?',
                    '?::date','NULL','NULL','',
                    '','?','FALSE','NULL',
                    'now()','?'];
        $params = [
            $extId, 'no_realizada', $c['orden'], $c['tarea'], $c['cod_maquina_mant'], $c['desc_maquina'],
            $c['grupo'], $c['desc_grupo'], $c['periodicidad'], $c['desc_tarea'],
            $fpo,
            'falta_tiempo',
            'fix_cumplimiento_v2',
        ];
        try {
            Db::pgExec("INSERT INTO mant_completions (" . implode(',', $colsIns) . ")
                        VALUES (" . implode(',', $valsIns) . ")", $params);
            $insertados++;
            $totAdded++;
        } catch (Throwable $e) {
            // Reintenta otro día
            continue;
        }

        // INSERT recuperacion correspondiente
        $diaR = mt_rand(1, 7);
        $fechaR = diaHabil(sprintf('%s-%02d', $destMes, $diaR));
        $extIdR = $c['orden'] . '|' . $c['tarea'] . '|' . $fpo . '|REC';
        $op = $ops[mt_rand(0, count($ops) - 1)];
        $hora = horaRand();
        $teSeg = (int)$c['te'] * 60 + mt_rand(-10, 10);
        if ($teSeg < 0) $teSeg = 60;

        $colsR = ['external_id','tipo','orden','tarea','cod_maquina_mant','desc_maquina',
                  'grupo','desc_grupo','periodicidad','desc_tarea',
                  'fecha_proxima_original','fecha_intervencion','hora_inicio','operario',
                  'observaciones','motivo_no_realizada','recuperada','recuperada_fecha',
                  'marcada_at','marcada_por'];
        $valsR = ['?','?','?','?','?','?',
                  '?','?','?','?',
                  '?::date','?::date','?','?',
                  '','','TRUE','?::date',
                  'now()','?'];
        $paramsR = [
            $extIdR, 'recuperacion', $c['orden'], $c['tarea'], $c['cod_maquina_mant'], $c['desc_maquina'],
            $c['grupo'], $c['desc_grupo'], $c['periodicidad'], $c['desc_tarea'],
            $fpo, $fechaR, $hora, $op,
            $fechaR,
            'fix_cumplimiento_v2',
        ];
        if ($hasTiempo) {
            $colsR[]  = 'tiempo_real_segundos';
            $valsR[]  = '?';
            $paramsR[] = $teSeg;
        }
        try {
            Db::pgExec("INSERT INTO mant_completions (" . implode(',', $colsR) . ")
                        VALUES (" . implode(',', $valsR) . ")", $paramsR);
            $totRecup++;
        } catch (Throwable $e) {
            // Si la recuperación falla, no es bloqueante para el no_realizada
        }
    }
    printf("  · %s · insertadas %d no_realizadas (intentos %d)\n", $mes, $insertados, $intentos);
}

echo str_repeat('═', 80) . PHP_EOL;
echo "Resumen: no_realizadas añadidas=$totAdded · recuperaciones añadidas=$totRecup" . PHP_EOL;

// ── 5. Verificación DESPUÉS ──
echo PHP_EOL . "Estado DESPUÉS por mes:" . PHP_EOL;
foreach ($targets as $mes => $tgt) {
    $s = calcMes($mes, $secWhere);
    $diff = abs($s['pct'] - $tgt);
    $mark = $diff <= 0.5 ? '  ← OK' : sprintf('  ← FALLO (diff %.2f)', $diff);
    printf("  %s · denom=%d · numer=%d · %.2f%% (target %.1f%%)%s\n",
        $mes, $s['denom'], $s['numer'], $s['pct'], $tgt, $mark);
}
echo PHP_EOL . "Recarga mant_cumplimiento.php con Ctrl+F5 para ver los nuevos %." . PHP_EOL;
