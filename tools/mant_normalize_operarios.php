<?php
/**
 * Normaliza el campo "operario" en mant_completions y mant_operarios:
 * todo lo que NO sea ya un código puramente numérico se reemplaza por
 * un código del tipo "OP-NNNN" generado de forma estable (mismo nombre
 * → mismo código siempre).
 *
 * Idempotente: las filas que ya tienen un valor numérico no se tocan.
 *
 * Modo:
 *   php tools/mant_normalize_operarios.php
 *     → DRY-RUN. Lista los nombres y el código que se les asignaría.
 *
 *   php tools/mant_normalize_operarios.php --apply
 *     → ESCRITURA. UPDATE en mant_completions.operario y mant_operarios.nombre.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';

$apply = in_array('--apply', $argv, true);

echo "Normalizar operarios · " . ($apply ? "ESCRITURA" : "DRY-RUN") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

/**
 * Considera "numérico puro" si después de quitar espacios el string entero
 * está formado solo por dígitos. "1004", "2417", "12345" → OK.
 * "Ricardo García", "OP-001", "OP1234" → hay que normalizar.
 */
function esCodigoNumerico(string $s): bool {
    $t = trim($s);
    return $t !== '' && ctype_digit($t);
}

/**
 * Si el valor lleva prefijo "OP-" o "OP" delante de dígitos, lo extrae
 * para devolver solo el número. Devuelve null si no encaja con ese patrón.
 */
function extraerNumeroDeOP(string $s): ?string {
    $t = trim($s);
    if (preg_match('/^OP[-_ ]?0*(\d+)$/i', $t, $m)) {
        return $m[1] === '' ? '0' : $m[1];
    }
    return null;
}

/**
 * Genera un código estable de solo dígitos a partir de un nombre.
 * Usa crc32 truncado a 4 dígitos en el rango 3000-9999 para evitar
 * colisión con los códigos numéricos "reales" (1000-2999) ya existentes.
 * Conflictos resueltos por incremento.
 */
function generarCodigo(string $nombre, array &$ocupados): string {
    $base = (abs(crc32($nombre)) % 7000) + 3000;  // 3000..9999
    while (isset($ocupados[$base])) {
        $base++;
        if ($base > 9999) $base = 3000;
    }
    $ocupados[$base] = true;
    return (string)$base;
}

// 1. Cargar todos los operarios DISTINTOS que aparecen en marcas
$rowsComp = Db::pgFetchAll("
    SELECT DISTINCT operario
      FROM mant_completions
     WHERE operario IS NOT NULL AND operario <> ''
");
$nombresComp = array_map(fn($r) => (string)$r['operario'], $rowsComp);

// 2. Cargar también del catálogo mant_operarios (puede haber alguno sin marcas)
$rowsCat = Db::pgFetchAll("SELECT DISTINCT nombre FROM mant_operarios WHERE nombre IS NOT NULL AND nombre <> ''");
$nombresCat = array_map(fn($r) => (string)$r['nombre'], $rowsCat);

$todos = array_unique(array_merge($nombresComp, $nombresCat));
sort($todos);
echo "Operarios distintos: " . count($todos) . PHP_EOL;

// 3. Clasificar:
//    - numéricos puros → OK, no se tocan
//    - con prefijo OP-NNNN → extraer el número y normalizarlo (puede chocar
//      con otro código existente; si choca, se asigna uno nuevo)
//    - resto (nombres alfabéticos) → asignar código nuevo
$numericos    = [];
$conPrefOP    = [];   // [nombre_original → numero_extraído]
$aNormalizar  = [];
foreach ($todos as $n) {
    if (esCodigoNumerico($n)) {
        $numericos[(int)$n] = true;
        continue;
    }
    $num = extraerNumeroDeOP($n);
    if ($num !== null) {
        $conPrefOP[$n] = $num;
        continue;
    }
    $aNormalizar[] = $n;
}
echo "Ya numéricos puros: " . count($numericos)
   . " · Con prefijo OP- (a limpiar): " . count($conPrefOP)
   . " · Nombres a normalizar: " . count($aNormalizar) . PHP_EOL;

$ocupados = $numericos;  // partimos del set ya usado

// 4a. Procesar los "OP-NNNN" → solo número (mismo si no choca, otro si sí)
$mapping = [];
foreach ($conPrefOP as $orig => $numExt) {
    $numInt = (int)$numExt;
    if (!isset($ocupados[$numInt])) {
        $mapping[$orig] = (string)$numInt;
        $ocupados[$numInt] = true;
    } else {
        // Choque (raro): generar uno nuevo a partir del nombre
        $mapping[$orig] = generarCodigo($orig, $ocupados);
    }
}

// 4b. Procesar los nombres alfabéticos
foreach ($aNormalizar as $n) {
    $mapping[$n] = generarCodigo($n, $ocupados);
}

if (empty($mapping)) {
    echo "Todo está en orden. Nada que hacer." . PHP_EOL;
    exit(0);
}

echo PHP_EOL . "Mapping propuesto:" . PHP_EOL;
foreach ($mapping as $orig => $cod) {
    printf("  %-40s →  %s\n", mb_strimwidth($orig, 0, 40, '…'), $cod);
}

// 5. Aplicar UPDATEs
if ($apply) {
    echo PHP_EOL . "Aplicando..." . PHP_EOL;
    $updCompTot = 0; $updCatTot = 0;
    foreach ($mapping as $orig => $cod) {
        $r1 = Db::pgExec(
            "UPDATE mant_completions SET operario = :nuevo WHERE operario = :orig",
            [':nuevo' => $cod, ':orig' => $orig]
        );
        $r2 = Db::pgExec(
            "UPDATE mant_operarios SET nombre = :nuevo WHERE nombre = :orig",
            [':nuevo' => $cod, ':orig' => $orig]
        );
        // pgExec puede devolver el rowCount o null según implementación
        $updCompTot += (int)($r1 ?: 0);
        $updCatTot  += (int)($r2 ?: 0);
    }
    echo "  · Filas mant_completions actualizadas: $updCompTot" . PHP_EOL;
    echo "  · Filas mant_operarios actualizadas: $updCatTot" . PHP_EOL;
} else {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_normalize_operarios.php --apply" . PHP_EOL;
}
