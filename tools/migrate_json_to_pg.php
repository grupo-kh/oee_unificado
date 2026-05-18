<?php
/**
 * Migra los 3 ficheros JSON de mantenimiento a PostgreSQL.
 *
 * - data/maintenance_completed.json     → mant_completions
 * - data/maintenance_periodicidad.json  → mant_periodicidad_overrides
 * - data/maintenance_pendiente.json     → mant_pendientes
 *
 * Idempotente: usa ON CONFLICT en cada inserción. Si ya hay datos en PG con
 * los mismos IDs, los actualiza (no duplica). Permite ejecutarlo varias veces.
 *
 * Uso:
 *   php tools/migrate_json_to_pg.php [--dry-run]
 */

require_once __DIR__ . '/../lib/Db.php';

if (!defined('MANT_USE_PG') || !MANT_USE_PG) {
    fwrite(STDERR, "MANT_USE_PG no está activo en config/database.php — abortando." . PHP_EOL);
    exit(1);
}

$opts   = getopt('', ['dry-run']);
$dryRun = isset($opts['dry-run']);

$dataDir = realpath(__DIR__ . '/../data');
$files = [
    'completed'    => $dataDir . '/maintenance_completed.json',
    'periodicidad' => $dataDir . '/maintenance_periodicidad.json',
    'pendiente'    => $dataDir . '/maintenance_pendiente.json',
];

echo "Migrador JSON → PostgreSQL" . ($dryRun ? ' [DRY-RUN]' : '') . PHP_EOL;
$pdo = Db::pg();

function loadItems(string $path): array {
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    return (is_array($d) && isset($d['items']) && is_array($d['items'])) ? $d['items'] : [];
}

function dateOrNull($v) {
    if ($v === null) return null;
    $s = (string)$v;
    if ($s === '') return null;
    return preg_match('/^\d{4}-\d{2}-\d{2}/', $s) ? substr($s, 0, 10) : null;
}

// ───── 1) completions ─────
$items = loadItems($files['completed']);
echo "  · maintenance_completed.json: " . count($items) . " items" . PHP_EOL;

if (!$dryRun && $items) {
    $pdo->beginTransaction();
    $sql = "
        INSERT INTO mant_completions (
            external_id, tipo, orden, tarea, cod_maquina_mant, desc_maquina,
            grupo, desc_grupo, periodicidad, desc_tarea, activa,
            fecha_proxima_original, fecha_intervencion,
            operario, observaciones, motivo_no_realizada,
            recuperada, recuperada_fecha, marcada_at, marcada_por
        ) VALUES (
            :external_id, :tipo, :orden, :tarea, :cod_maquina_mant, :desc_maquina,
            :grupo, :desc_grupo, :periodicidad, :desc_tarea, :activa,
            :fpo, :fi, :operario, :observaciones, :motivo,
            :recuperada, :recuperada_fecha, to_timestamp(:marcada_at), :marcada_por
        )
        ON CONFLICT (external_id) DO UPDATE SET
            tipo = EXCLUDED.tipo,
            cod_maquina_mant = EXCLUDED.cod_maquina_mant,
            desc_maquina = EXCLUDED.desc_maquina,
            grupo = EXCLUDED.grupo,
            desc_grupo = EXCLUDED.desc_grupo,
            periodicidad = EXCLUDED.periodicidad,
            desc_tarea = EXCLUDED.desc_tarea,
            activa = EXCLUDED.activa,
            fecha_proxima_original = EXCLUDED.fecha_proxima_original,
            fecha_intervencion = EXCLUDED.fecha_intervencion,
            operario = EXCLUDED.operario,
            observaciones = EXCLUDED.observaciones,
            motivo_no_realizada = EXCLUDED.motivo_no_realizada,
            recuperada = EXCLUDED.recuperada,
            recuperada_fecha = EXCLUDED.recuperada_fecha,
            marcada_at = EXCLUDED.marcada_at,
            marcada_por = EXCLUDED.marcada_por
    ";
    $st = $pdo->prepare($sql);
    $imported = 0;
    foreach ($items as $it) {
        $tipo = (string)($it['tipo'] ?? (empty($it['fecha_intervencion']) ? 'no_realizada' : 'completada'));
        $st->execute([
            ':external_id'      => (string)($it['id'] ?? ''),
            ':tipo'             => $tipo,
            ':orden'            => (string)($it['orden'] ?? ''),
            ':tarea'            => (string)($it['tarea'] ?? ''),
            ':cod_maquina_mant' => (string)($it['cod_maquina_mant'] ?? '') ?: null,
            ':desc_maquina'     => (string)($it['desc_maquina'] ?? '')     ?: null,
            ':grupo'            => (string)($it['grupo'] ?? '')            ?: null,
            ':desc_grupo'       => (string)($it['desc_grupo'] ?? '')       ?: null,
            ':periodicidad'     => (string)($it['periodicidad'] ?? '')     ?: null,
            ':desc_tarea'       => (string)($it['desc_tarea'] ?? '')       ?: null,
            ':activa'           => (string)($it['activa'] ?? '')           ?: null,
            ':fpo'              => dateOrNull($it['fecha_proxima_original'] ?? null),
            ':fi'               => dateOrNull($it['fecha_intervencion']     ?? null),
            ':operario'         => (string)($it['operario']      ?? '')    ?: null,
            ':observaciones'    => (string)($it['observaciones'] ?? '')    ?: null,
            ':motivo'           => (string)($it['motivo_no_realizada'] ?? '') ?: null,
            ':recuperada'       => !empty($it['recuperada']) ? 'true' : 'false',
            ':recuperada_fecha' => dateOrNull($it['recuperada_fecha'] ?? null),
            ':marcada_at'       => is_numeric($it['marcada_at'] ?? 0) ? (int)$it['marcada_at'] : (strtotime((string)($it['marcada_at'] ?? '')) ?: time()),
            ':marcada_por'      => (string)($it['marcada_por'] ?? '') ?: null,
        ]);
        $imported++;
        if ($imported % 1000 === 0) echo "    · $imported…" . PHP_EOL;
    }
    $pdo->commit();
    echo "    ✓ $imported items en mant_completions" . PHP_EOL;
}

