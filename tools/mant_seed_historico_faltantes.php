<?php
/**
 * Seed de histórico para máquinas que NO aparecen en el histórico
 * (o aparecen incompletas) respecto a la periodicidad de sus tareas.
 *
 * Recorre TODAS las máquinas con tareas activas (activa='A', alta_baja='ALTA')
 * y, para cada una, genera marcas en mant_completions desde --since hasta hoy
 * siguiendo la cadencia de la periodicidad. Es IDEMPOTENTE: cada marca tiene un
 * external_id construido con (orden, tarea, fecha_proxima_original), y si
 * ya existe en BD se salta — así no duplica nada que ya esté presente.
 *
 * Reglas:
 *   - Racks (RACK %) y PLATAFORMAS → visitas CONSOLIDADAS:
 *       una visita comparte fecha+operario+hora entre todas las sub-tareas.
 *       La cadencia es la periodicidad MÁS EXIGENTE de las tareas de esa máquina.
 *   - Resto → visitas INDIVIDUALES por tarea, cada tarea con su periodicidad.
 *
 *   - Cadencia por periodicidad (días entre fechas):
 *       DIARIO 1 · SEMANAL 7 · QUINCENAL 15 · MENSUAL 30 · BIMESTRAL 60
 *       TRIMESTRAL 90 · CUATRIMESTRAL 120 · SEMESTRAL 180 · ANUAL 365
 *
 *   - Días no hábiles (CV) → la fecha de intervención se desplaza al día
 *     hábil anterior. fecha_proxima_original se mantiene como referencia.
 *   - Operario = aleatorio del catálogo activo.
 *   - Hora = repartida por turnos (50% tarde, 35% mañana, 15% noche).
 *   - tiempo_real_segundos = tiempo_estimado × 60 ± 5..10 s.
 *
 * Modos:
 *   php tools/mant_seed_historico_faltantes.php
 *     → DRY-RUN: cuenta cuántas marcas inserterría sin tocar nada.
 *
 *   php tools/mant_seed_historico_faltantes.php --apply
 *     → ESCRITURA.
 *
 *   php tools/mant_seed_historico_faltantes.php --apply --solo-vacias
 *     → Solo procesa máquinas que ahora mismo tienen 0 marcas en histórico.
 *
 *   php tools/mant_seed_historico_faltantes.php --apply --since=2025-06-01
 *     → Cambia la fecha de arranque (default 2025-09-01).
 *
 *   php tools/mant_seed_historico_faltantes.php --apply --like='ETIQ%'
 *     → Solo máquinas cuyo desc_maquina ILIKE el patrón dado.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply      = in_array('--apply', $argv, true);
$soloVacias = in_array('--solo-vacias', $argv, true);
$since      = '2025-09-01';
$like       = null;
foreach ($argv as $a) {
    if (preg_match('/^--since=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $since = $m[1];
    if (preg_match('/^--like=(.+)$/',                  $a, $m)) $like  = $m[1];
}

$hoy = date('Y-m-d');

echo "Seed histórico faltantes (desde $since a $hoy) · "
   . ($apply ? "ESCRITURA" : "DRY-RUN")
   . ($soloVacias ? " · solo vacías" : "")
   . ($like ? " · ILIKE '$like'" : "")
   . PHP_EOL;
echo str_repeat('─', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// ── Operarios ──
$ops = array_map(fn($r) => (string)$r['nombre'],
    Db::pgFetchAll("SELECT nombre FROM mant_operarios WHERE COALESCE(activo, TRUE) = TRUE ORDER BY nombre"));
if (!$ops) $ops = MaintenanceCompletionStore::loadOperarios();
if (!$ops) { fwrite(STDERR, "Sin operarios.\n"); exit(3); }
echo "Operarios disponibles: " . count($ops) . PHP_EOL;

// ── Helpers ──
function periodicidadADias(string $per): int {
    switch (strtoupper(trim($per))) {
        case 'DIARIO': case 'DIARIA':     return 1;
        case 'SEMANAL':                   return 7;
        case 'QUINCENAL':                 return 15;
        case 'MENSUAL':                   return 30;
        case 'BIMESTRAL': case 'BIMENSUAL': return 60;
        case 'TRIMESTRAL':                return 90;
        case 'CUATRIMESTRAL':             return 120;
        case 'SEMESTRAL':                 return 180;
        case 'ANUAL':                     return 365;
        default:                          return 0;
    }
}

function jitterPara(int $dias): int {
    if ($dias >= 60) return 3;
    if ($dias >= 30) return 2;
    if ($dias >= 15) return 1;
    return 0;
}

function genFechas(string $desde, string $hasta, int $dias, int $jit): array {
    if ($dias <= 0) return [];
    $fechas = [];
    $cursor = $desde;
    while ($cursor <= $hasta) {
        $fechas[] = $cursor;
        $delta = $dias + ($jit > 0 ? mt_rand(-$jit, $jit) : 0);
        if ($delta < 1) $delta = 1;
        $cursor = date('Y-m-d', strtotime($cursor . ' +' . $delta . ' days'));
    }
    return $fechas;
}

// ── 1. Máquinas activas en mant_plan ──
$sqlMaq = "
    SELECT DISTINCT cod_maquina_mant, desc_maquina
      FROM mant_plan
     WHERE COALESCE(activa, 'A') = 'A'
       AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
" . ($like !== null ? "       AND desc_maquina ILIKE :p\n" : '') . "
     ORDER BY desc_maquina, cod_maquina_mant
";
$params = $like !== null ? [':p' => $like] : [];
$maqs = Db::pgFetchAll($sqlMaq, $params);

$totalMaq = count($maqs);
echo "Máquinas activas con tareas: $totalMaq" . PHP_EOL . PHP_EOL;

// Detectar columnas opcionales en mant_plan
$planCols = array_column(Db::pgFetchAll("
    SELECT column_name FROM information_schema.columns WHERE table_name = 'mant_plan'
"), 'column_name');
$hasGrupo    = in_array('grupo',     $planCols, true);
$hasDescGr   = in_array('desc_grupo',$planCols, true);
$hasTiempoEst = in_array('tiempo_estimado', $planCols, true);

// ── 2. Procesar cada máquina ──
$insertadas     = 0;
$saltadas       = 0;  // existían ya en mant_completions
$omitidas       = 0;  // sin tareas válidas
$maqConMarcas   = 0;
$maqSinMarcas   = 0;
$maqProcesadas  = 0;
$tareasUpdated  = []; // key = orden|tarea, value = ['orden','tarea','per','last_fecha']

foreach ($maqs as $maq) {
    $cod  = (string)$maq['cod_maquina_mant'];
    $desc = (string)$maq['desc_maquina'];

    // Marcas existentes
    $nMarcas = (int)(Db::pgFetchOne(
        "SELECT COUNT(*) AS n FROM mant_completions WHERE cod_maquina_mant = :c",
        [':c' => $cod]
    )['n'] ?? 0);

    if ($nMarcas > 0) {
        $maqConMarcas++;
        if ($soloVacias) continue;
    } else {
        $maqSinMarcas++;
    }

    // Tareas activas
    $cols = ['orden', 'tarea', 'desc_tarea', 'periodicidad'];
    if ($hasTiempoEst) $cols[] = "COALESCE(tiempo_estimado, 25) AS te";
    else               $cols[] = "25 AS te";
    if ($hasGrupo)  $cols[] = "COALESCE(grupo, '') AS grupo";       else $cols[] = "'' AS grupo";
    if ($hasDescGr) $cols[] = "COALESCE(desc_grupo, '') AS desc_grupo"; else $cols[] = "'' AS desc_grupo";

    $tareas = Db::pgFetchAll("
        SELECT " . implode(', ', $cols) . "
          FROM mant_plan
         WHERE cod_maquina_mant = :c
           AND COALESCE(activa, 'A') = 'A'
           AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
         ORDER BY orden, tarea
    ", [':c' => $cod]);

    if (!$tareas) { $omitidas++; continue; }

    $isConsolidable = MaintenancePlanStore::esConsolidable($desc);
    $insMaq = 0;

    if ($isConsolidable && count($tareas) > 1) {
        // Periodicidad más exigente
        $bestPer = (string)$tareas[0]['periodicidad'];
        foreach ($tareas as $t) {
            $p = (string)$t['periodicidad'];
            if (MaintenancePlanStore::periodicidadRank($p)
                < MaintenancePlanStore::periodicidadRank($bestPer)) {
                $bestPer = $p;
            }
        }
        $dias = periodicidadADias($bestPer);
        if ($dias <= 0) { $omitidas++; continue; }
        $jit = jitterPara($dias);

        $fechas = genFechas($since, $hoy, $dias, $jit);
        foreach ($fechas as $fpo) {
            $offset   = mt_rand(-2, 2);
            $fechaInt = date('Y-m-d', strtotime($fpo . ' ' . sprintf('%+d', $offset) . ' days'));
            if ($fechaInt > $hoy) $fechaInt = $hoy;
            $fechaInt = CalendarioLaboral::ajustarADiaHabil($fechaInt, 'anterior');
            $op   = $ops[mt_rand(0, count($ops) - 1)];
            $hora = MaintenanceCompletionStore::horaTurnoAleatoria();

            foreach ($tareas as $t) {
                $orden = (string)$t['orden'];
                $tarea = (string)$t['tarea'];
                $id    = MaintenanceCompletionStore::buildId($orden, $tarea, $fpo);

                $existe = (bool)Db::pgFetchOne(
                    "SELECT 1 FROM mant_completions WHERE external_id = :i LIMIT 1",
                    [':i' => $id]
                );
                if ($existe) { $saltadas++; continue; }

                if ($apply) {
                    try {
                        MaintenanceCompletionStore::add([
                            'tipo'                 => 'completada',
                            'orden'                => $orden,
                            'tarea'                => $tarea,
                            'cod_maquina_mant'     => $cod,
                            'desc_maquina'         => $desc,
                            'grupo'                => (string)$t['grupo'],
                            'desc_grupo'           => (string)$t['desc_grupo'],
                            'periodicidad'         => (string)$t['periodicidad'],
                            'desc_tarea'           => (string)$t['desc_tarea'],
                            'fecha_proxima_original' => $fpo,
                            'fecha_intervencion'   => $fechaInt,
                            'hora_inicio'          => $hora,
                            'operario'             => $op,
                            'observaciones'        => '',
                            'motivo_no_realizada'  => '',
                            'recuperada'           => false,
                            'recuperada_fecha'     => null,
                            'marcada_at'           => time(),
                            'marcada_por'          => 'seed_historico_faltantes',
                            'tiempo_real_segundos' => MaintenanceCompletionStore::aplicarDecalajeAleatorio((int)$t['te'] * 60),
                        ]);
                    } catch (Throwable $e) { /* skip dup */ }
                }
                $insertadas++; $insMaq++;
                $k = $orden . '|' . $tarea;
                if (!isset($tareasUpdated[$k]) || $tareasUpdated[$k]['last_fecha'] < $fechaInt) {
                    $tareasUpdated[$k] = [
                        'orden' => $orden, 'tarea' => $tarea,
                        'per'   => (string)$t['periodicidad'],
                        'last_fecha' => $fechaInt,
                    ];
                }
            }
        }
    } else {
        // No consolidable: por tarea independiente
        foreach ($tareas as $t) {
            $orden = (string)$t['orden'];
            $tarea = (string)$t['tarea'];
            $per   = (string)$t['periodicidad'];
            $dias  = periodicidadADias($per);
            if ($dias <= 0) continue;
            $jit = jitterPara($dias);

            $fechas = genFechas($since, $hoy, $dias, $jit);
            foreach ($fechas as $fpo) {
                $id = MaintenanceCompletionStore::buildId($orden, $tarea, $fpo);
                $existe = (bool)Db::pgFetchOne(
                    "SELECT 1 FROM mant_completions WHERE external_id = :i LIMIT 1",
                    [':i' => $id]
                );
                if ($existe) { $saltadas++; continue; }

                $offset   = mt_rand(-2, 2);
                $fechaInt = date('Y-m-d', strtotime($fpo . ' ' . sprintf('%+d', $offset) . ' days'));
                if ($fechaInt > $hoy) $fechaInt = $hoy;
                $fechaInt = CalendarioLaboral::ajustarADiaHabil($fechaInt, 'anterior');
                $op   = $ops[mt_rand(0, count($ops) - 1)];
                $hora = MaintenanceCompletionStore::horaTurnoAleatoria();

                if ($apply) {
                    try {
                        MaintenanceCompletionStore::add([
                            'tipo'                 => 'completada',
                            'orden'                => $orden,
                            'tarea'                => $tarea,
                            'cod_maquina_mant'     => $cod,
                            'desc_maquina'         => $desc,
                            'grupo'                => (string)$t['grupo'],
                            'desc_grupo'           => (string)$t['desc_grupo'],
                            'periodicidad'         => $per,
                            'desc_tarea'           => (string)$t['desc_tarea'],
                            'fecha_proxima_original' => $fpo,
                            'fecha_intervencion'   => $fechaInt,
                            'hora_inicio'          => $hora,
                            'operario'             => $op,
                            'observaciones'        => '',
                            'motivo_no_realizada'  => '',
                            'recuperada'           => false,
                            'recuperada_fecha'     => null,
                            'marcada_at'           => time(),
                            'marcada_por'          => 'seed_historico_faltantes',
                            'tiempo_real_segundos' => MaintenanceCompletionStore::aplicarDecalajeAleatorio((int)$t['te'] * 60),
                        ]);
                    } catch (Throwable $e) { /* skip dup */ }
                }
                $insertadas++; $insMaq++;
                $k = $orden . '|' . $tarea;
                if (!isset($tareasUpdated[$k]) || $tareasUpdated[$k]['last_fecha'] < $fechaInt) {
                    $tareasUpdated[$k] = [
                        'orden' => $orden, 'tarea' => $tarea,
                        'per'   => $per,
                        'last_fecha' => $fechaInt,
                    ];
                }
            }
        }
    }

    $maqProcesadas++;
    if ($insMaq > 0) {
        printf("  · %-45s %s · +%d marcas%s\n",
            substr($cod, 0, 45),
            $isConsolidable ? 'CONS' : 'IND ',
            $insMaq,
            $apply ? '' : ' [dry]');
    }
}

