<?php
/**
 * Cierra como "no realizadas" las tareas que llegaron a fin de mes sin
 * marcar. Para cada tarea de mant_plan con proxima_revision en el pasado,
 * no pausada, sin marca correspondiente, crea un registro tipo='no_realizada'
 * en mant_completions con fecha_proxima_original = la fecha vencida, y
 * AVANZA proxima_revision a la siguiente cadencia. Repite el proceso si el
 * avance sigue cayendo en mes ya cerrado, así una tarea con varios ciclos
 * perdidos recibe una no_realizada por cada ciclo.
 *
 * Pensado como tarea de fin de mes (manual o cron):
 *   - "Hoy" marca la frontera: cualquier proxima_revision < CURRENT_DATE
 *     en mes distinto al actual se considera cerrada y se "fija".
 *   - El mes en curso NO se toca (sus pendientes siguen siendo pendientes).
 *
 * Filtros:
 *   --mes=YYYY-MM       Solo procesa pendientes con fpo en ese mes.
 *   --solo-mes-anterior Solo procesa el mes anterior al actual.
 *   --incluir-mes-actual También cierra pendientes del mes en curso.
 *   --max-ciclos=N      Máx no_realizadas por tarea (default 50).
 *
 * Modos:
 *   php tools/mant_cerrar_pendientes_no_realizadas.php
 *     → DRY-RUN. Conteo de cuántas marcas no_realizada crearía.
 *   php tools/mant_cerrar_pendientes_no_realizadas.php --apply
 *     → ESCRITURA.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply           = in_array('--apply', $argv, true);
$soloMesAnterior = in_array('--solo-mes-anterior', $argv, true);
$incluirActual   = in_array('--incluir-mes-actual', $argv, true);
$mesArg          = null;
$maxCiclos       = 50;
foreach ($argv as $a) {
    if (preg_match('/^--mes=(\d{4}-\d{2})$/', $a, $m))     $mesArg     = $m[1];
    if (preg_match('/^--max-ciclos=(\d+)$/',  $a, $m))     $maxCiclos  = max(1, (int)$m[1]);
}

$hoy        = date('Y-m-d');
$mesActual  = date('Y-m');
$mesAnt     = date('Y-m', strtotime('first day of last month'));

echo "Cerrar pendientes como no_realizadas · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo "Hoy: $hoy · Mes actual: $mesActual";
if ($mesArg)            echo " · solo mes=$mesArg";
if ($soloMesAnterior)   echo " · solo mes anterior=$mesAnt";
if ($incluirActual)     echo " · INCLUIR mes actual";
echo " · max-ciclos=$maxCiclos" . PHP_EOL;
echo str_repeat('═', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

function cadenciaDias(string $per): int {
    switch (strtoupper(trim($per))) {
        case 'DIARIO': case 'DIARIA':       return 1;
        case 'SEMANAL':                     return 7;
        case 'QUINCENAL':                   return 15;
        case 'MENSUAL':                     return 30;
        case 'BIMESTRAL': case 'BIMENSUAL': return 60;
        case 'TRIMESTRAL':                  return 90;
        case 'CUATRIMESTRAL':               return 120;
        case 'SEMESTRAL':                   return 180;
        case 'ANUAL':                       return 365;
        default:                            return 30;
    }
}

// ── 1. Detectar tareas pendientes ──
$where = "p.proxima_revision IS NOT NULL
        AND p.proxima_revision <= CURRENT_DATE
        AND COALESCE(p.activa, 'A') = 'A'
        AND COALESCE(p.alta_baja, 'ALTA') = 'ALTA'
        AND p.fecha_pausado IS NULL
        AND NOT EXISTS (
              SELECT 1 FROM mant_completions c
               WHERE c.orden = p.orden AND c.tarea = p.tarea
                 AND c.fecha_proxima_original = p.proxima_revision
        )";

// Sin --incluir-mes-actual, el mes en curso NO se cierra
if (!$incluirActual && $mesArg === null && !$soloMesAnterior) {
    $where .= " AND substr(p.proxima_revision::text,1,7) <> '" . addslashes($mesActual) . "'";
}
if ($mesArg) {
    $where .= " AND substr(p.proxima_revision::text,1,7) = '" . addslashes($mesArg) . "'";
}
if ($soloMesAnterior) {
    $where .= " AND substr(p.proxima_revision::text,1,7) = '" . addslashes($mesAnt) . "'";
}

$pendientes = Db::pgFetchAll("
    SELECT p.orden, p.tarea, p.cod_maquina_mant, p.desc_maquina,
           COALESCE(p.grupo,'') AS grupo, COALESCE(p.desc_grupo,'') AS desc_grupo,
           COALESCE(p.periodicidad,'') AS periodicidad,
           COALESCE(p.desc_tarea,'') AS desc_tarea,
           p.proxima_revision
      FROM mant_plan p
     WHERE $where
     ORDER BY p.proxima_revision
");
$total = count($pendientes);
echo "Tareas pendientes a cerrar: $total" . PHP_EOL;

// Desglose por mes
$porMes = [];
foreach ($pendientes as $r) {
    $m = substr((string)$r['proxima_revision'], 0, 7);
    $porMes[$m] = ($porMes[$m] ?? 0) + 1;
}
ksort($porMes);
echo PHP_EOL . "Reparto por mes (primera no_realizada que se creará):" . PHP_EOL;
foreach ($porMes as $m => $n) printf("  %s → %d\n", $m, $n);

if ($total === 0) { echo PHP_EOL . "Nada que cerrar." . PHP_EOL; exit(0); }

if (!$apply) {
    echo PHP_EOL . "Para aplicar:\n  php tools/mant_cerrar_pendientes_no_realizadas.php --apply\n";
    echo PHP_EOL . "Flags útiles:\n";
    echo "  --solo-mes-anterior     solo cierra pendientes del mes pasado\n";
    echo "  --mes=2025-09           solo cierra pendientes con fpo en ese mes\n";
    echo "  --incluir-mes-actual    también cierra las del mes en curso\n";
    echo "  --max-ciclos=10         limita el número de no_realizadas por tarea\n";
    exit(0);
}

// ── 2. APPLY ──
echo PHP_EOL . "Aplicando..." . PHP_EOL;
$nNoReal = 0; $nPlanUpd = 0;

foreach ($pendientes as $r) {
    $orden = (string)$r['orden'];
    $tarea = (string)$r['tarea'];
    $cod   = (string)$r['cod_maquina_mant'];
    $desc  = (string)$r['desc_maquina'];
    $per   = (string)$r['periodicidad'];
    $dias  = cadenciaDias($per);

    $fpo = (string)$r['proxima_revision'];
    $ciclo = 0;

    while ($ciclo < $maxCiclos) {
        // ¿el mes de $fpo está cerrado?
        $mFpo = substr($fpo, 0, 7);
        if (!$incluirActual && $mFpo === $mesActual) break;
        if ($mesArg !== null && $mFpo !== $mesArg)  break;
        if ($soloMesAnterior && $mFpo !== $mesAnt)  break;
        // Si fpo está en el futuro, paramos
        if ($fpo > $hoy) break;

        $extId = $orden . '|' . $tarea . '|' . $fpo;

        // Skip si ya hay marca con ese external_id
        $ya = (bool) Db::pgFetchOne(
            "SELECT 1 FROM mant_completions WHERE external_id = :e LIMIT 1",
            [':e' => $extId]
        );

        if (!$ya) {
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
                    ':ord' => $orden, ':tar' => $tarea,
                    ':cm'  => $cod, ':dm' => $desc,
                    ':g'   => (string)$r['grupo'], ':dg' => (string)$r['desc_grupo'],
                    ':per' => $per, ':dt' => (string)$r['desc_tarea'],
                    ':fpo' => $fpo, ':op' => '',
                    ':obs' => '', ':mot' => 'falta_tiempo',
                    ':mp'  => 'cerrar_pendientes_fin_mes',
                ]);
                $nNoReal++;
            } catch (Throwable $e) { /* skip */ }
        }

        // Avanzar al siguiente ciclo
        $fpo = date('Y-m-d', strtotime($fpo . " +$dias days"));
        $ciclo++;
    }

    // Actualizar mant_plan.proxima_revision al último fpo no cerrado (futuro o mes actual)
    $r2 = Db::pgExec(
        "UPDATE mant_plan SET proxima_revision = ?
          WHERE orden = ? AND tarea = ?",
        [$fpo, $orden, $tarea]
    );
    $nPlanUpd += (int)$r2;
}

echo str_repeat('═', 75) . PHP_EOL;
echo "✓ no_realizadas creadas:                    $nNoReal" . PHP_EOL;
echo "  · mant_plan.proxima_revision avanzadas:   $nPlanUpd" . PHP_EOL;

// Verificación
$resi = (int) (Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_plan p
     WHERE p.proxima_revision IS NOT NULL
       AND p.proxima_revision <= CURRENT_DATE
       AND COALESCE(p.activa,'A') = 'A'
       AND COALESCE(p.alta_baja,'ALTA') = 'ALTA'
       AND p.fecha_pausado IS NULL
       AND substr(p.proxima_revision::text,1,7) <> ?
       AND NOT EXISTS (SELECT 1 FROM mant_completions c
                        WHERE c.orden=p.orden AND c.tarea=p.tarea
                          AND c.fecha_proxima_original=p.proxima_revision)
", [$mesActual])['n'] ?? 0);
echo PHP_EOL . "Pendientes residuales en meses cerrados: $resi" . PHP_EOL;
