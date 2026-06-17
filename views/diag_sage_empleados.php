<?php
/**
 * Diagnóstico: localiza la tabla de empleados en Sage (Sage).
 *
 * Lista las tablas cuyo nombre contiene EMPL/PERSONA/OPERAR/USUARIO/TRABAJ
 * y muestra sus columnas, para identificar exactamente la que se usa en
 * tu Sage. Acceso: solo rol técnico.
 *
 * URL: views/diag_sage_empleados.php
 *      views/diag_sage_empleados.php?probar=1234  (prueba un código concreto)
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/SageEmpleadosStore.php';

Auth::requireLogin();
if (!Auth::isTecnico()) { header('Location: ../views/mantenimiento.php'); exit; }

$probar = trim((string)($_GET['probar'] ?? ''));
$err = '';
$tablas = [];
$probarResultado = null;

try {
    // 1) Listar tablas candidatas en information_schema
    $tablas = fetchAll('sage', "
        SELECT TABLE_SCHEMA + '.' + TABLE_NAME AS tabla
          FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_TYPE = 'BASE TABLE'
           AND (
                TABLE_NAME LIKE '%EMPLEA%'
             OR TABLE_NAME LIKE '%PERSONA%'
             OR TABLE_NAME LIKE '%PERSONAL%'
             OR TABLE_NAME LIKE '%OPERAR%'
             OR TABLE_NAME LIKE '%USUARIO%'
             OR TABLE_NAME LIKE '%TRABAJ%'
             OR TABLE_NAME LIKE '%PERS_%'
             OR TABLE_NAME LIKE '%RH_%'
           )
         ORDER BY TABLE_NAME
    ");
} catch (Throwable $e) {
    $err = 'Error consultando Sage: ' . $e->getMessage();
}

// Columnas por tabla
$cols = [];
foreach ($tablas as $t) {
    $nom = (string)$t['tabla'];
    try {
        $c = fetchAll('sage', "
            SELECT COLUMN_NAME, DATA_TYPE
              FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA + '.' + TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION
        ", [$nom]);
        $cols[$nom] = $c;
    } catch (Throwable $e) {
        $cols[$nom] = [];
    }
}

// Probar el código del store
if ($probar !== '' && preg_match('/^\d{1,10}$/', $probar)) {
    $probarResultado = [
        'existe' => SageEmpleadosStore::existe($probar),
        'nombre' => SageEmpleadosStore::nombre($probar),
        'usando' => SageEmpleadosStore::candidatoUsado(),
    ];
}

?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Diagnóstico · Empleados Sage</title>
<style>
    body { font-family: Arial, sans-serif; padding: 24px; background:#f4f7fb; color:#1a2d4a; max-width:1080px; margin:auto; }
    h1   { font-size: 20px; color:#2d4d7a; margin: 0 0 6px; }
    h2   { font-size: 14px; color:#2d4d7a; margin: 20px 0 8px; }
    .sub { color:#5b6f86; font-size: 13px; margin-bottom: 18px; }
    .box { padding:12px 16px; border-radius:6px; margin:12px 0; font-size:13.5px; line-height:1.5; }
    .info { background:#eef3f8; border-left:5px solid #2d4d7a; }
    .ok   { background:#e8f5e9; border-left:5px solid #1f8a3c; color:#0f5a26; }
    .err  { background:#fdecec; border-left:5px solid #c8102e; color:#8a0d22; }
    .warn { background:#fff8e6; border-left:5px solid #f0c674; color:#7a5b1b; }
    table { width:100%; border-collapse:collapse; font-size:12.5px; background:#fff;
            box-shadow:0 1px 3px rgba(15,28,48,.06); border-radius:6px; overflow:hidden; }
    th { background:#2d4d7a; color:#fff; padding:8px 10px; text-align:left; font-size:11.5px; }
    td { padding:6px 10px; border-bottom:1px solid #eef2f6; vertical-align:top; }
    code { background:#1a2d4a; color:#cdd6e3; padding:1px 6px; border-radius:3px; font-size:11.5px; }
    .filters { display:flex; gap:10px; align-items:center; margin-bottom:12px; }
    .filters input { padding:6px 10px; border:1px solid #c5d2e0; border-radius:4px; font-size:13px; }
    .btn { background:#2d4d7a; color:#fff; padding:7px 14px; border:0; border-radius:4px; font-weight:600; cursor:pointer; }
    .link { color:#2d4d7a; text-decoration:underline; }
    details summary { cursor:pointer; font-weight:700; color:#2d4d7a; font-size:13px; padding:4px 0; }
    .colsbox { background:#f8fafc; padding:8px 12px; border-radius:5px; margin-top:4px; font-size:12px; }
    .colsbox span { display:inline-block; background:#fff; padding:2px 8px; margin:2px; border:1px solid #c5d2e0; border-radius:3px; font-family:monospace; }
</style></head><body>

<h1>Diagnóstico Empleados Sage</h1>
<div class="sub">Localiza la tabla y los campos correctos para la verificación de operarios en la app de planta. Solo lectura.</div>

<?php if ($err): ?>
    <div class="box err">❌ <?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<form class="filters" method="get">
    <label>Probar código de empleado:
        <input type="text" name="probar" value="<?= htmlspecialchars($probar) ?>" placeholder="Ej. 1004" pattern="\d+" inputmode="numeric">
    </label>
    <button type="submit" class="btn">Probar</button>
</form>

<?php if ($probarResultado !== null): ?>
    <div class="box <?= $probarResultado['existe'] ? 'ok' : 'warn' ?>">
        <strong>Probando código <?= htmlspecialchars($probar) ?>:</strong>
        <?php if ($probarResultado['existe']): ?>
            ✅ ENCONTRADO · nombre: <strong><?= htmlspecialchars($probarResultado['nombre']) ?></strong><br>
            Usando la consulta: <code><?= htmlspecialchars($probarResultado['usando'] ?? '—') ?></code>
        <?php else: ?>
            ⚠ NO encontrado en ninguna de las consultas precargadas del store.<br>
            <?php if ($probarResultado['usando']): ?>
                Tabla detectada: <code><?= htmlspecialchars($probarResultado['usando']) ?></code> (existe la tabla pero el código no está activo o no existe).
            <?php else: ?>
                Ninguna de las tablas precargadas existe en este Sage. Revisa la lista de abajo y dime cuál es la correcta.
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<h2>Tablas candidatas en Sage (<?= count($tablas) ?>)</h2>
<?php if (empty($tablas)): ?>
    <div class="box warn">
        No se encontraron tablas con esos patrones. Ejecuta la siguiente query manualmente en Sage para listar TODAS las tablas:<br>
        <code style="display:block;margin-top:6px">SELECT TABLE_SCHEMA + '.' + TABLE_NAME FROM INFORMATION_SCHEMA.TABLES ORDER BY 1</code>
    </div>
<?php else: ?>
    <table>
        <thead><tr><th>Tabla</th><th>Columnas</th></tr></thead>
        <tbody>
        <?php foreach ($tablas as $t):
            $nom = (string)$t['tabla'];
            $colsT = $cols[$nom] ?? [];
            $colNames = array_map(fn($c) => (string)$c['COLUMN_NAME'], $colsT);
        ?>
            <tr>
                <td><code><?= htmlspecialchars($nom) ?></code><br>
                    <small><?= count($colNames) ?> columnas</small>
                </td>
                <td>
                    <details>
                        <summary>Ver columnas</summary>
                        <div class="colsbox">
                            <?php foreach ($colsT as $c): ?>
                                <span><?= htmlspecialchars($c['COLUMN_NAME']) ?> <em style="color:#5b6f86">(<?= htmlspecialchars($c['DATA_TYPE']) ?>)</em></span>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p style="margin-top:24px;font-size:12px;color:#5b6f86">
    <strong>Cómo configurar la consulta correcta</strong>: si ninguna de las cuatro consultas precargadas funciona,
    edita <code>lib/SageEmpleadosStore.php</code> y añade en <code>CANDIDATAS</code> una nueva entrada con el SQL apropiado
    a la tabla/columnas que aparecen arriba.
</p>

<p style="margin-top:18px">
    <a class="link" href="../oflanza.php">Ir a Lanzamiento de OFs →</a>
    · <a class="link" href="mantenimiento.php">Volver al menú</a>
</p>

</body></html>
