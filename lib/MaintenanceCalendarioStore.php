<?php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/CalendarioLaboral.php';

/**
 * Gestión de excepciones del calendario laboral y recálculo automático
 * de las próximas revisiones afectadas.
 */
class MaintenanceCalendarioStore
{
    public const TIPOS = ['NO_LABORABLE', 'LABORABLE_EXTRA'];

    private static function usePg(): bool
    {
        return defined('MANT_USE_PG') && MANT_USE_PG === true;
    }

    /**
     * Lista las excepciones BD entre dos fechas (inclusivo).
     * @return array<int, array{fecha:string, tipo:string, motivo:string}>
     */
    public static function listarRango(string $fdesde, string $fhasta): array
    {
        if (!self::usePg()) return [];
        $rows = Db::pgFetchAll("
            SELECT to_char(fecha, 'YYYY-MM-DD') AS fecha,
                   tipo,
                   COALESCE(motivo, '') AS motivo
              FROM mant_calendario_excepciones
             WHERE fecha BETWEEN :fa AND :fb
             ORDER BY fecha
        ", [':fa' => $fdesde, ':fb' => $fhasta]);
        return array_map(fn($r) => [
            'fecha'  => (string)$r['fecha'],
            'tipo'   => (string)$r['tipo'],
            'motivo' => (string)$r['motivo'],
        ], $rows);
    }

    public static function getExcepcion(string $fecha): ?array
    {
        if (!self::usePg()) return null;
        $row = Db::pgFetchOne("
            SELECT to_char(fecha, 'YYYY-MM-DD') AS fecha,
                   tipo, COALESCE(motivo, '') AS motivo
              FROM mant_calendario_excepciones
             WHERE fecha = :f
        ", [':f' => $fecha]);
        if (!$row) return null;
        return [
            'fecha'  => (string)$row['fecha'],
            'tipo'   => (string)$row['tipo'],
            'motivo' => (string)$row['motivo'],
        ];
    }

    /**
     * Inserta o actualiza una excepción (upsert por fecha). Después invalida
     * la caché del CalendarioLaboral para que las siguientes llamadas a
     * esDiaHabil() vean el cambio.
     *
     * @return array información sobre el cambio.
     */
    public static function setExcepcion(string $fecha, string $tipo, string $motivo, string $usuario = ''): array
    {
        if (!self::usePg()) throw new RuntimeException('Calendario requiere PostgreSQL');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new InvalidArgumentException('Fecha inválida (YYYY-MM-DD)');
        }
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new InvalidArgumentException('Tipo inválido: ' . $tipo);
        }
        Db::pgExec("
            INSERT INTO mant_calendario_excepciones (fecha, tipo, motivo, created_by)
            VALUES (:f, :t, :m, :u)
            ON CONFLICT (fecha) DO UPDATE
                SET tipo = EXCLUDED.tipo,
                    motivo = EXCLUDED.motivo,
                    updated_at = now()
        ", [':f' => $fecha, ':t' => $tipo, ':m' => $motivo, ':u' => $usuario ?: null]);

        CalendarioLaboral::resetExcepcionesCache();

        return ['fecha' => $fecha, 'tipo' => $tipo, 'motivo' => $motivo];
    }

    public static function deleteExcepcion(string $fecha): bool
    {
        if (!self::usePg()) return false;
        Db::pgExec("DELETE FROM mant_calendario_excepciones WHERE fecha = :f", [':f' => $fecha]);
        CalendarioLaboral::resetExcepcionesCache();
        return true;
    }

    /**
     * Recalcula las próximas revisiones afectadas por una excepción.
     *
     * Reglas:
     *   - SOLO se mueven tareas con `proxima_revision >= hoy` Y `activa='A'`
     *     Y `alta_baja='ALTA'` Y `fecha_pausado IS NULL`.
     *   - Si la excepción es NO_LABORABLE → todas las tareas planificadas en
     *     esa fecha pasan al día hábil siguiente.
     *   - Si la excepción es LABORABLE_EXTRA → no se mueve nada (el día se
     *     vuelve disponible pero las tareas ya planificadas se respetan).
     *   - El histórico (mant_completions) NO se toca.
     *
     * @return array{movidas:int, examinadas:int, detalle:array}
     */
    public static function recalcularProximas(string $fechaCambiada, string $tipo): array
    {
        if (!self::usePg()) return ['movidas' => 0, 'examinadas' => 0, 'detalle' => []];
        if ($tipo !== 'NO_LABORABLE') {
            // Habilitar un sábado no requiere mover nada. Damos por OK.
            return ['movidas' => 0, 'examinadas' => 0, 'detalle' => []];
        }

        $hoy = date('Y-m-d');
        // Solo si la fecha es >= hoy tiene sentido mover proxima_revision
        if ($fechaCambiada < $hoy) {
            return ['movidas' => 0, 'examinadas' => 0, 'detalle' => []];
        }

        // Buscar próximas planificadas en esa fecha
        $rows = Db::pgFetchAll("
            SELECT id, cod_maquina_mant, desc_maquina, orden, tarea, periodicidad,
                   to_char(proxima_revision, 'YYYY-MM-DD') AS proxima
              FROM mant_plan
             WHERE proxima_revision = :f
               AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
               AND COALESCE(activa,    'A')    = 'A'
               AND fecha_pausado IS NULL
        ", [':f' => $fechaCambiada]);

        $movidas = 0;
        $detalle = [];
        foreach ($rows as $r) {
            $nueva = CalendarioLaboral::ajustarADiaHabil(
                (string)$r['proxima'], 'posterior'
            );
            if ($nueva === (string)$r['proxima']) continue; // no debería pero por seguridad
            Db::pgExec(
                "UPDATE mant_plan SET proxima_revision = :p WHERE id = :id",
                [':p' => $nueva, ':id' => $r['id']]
            );
            $movidas++;
            $detalle[] = [
                'desc_maquina' => (string)$r['desc_maquina'],
                'tarea'        => (string)$r['tarea'],
                'desde'        => (string)$r['proxima'],
                'hasta'        => $nueva,
            ];
        }
        return [
            'movidas'    => $movidas,
            'examinadas' => count($rows),
            'detalle'    => $detalle,
        ];
    }
}