// ───── 2) periodicidad overrides ─────
$items = loadItems($files['periodicidad']);
echo "  · maintenance_periodicidad.json: " . count($items) . " items" . PHP_EOL;

if (!$dryRun && $items) {
    $pdo->beginTransaction();
    $st = $pdo->prepare("
        INSERT INTO mant_periodicidad_overrides (orden, tarea, periodicidad, set_at, set_por, nota)
        VALUES (:orden, :tarea, :periodicidad, to_timestamp(:set_at), :set_por, :nota)
        ON CONFLICT (orden, tarea) DO UPDATE SET
            periodicidad = EXCLUDED.periodicidad,
            set_at       = EXCLUDED.set_at,
            set_por      = EXCLUDED.set_por,
            nota         = EXCLUDED.nota
    ");
    foreach ($items as $it) {
        $st->execute([
            ':orden'        => (string)($it['orden'] ?? ''),
            ':tarea'        => (string)($it['tarea'] ?? ''),
            ':periodicidad' => (string)($it['periodicidad'] ?? ''),
            ':set_at'       => (int)($it['set_at'] ?? time()),
            ':set_por'      => (string)($it['set_por'] ?? '') ?: null,
            ':nota'         => (string)($it['nota']    ?? '') ?: null,
        ]);
    }
    $pdo->commit();
    echo "    ✓ insertados/actualizados" . PHP_EOL;
}

// ───── 3) pendientes ─────
$items = loadItems($files['pendiente']);
echo "  · maintenance_pendiente.json: " . count($items) . " items" . PHP_EOL;

if (!$dryRun && $items) {
    $pdo->beginTransaction();
    $st = $pdo->prepare("
        INSERT INTO mant_pendientes (
            orden, tarea, fecha_proxima_original, cod_maquina_mant,
            desc_maquina, desc_grupo, desc_tarea, periodicidad,
            set_at, set_por, nota
        ) VALUES (
            :orden, :tarea, :fpo, :cmm,
            :desc_maquina, :desc_grupo, :desc_tarea, :periodicidad,
            to_timestamp(:set_at), :set_por, :nota
        )
        ON CONFLICT (orden, tarea, fecha_proxima_original) DO UPDATE SET
            cod_maquina_mant = EXCLUDED.cod_maquina_mant,
            desc_maquina = EXCLUDED.desc_maquina,
            desc_grupo   = EXCLUDED.desc_grupo,
            desc_tarea   = EXCLUDED.desc_tarea,
            periodicidad = EXCLUDED.periodicidad,
            set_at       = EXCLUDED.set_at,
            set_por      = EXCLUDED.set_por,
            nota         = EXCLUDED.nota
    ");
    foreach ($items as $it) {
        $st->execute([
            ':orden'        => (string)($it['orden'] ?? ''),
            ':tarea'        => (string)($it['tarea'] ?? ''),
            ':fpo'          => dateOrNull($it['fecha_proxima_original'] ?? null),
            ':cmm'          => (string)($it['cod_maquina_mant'] ?? '') ?: null,
            ':desc_maquina' => (string)($it['desc_maquina']     ?? '') ?: null,
            ':desc_grupo'   => (string)($it['desc_grupo']       ?? '') ?: null,
            ':desc_tarea'   => (string)($it['desc_tarea']       ?? '') ?: null,
            ':periodicidad' => (string)($it['periodicidad']     ?? '') ?: null,
            ':set_at'       => (int)($it['set_at'] ?? time()),
            ':set_por'      => (string)($it['set_por'] ?? '') ?: null,
            ':nota'         => (string)($it['nota']    ?? '') ?: null,
        ]);
    }
    $pdo->commit();
    echo "    ✓ insertados/actualizados" . PHP_EOL;
}

if ($dryRun) { echo PHP_EOL . "[DRY-RUN] no se ha escrito nada en PostgreSQL." . PHP_EOL; exit(0); }

// Resumen final
echo PHP_EOL . "Estado actual en PostgreSQL:" . PHP_EOL;
foreach (['mant_completions','mant_periodicidad_overrides','mant_pendientes','mant_plan','mant_operarios'] as $t) {
    $r = Db::pgFetchOne("SELECT COUNT(*) AS c FROM $t");
    printf("  %-35s %s filas\n", $t, $r['c']);
}
echo PHP_EOL . "Hecho. Los .json siguen en data/ por seguridad — bórralos cuando estés cómodo." . PHP_EOL;
