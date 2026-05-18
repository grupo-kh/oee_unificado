<?php
require_once __DIR__ . '/Db.php';

/**
 * Almacén de intervenciones de mantenimiento.
 *
 * Modo de almacenamiento:
 *   - Si MANT_USE_PG === true: tabla `mant_completions` en PostgreSQL.
 *   - En caso contrario: fichero JSON `data/maintenance_completed.json`
 *     (modo legacy mantenido por compatibilidad mientras se migra).
 *
 * Estructura lógica de un item (idéntica en ambos modos):
 *   id, external_id, tipo (completada|no_realizada|recuperacion),
 *   orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
 *   periodicidad, desc_tarea, activa,
 *   fecha_proxima_original, fecha_intervencion,
 *   operario, observaciones, motivo_no_realizada,
 *   recuperada, recuperada_fecha,
 *   marcada_at (timestamp UNIX en JSON, ISO en PG → convertido),
 *   marcada_por
 */
class MaintenanceCompletionStore
{
    const STORE_PATH = __DIR__ . '/../data/maintenance_completed.json';
    const UNDO_WINDOW_SECONDS = 86400;

    /** ¿Estamos usando PostgreSQL? */
    private static function usePg(): bool
    {
        return defined('MANT_USE_PG') && MANT_USE_PG === true;
    }

    /** Construye el id estable: orden|tarea|fecha_proxima_original. */
    public static function buildId(string $orden, string $tarea, string $fechaProxima): string
    {
        return $orden . '|' . $tarea . '|' . $fechaProxima;
    }

    /** @return array<int, array<string, mixed>> */
    public static function loadAll(): array
    {
        if (self::usePg()) return self::pgLoadAll();
        return self::jsonLoadAll();
    }

    /** Items indexados por id legacy (string). */
    public static function loadIndexed(): array
    {
        $idx = [];
        foreach (self::loadAll() as $it) {
            if (isset($it['id'])) $idx[$it['id']] = $it;
        }
        return $idx;
    }

