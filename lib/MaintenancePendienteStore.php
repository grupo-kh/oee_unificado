<?php
require_once __DIR__ . '/Db.php';

/**
 * Almacén de tareas marcadas con bandera "pendiente de revisar".
 *
 * Modo:
 *   - PostgreSQL (MANT_USE_PG=true): tabla `mant_pendientes`
 *   - JSON legacy: data/maintenance_pendiente.json
 *
 * Una tarea marcada como pendiente:
 *   - Aparece SIEMPRE en la vista de Preventivos por Semana, aunque su
 *     próxima revisión esté fuera del rango seleccionado.
 *   - Se muestra con un check rojo intenso para señalar que debería
 *     haberse terminado.
 *
 * Se considera resuelta cuando el usuario:
 *   - Quita el check rojo manualmente (POST con pendiente=0), o
 *   - Marca la revisión como hecha (mant_marcar_hecha.php), que limpia
 *     automáticamente la bandera.
 *
 * Identificador: orden|tarea|fecha_proxima_original.
 */
class MaintenancePendienteStore
{
    const STORE_PATH = __DIR__ . '/../data/maintenance_pendiente.json';

    private static function usePg(): bool
    {
        return defined('MANT_USE_PG') && MANT_USE_PG === true;
    }

    public static function buildId(string $orden, string $tarea, string $fechaProxima): string
    {
        return $orden . '|' . $tarea . '|' . $fechaProxima;
    }

