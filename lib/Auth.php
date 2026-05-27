<?php
/**
 * Autenticación basada en sesión PHP para la sección de Mantenimiento.
 *
 * Roles:
 *   - tecnico  : acceso completo (CRUD, exportar, etc.).
 *   - operario : solo puede registrar la fecha de una tarea preventiva
 *                (mant_marcar_hecha). El resto de la UI es de solo lectura.
 *
 * Credenciales:
 *   Las contraseñas se almacenan como hash bcrypt en `.env`:
 *     MANT_TECNICO_USER, MANT_TECNICO_PASS_HASH
 *     MANT_OPERARIO_USER, MANT_OPERARIO_PASS_HASH
 *
 *   Para generar un hash:
 *     php -r "echo password_hash('LA_PASSWORD', PASSWORD_BCRYPT), \"\n\";"
 *
 *   Si en `.env` no hay hashes, hay un fallback a credenciales por defecto
 *   solo cuando APP_ENV !== 'production'. En producción el login falla.
 *
 * Seguridad añadida:
 *   - Cookie de sesión HttpOnly + SameSite=Lax + Secure si la request es HTTPS.
 *   - session_regenerate_id() tras login (evita fixation).
 *   - Rate-limit: tras 5 fallos consecutivos por IP, bloquea 15 minutos
 *     (almacenado en sys_temp como JSON; suficiente para una intranet).
 *   - Token CSRF por sesión para los endpoints POST de mantenimiento.
 */
declare(strict_types=1);

require_once __DIR__ . '/EnvLoader.php';
EnvLoader::loadOnce(__DIR__ . '/../.env');

class Auth
{
    private const RATE_MAX_FAILS   = 5;
    private const RATE_WINDOW_SECS = 900;  // 15 min
    private const RATE_FILE        = 'kh_plan_attainment_login_attempts.json';

