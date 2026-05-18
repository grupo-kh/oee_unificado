<?php
/**
 * Generador de datos de auditoría de mantenimiento preventivo.
 *
 * Para cada tarea de PROXIMAS REV. genera una cadena sintética de revisiones:
 *   - Fecha inicial aleatoria entre 25/08/2025 y 15/09/2025
 *   - Cada revisión siguiente = previa + periodicidad ± variancia
 *   - Continúa hasta hoy (CUTOFF_DATE)
 *   - Operario asignado al azar (8 números)
 *
 * En los meses 11/2025 y 02/2026 (MISS_MONTHS):
 *   - 2% de las revisiones se marcan como NO REALIZADAS con motivo
 *     relacionado con "falta de material" según la descripción de la tarea.
 *   - Esas tareas se "recuperan" en el mes siguiente con un registro
 *     adicional tipo "recuperacion" (fpo=null), de forma que:
 *
 *       11/2025 → 98 % cumplimiento (2 % no realizadas con motivo)
 *       12/2025 → 102 % (98 cycle + 2 recuperación / 98 cycle = ~102 %)
 *       02/2026 → 98 %
 *       03/2026 → 102 %
 *
 * Resto de meses: 100 %.
 *
 * El resultado se escribe en data/maintenance_completed.json
 * (sobrescribe completamente cualquier dato anterior).
 *
 * Uso:
 *   php tools/generate_audit_data.php [--seed=N] [--dry-run]
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/MaintenanceExcelReader.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/Db.php';

const CUTOFF_DATE       = '2026-04-29';
const SEED_FROM         = '2025-08-25';
const SEED_TO           = '2025-09-15';
const MISS_MONTHS       = ['2025-11', '2026-02'];
const CATCHUP_MONTHS    = ['2025-12', '2026-03'];
const MISS_RATIO        = 0.02; // 2 %
const STORE_PATH        = __DIR__ . '/../data/maintenance_completed.json';

const OPERARIOS = ['2418', '2417', '1004', '1374', '1886', '1593', '2338', '2898'];

// Días por periodicidad
const PER_DAYS = [
    'DIARIO'        => 1,
    'DIARIA'        => 1,
    'SEMANAL'       => 7,
    'QUINCENAL'     => 15,
    'MENSUAL'       => 30,
    'BIMESTRAL'     => 60,
    'BIMENSUAL'     => 60,
    'TRIMESTRAL'    => 90,
    'CUATRIMESTRAL' => 120,
    'SEMESTRAL'     => 180,
    'ANUAL'         => 365,
];

// Variancia ± días por periodicidad
const PER_VARIANCE = [
    'DIARIO'        => 0,
    'DIARIA'        => 0,
    'SEMANAL'       => 1,
    'QUINCENAL'     => 4,
    'MENSUAL'       => 3,
    'BIMESTRAL'     => 5,
    'BIMENSUAL'     => 5,
    'TRIMESTRAL'    => 8,
    'CUATRIMESTRAL' => 9,
    'SEMESTRAL'     => 10,
    'ANUAL'         => 15,
];

// Observaciones genéricas para revisiones realizadas (rotativo)
const OBSERVACIONES_OK = [
    '',
    'Sin incidencias.',
    'Realizada según procedimiento.',
    'OK.',
    'Verificada y operativa.',
    '',
    '',
    '',
];

// ---------- args ----------
$opts = getopt('', ['seed::', 'dry-run', 'target::']);
$seed = isset($opts['seed']) ? (int)$opts['seed'] : 12345;
$dryRun = isset($opts['dry-run']);
// target=auto (por defecto): si MANT_USE_PG, escribe en PG; si no, JSON.
// target=json: fuerza salida a data/maintenance_completed.json
// target=pg:   fuerza inserción en mant_completions
$target = $opts['target'] ?? 'auto';
$useDb  = ($target === 'pg') || ($target === 'auto' && defined('MANT_USE_PG') && MANT_USE_PG);
mt_srand($seed);

echo "Generador auditoría · seed=$seed · target="
   . ($useDb ? 'PostgreSQL' : 'JSON')
   . ($dryRun ? ' [DRY-RUN]' : '') . "\n";

// ---------- carga proximas ----------
// Origen: si está activo MANT_USE_PG y la tabla mant_plan tiene datos, los
// leemos de allí; si no, caemos al Excel (compatibilidad pre-migración).
$data = MaintenancePlanStore::load();
$proximas = $data['proximas'];
echo "Tareas en plan de mantenimiento: " . count($proximas) . "\n";

// ---------- helpers ----------
function tsToYmd(int $ts): string { return date('Y-m-d', $ts); }
function ymdToTs(string $ymd): int { return (int)strtotime($ymd); }
function rangeRandomDate(string $a, string $b): string {
    $ta = ymdToTs($a); $tb = ymdToTs($b);
    return tsToYmd($ta + mt_rand(0, max(0, ($tb - $ta) / 86400)) * 86400);
}
function pickOne(array $arr) { return $arr[mt_rand(0, count($arr) - 1)]; }
function monthOf(string $ymd): string { return substr($ymd, 0, 7); }
function randVar(int $v): int { return $v <= 0 ? 0 : mt_rand(-$v, $v); }

/**
 * Devuelve un motivo de "no realizada" relacionado con la descripción.
 */
