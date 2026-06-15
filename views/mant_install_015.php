<?php
/**
 * Aplica la migración 015 (Calendario laboral · excepciones).
 *
 * Abrir: http://<host>/PLAN_ATTAINMENT/views/mant_install_015.php
 *   - Sin parámetros: muestra el SQL y el botón para aplicarlo.
 *   - Con ?apply=1:  aplica la migración.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireLogin();
if (!Auth::isTecnico()) { header('Location: mantenimiento.php'); exit; }

$migFile = __DIR__ . '/../migrations/015_mant_calendario_excepciones.sql';
$apply   = isset($_GET['apply']) && $_GET['apply'] === '1';
$result  = '';
$err     = '';
$already = false;

try {
    $row = Db::pgFetchOne(
        "SELECT version FROM schema_migrations WHERE version = '015'"
    );
    $already = !empty($row);
} catch (Throwable $e) {
    $err = 'No se pudo consultar schema_migrations: ' . $e->getMessage();
}

if ($apply && !$already && !$err) {
    try {
        $sql = file_get_contents($migFile);
        if ($sql === false) {
            $err = 'No se encontró el archivo de migración';
        } else {
            Db::pg()->exec($sql);
            $result = 'Migración 015 aplicada correctamente.';
            $already = true;
        }
    } catch (Throwable $e) {
        $err = 'Error aplicando la migración: ' . $e->getMessage();
    }
}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Aplicar migración 015 · Calendario laboral</title>
<style>
    body { font-family: Arial, sans-serif; padding: 24px; background:#f4f7fb; color:#1a2d4a; max-width:920px; margin:auto; }
    h1   { font-size: 20px; color:#2d4d7a; margin: 0 0 6px; }
    .sub { color:#5b6f86; font-size: 13px; margin-bottom: 18px; }
    .box { padding:12px 16px; border-radius:6px; margin:12px 0; font-size:13.5px; line-height:1.5; }
    .info { background:#eef3f8; border-left:5px solid #2d4d7a; }
    .ok   { background:#e8f5e9; border-left:5px solid #1f8a3c; color:#0f5a26; }
    .err  { background:#fdecec; border-left:5px solid #c8102e; color:#8a0d22; }
    .warn { background:#fff8e6; border-left:5px solid #f0c674; color:#7a5b1b; }
    .btn  { display:inline-block; background:#c8102e; color:#fff; padding:12px 22px; font-weight:700; font-size:14px;
            text-decoration:none; border-radius:6px; box-shadow:0 2px 6px rgba(200,16,46,.3); margin: 14px 0; }
    .btn:hover { background:#a00d24; }
    pre { background:#1a2d4a; color:#cdd6e3; padding:14px; border-radius:6px; font-size:11.5px; overflow:auto; }
    a.link { color:#2d4d7a; text-decoration:underline; }
</style>
</head><body>
<h1>Migración 015 · Calendario laboral · excepciones</h1>
<div class="sub">Crea la tabla <code>mant_calendario_excepciones</code> que permite marcar días concretos como NO laborables o como laborables extra.</div>

<?php if ($err): ?>
    <div class="box err">❌ <?= htmlspecialchars($err) ?></div>
<?php endif; ?>
<?php if ($result): ?>
    <div class="box ok">✅ <?= htmlspecialchars($result) ?></div>
<?php endif; ?>

<?php if ($already): ?>
    <div class="box ok">
        ✅ La migración <strong>015</strong> ya está aplicada en la base de datos.
        Ya puedes usar <a class="link" href="mant_calendario.php">Calendario laboral</a>.
    </div>
<?php else: ?>
    <div class="box warn">⚠ La migración 015 no está aplicada todavía. Pulsa el botón rojo para aplicarla.</div>
    <a class="btn" href="?apply=1">⚠ APLICAR MIGRACIÓN 015</a>
<?php endif; ?>

<details style="margin-top:18px">
    <summary style="cursor:pointer;font-weight:700;color:#2d4d7a">Ver SQL de la migración</summary>
    <pre><?= htmlspecialchars(file_get_contents($migFile)) ?></pre>
</details>

<p style="margin-top:24px">
    <a class="link" href="mantenimiento.php">← Volver al menú</a>
    <?php if ($already): ?>
        · <a class="link" href="mant_calendario.php">Ir al Calendario →</a>
    <?php endif; ?>
</p>
</body></html>