    public static function loadAll(): array
    {
        if (self::usePg()) {
            $rows = Db::pgFetchAll("SELECT * FROM mant_pendientes ORDER BY id");
            return array_map([self::class, 'pgRowToArr'], $rows);
        }
        if (!is_file(self::STORE_PATH)) return [];
        $raw = @file_get_contents(self::STORE_PATH);
        if ($raw === false || $raw === '') return [];
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) return [];
        return $data['items'];
    }

    public static function loadIndexed(): array
    {
        $idx = [];
        foreach (self::loadAll() as $it) {
            if (isset($it['id'])) $idx[$it['id']] = $it;
        }
        return $idx;
    }

    public static function set(array $rec): array
    {
        $required = ['orden', 'tarea', 'fecha_proxima_original'];
        foreach ($required as $k) {
            if (!isset($rec[$k]) || $rec[$k] === '') {
                throw new InvalidArgumentException("Campo requerido: $k");
            }
        }
        $id = self::buildId(
            (string)$rec['orden'],
            (string)$rec['tarea'],
            (string)$rec['fecha_proxima_original']
        );
        $now = time();
        $item = [
            'id'                     => $id,
            'orden'                  => (string)$rec['orden'],
            'tarea'                  => (string)$rec['tarea'],
            'fecha_proxima_original' => (string)$rec['fecha_proxima_original'],
            'cod_maquina_mant'       => (string)($rec['cod_maquina_mant'] ?? ''),
            'desc_maquina'           => (string)($rec['desc_maquina'] ?? ''),
            'desc_grupo'             => (string)($rec['desc_grupo'] ?? ''),
            'desc_tarea'             => (string)($rec['desc_tarea'] ?? ''),
            'periodicidad'           => (string)($rec['periodicidad'] ?? ''),
            'set_at'                 => $now,
            'set_por'                => (string)($rec['set_por'] ?? ''),
            'nota'                   => trim((string)($rec['nota'] ?? '')),
        ];

        if (self::usePg()) {
            Db::pgExec("
                INSERT INTO mant_pendientes (
                    orden, tarea, fecha_proxima_original, cod_maquina_mant,
                    desc_maquina, desc_grupo, desc_tarea, periodicidad,
                    set_at, set_por, nota
                ) VALUES (
                    :orden, :tarea, :fpo, :cod_maquina_mant,
                    :desc_maquina, :desc_grupo, :desc_tarea, :periodicidad,
                    to_timestamp(:set_at), :set_por, :nota
                )
                ON CONFLICT (orden, tarea, fecha_proxima_original) DO UPDATE SET
                    cod_maquina_mant = EXCLUDED.cod_maquina_mant,
                    desc_maquina     = EXCLUDED.desc_maquina,
                    desc_grupo       = EXCLUDED.desc_grupo,
                    desc_tarea       = EXCLUDED.desc_tarea,
                    periodicidad     = EXCLUDED.periodicidad,
                    set_at           = EXCLUDED.set_at,
                    set_por          = EXCLUDED.set_por,
                    nota             = EXCLUDED.nota
            ", [
                ':orden'            => $item['orden'],
                ':tarea'            => $item['tarea'],
                ':fpo'              => $item['fecha_proxima_original'],
                ':cod_maquina_mant' => $item['cod_maquina_mant'] ?: null,
                ':desc_maquina'     => $item['desc_maquina']     ?: null,
                ':desc_grupo'       => $item['desc_grupo']       ?: null,
                ':desc_tarea'       => $item['desc_tarea']       ?: null,
                ':periodicidad'     => $item['periodicidad']     ?: null,
                ':set_at'           => $item['set_at'],
                ':set_por'          => $item['set_por'] ?: null,
                ':nota'             => $item['nota']    ?: null,
            ]);
        } else {
            self::withLock(function() use ($item) {
                $items = self::loadAll();
                $items = array_values(array_filter($items, fn($x) => ($x['id'] ?? '') !== $item['id']));
                $items[] = $item;
                self::writeAll($items);
            });
        }
        return $item;
    }

    public static function remove(string $id): ?array
    {
        if (self::usePg()) {
            $parts = explode('|', $id, 3);
            if (count($parts) < 3) return null;
            [$orden, $tarea, $fpo] = $parts;
            $row = Db::pgFetchOne(
                "SELECT * FROM mant_pendientes WHERE orden = ? AND tarea = ? AND fecha_proxima_original = ?",
                [$orden, $tarea, $fpo]
            );
            if (!$row) return null;
            Db::pgExec(
                "DELETE FROM mant_pendientes WHERE orden = ? AND tarea = ? AND fecha_proxima_original = ?",
                [$orden, $tarea, $fpo]
            );
            return self::pgRowToArr($row);
        }
        $removed = null;
        self::withLock(function() use ($id, &$removed) {
            $items = self::loadAll();
            $kept = [];
            foreach ($items as $it) {
                if (($it['id'] ?? '') === $id) { $removed = $it; continue; }
                $kept[] = $it;
            }
            self::writeAll($kept);
        });
        return $removed;
    }

    private static function pgRowToArr(array $row): array
    {
        return [
            'id'                     => (string)$row['orden'] . '|' . (string)$row['tarea'] . '|' . (string)$row['fecha_proxima_original'],
            'orden'                  => (string)$row['orden'],
            'tarea'                  => (string)$row['tarea'],
            'fecha_proxima_original' => (string)$row['fecha_proxima_original'],
            'cod_maquina_mant'       => (string)($row['cod_maquina_mant'] ?? ''),
            'desc_maquina'           => (string)($row['desc_maquina']     ?? ''),
            'desc_grupo'             => (string)($row['desc_grupo']       ?? ''),
            'desc_tarea'             => (string)($row['desc_tarea']       ?? ''),
            'periodicidad'           => (string)($row['periodicidad']     ?? ''),
            'set_at'                 => isset($row['set_at']) ? strtotime((string)$row['set_at']) : 0,
            'set_por'                => (string)($row['set_por'] ?? ''),
            'nota'                   => (string)($row['nota']    ?? ''),
        ];
    }

    private static function writeAll(array $items): void
    {
        $dir = dirname(self::STORE_PATH);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $tmp = self::STORE_PATH . '.tmp.' . getmypid();
        $payload = json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($tmp, $payload);
        @rename($tmp, self::STORE_PATH);
    }

    private static function withLock(callable $fn): void
    {
        $dir = dirname(self::STORE_PATH);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $lockFile = self::STORE_PATH . '.lock';
        $fp = fopen($lockFile, 'c');
        if ($fp === false) { $fn(); return; }
        try {
            flock($fp, LOCK_EX);
            $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
