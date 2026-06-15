<?php
require_once __DIR__ . '/Db.php';

/**
 * Gestión de operarios: CRUD para datos administrativos del catálogo
 * `mant_operarios` (apellidos, fechas, puesto) y de la tabla pivote
 * `mant_operario_capacitacion`.
 *
 * Reglas de negocio:
 *  - El campo `activo` se DERIVA del campo `fecha_baja`: activo = (fecha_baja IS NULL).
 *    En el save() se sincroniza siempre, no debe escribirse a mano.
 *  - Las capacitaciones P25/P50/P75/P100 son ACUMULATIVAS: si el técnico marca
 *    P75 al guardar añadimos también P25 y P50. TALLER es independiente.
 *  - Solo rol "tecnico" usa esta tabla — la validación de auth se hace en el
 *    endpoint que invoca el store.
 *
 * Catálogos:
 *  - PUESTOS: 5 valores admitidos (CHECK constraint en BD).
 *  - CAPACITACIONES: P25 P50 P75 P100 (acumulativas) + TALLER (independiente).
 */
class MaintenanceOperariosStore
{
    public const PUESTOS = [
        'responsable'             => 'Responsable',
        'tecnico_mantenimiento'   => 'Técnico mantenimiento',
        'tecnico_taller'          => 'Técnico taller',
        'operario_mantenimiento'  => 'Operario mantenimiento',
        'operario_taller'         => 'Operario taller',
    ];

    /** Niveles porcentuales en orden ascendente (acumulativos). */
    public const NIVELES = ['P25', 'P50', 'P75', 'P100'];

    /** Labels legibles para la UI. La clave 'TALLER' se mantiene como
     *  identificador interno (constraint en BD), pero la etiqueta visible es
     *  "Racks" porque esa capacitación habilita las tareas de los racks. */
    public const CAPACITACION_LABELS = [
        'P25'    => '25 %',
        'P50'    => '50 %',
        'P75'    => '75 %',
        'P100'   => '100 %',
        'TALLER' => 'Racks',
    ];

    /** ¿Estamos en modo PostgreSQL? Replica el patrón de los otros stores. */
    private static function usePg(): bool
    {
        return defined('MANT_USE_PG') && MANT_USE_PG === true;
    }

    /**
     * Lista todos los operarios con sus capacitaciones. Opcionalmente filtra
     * a solo activos.
     *
     * @return array<int, array{
     *     numero:string, nombre:string, apellidos:string, fecha_alta:?string,
     *     fecha_baja:?string, puesto:?string, activo:bool, capacitaciones:array<int,string>
     * }>
     */
    public static function listAll(bool $soloActivos = false): array
    {
        if (!self::usePg()) return [];

        $where = $soloActivos ? 'WHERE fecha_baja IS NULL' : '';
        // Orden: primero los ACTIVOS (fecha_baja IS NULL), luego los de BAJA
        // — dentro de cada bloque alfabéticamente por apellidos + nombre.
        $rows = Db::pgFetchAll("
            SELECT numero, nombre, apellidos, puesto,
                   to_char(fecha_alta, 'YYYY-MM-DD') AS fecha_alta,
                   to_char(fecha_baja, 'YYYY-MM-DD') AS fecha_baja,
                   activo
              FROM mant_operarios
            $where
            ORDER BY (CASE WHEN fecha_baja IS NULL THEN 0 ELSE 1 END),
                     apellidos NULLS LAST, nombre NULLS LAST, numero
        ");

        // Capacitaciones de TODOS los operarios en un solo query
        $capRows = Db::pgFetchAll(
            "SELECT numero, capacitacion FROM mant_operario_capacitacion"
        );
        $capsByOp = [];
        foreach ($capRows as $cr) {
            $capsByOp[$cr['numero']][] = $cr['capacitacion'];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'numero'         => (string)$r['numero'],
                'nombre'         => (string)($r['nombre'] ?? ''),
                'apellidos'      => (string)($r['apellidos'] ?? ''),
                'fecha_alta'     => $r['fecha_alta'] ?: null,
                'fecha_baja'     => $r['fecha_baja'] ?: null,
                'puesto'         => $r['puesto'] ?: null,
                'activo'         => (bool)$r['activo'],
                'capacitaciones' => $capsByOp[(string)$r['numero']] ?? [],
            ];
        }
        return $out;
    }

