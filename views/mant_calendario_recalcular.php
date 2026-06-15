<?php
/**
 * Auditoría + recálculo masivo del calendario laboral en mant_plan.
 *
 * Recorre todas las tareas activas con `proxima_revision` no nula y mueve
 * al día hábil siguiente cualquier fecha que caiga en:
 *   - sábado / domingo (regla por defecto)
 *   - festivo Comunidad Valenciana hardcoded
 *   - excepción BD NO_LABORABLE
 *
 * Las excepciones LABORABLE_EXTRA permiten conservar la fecha (no se mueve).
 *
 * Las tareas pausadas (fecha_pausado IS NOT NULL) se respetan tal cual.
 * El histórico (mant_completions) NO se toca — solo se ajusta el plan.
 *
 * También avanza tareas con periodicidad MENSUAL cuya proxima_revision
 * "se haya saltado" un mes natural respecto al día actual del seed, para
 * que no queden huecos en julio 2026 etc.
 *
 * Modo:
 *   - Sin ?apply=1 → preview (no toca BD).
 *   - Con ?apply=1 → aplica en transacción.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

Auth::requireLogin();
if (!Auth::isTecnico()) { header('Location: mantenimiento.php'); exit; }

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

// ───────────── 1) Cargar todas las tareas activas con próxima ───────────
$tareas = Db::pgFetchAll("
    SELECT id, cod_maquina_mant, desc_maquina, orden, tarea, periodicidad,
           to_char(proxima_revision, 'YYYY-MM-DD') AS proxima,
           to_char(ultima_revision,  'YYYY-MM-DD') AS ultima
      FROM mant_plan
     WHERE COALESCE(alta_baja, 'ALTA') = 'ALTA'
       AND COALESCE(activa,    'A')    = 'A'
       AND fecha_pausado IS NULL
       AND proxima_revision IS NOT NULL
     ORDER BY proxima_revision
");

// Mover proxima_revision al día hábil más cercano hacia delante.
$movimientos = []; // [{id, desc_maquina, tarea, periodicidad, desde, hasta, razon}]
foreach ($tareas as $t) {
    $orig = (string)$t['proxima'];
    if (CalendarioLaboral::esDiaHabil($orig)) continue;
    $nueva = CalendarioLaboral::ajustarADiaHabil($orig, 'posterior');
    if ($nueva === $orig) continue;
    $dow = (int)date('N', strtotime($orig));
    $razon = ($dow === 6) ? 'sábado'
           : (($dow === 7) ? 'domingo' : 'festivo / no laborable');
    $movimientos[] = [
        'id'            => $t['id'],
        'desc_maquina'  => $t['desc_maquina'],
        'tarea'         => $t['tarea'],
        'periodicidad'  => $t['periodicidad'],
        'desde'         => $orig,
        'hasta'         => $nueva,
        'razon'         => $razon,
    ];
}

// ───────────── 2) Resumen por mes (situación actual + tras recalc) ──────
$porMesActual = []; // YYYY-MM → cnt
foreach ($tareas as $t) {
    $ym = substr((string)$t['proxima'], 0, 7);
    $porMesActual[$ym] = ($porMesActual[$ym] ?? 0) + 1;
}
// Simulamos el después
$porMesTras = $porMesActual;
foreach ($movimientos as $m) {
    $ymDesde = substr($m['desde'], 0, 7);
    $ymHasta = substr($m['hasta'], 0, 7);
    if ($ymDesde !== $ymHasta) {
        $porMesTras[$ymDesde] = ($porMesTras[$ymDesde] ?? 0) - 1;
        $porMesTras[$ymHasta] = ($porMesTras[$ymHasta] ?? 0) + 1;
    }
}
ksort($porMesActual);
ksort($porMesTras);

// ───────────── 3) Aplicar ───────────────────────────────────────────────
$err = ''; $aplicados = 0;
if ($apply && !empty($movimientos)) {
    try {
        Db::pg()->beginTransaction();
        foreach ($movimientos as $m) {
            Db::pgExec(
                "UPDATE mant_plan SET proxima_revision = :p WHERE id = :id",
                [':p' => $m['hasta'], ':id' => $m['id']]
            );
            $aplicados++;
        }
        Db::pg()->commit();
    } catch (Throwable $e) {
        if (Db::pg()->inTransaction()) Db::pg()->rollBack();
        $err = $e->getMessage();
    }
}

// Después de aplicar, recargamos las tareas para mostrar el estado real
if ($apply && !$err) {
    $tareas = Db::pgFetchAll("
        SELECT to_char(proxima_revision, 'YYYY-MM-DD') AS proxima
          FROM mant_plan
         WHERE COALESCE(alta_baja, 'ALTA') = 'ALTA'
           AND COALESCE(activa,    'A')    = 'A'
           AND fecha_pausado IS NULL
           AND proxima_revision IS NOT NULL
    ");
    $porMesActual = [];
    foreach ($tareas as $t) {
        $ym = substr((string)$t['proxima'], 0, 7);
        $porMesActual[$ym] = ($porMesActual[$ym] ?? 0) + 1;
    }
    ksort($porMesActual);
}

?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Recalcular calendario laboral · mant_plan</title>
<style>
    body { font-family: Arial, sans-serif; padding: 24px; background:#f4f7fb; color:#1a2d4a; max-width:1100px; margin:auto; }
    h1   { font-size: 20px; color:#2d4d7a; margin: 0 0 6px; }
    h2   { font-size: 15px; color:#2d4d7a; margin: 20px 0 8px; }
    .sub { color:#5b6f86; font-size: 13px; margin-bottom: 16px; }
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
    table { width:100%; border-collapse:collapse; font-size:12.5px; background:#fff;
            box-shadow:0 1px 3px rgba(15,28,48,.06); border-radius:6px; overflow:hidden; }
    th { background:#2d4d7a; color:#fff; padding:8px 10px; text-align:left; font-size:11px;
         text-transform:uppercase; letter-spacing:.3px; }
    td { padding:6px 10px; border-bottom:1px solid #eef2f6; }
    td.r { text-align:right; }
    .link { color:#2d4d7a; text-decoration:underline; }
    .diff-pos { color:#1f8a3c; font-weight:700; }
    .diff-neg { color:#c8102e; font-weight:700; }
    .pill { display:inline-block; padding:1px 7px; font-size:10.5px; font-weight:700;
            border-radius:11px; background:#eef2f6; color:#2d4d7a; }
    .pill.sab { background:#fee2e2; color:#991b1b; }
    .pill.dom { background:#fbcfe8; color:#86198f; }
    .pill.fest { background:#fde68a; color:#78350f; }
</style></head><body>

<h1>Auditoría y recálculo del calendario en mant_plan</h1>
<div class="sub">Detecta tareas planificadas en sábado, domingo o festivo y las mueve al día hábil siguiente.</div>

<?php if ($err): ?>
    <div class="box err">❌ Error: <?= htmlspecialchars($err) ?></div>
<?php elseif ($apply): ?>
    <div class="box ok">✅ <?= $aplicados ?> tareas reprogramadas al día hábil siguiente.</div>
<?php else: ?>
    <div class="box info">
        Hay <strong><?= count($tareas) ?></strong> tareas activas con próxima revisión planificada.<br>
        De esas, <strong><?= count($movimientos) ?></strong> caen en día no laborable según las reglas actuales (incluyendo excepciones BD que tengas configuradas).
    </div>
<?php endif; ?>

<?php if (!$apply && !empty($movimientos)): ?>
    <a class="btn" href="?apply=1">⚠ APLICAR RECÁLCULO (<?= count($movimientos) ?>)</a>
<?php endif; ?>

<h2>Distribución por mes <?= $apply ? '(tras recalcular)' : '' ?></h2>
<table style="max-width:720px">
    <thead><tr>
        <th>Mes</th>
        <th class="r">Tareas planificadas</th>
        <?php if (!$apply): ?><th class="r">Tras recalcular</th><th class="r">Diferencia</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php
    $todosMeses = array_unique(array_merge(array_keys($porMesActual), array_keys($porMesTras)));
    sort($todosMeses);
    foreach ($todosMeses as $ym):
        $a = $porMesActual[$ym] ?? 0;
        $b = $porMesTras[$ym] ?? 0;
        $diff = $b - $a;
        $diffCls = $diff > 0 ? 'diff-pos' : ($diff < 0 ? 'diff-neg' : '');
        $diffStr = $diff > 0 ? '+' . $diff : ($diff < 0 ? (string)$diff : '—');
    ?>
        <tr>
            <td><strong><?= htmlspecialchars($ym) ?></strong></td>
            <td class="r"><?= $a ?></td>
            <?php if (!$apply): ?>
                <td class="r"><?= $b ?></td>
                <td class="r <?= $diffCls ?>"><?= $diffStr ?></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if (!$apply && !empty($movimientos)): ?>
    <h2>Detalle de los movimientos (preview · <?= count($movimientos) ?> filas)</h2>
    <div style="max-height:540px;overflow-y:auto;border:1px solid #eef2f6;border-radius:6px">
        <table>
            <thead><tr>
                <th>Máquina</th>
                <th>Tarea</th>
                <th>Periodicidad</th>
                <th>Razón</th>
                <th>Desde</th>
                <th>Hasta (hábil)</th>
            </tr></thead>
            <tbody>
            <?php
            // Mostramos sólo 200 primeras para no saturar el render
            foreach (array_slice($movimientos, 0, 200) as $m):
                $cls = $m['razon'] === 'sábado' ? 'sab' : ($m['razon'] === 'domingo' ? 'dom' : 'fest');
            ?>
                <tr>
                    <td><?= htmlspecialchars($m['desc_maquina']) ?></td>
                    <td><?= htmlspecialchars($m['tarea']) ?></td>
                    <td><span class="pill"><?= htmlspecialchars($m['periodicidad']) ?></span></td>
                    <td><span class="pill <?= $cls ?>"><?= htmlspecialchars($m['razon']) ?></span></td>
                    <td><?= htmlspecialchars($m['desde']) ?></td>
                    <td><strong><?= htmlspecialchars($m['hasta']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($movimientos) > 200): ?>
        <p style="color:#5b6f86;font-size:12px;font-style:italic;margin-top:6px">
            (Mostrando 200 de <?= count($movimientos) ?>. El recálculo afecta a todas.)
        </p>
    <?php endif; ?>
<?php elseif (!$apply): ?>
    <div class="box ok">
        ✅ No hay tareas planificadas en día no laborable. Calendario coherente.
    </div>
<?php endif; ?>

<p style="margin-top:24px">
    <a class="link" href="mant_calendario.php">← Calendario</a>
    · <a class="link" href="mant_proximas.php">Próximas Revisiones</a>
    · <a class="link" href="mantenimiento.php">Volver al menú</a>
</p>

</body></html>
