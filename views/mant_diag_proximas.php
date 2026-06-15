<?php
/**
 * Pantalla de diagnóstico para Próximas Revisiones.
 *
 * Abrirla así (cualquier máquina):
 *   http://<host>/PLAN_ATTAINMENT/views/mant_diag_proximas.php?cod=R2108
 *   http://<host>/PLAN_ATTAINMENT/views/mant_diag_proximas.php?cod=R2108&fdesde=2026-06-08&fhasta=2026-06-14
 *
 * Compara EN UN SOLO VISTAZO:
 *   1. Filas brutas en mant_plan para esa máquina (vivas).
 *   2. Marcas en mant_completions para esa máquina (por orden+tarea).
 *   3. Resultado del endpoint api/mant_proximas.php (listado / grid).
 *   4. Resultado del endpoint api/mant_proximas_tiempos.php (popup ⏱).
 *
 * Así se ve si la discrepancia viene de la BD, del filtro de marcadas,
 * del filtro de fechas, etc.
 */
// Mostrar TODOS los errores PHP (estamos en diagnóstico — útil ver fallos)
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';

Auth::requireLogin();

$cod    = (string)($_GET['cod']    ?? 'R2108');
$fdesde = (string)($_GET['fdesde'] ?? date('Y-m-d', strtotime('-90 days')));
$fhasta = (string)($_GET['fhasta'] ?? date('Y-m-d', strtotime('+30 days')));
$hoy    = date('Y-m-d');

// Envolvemos todas las consultas en try/catch para que cualquier error
// SQL salga visible en pantalla en vez de dejar la página en blanco.
$errorSql = null;
try {

// ── 1) mant_plan: filas vivas para la máquina ────────────────────────
$filasPlan = Db::pgFetchAll(
    "SELECT cod_maquina_mant, desc_maquina, orden, tarea, periodicidad,
            to_char(ultima_revision,  'YYYY-MM-DD') AS ultima,
            to_char(proxima_revision, 'YYYY-MM-DD') AS proxima,
            tiempo_estimado,
            alta_baja, activa,
            to_char(fecha_pausado,     'YYYY-MM-DD') AS fecha_pausado,
            to_char(fecha_bloqueo_ini, 'YYYY-MM-DD') AS bloq_ini,
            to_char(fecha_bloqueo_fin, 'YYYY-MM-DD') AS bloq_fin
       FROM mant_plan
      WHERE cod_maquina_mant = :c
      ORDER BY orden, tarea",
    [':c' => $cod]
);

// ── 2) mant_completions: marcas para esta máquina ────────────────────
// Columnas reales: orden, tarea, fecha_proxima_original, fecha_intervencion,
// tipo, operario. (NO se llama fecha_revision)
$filasComp = Db::pgFetchAll(
    "SELECT orden, tarea,
            to_char(fecha_proxima_original, 'YYYY-MM-DD') AS fecha_proxima_original,
            to_char(fecha_intervencion,     'YYYY-MM-DD') AS fecha_intervencion,
            tipo, operario
       FROM mant_completions
      WHERE cod_maquina_mant = :c
      ORDER BY COALESCE(fecha_intervencion, fecha_proxima_original) DESC
      LIMIT 50",
    [':c' => $cod]
);

// ── 3) Simulación de mant_proximas.php (mismo flujo que el endpoint) ─
$marcadasIdx = MaintenanceCompletionStore::loadIndexed();
$perIdx      = MaintenancePeriodicidadStore::loadIndexed();
$store       = MaintenancePlanStore::load();
$todas       = $store['proximas'] ?? [];

$listadoSimu = []; // lo que mostraría el grid
$descartadas = []; // y por qué se descartó cada una

foreach ($todas as $p) {
    if ((string)($p['cod_maquina_mant'] ?? '') !== $cod) continue;

    // Filtro pre — marcada por (orden, tarea, proxima original)
    $idMark = MaintenanceCompletionStore::buildId(
        (string)$p['orden'], (string)$p['tarea'], (string)($p['proxima_revision'] ?? '')
    );
    if (isset($marcadasIdx[$idMark])) {
        $descartadas[] = [
            'orden' => $p['orden'], 'tarea' => $p['tarea'],
            'px'    => $p['proxima_revision'],
            'motivo'=> 'Ya marcada (id=' . $idMark . ')'
        ];
        continue;
    }

    // Aplicar override de periodicidad
    $idOv = MaintenancePeriodicidadStore::buildId((string)$p['orden'], (string)$p['tarea']);
    $eff  = MaintenancePeriodicidadStore::applyOverride($p, $perIdx[$idOv] ?? null);
    $px   = (string)($eff['proxima_revision'] ?? '');

    if ($px === '') {
        $descartadas[] = ['orden'=>$p['orden'],'tarea'=>$p['tarea'],'px'=>$px,'motivo'=>'Sin proxima_revision'];
        continue;
    }

    // Filtro de fecha
    if ($px < $fdesde || $px > $fhasta) {
        $descartadas[] = ['orden'=>$p['orden'],'tarea'=>$p['tarea'],'px'=>$px,
                          'motivo'=>"Fuera de rango ($fdesde → $fhasta)"];
        continue;
    }

    $listadoSimu[] = [
        'orden' => $p['orden'], 'tarea' => $p['tarea'],
        'periodicidad' => $eff['periodicidad'], 'px' => $px,
        'desc_tarea' => substr((string)$eff['desc_tarea'], 0, 70),
    ];
}

// ── 4) Simulación de mant_proximas_tiempos.php (popup) ───────────────
$popupSimu = [];
foreach ($todas as $p) {
    if ((string)($p['cod_maquina_mant'] ?? '') !== $cod) continue;
    // No filtra marcadas, pero respeta el intervalo
    $idOv = MaintenancePeriodicidadStore::buildId((string)$p['orden'], (string)$p['tarea']);
    $eff  = MaintenancePeriodicidadStore::applyOverride($p, $perIdx[$idOv] ?? null);
    $px   = (string)($eff['proxima_revision'] ?? '');
    if ($px === '' || $px < $fdesde || $px > $fhasta) continue;
    $popupSimu[] = [
        'orden' => $p['orden'], 'tarea' => $p['tarea'],
        'periodicidad' => $eff['periodicidad'], 'px' => $px,
        'desc_tarea' => substr((string)$eff['desc_tarea'], 0, 70),
    ];
}

} catch (\Throwable $e) {
    $errorSql = $e->getMessage() . "\n" . $e->getTraceAsString();
    $filasPlan = $filasPlan ?? [];
    $filasComp = $filasComp ?? [];
    $listadoSimu = $listadoSimu ?? [];
    $descartadas = $descartadas ?? [];
    $popupSimu   = $popupSimu   ?? [];
}

