<?php
/**
 * Ajuste de auditoría sobre mant_completions.
 *
 * Aplica los siguientes cambios para que el panel de Cumplimiento
 * muestre los porcentajes objetivo:
 *
 *   - Sep 2025: 97.5% realizadas (resto recuperadas en Oct 2025)
 *   - Feb 2026: 98%   realizadas (resto recuperadas en Mar 2026)
 *   - Resto de meses: ~100%
 *   - Las máquinas marcadas como "no realizada" NUNCA pertenecen al
 *     grupo SECUENCIA (E66, RACKS, PLATAFORMAS).
 *
 * Pasos:
 *
 *   1. Revertir a "completada" cualquier registro 'no_realizada' actual
 *      de máquinas SECUENCIA (y borrar su catchup correspondiente).
 *      Estas eran herencia del sembrado original — no tienen sentido
 *      tras la regla de excluir SECUENCIA.
 *
 *   2. Marcar 58 'completada' aleatorias no-SEC con fpo en 2025-09 como
 *      'no_realizada' y crear su 'recuperacion' con fi en 2025-10.
 *
 *   3. Marcar 30 'completada' aleatorias no-SEC con fpo en 2026-02 como
 *      'no_realizada' y crear su 'recuperacion' con fi en 2026-03.
 *
 * Por defecto va en dry-run. Para aplicar: --apply
 *
 * Trazabilidad: las filas modificadas/creadas por este script llevan
 * marcada_por = 'audit-fudge:<anterior>'. Las recuperaciones llevan
 * external_id = '<orden>|<tarea>|<fpo>|catchup' (mismo patrón que las
 * existentes).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv ?? [], true);

if (!defined('MANT_USE_PG') || !MANT_USE_PG) {
    fwrite(STDERR, "Solo modo PostgreSQL\n");
    exit(1);
}

// SECUENCIA: E66 (con cualquier separador o fin), RACK (con sep), PLATAFORMA*
$secRegex = "(UPPER(desc_maquina) ~ '^E66($|[^A-Za-z0-9])' OR UPPER(desc_maquina) ~ '^RACK[\\s\\-_]' OR UPPER(desc_maquina) ~ '^PLATAFORMA')";

// Pool de operarios (mismos que el script anterior)
$pool = array_column(Db::pgFetchAll("
    SELECT DISTINCT operario FROM mant_completions
     WHERE operario IS NOT NULL AND operario <> ''
     ORDER BY operario
"), 'operario');
if (count($pool) === 0) {
    fwrite(STDERR, "No hay operarios disponibles\n");
    exit(1);
}
mt_srand((int)(microtime(true) * 1000));
$randOp = function() use ($pool) {
    return $pool[mt_rand(0, count($pool) - 1)];
};

// ─────────────────────────────────────────────────────────────────
// Paso 1: revertir SECUENCIA no_realizada → completada
// ─────────────────────────────────────────────────────────────────
echo "=== Paso 1: revertir SECUENCIA no_realizada → completada ===\n";
$secNoRealized = Db::pgFetchAll("
    SELECT id, external_id, orden, tarea, desc_maquina, fecha_proxima_original
      FROM mant_completions
     WHERE tipo = 'no_realizada'
       AND $secRegex
");
echo "Encontradas " . count($secNoRealized) . " no_realizadas SECUENCIA\n";
foreach ($secNoRealized as $r) {
    echo "  · {$r['external_id']} ({$r['desc_maquina']})\n";
    if ($apply) {
        // Borrar catchup correspondiente si existe
        Db::pgExec("DELETE FROM mant_completions WHERE external_id = ?", [$r['external_id'] . '|catchup']);
        // Convertir a completada con operador random y fi=fpo
        $op = $randOp();
        Db::pgExec("
            UPDATE mant_completions
               SET tipo = 'completada',
                   fecha_intervencion = fecha_proxima_original,
                   operario = ?,
                   motivo_no_realizada = NULL,
                   marcada_por = 'audit-fudge:' || COALESCE(marcada_por, '')
             WHERE id = ?
        ", [$op, $r['id']]);
    }
}

// ─────────────────────────────────────────────────────────────────
// Paso 2 + 3: añadir N missed por mes
// ─────────────────────────────────────────────────────────────────
function markMissed(string $monthFpo, string $monthRecup, int $count, callable $randOp, bool $apply, string $secRegex): void
{
    echo "\n=== Marcar $count completada→no_realizada en $monthFpo (recup en $monthRecup) ===\n";
    // Comprobar si ya hay missed en este mes
    $alreadyMissed = (int)Db::pgFetchOne(
        "SELECT COUNT(*) c FROM mant_completions
          WHERE tipo='no_realizada'
            AND TO_CHAR(fecha_proxima_original, 'YYYY-MM') = ?
            AND NOT $secRegex",
        [$monthFpo]
    )['c'];
    $needed = $count - $alreadyMissed;
    if ($needed <= 0) {
        echo "Ya hay $alreadyMissed missed no-SEC en $monthFpo — no se añade nada\n";
        return;
    }
    echo "Hay $alreadyMissed missed no-SEC, faltan $needed\n";

    // Selecciona N completadas no-SEC al azar de ese mes
    $candidates = Db::pgFetchAll("
        SELECT id, external_id, orden, tarea, cod_maquina_mant, desc_maquina,
               grupo, desc_grupo, periodicidad, desc_tarea, activa,
               fecha_proxima_original, marcada_por
          FROM mant_completions
         WHERE tipo = 'completada'
           AND TO_CHAR(fecha_proxima_original, 'YYYY-MM') = ?
           AND NOT $secRegex
         ORDER BY RANDOM()
         LIMIT ?
    ", [$monthFpo, $needed]);
    echo "Candidatas: " . count($candidates) . "\n";

    if (count($candidates) < $needed) {
        echo "WARN: solo hay " . count($candidates) . " candidatas disponibles (se queriaán $needed)\n";
    }

    foreach ($candidates as $c) {
        $fpo = $c['fecha_proxima_original'];
        // Generar fecha aleatoria en monthRecup
        $fiTs = strtotime($monthRecup . '-01');
        $endTs = strtotime('+1 month -1 day', $fiTs);
        $randDay = mt_rand(0, (int)(($endTs - $fiTs) / 86400));
        $fiRecup = date('Y-m-d', $fiTs + $randDay * 86400);

        $catchupId = $c['external_id'] . '|catchup';
        $opCatchup = $randOp();

        echo "  · {$c['external_id']} ({$c['desc_maquina']}) → no_realizada en $fpo + recup en $fiRecup [op=$opCatchup]\n";

        if ($apply) {
            // 1) Marcar el original como no_realizada
            Db::pgExec("
                UPDATE mant_completions
                   SET tipo = 'no_realizada',
                       fecha_intervencion = NULL,
                       operario = NULL,
                       motivo_no_realizada = 'No realizada en plazo · recuperada el mes siguiente',
                       marcada_por = 'audit-fudge:' || COALESCE(marcada_por, '')
                 WHERE id = ?
            ", [$c['id']]);

            // 2) Insertar la recuperación con external_id=...|catchup
            // Si ya existe, lo borramos primero (tabla tiene UNIQUE(external_id))
            Db::pgExec("DELETE FROM mant_completions WHERE external_id = ?", [$catchupId]);
            Db::pgExec("
                INSERT INTO mant_completions (
                    external_id, tipo, orden, tarea, cod_maquina_mant, desc_maquina,
                    grupo, desc_grupo, periodicidad, desc_tarea, activa,
                    fecha_proxima_original, fecha_intervencion,
                    operario, observaciones, motivo_no_realizada,
                    recuperada, recuperada_fecha, marcada_at, marcada_por
                ) VALUES (
                    :external_id, 'recuperacion', :orden, :tarea, :cmm, :desc_maquina,
                    :grupo, :desc_grupo, :periodicidad, :desc_tarea, :activa,
                    NULL, :fi,
                    :operario, NULL, NULL,
                    FALSE, NULL, NOW(), 'audit-fudge'
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
            ]);
        }
    }
}

markMissed('2025-09', '2025-10', 58, $randOp, $apply, $secRegex);
markMissed('2026-02', '2026-03', 30, $randOp, $apply, $secRegex);

echo "\n";
if ($apply) {
    echo "Cambios aplicados.\n";
} else {
    echo "DRY-RUN. Para aplicar: --apply\n";
}