    /** Devuelve UN operario por número (o null si no existe). */
    public static function get(string $numero): ?array
    {
        if (!self::usePg()) return null;
        $row = Db::pgFetchOne("
            SELECT numero, nombre, apellidos, puesto,
                   to_char(fecha_alta, 'YYYY-MM-DD') AS fecha_alta,
                   to_char(fecha_baja, 'YYYY-MM-DD') AS fecha_baja,
                   activo
              FROM mant_operarios
             WHERE numero = :n
        ", [':n' => $numero]);
        if (!$row) return null;

        $caps = Db::pgFetchAll(
            "SELECT capacitacion FROM mant_operario_capacitacion WHERE numero = :n",
            [':n' => $numero]
        );
        return [
            'numero'         => (string)$row['numero'],
            'nombre'         => (string)($row['nombre'] ?? ''),
            'apellidos'      => (string)($row['apellidos'] ?? ''),
            'fecha_alta'     => $row['fecha_alta'] ?: null,
            'fecha_baja'     => $row['fecha_baja'] ?: null,
            'puesto'         => $row['puesto'] ?: null,
            'activo'         => (bool)$row['activo'],
            'capacitaciones' => array_map(fn($r) => $r['capacitacion'], $caps),
        ];
    }

    /**
     * Crea un nuevo operario.
     * @throws InvalidArgumentException si el código ya existe o faltan campos.
     */
    public static function create(array $data): array
    {
        if (!self::usePg()) {
            throw new RuntimeException('Gestión de operarios requiere PostgreSQL');
        }
        $numero = self::_validaNumero($data['numero'] ?? '');
        if (self::get($numero) !== null) {
            throw new InvalidArgumentException("El código '$numero' ya existe");
        }
        $payload = self::_validaYNormaliza($data);

        Db::pg()->beginTransaction();
        try {
            Db::pgExec("
                INSERT INTO mant_operarios
                    (numero, nombre, apellidos, fecha_alta, fecha_baja, puesto, activo)
                VALUES
                    (:n, :nom, :ape, :fa, :fb, :p, :act)
            ", [
                ':n'   => $numero,
                ':nom' => $payload['nombre'],
                ':ape' => $payload['apellidos'],
                ':fa'  => $payload['fecha_alta'],
                ':fb'  => $payload['fecha_baja'],
                ':p'   => $payload['puesto'],
                ':act' => $payload['activo'] ? 'true' : 'false',
            ]);
            self::_saveCapacitaciones($numero, $payload['capacitaciones']);
            Db::pg()->commit();
        } catch (Throwable $e) {
            if (Db::pg()->inTransaction()) Db::pg()->rollBack();
            throw $e;
        }
        return self::get($numero);
    }

