<?php
/**
 * Capa los tiempos de intervención a un máximo (default 30 minutos):
 *
 *   1. mant_plan.tiempo_estimado > MAX_MIN  →  MAX_MIN
 *      (UPDATE bulk a las tareas excedidas).
 *
 *   2. mant_completions.tiempo_real_segundos > MAX_SEG  →  recálculo:
 *      busca el tiempo_estimado actual de la tarea (ya capeado) y
 *      genera un nuevo tiempo_real con la regla normal (±5..10 seg
 *      sobre tiempo_estimado*60). Si la tarea no tiene tiempo_estimado,
 *      usa MAX_MIN como base.
 *
 * Modos:
 *   php tools/mant_cap_tiempos.php
 *     → DRY-RUN. Cuenta cuántas filas tocaría.
 *
 *   php tools/mant_cap_tiempos.php --apply
 *     → ESCRITURA. Cap por defecto = 30 min.
 *
 *   php tools/mant_cap_tiempos.php --apply --max=45
 *     → Cambia el máximo.
 *
 *   php tools/mant_cap_tiempos.php --apply --maquina-like='RACK%'
 *     → Solo las máquinas que coincidan con el patrón.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

$apply  = in_array('--apply', $argv, true);
$maxMin = 30;
$mLike  = null;
foreach ($argv as $a) {
    if (preg_match('/^--max=(\d{1,4})$/', $a, $m)) $maxMin = max(1, (int)$m[1]);
    if (preg_match('/^--maquina-like=(.+)$/', $a, $m)) $mLike = $m[1];
}
$maxSeg = $maxMin * 60;

echo "Cap de tiempos · max = $maxMin min ($maxSeg s) · " . ($apply ? "ESCRITURA" : "DRY-RUN");
if ($mLike) echo " · ILIKE '$mLike'";
echo PHP_EOL . str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// ── 1. mant_plan: contar y capear tiempo_estimado ──
$paramsPlan = [':max' => $maxMin];
$wherePlan  = "tiempo_estimado > :max";
if ($mLike) { $wherePlan .= " AND desc_maquina ILIKE :mlike"; $paramsPlan[':mlike'] = $mLike; }

$exPlan = Db::pgFetchAll("
    SELECT cod_maquina_mant, desc_maquina, orden, tarea, periodicidad, tiempo_estimado
      FROM mant_plan
     WHERE $wherePlan
     ORDER BY tiempo_estimado DESC
     LIMIT 30
", $paramsPlan);
$totalPlan = (int) (Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_plan WHERE $wherePlan
", $paramsPlan)['n'] ?? 0);

echo "1) mant_plan.tiempo_estimado > $maxMin min: $totalPlan tareas excedidas" . PHP_EOL;
if ($exPlan) {
    echo "   Top excedidos:" . PHP_EOL;
    foreach (array_slice($exPlan, 0, 12) as $e) {
        printf("   · %-35s  tarea=%s  per=%-12s  te=%d min\n",
            mb_strimwidth((string)$e['desc_maquina'], 0, 33, '…'),
            $e['tarea'], $e['periodicidad'], (int)$e['tiempo_estimado']);
    }
}

if ($apply && $totalPlan > 0) {
    Db::pgExec("UPDATE mant_plan SET tiempo_estimado = :max WHERE $wherePlan", $paramsPlan);
    echo "   · Tareas capeadas a $maxMin min: $totalPlan" . PHP_EOL;
}

// ── 2. mant_completions: contar y recalcular tiempo_real ──
$paramsComp = [':maxs' => $maxSeg];
$whereComp  = "tiempo_real_segundos > :maxs";
if ($mLike) { $whereComp .= " AND desc_maquina ILIKE :mlike"; $paramsComp[':mlike'] = $mLike; }

$totalComp = (int) (Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_completions WHERE $whereComp
", $paramsComp)['n'] ?? 0);
echo PHP_EOL . "2) mant_completions.tiempo_real_segundos > $maxSeg s ($maxMin min): $totalComp marcas excedidas" . PHP_EOL;

$sampleComp = Db::pgFetchAll("
    SELECT external_id, desc_maquina, orden, tarea, tiempo_real_segundos
      FROM mant_completions
     WHERE $whereComp
     ORDER BY tiempo_real_segundos DESC
     LIMIT 10
", $paramsComp);
foreach ($sampleComp as $r) {
    $h = floor($r['tiempo_real_segundos'] / 3600);
    $m = floor(($r['tiempo_real_segundos'] % 3600) / 60);
    $extra = $h > 0 ? "{$h}h {$m}m" : "{$m}m";
    printf("   · %-35s  tarea=%s  tiempo=%d s (%s)\n",
        mb_strimwidth((string)$r['desc_maquina'], 0, 33, '…'),
        $r['tarea'], (int)$r['tiempo_real_segundos'], $extra);
}

if ($apply && $totalComp > 0) {
    echo "   Recalculando..." . PHP_EOL;
    // Para cada marca excedida, leer su tiempo_estimado actual (capeado ya)
    // y aplicar el decalaje. Si la tarea no tiene tiempo_estimado, usar maxMin.
    $rows = Db::pgFetchAll("
        SELECT mc.external_id, COALESCE(mp.tiempo_estimado, :max) AS te
          FROM mant_completions mc
          LEFT JOIN mant_plan mp ON mp.orden = mc.orden AND mp.tarea = mc.tarea
         WHERE mc.tiempo_real_segundos > :maxs
           " . ($mLike ? "AND mc.desc_maquina ILIKE :mlike" : ""),
        $mLike ? [':max' => $maxMin, ':maxs' => $maxSeg, ':mlike' => $mLike]
               : [':max' => $maxMin, ':maxs' => $maxSeg]
    );
    $n = 0;
    foreach ($rows as $r) {
        $te = max(1, (int)$r['te']);
        $nuevo = MaintenanceCompletionStore::aplicarDecalajeAleatorio($te * 60);
        Db::pgExec(
            "UPDATE mant_completions SET tiempo_real_segundos = :t WHERE external_id = :id",
            [':t' => $nuevo, ':id' => $r['external_id']]
        );
        $n++;
    }
    echo "   · Marcas recalculadas: $n" . PHP_EOL;
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_cap_tiempos.php --apply"
        . ($maxMin !== 30 ? " --max=$maxMin" : "")
        . ($mLike ? " --maquina-like='$mLike'" : "")
        . PHP_EOL;
}
