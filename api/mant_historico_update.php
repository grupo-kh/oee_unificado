<?php
/**
 * Edición de una intervención del histórico (solo técnico).
 *
 * POST application/json | application/x-www-form-urlencoded
 * Campos:
 *   - id                    (req)  external_id de la marca
 *   - operario              (opc)  texto libre
 *   - fecha_intervencion    (opc)  YYYY-MM-DD (solo si tipo=completada/recuperacion)
 *   - hora_inicio           (opc)  HH:MM
 *   - tiempo_real_segundos  (opc)  entero 0..36000
 *   - observaciones         (opc)  texto libre
 *   - motivo_no_realizada   (opc)  solo si la marca es no_realizada
 *
 * Devuelve:
 *   { ok: true, data: { item: <stored> } }
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/Db.php';

Auth::requireTecnicoApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido', 405);
}

// ── Parseo del body ──
$payload = [];
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = [];
} else {
    $payload = $_POST;
}

try {
    $id = trim((string)($payload['id'] ?? ''));
    if ($id === '') jsonError('Falta id de la intervención');

    // Comprobación previa: ¿existe la columna nueva? Si no, la migración 012
    // no se ha aplicado. Devolvemos un mensaje claro en lugar del error críptico
    // de PostgreSQL "no existe la columna «tiempo_real_segundos»".
    $hasTiempoCol = (bool) Db::pgFetchOne("
        SELECT 1 FROM information_schema.columns
         WHERE table_name = 'mant_completions'
           AND column_name = 'tiempo_real_segundos'
        LIMIT 1
    ");
    if (!$hasTiempoCol) {
        jsonError(
            'Falta aplicar la migración 012. Ejecuta en el servidor: '
            . 'php tools/install_postgres.php  y luego  '
            . 'php tools/mant_backfill_tiempo_real.php --apply --force',
            500
        );
    }

    // Cargar la intervención existente
    $current = Db::pgFetchOne(
        "SELECT * FROM mant_completions WHERE external_id = :id",
        [':id' => $id]
    );
    if (!$current) jsonError('Intervención no encontrada', 404);

    $tipo = (string)($current['tipo'] ?? 'completada');

    // ── Validaciones por campo ──
    $update = [];

    if (array_key_exists('operario', $payload)) {
        $update['operario'] = trim((string)$payload['operario']);
        if ($update['operario'] === '') $update['operario'] = null;
    }

    if (array_key_exists('observaciones', $payload)) {
        $obs = trim((string)$payload['observaciones']);
        if (mb_strlen($obs) > 2000) jsonError('Observaciones demasiado largas (máx 2000)');
        $update['observaciones'] = $obs !== '' ? $obs : null;
    }

    if (array_key_exists('motivo_no_realizada', $payload)) {
        $motivo = trim((string)$payload['motivo_no_realizada']);
        if ($motivo !== '' && !in_array($motivo, [
            'disponibilidad_maquina', 'disponibilidad_operario', 'falta_material'
        ], true)) {
            jsonError("motivo_no_realizada inválido: '$motivo'");
        }
        $update['motivo_no_realizada'] = $motivo !== '' ? $motivo : null;
    }

    if (array_key_exists('fecha_intervencion', $payload)) {
        $fi = trim((string)$payload['fecha_intervencion']);
        if ($fi !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fi)) {
            jsonError('fecha_intervencion debe ser YYYY-MM-DD');
        }
        if ($tipo === 'no_realizada' && $fi !== '') {
            jsonError('Una intervención no_realizada no admite fecha_intervencion');
        }
        $update['fecha_intervencion'] = $fi !== '' ? $fi : null;
    }

    if (array_key_exists('hora_inicio', $payload)) {
        $hi = trim((string)$payload['hora_inicio']);
        if ($hi !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hi)) {
            jsonError('hora_inicio debe ser HH:MM');
        }
        $update['hora_inicio'] = $hi !== '' ? substr($hi, 0, 5) : null;
    }

    if (array_key_exists('tiempo_real_segundos', $payload)) {
        $t = $payload['tiempo_real_segundos'];
        if ($t === '' || $t === null) {
            $update['tiempo_real_segundos'] = null;
        } else {
            if (!is_numeric($t)) jsonError('tiempo_real_segundos debe ser numérico');
            $iv = (int)$t;
            if ($iv < 0 || $iv > 36000) jsonError('tiempo_real_segundos fuera de rango (0..36000)');
            $update['tiempo_real_segundos'] = $iv;
        }
    }

    if (array_key_exists('visita_incompleta', $payload)) {
        $update['visita_incompleta'] = !empty($payload['visita_incompleta']) ? 'true' : 'false';
    }

    if (empty($update)) {
        jsonError('No se ha enviado ningún campo editable', 400);
    }

    // ── UPDATE en BD ──
    $sets = [];
    $params = [':id' => $id];
    foreach ($update as $k => $v) {
        $sets[] = "$k = :$k";
        $params[":$k"] = $v;
    }
    $sql = "UPDATE mant_completions SET " . implode(', ', $sets) . " WHERE external_id = :id";
    Db::pgExec($sql, $params);

    // ── Devolver la intervención actualizada ──
    $row = Db::pgFetchOne(
        "SELECT * FROM mant_completions WHERE external_id = :id",
        [':id' => $id]
    );

    jsonOk(['item' => [
        'id'                     => (string)($row['external_id'] ?? $id),
        'tipo'                   => (string)($row['tipo']                ?? ''),
        'orden'                  => (string)($row['orden']               ?? ''),
        'tarea'                  => (string)($row['tarea']               ?? ''),
        'cod_maquina_mant'       => (string)($row['cod_maquina_mant']    ?? ''),
        'desc_maquina'           => (string)($row['desc_maquina']        ?? ''),
        'periodicidad'           => (string)($row['periodicidad']        ?? ''),
        'desc_tarea'             => (string)($row['desc_tarea']          ?? ''),
        'fecha_proxima_original' => $row['fecha_proxima_original'] ?? null,
        'fecha_intervencion'     => $row['fecha_intervencion']     ?? null,
        'hora_inicio'            => isset($row['hora_inicio']) && $row['hora_inicio'] !== ''
                                      ? substr((string)$row['hora_inicio'], 0, 5) : null,
        'operario'               => (string)($row['operario']            ?? ''),
        'observaciones'          => (string)($row['observaciones']       ?? ''),
        'motivo_no_realizada'    => (string)($row['motivo_no_realizada'] ?? ''),
        'tiempo_real_segundos'   => isset($row['tiempo_real_segundos']) && $row['tiempo_real_segundos'] !== ''
                                      ? (int)$row['tiempo_real_segundos'] : null,
        'visita_incompleta'      => isset($row['visita_incompleta'])
                                      ? (bool)($row['visita_incompleta'] === true
                                          || $row['visita_incompleta'] === 't'
                                          || $row['visita_incompleta'] === '1'
                                          || $row['visita_incompleta'] === 1)
                                      : false,
    ]]);

} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (Throwable $e) {
    jsonError('Error al actualizar: ' . $e->getMessage(), 500);
}
