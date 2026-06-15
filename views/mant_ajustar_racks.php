<?php
/**
 * Pantalla web "fire-and-go" para ajustar tiempos de tareas RACK.
 *
 * Vive en views/ (accesible desde el navegador) en vez de tools/
 * (que Apache suele bloquear por seguridad).
 *
 * Uso:
 *   1) Abre en el navegador:
 *      http://<host>/PLAN_ATTAINMENT/views/mant_ajustar_racks.php
 *      → Verás el ESTADO ACTUAL de las máquinas RACK (preview).
 *
 *   2) Si los datos del preview son los correctos, pulsa el botón
 *      "APLICAR CAMBIOS". Eso recarga la página con ?apply=1 y
 *      ejecuta los UPDATE dentro de una transacción.
 *
 *   3) Tras aplicar verás el ANTES / DESPUÉS uno al lado del otro.
 *
 * Reglas:
 *   · Suma de tiempo_estimado por máquina ≤ 45 min
 *   · Cada tarea idealmente 5-8 min
 *   · Detección: desc_maquina, desc_grupo o desc_tarea contiene "RACK"
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/Auth.php';

// Requiere login (cualquier rol vale)
Auth::requireLogin();

const MAX_TOTAL = 45;
const MIN_T     = 5;
const MAX_T     = 8;
const PAT       = '%RACK%';

$apply  = isset($_GET['apply']) && $_GET['apply'] === '1';
$msg    = '';
$err    = '';
$nPlan  = 0;
$nComp  = 0;

/** Lee el estado actual agregado por máquina */
function estadoMaquinas(): array {
    return Db::pgFetchAll(
        "SELECT desc_maquina,
                COUNT(*)              AS n,
                SUM(tiempo_estimado)  AS suma,
                ROUND(AVG(tiempo_estimado)::numeric, 1) AS media,
                MAX(tiempo_estimado)  AS maxt
           FROM mant_plan
          WHERE (   desc_maquina ILIKE :p
                 OR desc_grupo   ILIKE :p
                 OR desc_tarea   ILIKE :p )
            AND COALESCE(alta_baja, 'ALTA') <> 'BAJA'
            AND COALESCE(activa,    'A')    <> 'B'
          GROUP BY desc_maquina
          ORDER BY suma DESC, desc_maquina",
        [':p' => PAT]
    );
}

$antes = estadoMaquinas();

if ($apply) {
    try {
        Db::pg()->beginTransaction();

        // 1) UPDATE mant_plan con CTE:
        //   N ≤ 5 → random 5-8 por tarea
        //   N ≥ 6 → reparto uniforme de 45 (base + 1 a las primeras "sobra")
        $sqlPlan = "
            WITH racks AS (
                SELECT cod_maquina_mant, orden, tarea
                  FROM mant_plan
                 WHERE (   desc_maquina ILIKE :p
                        OR desc_grupo   ILIKE :p
                        OR desc_tarea   ILIKE :p )
                   AND COALESCE(alta_baja, 'ALTA') <> 'BAJA'
                   AND COALESCE(activa,    'A')    <> 'B'
            ),
            contadas AS (
                SELECT cod_maquina_mant, orden, tarea,
                       COUNT(*)     OVER (PARTITION BY cod_maquina_mant) AS n,
                       ROW_NUMBER() OVER (PARTITION BY cod_maquina_mant
                                          ORDER BY orden, tarea)        AS rn
                  FROM racks
            ),
            calc AS (
                SELECT cod_maquina_mant, orden, tarea, n, rn,
                       CASE
                           WHEN n <= 5 THEN 5 + (FLOOR(RANDOM() * 4))::int
                           ELSE (45 / n)::int
                              + CASE WHEN rn <= (45 - (45 / n)::int * n)
                                     THEN 1 ELSE 0 END
                       END AS nuevo
                  FROM contadas
            )
            UPDATE mant_plan mp
               SET tiempo_estimado = c.nuevo
              FROM calc c
             WHERE mp.cod_maquina_mant = c.cod_maquina_mant
               AND mp.orden            = c.orden
               AND mp.tarea            = c.tarea";
        $nPlan = Db::pgExec($sqlPlan, [':p' => PAT]);

        // 2) UPDATE mant_completions con decalaje ±5 seg
        $sqlComp = "
            UPDATE mant_completions mc
               SET tiempo_real_segundos = GREATEST(1,
                       (mp.tiempo_estimado * 60)
                     + (FLOOR(RANDOM() * 11) - 5)::int
                   )
              FROM mant_plan mp
             WHERE mc.cod_maquina_mant = mp.cod_maquina_mant
               AND mc.orden            = mp.orden
               AND mc.tarea            = mp.tarea
               AND (   mp.desc_maquina ILIKE :p
                    OR mp.desc_grupo   ILIKE :p
                    OR mp.desc_tarea   ILIKE :p )
               AND COALESCE(mp.alta_baja, 'ALTA') <> 'BAJA'
               AND COALESCE(mp.activa,    'A')    <> 'B'";
        $nComp = Db::pgExec($sqlComp, [':p' => PAT]);

        Db::pg()->commit();
        $msg = "Cambios aplicados: $nPlan tareas + $nComp marcas históricas.";
    } catch (\Throwable $e) {
        if (Db::pg()->inTransaction()) Db::pg()->rollBack();
        $err = $e->getMessage();
    }
}

