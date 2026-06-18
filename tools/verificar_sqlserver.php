<?php
/**
 * Verificación de conexiones SQL Server (MAPEX y SAGE).
 *
 * Intención: tras rellenar las claves DB_MAPEX_* y DB_SAGE_* en el .env,
 * este script confirma que el driver pdo_sqlsrv conecta a ambos orígenes
 * sin tener que abrir las vistas del dashboard. Solo lectura.
 *
 * Uso:  php tools/verificar_sqlserver.php
 */

require __DIR__ . '/../config/database.php';

foreach (['mapex' => 'MAPEX', 'sage' => 'SAGE'] as $bd => $etiqueta) {
    echo "=== $etiqueta ===\n";
    $host = $bd === 'mapex' ? DB_MAPEX_HOST : DB_SAGE_HOST;
    $name = $bd === 'mapex' ? DB_MAPEX_NAME : DB_SAGE_NAME;

    if ($host === '' || $name === '') {
        echo "  OMITIDO: host/base de datos vacíos en .env\n\n";
        continue;
    }

    try {
        $pdo = getConnection($bd);
        $ver = $pdo->query('SELECT @@VERSION')->fetchColumn();
        $linea = strtok($ver, "\n");
        echo "  CONEXIÓN OK · $linea\n\n";
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n\n";
    }
}
