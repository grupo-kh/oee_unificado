<?php
/**
 * Restaura la invariante "no_realizada[M] == recuperacion[M+1]" para los meses
 * sept-2025 y feb-2026 del Cumplimiento Preventivo.
 *
 * Detecta cada recuperación cuyo external_id termina en '|REC' (creada por
 * fix_cumplimiento_v5) y cuya pareja `no_realizada` (mismo external_id sin
 * el sufijo) NO existe en la BD. Recrea esa no_realizada copiando los datos
 * desde la propia recuperación.
 *
 * Idempotente: si la pareja existe, no hace nada.
 *
 * Uso:
 *   php tools/mant_restaurar_invariante_recups.php              # dry-run
 *   php tools/mant_restaurar_invariante_recups.php --apply      # escribe
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

if (!defined('MANT_USE_PG') || !MANT_USE_PG) {
    fwrite(STDERR, "Este script requiere PostgreSQL (MANT_USE_PG=true)\n");
    exit(1);
}

$apply = in_array('--apply', $argv ?? [], true);

echo "Restaurar invariante recuperaciones · " . ($apply ? "APPLY" : "DRY-RUN") . "\n";
echo str_repeat('=', 78) . "\n";

$pairs = [
    '2025-10' => '2025-09',   // recups en oct → no_realizada en sept
    '2026-03' => '2026-02',   // recups en mar → no_realizada en feb
];

$totalCreadas = 0;

foreach ($pairs as $mesRec => $mesOrig) {
    echo "\n--- recups en $mesRec (esperan no_realizada en $mesOrig) ---\n";

    $recs = Db::pgFetchAll("
        SELECT r.*
          FROM mant_completions r
         WHERE r.tipo = 'recuperacion'
           AND r.external_id LIKE '%|REC'
           AND TO_CHAR(r.fecha_intervencion, 'YYYY-MM') = ?
    ", [$mesRec]);

    echo "Recuperaciones |REC en $mesRec: " . count($recs) . "\n";

    $missing = 0;
    foreach ($recs as $r) {
        $origExtId = substr($r['external_id'], 0, -strlen('|REC'));
        $exists = Db::pgFetchOne(
            "SELECT 1 FROM mant_completions WHERE external_id = ?",
            [$origExtId]
        );
        if ($exists) continue;

        $missing++;
        printf("  · faltante: %s (fpo=%s, máquina=%s)\n",
            $origExtId, $r['fecha_proxima_original'], $r['desc_maquina']);

        if (!$apply) continue;

        // Insertar la no_realizada usando los datos de la propia recuperación.
        // fecha_intervencion = NULL, motivo = 'falta_tiempo' (genérico).
        Db::pgExec("
            INSERT INTO mant_completions (
                external_id, tipo, orden, tarea,
                cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                periodicidad, desc_tarea, activa,
                fecha_proxima_original, fecha_intervencion, hora_inicio,
                operario, observaciones, motivo_no_realizada,
                recuperada, recuperada_fecha, marcada_at, marcada_por
            ) VALUES (
                :ext, 'no_realizada', :ord, :tar,
                :cmm, :dm, :g, :dg,
                :per, :dt, :act,
                :fpo, NULL, NULL,
                '', '', 'falta_tiempo',
                TRUE, :rf, NOW(), 'restaurar_invariante_v1'
            )
        ", [
            ':ext' => $origExtId,
            ':ord' => (string)$r['orden'],
            ':tar' => (string)$r['tarea'],
            ':cmm' => (string)$r['cod_maquina_mant'],
            ':dm'  => (string)$r['desc_maquina'],
            ':g'   => (string)($r['grupo'] ?? ''),
            ':dg'  => (string)($r['desc_grupo'] ?? ''),
            ':per' => (string)($r['periodicidad'] ?? ''),
            ':dt'  => (string)($r['desc_tarea'] ?? ''),
            ':act' => (string)($r['activa'] ?? 'A'),
            ':fpo' => (string)$r['fecha_proxima_original'],
            ':rf'  => (string)$r['fecha_intervencion'],
        ]);
        $totalCreadas++;
    }
    printf("Pares completados ahora: %d / faltaban %d\n",
        $apply ? $missing : 0, $missing);
}

echo "\n" . str_repeat('=', 78) . "\n";
echo ($apply ? "APLICADO. " : "DRY-RUN. ") . "Total no_realizada recreadas: $totalCreadas\n";

if (!$apply) {
    echo "Para aplicar: php tools/mant_restaurar_invariante_recups.php --apply\n";
}

// Recomputo cumplimiento de los meses afectados con la MISMA lógica que la API:
//   - SEC se filtra SOLO en no_realizada y recuperacion (completada incluye SEC).
//   - denom = completadas (fpo en M) + no_realizadas no-SEC (fpo en M)
//   - numer = completadas (fpo en M, fi no nulo) + recuperaciones no-SEC (fi en M)
echo "\n--- Verificación (lógica API mant_cumplimiento_meses) ---\n";
foreach (['2025-09', '2026-02'] as $mes) {
    $sql = "
        WITH base AS (
            SELECT c.* FROM mant_completions c
             WHERE EXISTS (SELECT 1 FROM mant_maquinas mm WHERE mm.cod_maquina_mant = c.cod_maquina_mant)
               AND EXISTS (SELECT 1 FROM mant_plan p
                                WHERE p.cod_maquina_mant = c.cod_maquina_mant
                                  AND COALESCE(p.alta_baja,'ALTA')='ALTA'
                                  AND COALESCE(p.activa,'A')='A')
        )
        SELECT
          (SELECT COUNT(*) FROM base
            WHERE tipo = 'completada'
              AND TO_CHAR(fecha_proxima_original, 'YYYY-MM') = :m) AS compl_fpo,
          (SELECT COUNT(*) FROM base
            WHERE tipo = 'no_realizada'
              AND TO_CHAR(fecha_proxima_original, 'YYYY-MM') = :m
              AND NOT (desc_maquina ~* '^(E66([^A-Za-z0-9]|$)|RACK[ _-]|PLATAFORMA|TROLEY[ _-])')) AS nor_fpo,
          (SELECT COUNT(*) FROM base
            WHERE tipo = 'recuperacion'
              AND TO_CHAR(fecha_intervencion, 'YYYY-MM') = :m
              AND NOT (desc_maquina ~* '^(E66([^A-Za-z0-9]|$)|RACK[ _-]|PLATAFORMA|TROLEY[ _-])')) AS rec_fi
    ";
    $row = Db::pgFetchOne($sql, [':m' => $mes]);
    $compl = (int)$row['compl_fpo'];
    $nor = (int)$row['nor_fpo'];
    $rec = (int)$row['rec_fi'];
    $denom = $compl + $nor;
    $numer = $compl + $rec;
    $pct = $denom > 0 ? round($numer / $denom * 100, 2) : null;
    printf("  %s · compl_fpo=%d nor_fpo=%d rec_fi=%d → denom=%d numer=%d → %s%%\n",
        $mes, $compl, $nor, $rec, $denom, $numer, $pct ?? '-');
}
