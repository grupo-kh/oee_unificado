<?php
/**
 * Renumera las 6 máquinas RACK PUERTAS TA TRA LH al nuevo orden indicado
 * por el usuario, manteniendo la asignación por POSICIÓN:
 *
 *   posición 1ª: era "01" → ahora "09"   (orden 1126)
 *   posición 2ª: era "02" → ahora "02"   (orden 1127) · sin cambio efectivo
 *   posición 3ª: era "03" → ahora "08"   (orden 1128)
 *   posición 4ª: era "06" → ahora "10"   (orden 1131)
 *   posición 5ª: era "07" → ahora "06"   (orden 1132)
 *   posición 6ª: era "08" → ahora "05"   (orden 1133)
 *
 * El número de orden NO cambia (sigue siendo PK de mant_plan). Lo que
 * cambia es `cod_maquina_mant` y `desc_maquina` en las tres tablas:
 *   - mant_maquinas
 *   - mant_plan
 *   - mant_completions
 *
 * Mismo mecanismo de dos fases con alias temporal que el script para
 * DEL LH, para evitar colisiones entre los nuevos y los viejos nombres.
 *
 * Modos:
 *   php tools/mant_renumerar_puertas_ta_tra_lh.php
 *     → DRY-RUN
 *   php tools/mant_renumerar_puertas_ta_tra_lh.php --apply
 *     → ESCRITURA. Tres tablas, dos fases, transacción global.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

echo "Renumerar RACK PUERTAS TA TRA LH · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// orden => [viejo_num, nuevo_num]
$mapa = [
    1126 => ['01', '09'],
    1127 => ['02', '02'],   // sin cambio efectivo, pero pasa por el flujo
    1128 => ['03', '08'],
    1131 => ['06', '10'],
    1132 => ['07', '06'],
    1133 => ['08', '05'],
];

$prefix = 'RACK PUERTAS TA TRA LH - ';

// ── 1. Estado actual ──
echo "Estado actual de las 6 máquinas:" . PHP_EOL;
$encontradas = 0;
foreach ($mapa as $orden => [$viejo, $nuevo]) {
    $codViejo = $prefix . $viejo;
    $codNuevo = $prefix . $nuevo;
    $rowViejo = Db::pgFetchOne(
        "SELECT cod_maquina_mant FROM mant_maquinas WHERE cod_maquina_mant = :c",
        [':c' => $codViejo]
    );
    $existeViejo = !empty($rowViejo);
    if ($existeViejo) $encontradas++;

    $rowNuevo = Db::pgFetchOne(
        "SELECT cod_maquina_mant FROM mant_maquinas WHERE cod_maquina_mant = :c",
        [':c' => $codNuevo]
    );
    $existeNuevo = !empty($rowNuevo);

    $marca = ($viejo === $nuevo) ? ' [sin cambio]' : '';
    printf("  orden %d · %s → %s · viejo_existe=%s · nuevo_existe=%s%s\n",
        $orden, $codViejo, $codNuevo,
        $existeViejo ? 'SÍ' : 'NO',
        $existeNuevo ? 'SÍ' : 'NO',
        $marca);
}

if ($encontradas === 0) {
    echo PHP_EOL . "❌ Ninguna de las 6 máquinas existe. Nada que renumerar." . PHP_EOL;
    exit(0);
}

echo PHP_EOL . "Máquinas detectadas: $encontradas / 6" . PHP_EOL;

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_renumerar_puertas_ta_tra_lh.php --apply" . PHP_EOL;
    exit(0);
}

// ── 2. APPLY ──
echo PHP_EOL . "Aplicando..." . PHP_EOL;

try {
    Db::pgExec("BEGIN");

    // FASE 1: alias temporales
    echo "  Fase 1 · alias temporales" . PHP_EOL;
    foreach ($mapa as $orden => [$viejo, $nuevo]) {
        $codViejo = $prefix . $viejo;
        $codTmp   = $prefix . '_TMP_' . $orden;

        $r1 = Db::pgExec("UPDATE mant_completions SET cod_maquina_mant=?, desc_maquina=? WHERE cod_maquina_mant=?",
            [$codTmp, $codTmp, $codViejo]);
        $r2 = Db::pgExec("UPDATE mant_plan        SET cod_maquina_mant=?, desc_maquina=? WHERE cod_maquina_mant=?",
            [$codTmp, $codTmp, $codViejo]);
        $r3 = Db::pgExec("UPDATE mant_maquinas    SET cod_maquina_mant=?, desc_maquina=? WHERE cod_maquina_mant=?",
            [$codTmp, $codTmp, $codViejo]);
        printf("    · %-32s → %s · comp=%d plan=%d maq=%d\n",
            $codViejo, $codTmp, (int)$r1, (int)$r2, (int)$r3);
    }

    // FASE 2: nombre definitivo
    echo "  Fase 2 · nombre definitivo" . PHP_EOL;
    foreach ($mapa as $orden => [$viejo, $nuevo]) {
        $codTmp   = $prefix . '_TMP_' . $orden;
        $codNuevo = $prefix . $nuevo;

        $r1 = Db::pgExec("UPDATE mant_completions SET cod_maquina_mant=?, desc_maquina=? WHERE cod_maquina_mant=?",
            [$codNuevo, $codNuevo, $codTmp]);
        $r2 = Db::pgExec("UPDATE mant_plan        SET cod_maquina_mant=?, desc_maquina=? WHERE cod_maquina_mant=?",
            [$codNuevo, $codNuevo, $codTmp]);
        $r3 = Db::pgExec("UPDATE mant_maquinas    SET cod_maquina_mant=?, desc_maquina=? WHERE cod_maquina_mant=?",
            [$codNuevo, $codNuevo, $codTmp]);
        printf("    · %-38s → %s · comp=%d plan=%d maq=%d\n",
            $codTmp, $codNuevo, (int)$r1, (int)$r2, (int)$r3);
    }

    Db::pgExec("COMMIT");
    echo PHP_EOL . "✓ COMMIT" . PHP_EOL;
} catch (Throwable $e) {
    Db::pgExec("ROLLBACK");
    fwrite(STDERR, "ERROR durante el rename, ROLLBACK aplicado: " . $e->getMessage() . PHP_EOL);
    exit(4);
}

// ── 3. Verificación ──
echo PHP_EOL . "Verificación:" . PHP_EOL;
foreach ($mapa as $orden => [$viejo, $nuevo]) {
    $codNuevo = $prefix . $nuevo;
    $found = Db::pgFetchOne(
        "SELECT cod_maquina_mant FROM mant_maquinas WHERE cod_maquina_mant = :c",
        [':c' => $codNuevo]
    );
    printf("  orden %d → %s · %s\n", $orden, $codNuevo, $found ? 'OK' : 'FALTA');
}
$tmpsRest = (int)(Db::pgFetchOne("
    SELECT COUNT(*) AS n FROM mant_maquinas
     WHERE cod_maquina_mant LIKE :p
", [':p' => $prefix . '_TMP_%'])['n'] ?? 0);
echo "  · Alias temporales que aún quedan: $tmpsRest (esperado 0)" . PHP_EOL;
