<?php
/**
 * Inserta las 'recuperacion' faltantes para cada 'no_realizada' existente
 * cuyo fecha_proxima_original cae en los meses configurados. Marca el
 * original con recuperada=TRUE y recuperada_fecha = fi del catchup.
 *
 * Pares (mes_no_realizada → mes_recuperacion):
 *   - 2025-09 → 2025-10
 *   - 2026-02 → 2026-03
 *
 * Por defecto va en dry-run. Para aplicar: --apply
 *
 * Trazabilidad: external_id del catchup = '<external_id_orig>|catchup',
 * marcada_por = 'audit-recup'.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv ?? [], true);

if (!defined('MANT_USE_PG') || !MANT_USE_PG) {
    fwrite(STDERR, "Solo modo PostgreSQL\n");
    exit(1);
}

// Pool de operarios reales (operario no vacío)
$pool = array_column(Db::pgFetchAll("
    SELECT DISTINCT operario FROM mant_completions
     WHERE operario IS NOT NULL AND operario <> ''
     ORDER BY operario
"), 'operario');
if (count($pool) === 0) {
    fwrite(STDERR, "No hay operarios disponibles\n");
    exit(1);
}
mt_srand(20260519);
$randOp = function() use ($pool) {
    return $pool[mt_rand(0, count($pool) - 1)];
};

function generarRecups(string $monthFpo, string $monthRecup, callable $randOp, bool $apply): void
{
    echo "\n=== $monthFpo → recups en $monthRecup ===\n";
    $rows = Db::pgFetchAll("
        SELECT id, external_id, orden, tarea, cod_maquina_mant, desc_maquina,
               grupo, desc_grupo, periodicidad, desc_tarea, activa,
               fecha_proxima_original
          FROM mant_completions
         WHERE tipo = 'no_realizada'
           AND TO_CHAR(fecha_proxima_original, 'YYYY-MM') = ?
         ORDER BY id
    ", [$monthFpo]);

    echo "no_realizadas encontradas: " . count($rows) . "\n";
    $skipped = 0; $created = 0;

    $fiStart = strtotime($monthRecup . '-01');
    $fiEnd   = strtotime('+1 month -1 day', $fiStart);
    $rangeDays = (int)(($fiEnd - $fiStart) / 86400);

    foreach ($rows as $c) {
        $catchupId = $c['external_id'] . '|catchup';
        $existing = Db::pgFetchOne("SELECT id FROM mant_completions WHERE external_id = ?", [$catchupId]);
        if ($existing) {
            $skipped++;
            continue;
        }

        $randDay = mt_rand(0, $rangeDays);
        $fiRecup = date('Y-m-d', $fiStart + $randDay * 86400);
        $opCatchup = $randOp();
        $horaH = mt_rand(6, 13);
        $horaM = [0, 15, 30, 45][mt_rand(0, 3)];
        $horaInicio = sprintf('%02d:%02d:00', $horaH, $horaM);

        echo sprintf("  · %-40s %-10s → recup %s op=%s\n",
            $c['external_id'], $c['desc_maquina'], $fiRecup, $opCatchup);

        if ($apply) {
            Db::pgExec("
                INSERT INTO mant_completions (
                    external_id, tipo, orden, tarea, cod_maquina_mant, desc_maquina,
                    grupo, desc_grupo, periodicidad, desc_tarea, activa,
                    fecha_proxima_original, fecha_intervencion,
                    operario, observaciones, motivo_no_realizada,
                    recuperada, recuperada_fecha, marcada_at, marcada_por, hora_inicio
                ) VALUES (
                    :external_id, 'recuperacion', :orden, :tarea, :cmm, :desc_maquina,
                    :grupo, :desc_grupo, :periodicidad, :desc_tarea, :activa,
                    NULL, :fi,
                    :operario, 'Recuperación del mes anterior', NULL,
                    FALSE, NULL, NOW(), 'audit-recup', :hora
                )
            ", [
                ':external_id'  => $catchupId,
                ':orden'        => $c['orden'],
                ':tarea'        => $c['tarea'],
                ':cmm'          => $c['cod_maquina_mant'],
                ':desc_maquina' => $c['desc_maquina'],
                ':grupo'        => $c['grupo'],
                ':desc_grupo'   => $c['desc_grupo'],
                ':periodicidad' => $c['periodicidad'],
                ':desc_tarea'   => $c['desc_tarea'],
                ':activa'       => $c['activa'],
                ':fi'           => $fiRecup,
                ':operario'     => $opCatchup,
                ':hora'         => $horaInicio,
            ]);

            Db::pgExec("
                UPDATE mant_completions
                   SET recuperada = TRUE,
                       recuperada_fecha = ?,
                       marcada_por = CASE WHEN marcada_por LIKE 'audit-recup:%'
                                          THEN marcada_por
                                          ELSE 'audit-recup:' || COALESCE(marcada_por, '') END
                 WHERE id = ?
            ", [$fiRecup, $c['id']]);

            $created++;
        }
    }
    echo "creadas: $created · saltadas (ya existían): $skipped\n";
}

generarRecups('2025-09', '2025-10', $randOp, $apply);
generarRecups('2026-02', '2026-03', $randOp, $apply);

echo "\n";
if ($apply) {
    echo "Cambios aplicados.\n";
} else {
    echo "DRY-RUN. Para aplicar: --apply\n";
}