function motivoFalta(string $desc): string {
    $d = mb_strtolower($desc);
    $rules = [
        ['/(engrase|lubric|aceite|grasa)/u', 'Falta de grasa lubricante en almacén — pendiente de pedido al proveedor.'],
        ['/(filtro)/u',                       'Falta de filtro de repuesto — pendiente de recepción del pedido.'],
        ['/(junta|sello|reten)/u',            'Falta de juntas/retenes en almacén — solicitadas a compras.'],
        ['/(rodamiento)/u',                   'Falta de rodamiento de repuesto — pedido emitido al proveedor.'],
        ['/(correa|banda)/u',                 'Falta de correa de repuesto — pendiente de entrega.'],
        ['/(fusible|rel[eé]|relay)/u',        'Falta de fusible/relé compatible — pendiente de pedido.'],
        ['/(cable|cableado)/u',               'Falta de cable de repuesto adecuado — pendiente de recepción.'],
        ['/(manguera|tubo|tuber[ií]a)/u',     'Falta de manguera de repuesto — pendiente de recepción.'],
        ['/(tornill|tuerca|sujec)/u',         'Falta de elementos de fijación — solicitados a compras.'],
        ['/(neum[aá]tic|aire|compresor)/u',   'Falta de componentes neumáticos — pendiente de pedido.'],
        ['/(bater[ií]a)/u',                   'Falta de batería de repuesto.'],
        ['/(cuchill|hoja)/u',                 'Falta de cuchilla de repuesto.'],
        ['/(escobill)/u',                     'Falta de escobillas de repuesto.'],
        ['/(sensor|c[eé]lula|fotoc)/u',       'Falta de sensor de repuesto.'],
        ['/(motor)/u',                        'Falta de motor/componente de motor de repuesto.'],
    ];
    foreach ($rules as [$re, $msg]) {
        if (preg_match($re, $d)) return $msg;
    }
    return 'Falta de material consumible — pendiente de pedido al proveedor.';
}

/** id sintético único determinístico */
function buildIdCycle(string $orden, string $tarea, string $fpo): string {
    return $orden . '|' . $tarea . '|' . $fpo;
}
function buildIdCatchup(string $orden, string $tarea, string $fpoMissed): string {
    return $orden . '|' . $tarea . '|' . $fpoMissed . '|catchup';
}

// ---------- generación ----------
$items   = [];
$nowEpoch = time();

$stats = [
    'total_revisiones'     => 0,
    'completadas'          => 0,
    'no_realizadas'        => 0,
    'recuperaciones'       => 0,
    'tareas_sin_cadena'    => 0,
    'por_mes'              => [],
];

$cutoffTs = ymdToTs(CUTOFF_DATE);

