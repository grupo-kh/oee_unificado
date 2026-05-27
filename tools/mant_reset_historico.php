<?php
/**
 * Reset COMPLETO del histórico generado por seeds, agrupando por
 * (cod_maquina_mant, periodicidad) para que todas las sub-tareas de un
 * rack compartan UNA SOLA fecha_intervencion por visita.
 *
 * Lógica:
 *   1. Agrupa las tareas activas del plan por (máquina, periodicidad).
 *      Una máquina puede tener varios grupos (ej. MENSUAL + TRIMESTRAL).
 *   2. Para cada grupo:
 *      a. Borra las marcas existentes generadas por seeds (marcada_por
 *         LIKE 'seed_%') de TODAS las tareas del grupo. Con --todo borra
 *         también las manuales.
 *      b. Calcula las visitas: desde --desde (default 2025-09-01) hasta
 *         hoy, una visita cada PERIODICIDAD ± 3 días.
 *      c. Para cada visita:
 *           - Elige UNA fecha_intervencion (la programada ± hasta 2 días,
 *             ajustada a día hábil — lun-vie, no festivos CV).
 *           - Elige UN operario aleatorio (cumple con códigos numéricos
 *             del catálogo mant_operarios).
 *           - Elige UNA hora de inicio (50% tarde, 35% mañana, 15% noche).
 *           - Inserta una marca por CADA sub-tarea del grupo, con esa
 *             misma fecha_intervencion + operario + hora. Tiempo real
 *             por sub-tarea con ±5..10 seg.
 *   3. Actualiza mant_plan.ultima_revision y proxima_revision.
 *
 * Resultado: el histórico consolidado muestra una fila por visita por
 * trimestre/mes/etc., con un único operario y una sola hora.
 *
 * Modos:
 *   php tools/mant_reset_historico.php
 *     → DRY-RUN.
 *
 *   php tools/mant_reset_historico.php --apply
 *     → ESCRITURA.
 *
 *   php tools/mant_reset_historico.php --apply --maquina-like='RACK%'
 *     → Solo procesa las máquinas que coincidan.
 *
 *   php tools/mant_reset_historico.php --apply --todo
 *     → Borra TODAS las marcas (no solo seed_*).
 *
 *   php tools/mant_reset_historico.php --apply --desde=2024-09-01
 *     → Cambia la fecha de inicio.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply = in_array('--apply', $argv, true);
$todo  = in_array('--todo',  $argv, true);
$mLike = null;
$desde = '2025-09-01';
foreach ($argv as $a) {
    if (preg_match('/^--maquina-like=(.+)$/', $a, $m)) $mLike = $m[1];
    if (preg_match('/^--desde=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $desde = $m[1];
}

echo "Reset histórico (por visita) · " . ($apply ? "ESCRITURA" : "DRY-RUN")
   . " · desde $desde · " . ($todo ? "BORRA TODO" : "solo marcas seed_*")
   . ($mLike ? " · ILIKE '$mLike'" : "") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// ── Operarios numéricos ──
$ops = array_map(fn($r) => (string)$r['nombre'],
    Db::pgFetchAll("SELECT nombre FROM mant_operarios WHERE COALESCE(activo, TRUE) = TRUE ORDER BY nombre"));
if (!$ops) $ops = MaintenanceCompletionStore::loadOperarios();
if (!$ops) { fwrite(STDERR, "Sin operarios.\n"); exit(3); }
echo "Operarios: " . count($ops) . PHP_EOL;

// ── perToDays ──
function perToDays(string $per): int {
    switch (strtoupper(trim($per))) {
        case 'DIARIA':       return 1;
        case 'SEMANAL':      return 7;
        case 'QUINCENAL':    return 14;
        case 'MENSUAL':      return 30;
        case 'BIMENSUAL':    return 60;
        case 'TRIMESTRAL':   return 90;
        case 'CUATRIMESTRAL':return 120;
        case 'SEMESTRAL':    return 180;
        case 'ANUAL':        return 365;
        case 'BIANUAL':      return 730;
        default:             return 0;
    }
}

// ── Tareas a procesar ──
$paramsSel = [];
$whereExtra = '';
if ($mLike) { $whereExtra .= " AND mp.desc_maquina ILIKE :mlike"; $paramsSel[':mlike'] = $mLike; }

// Procesamos TODAS las tareas con periodicidad (incluso B/BAJA), porque
// muchas máquinas (RACK PARABRISAS) las tienen en B y queremos sus marcas
// reseteadas también.
$tareas = Db::pgFetchAll("
    SELECT mp.orden, mp.tarea, mp.cod_maquina_mant, mp.desc_maquina,
           mp.grupo, mp.desc_grupo, mp.periodicidad, mp.desc_tarea,
           mp.tiempo_estimado, mp.activa
      FROM mant_plan mp
     WHERE mp.periodicidad IS NOT NULL
       $whereExtra
     ORDER BY mp.cod_maquina_mant, mp.periodicidad, mp.tarea
", $paramsSel);
echo "Tareas a procesar (incluye B/BAJA): " . count($tareas) . PHP_EOL;

// ── Agrupar por (cod_maquina_mant, periodicidad) ──
$grupos = [];   // [cod_maq|per → [tareas]]
foreach ($tareas as $t) {
    $k = $t['cod_maquina_mant'] . '|' . strtoupper(trim((string)$t['periodicidad']));
    if (!isset($grupos[$k])) $grupos[$k] = [];
    $grupos[$k][] = $t;
}
echo "Grupos (máquina + periodicidad): " . count($grupos) . PHP_EOL;

$hoy = date('Y-m-d');
$borradas = 0;
$visitasTot = 0;
$marcasTot  = 0;
$sinPer     = 0;
$gruposOk   = 0;

foreach ($grupos as $k => $tareasGrupo) {
    $per     = (string)$tareasGrupo[0]['periodicidad'];
    $perDays = perToDays($per);
    $cod     = (string)$tareasGrupo[0]['cod_maquina_mant'];
    $desc    = (string)$tareasGrupo[0]['desc_maquina'];
    if ($perDays <= 0) { $sinPer++; continue; }

    // 1. BORRAR marcas de todas las tareas del grupo.
    //    Por defecto borramos las generadas por scripts/seeds y las
    //    importadas desde Excel (marcada_por NULL o vacío). Solo se
    //    conservan las que tienen un marcada_por explícito de usuario
    //    real (que no contenga 'seed' ni 'import' ni 'auditor').
    //    Con --todo borramos TODAS las marcas, sin excepciones.
    if ($apply) {
        foreach ($tareasGrupo as $t) {
            if ($todo) {
                $r = Db::pgExec(
                    "DELETE FROM mant_completions WHERE orden = :o AND tarea = :t",
                    [':o' => $t['orden'], ':t' => $t['tarea']]
                );
            } else {
                $r = Db::pgExec(
                    "DELETE FROM mant_completions
                      WHERE orden = :o AND tarea = :t
                        AND (
                            marcada_por IS NULL
                            OR marcada_por = ''
                            OR LOWER(marcada_por) LIKE 'seed%'
                            OR LOWER(marcada_por) LIKE '%import%'
                            OR LOWER(marcada_por) LIKE '%auditor%'
                        )",
                    [':o' => $t['orden'], ':t' => $t['tarea']]
                );
            }
            $borradas += (int)$r;
        }
    }

    // 2. Generar fechas programadas (visitas) con gap = perDays ± 3
    $visitas = [];   // cada entrada: ['fpo' => Y-m-d, 'fechaInt' => Y-m-d, 'op' => str, 'hora' => HH:MM]
    $cursor = $desde;
    while ($cursor <= $hoy) {
        $fpo      = $cursor;
        $offset   = mt_rand(-2, 2);
        $fechaInt = date('Y-m-d', strtotime($fpo . ' ' . sprintf('%+d', $offset) . ' days'));
        if ($fechaInt > $hoy) $fechaInt = $hoy;
        $fechaInt = CalendarioLaboral::ajustarADiaHabil($fechaInt, 'anterior');

        $visitas[] = [
            'fpo'      => $fpo,
            'fechaInt' => $fechaInt,
            'op'       => $ops[mt_rand(0, count($ops) - 1)],
            'hora'     => MaintenanceCompletionStore::horaTurnoAleatoria(),
        ];

        $delta = $perDays + mt_rand(-3, 3);
        if ($delta < 1) $delta = $perDays;
        $cursor = date('Y-m-d', strtotime($cursor . " +$delta days"));
    }
    $visitasTot += count($visitas);

    // 3. Para cada visita, insertar UNA marca por sub-tarea con la
    //    misma fecha_intervencion + operario + hora.
    $ultIntFecha = null;
    foreach ($visitas as $v) {
        $ultIntFecha = $v['fechaInt'];
        foreach ($tareasGrupo as $t) {
            $teMin = isset($t['tiempo_estimado']) && $t['tiempo_estimado'] !== ''
                        ? (int)$t['tiempo_estimado'] : 10;
            if ($apply) {
                try {
                    MaintenanceCompletionStore::add([
                        'tipo'                   => 'completada',
                        'orden'                  => (string)$t['orden'],
                        'tarea'                  => (string)$t['tarea'],
                        'cod_maquina_mant'       => $cod,
                        'desc_maquina'           => $desc,
                        'grupo'                  => (string)($t['grupo']      ?? ''),
                        'desc_grupo'             => (string)($t['desc_grupo'] ?? ''),
                        'periodicidad'           => $per,
                        'desc_tarea'             => (string)($t['desc_tarea'] ?? ''),
                        'activa'                 => (string)($t['activa']     ?? 'A'),
                        'fecha_proxima_original' => $v['fpo'],
                        'fecha_intervencion'     => $v['fechaInt'],
                        'hora_inicio'            => $v['hora'],
                        'operario'               => $v['op'],
                        'observaciones'          => '',
                        'motivo_no_realizada'    => '',
                        'recuperada'             => false,
                        'recuperada_fecha'       => null,
                        'marcada_at'             => time(),
                        'marcada_por'            => 'seed_reset',
                        'tiempo_real_segundos'   => MaintenanceCompletionStore::aplicarDecalajeAleatorio($teMin * 60),
                    ]);
                    $marcasTot++;
                } catch (Throwable $e) { /* skip dup */ }
            } else {
                $marcasTot++;
            }
        }
    }

    // 4. Avanzar mant_plan para todas las tareas del grupo
    if ($apply && $ultIntFecha) {
        $proxima = date('Y-m-d', strtotime($ultIntFecha . " +$perDays days"));
        foreach ($tareasGrupo as $t) {
            Db::pgExec(
                "UPDATE mant_plan SET ultima_revision = :u, proxima_revision = :p
                  WHERE orden = :o AND tarea = :t",
                [':u' => $ultIntFecha, ':p' => $proxima,
                 ':o' => (string)$t['orden'], ':t' => (string)$t['tarea']]
            );
        }
    }
    $gruposOk++;
}

echo str_repeat('─', 70) . PHP_EOL;
echo "Grupos procesados: $gruposOk" . PHP_EOL;
echo "Grupos sin periodicidad mapeable: $sinPer" . PHP_EOL;
echo "Marcas " . ($todo ? "totales" : "de seed_*") . " borradas: $borradas" . PHP_EOL;
echo "Visitas generadas: $visitasTot" . PHP_EOL;
echo "Marcas creadas (visitas × sub-tareas): $marcasTot" . PHP_EOL;

if (!$apply) {
    echo PHP_EOL . "Para aplicarlo:" . PHP_EOL;
    echo "  php tools/mant_reset_historico.php --apply"
        . ($mLike ? " --maquina-like='$mLike'" : "")
        . ($todo  ? " --todo" : "")
        . ($desde !== '2025-09-01' ? " --desde=$desde" : "")
        . PHP_EOL;
}
