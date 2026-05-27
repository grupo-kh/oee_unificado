<?php
/**
 * Deja UNA marca por (orden, tarea, año-mes) en tareas cuya periodicidad
 * implica como mucho una visita mensual:
 *   MENSUAL · BIMESTRAL · TRIMESTRAL · CUATRIMESTRAL · SEMESTRAL · ANUAL
 *
 * (SEMANAL y QUINCENAL se dejan intactas: permiten varias visitas en el
 * mismo mes legítimamente.)
 *
 * Política de "quién se queda":
 *   - Si hay una con `fecha_intervencion` no nula (visita REAL), la mantiene.
 *   - Si no, mantiene la que tenga la `fecha_proxima_original` más antigua.
 *   - Las demás se borran.
 *
 * Modos:
 *   php tools/mant_dedupe_marcas_por_mes.php
 *     → DRY-RUN
 *   php tools/mant_dedupe_marcas_por_mes.php --apply
 *     → ESCRITURA
 *   php tools/mant_dedupe_marcas_por_mes.php --apply --maquina="TICE K0"
 *     → Solo esa máquina
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);
$maqArg = null;
foreach ($argv as $a) {
    if (preg_match('/^--maquina=(.+)$/', $a, $m)) $maqArg = trim($m[1]);
}

$PERS = ['MENSUAL','BIMESTRAL','BIMENSUAL','TRIMESTRAL','CUATRIMESTRAL','SEMESTRAL','ANUAL'];

echo "===========================================" . PHP_EOL;
echo " Dedupe marcas: 1 por (orden, tarea, mes)" . PHP_EOL;
echo " Periodicidades: " . implode(', ', $PERS) . PHP_EOL;
if ($maqArg) echo " Filtro máquina: '$maqArg'" . PHP_EOL;
echo " Modo: " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo "===========================================" . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$inPers = "('" . implode("','", array_map(fn($p)=>addslashes($p),$PERS)) . "')";
$whereMaq = $maqArg ? "AND cod_maquina_mant = ?" : "";
$paramsMaq = $maqArg ? [$maqArg] : [];

// Detectar grupos con > 1 marca por (orden, tarea, año-mes)
$grupos = Db::pgFetchAll("
    SELECT orden, tarea, substr(fecha_proxima_original::text,1,7) AS ym,
           COUNT(*) AS n,
           string_agg(id::text, ',') AS ids
      FROM mant_completions
     WHERE periodicidad IN $inPers
       AND tipo IN ('completada','no_realizada')
       AND fecha_proxima_original IS NOT NULL
       $whereMaq
     GROUP BY orden, tarea, substr(fecha_proxima_original::text,1,7)
    HAVING COUNT(*) > 1
", $paramsMaq);

$totalGrupos = count($grupos);
$totalSobrantes = 0;
foreach ($grupos as $g) $totalSobrantes += ((int)$g['n']) - 1;

echo PHP_EOL . "Grupos (orden, tarea, mes) con duplicados: $totalGrupos" . PHP_EOL;
echo "Marcas sobrantes a eliminar:               $totalSobrantes" . PHP_EOL;

if ($totalGrupos === 0) {
    echo PHP_EOL . "Nada que deduplicar." . PHP_EOL;
    exit(0);
}

// Detalle hasta 8
echo PHP_EOL . "Primeros 8 grupos duplicados:" . PHP_EOL;
foreach (array_slice($grupos, 0, 8) as $g) {
    printf("  · orden=%s · tarea=%s · mes=%s · %d marcas\n",
        $g['orden'], $g['tarea'], $g['ym'], $g['n']);
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:\n  php tools/mant_dedupe_marcas_por_mes.php --apply\n";
    exit(0);
}

// ─── APPLY ───
echo PHP_EOL . "Aplicando..." . PHP_EOL;
$borradas = 0;

foreach ($grupos as $g) {
    $ids = array_map('intval', explode(',', (string)$g['ids']));
    if (count($ids) <= 1) continue;

    // Cargar los detalles para decidir cuál conservar
    $place = implode(',', array_fill(0, count($ids), '?'));
    $rows = Db::pgFetchAll("
        SELECT id, tipo, fecha_proxima_original, fecha_intervencion
          FROM mant_completions
         WHERE id IN ($place)
         ORDER BY id
    ", $ids);

    // Preferencia: la que tenga fi no nulo. Empate → la de fpo más antigua → menor id.
    usort($rows, function($a, $b) {
        $aHasFi = !empty($a['fecha_intervencion']) ? 0 : 1;
        $bHasFi = !empty($b['fecha_intervencion']) ? 0 : 1;
        if ($aHasFi !== $bHasFi) return $aHasFi - $bHasFi;
        $fa = (string)$a['fecha_proxima_original'];
        $fb = (string)$b['fecha_proxima_original'];
        if ($fa !== $fb) return strcmp($fa, $fb);
        return (int)$a['id'] - (int)$b['id'];
    });

    $kept = $rows[0];
    $toDelete = array_slice($rows, 1);
    $delIds = array_map(fn($r)=>(int)$r['id'], $toDelete);
    $placeDel = implode(',', array_fill(0, count($delIds), '?'));
    $r = Db::pgExec("DELETE FROM mant_completions WHERE id IN ($placeDel)", $delIds);
    $borradas += (int)$r;
}

echo "  ✓ Marcas eliminadas: $borradas" . PHP_EOL;

// Verificación
$rest = Db::pgFetchAll("
    SELECT orden, tarea, substr(fecha_proxima_original::text,1,7) AS ym, COUNT(*) AS n
      FROM mant_completions
     WHERE periodicidad IN $inPers
       AND tipo IN ('completada','no_realizada')
       AND fecha_proxima_original IS NOT NULL
       $whereMaq
     GROUP BY orden, tarea, substr(fecha_proxima_original::text,1,7)
    HAVING COUNT(*) > 1
", $paramsMaq);
echo PHP_EOL . "Verificación · grupos duplicados residuales: " . count($rest) . " (esperado 0)" . PHP_EOL;