foreach ($proximas as $p) {
    $orden       = (string)$p['orden'];
    $tarea       = (string)$p['tarea'];
    $per         = strtoupper(trim((string)$p['periodicidad']));
    $diasPer     = PER_DAYS[$per] ?? null;
    $variance    = PER_VARIANCE[$per] ?? 3;

    if ($diasPer === null) {
        $stats['tareas_sin_cadena']++;
        continue;
    }

    // Fecha de la primera revisión (random en SEED_FROM..SEED_TO)
    $firstActual = rangeRandomDate(SEED_FROM, SEED_TO);
    $firstTs = ymdToTs($firstActual);

    // step 0: fpo == actual (es la semilla)
    $fpoTs    = $firstTs;
    $actualTs = $firstTs;

    $iter = 0;
    $maxIter = 600; // safety
    $catchupAccum = []; // recuperaciones de este task
    while ($iter < $maxIter) {
        $iter++;
        $fpoYmd    = tsToYmd($fpoTs);
        $actualYmd = tsToYmd($actualTs);

        // Solo nos interesan revisiones cuya fecha programada sea ≤ hoy.
        // Si la programada está en el futuro, esta tarea ya está al día.
        if ($fpoTs > $cutoffTs) break;

        $monthFpo = monthOf($fpoYmd);
        $isMissTarget = in_array($monthFpo, MISS_MONTHS, true);
        $miss = $isMissTarget && (mt_rand(1, 10000) <= MISS_RATIO * 10000);

        $stats['total_revisiones']++;
        if (!isset($stats['por_mes'][$monthFpo])) {
            $stats['por_mes'][$monthFpo] = ['fpo' => 0, 'actual' => 0, 'miss' => 0];
        }
        $stats['por_mes'][$monthFpo]['fpo']++;

        if ($miss) {
            // Registro NO REALIZADA con motivo
            $items[] = [
                'id'                     => buildIdCycle($orden, $tarea, $fpoYmd),
                'tipo'                   => 'no_realizada',
                'orden'                  => $orden,
                'cod_maquina_mant'       => (string)$p['cod_maquina_mant'],
                'desc_maquina'           => (string)$p['desc_maquina'],
                'grupo'                  => (string)$p['grupo'],
                'desc_grupo'             => (string)$p['desc_grupo'],
                'periodicidad'           => $per,
                'tarea'                  => $tarea,
                'desc_tarea'             => (string)$p['desc_tarea'],
                'activa'                 => (string)$p['activa'],
                'fecha_proxima_original' => $fpoYmd,
                'fecha_intervencion'     => null,
                'operario'               => '',
                'observaciones'          => '',
                'motivo_no_realizada'    => motivoFalta((string)$p['desc_tarea']),
                'recuperada'             => false,
                'marcada_at'             => $nowEpoch,
                'marcada_por'            => 'audit-generator',
            ];
            $stats['no_realizadas']++;
            $stats['por_mes'][$monthFpo]['miss']++;

            // Recuperación: registro extra con tipo='recuperacion', fpo=null,
            // fecha_intervencion en los primeros días del mes siguiente.
            $catchupMonth = MISS_MONTHS[array_search($monthFpo, MISS_MONTHS, true)];
            $catchupKey   = CATCHUP_MONTHS[array_search($monthFpo, MISS_MONTHS, true)];
            $catchupYmd   = $catchupKey . '-' . str_pad((string)mt_rand(2, 8), 2, '0', STR_PAD_LEFT);
            $catchupAccum[] = [
                'fpo_missed' => $fpoYmd,
                'catchup'    => $catchupYmd,
            ];

            $items[] = [
                'id'                     => buildIdCatchup($orden, $tarea, $fpoYmd),
                'tipo'                   => 'recuperacion',
                'orden'                  => $orden,
                'cod_maquina_mant'       => (string)$p['cod_maquina_mant'],
                'desc_maquina'           => (string)$p['desc_maquina'],
                'grupo'                  => (string)$p['grupo'],
                'desc_grupo'             => (string)$p['desc_grupo'],
                'periodicidad'           => $per,
                'tarea'                  => $tarea,
                'desc_tarea'             => (string)$p['desc_tarea'],
                'activa'                 => (string)$p['activa'],
                'fecha_proxima_original' => null,
                'fecha_intervencion'     => $catchupYmd,
                'operario'               => pickOne(OPERARIOS),
                'observaciones'          => 'RECUPERACIÓN — Tarea no realizada en ' . substr($fpoYmd, 0, 7)
                                             . ' por falta de material; ejecutada al recibir el pedido.',
                'motivo_no_realizada'    => '',
                'recuperada'             => true,
                'marcada_at'             => $nowEpoch,
                'marcada_por'            => 'audit-generator',
            ];
            $stats['recuperaciones']++;

            // Marca la cycle missed como recuperada (referencia mutua)
            $items[count($items) - 2]['recuperada'] = true;
            $items[count($items) - 2]['recuperada_fecha'] = $catchupYmd;

            // Para el avance del ciclo: tras recuperar el material, la
            // siguiente revisión cuenta desde el catchup.
            $actualTs = ymdToTs($catchupYmd);
        } else {
            // Registro COMPLETADA normal
            $items[] = [
                'id'                     => buildIdCycle($orden, $tarea, $fpoYmd),
                'tipo'                   => 'completada',
                'orden'                  => $orden,
                'cod_maquina_mant'       => (string)$p['cod_maquina_mant'],
                'desc_maquina'           => (string)$p['desc_maquina'],
                'grupo'                  => (string)$p['grupo'],
                'desc_grupo'             => (string)$p['desc_grupo'],
                'periodicidad'           => $per,
                'tarea'                  => $tarea,
                'desc_tarea'             => (string)$p['desc_tarea'],
                'activa'                 => (string)$p['activa'],
                'fecha_proxima_original' => $fpoYmd,
                'fecha_intervencion'     => $actualYmd,
                'operario'               => pickOne(OPERARIOS),
                'observaciones'          => pickOne(OBSERVACIONES_OK),
                'motivo_no_realizada'    => '',
                'recuperada'             => false,
                'marcada_at'             => $nowEpoch,
                'marcada_por'            => 'audit-generator',
            ];
            $stats['completadas']++;
            $monthAct = monthOf($actualYmd);
            if (!isset($stats['por_mes'][$monthAct])) {
                $stats['por_mes'][$monthAct] = ['fpo' => 0, 'actual' => 0, 'miss' => 0];
            }
            $stats['por_mes'][$monthAct]['actual']++;

            // El siguiente fpo se calcula desde el actual (la cadena avanza)
            // El usuario solo proporcionó variancia para la fecha real de
            // la siguiente intervención; mantenemos esa interpretación.
        }

        // Calcular el siguiente fpo (basado en actualTs, sea cycle o catchup)
        $fpoTs = $actualTs + $diasPer * 86400;
        $actualTs = $fpoTs + randVar($variance) * 86400;
    }
}

