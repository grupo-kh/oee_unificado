<?php
/**
 * Diagnóstico rápido de la máquina "418 - Tice K0" (orden 1257).
 * Muestra: si existe en mant_maquinas, qué tareas tiene en mant_plan,
 * y cuántas marcas hay en mant_completions.
 *
 * Uso:
 *   php tools/mant_diag_tice_k0.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

echo "Diagnóstico · 418 - Tice K0 / orden 1257" . PHP_EOL;
echo str_repeat('═', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// 1. mant_maquinas
echo PHP_EOL . "1) mant_maquinas que contengan 'TICE' o '418':" . PHP_EOL;
$rows = Db::pgFetchAll("
    SELECT cod_maquina_mant, desc_maquina FROM mant_maquinas
     WHERE cod_maquina_mant ILIKE '%TICE%'
        OR cod_maquina_mant ILIKE '%418%'
        OR desc_maquina ILIKE '%TICE%'
        OR desc_maquina ILIKE '%418%'
     ORDER BY cod_maquina_mant
");
if (!$rows) echo "  (ninguna)" . PHP_EOL;
foreach ($rows as $r) printf("  · cod='%s' · desc='%s'\n", $r['cod_maquina_mant'], $r['desc_maquina']);

// 2. mant_plan con orden=1257
echo PHP_EOL . "2) mant_plan con orden=1257:" . PHP_EOL;
$plan = Db::pgFetchAll("
    SELECT orden, tarea, cod_maquina_mant, periodicidad, activa,
           alta_baja, fecha_pausado,
           to_char(ultima_revision,'YYYY-MM-DD') AS ultima,
           to_char(proxima_revision,'YYYY-MM-DD') AS proxima
      FROM mant_plan WHERE orden = '1257'
");
if (!$plan) echo "  (ninguna)" . PHP_EOL;
foreach ($plan as $r) {
    printf("  · tarea=%s · cod=%s · per=%s · activa=%s · alta=%s · pausa=%s · ult=%s · prox=%s\n",
        $r['tarea'], $r['cod_maquina_mant'], $r['periodicidad'],
        $r['activa'] ?? '?', $r['alta_baja'] ?? '?',
        $r['fecha_pausado'] ?? '-',
        $r['ultima'] ?? '-', $r['proxima'] ?? '-');
}

// 3. mant_completions
echo PHP_EOL . "3) mant_completions con orden=1257:" . PHP_EOL;
$n = (int) (Db::pgFetchOne("SELECT COUNT(*) AS n FROM mant_completions WHERE orden = '1257'")['n'] ?? 0);
echo "  Total marcas: $n" . PHP_EOL;
if ($n > 0) {
    $rows3 = Db::pgFetchAll("
        SELECT tarea, tipo,
               to_char(fecha_proxima_original,'YYYY-MM-DD') AS fpo,
               to_char(fecha_intervencion,'YYYY-MM-DD') AS fi,
               operario, marcada_por
          FROM mant_completions WHERE orden = '1257'
         ORDER BY fecha_proxima_original DESC LIMIT 8
    ");
    echo "  Últimas 8 marcas:" . PHP_EOL;
    foreach ($rows3 as $r) {
        printf("    · tarea=%s · %s · fpo=%s · fi=%s · op=%s · por=%s\n",
            $r['tarea'], $r['tipo'], $r['fpo'], $r['fi'] ?? '-',
            $r['operario'] ?? '-', $r['marcada_por'] ?? '-');
    }
}

// 4. ¿Pasa el filtro de listMaquinasConContador?
echo PHP_EOL . "4) ¿Aparecería en mant_acciones (listMaquinasConContador)?" . PHP_EOL;
$f = Db::pgFetchOne("
    SELECT m.cod_maquina_mant, m.desc_maquina,
           COALESCE(t.task_count, 0) AS task_count,
           m.is_user_added
      FROM mant_maquinas m
      LEFT JOIN (
            SELECT cod_maquina_mant, COUNT(*) AS task_count
              FROM mant_plan
             WHERE COALESCE(alta_baja, 'ALTA') = 'ALTA'
               AND COALESCE(activa,    'A')    = 'A'
             GROUP BY cod_maquina_mant
           ) t ON t.cod_maquina_mant = m.cod_maquina_mant
     WHERE m.cod_maquina_mant = '418 - Tice K0'
       AND (m.is_user_added = TRUE OR EXISTS (
               SELECT 1 FROM mant_plan p
                WHERE p.cod_maquina_mant = m.cod_maquina_mant
                  AND COALESCE(p.alta_baja, 'ALTA') = 'ALTA'
                  AND COALESCE(p.activa,    'A')    = 'A'
           ))
");
if (!$f) {
    echo "  ❌ NO pasa el filtro. Razones posibles:" . PHP_EOL;
    echo "     - La máquina no existe en mant_maquinas" . PHP_EOL;
    echo "     - O no tiene tareas con alta_baja='ALTA' y activa='A'" . PHP_EOL;
} else {
    printf("  ✓ Pasa filtro · cod=%s · task_count=%d · user_added=%s\n",
        $f['cod_maquina_mant'], (int)$f['task_count'], $f['is_user_added'] ? 'SÍ' : 'NO');
}
