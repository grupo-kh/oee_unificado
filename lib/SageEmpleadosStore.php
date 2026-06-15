<?php
/**
 * Mantenimiento de empleados en Sage Logicclass.
 *
 * Sage tiene su propia tabla de personal — sirve para identificar al
 * operario que entra en la app de planta (tablet de OFs). Estado:
 *
 *   - Probamos contra un set de tablas/columnas habituales en Logicclass
 *     (PERSONAL, RH_PERSONA, Sistema_PERSONAL...) hasta dar con una que
 *     responda. La primera que encuentre se queda CACHEADA para la
 *     petición.
 *   - Si tu instalación usa otra tabla/columna distinta a las probadas,
 *     basta con añadirla a la constante CANDIDATAS — el resto del flujo
 *     sigue funcionando.
 *
 * Solo se accede en lectura. Devuelve si un código de empleado existe
 * y está dado de alta, y opcionalmente su nombre legible.
 */
class SageEmpleadosStore
{
    /**
     * Lista de queries candidatas. La primera que devuelva resultados
     * será la que use el sistema. Cada item:
     *   - sql_exists  : SQL con :n que devuelve 1 fila si el código existe y está activo.
     *   - sql_label   : SQL con :n que devuelve {numero, nombre}. Opcional.
     *   - label       : nombre legible para diagnóstico.
     *
     * Si en tu Logicclass la tabla se llama distinto o el campo "activo"
     * usa otra convención, añade aquí la consulta que toque.
     */
    private const CANDIDATAS = [
        // 1) Patrón clásico Logicclass: tabla PERSONAL con CodigoEmpleado y FechaBaja
        [
            'label'      => 'PERSONAL · CodigoEmpleado',
            'sql_exists' => "SELECT TOP 1 1 FROM PERSONAL
                              WHERE CodigoEmpleado = :n
                                AND (FechaBaja IS NULL OR FechaBaja > GETDATE())",
            'sql_label'  => "SELECT TOP 1 CodigoEmpleado AS numero,
                                    LTRIM(RTRIM(COALESCE(NombreCompleto,
                                          ISNULL(Apellidos,'') + ' ' + ISNULL(Nombre,''),
                                          CodigoEmpleado))) AS nombre
                               FROM PERSONAL WHERE CodigoEmpleado = :n",
        ],
        // 2) Tabla EMPLEADOS estándar
        [
            'label'      => 'EMPLEADOS · CodigoEmpleado',
            'sql_exists' => "SELECT TOP 1 1 FROM EMPLEADOS
                              WHERE CodigoEmpleado = :n
                                AND (FechaBaja IS NULL OR FechaBaja > GETDATE())",
            'sql_label'  => "SELECT TOP 1 CodigoEmpleado AS numero,
                                    LTRIM(RTRIM(ISNULL(Apellidos,'') + ' ' + ISNULL(Nombre,''))) AS nombre
                               FROM EMPLEADOS WHERE CodigoEmpleado = :n",
        ],
        // 3) Tabla RH_PERSONA (módulo RRHH)
        [
            'label'      => 'RH_PERSONA · CodigoPersona',
            'sql_exists' => "SELECT TOP 1 1 FROM RH_PERSONA
                              WHERE CodigoPersona = :n
                                AND (FechaBaja IS NULL OR FechaBaja > GETDATE())",
            'sql_label'  => "SELECT TOP 1 CodigoPersona AS numero,
                                    LTRIM(RTRIM(ISNULL(Apellidos,'') + ' ' + ISNULL(Nombre,''))) AS nombre
                               FROM RH_PERSONA WHERE CodigoPersona = :n",
        ],
        // 4) Tabla Sistema_PERSONAL (variante)
        [
            'label'      => 'Sistema_PERSONAL · Codigo',
            'sql_exists' => "SELECT TOP 1 1 FROM Sistema_PERSONAL
                              WHERE Codigo = :n
                                AND (Activo = 1 OR Activo IS NULL)",
            'sql_label'  => "SELECT TOP 1 Codigo AS numero, Nombre AS nombre
                               FROM Sistema_PERSONAL WHERE Codigo = :n",
        ],
    ];

    /** Cache del candidato que funcionó para esta petición. */
    private static ?int $candIdx = null;

    /**
     * ¿Existe el empleado y está de alta en Sage?
     */
    public static function existe(string $numero): bool
    {
        $numero = trim($numero);
        if ($numero === '') return false;
        $idx = self::pickCandidato();
        if ($idx === null) return false;
        try {
            $r = fetchAll('sage', self::CANDIDATAS[$idx]['sql_exists'], [':n' => $numero]);
            return !empty($r);
        } catch (Throwable $e) {
            error_log('[SageEmpleadosStore::existe] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Devuelve el nombre legible si la tabla tiene una columna razonable.
     * Si no, devuelve el propio número.
     */
    public static function nombre(string $numero): string
    {
        $numero = trim($numero);
        if ($numero === '') return '';
        $idx = self::pickCandidato();
        if ($idx === null) return $numero;
        $cand = self::CANDIDATAS[$idx];
        if (empty($cand['sql_label'])) return $numero;
        try {
            $r = fetchAll('sage', $cand['sql_label'], [':n' => $numero]);
            if (empty($r)) return $numero;
            $nom = trim((string)($r[0]['nombre'] ?? ''));
            return $nom !== '' ? $nom : $numero;
        } catch (Throwable $e) {
            return $numero;
        }
    }

    /**
     * Identifica qué tabla candidata responde en esta BD. Resultado cacheado
     * por petición (lo recalcula cuando alguien llama y se ha invalidado).
     */
    private static function pickCandidato(): ?int
    {
        if (self::$candIdx !== null) return self::$candIdx >= 0 ? self::$candIdx : null;
        foreach (self::CANDIDATAS as $i => $cand) {
            try {
                // Probamos con un número genérico (NULL falla; usamos '0' que
                // raramente existe pero la query tiene que parsearse OK).
                fetchAll('sage', str_replace(':n', "'__chk__'", $cand['sql_exists']));
                self::$candIdx = $i;
                return $i;
            } catch (Throwable $e) {
                // tabla no existe → probamos la siguiente
                continue;
            }
        }
        self::$candIdx = -1;
        return null;
    }

    /**
     * Devuelve cuál de los candidatos respondió (útil para diagnóstico).
     */
    public static function candidatoUsado(): ?string
    {
        $i = self::pickCandidato();
        return $i === null ? null : self::CANDIDATAS[$i]['label'];
    }
}
