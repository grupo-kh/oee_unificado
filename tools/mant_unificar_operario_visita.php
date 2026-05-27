<?php
/**
 * Unifica el operario por visita en mant_completions.
 *
 * Una visita real (rack o cualquier máquina) la hace UN operario que
 * ejecuta todas las sub-tareas del día. Los seeds pueden haber asignado
 * operarios aleatorios distintos a cada sub-tarea del mismo día; esto
 * hace que en la consolidación del histórico aparezcan como visitas
 * múltiples.
 *
 * Para cada (cod_maquina_mant, fecha_intervencion) el script:
 *   - Cuenta cuántas marcas hay por operario.
 *   - Elige el operario más frecuente (o el primero alfabéticamente
 *     en caso de empate).
 *   - UPDATE en todas las filas del grupo con ese operario.
 *
 * Modos:
 *   php tools/mant_unificar_operario_visita.php
 *     → DRY-RUN. Muestra grupos a unificar.
 *
 *   php tools/mant_unificar_operario_visita.php --apply
 *     → ESCRITURA.
 *
 *   php tools/mant_unificar_operario_visita.php --apply --maquina-like='RACK%'
 *     → Solo unifica máquinas que coincidan con el patrón ILIKE.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);
$mLike = null;
foreach ($argv as $a) {
    if (preg_match('/^--maquina-like=(.+)$/', $a, $m)) $mLike = $m[1];
}

echo "Unificar operario por visita · " . ($apply ? "ESCRITURA" : "DRY-RUN");
if ($mLike) echo " · ILIKE '$mLike'";
echo PHP_EOL . str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// Encontrar grupos por (cod_maquina_mant, fecha_intervencion) con >1 operario
$params = [];
$where  = "fecha_intervencion IS NOT NULL";
if ($mLike) {
    $where .= " AND desc_maquina ILIKE :mlike";
    $params[':mlike'] = $mLike;
}

$dup = Db::pgFetchAll("
    SELECT cod_maquina_mant,
           to_char(fecha_intervencion, 'YYYY-MM-DD') AS fi,
           COUNT(DISTINCT operario) AS n_op,
           COUNT(*)                 AS n_filas
      FROM mant_completions
     WHERE $where AND operario IS NOT NULL AND operario <> ''
     GROUP BY cod_maquina_mant, fecha_intervencion
    HAVING COUNT(DISTINCT operario) > 1
     ORDER BY n_op DESC, n_filas DESC
", $params);

echo "Visitas con >1 operario: " . count($dup) . PHP_EOL;

if (empty($dup)) {
    echo "Todas las visitas ya tienen un operario único." . PHP_EOL;
    exit(0);
}

// Sample
echo PHP_EOL . "Sample (top 10):" . PHP_EOL;
foreach (array_slice($dup, 0, 10) as $d) {
    printf("  · %s @ %s → %d operarios distintos, %d filas\n",
        $d['cod_maquina_mant'], $d['fi'], $d['n_op'], $d['n_filas']);
}

if ($apply) {
    echo PHP_EOL . "Unificando..." . PHP_EOL;
    $unif = 0; $tocadas = 0;
    foreach ($dup as $d) {
        // Encontrar el operario más frecuente en ese grupo
        $best = Db::pgFetchOne("
            SELECT operario, COUNT(*) AS n
              FROM mant_completions
             WHERE cod_maquina_mant = :cm
               AND fecha_intervencion = :fi
               AND operario IS NOT NULL AND operario <> ''
             GROUP BY operario
             ORDER BY COUNT(*) DESC, operario ASC
             LIMIT 1
        ", [':cm' => $d['cod_maquina_mant'], ':fi' => $d['fi']]);
        if (!$best) continue;
        $op = (string)$best['operario'];

        // UPDATE todas las del grupo a ese operario
        Db::pgExec("
            UPDATE mant_completions
               SET operario = :op
             WHERE cod_maquina_mant = :cm
               AND fecha_intervencion = :fi
               AND operario IS DISTINCT FROM :op
        ", [':op' => $op, ':cm' => $d['cod_maquina_mant'], ':fi' => $d['fi']]);
        $unif++;
        $tocadas += (int)$d['n_filas'];
    }
    echo "  · Visitas unificadas: $unif (filas potencialmente tocadas: $tocadas)" . PHP_EOL;
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_unificar_operario_visita.php --apply"
        . ($mLike ? " --maquina-like='$mLike'" : "") . PHP_EOL;
}
