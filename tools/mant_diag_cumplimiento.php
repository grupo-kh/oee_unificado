<?php
/**
 * Diagnóstico ESTRICTO del cumplimiento por mes.
 *
 * Replica EXACTAMENTE la fórmula de api/mant_cumplimiento_meses.php para
 * saber qué % devolverá el endpoint, y desglosa el numerador/denominador
 * con conteos por tipo y por familia (SEC vs NO-SEC).
 *
 * Útil para detectar si una corrección anterior realmente ha llegado a BD
 * o si está filtrada por loadAll() (que excluye marcas de máquinas sin
 * tareas ALTA+A en mant_plan).
 *
 * Uso:
 *   php tools/mant_diag_cumplimiento.php
 *   php tools/mant_diag_cumplimiento.php 2025-09
 *   php tools/mant_diag_cumplimiento.php 2025-09 2026-02
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';

$mesesArg = array_slice($argv, 1);
$mesesFoco = $mesesArg ?: ['2025-09', '2026-02'];

echo "Diagnóstico cumplimiento · foco en " . implode(', ', $mesesFoco) . PHP_EOL;
echo str_repeat('═', 80) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$isSec = function(string $desc): bool {
    $s = trim($desc);
    return preg_match('/^E66($|[^A-Za-z0-9])/i', $s)
        || preg_match('/^RACK[\s\-_]/i', $s)
        || preg_match('/^PLATAFORMA/i', $s);
};

// ── 1. Comprobar si loadAll() devuelve nuestras marcas ──
echo "1. Test loadAll() · ¿el store devuelve marcas para máquinas non-SEC pausadas?" . PHP_EOL;

// Una máquina test non-SEC: la primera que tenga ALTA+A
$test = Db::pgFetchOne("
    SELECT DISTINCT mm.cod_maquina_mant
      FROM mant_maquinas mm
      JOIN mant_plan p ON p.cod_maquina_mant = mm.cod_maquina_mant
     WHERE mm.desc_maquina NOT ILIKE 'RACK %'
       AND mm.desc_maquina NOT ILIKE 'PLATAFORMA%'
       AND mm.desc_maquina NOT ILIKE 'E66 %'
       AND mm.desc_maquina NOT ILIKE 'E66-%'
       AND mm.desc_maquina NOT ILIKE 'E66_%'
       AND mm.desc_maquina NOT ILIKE 'E66'
       AND COALESCE(p.activa, 'A') = 'A'
       AND COALESCE(p.alta_baja, 'ALTA') = 'ALTA'
     LIMIT 1
");
echo "  Una máquina NON-SEC con ALTA+A: " . ($test['cod_maquina_mant'] ?? '(ninguna)') . PHP_EOL;

// ── 2. Para cada mes foco, replicar la fórmula API ──
foreach ($mesesFoco as $mesFoco) {
    echo PHP_EOL . str_repeat('━', 80) . PHP_EOL;
    echo "Mes foco: $mesFoco" . PHP_EOL;
    echo str_repeat('━', 80) . PHP_EOL;

    // Usamos el MISMO loadAll que la API
    $items = MaintenanceCompletionStore::loadAll();
    echo "  loadAll() devolvió " . count($items) . " filas en total" . PHP_EOL;

    $stats = [
        'sec_comp'        => 0,
        'sec_nor'         => 0,   // no_realizada SEC (excluidas por API)
        'sec_rec'         => 0,   // recuperacion SEC (excluidas por API)
        'nsec_comp'       => 0,
        'nsec_nor'        => 0,
        'nsec_rec_in_mes' => 0,
        'denom'           => 0,
        'numer'           => 0,
    ];

    $muestraNor   = [];
    $muestraComp  = [];

    foreach ($items as $rec) {
        $tipo = (string)($rec['tipo'] ?? '');
        if ($tipo === '') {
            $tipo = empty($rec['fecha_intervencion']) ? 'no_realizada' : 'completada';
        }
        $desc = (string)($rec['desc_maquina'] ?? '');
        $isSecD = $isSec($desc);
        $fpo = (string)($rec['fecha_proxima_original'] ?? '');
        $fi  = (string)($rec['fecha_intervencion'] ?? '');

        if ($tipo === 'recuperacion') {
            if ($isSecD) {
                if (substr($fi, 0, 7) === $mesFoco) $stats['sec_rec']++;
                continue;
            }
            if ($fi === '' || substr($fi, 0, 7) !== $mesFoco) continue;
            $stats['nsec_rec_in_mes']++;
            $stats['numer']++;
        } else {
            if ($isSecD && $tipo === 'no_realizada') {
                if (substr($fpo, 0, 7) === $mesFoco) $stats['sec_nor']++;
                continue;
            }
            if ($fpo === '' || substr($fpo, 0, 7) !== $mesFoco) continue;

            if ($isSecD) {
                $stats['sec_comp']++;
            } else {
                if ($tipo === 'completada' || $fi !== '') $stats['nsec_comp']++;
                else $stats['nsec_nor']++;
            }

            $stats['denom']++;
            if ($tipo === 'completada' || $fi !== '') {
                $stats['numer']++;
            } else {
                if (count($muestraNor) < 5) {
                    $muestraNor[] = sprintf("%s · %s · fpo=%s · sec=%s",
                        (string)$rec['cod_maquina_mant'], $tipo, $fpo, $isSecD ? 'SI' : 'NO');
                }
            }
            if (($tipo === 'completada' || $fi !== '') && count($muestraComp) < 3) {
                $muestraComp[] = sprintf("%s · fpo=%s · fi=%s · sec=%s",
                    (string)$rec['cod_maquina_mant'], $fpo, $fi, $isSecD ? 'SI' : 'NO');
            }
        }
    }

    $pct = $stats['denom'] > 0 ? round($stats['numer']/$stats['denom']*100, 2) : 0;
    echo "  Desglose:" . PHP_EOL;
    echo "    SEC completadas (cuentan denom+numer):       " . $stats['sec_comp'] . PHP_EOL;
    echo "    SEC no_realizada (EXCLUIDAS por API):        " . $stats['sec_nor'] . PHP_EOL;
    echo "    SEC recuperacion (EXCLUIDAS por API):        " . $stats['sec_rec'] . PHP_EOL;
    echo "    NO-SEC completadas (denom+numer):            " . $stats['nsec_comp'] . PHP_EOL;
    echo "    NO-SEC no_realizada (denom, no numer):       " . $stats['nsec_nor'] . PHP_EOL;
    echo "    NO-SEC recuperacion en $mesFoco (numer):     " . $stats['nsec_rec_in_mes'] . PHP_EOL;
    echo PHP_EOL;
    echo "    denom = $stats[denom] · numer = $stats[numer] · pct = $pct%" . PHP_EOL;

    if ($muestraComp) {
        echo PHP_EOL . "  Muestra completadas en $mesFoco:" . PHP_EOL;
        foreach ($muestraComp as $s) echo "    · $s\n";
    }
    if ($muestraNor) {
        echo PHP_EOL . "  Muestra no_realizada en $mesFoco:" . PHP_EOL;
        foreach ($muestraNor as $s) echo "    · $s\n";
    } else {
        echo PHP_EOL . "  ❌ NO HAY no_realizadas NON-SEC con fpo en $mesFoco." . PHP_EOL;
        echo "     Esto explica que el % esté en 100%: nada baja el numerador." . PHP_EOL;
    }

    // Conteo directo en BD sin pasar por loadAll()
    $directo = Db::pgFetchOne("
        SELECT
          (SELECT COUNT(*) FROM mant_completions
            WHERE tipo='no_realizada' AND substr(fecha_proxima_original::text,1,7)=?) AS total_nor,
          (SELECT COUNT(*) FROM mant_completions
            WHERE tipo='no_realizada' AND substr(fecha_proxima_original::text,1,7)=?
              AND desc_maquina NOT ILIKE 'RACK %'
              AND desc_maquina NOT ILIKE 'PLATAFORMA%'
              AND desc_maquina NOT ILIKE 'E66 %'
              AND desc_maquina NOT ILIKE 'E66-%'
              AND desc_maquina NOT ILIKE 'E66_%'
              AND desc_maquina NOT ILIKE 'E66') AS total_nor_nsec
    ", [$mesFoco, $mesFoco]);
    echo PHP_EOL . "  Conteo directo BD (sin filtro loadAll):" . PHP_EOL;
    echo "    no_realizada en $mesFoco (todas las máquinas): " . $directo['total_nor'] . PHP_EOL;
    echo "    no_realizada en $mesFoco con desc NON-SEC   : " . $directo['total_nor_nsec'] . PHP_EOL;

    if ($directo['total_nor_nsec'] > 0 && $stats['nsec_nor'] === 0) {
        echo PHP_EOL . "  ⚠ Hay no_realizadas NON-SEC en BD pero loadAll() las filtra." . PHP_EOL;
        echo "    Causa probable: sus máquinas no tienen tareas ALTA+A en mant_plan." . PHP_EOL;
    }
}

echo PHP_EOL . str_repeat('═', 80) . PHP_EOL;
echo "Para arreglar:" . PHP_EOL;
echo "  - Si 'no hay no_realizadas NON-SEC en BD': re-lanzar mant_ajustar_cumplimiento.php --apply" . PHP_EOL;
echo "  - Si 'hay en BD pero loadAll las filtra': las máquinas elegidas no tienen tarea ALTA+A → revisar pool." . PHP_EOL;