    /** Inicia la sesión PHP si aún no está activa. */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);
        session_start();
    }

    /**
     * Valida usuario+contraseña y arranca la sesión.
     * Aplica rate-limit por IP para frenar fuerza bruta.
     *
     * Dos caminos de autenticación:
     *   1. TÉCNICO → usuario+contraseña de .env (MANT_TECNICO_*) o fallback.
     *   2. OPERARIO → cualquier número activo de mant_operarios sirve tanto
     *      como usuario como contraseña (user == pass == numero). Así
     *      cada operario tiene su login propio y la sesión guarda su numero
     *      como `mant_user`, que es el código que luego aparece en las
     *      marcas de revisión.
     */
    public static function login(string $user, string $pass): bool
    {
        self::start();

        $ip = self::clientIp();
        if (self::isRateLimited($ip)) {
            return false;
        }

        $u = trim($user);
        $p = $pass; // sin trim: las contraseñas pueden tener espacios

        // ── 1. Camino TÉCNICO (.env + fallback) ──
        $db = self::loadUsers();
        if (isset($db[$u]) && password_verify($p, $db[$u]['hash'])) {
            self::completarLogin($ip, $u, $db[$u]['role']);
            return true;
        }

        // ── 2. Camino OPERARIO (catálogo mant_operarios) ──
        // El operario introduce su numero como usuario Y como contraseña.
        // Si el numero está activo en BD, login válido como rol=operario.
        if ($u !== '' && $u === $p && self::isOperarioActivo($u)) {
            self::completarLogin($ip, $u, 'operario');
            return true;
        }

        // Fallo: gasta tiempo en bcrypt dummy para no revelar caminos.
        self::registerFailedAttempt($ip);
        password_verify($p, '$2y$10$' . str_repeat('x', 53));
        return false;
    }

    /**
     * Una vez verificadas las credenciales, regenera la sesión, limpia el
     * rate-limit y guarda los datos del usuario logueado.
     */
    private static function completarLogin(string $ip, string $user, string $role): void
    {
        self::clearAttempts($ip);
        session_regenerate_id(true);
        $_SESSION['mant_user']     = $user;
        $_SESSION['mant_role']     = $role;
        $_SESSION['mant_login_at'] = time();
        self::csrfToken();
    }

    /**
     * Comprueba si un número es de un operario actualmente activo en
     * mant_operarios. Si la BD no está disponible, devuelve false.
     */
    private static function isOperarioActivo(string $numero): bool
    {
        // Solo dígitos para evitar pasadas accidentales como SQL injection
        // (aunque PDO ya protege, validamos el formato esperado del numero).
        if (!preg_match('/^\d+$/', $numero)) return false;
        try {
            require_once __DIR__ . '/Db.php';
            $row = Db::pgFetchOne(
                "SELECT 1 AS ok FROM mant_operarios
                  WHERE numero = :n AND COALESCE(activo, TRUE) = TRUE
                  LIMIT 1",
                [':n' => $numero]
            );
            return !empty($row);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION['mant_user'], $_SESSION['mant_role'], $_SESSION['mant_login_at'], $_SESSION['csrf_token']);
    }

    public static function isLoggedIn(): bool
    {
        self::start();
        return !empty($_SESSION['mant_user']);
    }

    public static function user(): ?string
    {
        self::start();
        return $_SESSION['mant_user'] ?? null;
    }

    public static function role(): ?string
    {
        self::start();
        return $_SESSION['mant_role'] ?? null;
    }

    public static function isTecnico(): bool { return self::role() === 'tecnico'; }
    public static function isOperario(): bool { return self::role() === 'operario'; }

    /**
     * Para vistas: redirige al login si no hay sesión activa, conservando la
     * URL solicitada en ?next= para volver tras autenticarse.
     */
    public static function requireLogin(string $loginPath = 'mant_login.php'): void
    {
        if (self::isLoggedIn()) return;
        $next  = basename($_SERVER['PHP_SELF']);
        $qs    = $_SERVER['QUERY_STRING'] ?? '';
        $param = '?next=' . urlencode($next) . ($qs !== '' ? '&qs=' . urlencode($qs) : '');
        header('Location: ' . $loginPath . $param);
        exit;
    }

    /** Para APIs JSON: 401 si no hay sesión. */
    public static function requireLoginApi(): void
    {
        if (self::isLoggedIn()) return;
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No autenticado']);
        exit;
    }

    /** Para APIs JSON: 403 si el usuario no es técnico. */
    public static function requireTecnicoApi(): void
    {
        self::requireLoginApi();
        if (self::isTecnico()) return;
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado: requiere rol técnico']);
        exit;
    }

    // ───────────── CSRF ─────────────

    /**
     * Devuelve el token CSRF de la sesión actual, generándolo si no existía.
     * Las vistas lo exponen al frontend (meta tag + window var) y el JS lo
     * envía como cabecera `X-CSRF-Token` en cada POST.
     */
    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verifica que la petición lleva el token correcto. Si no, responde 403.
     * Acepta el token en `X-CSRF-Token`, en `_csrf` (POST/JSON) o en `csrf` (GET).
     * Salida JSON (uso típico desde APIs).
     */
    public static function requireCsrfApi(): void
    {
        self::start();
        $expected = $_SESSION['csrf_token'] ?? null;
        if (!$expected) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'CSRF: sesión sin token']);
            exit;
        }
        $got = self::extractCsrfFromRequest();
        if (!is_string($got) || !hash_equals($expected, $got)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'CSRF: token inválido o ausente']);
            exit;
        }
    }

    /** Verifica CSRF para formularios (login). Redirige con error si falla. */
    public static function requireCsrfForm(string $redirectTo): void
    {
        self::start();
        $expected = $_SESSION['csrf_token'] ?? null;
        $got      = self::extractCsrfFromRequest();
        if (!$expected || !is_string($got) || !hash_equals($expected, $got)) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }

    private static function extractCsrfFromRequest(): ?string
    {
        $h = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (is_string($h) && $h !== '') return $h;
        // En cuerpos JSON el header es el canal estándar; aún así aceptamos campo `_csrf`.
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $j   = json_decode($raw ?: 'null', true);
            if (is_array($j) && isset($j['_csrf']) && is_string($j['_csrf'])) {
                // Reset stream for downstream readers? No es posible; los endpoints
                // que leen el body deben hacerlo via file_get_contents una vez —
                // todos cachean en variable local, por lo que la lectura adicional
                // aquí no rompe nada (php://input es leíble varias veces).
                return $j['_csrf'];
            }
        }
        $b = $_POST['_csrf'] ?? null;
        if (is_string($b) && $b !== '') return $b;
        $g = $_GET['_csrf'] ?? $_GET['csrf'] ?? null;
        if (is_string($g) && $g !== '') return $g;
        return null;
    }

    // ───────────── Internos: usuarios y rate-limit ─────────────

    /**
     * Devuelve solo los usuarios "fijos" del login (técnico).
     *
     * Los operarios ya NO viven aquí: cualquiera de los 8 del catálogo
     * (mant_operarios.activo=TRUE) entra con su numero como user y pass
     * por el camino de login() · 2 (ver método login()).
     *
     * @return array<string, array{hash:string, role:string}>
     */
    private static function loadUsers(): array
    {
        $users = [];

        $techUser = (string) (env('MANT_TECNICO_USER',  '') ?: '');
        $techHash = (string) (env('MANT_TECNICO_PASS_HASH', '') ?: '');
        if ($techUser !== '' && $techHash !== '') {
            $users[$techUser] = ['hash' => $techHash, 'role' => 'tecnico'];
        }

        // Fallback solo fuera de producción para no dejar el módulo inoperativo
        // en entornos locales sin .env configurado. En producción esto es vacío
        // y el login técnico falla hasta que se configuren las variables de
        // entorno. El operario sigue funcionando vía catálogo (mant_operarios).
        if (empty($users)) {
            $appEnv = strtolower((string) (env('APP_ENV', 'production') ?: 'production'));
            if ($appEnv !== 'production') {
                $users['Ricardo'] = ['hash' => password_hash('7876', PASSWORD_BCRYPT), 'role' => 'tecnico'];
            }
        }

        return $users;
    }

    private static function clientIp(): string
    {
        // Intranet sin proxy: REMOTE_ADDR es fiable.
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private static function attemptsFile(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::RATE_FILE;
    }

    /** @return array<string, array{count:int, until:int}> */
    private static function loadAttempts(): array
    {
        $p = self::attemptsFile();
        if (!is_file($p)) return [];
        $raw = @file_get_contents($p);
        if ($raw === false || $raw === '') return [];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private static function saveAttempts(array $data): void
    {
        $p = self::attemptsFile();
        @file_put_contents($p, json_encode($data), LOCK_EX);
    }

    private static function isRateLimited(string $ip): bool
    {
        $data = self::loadAttempts();
        $now  = time();
        if (!isset($data[$ip])) return false;
        if (($data[$ip]['until'] ?? 0) > $now && ($data[$ip]['count'] ?? 0) >= self::RATE_MAX_FAILS) {
            return true;
        }
        // Si la ventana expiró, limpia entrada y permite intentar.
        if (($data[$ip]['until'] ?? 0) <= $now) {
            unset($data[$ip]);
            self::saveAttempts($data);
        }
        return false;
    }

    private static function registerFailedAttempt(string $ip): void
    {
        $data = self::loadAttempts();
        $now  = time();
        $row  = $data[$ip] ?? ['count' => 0, 'until' => 0];
        if (($row['until'] ?? 0) <= $now) {
            $row = ['count' => 0, 'until' => $now + self::RATE_WINDOW_SECS];
        }
        $row['count'] = (int)$row['count'] + 1;
        $data[$ip] = $row;
        self::saveAttempts($data);
    }

    private static function clearAttempts(string $ip): void
    {
        $data = self::loadAttempts();
        if (isset($data[$ip])) {
            unset($data[$ip]);
            self::saveAttempts($data);
        }
    }
}