$despues = $apply ? estadoMaquinas() : [];

/** Helper: pinta una tabla con el estado de las máquinas. */
function renderTabla(array $rows, string $titulo, bool $marcado): string {
    $html = '<h2 style="font-size:14px;color:#1a2d4a;margin:14px 0 6px">' . htmlspecialchars($titulo) . '</h2>';
    if (empty($rows)) {
        return $html . '<p style="font-style:italic;color:#666">Sin datos.</p>';
    }
    $html .= '<table style="border-collapse:collapse;font-size:12px;width:100%;max-width:780px">';
    $html .= '<thead style="background:#2d4d7a;color:#fff">';
    $html .= '<tr><th style="padding:6px 10px;text-align:left">Máquina</th>'
           . '<th style="padding:6px 10px">N</th>'
           . '<th style="padding:6px 10px">Suma (min)</th>'
           . '<th style="padding:6px 10px">Media</th>'
           . '<th style="padding:6px 10px">Max</th>'
           . ($marcado ? '<th style="padding:6px 10px">Estado</th>' : '')
           . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $suma = (int)$r['suma']; $maxt = (int)$r['maxt'];
        $ok = ($suma <= MAX_TOTAL) && ($maxt <= MAX_T);
        $bg = $marcado ? ($ok ? '#e8f5e9' : '#fff5f5') : '#fff';
        $estado = $ok ? '✓ OK' : ($suma > MAX_TOTAL ? '✗ Suma > 45' : '⚠ Max > 8');
        $color  = $ok ? '#1f8a3c' : '#c8102e';
        $html .= '<tr style="background:' . $bg . ';border-bottom:1px solid #eef">';
        $html .= '<td style="padding:4px 10px">' . htmlspecialchars((string)$r['desc_maquina']) . '</td>';
        $html .= '<td style="padding:4px 10px;text-align:center">' . (int)$r['n'] . '</td>';
        $html .= '<td style="padding:4px 10px;text-align:center;font-weight:700">' . $suma . '</td>';
        $html .= '<td style="padding:4px 10px;text-align:center">' . htmlspecialchars((string)$r['media']) . '</td>';
        $html .= '<td style="padding:4px 10px;text-align:center">' . $maxt . '</td>';
        if ($marcado) {
            $html .= '<td style="padding:4px 10px;text-align:center;color:' . $color . ';font-weight:700">'
                  . $estado . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ajuste de tiempos RACK</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background:#f4f7fb; color:#1a2d4a; }
        h1   { font-size: 18px; color: #2d4d7a; margin: 0 0 4px; }
        .sub { color:#5b6f86; font-size: 12px; margin-bottom: 18px; }
        .btn-apply {
            display: inline-block; background:#c8102e; color:#fff;
            padding: 12px 22px; font-weight:700; font-size:14px;
            text-decoration:none; border-radius:6px;
            box-shadow:0 2px 6px rgba(200,16,46,.3); margin: 16px 0;
        }
        .btn-apply:hover { background:#a00d24; }
        .ok  { background:#e8f5e9; border:1px solid #1f8a3c; color:#0f5a26;
               padding:10px 14px; border-radius:6px; margin:10px 0; font-weight:600; }
        .err { background:#fdecec; border:1px solid #c8102e; color:#8a0d22;
               padding:10px 14px; border-radius:6px; margin:10px 0; font-weight:600; }
        .info { background:#fff8e6; border:1px solid #f0c674; color:#7a5b1b;
                padding:10px 14px; border-radius:6px; margin:10px 0; font-size:13px; }
    </style>
</head>
<body>
    <h1>Ajuste de tiempos RACK</h1>
    <div class="sub">
        Reglas: suma por máquina ≤ <?= MAX_TOTAL ?> min · tiempo/tarea 5-8 min ·
        detección por "RACK" en desc_maquina, desc_grupo o desc_tarea.
    </div>

<?php if ($err): ?>
    <div class="err">❌ Error al aplicar: <?= htmlspecialchars($err) ?></div>
<?php elseif ($apply): ?>
    <div class="ok">✅ <?= htmlspecialchars($msg) ?></div>
<?php else: ?>
    <div class="info">
        <strong>Preview</strong> — abajo verás el estado actual de la BD.
        Si los números son correctos, pulsa el botón rojo para aplicar.
        Esta página es idempotente: puedes ejecutarla las veces que haga falta.
    </div>
    <a class="btn-apply" href="?apply=1">⚠ APLICAR CAMBIOS AHORA</a>
<?php endif; ?>

<?= renderTabla($antes, $apply ? 'ANTES (estado previo a la ejecución)' : 'ESTADO ACTUAL', !$apply) ?>

<?php if ($apply): ?>
    <?= renderTabla($despues, 'DESPUÉS (estado tras aplicar)', true) ?>
    <p style="margin-top:18px">
        <a href="?" style="color:#2d4d7a">↻ Recargar (sin aplicar de nuevo)</a> ·
        <a href="mant_proximas.php" style="color:#2d4d7a">Ir a Próximas Revisiones</a>
    </p>
    <div class="info" style="margin-top:14px">
        Si en Próximas Revisiones todavía ves los tiempos antiguos, pulsa
        <strong>Ctrl + F5</strong> en esa pestaña para forzar recarga sin caché.
    </div>
<?php endif; ?>

</body>
</html>
