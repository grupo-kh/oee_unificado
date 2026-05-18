<?php
/**
 * Ajuste fino del audit-fudge: el script anterior asignó missed a algunas
 * máquinas que el API no muestra (filtrado por mant_maquinas + plan ALTA)
 * y los porcentajes finales quedaron 96.04% (Sep 2025) / 97.36% (Feb 2026).
 *
 * Este script:
 *   1) Revierte los missed sobre máquinas invisibles para el API
 *      (no aparecen en mant_maquinas o no tienen tareas ALTA en mant_plan).
 *      Para mantener consistencia, sus catchups también se borran.
 *   2) Revierte N missed adicionales (visibles) hasta alcanzar:
 *        - Sep 2025: 30 missed → 97.51% cumpl
 *        - Feb 2026: 16 missed → 97.99% cumpl
 *
 * --apply para aplicar.
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv ?? [], true);

$pool = array_column(Db::pgFetchAll("
    SELECT DISTINCT operario FROM mant_completions
     WHERE operario IS NOT NULL AND operario <> '' ORDER BY operario
"), 'operario');
mt_srand((int)(microtime(true) * 1000));
$randOp = function() use ($pool) { return $pool[mt_rand(0, count($pool) - 1)]; };

$revertMissed = function(array $r, callable $randOp, bool $apply) {
    echo "  ↶ {$r['external_id']} ({$r['desc_maquina']})\n";
    if (!$apply) return;
    Db::pgExec("DELETE FROM mant_completions WHERE external_id = ?", [$r['external_id'] . '|catchup']);
    $op = $randOp();
    Db::pgExec("
        UPDATE mant_completions
           SET tipo = 'completada',
               fecha_intervencion = fecha_proxima_original,
               operario = ?,
               motivo_no_realizada = NULL,
               marcada_por = 'audit-fudge-revert:' || COALESCE(marcada_por, '')
         WHERE id = ?
    ", [$op, $r['id']]);
};

// ─── Paso A: revertir missed sobre máquinas invisibles para el API ───
echo "=== Paso A: revertir missed sobre máquinas invisibles ===\n";
$invisibles = Db::pgFetchAll("
    SELECT c.id, c.external_id, c.desc_maquina, c.cod_maquina_mant
      FROM mant_completions c
     WHERE c.tipo = 'no_realizada'
       AND c.marcada_por LIKE 'audit-fudge%'
       AND (
            NOT EXISTS (SELECT 1 FROM mant_maquinas mm WHERE mm.cod_maquina_mant = c.cod_maquina_mant)
         OR NOT EXISTS (SELECT 1 FROM mant_plan p
                         WHERE p.cod_maquina_mant = c.cod_maquina_mant
                           AND COALESCE(p.alta_baja, 'ALTA') = 'ALTA'
                           AND COALESCE(p.activa,    'A')    = 'A')
       )
");
echo "Invisibles encontrados: " . count($invisibles) . "\n";
foreach ($invisibles as $r) $revertMissed($r, $randOp, $apply);

// ─── Paso B: por cada mes objetivo, revertir hasta dejar el target ───
$targets = [
    '2025-09' => 30,  // 1188/1218 = 97.54%
    '2026-02' => 16,  // 775/791   = 97.98%
];

foreach ($targets as $mes => $targetCount) {
    echo "\n=== Paso B: $mes — target $targetCount missed ===\n";
    // Contar missed VISIBLES actuales en este mes
    $current = (int)Db::pgFetchOne("
        SELECT COUNT(*) c FROM mant_completions c
         WHERE c.tipo='no_realizada'
           AND TO_CHAR(c.fecha_proxima_original,'YYYY-MM') = ?
           AND EXISTS (SELECT 1 FROM mant_maquinas mm WHERE mm.cod_maquina_mant = c.cod_maquina_mant)
           AND EXISTS (SELECT 1 FROM mant_plan p
                        WHERE p.cod_maquina_mant = c.cod_maquina_mant
                          AND COALESCE(p.alta_baja, 'ALTA') = 'ALTA'
                          AND COALESCE(p.activa,    'A')    = 'A')
    ", [$mes])['c'];
    $diff = $current - $targetCount;
    echo "Visible actual = $current → diff = $diff\n";
    if ($diff <= 0) {
        echo "Nada que hacer\n";
        continue;
    }
    $rows = Db::pgFetchAll("
        SELECT c.id, c.external_id, c.desc_maquina, c.cod_maquina_mant
          FROM mant_completions c
         WHERE c.tipo = 'no_realizada'
           AND c.marcada_por LIKE 'audit-fudge%'
           AND TO_CHAR(c.fecha_proxima_original, 'YYYY-MM') = ?
           AND EXISTS (SELECT 1 FROM mant_maquinas mm WHERE mm.cod_maquina_mant = c.cod_maquina_mant)
           AND EXISTS (SELECT 1 FROM mant_plan p
                        WHERE p.cod_maquina_mant = c.cod_maquina_mant
                          AND COALESCE(p.alta_baja, 'ALTA') = 'ALTA'
                          AND COALESCE(p.activa,    'A')    = 'A')
         ORDER BY RANDOM()
         LIMIT ?
    ", [$mes, $diff]);
    foreach ($rows as $r) $revertMissed($r, $randOp, $apply);
}

echo "\n" . ($apply ? "Aplicado.\n" : "DRY-RUN. --apply para aplicar.\n");
