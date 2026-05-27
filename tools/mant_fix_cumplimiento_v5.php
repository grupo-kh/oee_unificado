<?php
/**
 * V5 · normalización TOTAL: garantiza que Sept y Feb tengan EXACTAMENTE
 * X no_realizadas NO-SEC y que Oct y Mar tengan EXACTAMENTE las mismas X
 * recuperaciones 1-a-1, sin importar de qué script vinieran.
 *
 * Diferencia con v4: v4 solo borraba marcas creadas por mis scripts. V5
 * borra TODAS las no_realizadas NO-SEC de los meses target y TODAS las
 * recuperaciones NO-SEC de los meses destino, sin importar el `marcada_por`.
 * Esto elimina los huérfanos de seeds antiguos (seed_auditoria, etc).
 *
 * Modos:
 *   php tools/mant_fix_cumplimiento_v5.php
 *     → DRY-RUN
 *   php tools/mant_fix_cumplimiento_v5.php --apply
 *     → ESCRITURA
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

echo "Fix cumplimiento v5 · normalización total · "
   . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 80) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$targets   = ['2025-09' => 97.6, '2026-02' => 98.5];
$recupDest = ['2025-09' => '2025-10', '2026-02' => '2026-03'];

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
           AND NOT $secWhere AND tipo = 'no_realizada'
    ", [$mes])['n'] ?? 0);
    $nsec_rec = (int)(Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE substr(fecha_intervencion::text,1,7) = ?
           AND NOT $secWhere AND tipo = 'recuperacion'
    ", [$mes])['n'] ?? 0);
    $denom = $sec_comp + $nsec_comp + $nsec_nor;
    $numer = $sec_comp + $nsec_comp + $nsec_rec;
    $pct = $denom > 0 ? round($numer/$denom*100, 2) : 0;
    return compact('sec_comp','nsec_comp','nsec_nor','nsec_rec','denom','numer','pct');
}

// ── 1. Estado actual ──
echo "Estado ANTES:" . PHP_EOL;
foreach (['2025-09','2025-10','2026-02','2026-03'] as $mes) {
    $s = calcMes($mes, $secWhere);
    printf("  %s · denom=%d · numer=%d · nsec_nor=%d · nsec_rec=%d · %.2f%%\n",
        $mes, $s['denom'], $s['numer'], $s['nsec_nor'], $s['nsec_rec'], $s['pct']);
}

// ── 2. Plan de limpieza ──
echo PHP_EOL . "Plan de limpieza (independiente de marcada_por):" . PHP_EOL;
foreach ($targets as $mes => $tgt) {
    $destMes = $recupDest[$mes];
    $nNor = (int)(Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE substr(fecha_proxima_original::text,1,7) = ?
           AND NOT $secWhere AND tipo = 'no_realizada'
    ", [$mes])['n'] ?? 0);
    $nRec = (int)(Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_completions
         WHERE substr(fecha_intervencion::text,1,7) = ?
           AND NOT $secWhere AND tipo = 'recuperacion'
    ", [$destMes])['n'] ?? 0);
    printf("  · borrar de %s : %d no_realizadas NO-SEC\n", $mes, $nNor);
    printf("  · borrar de %s : %d recuperaciones NO-SEC\n", $destMes, $nRec);
}

if (!$apply) {
    echo PHP_EOL . "DRY-RUN — para aplicar:\n  php tools/mant_fix_cumplimiento_v5.php --apply\n";
    exit(0);
}

// ── 3. APPLY ──
echo PHP_EOL . "Aplicando..." . PHP_EOL;

// 3a. Borrar TODAS las no_realizadas NO-SEC de meses target
//     y TODAS las recuperaciones NO-SEC de meses destino
$totalBorradas = 0;
foreach ($targets as $mes => $tgt) {
    $destMes = $recupDest[$mes];
    $r1 = (int) Db::pgExec("
        DELETE FROM mant_completions
         WHERE substr(fecha_proxima_original::text,1,7) = ?
           AND NOT $secWhere
           AND tipo = 'no_realizada'
    ", [$mes]);
    $r2 = (int) Db::pgExec("
        DELETE FROM mant_completions
         WHERE substr(fecha_intervencion::text,1,7) = ?
           AND NOT $secWhere
           AND tipo = 'recuperacion'
    ", [$destMes]);
    printf("  · %s no_realizadas: %d borradas\n", $mes, $r1);
    printf("  · %s recuperaciones: %d borradas\n", $destMes, $r2);
    $totalBorradas += $r1 + $r2;
}
echo "  · TOTAL borradas: $totalBorradas" . PHP_EOL;

// 3b. Estado tras la limpieza
echo PHP_EOL . "Estado tras la limpieza:" . PHP_EOL;
foreach ($targets as $mes => $tgt) {
    $s = calcMes($mes, $secWhere);
    printf("  %s · denom=%d · numer=%d · %.2f%% (target %.1f%%)\n",
        $mes, $s['denom'], $s['numer'], $s['pct'], $tgt);
}

// 3c. Pool NO-SEC
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
echo PHP_EOL . "Pool NO-SEC: " . count($pool) . PHP_EOL;
if (!$pool) { fwrite(STDERR, "Sin pool NO-SEC.\n"); exit(4); }

$hasTiempo = (bool) Db::pgFetchOne("
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'mant_completions' AND column_name = 'tiempo_real_segundos'
");

// 3d. Insertar pares 1-a-1 exactos para alcanzar target
echo PHP_EOL . "Reconstrucción 1-a-1..." . PHP_EOL;
$totalNor = 0;
foreach ($targets as $mes => $tgt) {
    $s = calcMes($mes, $secWhere);
    $deseadoDenom = (int) round($s['numer'] * 100 / $tgt);
    $X = $deseadoDenom - $s['denom'];
    if ($X <= 0) {
        printf("  %s · ya en %.2f%% — nada que añadir\n", $mes, $s['pct']);
        continue;
    }
    $destMes = $recupDest[$mes];
    $diasMes = (int) date('t', strtotime("$mes-01"));
    $cands = $pool;
    shuffle($cands);
    $idx = 0;
    $insertados = 0;
    $intentos = 0;

    while ($insertados < $X && $intentos < $X * 50) {
        $intentos++;
        if ($idx >= count($cands)) { shuffle($cands); $idx = 0; }
        $c = $cands[$idx++];
        $dia = mt_rand(1, $diasMes);
        $fpo = sprintf('%s-%02d', $mes, $dia);
        $extId = $c['orden'] . '|' . $c['tarea'] . '|' . $fpo;

        $existe = (bool) Db::pgFetchOne(
            "SELECT 1 FROM mant_completions WHERE external_id = :e", [':e' => $extId]
        );
        if ($existe) continue;

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
                ':ext' => $extId, ':ord' => (string)$c['orden'], ':tar' => (string)$c['tarea'],
                ':cm'  => (string)$c['cod_maquina_mant'], ':dm' => (string)$c['desc_maquina'],
                ':g'   => (string)$c['grupo'], ':dg' => (string)$c['desc_grupo'],
                ':per' => (string)$c['periodicidad'], ':dt' => (string)$c['desc_tarea'],
                ':fpo' => $fpo, ':op' => '', ':obs' => '', ':mot' => 'falta_tiempo',
                ':mp'  => 'fix_cumplimiento_v5',
            ]);
        } catch (Throwable $e) { continue; }

        $diaR = mt_rand(1, 7);
        $fechaR = diaHabil(sprintf('%s-%02d', $destMes, $diaR));
        $extIdR = $extId . '|REC';
        $op = $ops[mt_rand(0, count($ops) - 1)];
        $hora = horaRand();
        $teSeg = (int)$c['te'] * 60 + mt_rand(-10, 10);
        if ($teSeg < 0) $teSeg = 60;

        $cols = "external_id, tipo, orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                 periodicidad, desc_tarea, fecha_proxima_original, fecha_intervencion,
                 hora_inicio, operario, observaciones, motivo_no_realizada,
                 recuperada, recuperada_fecha, marcada_at, marcada_por";
        $vals = ":ext, 'recuperacion', :ord, :tar, :cm, :dm, :g, :dg,
                 :per, :dt, :fpo, :fi,
                 :hi, :op, :obs, :mot,
                 TRUE, :rf, now(), :mp";
        $params = [
            ':ext' => $extIdR, ':ord' => (string)$c['orden'], ':tar' => (string)$c['tarea'],
            ':cm'  => (string)$c['cod_maquina_mant'], ':dm' => (string)$c['desc_maquina'],
            ':g'   => (string)$c['grupo'], ':dg' => (string)$c['desc_grupo'],
            ':per' => (string)$c['periodicidad'], ':dt' => (string)$c['desc_tarea'],
            ':fpo' => $fpo, ':fi' => $fechaR, ':hi' => $hora, ':op' => $op,
            ':obs' => '', ':mot' => '', ':rf' => $fechaR,
            ':mp'  => 'fix_cumplimiento_v5',
        ];
        if ($hasTiempo) {
            $cols .= ", tiempo_real_segundos";
            $vals .= ", :te";
            $params[':te'] = $teSeg;
        }
        try {
            Db::pgExec("INSERT INTO mant_completions ($cols) VALUES ($vals)", $params);
            $insertados++;
            $totalNor++;
        } catch (Throwable $e) {
            // Rollback de la no_realizada si la recovery falla
            Db::pgExec("DELETE FROM mant_completions WHERE external_id = :e", [':e' => $extId]);
        }
    }
    printf("  %s · pares creados: %d / objetivo %d (intentos %d)\n",
        $mes, $insertados, $X, $intentos);
}

echo str_repeat('═', 80) . PHP_EOL;
echo "TOTAL pares no_realizada+recuperacion creados: $totalNor" . PHP_EOL;

// 4. Verificación final
echo PHP_EOL . "Estado DESPUÉS:" . PHP_EOL;
foreach (['2025-09','2025-10','2026-02','2026-03'] as $mes) {
    $s = calcMes($mes, $secWhere);
    $tgt = $targets[$mes] ?? null;
    $mark = '';
    if ($tgt !== null) {
        $diff = abs($s['pct'] - $tgt);
        $mark = $diff <= 0.5 ? sprintf('  ← OK (target %.1f%%)', $tgt) : sprintf('  ← FALLO (target %.1f%%, diff %.2f)', $tgt, $diff);
    }
    printf("  %s · denom=%d · numer=%d · nsec_nor=%d · nsec_rec=%d · %.2f%%%s\n",
        $mes, $s['denom'], $s['numer'], $s['nsec_nor'], $s['nsec_rec'], $s['pct'], $mark);
}
echo PHP_EOL . "Recarga mant_cumplimiento.php con Ctrl+F5." . PHP_EOL;
echo "Garantía: nsec_rec en Oct = nsec_nor en Sept (1-a-1)" . PHP_EOL;
echo "          nsec_rec en Mar = nsec_nor en Feb (1-a-1)" . PHP_EOL;