    /**
     * Última intervención por (orden, tarea) — para auto-reprogramación.
     * @return array<string, array<string, mixed>>
     */
    public static function loadLatestByTask(): array
    {
        if (self::usePg()) {
            $rows = Db::pgFetchAll("
                SELECT * FROM v_mant_latest_by_task
            ");
            $out = [];
            foreach ($rows as $r) {
                $key = (string)$r['orden'] . '|' . (string)$r['tarea'];
                $out[$key] = self::pgRowToArr($r, /*minimal=*/true);
            }
            return $out;
        }
        $latest = [];
        foreach (self::loadAll() as $it) {
            $key = ($it['orden'] ?? '') . '|' . ($it['tarea'] ?? '');
            $cand = (string)($it['fecha_intervencion'] ?? '');
            if ($cand === '') continue;
            if (!isset($latest[$key]) || $cand > (string)($latest[$key]['fecha_intervencion'] ?? '')) {
                $latest[$key] = $it;
            }
        }
        return $latest;
    }

    /** Lista alfabética de operarios distintos con al menos una intervención. */
    public static function loadOperarios(): array
    {
        if (self::usePg()) {
            // Solo cuentan los operarios que han intervenido en máquinas
            // presentes en el catálogo activo (mant_maquinas).
            $rows = Db::pgFetchAll("
                SELECT DISTINCT operario FROM mant_completions c
                WHERE operario IS NOT NULL AND operario <> ''
                  AND EXISTS (
                      SELECT 1 FROM mant_maquinas mm
                       WHERE mm.cod_maquina_mant = c.cod_maquina_mant
                  )
                ORDER BY operario
            ");
            return array_column($rows, 'operario');
        }
        $set = [];
        foreach (self::loadAll() as $it) {
            $op = trim((string)($it['operario'] ?? ''));
            if ($op !== '') $set[$op] = true;
        }
        $arr = array_keys($set); sort($arr);
        return $arr;
    }

    /** Añade o reemplaza un item por external_id. Devuelve el item normalizado. */
    public static function add(array $rec): array
    {
        $required = ['orden', 'tarea', 'fecha_proxima_original'];
        foreach ($required as $k) {
            if (!isset($rec[$k]) || $rec[$k] === '') {
                throw new InvalidArgumentException("Campo requerido: $k");
            }
        }
        $id  = self::buildId((string)$rec['orden'], (string)$rec['tarea'], (string)$rec['fecha_proxima_original']);
        $now = time();
        $item = [
            'id'                     => $id,
            'tipo'                   => (string)($rec['tipo'] ?? 'completada'),
            'orden'                  => (string)$rec['orden'],
            'tarea'                  => (string)$rec['tarea'],
            'cod_maquina_mant'       => (string)($rec['cod_maquina_mant'] ?? ''),
            'desc_maquina'           => (string)($rec['desc_maquina']     ?? ''),
            'grupo'                  => (string)($rec['grupo']            ?? ''),
            'desc_grupo'             => (string)($rec['desc_grupo']       ?? ''),
            'periodicidad'           => (string)($rec['periodicidad']     ?? ''),
            'desc_tarea'             => (string)($rec['desc_tarea']       ?? ''),
            'activa'                 => (string)($rec['activa']           ?? ''),
            'fecha_proxima_original' => (string)$rec['fecha_proxima_original'],
            'fecha_intervencion'     => isset($rec['fecha_intervencion']) && $rec['fecha_intervencion'] !== ''
                                         ? (string)$rec['fecha_intervencion']
                                         : null,
            'operario'               => trim((string)($rec['operario']            ?? '')),
            'observaciones'          => trim((string)($rec['observaciones']       ?? '')),
            'motivo_no_realizada'    => trim((string)($rec['motivo_no_realizada'] ?? '')),
            'recuperada'             => !empty($rec['recuperada']),
            'recuperada_fecha'       => $rec['recuperada_fecha'] ?? null,
            'marcada_at'             => $rec['marcada_at'] ?? $now,
            'marcada_por'            => (string)($rec['marcada_por'] ?? ''),
        ];

        if (self::usePg()) {
            self::pgUpsert($item);
        } else {
            self::jsonUpsert($item);
        }
        return $item;
    }

    /** Borra una marca por id legacy. Solo si está dentro de la ventana de undo, salvo $force. */
    public static function remove(string $id, bool $force = false): ?array
    {
        if (self::usePg()) {
            $row = Db::pgFetchOne("SELECT * FROM mant_completions WHERE external_id = ?", [$id]);
            if (!$row) return null;
            if (!$force) {
                $age = time() - strtotime((string)$row['marcada_at']);
                if ($age > self::UNDO_WINDOW_SECONDS) return null;
            }
            Db::pgExec("DELETE FROM mant_completions WHERE external_id = ?", [$id]);
            return self::pgRowToArr($row);
        }
        $removed = null;
        self::jsonWithLock(function() use ($id, $force, &$removed) {
            $items = self::jsonLoadAll();
            $kept = [];
            foreach ($items as $it) {
                if (($it['id'] ?? '') === $id) {
                    if (!$force) {
                        $age = time() - (int)($it['marcada_at'] ?? 0);
                        if ($age > self::UNDO_WINDOW_SECONDS) { $kept[] = $it; continue; }
                    }
                    $removed = $it;
                    continue;
                }
                $kept[] = $it;
            }
            self::jsonWriteAll($kept);
        });
        return $removed;
    }

    public static function isUndoable(array $item): bool
    {
        $ts = isset($item['marcada_at']) ? (is_numeric($item['marcada_at'])
                ? (int)$item['marcada_at'] : strtotime((string)$item['marcada_at'])) : 0;
        return (time() - $ts) <= self::UNDO_WINDOW_SECONDS;
    }

    // ───────────────── Implementación PostgreSQL ─────────────────

    private static function pgLoadAll(): array
    {
        // Filtramos el histórico para excluir las máquinas "de baja":
        //   - tienen que estar en el catálogo (mant_maquinas)
        //   - y tener al menos una tarea ALTA en mant_plan (alta_baja='ALTA'
        //     y activa='A'). Si todas sus tareas están de baja, la máquina
        //     no debe aparecer en ningún panel.
        // Las filas siguen guardadas en BD: si más adelante se reactiva una
        // máquina (alta_baja='ALTA'), su histórico vuelve a aflorar.
        $rows = Db::pgFetchAll("
            SELECT c.* FROM mant_completions c
            WHERE EXISTS (
                SELECT 1 FROM mant_maquinas mm
                 WHERE mm.cod_maquina_mant = c.cod_maquina_mant
            )
              AND EXISTS (
                SELECT 1 FROM mant_plan p
                 WHERE p.cod_maquina_mant = c.cod_maquina_mant
                   AND COALESCE(p.alta_baja, 'ALTA') = 'ALTA'
                   AND COALESCE(p.activa,    'A')    = 'A'
              )
            ORDER BY COALESCE(c.fecha_intervencion, c.fecha_proxima_original) ASC, c.id ASC
        ");
        return array_map([self::class, 'pgRowToArr'], $rows);
    }

    private static function pgUpsert(array $item): void
    {
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
                :fecha_proxima_original, :fecha_intervencion,
                :operario, :observaciones, :motivo_no_realizada,
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
        $marcadaAt = is_numeric($item['marcada_at']) ? (int)$item['marcada_at']
                   : strtotime((string)$item['marcada_at']);
        Db::pgExec($sql, [
            ':external_id'             => $item['id'],
            ':tipo'                    => $item['tipo'],
            ':orden'                   => $item['orden'],
            ':tarea'                   => $item['tarea'],
            ':cod_maquina_mant'        => $item['cod_maquina_mant'],
            ':desc_maquina'            => $item['desc_maquina'],
            ':grupo'                   => $item['grupo'],
            ':desc_grupo'              => $item['desc_grupo'],
            ':periodicidad'            => $item['periodicidad'],
            ':desc_tarea'              => $item['desc_tarea'],
            ':activa'                  => $item['activa'] !== '' ? $item['activa'] : null,
            ':fecha_proxima_original'  => $item['fecha_proxima_original'] !== '' ? $item['fecha_proxima_original'] : null,
            ':fecha_intervencion'      => $item['fecha_intervencion'],
            ':operario'                => $item['operario'] !== '' ? $item['operario'] : null,
            ':observaciones'           => $item['observaciones'] !== '' ? $item['observaciones'] : null,
            ':motivo_no_realizada'     => $item['motivo_no_realizada'] !== '' ? $item['motivo_no_realizada'] : null,
            ':recuperada'              => $item['recuperada'] ? 'true' : 'false',
            ':recuperada_fecha'        => $item['recuperada_fecha'] ?: null,
            ':marcada_at'              => $marcadaAt,
            ':marcada_por'             => $item['marcada_por'] !== '' ? $item['marcada_por'] : null,
        ]);
    }

    /** Convierte una fila SQL al formato lógico (idéntico al JSON). */
    private static function pgRowToArr(array $row, bool $minimal = false): array
    {
        $base = [
            'id'                     => (string)($row['external_id'] ?? ''),
            'tipo'                   => (string)($row['tipo'] ?? ''),
            'orden'                  => (string)($row['orden'] ?? ''),
            'tarea'                  => (string)($row['tarea'] ?? ''),
            'cod_maquina_mant'       => (string)($row['cod_maquina_mant'] ?? ''),
            'desc_maquina'           => (string)($row['desc_maquina'] ?? ''),
            'grupo'                  => (string)($row['grupo'] ?? ''),
            'desc_grupo'             => (string)($row['desc_grupo'] ?? ''),
            'periodicidad'           => (string)($row['periodicidad'] ?? ''),
            'desc_tarea'             => (string)($row['desc_tarea'] ?? ''),
            'activa'                 => (string)($row['activa'] ?? ''),
            'fecha_proxima_original' => $row['fecha_proxima_original'] ?? null,
            'fecha_intervencion'     => $row['fecha_intervencion'] ?? null,
            'operario'               => (string)($row['operario'] ?? ''),
            'observaciones'          => (string)($row['observaciones'] ?? ''),
            'motivo_no_realizada'    => (string)($row['motivo_no_realizada'] ?? ''),
            'recuperada'             => !empty($row['recuperada']) && $row['recuperada'] !== 'f' && $row['recuperada'] !== false,
            'recuperada_fecha'       => $row['recuperada_fecha'] ?? null,
            'marcada_at'             => isset($row['marcada_at']) ? strtotime((string)$row['marcada_at']) : 0,
            'marcada_por'            => (string)($row['marcada_por'] ?? ''),
        ];
        return $minimal ? array_intersect_key($base, array_flip(['orden','tarea','fecha_intervencion','operario','tipo','id'])) : $base;
    }

    // ───────────────── Implementación JSON (legacy) ─────────────────

    private static function jsonLoadAll(): array
    {
        if (!is_file(self::STORE_PATH)) return [];
        $raw = @file_get_contents(self::STORE_PATH);
        if ($raw === false || $raw === '') return [];
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) return [];
        return $data['items'];
    }

    private static function jsonUpsert(array $item): void
    {
        self::jsonWithLock(function() use ($item) {
            $items = self::jsonLoadAll();
            $items = array_values(array_filter($items, fn($x) => ($x['id'] ?? '') !== $item['id']));
            $items[] = $item;
            self::jsonWriteAll($items);
        });
    }

    private static function jsonWriteAll(array $items): void
    {
        $dir = dirname(self::STORE_PATH);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $tmp = self::STORE_PATH . '.tmp.' . getmypid();
        $payload = json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($tmp, $payload);
        @rename($tmp, self::STORE_PATH);
    }

    private static function jsonWithLock(callable $fn): void
    {
        $dir = dirname(self::STORE_PATH);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $lockFile = self::STORE_PATH . '.lock';
        $fp = fopen($lockFile, 'c');
        if ($fp === false) { $fn(); return; }
        try { flock($fp, LOCK_EX); $fn(); }
        finally { flock($fp, LOCK_UN); fclose($fp); }
    }
}