echo str_repeat('─', 75) . PHP_EOL;
echo "Máquinas con marcas previas    : $maqConMarcas" . PHP_EOL;
echo "Máquinas sin marcas previas    : $maqSinMarcas" . PHP_EOL;
echo "Máquinas omitidas              : $omitidas" . PHP_EOL;
echo "Máquinas procesadas            : $maqProcesadas" . PHP_EOL;
echo "Marcas insertadas              : $insertadas" . PHP_EOL;
echo "Marcas saltadas (ya existían)  : $saltadas" . PHP_EOL;

// ── 3. Actualizar mant_plan.ultima_revision / proxima_revision ──
if ($apply && !empty($tareasUpdated)) {
    $upd = 0;
    foreach ($tareasUpdated as $info) {
        $dias = periodicidadADias($info['per']);
        if ($dias <= 0) continue;
        $proxima = date('Y-m-d', strtotime($info['last_fecha'] . ' +' . $dias . ' days'));
        $r = Db::pgExec(
            "UPDATE mant_plan
                SET ultima_revision = :u,
                    proxima_revision = :p
              WHERE orden = :o
                AND tarea = :t
                AND (ultima_revision IS NULL OR ultima_revision < :u)",
            [':u' => $info['last_fecha'], ':p' => $proxima,
             ':o' => $info['orden'],      ':t' => $info['tarea']]
        );
        $upd += (int)$r;
    }
    echo "Filas mant_plan actualizadas (ultima/proxima): $upd" . PHP_EOL;
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_seed_historico_faltantes.php --apply" . PHP_EOL;
    echo "  php tools/mant_seed_historico_faltantes.php --apply --solo-vacias" . PHP_EOL;
    echo "    (solo máquinas con cero marcas)" . PHP_EOL;
}
