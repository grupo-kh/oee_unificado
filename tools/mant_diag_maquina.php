<?php
/**
 * Diagnóstico: muestra qué tareas tiene una máquina en mant_plan y por
 * qué algunas pueden no aparecer en la vista Acciones por máquina.
 *
 * Uso:
 *   php tools/mant_diag_maquina.php "ETIQUETADORA 01"
 *   php tools/mant_diag_maquina.php --cod=12001
 *
 * Lista todas las tareas (vivas y filtradas), señalando el motivo del
 * filtro: BAJA, pausada, bloqueada o activa OK.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$busqueda = '';
$busquedaCod = '';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--cod=(.+)$/', $a, $m)) $busquedaCod = $m[1];
    elseif (substr($a, 0, 2) !== '--') $busqueda = $a;
}

if ($busqueda === '' && $busquedaCod === '') {
    fwrite(STDERR, "Uso:\n");
    fwrite(STDERR, "  php tools/mant_diag_maquina.php \"ETIQUETADORA 01\"\n");
    fwrite(STDERR, "  php tools/mant_diag_maquina.php --cod=12001\n");
    exit(1);
}

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// Buscar máquinas que coincidan (por desc o cod)
$where  = $busquedaCod !== ''
    ? "cod_maquina_mant = :q"
    : "UPPER(desc_maquina) LIKE UPPER(:q)";
$param  = $busquedaCod !== '' ? $busquedaCod : '%' . $busqueda . '%';

$maquinas = Db::pgFetchAll("
    SELECT DISTINCT cod_maquina_mant, desc_maquina
      FROM mant_plan
     WHERE $where
     ORDER BY desc_maquina
", [':q' => $param]);

if (!$maquinas) {
    echo "No hay máquinas con ese criterio en mant_plan.\n";
    echo "Prueba también en el catálogo:\n";
    $cat = Db::pgFetchAll("SELECT cod_maquina_mant, desc_maquina FROM mant_maquinas WHERE $where LIMIT 10", [':q' => $param]);
    foreach ($cat as $c) printf("  - %s (%s)\n", $c['desc_maquina'], $c['cod_maquina_mant']);
    exit(0);
}

foreach ($maquinas as $m) {
    printf("\n┌─ %s (cod=%s)\n", $m['desc_maquina'], $m['cod_maquina_mant']);
    $rows = Db::pgFetchAll("
        SELECT id, orden, tarea, periodicidad, desc_tarea,
               COALESCE(activa, 'A')    AS activa,
               COALESCE(alta_baja, 'ALTA') AS alta_baja,
               to_char(fecha_pausado,    'YYYY-MM-DD') AS fecha_pausado,
               to_char(fecha_bloqueo_ini,'YYYY-MM-DD') AS bloq_ini,
               to_char(fecha_bloqueo_fin,'YYYY-MM-DD') AS bloq_fin,
               to_char(ultima_revision,  'YYYY-MM-DD') AS ult,
               to_char(proxima_revision, 'YYYY-MM-DD') AS prox
          FROM mant_plan
         WHERE cod_maquina_mant = :c
         ORDER BY tarea
    ", [':c' => $m['cod_maquina_mant']]);

    printf("│  Total tareas en mant_plan: %d\n", count($rows));
    $hoy = date('Y-m-d');
    $vivas = 0; $baja = 0; $pausadas = 0; $bloqueadas = 0; $inactivas = 0;
    foreach ($rows as $r) {
        $estado = 'OK';
        $cls = '✓';
        if ($r['alta_baja'] === 'BAJA') { $estado = 'BAJA'; $cls = '✗'; $baja++; }
        elseif ($r['activa'] === 'B')   { $estado = 'INACTIVA (activa=B)'; $cls = '✗'; $inactivas++; }
        elseif ($r['fecha_pausado'])    { $estado = 'PAUSADA desde ' . $r['fecha_pausado']; $cls = '⏸'; $pausadas++; }
        elseif ($r['bloq_ini'] && $r['bloq_fin'] && $hoy >= $r['bloq_ini'] && $hoy <= $r['bloq_fin']) {
            $estado = "BLOQUEADA ({$r['bloq_ini']} → {$r['bloq_fin']})";
            $cls = '🔒'; $bloqueadas++;
        }
        else $vivas++;
        printf("│   %s  tarea=%s  per=%s  %s\n",
            $cls, $r['tarea'], $r['periodicidad'], $estado);
        printf("│        desc: %s\n", mb_substr((string)$r['desc_tarea'], 0, 80));
    }
    printf("│\n│  RESUMEN — vivas:%d  baja:%d  inactivas:%d  pausadas:%d  bloqueadas:%d\n",
        $vivas, $baja, $inactivas, $pausadas, $bloqueadas);
    printf("└─ La vista 'Acciones por máquina' muestra todas las tareas (vivas + filtradas), pero los modales/plan/cumplimiento solo usan las VIVAS.\n");
}