function tabla(array $rows, array $cols, string $vacio = 'Sin datos'): string {
    if (empty($rows)) return '<p style="color:#777;font-style:italic">' . $vacio . '</p>';
    $h = '<table style="border-collapse:collapse;font-size:12px;width:100%"><thead style="background:#2d4d7a;color:#fff">';
    foreach ($cols as $c) $h .= '<th style="padding:6px 8px;text-align:left">' . htmlspecialchars($c) . '</th>';
    $h .= '</thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr style="border-bottom:1px solid #eef">';
        foreach ($cols as $c) {
            $v = $r[$c] ?? '';
            $h .= '<td style="padding:4px 8px">' . htmlspecialchars((string)$v) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table>';
}
?><!doctype html>
<html><head><meta charset="utf-8"><title>Diag · Próximas R<?= htmlspecialchars($cod) ?></title>
<style>
    body { font-family: Arial, sans-serif; padding: 20px; background:#f4f7fb; color:#1a2d4a; }
    h1   { font-size: 18px; color:#2d4d7a; margin:0 0 6px; }
    h2   { font-size: 14px; color:#2d4d7a; margin:18px 0 6px; border-bottom:2px solid #d5dfe8; padding-bottom:4px; }
    .filtros { background:#fff;border:1px solid #d5dfe8;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:13px }
    .filtros input { padding:4px 6px;border:1px solid #ccc;border-radius:3px;margin:0 4px 0 2px }
    .box-ok  { background:#e8f5e9;border-left:4px solid #1f8a3c;padding:8px 12px;margin:8px 0;font-size:13px;color:#0f5a26 }
    .box-bad { background:#fdecec;border-left:4px solid #c8102e;padding:8px 12px;margin:8px 0;font-size:13px;color:#8a0d22 }
    .num { color:#c8102e;font-weight:700 }
</style></head><body>

<h1>Diagnóstico Próximas Revisiones · máquina <?= htmlspecialchars($cod) ?></h1>
<form class="filtros">
    Máquina: <input name="cod" value="<?= htmlspecialchars($cod) ?>" size="12">
    Desde: <input name="fdesde" type="date" value="<?= htmlspecialchars($fdesde) ?>">
    Hasta: <input name="fhasta" type="date" value="<?= htmlspecialchars($fhasta) ?>">
    <button type="submit" style="background:#1a4a7a;color:#fff;border:0;padding:5px 12px;border-radius:4px;cursor:pointer">Aplicar</button>
</form>

<?php if ($errorSql): ?>
<div class="box-bad">
    <strong>❌ Error SQL/PHP durante el diagnóstico:</strong>
    <pre style="white-space:pre-wrap;margin:6px 0 0 0;font-size:11px;color:#8a0d22"><?= htmlspecialchars($errorSql) ?></pre>
</div>
<?php endif; ?>

<div class="<?= count($listadoSimu) === count($popupSimu) ? 'box-ok' : 'box-bad' ?>">
    <strong>Comparación:</strong>
    grid (mant_proximas.php) devolvería <span class="num"><?= count($listadoSimu) ?></span> fila(s) ·
    popup (mant_proximas_tiempos.php) devolvería <span class="num"><?= count($popupSimu) ?></span> fila(s) ·
    <?= count($listadoSimu) === count($popupSimu) ? '✓ Coinciden' : '✗ NO coinciden' ?>
</div>

<h2>1. mant_plan (todas las filas vivas para <?= htmlspecialchars($cod) ?>) — <?= count($filasPlan) ?> filas</h2>
<?= tabla($filasPlan, ['orden','tarea','periodicidad','desc_maquina','ultima','proxima','tiempo_estimado','alta_baja','activa','fecha_pausado','bloq_ini','bloq_fin']) ?>

<h2>2. mant_completions (últimas 50 marcas) — <?= count($filasComp) ?> filas</h2>
<?= tabla($filasComp, ['orden','tarea','fecha_proxima_original','fecha_intervencion','tipo','operario'],
        'Sin marcas registradas para esta máquina.') ?>

<h2>3. Lo que mostraría el GRID — <?= count($listadoSimu) ?> filas</h2>
<?= tabla($listadoSimu, ['orden','tarea','periodicidad','px','desc_tarea']) ?>

<h2>4. Tareas DESCARTADAS por el listado y motivo — <?= count($descartadas) ?> filas</h2>
<?= tabla($descartadas, ['orden','tarea','px','motivo'], 'Ninguna tarea descartada.') ?>

<h2>5. Lo que mostraría el POPUP / XLSX Resumen — <?= count($popupSimu) ?> filas</h2>
<?= tabla($popupSimu, ['orden','tarea','periodicidad','px','desc_tarea']) ?>

</body></html>
