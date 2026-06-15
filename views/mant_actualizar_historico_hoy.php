<?php
/**
 * Actualización masiva de revisiones preventivas "al día".
 *
 * Para cada tarea activa cuya `proxima_revision <= hoy`:
 *   1. Inserta una marca en mant_completions con tipo='completada',
 *      fecha_intervencion = hoy, fecha_proxima_original = la que tenía.
 *   2. Avanza mant_plan.proxima_revision a (hoy + periodicidad) y
 *      mant_plan.ultima_revision = hoy.
 *
 * Las tareas ya marcadas para esa fecha próxima se ignoran (no se duplican).
 *
 * Uso: abrir en navegador
 *   http://<host>/PLAN_ATTAINMENT/views/mant_actualizar_historico_hoy.php
 *   Filtros opcionales por GET: ?cod_maquina_mant=R2108
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

Auth::requireLogin();

$apply  = isset($_GET['apply']) && $_GET['apply'] === '1';
$codMaq = trim((string)($_GET['cod_maquina_mant'] ?? ''));

/** Avanza una fecha base según la periodicidad. */
function siguientePxRev(string $base, string $per): string {
    $per = strtoupper(trim($per));
    $bs  = strtotime($base);
    switch ($per) {
        case 'DIARIO': case 'DIARIA':       return date('Y-m-d', strtotime('+1 day',    $bs));
        case 'SEMANAL':                     return date('Y-m-d', strtotime('+7 days',   $bs));
        case 'QUINCENAL':                   return date('Y-m-d', strtotime('+15 days',  $bs));
        case 'MENSUAL':                     return date('Y-m-d', strtotime('+1 month',  $bs));
        case 'BIMESTRAL': case 'BIMENSUAL': return date('Y-m-d', strtotime('+2 months', $bs));
        case 'TRIMESTRAL':                  return date('Y-m-d', strtotime('+3 months', $bs));
        case 'CUATRIMESTRAL':               return date('Y-m-d', strtotime('+4 months', $bs));
        case 'SEMESTRAL':                   return date('Y-m-d', strtotime('+6 months', $bs));
        case 'ANUAL':                       return date('Y-m-d', strtotime('+1 year',   $bs));
        case 'TRIANUAL':                    return date('Y-m-d', strtotime('+3 years',  $bs));
        default:                            return $base;
    }
}

// ── Lectura de candidatos ─────────────────────────────────────────────
$params = [];
$wMaq = '';
if ($codMaq !== '') {
    $wMaq = " AND cod_maquina_mant = :c";
    $params[':c'] = $codMaq;
}
$rows = Db::pgFetchAll(
    "SELECT cod_maquina_mant, desc_maquina, grupo, desc_grupo, periodicidad,
            orden, tarea, desc_tarea, tiempo_estimado,
            to_char(proxima_revision, 'YYYY-MM-DD') AS proxima_revision,
            to_char(ultima_revision,  'YYYY-MM-DD') AS ultima_revision
       FROM mant_plan
      WHERE fecha_pausado IS NULL
        AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
        AND COALESCE(activa,    'A')    = 'A'
        AND proxima_revision IS NOT NULL
        AND proxima_revision <= CURRENT_DATE
        $wMaq
      ORDER BY desc_maquina, proxima_revision, orden, tarea",
    $params
);

// Filtrar las ya marcadas (no las duplicamos)
$marcadasIdx = MaintenanceCompletionStore::loadIndexed();
$pendientes  = [];
$yaMarcadas  = 0;
foreach ($rows as $r) {
    $id = MaintenanceCompletionStore::buildId(
        (string)$r['orden'], (string)$r['tarea'], (string)$r['proxima_revision']
    );
    if (isset($marcadasIdx[$id])) { $yaMarcadas++; continue; }
    $pendientes[] = $r;
}

// Resumen por máquina
$byMaq = [];
foreach ($pendientes as $p) {
    $cod = $p['cod_maquina_mant'];
    if (!isset($byMaq[$cod])) {
        $byMaq[$cod] = ['cod'=>$cod, 'desc'=>$p['desc_maquina'], 'n'=>0];
    }
    $byMaq[$cod]['n']++;
}
uasort($byMaq, fn($a,$b)=>strcasecmp($a['desc'],$b['desc']));

// Operarios activos para asignar aleatoriamente
$operarios = MaintenanceCompletionStore::loadOperariosActivos();
$opCodes   = [];
foreach ($operarios as $o) {
    $c = (string)($o['codigo'] ?? $o['operario'] ?? '');
    if ($c !== '') $opCodes[] = $c;
}

// ── Aplicar ───────────────────────────────────────────────────────────
$err = ''; $nMarc = 0; $nUpd = 0; $hoy = date('Y-m-d');
if ($apply && !empty($pendientes)) {
    try {
        Db::pg()->beginTransaction();
        foreach ($pendientes as $p) {
            $opCode = !empty($opCodes) ? $opCodes[array_rand($opCodes)] : '';
            MaintenanceCompletionStore::add([
                'tipo'                    => 'completada',
                'orden'                   => $p['orden'],
                'tarea'                   => $p['tarea'],
                'cod_maquina_mant'        => $p['cod_maquina_mant'],
                'desc_maquina'            => $p['desc_maquina'],
                'grupo'                   => $p['grupo'],
                'desc_grupo'              => $p['desc_grupo'],
                'periodicidad'            => $p['periodicidad'],
                'desc_tarea'              => $p['desc_tarea'],
                'activa'                  => 'A',
                'fecha_proxima_original'  => $p['proxima_revision'],
                'fecha_intervencion'      => $hoy,
                'hora_inicio'             => MaintenanceCompletionStore::horaTurnoAleatoria(),
                'operario'                => $opCode,
                'observaciones'           => '',
                'marcada_por'             => Auth::user() ?? 'sistema',
            ]);
            $nMarc++;

            // Avanzar el plan: ultima = hoy, proxima = hoy + periodicidad
            $nuevaPx = siguientePxRev($hoy, (string)$p['periodicidad']);
            Db::pgExec(
                "UPDATE mant_plan
                    SET ultima_revision  = CURRENT_DATE,
                        proxima_revision = :px
                  WHERE cod_maquina_mant = :c AND orden = :o AND tarea = :t",
                [':px'=>$nuevaPx, ':c'=>$p['cod_maquina_mant'],
                 ':o'=>$p['orden'], ':t'=>$p['tarea']]
            );
            $nUpd++;
        }
        Db::pg()->commit();
    } catch (\Throwable $e) {
        if (Db::pg()->inTransaction()) Db::pg()->rollBack();
        $err = $e->getMessage();
    }
}