    /**
     * Actualiza un operario existente. El número (PK) no se puede cambiar.
     */
    public static function update(string $numero, array $data): array
    {
        if (!self::usePg()) {
            throw new RuntimeException('Gestión de operarios requiere PostgreSQL');
        }
        if (self::get($numero) === null) {
            throw new InvalidArgumentException("El operario '$numero' no existe");
        }
        $payload = self::_validaYNormaliza($data);

        Db::pg()->beginTransaction();
        try {
            Db::pgExec("
                UPDATE mant_operarios SET
                    nombre     = :nom,
                    apellidos  = :ape,
                    fecha_alta = :fa,
                    fecha_baja = :fb,
                    puesto     = :p,
                    activo     = :act
                WHERE numero = :n
            ", [
                ':n'   => $numero,
                ':nom' => $payload['nombre'],
                ':ape' => $payload['apellidos'],
                ':fa'  => $payload['fecha_alta'],
                ':fb'  => $payload['fecha_baja'],
                ':p'   => $payload['puesto'],
                ':act' => $payload['activo'] ? 'true' : 'false',
            ]);
            // Reemplazamos por completo el set de capacitaciones del operario.
            Db::pgExec(
                "DELETE FROM mant_operario_capacitacion WHERE numero = :n",
                [':n' => $numero]
            );
            self::_saveCapacitaciones($numero, $payload['capacitaciones']);
            Db::pg()->commit();
        } catch (Throwable $e) {
            if (Db::pg()->inTransaction()) Db::pg()->rollBack();
            throw $e;
        }
        return self::get($numero);
    }

    /**
     * Borra un operario. ATENCIÓN: si tiene intervenciones registradas
     * (mant_completions) probablemente preferirás "dar de baja" en su lugar
     * — el endpoint decide cuándo permitirlo.
     */
    public static function delete(string $numero): bool
    {
        if (!self::usePg()) return false;
        Db::pgExec("DELETE FROM mant_operarios WHERE numero = :n", [':n' => $numero]);
        return true;
    }

    /** Útil para el frontend: ¿este operario tiene intervenciones? */
    public static function tieneIntervenciones(string $numero): bool
    {
        if (!self::usePg()) return false;
        try {
            $row = Db::pgFetchOne(
                "SELECT 1 FROM mant_completions WHERE operario = :n LIMIT 1",
                [':n' => $numero]
            );
            return $row !== null && $row !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ──────────────────────────────────────────────────────────────────────

    /** Sanea el número: solo dígitos, no vacío, tope 50 chars. */
    private static function _validaNumero($n): string
    {
        $n = trim((string)$n);
        if ($n === '') throw new InvalidArgumentException('Código obligatorio');
        if (mb_strlen($n) > 50) throw new InvalidArgumentException('Código demasiado largo');
        if (!preg_match('/^\d+$/', $n)) {
            throw new InvalidArgumentException('Código debe ser numérico (solo dígitos)');
        }
        return $n;
    }

    /**
     * Valida el resto de campos y aplica las reglas de negocio:
     *  - activo derivado de fecha_baja.
     *  - capacitaciones acumulativas.
     */
    private static function _validaYNormaliza(array $data): array
    {
        $nombre    = trim((string)($data['nombre'] ?? ''));
        $apellidos = trim((string)($data['apellidos'] ?? ''));
        if (mb_strlen($nombre)    > 120) throw new InvalidArgumentException('Nombre demasiado largo');
        if (mb_strlen($apellidos) > 120) throw new InvalidArgumentException('Apellidos demasiado largos');

        $fechaAlta = self::_validaFecha($data['fecha_alta'] ?? null, 'fecha_alta');
        $fechaBaja = self::_validaFecha($data['fecha_baja'] ?? null, 'fecha_baja');
        if ($fechaAlta && $fechaBaja && $fechaBaja < $fechaAlta) {
            throw new InvalidArgumentException('fecha_baja no puede ser anterior a fecha_alta');
        }

        $puesto = $data['puesto'] ?? null;
        if ($puesto !== null && $puesto !== '' && !array_key_exists($puesto, self::PUESTOS)) {
            throw new InvalidArgumentException("Puesto inválido: $puesto");
        }
        $puesto = ($puesto === '' ? null : $puesto);

        // Capacitaciones: array de strings, validamos y acumulamos.
        $rawCaps = $data['capacitaciones'] ?? [];
        if (!is_array($rawCaps)) $rawCaps = [];
        $rawCaps = array_values(array_unique(array_map(
            fn($c) => strtoupper(trim((string)$c)),
            $rawCaps
        )));
        $validas = array_keys(self::CAPACITACION_LABELS);
        foreach ($rawCaps as $c) {
            if (!in_array($c, $validas, true)) {
                throw new InvalidArgumentException("Capacitación inválida: $c");
            }
        }
        $caps = self::_acumulaCapacitaciones($rawCaps);

        return [
            'nombre'         => $nombre,
            'apellidos'      => $apellidos,
            'fecha_alta'     => $fechaAlta,
            'fecha_baja'     => $fechaBaja,
            'puesto'         => $puesto,
            'activo'         => $fechaBaja === null, // ← derivado
            'capacitaciones' => $caps,
        ];
    }

    /**
     * Aplica acumulado: marcar P75 implica P25 y P50. TALLER independiente.
     * @param array<int,string> $caps  niveles marcados literales
     * @return array<int,string>  niveles consolidados (con acumulado)
     */
    private static function _acumulaCapacitaciones(array $caps): array
    {
        $set = array_fill_keys($caps, true);
        $taller = isset($set['TALLER']);
        // Encontrar el nivel máximo de los porcentuales marcados
        $maxIdx = -1;
        foreach (self::NIVELES as $i => $n) {
            if (isset($set[$n])) $maxIdx = max($maxIdx, $i);
        }
        $out = [];
        if ($maxIdx >= 0) {
            // Añadimos TODOS los niveles ≤ máximo
            for ($i = 0; $i <= $maxIdx; $i++) $out[] = self::NIVELES[$i];
        }
        if ($taller) $out[] = 'TALLER';
        return $out;
    }

    private static function _validaFecha($v, string $campo): ?string
    {
        if ($v === null || $v === '') return null;
        $v = (string)$v;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            throw new InvalidArgumentException("$campo inválida (formato YYYY-MM-DD)");
        }
        return $v;
    }

    private static function _saveCapacitaciones(string $numero, array $caps): void
    {
        foreach ($caps as $c) {
            Db::pgExec("
                INSERT INTO mant_operario_capacitacion (numero, capacitacion)
                VALUES (:n, :c)
                ON CONFLICT DO NOTHING
            ", [':n' => $numero, ':c' => $c]);
        }
    }
}