// Reportar estadísticas
echo "\nResumen:\n";
echo "  total revisiones: " . $stats['total_revisiones'] . "\n";
echo "  completadas:      " . $stats['completadas'] . "\n";
echo "  no realizadas:    " . $stats['no_realizadas'] . "\n";
echo "  recuperaciones:   " . $stats['recuperaciones'] . "\n";
echo "  tareas sin cadena: " . $stats['tareas_sin_cadena'] . "\n";
echo "\nPor mes (programada / realizada / missed):\n";
ksort($stats['por_mes']);
foreach ($stats['por_mes'] as $m => $v) {
    $pct = $v['fpo'] > 0 ? round(($v['fpo'] - $v['miss']) / $v['fpo'] * 100, 1) : 0;
    printf("  %s · fpo=%4d · actual=%4d · miss=%3d · %%cycle=%5.1f%%\n",
        $m, $v['fpo'], $v['actual'], $v['miss'], $pct);
}

// Compute per-month metric exactly as the API will:
//   denom_M = cycle records (no recuperación) con fpo en M
//   numer_M = cycle records con fpo en M Y actual no nulo
//             + recuperación records con actual en M
$denom = $numer = [];
foreach ($items as $it) {
    $tipo = (string)($it['tipo'] ?? '');
    $fpo  = (string)($it['fecha_proxima_original'] ?? '');
    $fi   = (string)($it['fecha_intervencion'] ?? '');

    if ($tipo === 'recuperacion') {
        if ($fi !== '') {
            $m = substr($fi, 0, 7);
            $numer[$m] = ($numer[$m] ?? 0) + 1;
        }
    } else {
        if ($fpo !== '') {
            $m = substr($fpo, 0, 7);
            $denom[$m] = ($denom[$m] ?? 0) + 1;
            if ($fi !== '') $numer[$m] = ($numer[$m] ?? 0) + 1;
        }
    }
}
echo "\nMétrica de cumplimiento por mes (numer/denom = %):\n";
$months = array_unique(array_merge(array_keys($denom), array_keys($numer)));
sort($months);
foreach ($months as $m) {
    $d = $denom[$m] ?? 0; $n = $numer[$m] ?? 0;
    $pct = $d > 0 ? round($n / $d * 100, 1) : null;
    printf("  %s · denom=%4d · numer=%4d · %s\n",
        $m, $d, $n, $pct === null ? '—' : $pct.'%');
}

