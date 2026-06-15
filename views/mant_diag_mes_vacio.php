<?php
/**
 * Diagnóstico: por qué un mes no tiene tareas planificadas.
 *
 * Recorre todas las tareas activas con su última intervención y predice
 * cuál DEBERÍA ser su próxima_revision según su periodicidad, comparando
 * con lo que tiene almacenado en mant_plan. Muestra el desfase mes a mes
 * para detectar "saltos" (p.ej. la mensual que pasa de junio a agosto
 * porque su próxima en BD está mal calculada).
 *
 * También ofrece un botón "Rellenar mes X" que mueve a ese mes las
 * tareas cuya próxima_revision se ha calculado en exceso (más adelante
 * de lo que tocaría por periodicidad).
 *
 * Solo rol técnico.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

Auth::requireLogin();
if (!Auth::isTecnico()) { header('Location: mantenimiento.php'); exit; }

$ym  = (string)($_GET['ym'] ?? '2026-07');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

[$yObj, $mObj] = array_map('intval', explode('-', $ym));
$primer = sprintf('%04d-%02d-01', $yObj, $mObj);
$ultimo = date('Y-m-t', strtotime($primer));

// Cantidad de días naturales que avanza cada periodicidad. El truco "30 días
// por mes" es el mismo que ya usa el resto del sistema (mant_proximas, etc.).
$DIAS_PERIOD = [
    'DIARIA'        => 1,  'DIARIO'   => 1,
    'SEMANAL'       => 7,
    'QUINCENAL'     => 15,
    'MENSUAL'       => 30,
    'BIMESTRAL'     => 60, 'BIMENSUAL' => 60,
    'TRIMESTRAL'    => 90,
    'CUATRIMESTRAL' => 120,
    'SEMESTRAL'     => 180,
    'ANUAL'         => 365,
    'TRIANUAL'      => 365 * 3,
];
function diasPer(string $p, array $idx): int {
    $u = strtoupper(trim($p));
    return $idx[$u] ?? 0;
}

// ───────────── 1) Estado actual del mes objetivo ────────────────────────
$rowsMes = Db::pgFetchAll("
    SELECT to_char(proxima_revision, 'YYYY-MM-DD') AS f, COUNT(*)::int AS n
      FROM mant_plan
     WHERE proxima_revision BETWEEN :a AND :b
       AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
       AND COALESCE(activa,    'A')    = 'A'
       AND fecha_pausado IS NULL
     GROUP BY proxima_revision
     ORDER BY proxima_revision
", [':a' => $primer, ':b' => $ultimo]);
$totalMes = 0;
foreach ($rowsMes as $r) $totalMes += (int)$r['n'];

// ───────────── 2) Predecir próxima esperada de cada tarea ───────────────
// Si una tarea tiene ultima_revision = U y periodicidad P, su próxima
// "esperada" es U + P. Si la BD tiene proxima muy posterior, hay desfase.
$tareas = Db::pgFetchAll("
    SELECT id, cod_maquina_mant, desc_maquina, orden, tarea, periodicidad,
           to_char(ultima_revision,  'YYYY-MM-DD') AS ultima,
           to_char(proxima_revision, 'YYYY-MM-DD') AS proxima
      FROM mant_plan
     WHERE COALESCE(alta_baja, 'ALTA') = 'ALTA'
       AND COALESCE(activa,    'A')    = 'A'
       AND fecha_pausado IS NULL
       AND proxima_revision IS NOT NULL
       AND ultima_revision  IS NOT NULL
");

$movimientos = [];   // [{id, desc_maquina, tarea, per, ultima, antes, esperada}]
$dentroDelMes = 0;   // cuántas DEBERÍAN caer en el mes objetivo

foreach ($tareas as $t) {
    $per = (string)$t['periodicidad'];
    $d   = diasPer($per, $DIAS_PERIOD);
    if ($d <= 0) continue;
    $u = (string)$t['ultima'];
    // próxima esperada = última + N días, ajustada a día hábil siguiente
    $esp = date('Y-m-d', strtotime($u . " +$d days"));
    if (!CalendarioLaboral::esDiaHabil($esp)) {
        $esp = CalendarioLaboral::ajustarADiaHabil($esp, 'posterior');
    }
    $antes = (string)$t['proxima'];
    if ($esp === $antes) continue;          // todo OK
    if ($esp < $primer)  continue;          // la esperada ya pasó del mes objetivo (otra época)

    // Solo proponer corrección si la esperada cae en el mes objetivo o antes
    // que la que hay almacenada (es decir, hay "salto" hacia adelante).
    if ($esp >= $antes) continue;
    if ($esp > $ultimo) continue;           // no aporta al mes objetivo

    $movimientos[] = [
        'id'           => (int)$t['id'],
        'desc_maquina' => (string)$t['desc_maquina'],
        'tarea'        => (string)$t['tarea'],
        'periodicidad' => $per,
        'ultima'       => $u,
        'antes'        => $antes,
        'esperada'     => $esp,
    ];
    if ($esp >= $primer && $esp <= $ultimo) $dentroDelMes++;
}

// ───────────── 3) Aplicar correcciones ──────────────────────────────────
$err = ''; $aplicados = 0;
if ($apply && !empty($movimientos)) {
    try {
        Db::pg()->beginTransaction();
        foreach ($movimientos as $m) {
            Db::pgExec(
                "UPDATE mant_plan SET proxima_revision = :p WHERE id = :id",
                [':p' => $m['esperada'], ':id' => $m['id']]
            );
            $aplicados++;
        }
        Db::pg()->commit();
    } catch (Throwable $e) {
        if (Db::pg()->inTransaction()) Db::pg()->rollBack();
        $err = $e->getMessage();
    }
}

?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Diagnóstico mes vacío · <?= htmlspecialchars($ym) ?></title>
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
    table { width:100%; border-collapse:collapse; font-size:12.5px; background:#fff;
            box-shadow:0 1px 3px rgba(15,28,48,.06); border-radius:6px; overflow:hidden; }
    th { background:#2d4d7a; color:#fff; padding:8px 10px; text-align:left; font-size:11px;
         text-transform:uppercase; letter-spacing:.3px; }
    td { padding:6px 10px; border-bottom:1px solid #eef2f6; }
    td.r { text-align:right; }
    .pill { display:inline-block; padding:1px 7px; font-size:10.5px; font-weight:700;
            border-radius:11px; background:#eef2f6; color:#2d4d7a; }
    .arrow { color:#c8102e; font-weight:700; }
    .filters { background:#fff; padding:10px 14px; border-radius:6px; box-shadow:0 1px 3px rgba(15,28,48,.06); margin-bottom:14px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
    .filters input[type=text] { padding:5px 8px; border:1px solid #c5d2e0; border-radius:4px; font-size:13px; width:100px; }
    .link { color:#2d4d7a; text-decoration:underline; }
</style></head><body>

<h1>Diagnóstico mes "vacío"</h1>
<div class="sub">
    Detecta tareas cuya próxima_revision está más tarde de lo que tocaría según su periodicidad y propone moverlas hacia atrás (al día esperado), para rellenar meses que han quedado vacíos por "saltos" del seed.
</div>

<form class="filters" method="get">
    <label>Mes objetivo (YYYY-MM):
        <input type="text" name="ym" value="<?= htmlspecialchars($ym) ?>" pattern="\d{4}-\d{2}" required>
    </label>
    <button type="submit" class="btn btn-2" style="padding:8px 14px;font-size:12px">Analizar</button>
</form>

<?php if ($err): ?>
    <div class="box err">❌ Error: <?= htmlspecialchars($err) ?></div>
<?php elseif ($apply): ?>
    <div class="box ok">✅ <?= $aplicados ?> tareas reubicadas en sus fechas esperadas.</div>
<?php else: ?>
    <div class="box info">
        Mes objetivo: <strong><?= htmlspecialchars($ym) ?></strong>
        (<?= htmlspecialchars($primer) ?> → <?= htmlspecialchars($ultimo) ?>)<br>
        En este momento tiene <strong><?= $totalMes ?></strong> tareas planificadas (en <?= count($rowsMes) ?> días distintos).<br>
        Tras la corrección caerían <strong><?= $totalMes + $dentroDelMes ?></strong> tareas
        (<?= $dentroDelMes ?> más, recuperadas de meses posteriores).
    </div>
<?php endif; ?>

<?php if (!$apply && $dentroDelMes > 0): ?>
    <a class="btn" href="?<?= http_build_query(['apply'=>'1', 'ym'=>$ym]) ?>">
        ⚠ APLICAR RELLENO DE <?= htmlspecialchars($ym) ?> (<?= $dentroDelMes ?>)
    </a>
<?php elseif (!$apply && $dentroDelMes === 0): ?>
    <div class="box warn">
        No hay tareas que se puedan mover desde meses posteriores a este mes según su periodicidad.
        Probablemente el mes está vacío porque las últimas intervenciones de las tareas mensuales/quincenales caen lejos de él (por ejemplo, todas se marcaron a primeros del mes pasado y +30/+15 días las lleva al siguiente).
    </div>
<?php endif; ?>

<?php if (!empty($rowsMes)): ?>
    <h2>Tareas actualmente en <?= htmlspecialchars($ym) ?></h2>
    <table style="max-width:520px">
        <thead><tr><th>Día</th><th class="r">Nº tareas</th></tr></thead>
        <tbody>
        <?php foreach ($rowsMes as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['f']) ?></td>
                <td class="r"><?= $r['n'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if (!$apply && !empty($movimientos)): ?>
    <h2>Movimientos propuestos (<?= count($movimientos) ?>)</h2>
    <div style="max-height:540px;overflow-y:auto;border:1px solid #eef2f6;border-radius:6px">
        <table>
            <thead><tr>
                <th>Máquina</th>
                <th>Tarea</th>
                <th>Per</th>
                <th>Última</th>
                <th>Antes</th>
                <th>Esperada</th>
            </tr></thead>
            <tbody>
            <?php foreach (array_slice($movimientos, 0, 200) as $m): ?>
                <tr>
                    <td><?= htmlspecialchars($m['desc_maquina']) ?></td>
                    <td><?= htmlspecialchars($m['tarea']) ?></td>
                    <td><span class="pill"><?= htmlspecialchars($m['periodicidad']) ?></span></td>
                    <td><?= htmlspecialchars($m['ultima']) ?></td>
                    <td><?= htmlspecialchars($m['antes']) ?></td>
                    <td class="arrow">← <?= htmlspecialchars($m['esperada']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($movimientos) > 200): ?>
        <p style="font-size:12px;color:#5b6f86;font-style:italic;margin-top:6px">
            (Mostrando 200 de <?= count($movimientos) ?>.)
        </p>
    <?php endif; ?>
<?php endif; ?>

<p style="margin-top:24px">
    <a class="link" href="mant_calendario.php">← Calendario</a>
    · <a class="link" href="mant_calendario_recalcular.php">Recalcular días no hábiles</a>
    · <a class="link" href="mant_proximas.php">Próximas Revisiones</a>
</p>

</body></html>
