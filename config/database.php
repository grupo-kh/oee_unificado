<?php
/**
 * Configuración de conexión a las bases de datos.
 *
 * Las credenciales se leen del fichero `.env` (raíz del proyecto) o del
 * entorno real del servidor (Apache SetEnv, Docker, systemd, etc.). Este
 * fichero NO debe contener credenciales en claro.
 *
 * Para entornos nuevos: copia .env.example → .env y completa los valores.
 */

require_once __DIR__ . '/../lib/EnvLoader.php';
EnvLoader::loadOnce(__DIR__ . '/../.env');

// ───── Display de errores en función de APP_DEBUG ─────
// En producción no queremos exponer trazas/credenciales en pantalla; los
// errores siguen yéndose al log de PHP (error_log).
$_appDebug = (bool) env('APP_DEBUG', false);
ini_set('display_errors', $_appDebug ? '1' : '0');
ini_set('display_startup_errors', $_appDebug ? '1' : '0');
error_reporting(E_ALL);

// ───── Constantes de conexión (compat con código existente) ─────
// Mantenemos las constantes públicas para no romper nada que ya las use
// (lib/, scripts/, tools/ y los APIs que pudieran referenciarlas).
// IMPORTANTE: estos valores por defecto deben quedar VACÍOS en el repositorio.
// La configuración real (host, base de datos, usuario y contraseña) se inyecta
// exclusivamente vía `.env` (no versionado). Ver `.env.example`.
define('DB_MAPEX_HOST', env('DB_MAPEX_HOST', ''));
define('DB_MAPEX_NAME', env('DB_MAPEX_NAME', ''));
define('DB_MAPEX_USER', env('DB_MAPEX_USER', ''));
define('DB_MAPEX_PASS', env('DB_MAPEX_PASS', ''));

define('DB_SAGE_HOST', env('DB_SAGE_HOST', ''));
define('DB_SAGE_NAME', env('DB_SAGE_NAME', ''));
define('DB_SAGE_USER', env('DB_SAGE_USER', ''));
define('DB_SAGE_PASS', env('DB_SAGE_PASS', ''));

// Logicclass (SQL Server, server2) — productividad nominal Oper_Formula.UnidadesHora.
// Tercera conexión del QlikView original (User ID=khapps, BD Logicclass).
define('DB_LOGIC_HOST', env('DB_LOGIC_HOST', ''));
define('DB_LOGIC_NAME', env('DB_LOGIC_NAME', ''));
define('DB_LOGIC_USER', env('DB_LOGIC_USER', ''));
define('DB_LOGIC_PASS', env('DB_LOGIC_PASS', ''));

define('DB_PG_HOST',   env('DB_PG_HOST',   '127.0.0.1'));
define('DB_PG_PORT',   env('DB_PG_PORT',   '5432'));
define('DB_PG_NAME',   env('DB_PG_NAME',   'plan_attainment'));
define('DB_PG_USER',   env('DB_PG_USER',   ''));
define('DB_PG_PASS',   env('DB_PG_PASS',   ''));
define('DB_PG_SCHEMA', env('DB_PG_SCHEMA', 'public'));

if (!defined('MANT_USE_PG')) {
    define('MANT_USE_PG', (bool) env('MANT_USE_PG', true));
}

if (!defined('MANT_XLSX_PATH')) {
    // Ruta al Excel de mantenimiento. Se define en `.env` (MANT_XLSX_PATH).
    define('MANT_XLSX_PATH', (string) env('MANT_XLSX_PATH', ''));
}

function getConnection($bd = 'mapex') {
    try {
        switch (strtolower($bd)) {
            case 'mapex':
                $host = DB_MAPEX_HOST; $name = DB_MAPEX_NAME;
                $user = DB_MAPEX_USER; $pass = DB_MAPEX_PASS;
                break;
            case 'sage':
                $host = DB_SAGE_HOST; $name = DB_SAGE_NAME;
                $user = DB_SAGE_USER; $pass = DB_SAGE_PASS;
                break;
            case 'logicclass':
                $host = DB_LOGIC_HOST; $name = DB_LOGIC_NAME;
                $user = DB_LOGIC_USER; $pass = DB_LOGIC_PASS;
                break;
            default:
                throw new Exception("Base de datos desconocida: $bd");
        }

        $dsn = "sqlsrv:Server=$host;Database=$name;Encrypt=no;TrustServerCertificate=yes";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET DATEFORMAT ymd;");
        return $pdo;
    } catch (PDOException $e) {
        // No exponemos el mensaje original en producción (puede contener
        // datos de conexión); va al log y al cliente damos un mensaje genérico.
        error_log("DB connect [$bd] failed: " . $e->getMessage());
        $msg = (defined('APP_ENV_DEBUG') && APP_ENV_DEBUG)
            ? $e->getMessage()
            : 'No se pudo conectar a la base de datos';
        throw new Exception("Error conectando a $bd: " . $msg);
    }
}

function fetchAll($bd, $sql, $params = []) {
    $sqlTrim = ltrim($sql);
    if (stripos($sqlTrim, 'SELECT') !== 0 && stripos($sqlTrim, 'WITH') !== 0) {
        throw new Exception("Solo se permiten consultas SELECT");
    }
    $pdo  = getConnection($bd);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
