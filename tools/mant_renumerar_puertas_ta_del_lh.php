<?php
/**
 * Renumera las 6 máquinas RACK PUERTAS TA DEL LH al nuevo orden indicado
 * por el usuario, manteniendo la asignación por POSICIÓN:
 *
 *   posición 1ª: era "01" → ahora "10"   (orden 1106)
 *   posición 2ª: era "02" → ahora "04"   (orden 1107)
 *   posición 3ª: era "04" → ahora "08"   (orden 1109)
 *   posición 4ª: era "05" → ahora "02"   (orden 1110)
 *   posición 5ª: era "06" → ahora "05"   (orden 1111)
 *   posición 6ª: era "10" → ahora "06"   (orden 1115)
 *
 * El número de orden NO cambia (sigue siendo PK de mant_plan). Lo que
 * cambia es `cod_maquina_mant` y `desc_maquina` en las tres tablas:
 *   - mant_maquinas
 *   - mant_plan
 *   - mant_completions
 *
 * Como los nuevos nombres COLISIONAN con los antiguos durante el swap
 * (p.ej. el nuevo "10" choca con el viejo "10" antes de que ese se
 * convierta en "06"), hacemos el cambio en DOS pasos por cada máquina:
 *   1. renombrar al alias temporal "RACK PUERTAS TA DEL LH - _TMP_<orden>"
 *   2. renombrar del alias temporal al nombre definitivo
 *
 * Modos:
 *   php tools/mant_renumerar_puertas_ta_del_lh.php
 *     → DRY-RUN
 *   php tools/mant_renumerar_puertas_ta_del_lh.php --apply
 *     → ESCRITURA. Tres tablas, dos fases, transacción global.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

echo "Renumerar RACK PUERTAS TA DEL LH · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('═', 75) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// orden => [viejo_num, nuevo_num]
$mapa = [
    1106 => ['01', '10'],
    1107 => ['02', '04'],
    1109 => ['04', '08'],
    1110 => ['05', '02'],
    1111 => ['06', '05'],
    1115 => ['10', '06'],
];

$prefix = 'RACK PUERTAS TA DEL LH - ';

// ── 1. Comprobar estado actual ──
echo "Estado actual de las 6 máquinas:" . PHP_EOL;
$encontradas = 0;
$conflictos  = [];
foreach ($mapa as $orden => [$viejo, $nuevo]) {
    $codViejo = $prefix . $viejo;
    $codNuevo = $prefix . $nuevo;

    // Verifica que el viejo existe en mant_maquinas
    $rowViejo = Db::pgFetchOne(
        "SELECT cod_maquina_mant FROM mant_maquinas WHERE cod_maquina_mant = :c",
        [':c' => $codViejo]
    );
    $existeViejo = !empty($rowViejo);
    if ($existeViejo) $encontradas++;

    // Verifica si el nuevo ya existe (será reemplazado por otro swap, así
    // que si existe pero NO está en nuestro mapa de origen, es conflicto).
    $rowNuevo = Db::pgFetchOne(
        "SELECT cod_maquina_mant FROM mant_maquinas WHERE cod_maquina_mant = :c",
        [':c' => $codNuevo]
    );
    $existeNuevo = !empty($rowNuevo);

    printf("  orden %d · %s → %s · viejo_existe=%s · nuevo_existe=%s\n",
        $orden, $codViejo, $codNuevo,
        $existeViejo ? 'SÍ' : 'NO',
        $existeNuevo ? 'SÍ' : 'NO');
}

if ($encontradas === 0) {
    echo PHP_EOL . "❌ Ninguna de las 6 máquinas existe. Nada que renumerar." . PHP_EOL;
    exit(0);
}

echo PHP_EOL . "Máquinas detectadas: $encontradas / 6" . PHP_EOL;

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_renumerar_puertas_ta_del_lh.php --apply" . PHP_EOL;
    exit(0);
}

// ── 2. APPLY: dos fases en transacción ──
echo PHP_EOL . "Aplicando..." . PHP_EOL;

try {
    Db::pgExec("BEGIN");

    // ── FASE 1: a alias temporal ──
    echo "  Fase 1 · renombrar a alias temporales" . PHP_EOL;
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

    // ── FASE 2: del alias al nombre definitivo ──
    echo "  Fase 2 · renombrar al nombre definitivo" . PHP_EOL;
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
