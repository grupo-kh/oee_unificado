<?php
/**
 * Aplaza la próxima revisión de los RACKS para que NINGUNO caiga en esta
 * semana ni en la siguiente (próximos 14 días naturales desde hoy).
 *
 * Lógica:
 *   - Busca tareas con desc_maquina ILIKE 'RACK %' cuya proxima_revision
 *     sea <= hoy + 14 días.
 *   - Mueve esa proxima_revision a un día entre HOY + 15 y HOY + 28
 *     (segunda quincena), respetando día hábil del calendario CV.
 *   - NO toca ultima_revision (eso es histórico).
 *   - NO toca tareas con proxima_revision ya posterior a hoy + 14d.
 *
 * Modos:
 *   php tools/mant_aplazar_racks_proximas.php
 *     → DRY-RUN
 *   php tools/mant_aplazar_racks_proximas.php --apply
 *     → ESCRITURA
 *   php tools/mant_aplazar_racks_proximas.php --apply --semanas=3
 *     → Vacía las próximas 3 semanas (default 2).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply   = in_array('--apply', $argv, true);
$semanas = 2;
foreach ($argv as $a) {
    if (preg_match('/^--semanas=(\d+)$/', $a, $m)) $semanas = max(1, (int)$m[1]);
}
$dias = $semanas * 7;

$hoy = date('Y-m-d');
$limite = date('Y-m-d', strtotime($hoy . ' +' . $dias . ' days'));

echo "Aplazar RACKS dentro de los próximos $dias días (hasta $limite) · "
   . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// 1. Detectar candidatas
$rows = Db::pgFetchAll("
    SELECT orden, tarea, cod_maquina_mant, desc_maquina, periodicidad,
           proxima_revision, ultima_revision
      FROM mant_plan
     WHERE desc_maquina ILIKE 'RACK %'
       AND proxima_revision IS NOT NULL
       AND proxima_revision <= :lim
       AND COALESCE(activa, 'A') = 'A'
       AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
     ORDER BY proxima_revision, desc_maquina, tarea
", [':lim' => $limite]);

$nFilas = count($rows);
echo "Tareas RACK con proxima_revision <= $limite: $nFilas" . PHP_EOL;

if (!$nFilas) {
    echo "Nada que aplazar. ✓" . PHP_EOL;
    exit(0);
}

// Resumen por máquina
$resumenMaq = [];
foreach ($rows as $r) {
    $cod = (string)$r['cod_maquina_mant'];
    $resumenMaq[$cod] = ($resumenMaq[$cod] ?? 0) + 1;
}
echo PHP_EOL . "Por máquina:" . PHP_EOL;
foreach ($resumenMaq as $c => $n) printf("  · %s → %d\n", $c, $n);

// 2. Para cada tarea: pickeo de una nueva fecha entre hoy+15 y hoy+28,
//    ajustada a día hábil. Las tareas de la misma máquina+periodicidad
//    se agrupan en LA MISMA nueva fecha para mantener la coherencia de
//    visita consolidada en racks.
$nuevasPorClave = []; // clave = cod_maquina|periodicidad → fecha
$actualizadas = 0;

if ($apply) {
    foreach ($rows as $r) {
        $clave = ((string)$r['cod_maquina_mant']) . '|' . ((string)$r['periodicidad']);
        if (!isset($nuevasPorClave[$clave])) {
            // Día aleatorio entre hoy+15 y hoy+15+13 (=28)
            $offset = mt_rand($dias + 1, $dias + 14);
            $f = date('Y-m-d', strtotime($hoy . ' +' . $offset . ' days'));
            $f = CalendarioLaboral::ajustarADiaHabil($f, 'posterior');
            $nuevasPorClave[$clave] = $f;
        }
        $nueva = $nuevasPorClave[$clave];

        $r2 = Db::pgExec(
            "UPDATE mant_plan
                SET proxima_revision = :p
              WHERE orden = :o AND tarea = :t",
            [':p' => $nueva, ':o' => (string)$r['orden'], ':t' => (string)$r['tarea']]
        );
        $actualizadas += (int)$r2;
    }
    echo PHP_EOL . "Aplicado." . PHP_EOL;
    echo "Filas mant_plan actualizadas: $actualizadas" . PHP_EOL;
    echo "Visitas (cod+per) reagendadas a una fecha común: " . count($nuevasPorClave) . PHP_EOL;

    // Verificación
    $check = (int)(Db::pgFetchOne("
        SELECT COUNT(*) AS n FROM mant_plan
         WHERE desc_maquina ILIKE 'RACK %'
           AND proxima_revision IS NOT NULL
           AND proxima_revision <= :lim
           AND COALESCE(activa, 'A') = 'A'
           AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
    ", [':lim' => $limite])['n'] ?? 0);
    echo PHP_EOL . "Tareas RACK que aún caen en ≤ $limite: $check (esperado 0)" . PHP_EOL;
} else {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_aplazar_racks_proximas.php --apply"
        . ($semanas !== 2 ? " --semanas=$semanas" : "") . PHP_EOL;
}

if (!CalendarioLaboral::esDiaHabil($hoy)) {
    echo PHP_EOL . "(Nota: hoy '$hoy' no es día hábil CV)" . PHP_EOL;
}