?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>Actualizar histórico al día</title>
<style>
    body { font-family: Arial, sans-serif; padding: 20px; background:#f4f7fb; color:#1a2d4a; }
    h1   { font-size: 18px; color:#2d4d7a; margin: 0 0 4px; }
    .sub { color:#5b6f86; font-size: 12px; margin-bottom: 18px; }
    .info { background:#fff8e6; border:1px solid #f0c674; color:#7a5b1b;
            padding:10px 14px; border-radius:6px; margin:10px 0; font-size:13px; }
    .ok   { background:#e8f5e9; border:1px solid #1f8a3c; color:#0f5a26;
            padding:10px 14px; border-radius:6px; margin:10px 0; font-weight:600; }
    .err  { background:#fdecec; border:1px solid #c8102e; color:#8a0d22;
            padding:10px 14px; border-radius:6px; margin:10px 0; font-weight:600; }
    .btn  { display:inline-block; background:#c8102e; color:#fff;
            padding:12px 22px; font-weight:700; font-size:14px;
            text-decoration:none; border-radius:6px;
            box-shadow:0 2px 6px rgba(200,16,46,.3); margin: 16px 0; }
    .btn:hover { background:#a00d24; }
    table { border-collapse:collapse; font-size:12px; width:100%; max-width:780px; }
    th { background:#2d4d7a; color:#fff; padding:6px 10px; text-align:left; }
    td { padding:4px 10px; border-bottom:1px solid #eef; }
    .filtros input { padding:4px 6px; border:1px solid #ccc; border-radius:3px; margin:0 4px; }
</style></head><body>

<h1>Actualizar histórico preventivo al día</h1>
<div class="sub">
    Marca como "completada hoy" cada tarea con próxima_revision en el pasado o
    hoy, y avanza la próxima revisión a HOY + periodicidad.
</div>

<form class="filtros" style="margin-bottom:14px">
    Máquina (opcional): <input name="cod_maquina_mant" value="<?= htmlspecialchars($codMaq) ?>" placeholder="Ej: R2108">
    <button type="submit" style="background:#1a4a7a;color:#fff;border:0;padding:5px 12px;border-radius:4px;cursor:pointer">Filtrar</button>
</form>

<?php if ($err): ?>
    <div class="err">❌ Error: <?= htmlspecialchars($err) ?></div>
<?php elseif ($apply): ?>
    <div class="ok">✅ Actualizado: <?= $nMarc ?> revisiones insertadas, <?= $nUpd ?> tareas avanzadas.</div>
<?php else: ?>
    <div class="info">
        Se han encontrado <strong><?= count($pendientes) ?></strong> tareas pendientes en
        <strong><?= count($byMaq) ?></strong> máquinas
        <?php if ($yaMarcadas > 0): ?>(otras <?= $yaMarcadas ?> ya estaban marcadas y se ignoran)<?php endif; ?>.
        Revisa el listado abajo y pulsa el botón rojo si quieres aplicar.
    </div>
    <?php if (!empty($pendientes)): ?>
        <a class="btn" href="?<?= http_build_query(['apply'=>'1','cod_maquina_mant'=>$codMaq]) ?>">
            ⚠ MARCAR LAS <?= count($pendientes) ?> COMO HECHAS HOY
        </a>
    <?php endif; ?>
<?php endif; ?>

<h2 style="font-size:14px;color:#2d4d7a;margin:14px 0 6px">Resumen por máquina (<?= count($byMaq) ?>)</h2>
<?php if (empty($byMaq)): ?>
    <p style="font-style:italic;color:#777">No hay tareas pendientes con los filtros indicados.</p>
<?php else: ?>
<table>
    <thead><tr><th>Máquina</th><th>Código</th><th style="text-align:right">Tareas a marcar</th></tr></thead>
    <tbody>
    <?php foreach ($byMaq as $m): ?>
        <tr>
            <td><?= htmlspecialchars($m['desc']) ?></td>
            <td><?= htmlspecialchars($m['cod']) ?></td>
            <td style="text-align:right;font-weight:700"><?= $m['n'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if ($apply && !$err): ?>
    <p style="margin-top:18px">
        <a href="?<?= htmlspecialchars(http_build_query(['cod_maquina_mant'=>$codMaq])) ?>"
           style="color:#2d4d7a">↻ Recargar</a> ·
        <a href="mant_historico.php" style="color:#2d4d7a">Ir a Histórico</a> ·
        <a href="mant_proximas.php" style="color:#2d4d7a">Ir a Próximas Revisiones</a>
    </p>
    <div class="info" style="margin-top:14px">
        Pulsa <strong>Ctrl + F5</strong> en cualquier pestaña abierta para forzar recarga sin caché.
    </div>
<?php endif; ?>

</body></html>
