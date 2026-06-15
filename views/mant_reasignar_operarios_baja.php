<?php
/**
 * Reasignación de intervenciones a operarios de baja.
 *
 * Objetivo: que en el histórico aparezcan también los operarios que ya
 * están de baja, como autores de algunas tareas preventivas — pero SOLO
 * dentro del periodo en que estuvieron activos (fecha_alta → fecha_baja).
 *
 * Reglas:
 *   - Solo se TOCAN intervenciones tipo 'completada' (las "no realizadas"
 *     y "recuperaciones" no se reasignan).
 *   - No se crean, borran ni se mueven fechas. SOLO se cambia el operario
 *     en un porcentaje de las intervenciones del rango activo del operario
 *     de baja, eligiéndolas al azar de las que actualmente tienen otro
 *     operario.
 *   - El porcentaje es configurable por GET (?pct=25, por defecto 25).
 *   - Distribución equitativa: si hay varios operarios de baja, la cuota
 *     de cada uno se calcula respecto a las intervenciones en SU rango
 *     individual.
 *   - Idempotencia: el script DETECTA intervenciones ya asignadas a un
 *     operario de baja y las cuenta como "ya hechas". Una segunda ejecución
 *     no duplicará la asignación; solo añadirá lo que falte para alcanzar
 *     el % objetivo.
 *
 * Modo:
 *   - Sin ?apply=1 → muestra preview (cuántas se reasignarían).
 *   - Con ?apply=1 → aplica los cambios en una transacción.
 *
 * Acceso: solo rol técnico.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::requireLogin();
if (!Auth::isTecnico()) {
    header('Location: mantenimiento.php');
    exit;
}

$pct  = max(1, min(100, (int)($_GET['pct'] ?? 25)));   // % objetivo
$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

// ───────────────── 1) Operarios de baja ──────────────────────────────────
$opsBaja = Db::pgFetchAll("
    SELECT numero,
           COALESCE(NULLIF(TRIM(apellidos), ''), '') AS apellidos,
           COALESCE(NULLIF(TRIM(nombre), ''), '')    AS nombre,
           to_char(fecha_alta, 'YYYY-MM-DD') AS fecha_alta,
           to_char(fecha_baja, 'YYYY-MM-DD') AS fecha_baja
      FROM mant_operarios
     WHERE fecha_baja IS NOT NULL
     ORDER BY apellidos, nombre, numero
");

// ───────────────── 2) Plan: por cada operario, calcular cuotas ────────────
$plan      = [];   // por operario de baja: {label, rango, candidatas, yaAsignadas, objetivo, faltan, ids}
$totalMov  = 0;

foreach ($opsBaja as $op) {
    $num = (string)$op['numero'];
    $fbAlta = $op['fecha_alta'] ?: '1900-01-01';
    $fbBaja = $op['fecha_baja'];
    $label  = trim(($op['apellidos'] ?? '') . ' ' . ($op['nombre'] ?? '')) ?: $num;

    // Candidatas: intervenciones COMPLETADAS dentro del rango activo del
    // operario, que tengan OTRO operario asignado (o ninguno). Excluimos
    // explícitamente las que YA son suyas.
    $cand = Db::pgFetchAll("
        SELECT id, fecha_intervencion, operario
          FROM mant_completions
         WHERE tipo = 'completada'
           AND fecha_intervencion IS NOT NULL
           AND fecha_intervencion BETWEEN :fa AND :fb
           AND (operario IS NULL OR operario <> :n)
        ORDER BY random()
    ", [':fa' => $fbAlta, ':fb' => $fbBaja, ':n' => $num]);

    // Ya asignadas en el rango (cuentan a favor del % objetivo)
    $ya = Db::pgFetchOne("
        SELECT COUNT(*)::int AS n
          FROM mant_completions
         WHERE tipo = 'completada'
           AND fecha_intervencion IS NOT NULL
           AND fecha_intervencion BETWEEN :fa AND :fb
           AND operario = :n
    ", [':fa' => $fbAlta, ':fb' => $fbBaja, ':n' => $num]);
    $yaN = (int)($ya['n'] ?? 0);

    // Total candidatas en el rango (todas las completadas, incluso las suyas)
    // → es la base sobre la que calculamos el %.
    $bas = Db::pgFetchOne("
        SELECT COUNT(*)::int AS n
          FROM mant_completions
         WHERE tipo = 'completada'
           AND fecha_intervencion IS NOT NULL
           AND fecha_intervencion BETWEEN :fa AND :fb
    ", [':fa' => $fbAlta, ':fb' => $fbBaja]);
    $base = (int)($bas['n'] ?? 0);

    $objetivo = (int)round($base * $pct / 100);
    $faltan   = max(0, $objetivo - $yaN);
    $faltan   = min($faltan, count($cand));

    $ids = [];
    for ($i = 0; $i < $faltan; $i++) $ids[] = (string)$cand[$i]['id'];
    $totalMov += count($ids);

    $plan[] = [
        'numero'    => $num,
        'label'     => $label,
        'fa'        => $fbAlta,
        'fb'        => $fbBaja,
        'base'      => $base,
        'ya'        => $yaN,
        'objetivo'  => $objetivo,
        'faltan'    => $faltan,
        'cand'      => count($cand),
        'ids'       => $ids,
    ];
}

// ───────────────── 3) Aplicar (transacción) ──────────────────────────────
$err = ''; $aplicadas = 0;
if ($apply && $totalMov > 0) {
    try {
        Db::pg()->beginTransaction();
        foreach ($plan as $row) {
            foreach ($row['ids'] as $id) {
                Db::pgExec(
                    "UPDATE mant_completions SET operario = :n WHERE id = :id",
                    [':n' => $row['numero'], ':id' => $id]
                );
                $aplicadas++;
            }
        }
        Db::pg()->commit();
    } catch (Throwable $e) {
        if (Db::pg()->inTransaction()) Db::pg()->rollBack();
        $err = $e->getMessage();
    }
}

?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Reasignar intervenciones a operarios de baja</title>
<style>
    body { font-family: Arial, sans-serif; padding: 24px; background:#f4f7fb; color:#1a2d4a; max-width:1080px; margin:auto; }
    h1   { font-size: 20px; color:#2d4d7a; margin: 0 0 6px; }
    h2   { font-size: 15px; color:#2d4d7a; margin: 22px 0 8px; }
    .sub { color:#5b6f86; font-size: 13px; margin-bottom: 18px; }
    .box { padding:12px 16px; border-radius:6px; margin:12px 0; font-size:13.5px; line-height:1.5; }
    .info { background:#eef3f8; border-left:5px solid #2d4d7a; }
    .ok   { background:#e8f5e9; border-left:5px solid #1f8a3c; color:#0f5a26; }
    .err  { background:#fdecec; border-left:5px solid #c8102e; color:#8a0d22; }
    .warn { background:#fff8e6; border-left:5px solid #f0c674; color:#7a5b1b; }
    .btn  { display:inline-block; background:#c8102e; color:#fff; padding:11px 20px; font-weight:700;
            font-size:13.5px; text-decoration:none; border-radius:6px; box-shadow:0 2px 6px rgba(200,16,46,.3); margin:8px 6px 8px 0; }
    .btn:hover { background:#a00d24; }
    .btn-2 { background:#2d4d7a; }
    .btn-2:hover { background:#1a4a7a; }
    table { width:100%; border-collapse:collapse; font-size:12.5px; background:#fff; box-shadow:0 1px 3px rgba(15,28,48,.06); border-radius:6px; overflow:hidden; }
    th { background:#2d4d7a; color:#fff; padding:8px 10px; text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.3px; }
    td { padding:7px 10px; border-bottom:1px solid #eef2f6; }
    td.r { text-align:right; }
    .codigo { color:#5b6f86; font-size:11px; }
    .link { color:#2d4d7a; text-decoration:underline; }
    .filters { background:#fff; padding:10px 14px; border-radius:6px; box-shadow:0 1px 3px rgba(15,28,48,.06); margin-bottom:14px; display:flex; gap:12px; align-items:center; }
    .filters label { font-size:12px; color:#2d4d7a; font-weight:600; }
    .filters input[type=number] { padding:5px 8px; border:1px solid #c5d2e0; border-radius:4px; font-size:13px; width:80px; }
</style></head><body>

<h1>Reasignar intervenciones a operarios de baja</h1>
<div class="sub">
    Asigna un % de las intervenciones <strong>completadas</strong> en el periodo activo (fecha alta → fecha baja) de cada operario de baja, manteniendo el resto intacto.
    Se respetan periodicidades y se preserva el historial ya generado.
</div>

<form class="filters" method="get">
    <label>Porcentaje objetivo:
        <input type="number" name="pct" min="1" max="100" value="<?= $pct ?>"> %
    </label>
    <button type="submit" class="btn btn-2" style="padding:8px 14px;font-size:12px">Recalcular preview</button>
</form>

<?php if ($err): ?>
    <div class="box err">❌ Error: <?= htmlspecialchars($err) ?></div>
<?php elseif ($apply): ?>
    <div class="box ok">✅ <?= $aplicadas ?> intervenciones reasignadas correctamente.</div>
<?php else: ?>
    <div class="box info">
        Preview de la reasignación con objetivo del <strong><?= $pct ?>%</strong> sobre las intervenciones del periodo activo de cada operario de baja.
        <?php if ($totalMov === 0): ?>
            <br>· No hay intervenciones que reasignar (o el objetivo ya se cumple).
        <?php else: ?>
            <br>· Se reasignarán en total <strong><?= $totalMov ?></strong> intervenciones (sumando todas las cuotas).
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$apply && $totalMov > 0): ?>
    <a class="btn" href="?<?= http_build_query(['apply' => '1', 'pct' => $pct]) ?>">
        ⚠ APLICAR REASIGNACIÓN (<?= $totalMov ?>)
    </a>
<?php endif; ?>

<h2>Operarios de baja detectados (<?= count($opsBaja) ?>)</h2>

<?php if (empty($opsBaja)): ?>
    <div class="box warn">
        No hay operarios marcados de baja en <code>mant_operarios</code>. Da de baja a alguno desde
        <a class="link" href="mant_operarios.php">Gestión de operarios</a> rellenando su <em>Fecha baja</em> y vuelve aquí.
    </div>
<?php else: ?>
    <table>
        <thead><tr>
            <th>Operario</th>
            <th>Periodo activo</th>
            <th class="r">Total intervenciones<br>en el periodo</th>
            <th class="r">Ya suyas</th>
            <th class="r">Objetivo (<?= $pct ?>%)</th>
            <th class="r">A reasignar<br>ahora</th>
        </tr></thead>
        <tbody>
        <?php foreach ($plan as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['label']) ?><br><span class="codigo">cód. <?= htmlspecialchars($row['numero']) ?></span></td>
                <td><?= htmlspecialchars($row['fa']) ?> → <?= htmlspecialchars($row['fb']) ?></td>
                <td class="r"><?= $row['base'] ?></td>
                <td class="r"><?= $row['ya'] ?></td>
                <td class="r"><?= $row['objetivo'] ?></td>
                <td class="r"><strong style="color:<?= $row['faltan'] ? '#c8102e' : '#5b6f86' ?>"><?= $row['faltan'] ?></strong>
                    <?php if ($row['faltan'] > 0 && $row['cand'] < $row['faltan']): ?>
                        <br><small style="color:#a00">(solo <?= $row['cand'] ?> candidatas disponibles)</small>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin-top:24px;font-size:12.5px;color:#5b6f86">
        <strong>Nota:</strong> "Ya suyas" cuenta las intervenciones que ya estaban asignadas a este operario en su periodo (no se duplicarán).
        "A reasignar ahora" se calcula como <em>Objetivo − Ya suyas</em> y se eligen al azar entre las candidatas (intervenciones con otro operario, sin tocar las NO realizadas ni las recuperaciones).
    </p>
<?php endif; ?>

<p style="margin-top:24px">
    <a class="link" href="mant_operarios.php">← Gestión de operarios</a>
    · <a class="link" href="mant_historico.php">Ir al Histórico</a>
    · <a class="link" href="mantenimiento.php">Volver al menú</a>
</p>

</body></html>
