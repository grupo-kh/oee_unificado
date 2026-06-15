<?php
/**
 * Calendario laboral · Comunidad Valenciana.
 *
 * Determina si una fecha (YYYY-MM-DD) es día hábil (lunes a viernes y
 * no festivo) o ajusta a un día hábil cercano. Pensado para evitar que
 * los seed / import / backfill imputen intervenciones en sábados,
 * domingos o festivos.
 *
 * Los festivos están hardcoded para 2024-2027. Cuando llegue 2028 hay
 * que ampliar la lista.
 */
class CalendarioLaboral
{
    /**
     * Festivos en la Comunidad Valenciana (nacionales + autonómicos).
     * Si en un año concreto el festivo cae en domingo y se traslada al
     * lunes siguiente, se incluyen ambos por seguridad (el lunes va
     * implícito porque será dia 1 del trasladado).
     *
     * Lista revisable. Si descubres que falta uno, añádelo y vuelve a
     * correr mant_fix_dias_no_habiles.php.
     */
    private static array $festivos = [
        // ─── 2024 ───
        '2024-01-01', '2024-01-06',
        '2024-03-19',
        '2024-03-28', '2024-03-29', '2024-04-01',
        '2024-05-01',
        '2024-06-24',
        '2024-08-15',
        '2024-10-09', '2024-10-12',
        '2024-11-01',
        '2024-12-06', '2024-12-08', '2024-12-25',

        // ─── 2025 ───
        '2025-01-01', '2025-01-06',
        '2025-03-19',
        '2025-04-17', '2025-04-18', '2025-04-21',
        '2025-05-01',
        '2025-06-24',
        '2025-08-15',
        '2025-10-09', '2025-10-13',   // 12 oct es domingo, se traslada al lunes 13
        '2025-11-01',
        '2025-12-06', '2025-12-08', '2025-12-25',

        // ─── 2026 ───
        '2026-01-01', '2026-01-06',
        '2026-03-19',
        '2026-04-02', '2026-04-03', '2026-04-06',
        '2026-05-01',
        '2026-06-24',
        '2026-08-15',
        '2026-10-09', '2026-10-12',
        '2026-11-02',                  // 1 nov 2026 es domingo, trasladado al lunes 2
        '2026-12-07',                  // 6 dic 2026 es domingo, trasladado al lunes 7
        '2026-12-08', '2026-12-25',

        // ─── 2027 ───
        '2027-01-01', '2027-01-06',
        '2027-03-19',
        '2027-03-25', '2027-03-26', '2027-03-29',
        '2027-05-01',                  // sábado, podría no trasladarse
        '2027-06-24',
        '2027-10-08',                  // 9 oct sábado, trasladado al viernes 8
        '2027-10-12',
        '2027-11-01',
        '2027-12-06', '2027-12-08', '2027-12-25',
    ];

    private static ?array $festivosIdx = null;

    /**
     * Excepciones BD cargadas perezosamente:
     *   ['YYYY-MM-DD' => 'NO_LABORABLE' | 'LABORABLE_EXTRA']
     *
     * Una vez cargadas, cualquier modificación desde la UI debe invocar
     * resetExcepcionesCache() para forzar la recarga en peticiones futuras.
     */
    private static ?array $excepciones = null;

    private static function idx(): array
    {
        if (self::$festivosIdx === null) {
            self::$festivosIdx = array_fill_keys(self::$festivos, true);
        }
        return self::$festivosIdx;
    }

    /**
     * Carga las excepciones desde mant_calendario_excepciones (si la tabla
     * existe y PostgreSQL está activo). Es resiliente: si la migración 015
     * todavía no se ha aplicado, devuelve array vacío sin lanzar.
     */
    private static function excepciones(): array
    {
        if (self::$excepciones !== null) return self::$excepciones;
        self::$excepciones = [];
        if (!defined('MANT_USE_PG') || MANT_USE_PG !== true) return self::$excepciones;
        try {
            // Db está disponible si MANT_USE_PG=true. Cargamos require_once por seguridad.
            if (!class_exists('Db')) require_once __DIR__ . '/Db.php';
            $rows = Db::pgFetchAll(
                "SELECT to_char(fecha, 'YYYY-MM-DD') AS f, tipo FROM mant_calendario_excepciones"
            );
            foreach ($rows as $r) {
                self::$excepciones[(string)$r['f']] = (string)$r['tipo'];
            }
        } catch (Throwable $e) {
            // tabla no existente o BD caída → sin excepciones, seguimos con reglas básicas
            self::$excepciones = [];
        }
        return self::$excepciones;
    }

    /**
     * Limpia la caché de excepciones. Debe llamarse desde el store cuando
     * se crea/edita/borra una excepción para que las peticiones siguientes
     * vean el cambio sin esperar a un reinicio de PHP.
     */
    public static function resetExcepcionesCache(): void
    {
        self::$excepciones = null;
    }

    /**
     * true si $iso (YYYY-MM-DD) es día hábil para mantenimiento.
     *
     * Lógica (las excepciones BD mandan sobre las reglas por defecto):
     *   1. Si BD dice LABORABLE_EXTRA → es hábil (incluso si es sábado/festivo CV).
     *   2. Si BD dice NO_LABORABLE     → no es hábil (incluso si es L-V normal).
     *   3. En otro caso, regla por defecto: L-V + no en festivos CV hardcoded.
     */
    public static function esDiaHabil(string $iso): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return false;
        $excIdx = self::excepciones();
        if (isset($excIdx[$iso])) {
            return $excIdx[$iso] === 'LABORABLE_EXTRA';
        }
        $ts = strtotime($iso);
        if ($ts === false) return false;
        $dow = (int) date('N', $ts);  // 1=lun … 7=dom
        if ($dow >= 6) return false;
        return !isset(self::idx()[$iso]);
    }

    /**
     * Devuelve el día hábil "más cercano" a $iso. Dirección:
     *   'anterior'  → busca hacia atrás.
     *   'posterior' → busca hacia delante.
     *   'cercano'   → primero ±1, ±2, ±3… (lo que esté más cerca).
     *
     * Si $iso ya es hábil, lo devuelve tal cual.
     */
    public static function ajustarADiaHabil(string $iso, string $dir = 'anterior'): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return $iso;
        if (self::esDiaHabil($iso)) return $iso;

        if ($dir === 'cercano') {
            for ($d = 1; $d <= 30; $d++) {
                foreach ([-$d, +$d] as $off) {
                    $cand = date('Y-m-d', strtotime($iso . ' ' . sprintf('%+d', $off) . ' days'));
                    if (self::esDiaHabil($cand)) return $cand;
                }
            }
            return $iso;
        }

        $step = ($dir === 'posterior') ? '+1 day' : '-1 day';
        $cursor = $iso;
        for ($i = 0; $i < 30; $i++) {
            $cursor = date('Y-m-d', strtotime($cursor . ' ' . $step));
            if (self::esDiaHabil($cursor)) return $cursor;
        }
        return $iso;
    }

    /**
     * Año-semana ISO ("YYYY-WW") usado para detectar duplicados en
     * periodicidades semanales.
     */
    public static function semanaIso(string $iso): string
    {
        $ts = strtotime($iso);
        if ($ts === false) return '';
        return date('o-W', $ts);
    }
}
