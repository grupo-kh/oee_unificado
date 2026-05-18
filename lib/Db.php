<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Acceso a la base de datos PostgreSQL del módulo de mantenimiento.
 *
 * Devuelve un PDO singleton. Las credenciales se cargan desde
 * config/database.php. La conexión se reutiliza durante toda la request.
 */
class Db
{
    private static ?PDO $pgPdo = null;

    /** Conexión PDO a PostgreSQL (singleton por request). */
    public static function pg(): PDO
    {
        if (self::$pgPdo !== null) return self::$pgPdo;

        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException(
                "Extensión PHP 'pdo_pgsql' no instalada. " .
                "Edita C:\\xampp\\php\\php.ini y descomenta 'extension=pdo_pgsql' (y opcionalmente 'extension=pgsql'). Reinicia Apache."
            );
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;options=--client_encoding=UTF8',
            DB_PG_HOST, DB_PG_PORT, DB_PG_NAME
        );
        try {
            $pdo = new PDO($dsn, DB_PG_USER, DB_PG_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // DB_PG_SCHEMA proviene de .env; permitimos solo identificadores
            // PostgreSQL válidos antes de inyectarlo en SET search_path. Si
            // alguien manipula la config, el SET falla en lugar de ejecutar
            // SQL arbitrario.
            $schema = (string) DB_PG_SCHEMA;
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $schema)) {
                throw new RuntimeException("DB_PG_SCHEMA inválido: '$schema'");
            }
            $pdo->exec('SET search_path TO "' . $schema . '"');
            $pdo->exec("SET TIME ZONE 'Europe/Madrid'");
            self::$pgPdo = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            // El mensaje completo (con host/puerto/dbname) sólo en debug;
            // en producción damos un mensaje genérico y el detalle al log.
            error_log('Db::pg connect failed at '
                . DB_PG_HOST . ':' . DB_PG_PORT . '/' . DB_PG_NAME . ': ' . $e->getMessage());
            $debug = (bool) env('APP_DEBUG', false);
            $msg = $debug
                ? 'Error conectando a PostgreSQL (' . DB_PG_HOST . ':' . DB_PG_PORT . '/' . DB_PG_NAME . '): ' . $e->getMessage()
                : 'No se pudo conectar a la base de datos de mantenimiento';
            throw new RuntimeException($msg);
        }
    }

    /** Cierra la conexión (útil en scripts CLI largos). */
    public static function close(): void { self::$pgPdo = null; }

    /**
     * Helper: ejecuta una consulta y devuelve todas las filas.
     * @param  string $sql    Consulta SQL.
     * @param  array  $params Parámetros (posicionales o nombrados).
     * @return array<int, array<string, mixed>>
     */
    public static function pgFetchAll(string $sql, array $params = []): array
    {
        $st = self::pg()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Helper: una sola fila o null. */
    public static function pgFetchOne(string $sql, array $params = []): ?array
    {
        $st = self::pg()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** Helper: ejecuta INSERT/UPDATE/DELETE y devuelve filas afectadas. */
    public static function pgExec(string $sql, array $params = []): int
    {
        $st = self::pg()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }
}
