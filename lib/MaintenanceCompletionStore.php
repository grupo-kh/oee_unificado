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
            'hora_inicio'            => isset($rec['hora_inicio']) && $rec['hora_inicio'] !== ''
                                         ? (string)$rec['hora_inicio']
                                         : null,
            'operario'               => trim((string)($rec['operario']            ?? '')),
            'observaciones'          => trim((string)($rec['observaciones']       ?? '')),
            'motivo_no_realizada'    => trim((string)($rec['motivo_no_realizada'] ?? '')),
            'recuperada'             => !empty($rec['recuperada']),
            'recuperada_fecha'       => $rec['recuperada_fecha'] ?? null,
            'marcada_at'             => $rec['marcada_at'] ?? $now,
            'marcada_por'            => (string)($rec['marcada_por'] ?? ''),
            'tiempo_real_segundos'   => self::resolveTiempoReal($rec),
            'visita_incompleta'      => !empty($rec['visita_incompleta']),
        ];

        if (self::usePg()) {
            self::pgUpsert($item);
        } else {
            self::jsonUpsert($item);
        }
        return $item;
    }

    /**
     * Calcula el tiempo real de la intervención (en segundos).
     *
     * - Si quien llama lo proporciona explícitamente (ej. edición desde el
     *   popup del histórico), se respeta el valor pasado.
     * - Si no se proporciona y el tipo es 'completada' o 'recuperacion', se
     *   genera automáticamente a partir del tiempo_estimado de la tarea
     *   (mant_plan.tiempo_estimado, en minutos) con un decalaje aleatorio
     *   absoluto: |offset| ∈ [5, 10] segundos, signo aleatorio. Es decir,
     *   el offset cae en (-10, -5] ∪ [5, 10). Pequeño pero suficiente para
     *   que dos intervenciones de la misma tarea no salgan idénticas.
     * - Si no hay tiempo_estimado o el tipo es 'no_realizada', devuelve null.
     */
    private static function resolveTiempoReal(array $rec): ?int
    {
        // Valor proporcionado (edición manual): respetamos.
        if (array_key_exists('tiempo_real_segundos', $rec)) {
            $v = $rec['tiempo_real_segundos'];
            if ($v === null || $v === '') return null;
            if (!is_numeric($v)) return null;
            $iv = (int)$v;
            if ($iv < 0) $iv = 0;
            if ($iv > 36000) $iv = 36000;
            return $iv;
        }

        $tipo = (string)($rec['tipo'] ?? 'completada');
        if ($tipo !== 'completada' && $tipo !== 'recuperacion') return null;

        // Buscar tiempo_estimado de la tarea
        $teMin = self::resolveTiempoEstimadoMinutos(
            (string)($rec['orden'] ?? ''),
            (string)($rec['tarea'] ?? '')
        );
        if ($teMin === null || $teMin <= 0) return null;

        return self::aplicarDecalajeAleatorio($teMin * 60);
    }

    /**
     * Aplica el decalaje aleatorio ±5..10 segundos sobre una base en segundos.
     * Magnitud uniforme en [5, 10] y signo aleatorio. Resultado saturado a
     * [0, 36000].
     */
    public static function aplicarDecalajeAleatorio(int $base): int
    {
        $magnitud = mt_rand(5, 10);              // 5..10 inclusive
        $signo    = (mt_rand(0, 1) === 0) ? -1 : 1;
        $offset   = $signo * $magnitud;
        $tiempo   = $base + $offset;
        if ($tiempo < 0) $tiempo = 0;
        if ($tiempo > 36000) $tiempo = 36000;
        return $tiempo;
    }

    /**
     * Devuelve el tiempo_estimado (minutos) de la tarea en mant_plan, o null.
     * Usa el modo PG cuando está activo (lo normal en producción).
     */
    private static function resolveTiempoEstimadoMinutos(string $orden, string $tarea): ?int
    {
        if ($orden === '' || $tarea === '') return null;
        if (!self::usePg()) return null;
        try {
            $row = Db::pgFetchOne(
                "SELECT tiempo_estimado FROM mant_plan WHERE orden = :o AND tarea = :t LIMIT 1",
                [':o' => $orden, ':t' => $tarea]
            );
            if (!$row) return null;
            $v = $row['tiempo_estimado'] ?? null;
            return ($v === null || $v === '') ? null : (int)$v;
        } catch (Throwable $e) {
            return null;
        }
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

    /**
     * Cache de la presencia de la columna tiempo_real_segundos (mig 012).
     * Si la migración no se ha aplicado, no la incluimos en el INSERT
     * para no romper el flujo de "marcar como hecha".
     */
    private static ?bool $hasTiempoColCache = null;
    private static function hasTiempoCol(): bool
    {
        if (self::$hasTiempoColCache !== null) return self::$hasTiempoColCache;
        try {
            $row = Db::pgFetchOne("
                SELECT 1 FROM information_schema.columns
                 WHERE table_name = 'mant_completions'
                   AND column_name = 'tiempo_real_segundos'
                LIMIT 1
            ");
            self::$hasTiempoColCache = (bool)$row;
        } catch (Throwable $e) {
            self::$hasTiempoColCache = false;
        }
        return self::$hasTiempoColCache;
    }

    /**
     * Cache de la presencia de la columna visita_incompleta (mig 013).
     */
    private static ?bool $hasVisitaIncompletaCache = null;
    private static function hasVisitaIncompletaCol(): bool
    {
        if (self::$hasVisitaIncompletaCache !== null) return self::$hasVisitaIncompletaCache;
        try {
            $row = Db::pgFetchOne("
                SELECT 1 FROM information_schema.columns
                 WHERE table_name = 'mant_completions'
                   AND column_name = 'visita_incompleta'
                LIMIT 1
            ");
            self::$hasVisitaIncompletaCache = (bool)$row;
        } catch (Throwable $e) {
            self::$hasVisitaIncompletaCache = false;
        }
        return self::$hasVisitaIncompletaCache;
    }

    private static function pgUpsert(array $item): void
    {
        $hasTC = self::hasTiempoCol();
        $colTC = $hasTC ? ",\n                tiempo_real_segundos" : "";
        $valTC = $hasTC ? ",\n                :tiempo_real_segundos" : "";
        $updTC = $hasTC ? ",\n                tiempo_real_segundos = COALESCE(EXCLUDED.tiempo_real_segundos, mant_completions.tiempo_real_segundos)" : "";
        $hasVI = self::hasVisitaIncompletaCol();
        $colVI = $hasVI ? ",\n                visita_incompleta" : "";
        $valVI = $hasVI ? ",\n                :visita_incompleta" : "";
        $updVI = $hasVI ? ",\n                visita_incompleta = EXCLUDED.visita_incompleta" : "";
        $sql = "
            INSERT INTO mant_completions (
                external_id, tipo, orden, tarea, cod_maquina_mant, desc_maquina,
                grupo, desc_grupo, periodicidad, desc_tarea, activa,
                fecha_proxima_original, fecha_intervencion, hora_inicio,
                operario, observaciones, motivo_no_realizada,
                recuperada, recuperada_fecha, marcada_at, marcada_por$colTC$colVI
            ) VALUES (
                :external_id, :tipo, :orden, :tarea, :cod_maquina_mant, :desc_maquina,
                :grupo, :desc_grupo, :periodicidad, :desc_tarea, :activa,
                :fecha_proxima_original, :fecha_intervencion, :hora_inicio,
                :operario, :observaciones, :motivo_no_realizada,
                :recuperada, :recuperada_fecha, to_timestamp(:marcada_at), :marcada_por$valTC$valVI
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
                hora_inicio = EXCLUDED.hora_inicio,
                operario = EXCLUDED.operario,
                observaciones = EXCLUDED.observaciones,
                motivo_no_realizada = EXCLUDED.motivo_no_realizada,
                recuperada = EXCLUDED.recuperada,
                recuperada_fecha = EXCLUDED.recuperada_fecha,
                marcada_at = EXCLUDED.marcada_at,
                marcada_por = EXCLUDED.marcada_por$updTC$updVI
        ";
        $marcadaAt = is_numeric($item['marcada_at']) ? (int)$item['marcada_at']
                   : strtotime((string)$item['marcada_at']);
        $params = [
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
            ':hora_inicio'             => $item['hora_inicio'] ?? null,
            ':operario'                => $item['operario'] !== '' ? $item['operario'] : null,
            ':observaciones'           => $item['observaciones'] !== '' ? $item['observaciones'] : null,
            ':motivo_no_realizada'     => $item['motivo_no_realizada'] !== '' ? $item['motivo_no_realizada'] : null,
            ':recuperada'              => $item['recuperada'] ? 'true' : 'false',
            ':recuperada_fecha'        => $item['recuperada_fecha'] ?: null,
            ':marcada_at'              => $marcadaAt,
            ':marcada_por'             => $item['marcada_por'] !== '' ? $item['marcada_por'] : null,
        ];
        // Solo añadimos al payload los placeholders presentes en el SQL,
        // que dependen de las migraciones aplicadas (012, 013).
        if (self::hasTiempoCol())            $params[':tiempo_real_segundos'] = $item['tiempo_real_segundos'] ?? null;
        if (self::hasVisitaIncompletaCol())  $params[':visita_incompleta']    = !empty($item['visita_incompleta']) ? 'true' : 'false';
        Db::pgExec($sql, $params);
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
            'hora_inicio'            => isset($row['hora_inicio']) && $row['hora_inicio'] !== ''
                                          ? substr((string)$row['hora_inicio'], 0, 5)
                                          : null,
            'operario'               => (string)($row['operario'] ?? ''),
            'observaciones'          => (string)($row['observaciones'] ?? ''),
            'motivo_no_realizada'    => (string)($row['motivo_no_realizada'] ?? ''),
            'recuperada'             => !empty($row['recuperada']) && $row['recuperada'] !== 'f' && $row['recuperada'] !== false,
            'recuperada_fecha'       => $row['recuperada_fecha'] ?? null,
            'marcada_at'             => isset($row['marcada_at']) ? strtotime((string)$row['marcada_at']) : 0,
            'marcada_por'            => (string)($row['marcada_por'] ?? ''),
            'tiempo_real_segundos'   => isset($row['tiempo_real_segundos']) && $row['tiempo_real_segundos'] !== ''
                                          ? (int)$row['tiempo_real_segundos']
                                          : null,
            'visita_incompleta'      => isset($row['visita_incompleta'])
                                          ? (bool)($row['visita_incompleta'] === true
                                              || $row['visita_incompleta'] === 't'
                                              || $row['visita_incompleta'] === '1'
                                              || $row['visita_incompleta'] === 1)
                                          : false,
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
