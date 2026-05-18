<?php
/**
 * Loader minimalista de variables de entorno desde fichero .env.
 *
 * Diseñado para no añadir dependencias (sin vlucas/phpdotenv). Soporta:
 *   - Líneas en blanco y comentarios (#).
 *   - KEY=VALUE.
 *   - Valor entre comillas simples (literal) o dobles (con \n, \t, \", \\).
 *   - Comentarios al final de línea sólo en valores SIN comillas.
 *
 * No soporta interpolación ${VAR} ni multiline — si en el futuro hace falta,
 * se puede sustituir por phpdotenv sin cambiar la API pública.
 *
 * Uso:
 *   require_once __DIR__ . '/EnvLoader.php';
 *   EnvLoader::loadOnce(__DIR__ . '/../.env');
 *   $host = env('DB_MAPEX_HOST', 'localhost');
 */
final class EnvLoader
{
    private static bool $loaded = false;

    /**
     * Carga el .env una sola vez. Si el fichero no existe, no lanza error
     * (permite operar con variables ya inyectadas en el entorno por el
     * servidor — útil en producción gestionada con Apache SetEnv, Docker,
     * systemd, etc.).
     */
    public static function loadOnce(string $path): void
    {
        if (self::$loaded) return;
        self::$loaded = true;

        if (!is_file($path) || !is_readable($path)) return;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;

            $key = trim(substr($line, 0, $eq));
            $val = self::parseValue(substr($line, $eq + 1));
            if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) continue;

            // No pisar variables ya definidas por el entorno real.
            if (getenv($key) === false && !isset($_ENV[$key]) && !isset($_SERVER[$key])) {
                putenv("$key=$val");
                $_ENV[$key]    = $val;
                $_SERVER[$key] = $val;
            }
        }
    }

    private static function parseValue(string $raw): string
    {
        $raw = ltrim($raw);
        if ($raw === '') return '';

        $first = $raw[0];
        if ($first === '"' || $first === "'") {
            // Buscar cierre de comilla coincidente (no escapado para "..").
            $end = -1;
            for ($i = 1, $n = strlen($raw); $i < $n; $i++) {
                if ($raw[$i] === '\\' && $first === '"' && $i + 1 < $n) { $i++; continue; }
                if ($raw[$i] === $first) { $end = $i; break; }
            }
            if ($end === -1) return $raw; // sin cierre: devolvemos crudo
            $inner = substr($raw, 1, $end - 1);
            if ($first === '"') {
                $inner = strtr($inner, [
                    '\\n'  => "\n",
                    '\\t'  => "\t",
                    '\\r'  => "\r",
                    '\\"'  => '"',
                    '\\\\' => '\\',
                ]);
            }
            return $inner;
        }

        // Sin comillas: cortar comentario inline (# precedido de espacio o BOL).
        $hash = strpos($raw, ' #');
        if ($hash !== false) $raw = substr($raw, 0, $hash);
        return rtrim($raw);
    }
}

if (!function_exists('env')) {
    /**
     * Devuelve el valor de una variable de entorno con conversión de tipos
     * básica para "true/false/null". Si no existe, devuelve $default.
     */
    function env(string $key, $default = null)
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v === false || $v === null || $v === '') return $default;
        switch (strtolower((string)$v)) {
            case 'true':  case '(true)':  return true;
            case 'false': case '(false)': return false;
            case 'null':  case '(null)':  return null;
            case 'empty': case '(empty)': return '';
        }
        return $v;
    }
}
