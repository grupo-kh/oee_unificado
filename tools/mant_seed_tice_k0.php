<?php
/**
 * Añade dos tareas preventivas a la máquina "418 - Tice K0" (orden 1257)
 * con su histórico de revisiones:
 *
 *   - 10556 · TRIMESTRAL · histórico DESDE 12/02/2026
 *     TOPE ASENTAMIENTO SOCKET (Frame 3); Comprobacion de la ausencia
 *     de holgura entre el topo y socket
 *
 *   - 12843 · MENSUAL    · histórico DESDE 01/09/2025
 *     MÓDULO DE MANTENIMIENTO: Comprobar el purgador de agua y el
 *     llenado de aceite, y en su caso, añadir.
 *
 * Familia: 10238 CELULA SOLDADURA K0 - MENSUAL. activa='A', alta_baja='ALTA'.
 * Tiempo estimado: 20..30 min. Operario: aleatorio entre los activos
 * SIN Juan (no es un rack). Hora repartida por turnos. tiempo_real ±5..10s.
 *
 * Idempotente: si ya existen las tareas o el histórico, se salta.
 *
 * Modos:
 *   php tools/mant_seed_tice_k0.php          → DRY-RUN
 *   php tools/mant_seed_tice_k0.php --apply  → ESCRITURA
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply = in_array('--apply', $argv, true);

echo "Seed 418 - Tice K0 · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// Operarios activos (no Juan, máquina no-rack)
$activos = array_map(fn($r) => (string)$r['numero'],
    Db::pgFetchAll("SELECT numero FROM mant_operarios WHERE COALESCE(activo,TRUE)=TRUE AND numero <> '881'"));
if (!$activos) {
    // fallback: incluir todos por si Juan es el único
    $activos = array_map(fn($r) => (string)$r['numero'],
        Db::pgFetchAll("SELECT numero FROM mant_operarios WHERE COALESCE(activo,TRUE)=TRUE"));
}
if (!$activos) { fwrite(STDERR, "Sin operarios activos.\n"); exit(3); }
echo "Operarios disponibles (no-Juan): " . count($activos) . PHP_EOL;

// ── Máquina y datos ──
$orden  = '1257';
$cod    = '418 - Tice K0';
$desc   = '418 - Tice K0';
$grupo  = '10238';
$descGr = 'CELULA SOLDADURA K0 - MENSUAL';

$tareas = [
    [
        'tarea'        => '10556',
        'periodicidad' => 'TRIMESTRAL',
        'desc_tarea'   => 'TOPE ASENTAMIENTO SOCKET (Frame 3) ; Comprobacion de la ausencia de holgura entre el topo y socket',
        'inicio'       => '2026-02-12',  // histórico SOLO posterior a esta fecha
        'cadencia'     => 90,
    ],
    [
        'tarea'        => '12843',
        'periodicidad' => 'MENSUAL',
        'desc_tarea'   => 'MÓDULO DE MANTENIMIENTO: Comprobar el purgador de agua y el llenado de aceite, y en su caso, añadir.',
        'inicio'       => '2025-09-01',
        'cadencia'     => 30,
    ],
];

// Helpers
function horaTurno(): string {
    $r = mt_rand(1, 100);
    if ($r <= 50)      $h = mt_rand(14, 21);
    elseif ($r <= 85)  $h = mt_rand(6, 13);
    else {
        $bloque = mt_rand(0, 1);
        $h = $bloque === 0 ? mt_rand(22, 23) : mt_rand(0, 5);
    }
    return sprintf('%02d:%02d', $h, mt_rand(0, 59));
}

// Detectar columnas opcionales mant_maquinas
$colNames = array_column(Db::pgFetchAll("
    SELECT column_name FROM information_schema.columns WHERE table_name = 'mant_maquinas'
"), 'column_name');
$hasGrupo  = in_array('grupo',      $colNames, true);
$hasDescGr = in_array('desc_grupo', $colNames, true);

$hasTiempo = (bool) Db::pgFetchOne("
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'mant_completions' AND column_name = 'tiempo_real_segundos'
");

$hoy = date('Y-m-d');

// ── 1. mant_maquinas ──
$existe = (bool) Db::pgFetchOne(
    "SELECT 1 FROM mant_maquinas WHERE cod_maquina_mant = :c LIMIT 1",
    [':c' => $cod]
);
echo PHP_EOL . "Máquina '$cod': " . ($existe ? "ya existe" : "se insertará") . PHP_EOL;
if (!$existe) {
    if ($apply) {
        $cols = ['cod_maquina_mant', 'desc_maquina'];
        $vals = [':cod', ':desc'];
        $params = [':cod' => $cod, ':desc' => $desc];
        if ($hasGrupo)  { $cols[] = 'grupo';      $vals[] = ':gr';  $params[':gr']  = $grupo; }
        if ($hasDescGr) { $cols[] = 'desc_grupo'; $vals[] = ':dgr'; $params[':dgr'] = $descGr; }
        Db::pgExec("INSERT INTO mant_maquinas (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")", $params);
    }
}

$totalTareas = 0;
$totalMarcas = 0;

foreach ($tareas as $T) {
    $tarea   = $T['tarea'];
    $per     = $T['periodicidad'];
    $descT   = $T['desc_tarea'];
    $inicio  = $T['inicio'];
    $cad     = $T['cadencia'];
    $teMin   = mt_rand(20, 30);

    echo PHP_EOL . "── Tarea $tarea · $per · desde $inicio ──" . PHP_EOL;

    // ── 2. mant_plan ──
    if ($apply) {
        Db::pgExec("
            INSERT INTO mant_plan (
                orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                periodicidad, desc_tarea, activa, alta_baja,
                tiempo_estimado, tipo_mantenimiento
            ) VALUES (
                :o, :t, :cm, :dm, :g, :dg,
                :per, :dt, 'A', 'ALTA',
                :te, 'Preventivo'
            )
            ON CONFLICT (orden, tarea) DO UPDATE SET
                cod_maquina_mant = EXCLUDED.cod_maquina_mant,
                desc_maquina     = EXCLUDED.desc_maquina,
                grupo            = EXCLUDED.grupo,
                desc_grupo       = EXCLUDED.desc_grupo,
                desc_tarea       = EXCLUDED.desc_tarea,
                periodicidad     = EXCLUDED.periodicidad,
                activa           = 'A',
                alta_baja        = 'ALTA',
                tiempo_estimado  = EXCLUDED.tiempo_estimado
        ", [
            ':o'  => $orden, ':t' => $tarea,
            ':cm' => $cod,   ':dm' => $desc,
            ':g'  => $grupo, ':dg' => $descGr,
            ':per' => $per,  ':dt' => $descT,
            ':te' => $teMin,
        ]);
    }
    $totalTareas++;
    echo "  mant_plan: " . ($apply ? "upserted" : "[dry-run] upsertaría") . PHP_EOL;

    // ── 3. Histórico ──
    // Generamos fechas desde $inicio con cadencia ± jitter
    $jitter = ($cad >= 60) ? 3 : (($cad >= 30) ? 2 : (($cad >= 15) ? 1 : 0));
    $cursor = $inicio;
    $visitas = [];
    while ($cursor <= $hoy) {
        $offset = mt_rand(-2, 2);
        $fechaInt = date('Y-m-d', strtotime($cursor . ' ' . sprintf('%+d', $offset) . ' days'));
        if ($fechaInt > $hoy) $fechaInt = $hoy;
        $fechaInt = CalendarioLaboral::ajustarADiaHabil($fechaInt, 'anterior');
        $visitas[] = ['fpo' => $cursor, 'fechaInt' => $fechaInt];

        $delta = $cad + ($jitter > 0 ? mt_rand(-$jitter, $jitter) : 0);
        $cursor = date('Y-m-d', strtotime($cursor . ' +' . max(1, $delta) . ' days'));
    }

    $insMarcas = 0; $skipMarcas = 0;
    $ultFi = null;
    foreach ($visitas as $v) {
        $extId = $orden . '|' . $tarea . '|' . $v['fpo'];
        $ya = (bool) Db::pgFetchOne(
            "SELECT 1 FROM mant_completions WHERE external_id = :e LIMIT 1",
            [':e' => $extId]
        );
        if ($ya) { $skipMarcas++; continue; }

        $op    = $activos[mt_rand(0, count($activos) - 1)];
        $hora  = horaTurno();
        $teSeg = $teMin * 60 + (mt_rand(0,1) === 0 ? -1 : 1) * mt_rand(5, 10);
        if ($teSeg < 0) $teSeg = 60;

        $cols = "external_id, tipo, orden, tarea,
                 cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                 periodicidad, desc_tarea,
                 fecha_proxima_original, fecha_intervencion, hora_inicio, operario,
                 observaciones, motivo_no_realizada,
                 recuperada, recuperada_fecha, marcada_at, marcada_por";
        $vals = ":ext, 'completada', :ord, :tar,
                 :cm, :dm, :g, :dg,
                 :per, :dt,
                 :fpo, :fi, :hi, :op,
                 :obs, :mot,
                 FALSE, NULL, now(), :mp";
        $params = [
            ':ext' => $extId, ':ord' => $orden, ':tar' => $tarea,
            ':cm'  => $cod, ':dm' => $desc,
            ':g'   => $grupo, ':dg' => $descGr,
            ':per' => $per, ':dt' => $descT,
            ':fpo' => $v['fpo'], ':fi' => $v['fechaInt'], ':hi' => $hora, ':op' => $op,
            ':obs' => '', ':mot' => '',
            ':mp'  => 'seed_tice_k0',
        ];
        if ($hasTiempo) {
            $cols .= ", tiempo_real_segundos";
            $vals .= ", :te";
            $params[':te'] = $teSeg;
        }
        if ($apply) {
            try {
                Db::pgExec("INSERT INTO mant_completions ($cols) VALUES ($vals)", $params);
                $insMarcas++;
                $ultFi = $v['fechaInt'];
            } catch (Throwable $e) {
                $skipMarcas++;
            }
        } else {
            $insMarcas++;
            $ultFi = $v['fechaInt'];
        }
    }
    $totalMarcas += $insMarcas;
    printf("  marcas: %d insertadas · %d saltadas (ya existían)\n", $insMarcas, $skipMarcas);

    // Avanzar plan
    if ($apply && $ultFi) {
        $proximaPx = date('Y-m-d', strtotime($ultFi . " +$cad days"));
        Db::pgExec(
            "UPDATE mant_plan SET ultima_revision = ?, proxima_revision = ?
              WHERE orden = ? AND tarea = ?",
            [$ultFi, $proximaPx, $orden, $tarea]
        );
        echo "  plan avanzado: ultima=$ultFi · proxima=$proximaPx\n";
    }
}

echo str_repeat('═', 75) . PHP_EOL;
echo "Resumen: $totalTareas tareas upserted · $totalMarcas marcas creadas" . PHP_EOL;
if (!$apply) {
    echo PHP_EOL . "Para aplicar:\n  php tools/mant_seed_tice_k0.php --apply\n";
}
