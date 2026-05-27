<?php
/**
 * Importador para "RACKS - E66.xlsx".
 *
 * - Inserta las máquinas (RACKs + E66) en mant_maquinas si no existen.
 * - Inserta las 393 tareas en mant_plan con tiempo_estimado por familia:
 *      RACK → 20..30 min   ("unos ±25")
 *      E66  →  5..15 min   ("unos ±10")
 * - Genera el histórico de revisiones desde 2025-09-01 hasta hoy según
 *   periodicidad (TRIMESTRAL/MENSUAL/SEMESTRAL/ANUAL), con:
 *      · operario aleatorio (numérico) de mant_operarios
 *      · hora por turnos (50% tarde · 35% mañana · 15% noche)
 *      · tiempo real = tiempo_estimado*60 ± 5..10 seg
 *
 * Estructura del Excel (Hoja1):
 *   A=Maquina  B=Grupo  C=familia  D=Periodicidad  E=Tarea  F=DescripcionTarea  G=Activa
 *
 * Las máquinas RACK quedan visibles en la vista bajo SECUENCIA/RACKS,
 * las E66 bajo SECUENCIA/E66 (la lógica de agrupación de la app las
 * detecta por el prefijo de desc_maquina: 'RACK ' o 'E66').
 *
 * Modos:
 *   php tools/mant_import_racks_e66.php "C:\tmp\RACKS - E66.xlsx"
 *     → DRY-RUN. Muestra qué insertaría.
 *
 *   php tools/mant_import_racks_e66.php "C:\tmp\RACKS - E66.xlsx" --apply
 *     → ESCRITURA real.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path  = $argv[1] ?? null;
$apply = in_array('--apply', $argv, true);

if (!$path || !is_file($path)) {
    fwrite(STDERR, "Uso: php tools/mant_import_racks_e66.php \"<ruta xlsx>\" [--apply]\n");
    exit(1);
}

echo "Import RACKS + E66 · " . ($apply ? "ESCRITURA" : "DRY-RUN") . "\n";
echo "Fichero: $path\n" . str_repeat('─', 70) . "\n";

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// ── Operarios disponibles (numéricos) ──
$ops = array_map(fn($r) => (string)$r['nombre'],
    Db::pgFetchAll("SELECT nombre FROM mant_operarios WHERE COALESCE(activo, TRUE) = TRUE ORDER BY nombre"));
if (!$ops) {
    $ops = MaintenanceCompletionStore::loadOperarios();
}
if (!$ops) {
    fwrite(STDERR, "No hay operarios en mant_operarios; aborto.\n"); exit(3);
}
echo "Operarios disponibles: " . count($ops) . "\n";

// ── Lectura del xlsx ──
$book  = IOFactory::load($path);
$sheet = $book->getSheet(0);
$rowsMax = $sheet->getHighestRow();

// Recolectamos las tareas en un array por máquina.
//
// Estructura del Excel:
//   A=Maquina  B=Grupo  C=familia  D=Periodicidad  E=Tarea  F=Desc  G=Activa
//   H=FechaIni I=TiempoEst (opcional, solo en las E66 raras)
//
// Algunas filas (las 11 E66) NO tienen código de tarea en col E pero sí
// tienen descripción y tiempo estimado en col I. Para esas generamos un
// código de tarea automático estable basado en hash de máquina+descripción.
$maquinas = [];
$saltadas = 0;
$tareasAuto = 0;
for ($r = 2; $r <= $rowsMax; $r++) {
    $maq    = trim((string)$sheet->getCell('A' . $r)->getValue());
    $grupo  = trim((string)$sheet->getCell('B' . $r)->getValue());
    $fam    = trim((string)$sheet->getCell('C' . $r)->getValue());
    $per    = trim((string)$sheet->getCell('D' . $r)->getValue());
    $tarea  = trim((string)$sheet->getCell('E' . $r)->getValue());
    $desc   = trim((string)$sheet->getCell('F' . $r)->getValue());
    $activa = trim((string)$sheet->getCell('G' . $r)->getValue()) ?: 'A';
    $teRaw  = trim((string)$sheet->getCell('I' . $r)->getValue());

    if ($maq === '' && $per === '') continue;

    // Si falta el código de tarea, lo generamos a partir de hash de
    // máquina + descripción → estable, reproducible y único.
    if ($tarea === '') {
        if ($desc === '') { $saltadas++; continue; }
        // Código numérico entre 90000 y 99999 derivado de hash(maq + desc)
        $h = crc32($maq . '|' . $desc);
        $tarea = (string) (90000 + (abs($h) % 10000));
        $tareasAuto++;
    }
    if ($maq === '' || $per === '') { $saltadas++; continue; }

    // Tiempo estimado: si la col I trae un número, lo respetamos;
    // si no, lo decidirá tiempoEstimadoMin($fam) más abajo.
    $teExcel = ctype_digit($teRaw) ? (int)$teRaw : null;

    if (!isset($maquinas[$maq])) {
        $maquinas[$maq] = [
            'desc_maquina' => $maq,
            'familia'      => $fam,
            'grupo'        => $grupo,
            'tareas'       => [],
        ];
    }
    $maquinas[$maq]['tareas'][] = [
        'tarea'        => $tarea,
        'periodicidad' => strtoupper($per),
        'desc_tarea'   => $desc,
        'activa'       => $activa,
        'te_excel'     => $teExcel,   // null si no hay tiempo en col I
    ];
}
printf("Máquinas en el Excel: %d (con %d tareas en total)\n",
    count($maquinas),
    array_sum(array_map(fn($m) => count($m['tareas']), $maquinas)));
printf("Códigos de tarea auto-generados (filas sin col E): %d\n", $tareasAuto);
printf("Filas saltadas (faltan datos esenciales): %d\n", $saltadas);

// ── Helpers ──
function perToDays(string $per): int {
    switch (strtoupper(trim($per))) {
        case 'SEMANAL':      return 7;
        case 'QUINCENAL':    return 14;
        case 'MENSUAL':      return 30;
        case 'BIMENSUAL':    return 60;
        case 'TRIMESTRAL':   return 90;
        case 'CUATRIMESTRAL':return 120;
        case 'SEMESTRAL':    return 180;
        case 'ANUAL':        return 365;
        case 'BIANUAL':      return 730;
        default:             return 30;
    }
}

function tiempoEstimadoMin(string $familia): int {
    $f = strtoupper(trim($familia));
    if ($f === 'RACKS' || str_starts_with($f, 'RACK')) {
        return mt_rand(20, 30);   // ±5 sobre 25
    }
    if ($f === 'E66' || str_starts_with($f, 'E66')) {
        return mt_rand(5, 15);    // ±5 sobre 10
    }
    return 10;
}

function genFechasHistorico(string $desde, string $hasta, int $perDays): array {
    if ($perDays <= 0) return [];
    $fechas = [];
    $cursor = $desde;
    while ($cursor <= $hasta) {
        $fechas[] = $cursor;
        $jitter = (int) round($perDays * 0.1);
        $delta  = $perDays + mt_rand(-$jitter, $jitter);
        if ($delta < 1) $delta = $perDays;
        $cursor = date('Y-m-d', strtotime($cursor . " +$delta days"));
    }
    return $fechas;
}

// ── INSERT ──
$hoy    = date('Y-m-d');
$inicio = '2025-09-01';

$insMaq = 0; $upMaq = 0;
$insTarea = 0; $upTarea = 0;
$insInter = 0;

// Detectar columnas de mant_maquinas para no fallar si tiene campos NOT NULL
$colsMaq = Db::pgFetchAll("
    SELECT column_name FROM information_schema.columns
     WHERE table_name = 'mant_maquinas'
");
$colNames = array_column($colsMaq, 'column_name');
$hasGrupo   = in_array('grupo', $colNames, true);
$hasDescGr  = in_array('desc_grupo', $colNames, true);

foreach ($maquinas as $maq => $m) {
    $cod  = $maq;            // usamos el nombre como cod_maquina_mant
    $desc = $m['desc_maquina'];
    $fam  = $m['familia'];
    $gr   = $m['grupo'];

    // 1. mant_maquinas (catálogo) — insertar si no existe
    $existe = (bool) Db::pgFetchOne(
        "SELECT 1 FROM mant_maquinas WHERE cod_maquina_mant = :c LIMIT 1", [':c' => $cod]
    );
    if (!$existe) {
        if ($apply) {
            $cols = ['cod_maquina_mant', 'desc_maquina'];
            $vals = [':cod', ':desc'];
            $params = [':cod' => $cod, ':desc' => $desc];
            if ($hasGrupo)  { $cols[] = 'grupo';      $vals[] = ':gr';   $params[':gr']   = $gr ?: null; }
            if ($hasDescGr) { $cols[] = 'desc_grupo'; $vals[] = ':dgr';  $params[':dgr']  = $fam ?: null; }
            $sql = "INSERT INTO mant_maquinas (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
            Db::pgExec($sql, $params);
        }
        $insMaq++;
    } else {
        $upMaq++;
    }

    // 2. Para cada tarea: insertar en mant_plan + histórico
    foreach ($m['tareas'] as $t) {
        $per   = $t['periodicidad'];
        // Tiempo estimado: prevalece el valor del Excel (col I) si lo trae;
        // si no, aleatorio por familia (RACK 20-30, E66 5-15).
        $teMin = $t['te_excel'] !== null ? $t['te_excel'] : tiempoEstimadoMin($fam);

        // mant_plan
        if ($apply) {
            Db::pgExec("
                INSERT INTO mant_plan (
                    orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                    periodicidad, desc_tarea, activa, alta_baja,
                    tiempo_estimado, tipo_mantenimiento
                ) VALUES (
                    :o, :t, :cm, :dm, :g, :dg,
                    :p, :dt, :ac, 'ALTA',
                    :te, 'Preventivo'
                )
                ON CONFLICT (orden, tarea) DO UPDATE SET
                    desc_tarea      = EXCLUDED.desc_tarea,
                    periodicidad    = EXCLUDED.periodicidad,
                    tiempo_estimado = EXCLUDED.tiempo_estimado
            ", [
                ':o'  => $cod,           // orden = cod_maquina_mant (único por máquina)
                ':t'  => $t['tarea'],
                ':cm' => $cod,  ':dm' => $desc,
                ':g'  => $gr ?: null, ':dg' => $fam ?: null,
                ':p'  => $per, ':dt' => $t['desc_tarea'],
                ':ac' => $t['activa'],
                ':te' => $teMin,
            ]);
        }
        $insTarea++;

        // 3. Histórico desde 01/09/2025
        $perDays = perToDays($per);
        $fechas  = genFechasHistorico($inicio, $hoy, $perDays);
        $ultIntFecha = null;
        $semanasUsadas = [];   // para deduplicación SEMANAL
        $esSemanal = (strtoupper($per) === 'SEMANAL');
        foreach ($fechas as $fpo) {
            $offset   = mt_rand(-2, 2);
            $fechaInt = date('Y-m-d', strtotime($fpo . ' ' . sprintf('%+d', $offset) . ' days'));
            if ($fechaInt > $hoy) $fechaInt = $hoy;
            // Ajustar a día hábil (lun-vie, no festivo CV)
            $fechaInt = CalendarioLaboral::ajustarADiaHabil($fechaInt, 'anterior');
            // Dedupe semanal: si ya hay una en esa semana ISO, saltar
            if ($esSemanal) {
                $sem = CalendarioLaboral::semanaIso($fechaInt);
                if (isset($semanasUsadas[$sem])) continue;
                $semanasUsadas[$sem] = true;
            }
            $ultIntFecha = $fechaInt;
            if ($apply) {
                try {
                    MaintenanceCompletionStore::add([
                        'tipo'                   => 'completada',
                        'orden'                  => $cod,
                        'tarea'                  => $t['tarea'],
                        'cod_maquina_mant'       => $cod,
                        'desc_maquina'           => $desc,
                        'grupo'                  => $gr ?: '',
                        'desc_grupo'             => $fam ?: '',
                        'periodicidad'           => $per,
                        'desc_tarea'             => $t['desc_tarea'],
                        'fecha_proxima_original' => $fpo,
                        'fecha_intervencion'     => $fechaInt,
                        'hora_inicio'            => MaintenanceCompletionStore::horaTurnoAleatoria(),
                        'operario'               => $ops[mt_rand(0, count($ops) - 1)],
                        'observaciones'          => 'Histórico generado',
                        'motivo_no_realizada'    => '',
                        'recuperada'             => false,
                        'recuperada_fecha'       => null,
                        'marcada_at'             => time(),
                        'marcada_por'            => 'seed_racks_e66',
                        'tiempo_real_segundos'   => MaintenanceCompletionStore::aplicarDecalajeAleatorio($teMin * 60),
                    ]);
                    $insInter++;
                } catch (Throwable $e) { /* skip dup */ }
            } else {
                $insInter++;
            }
        }

        // 4. Avanzar plan: ultima_revision = última fecha_int, proxima = ult + perDays
        if ($apply && $ultIntFecha) {
            $proxima = date('Y-m-d', strtotime($ultIntFecha . " +$perDays days"));
            Db::pgExec(
                "UPDATE mant_plan SET ultima_revision = :u, proxima_revision = :p
                  WHERE orden = :o AND tarea = :t",
                [':u' => $ultIntFecha, ':p' => $proxima, ':o' => $cod, ':t' => $t['tarea']]
            );
        }
    }
}

echo str_repeat('─', 70) . "\n";
echo "Máquinas insertadas: $insMaq · ya existían: $upMaq\n";
echo "Tareas (insertadas o actualizadas): $insTarea\n";
echo "Intervenciones generadas: $insInter\n";
if (!$apply) {
    echo "\nPara aplicarlo:\n";
    echo "  php tools/mant_import_racks_e66.php \"$path\" --apply\n";
}
