<?php
require_once __DIR__ . '/Db.php';

/**
 * Almacén de overrides de periodicidad por tarea (orden|tarea).
 *
 * Modo:
 *   - PostgreSQL (MANT_USE_PG=true): tabla `mant_periodicidad_overrides`
 *   - JSON legacy: data/maintenance_periodicidad.json
 *
 * Cuando se aplica un override, la tarea cuenta bajo la nueva periodicidad
 * y su próxima_revisión se recalcula como ultima_revision + dias(periodicidad).
 */
class MaintenancePeriodicidadStore
{
    const STORE_PATH = __DIR__ . '/../data/maintenance_periodicidad.json';

    private static function usePg(): bool
    {
        return defined('MANT_USE_PG') && MANT_USE_PG === true;
    }

    /** Días aproximados por periodicidad. null = desconocida. */
    public static function diasPorPeriodicidad(string $per): ?int
    {
        $p = strtoupper(trim($per));
        if ($p === '')                      return null;
        if (in_array($p, ['DIARIO','DIARIA'], true))      return 1;
        if ($p === 'SEMANAL')               return 7;
        if ($p === 'QUINCENAL')             return 15;
        if ($p === 'MENSUAL')                return 30;
        if (in_array($p, ['BIMESTRAL','BIMENSUAL'], true)) return 60;
        if ($p === 'TRIMESTRAL')            return 90;
        if ($p === 'CUATRIMESTRAL')         return 120;
        if ($p === 'SEMESTRAL')             return 180;
        if ($p === 'ANUAL')                 return 365;
        return null;
    }

    public static function periodicidadesSoportadas(): array
    {
        return ['DIARIO','SEMANAL','QUINCENAL','MENSUAL','BIMESTRAL','TRIMESTRAL','CUATRIMESTRAL','SEMESTRAL','ANUAL'];
    }

    public static function buildId(string $orden, string $tarea): string
    {
        return $orden . '|' . $tarea;
    }

    public static function loadAll(): array
    {
        if (self::usePg()) {
            $rows = Db::pgFetchAll("SELECT * FROM mant_periodicidad_overrides ORDER BY id");
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
        if (empty($rec['orden']) || empty($rec['tarea']) || empty($rec['periodicidad'])) {
            throw new InvalidArgumentException('Faltan campos obligatorios');
        }
        $per = strtoupper(trim((string)$rec['periodicidad']));
        if (!in_array($per, self::periodicidadesSoportadas(), true)) {
            throw new InvalidArgumentException("Periodicidad no soportada: $per");
        }
        $id   = self::buildId((string)$rec['orden'], (string)$rec['tarea']);
        $now  = time();
        $item = [
            'id'           => $id,
            'orden'        => (string)$rec['orden'],
            'tarea'        => (string)$rec['tarea'],
            'periodicidad' => $per,
            'set_at'       => $now,
            'set_por'      => (string)($rec['set_por'] ?? ''),
            'nota'         => trim((string)($rec['nota'] ?? '')),
        ];

        if (self::usePg()) {
            Db::pgExec("
                INSERT INTO mant_periodicidad_overrides (orden, tarea, periodicidad, set_at, set_por, nota)
                VALUES (:orden, :tarea, :periodicidad, to_timestamp(:set_at), :set_por, :nota)
                ON CONFLICT (orden, tarea) DO UPDATE SET
                    periodicidad = EXCLUDED.periodicidad,
                    set_at       = EXCLUDED.set_at,
                    set_por      = EXCLUDED.set_por,
                    nota         = EXCLUDED.nota
            ", [
                ':orden'        => $item['orden'],
                ':tarea'        => $item['tarea'],
                ':periodicidad' => $item['periodicidad'],
                ':set_at'       => $item['set_at'],
                ':set_por'      => $item['set_por'] ?: null,
                ':nota'         => $item['nota']    ?: null,
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
            [$orden, $tarea] = explode('|', $id, 2) + [null, null];
            if (!$orden || !$tarea) return null;
            $row = Db::pgFetchOne(
                "SELECT * FROM mant_periodicidad_overrides WHERE orden = ? AND tarea = ?",
                [$orden, $tarea]
            );
            if (!$row) return null;
            Db::pgExec(
                "DELETE FROM mant_periodicidad_overrides WHERE orden = ? AND tarea = ?",
                [$orden, $tarea]
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

    /** Aplica override sobre una fila de plan; recalcula proxima_revision. */
    public static function applyOverride(array $row, ?array $override): array
    {
        if ($override === null) {
            $row['periodicidad_original'] = $row['periodicidad'] ?? '';
            $row['has_override'] = false;
            return $row;
        }
        $row['periodicidad_original'] = $row['periodicidad'] ?? '';
        $row['has_override']          = true;
        $row['periodicidad']          = $override['periodicidad'];
        $dias   = self::diasPorPeriodicidad($override['periodicidad']);
        $ultima = $row['ultima_revision'] ?? null;
        if ($dias !== null && $ultima) {
            $ts = strtotime($ultima);
            if ($ts !== false) {
                $row['proxima_revision']  = date('Y-m-d', $ts + $dias * 86400);
                $row['proxima_recalculada'] = true;
            }
        }
        return $row;
    }

    private static function pgRowToArr(array $row): array
    {
        return [
            'id'           => (string)$row['orden'] . '|' . (string)$row['tarea'],
            'orden'        => (string)$row['orden'],
            'tarea'        => (string)$row['tarea'],
            'periodicidad' => (string)$row['periodicidad'],
            'set_at'       => isset($row['set_at']) ? strtotime((string)$row['set_at']) : 0,
            'set_por'      => (string)($row['set_por'] ?? ''),
            'nota'         => (string)($row['nota']    ?? ''),
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
        try { flock($fp, LOCK_EX); $fn(); }
        finally { flock($fp, LOCK_UN); fclose($fp); }
    }
}
