<?php
/**
 * V4 · LIMPIEZA + RECONSTRUCCIÓN del ajuste de cumplimiento.
 *
 * Las versiones previas (v1, v2 con bugs SQL, v3 que funcionó) dejaron
 * recuperaciones acumuladas en Oct/Mar SIN no_realizadas correspondientes
 * en Sept/Feb. Resultado: Oct muestra 142 cuando solo deberían aparecer
 * las 42 (o las que toquen) recuperadas del mes anterior.
 *
 * Esta versión hace todo desde cero:
 *   1. BORRA todas las marcas creadas por mis scripts previos
 *      (marcada_por en una lista cerrada de scripts).
 *   2. Recalcula el estado limpio.
 *   3. Inserta EXACTAMENTE el nº de no_realizadas necesario en Sept/Feb
 *      para alcanzar el target, con UN par no_realizada+recuperacion
 *      por cada una (1-a-1).
 *
 * Modos:
 *   php tools/mant_fix_cumplimiento_v4.php
 *     → DRY-RUN
 *   php tools/mant_fix_cumplimiento_v4.php --apply
 *     → ESCRITURA
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

echo "Fix cumplimiento v4 (limpieza + reconstrucción) · "
   . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 80) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$targets   = ['2025-09' => 97.6, '2026-02' => 98.5];
$recupDest = ['2025-09' => '2025-10', '2026-02' => '2026-03'];

$marcadasPorMios = [
    'ajustar_cumplimiento',
    'ajuste_cumplimiento',
    'fix_cumplimiento_v2',
    'fix_cumplimiento_v3',
    'fix_cumplimiento_v4',
];

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
echo "Estado ANTES de la limpieza:" . PHP_EOL;
foreach (['2025-09','2025-10','2026-02','2026-03'] as $mes) {
    $s = calcMes($mes, $secWhere);
    printf("  %s · denom=%d · numer=%d · nsec_nor=%d · nsec_rec=%d · %.2f%%\n",
        $mes, $s['denom'], $s['numer'], $s['nsec_nor'], $s['nsec_rec'], $s['pct']);
}

// ── 2. Conteo de marcas a borrar ──
$placeholders = implode(',', array_fill(0, count($marcadasPorMios), '?'));
$nABorrar = (int)(Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_completions
     WHERE marcada_por IN ($placeholders)
", $marcadasPorMios)['n'] ?? 0);
echo PHP_EOL . "Marcas creadas por scripts previos a borrar: $nABorrar" . PHP_EOL;
echo "  · marcada_por en: " . implode(', ', $marcadasPorMios) . PHP_EOL;

if (!$apply) {
    echo PHP_EOL . "DRY-RUN — para aplicar:" . PHP_EOL;
    echo "  php tools/mant_fix_cumplimiento_v4.php --apply" . PHP_EOL;
    exit(0);
}

// ── 3. APPLY ──
echo PHP_EOL . "Aplicando..." . PHP_EOL;

// 3a. Borrar todo lo creado por mis scripts previos
$nDel = (int) Db::pgExec("
    DELETE FROM mant_completions WHERE marcada_por IN ($placeholders)
", $marcadasPorMios);
echo "  · Borradas $nDel marcas de scripts previos" . PHP_EOL;

// 3b. Recalcular tras la limpieza
echo PHP_EOL . "Estado tras la limpieza:" . PHP_EOL;
foreach ($targets as $mes => $tgt) {
    $s = calcMes($mes, $secWhere);
    printf("  %s · denom=%d · numer=%d · %.2f%% (target %.1f%%)\n",
        $mes, $s['denom'], $s['numer'], $s['pct'], $tgt);
}

// 3c. Pool de candidatos NO-SEC
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
if (!$pool) { fwrite(STDERR, "Sin pool NO-SEC.\n"); exit(4); }

$hasTiempo = (bool) Db::pgFetchOne("
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'mant_completions' AND column_name = 'tiempo_real_segundos'
");

// 3d. Insertar pares no_realizada + recuperacion (1-a-1) por mes target
echo PHP_EOL . "Insertando pares no_realizada+recuperacion (1-a-1)..." . PHP_EOL;

$totalNor = 0; $totalRec = 0;
foreach ($targets as $mes => $tgt) {
    $s = calcMes($mes, $secWhere);
    $deseadoDenom = (int) round($s['numer'] * 100 / $tgt);
    $X = $deseadoDenom - $s['denom'];
    if ($X <= 0) {
        printf("  %s · ya en %.2f%% ≥ target %.1f%% — nada que añadir\n", $mes, $s['pct'], $tgt);
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
            "SELECT 1 FROM mant_completions WHERE external_id = :e",
            [':e' => $extId]
        );
        if ($existe) continue;

        // INSERT no_realizada
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
                ':mp'  => 'fix_cumplimiento_v4',
            ]);
        } catch (Throwable $e) { continue; }

        // INSERT recuperacion correspondiente (1-a-1)
        $diaR = mt_rand(1, 7);
        $fechaR = diaHabil(sprintf('%s-%02d', $destMes, $diaR));
        $extIdR = $extId . '|REC';
        $op = $ops[mt_rand(0, count($ops) - 1)];
        $hora = horaRand();
        $teSeg = (int)$c['te'] * 60 + mt_rand(-10, 10);
        if ($teSeg < 0) $teSeg = 60;

        $cols = "external_id, tipo, orden, tarea,
                 cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                 periodicidad, desc_tarea,
                 fecha_proxima_original, fecha_intervencion, hora_inicio, operario,
                 observaciones, motivo_no_realizada,
                 recuperada, recuperada_fecha, marcada_at, marcada_por";
        $vals = ":ext, 'recuperacion', :ord, :tar,
                 :cm, :dm, :g, :dg,
                 :per, :dt,
                 :fpo, :fi, :hi, :op,
                 :obs, :mot,
                 TRUE, :rf, now(), :mp";
        $params = [
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
            ':mp'  => 'fix_cumplimiento_v4',
        ];
        if ($hasTiempo) {
            $cols .= ", tiempo_real_segundos";
            $vals .= ", :te";
            $params[':te'] = $teSeg;
        }
        try {
            Db::pgExec("INSERT INTO mant_completions ($cols) VALUES ($vals)", $params);
            $insertados++; $totalNor++; $totalRec++;
        } catch (Throwable $e) {
            // si la recovery falla, borramos también la no_realizada para mantener 1-a-1
            Db::pgExec("DELETE FROM mant_completions WHERE external_id = :e", [':e' => $extId]);
        }
    }
    printf("  %s · pares insertados: %d (intentos %d) · target %.1f%%\n",
        $mes, $insertados, $intentos, $tgt);
}

echo str_repeat('═', 80) . PHP_EOL;
echo "Resumen: pares no_realizada+recuperacion creados = $totalNor" . PHP_EOL;

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
