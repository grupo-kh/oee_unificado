<?php
/**
 * Instalador del schema PostgreSQL.
 *
 * Lee migrations/*.sql en orden y aplica las que no constan en
 * schema_migrations. Idempotente.
 *
 * Uso (PowerShell o cmd):
 *   php tools/install_postgres.php
 *
 * Requisitos previos:
 *   1) PostgreSQL 16 instalado y arrancado.
 *   2) Extensión pdo_pgsql habilitada en C:\xampp\php\php.ini.
 *   3) Database, usuario y permisos creados (ver SETUP_POSTGRES.md).
 *   4) Credenciales actualizadas en config/database.php.
 */

require_once __DIR__ . '/../lib/Db.php';

$migDir = realpath(__DIR__ . '/../migrations');
if (!$migDir) { fwrite(STDERR, "No existe el directorio migrations/\n"); exit(1); }

echo "Instalador PostgreSQL · plan_attainment" . PHP_EOL;
echo "Conectando a " . DB_PG_HOST . ":" . DB_PG_PORT . "/" . DB_PG_NAME . " (usuario " . DB_PG_USER . ")…" . PHP_EOL;

try {
    $pdo = Db::pg();
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR de conexión: " . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "Repasa SETUP_POSTGRES.md y los valores en config/database.php." . PHP_EOL);
    exit(2);
}

// Asegura que existe la tabla de control para saber qué migraciones ya aplicamos.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(20) PRIMARY KEY,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT now(),
        description TEXT
    )
");

$applied = [];
foreach (Db::pgFetchAll("SELECT version FROM schema_migrations") as $r) {
    $applied[$r['version']] = true;
}

$files = glob($migDir . '/*.sql');
sort($files);
if (!$files) { echo "Sin migraciones que aplicar." . PHP_EOL; exit(0); }

$applied_count = 0;
foreach ($files as $file) {
    $base = basename($file);
    if (!preg_match('/^(\d+)_/', $base, $m)) {
        echo "  · saltando $base (formato no reconocido)" . PHP_EOL;
        continue;
    }
    $version = $m[1];
    if (isset($applied[$version])) {
        echo "  ✓ $base · ya aplicada" . PHP_EOL;
        continue;
    }
    echo "  → aplicando $base…" . PHP_EOL;
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "    no se pudo leer $file" . PHP_EOL);
        exit(3);
    }
    try {
        $pdo->exec($sql);
        echo "    ✓ aplicada" . PHP_EOL;
        $applied_count++;
    } catch (Throwable $e) {
        fwrite(STDERR, "    ✗ error: " . $e->getMessage() . PHP_EOL);
        exit(4);
    }
}

echo PHP_EOL . "Hecho. Migraciones aplicadas en esta ejecución: $applied_count" . PHP_EOL;

// Resumen de tablas
$rows = Db::pgFetchAll("
    SELECT relname AS tabla, n_live_tup AS filas
      FROM pg_stat_user_tables
     WHERE schemaname = 'public' AND relname LIKE 'mant_%'
     ORDER BY relname
");
if ($rows) {
    echo "Tablas mant_* y filas:" . PHP_EOL;
    foreach ($rows as $r) {
        printf("  %-35s %s filas\n", $r['tabla'], $r['filas']);
    }
}
