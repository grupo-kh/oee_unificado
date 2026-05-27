<?php
/**
 * Configura el catálogo DEFINITIVO de operarios activos.
 *
 * Solo estos 8 operarios pueden aparecer como ejecutantes de NUEVAS tareas
 * preventivas. El histórico se respeta tal cual (no se borra nada de
 * mant_completions aquí — eso lo hace mant_reasignar_operarios.php).
 *
 *   2394  Ricardo Albaráñez
 *   1004  Cristobal Tenorio Selma
 *   1374  Christian Guerrero Gonzalez
 *   1886  Rámon Alarcón Peinado
 *   2417  Cristofer Fernandez Queiroga
 *   2338  Jasser Basheer
 *   2898  Emir Hansali
 *    881  Juan Navarro
 *
 * Comportamiento:
 *   1. UPSERT de los 8 con activo=TRUE y su nombre canónico.
 *   2. UPDATE activo=FALSE para todos los demás que sigan en mant_operarios.
 *   3. NO borra operarios del catálogo (preserva referencias históricas).
 *
 * Modos:
 *   php tools/mant_set_operarios_activos.php
 *     → DRY-RUN. Muestra qué pasaría.
 *
 *   php tools/mant_set_operarios_activos.php --apply
 *     → ESCRITURA.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

echo "Catálogo definitivo de operarios · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

$operariosValidos = [
    '2394' => 'Ricardo Albaráñez',
    '1004' => 'Cristobal Tenorio Selma',
    '1374' => 'Christian Guerrero Gonzalez',
    '1886' => 'Rámon Alarcón Peinado',
    '2417' => 'Cristofer Fernandez Queiroga',
    '2338' => 'Jasser Basheer',
    '2898' => 'Emir Hansali',
    '881'  => 'Juan Navarro',
];

// 1. Estado actual
$actuales = Db::pgFetchAll("SELECT numero, nombre, activo FROM mant_operarios ORDER BY numero");
echo "Total operarios en catálogo actualmente: " . count($actuales) . PHP_EOL;
$enCat = [];
foreach ($actuales as $a) {
    $enCat[(string)$a['numero']] = $a;
}

// 2. Plan
echo PHP_EOL . "Acciones a aplicar:" . PHP_EOL;
$ins = 0; $upd = 0; $desactiva = 0;
foreach ($operariosValidos as $num => $nom) {
    $num = (string)$num;
    if (!isset($enCat[$num])) {
        printf("  · INSERT %s (%s) activo=TRUE\n", $num, $nom);
        $ins++;
    } else {
        $a = $enCat[$num];
        $cambia = ((string)$a['nombre'] !== $nom) || (((bool)$a['activo']) !== true);
        if ($cambia) {
            printf("  · UPDATE %s (%s → %s) activo=TRUE\n", $num, $a['nombre'] ?? '?', $nom);
            $upd++;
        } else {
            printf("  · OK     %s (%s) ya activo\n", $num, $nom);
        }
    }
}
foreach ($enCat as $num => $a) {
    if (!isset($operariosValidos[$num])) {
        if ((bool)$a['activo']) {
            printf("  · DESACT %s (%s) → activo=FALSE\n", $num, $a['nombre'] ?? '?');
            $desactiva++;
        }
    }
}

echo PHP_EOL . "Resumen plan:" . PHP_EOL;
echo "  INSERT  : $ins" . PHP_EOL;
echo "  UPDATE  : $upd" . PHP_EOL;
echo "  DESACT  : $desactiva" . PHP_EOL;

// 3. Aplicar
if ($apply) {
    echo PHP_EOL . "Aplicando..." . PHP_EOL;
    foreach ($operariosValidos as $num => $nom) {
        Db::pgExec("
            INSERT INTO mant_operarios (numero, nombre, activo)
            VALUES (:n, :nb, TRUE)
            ON CONFLICT (numero) DO UPDATE SET
                nombre = EXCLUDED.nombre,
                activo = TRUE,
                updated_at = now()
        ", [':n' => (string)$num, ':nb' => $nom]);
    }
    $r = Db::pgExec("
        UPDATE mant_operarios SET activo = FALSE, updated_at = now()
         WHERE numero NOT IN ('" . implode("','", array_map(fn($k) => addslashes((string)$k), array_keys($operariosValidos))) . "')
           AND activo IS DISTINCT FROM FALSE
    ");
    echo "  · " . count($operariosValidos) . " operarios upserted (activo=TRUE)\n";
    echo "  · $r operarios marcados como activo=FALSE\n";

    // 4. Verificación
    $activos = Db::pgFetchAll("SELECT numero, nombre FROM mant_operarios WHERE activo = TRUE ORDER BY nombre");
    echo PHP_EOL . "✓ Operarios ACTIVOS tras la operación:" . PHP_EOL;
    foreach ($activos as $o) {
        printf("  · %s · %s\n", $o['numero'], $o['nombre']);
    }
}

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_set_operarios_activos.php --apply" . PHP_EOL;
}
