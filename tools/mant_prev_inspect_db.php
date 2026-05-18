<?php
/**
 * tools/mant_prev_inspect_db.php
 * --------------------------------------------------------------
 * Diagnostico: lista que hay en mant_plan / mant_maquinas, separa lo
 * que MI condicion de Secuencia esta matcheando vs lo que NO.
 * Pensado para detectar por que faltan filas (p. ej. RACKS).
 *
 * URL: http://localhost/PLAN_ATTAINMENT/views/mant_prev_inspect_db.php
 * --------------------------------------------------------------
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../lib/Db.php';

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=UTF-8');

$seqCondition = "(
    UPPER(TRIM(p.cod_maquina_mant)) IN ('E66','RACKS','PLATAFORMAS')
 OR UPPER(TRIM(p.cod_maquina_mant)) LIKE 'E66%'
 OR UPPER(TRIM(p.cod_maquina_mant)) LIKE 'RACKS%'
 OR UPPER(TRIM(p.cod_maquina_mant)) LIKE 'PLATAFORMAS%'
 OR UPPER(TRIM(COALESCE(p.grupo,''))) IN ('E66','RACKS','PLATAFORMAS','SECUENCIA')
 OR UPPER(TRIM(COALESCE(p.desc_grupo,''))) ~ '\\m(E66|RACKS|PLATAFORMAS|SECUENCIA)\\M'
)";

echo "=== INSPECCION BD MANTENIMIENTO ===\n\n";

echo "--- mant_plan: cod_maquina_mant + grupo + desc_grupo distintos (top 60) ---\n";
$rows = Db::pgFetchAll("
    SELECT cod_maquina_mant,
           COALESCE(grupo,'') AS grupo,
           COALESCE(desc_grupo,'') AS desc_grupo,
           COUNT(*) AS n_tareas
      FROM mant_plan
     GROUP BY cod_maquina_mant, grupo, desc_grupo
     ORDER BY cod_maquina_mant, grupo
");
echo sprintf("%-30s %-25s %-40s %s\n", 'cod_maquina_mant', 'grupo', 'desc_grupo', 'n_tareas');
echo str_repeat('-', 110) . "\n";
$shown = 0;
foreach ($rows as $r) {
    echo sprintf("%-30s %-25s %-40s %s\n",
        substr((string)$r['cod_maquina_mant'], 0, 30),
        substr((string)$r['grupo'], 0, 25),
        substr((string)$r['desc_grupo'], 0, 40),
        $r['n_tareas']);
    $shown++;
    if ($shown >= 60) { echo "(...truncado a 60 filas)\n"; break; }
}
echo "\n";

echo "--- mant_plan: filas que YA matchean mi condicion de Secuencia ---\n";
$matchSeq = Db::pgFetchAll("
    SELECT cod_maquina_mant,
           COALESCE(grupo,'') AS grupo,
           COALESCE(desc_grupo,'') AS desc_grupo,
           COUNT(*) AS n_tareas
      FROM mant_plan p
     WHERE {$seqCondition}
     GROUP BY cod_maquina_mant, grupo, desc_grupo
     ORDER BY cod_maquina_mant, grupo
");
echo "(" . count($matchSeq) . " grupos)\n";
foreach ($matchSeq as $r) {
    echo sprintf("  ✓ %-30s | %-20s | %-30s | %s\n",
        $r['cod_maquina_mant'], $r['grupo'], substr((string)$r['desc_grupo'],0,30), $r['n_tareas']);
}
echo "\n";

echo "--- Buscar 'RACK' en cualquier columna ---\n";
$rack = Db::pgFetchAll("
    SELECT cod_maquina_mant,
           COALESCE(grupo,'') AS grupo,
           COALESCE(desc_grupo,'') AS desc_grupo,
           COALESCE(desc_maquina,'') AS desc_maquina,
           COUNT(*) AS n_tareas
      FROM mant_plan p
     WHERE UPPER(p.cod_maquina_mant) LIKE '%RACK%'
        OR UPPER(COALESCE(p.grupo,'')) LIKE '%RACK%'
        OR UPPER(COALESCE(p.desc_grupo,'')) LIKE '%RACK%'
        OR UPPER(COALESCE(p.desc_maquina,'')) LIKE '%RACK%'
     GROUP BY cod_maquina_mant, grupo, desc_grupo, desc_maquina
     ORDER BY cod_maquina_mant
");
echo "(" . count($rack) . " grupos con 'RACK' en algun lado)\n";
foreach ($rack as $r) {
    echo sprintf("  · cod=%-25s grupo=%-15s desc_grupo=%-25s desc_maq=%-30s tareas=%s\n",
        $r['cod_maquina_mant'], $r['grupo'],
        substr((string)$r['desc_grupo'],0,25),
        substr((string)$r['desc_maquina'],0,30),
        $r['n_tareas']);
}
echo "\n";

echo "--- Buscar 'PLATAFORMA' en cualquier columna ---\n";
$plat = Db::pgFetchAll("
    SELECT cod_maquina_mant,
           COALESCE(grupo,'') AS grupo,
           COALESCE(desc_grupo,'') AS desc_grupo,
           COALESCE(desc_maquina,'') AS desc_maquina,
           COUNT(*) AS n_tareas
      FROM mant_plan p
     WHERE UPPER(p.cod_maquina_mant)            LIKE '%PLATAFORM%'
        OR UPPER(COALESCE(p.grupo,''))           LIKE '%PLATAFORM%'
        OR UPPER(COALESCE(p.desc_grupo,''))      LIKE '%PLATAFORM%'
        OR UPPER(COALESCE(p.desc_maquina,''))    LIKE '%PLATAFORM%'
     GROUP BY cod_maquina_mant, grupo, desc_grupo, desc_maquina
     ORDER BY cod_maquina_mant
");
echo "(" . count($plat) . " grupos con 'PLATAFORM' en algun lado)\n";
foreach ($plat as $r) {
    echo sprintf("  · cod=%-25s grupo=%-15s desc_grupo=%-25s desc_maq=%-30s tareas=%s\n",
        $r['cod_maquina_mant'], $r['grupo'],
        substr((string)$r['desc_grupo'],0,25),
        substr((string)$r['desc_maquina'],0,30),
        $r['n_tareas']);
}
echo "\n";

echo "--- Buscar 'E66' o '666' en cualquier columna ---\n";
$e66 = Db::pgFetchAll("
    SELECT cod_maquina_mant,
           COALESCE(grupo,'') AS grupo,
           COALESCE(desc_grupo,'') AS desc_grupo,
           COALESCE(desc_maquina,'') AS desc_maquina,
           COUNT(*) AS n_tareas
      FROM mant_plan p
     WHERE UPPER(p.cod_maquina_mant)         LIKE '%E66%'
        OR UPPER(COALESCE(p.grupo,''))        LIKE '%E66%'
        OR UPPER(COALESCE(p.desc_grupo,''))   LIKE '%E66%'
        OR UPPER(COALESCE(p.desc_maquina,'')) LIKE '%E66%'
     GROUP BY cod_maquina_mant, grupo, desc_grupo, desc_maquina
     ORDER BY cod_maquina_mant
");
echo "(" . count($e66) . " grupos con 'E66')\n";
foreach ($e66 as $r) {
    echo sprintf("  · cod=%-25s grupo=%-15s desc_grupo=%-25s desc_maq=%-30s tareas=%s\n",
        $r['cod_maquina_mant'], $r['grupo'],
        substr((string)$r['desc_grupo'],0,25),
        substr((string)$r['desc_maquina'],0,30),
        $r['n_tareas']);
}
echo "\n";

echo "--- mant_maquinas (catalogo completo, top 80) ---\n";
$maq = Db::pgFetchAll("
    SELECT cod_maquina_mant, desc_maquina, is_user_added
      FROM mant_maquinas
     ORDER BY cod_maquina_mant
     LIMIT 80
");
echo "(" . count($maq) . " filas mostradas)\n";
foreach ($maq as $r) {
    $flag = $r['is_user_added'] ? '[USER]' : '[XLSX]';
    echo sprintf("  %s %-30s | %s\n", $flag, $r['cod_maquina_mant'],
        substr((string)$r['desc_maquina'], 0, 80));
}
echo "\n";

echo "=== FIN ===\n";
