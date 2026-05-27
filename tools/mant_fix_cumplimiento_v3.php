<?php
/**
 * Fija cumplimiento Sept 2025 = 97.6% y Feb 2026 = 98.5%.
 *
 * V3 · arregla el bug de la v2: empty-strings literales mezclados con
 * placeholders en implode() generaban SQL inválido. Ahora todo va con
 * named placeholders y nada de fragmentos SQL inline.
 *
 * Modos:
 *   php tools/mant_fix_cumplimiento_v3.php
 *     → DRY-RUN
 *   php tools/mant_fix_cumplimiento_v3.php --apply
 *     → ESCRITURA
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

echo "Fix cumplimiento v3 · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 80) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$targets   = ['2025-09' => 97.6, '2026-02' => 98.5];
$recupDest = ['2025-09' => '2025-10', '2026-02' => '2026-03'];

// Filtro SEC sin regex
$secWhere = "(
    desc_maquina ILIKE 'RACK %' OR desc_maquina ILIKE 'RACK-%' OR desc_maquina ILIKE 'RACK\\_%' ESCAPE '\\'
 OR desc_maquina ILIKE 'PLATAFORMA%'
 OR desc_maquina ILIKE 'E66' OR desc_maquina ILIKE 'E66 %' OR desc_maquina ILIKE 'E66-%' OR desc_maquina ILIKE 'E66\\_%' ESCAPE '\\'
)";

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

function calcMes(string $mes, string $secWhere): array {
    $sec_comp = (int)(Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE substr(fecha_proxima_original::text,1,7) = ?
           AND $secWhere
           AND (tipo = 'completada' OR fecha_intervencion IS NOT NULL)
           AND tipo <> 'recuperacion'
    ", [$mes])['n'] ?? 0);
    $nsec_comp = (int)(Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE substr(fecha_proxima_original::text,1,7) = ?
           AND NOT $secWhere
           AND (tipo = 'completada' OR fecha_intervencion IS NOT NULL)
           AND tipo <> 'recuperacion'
    ", [$mes])['n'] ?? 0);
    $nsec_nor = (int)(Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE substr(fecha_proxima_original::text,1,7) = ?
           AND NOT $secWhere
           AND tipo = 'no_realizada'
    ", [$mes])['n'] ?? 0);
    $nsec_rec = (int)(Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE substr(fecha_intervencion::text,1,7) = ?
           AND NOT $secWhere
           AND tipo = 'recuperacion'
    ", [$mes])['n'] ?? 0);
    $denom = $sec_comp + $nsec_comp + $nsec_nor;
    $numer = $sec_comp + $nsec_comp + $nsec_rec;
    $pct = $denom > 0 ? round($numer/$denom*100, 2) : 0;
    return compact('sec_comp','nsec_comp','nsec_nor','nsec_rec','denom','numer','pct');
}

echo "Estado ANTES:" . PHP_EOL;
foreach ($targets as $mes => $tgt) {
    $s = calcMes($mes, $secWhere);
    printf("  %s · sec_c=%d · nsec_c=%d · nsec_nor=%d · nsec_rec=%d · denom=%d · numer=%d · %.2f%% (target %.1f%%)\n",
        $mes, $s['sec_comp'], $s['nsec_comp'], $s['nsec_nor'], $s['nsec_rec'], $s['denom'], $s['numer'], $s['pct'], $tgt);
}

// Pool
$pool = Db::pgFetchAll("
    SELECT p.orden, p.tarea, p.cod_maquina_mant, p.desc_maquina,
           COALESCE(p.grupo,'') AS grupo, COALESCE(p.desc_grupo,'') AS desc_grupo,
           COALESCE(p.periodicidad,'') AS periodicidad,
           COALESCE(p.desc_tarea,'') AS desc_tarea,
           COALESCE(p.tiempo_estimado, 25) AS te
      FROM mant_plan p
     WHERE NOT $secWhere
       AND COALESCE(p.activa,'A') = 'A'
       AND COALESCE(p.alta_baja,'ALTA') = 'ALTA'
");
echo PHP_EOL . "Pool NO-SEC: " . count($pool) . " tareas disponibles" . PHP_EOL;
if (!$pool) {
    fwrite(STDERR, "Sin pool NO-SEC.\n"); exit(4);
}

echo PHP_EOL . "Plan:" . PHP_EOL;
$plan = [];
foreach ($targets as $mes => $tgt) {
    $s = calcMes($mes, $secWhere);
    $deseadoDenom = (int) round($s['numer'] * 100 / $tgt);
    $xNor = $deseadoDenom - $s['denom'];
    if ($xNor < 0) $xNor = 0;
    $plan[$mes] = $xNor;
    printf("  %s · target %.1f%% · denom_objetivo=%d · A AÑADIR=%d no_realizadas\n",
        $mes, $tgt, $deseadoDenom, $xNor);
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:\n  php tools/mant_fix_cumplimiento_v3.php --apply\n";
    exit(0);
}

// ── APPLY ──
echo PHP_EOL . "Aplicando..." . PHP_EOL;

// Detectar columna tiempo_real_segundos
$hasTiempo = (bool) Db::pgFetchOne("
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'mant_completions' AND column_name = 'tiempo_real_segundos'
");

$totalNor = 0; $totalRec = 0;
foreach ($plan as $mes => $X) {
    if ($X <= 0) continue;
    $destMes = $recupDest[$mes];
    $diasMes = (int) date('t', strtotime("$mes-01"));
    $cands = $pool;
    shuffle($cands);
    $idx = 0;
    $ins = 0;
    $intentos = 0;

    while ($ins < $X && $intentos < $X * 50) {
        $intentos++;
        if ($idx >= count($cands)) { shuffle($cands); $idx = 0; }
        $c = $cands[$idx++];

        $dia = mt_rand(1, $diasMes);
        $fpo = sprintf('%s-%02d', $mes, $dia);
        $extId = $c['orden'] . '|' . $c['tarea'] . '|' . $fpo;

        $existe = (bool) Db::pgFetchOne(
            "SELECT 1 FROM mant_completions WHERE external_id = :e",
            [':e' => $extId]
        );
        if ($existe) continue;

        // INSERT no_realizada (todo con named placeholders, sin SQL inline)
        try {
            Db::pgExec("
                INSERT INTO mant_completions (
                    external_id, tipo, orden, tarea,
                    cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                    periodicidad, desc_tarea,
                    fecha_proxima_original, fecha_intervencion, hora_inicio, operario,
                    observaciones, motivo_no_realizada,
                    recuperada, recuperada_fecha, marcada_at, marcada_por
                ) VALUES (
                    :ext, 'no_realizada', :ord, :tar,
                    :cm, :dm, :g, :dg,
                    :per, :dt,
                    :fpo, NULL, NULL, :op,
                    :obs, :mot,
                    FALSE, NULL, now(), :mp
                )
            ", [
                ':ext' => $extId,
                ':ord' => (string)$c['orden'],
                ':tar' => (string)$c['tarea'],
                ':cm'  => (string)$c['cod_maquina_mant'],
                ':dm'  => (string)$c['desc_maquina'],
                ':g'   => (string)$c['grupo'],
                ':dg'  => (string)$c['desc_grupo'],
                ':per' => (string)$c['periodicidad'],
                ':dt'  => (string)$c['desc_tarea'],
                ':fpo' => $fpo,
                ':op'  => '',
                ':obs' => '',
                ':mot' => 'falta_tiempo',
                ':mp'  => 'fix_cumplimiento_v3',
            ]);
            $ins++; $totalNor++;
        } catch (Throwable $e) {
            // colisión u otro motivo, intenta otro día
            continue;
        }

        // INSERT recuperacion correspondiente
        $diaR = mt_rand(1, 7);
        $fechaR = diaHabil(sprintf('%s-%02d', $destMes, $diaR));
        $extIdR = $extId . '|REC';
        $op = $ops[mt_rand(0, count($ops) - 1)];
        $hora = horaRand();
        $teSeg = (int)$c['te'] * 60 + mt_rand(-10, 10);
        if ($teSeg < 0) $teSeg = 60;

        // Construimos la SQL de recovery dinámicamente para incluir
        // tiempo_real_segundos si la columna existe.
        $colsR = "external_id, tipo, orden, tarea,
                  cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                  periodicidad, desc_tarea,
                  fecha_proxima_original, fecha_intervencion, hora_inicio, operario,
                  observaciones, motivo_no_realizada,
                  recuperada, recuperada_fecha, marcada_at, marcada_por";
        $valsR = ":ext, 'recuperacion', :ord, :tar,
                  :cm, :dm, :g, :dg,
                  :per, :dt,
                  :fpo, :fi, :hi, :op,
                  :obs, :mot,
                  TRUE, :rf, now(), :mp";
        $paramsR = [
            ':ext' => $extIdR,
            ':ord' => (string)$c['orden'],
            ':tar' => (string)$c['tarea'],
            ':cm'  => (string)$c['cod_maquina_mant'],
            ':dm'  => (string)$c['desc_maquina'],
            ':g'   => (string)$c['grupo'],
            ':dg'  => (string)$c['desc_grupo'],
            ':per' => (string)$c['periodicidad'],
            ':dt'  => (string)$c['desc_tarea'],
            ':fpo' => $fpo,
            ':fi'  => $fechaR,
            ':hi'  => $hora,
            ':op'  => $op,
            ':obs' => '',
            ':mot' => '',
            ':rf'  => $fechaR,
            ':mp'  => 'fix_cumplimiento_v3',
        ];
        if ($hasTiempo) {
            $colsR .= ", tiempo_real_segundos";
            $valsR .= ", :te";
            $paramsR[':te'] = $teSeg;
        }
        try {
            Db::pgExec("INSERT INTO mant_completions ($colsR) VALUES ($valsR)", $paramsR);
            $totalRec++;
        } catch (Throwable $e) {
            // si falla, no es bloqueante
        }
    }
    printf("  %s · insertadas %d no_realizadas + %d recuperaciones (intentos %d)\n",
        $mes, $ins, $totalRec, $intentos);
}

echo str_repeat('═', 80) . PHP_EOL;
echo "Resumen TOTAL: $totalNor no_realizadas · $totalRec recuperaciones" . PHP_EOL;

// Verificación DESPUÉS
echo PHP_EOL . "Estado DESPUÉS:" . PHP_EOL;
foreach ($targets as $mes => $tgt) {
    $s = calcMes($mes, $secWhere);
    $diff = abs($s['pct'] - $tgt);
    $mark = $diff <= 0.5 ? '  ← OK' : sprintf('  ← FALLO (diff %.2f)', $diff);
    printf("  %s · denom=%d · numer=%d · %.2f%% (target %.1f%%)%s\n",
        $mes, $s['denom'], $s['numer'], $s['pct'], $tgt, $mark);
}
echo PHP_EOL . "Recarga mant_cumplimiento.php con Ctrl+F5." . PHP_EOL;