if ($dryRun) {
    echo "\n[DRY-RUN] no se ha escrito nada.\n";
    exit(0);
}

if ($useDb) {
    // Inserción masiva en PostgreSQL (TRUNCATE + COPY-style por bloques)
    require_once __DIR__ . '/../lib/Db.php';
    $pdo = Db::pg();
    echo "\n→ Vaciando mant_completions y reinsertando " . count($items) . " items…\n";
    $pdo->exec("TRUNCATE TABLE mant_completions RESTART IDENTITY");

    $sql = "INSERT INTO mant_completions (
                external_id, tipo, orden, tarea, cod_maquina_mant, desc_maquina,
                grupo, desc_grupo, periodicidad, desc_tarea, activa,
                fecha_proxima_original, fecha_intervencion,
                operario, observaciones, motivo_no_realizada,
                recuperada, recuperada_fecha, marcada_at, marcada_por
            ) VALUES (
                :external_id, :tipo, :orden, :tarea, :cmm, :desc_maquina,
                :grupo, :desc_grupo, :periodicidad, :desc_tarea, :activa,
                :fpo, :fi, :operario, :observaciones, :motivo,
                :recuperada, :recuperada_fecha, to_timestamp(:marcada_at), :marcada_por
            )";
    $st = $pdo->prepare($sql);
    $pdo->beginTransaction();
    $i = 0;
    foreach ($items as $it) {
        $st->execute([
            ':external_id'      => (string)$it['id'],
            ':tipo'             => (string)$it['tipo'],
            ':orden'            => (string)$it['orden'],
            ':tarea'            => (string)$it['tarea'],
            ':cmm'              => $it['cod_maquina_mant'] !== '' ? $it['cod_maquina_mant'] : null,
            ':desc_maquina'     => $it['desc_maquina']     !== '' ? $it['desc_maquina']     : null,
            ':grupo'            => $it['grupo']            !== '' ? $it['grupo']            : null,
            ':desc_grupo'       => $it['desc_grupo']       !== '' ? $it['desc_grupo']       : null,
            ':periodicidad'     => $it['periodicidad']     !== '' ? $it['periodicidad']     : null,
            ':desc_tarea'       => $it['desc_tarea']       !== '' ? $it['desc_tarea']       : null,
            ':activa'           => $it['activa']           !== '' ? $it['activa']           : null,
            ':fpo'              => $it['fecha_proxima_original'] ?: null,
            ':fi'               => $it['fecha_intervencion']     ?: null,
            ':operario'         => ($it['operario'] ?? '') !== '' ? $it['operario'] : null,
            ':observaciones'    => ($it['observaciones'] ?? '') !== '' ? $it['observaciones'] : null,
            ':motivo'           => ($it['motivo_no_realizada'] ?? '') !== '' ? $it['motivo_no_realizada'] : null,
            ':recuperada'       => !empty($it['recuperada']) ? 'true' : 'false',
            ':recuperada_fecha' => $it['recuperada_fecha'] ?? null,
            ':marcada_at'       => (int)$it['marcada_at'],
            ':marcada_por'      => (string)$it['marcada_por'],
        ]);
        $i++;
        if ($i % 1000 === 0) echo "  · $i…\n";
    }
    $pdo->commit();
    echo "\nEscritos $i registros en mant_completions.\n";
} else {
    // Escribir el JSON (modo legacy)
    $payload = ['items' => $items];
    $dir = dirname(STORE_PATH);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $tmp = STORE_PATH . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename($tmp, STORE_PATH);
    echo "\nEscrito: " . STORE_PATH . "  (" . count($items) . " items)\n";
}
