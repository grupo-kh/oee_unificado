<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenanceCalendarioStore.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

Auth::requireLoginApi();
Auth::requireTecnicoApi();

/**
 * API del calendario laboral de mantenimiento.
 *
 * GET  ?action=mes&ym=YYYY-MM        → datos del mes (días + excepciones + tareas)
 * GET  ?action=year&y=YYYY           → datos del año (12 meses con excepciones)
 * GET  ?action=tareas_dia&fecha=...  → tareas planificadas en un día concreto
 * POST ?action=set                   → upsert excepción + recalcular (JSON body)
 * POST ?action=delete                → quita excepción + recalcular (JSON body)
 * POST ?action=mover_tarea           → mover UNA tarea a otra fecha (JSON body)
 */

function readJsonBody(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
    return $_POST ?: [];
}

try {
    $action = (string)getParam('action', 'mes');
    if (in_array($action, ['set', 'delete', 'mover_tarea'], true)) {
        Auth::requireCsrfApi();
    }

    switch ($action) {
        case 'mes': {
            $ym = (string)getParam('ym', date('Y-m'));
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) jsonError('ym inválido (YYYY-MM)');
            [$y, $m] = array_map('intval', explode('-', $ym));
            $first = sprintf('%04d-%02d-01', $y, $m);
            $last  = date('Y-m-t', strtotime($first));

            // Excepciones BD del mes
            $excs = MaintenanceCalendarioStore::listarRango($first, $last);
            $excIdx = [];
            foreach ($excs as $e) $excIdx[$e['fecha']] = $e;

            // Recorremos todos los días del mes
            $dias = [];
            $cursor = $first;
            while ($cursor <= $last) {
                $dow = (int)date('N', strtotime($cursor)); // 1=lun … 7=dom
                $habil = CalendarioLaboral::esDiaHabil($cursor);
                $base = ($dow <= 5);    // L-V por defecto
                $exc  = $excIdx[$cursor] ?? null;
                $dias[] = [
                    'fecha'     => $cursor,
                    'dow'       => $dow,
                    'base'      => $base,         // ¿sería L-V?
                    'habil'     => $habil,        // resultado final (excepciones incluidas)
                    'excepcion' => $exc,          // {fecha,tipo,motivo} o null
                ];
                $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
            }

            // Tareas planificadas en cada día del mes (para badge en el cal)
            $tarRows = \Db::pgFetchAll("
                SELECT to_char(proxima_revision, 'YYYY-MM-DD') AS f,
                       COUNT(*)::int AS n
                  FROM mant_plan
                 WHERE proxima_revision BETWEEN :fa AND :fb
                   AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
                   AND COALESCE(activa,    'A')    = 'A'
                   AND fecha_pausado IS NULL
                 GROUP BY proxima_revision
            ", [':fa' => $first, ':fb' => $last]);
            $tareasPorDia = [];
            foreach ($tarRows as $r) $tareasPorDia[(string)$r['f']] = (int)$r['n'];

            jsonOk([
                'ym'              => $ym,
                'primer_dia'      => $first,
                'ultimo_dia'      => $last,
                'dias'            => $dias,
                'excepciones'     => $excs,
                'tareas_por_dia'  => $tareasPorDia,
            ]);
            break;
        }

        case 'set': {
            $body = readJsonBody();
            $fecha  = trim((string)($body['fecha']  ?? ''));
            $tipo   = trim((string)($body['tipo']   ?? ''));
            $motivo = trim((string)($body['motivo'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');
            if (!in_array($tipo, MaintenanceCalendarioStore::TIPOS, true)) jsonError('tipo inválido');

            $usuario = (string)(Auth::user() ?? '');

            // Guardamos excepción + recalcular en una transacción
            \Db::pg()->beginTransaction();
            try {
                $exc = MaintenanceCalendarioStore::setExcepcion($fecha, $tipo, $motivo, $usuario);
                $recalc = MaintenanceCalendarioStore::recalcularProximas($fecha, $tipo);
                \Db::pg()->commit();
            } catch (Throwable $e) {
                if (\Db::pg()->inTransaction()) \Db::pg()->rollBack();
                throw $e;
            }
            jsonOk([
                'excepcion'         => $exc,
                'tareas_movidas'    => $recalc['movidas'],
                'tareas_examinadas' => $recalc['examinadas'],
                'detalle'           => $recalc['detalle'],
            ]);
            break;
        }

        case 'delete': {
            $body = readJsonBody();
            $fecha = trim((string)($body['fecha'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');
            MaintenanceCalendarioStore::deleteExcepcion($fecha);
            jsonOk(['ok' => true, 'fecha' => $fecha]);
            break;
        }

        case 'year': {
            $y = (int)getParam('y', date('Y'));
            if ($y < 2020 || $y > 2099) jsonError('año fuera de rango (2020-2099)');
            $first = sprintf('%04d-01-01', $y);
            $last  = sprintf('%04d-12-31', $y);

            // Excepciones BD del año
            $excs = MaintenanceCalendarioStore::listarRango($first, $last);
            $excIdx = [];
            foreach ($excs as $e) $excIdx[$e['fecha']] = $e;

            // Conteo de tareas activas planificadas por día del año
            $tarRows = \Db::pgFetchAll("
                SELECT to_char(proxima_revision, 'YYYY-MM-DD') AS f,
                       COUNT(*)::int AS n
                  FROM mant_plan
                 WHERE proxima_revision BETWEEN :fa AND :fb
                   AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
                   AND COALESCE(activa,    'A')    = 'A'
                   AND fecha_pausado IS NULL
                 GROUP BY proxima_revision
            ", [':fa' => $first, ':fb' => $last]);
            $tareasPorDia = [];
            foreach ($tarRows as $r) $tareasPorDia[(string)$r['f']] = (int)$r['n'];

            // Recorrer cada día del año y agruparlos por mes (1..12)
            $meses = array_fill_keys(range(1, 12), []);
            $cursor = $first;
            while ($cursor <= $last) {
                $ts    = strtotime($cursor);
                $m     = (int)date('n', $ts);
                $dow   = (int)date('N', $ts);
                $habil = CalendarioLaboral::esDiaHabil($cursor);
                $base  = ($dow <= 5);
                $exc   = $excIdx[$cursor] ?? null;
                $meses[$m][] = [
                    'fecha'     => $cursor,
                    'dia'       => (int)date('j', $ts),
                    'dow'       => $dow,
                    'base'      => $base,
                    'habil'     => $habil,
                    'excepcion' => $exc,
                    'tareas'    => $tareasPorDia[$cursor] ?? 0,
                ];
                $cursor = date('Y-m-d', strtotime("$cursor +1 day"));
            }

            // Estadísticas globales del año
            $nHabiles = 0; $nExc = 0;
            foreach ($meses as $diasMes) {
                foreach ($diasMes as $d) {
                    if ($d['habil']) $nHabiles++;
                    if ($d['excepcion']) $nExc++;
                }
            }
            jsonOk([
                'y'                 => $y,
                'meses'             => $meses,
                'excepciones'       => $excs,
                'dias_habiles_anyo' => $nHabiles,
                'excepciones_cnt'   => $nExc,
            ]);
            break;
        }

        case 'tareas_dia': {
            $fecha = trim((string)getParam('fecha', ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) jsonError('fecha inválida');
            $rows = \Db::pgFetchAll("
                SELECT id,
                       cod_maquina_mant, desc_maquina,
                       orden, tarea, desc_tarea,
                       periodicidad,
                       to_char(ultima_revision,  'YYYY-MM-DD') AS ultima_revision,
                       to_char(proxima_revision, 'YYYY-MM-DD') AS proxima_revision
                  FROM mant_plan
                 WHERE proxima_revision = :f
                   AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
                   AND COALESCE(activa,    'A')    = 'A'
                   AND fecha_pausado IS NULL
                 ORDER BY desc_maquina, periodicidad, tarea
            ", [':f' => $fecha]);
            jsonOk([
                'fecha'  => $fecha,
                'total'  => count($rows),
                'tareas' => array_map(fn($r) => [
                    'id'                => (int)$r['id'],
                    'cod_maquina_mant'  => (string)($r['cod_maquina_mant'] ?? ''),
                    'desc_maquina'      => (string)($r['desc_maquina']     ?? ''),
                    'orden'             => (string)($r['orden']            ?? ''),
                    'tarea'             => (string)($r['tarea']            ?? ''),
                    'desc_tarea'        => (string)($r['desc_tarea']       ?? ''),
                    'periodicidad'      => (string)($r['periodicidad']     ?? ''),
                    'ultima_revision'   => $r['ultima_revision']  ?: null,
                    'proxima_revision'  => $r['proxima_revision'] ?: null,
                ], $rows),
            ]);
            break;
        }

        case 'mover_tarea': {
            $body = readJsonBody();
            $id    = (int)($body['id'] ?? 0);
            $nueva = trim((string)($body['nueva_fecha'] ?? ''));
            if ($id <= 0) jsonError('id inválido');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nueva)) jsonError('nueva_fecha inválida');
            // Comprobamos que la tarea exista y esté activa
            $r = \Db::pgFetchOne("
                SELECT id, desc_maquina, tarea,
                       to_char(proxima_revision, 'YYYY-MM-DD') AS antes
                  FROM mant_plan
                 WHERE id = :id
                   AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
                   AND COALESCE(activa,    'A')    = 'A'
            ", [':id' => $id]);
            if (!$r) jsonError('Tarea no encontrada o no activa', 404);
            \Db::pgExec(
                "UPDATE mant_plan SET proxima_revision = :p WHERE id = :id",
                [':p' => $nueva, ':id' => $id]
            );
            jsonOk([
                'id'    => $id,
                'antes' => (string)$r['antes'],
                'desde' => (string)$r['antes'],
                'hasta' => $nueva,
                'desc_maquina' => (string)$r['desc_maquina'],
                'tarea'        => (string)$r['tarea'],
            ]);
            break;
        }

        default:
            jsonError('action desconocida: ' . $action, 400);
    }
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (Throwable $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
