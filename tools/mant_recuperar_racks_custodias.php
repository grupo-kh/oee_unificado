<?php
/**
 * Recupera las 51 máquinas RACK CUSTODIAS (lista completa enviada por
 * el usuario) e inserta su histórico completo desde 2025-09-01.
 *
 *   - 14 RACK CUSTODIAS TA LH (01, 03..15 — falta 02)
 *   - 12 RACK CUSTODIAS TA RH (01..05, 08..14 — faltan 06, 07)
 *   - 12 RACK CUSTODIAS TB LH (01, 03..13 — falta 02)
 *   - 13 RACK CUSTODIAS TB RH (01..13)
 *
 * Familia / grupo:
 *   - grupo: 10167  ·  desc_grupo: "RACK CUSTODIAS - TRIMESTRAL"
 *   - 13 tareas TRIMESTRAL idénticas para cada máquina:
 *       10669, 10672, 10673, 10675, 10676, 10678,
 *       10689, 10690, 10692, 10693, 10879, 10888, 10889
 *
 * Comportamiento:
 *   1. UPSERT mant_maquinas (no duplica si ya existen).
 *   2. UPSERT mant_plan (ON CONFLICT por (orden, tarea)).
 *   3. Genera histórico TRIMESTRAL desde 2025-09-01 hasta hoy con UNA visita
 *      por trimestre (todas las 13 sub-tareas comparten fecha+operario+hora).
 *   4. Operario por visita: Juan Navarro (881) en el 70% de los casos, el
 *      30% restante repartido entre los otros 7 activos. Si toca Juan, la
 *      hora cae en turno de mañana (06:00–13:55).
 *   5. tiempo_estimado por tarea: 20..30 min.
 *   6. tiempo_real_segundos: tiempo_estimado × 60 ± 5..10 s.
 *   7. Fechas no hábiles (CV) se desplazan al día hábil anterior.
 *   8. IDEMPOTENTE: si la marca ya existe (mismo external_id), se salta.
 *
 * Modos:
 *   php tools/mant_recuperar_racks_custodias.php
 *     → DRY-RUN: cuenta todo lo que insertaría sin tocar nada.
 *
 *   php tools/mant_recuperar_racks_custodias.php --apply
 *     → ESCRITURA: procesa las 51 máquinas en orden. Es idempotente
 *       (ON CONFLICT en plan + skip por external_id en marcas), así
 *       que relanzarlo no duplica nada — pero sí volvería a recorrer
 *       las máquinas ya completas.
 *
 *   php tools/mant_recuperar_racks_custodias.php --apply --solo-faltantes
 *     → Solo procesa las máquinas que NO existen ya en mant_maquinas.
 *       Útil cuando una ejecución previa quedó a medias: las máquinas
 *       que ya están terminadas se saltan por completo (no toca su
 *       plan ni su histórico). Recomendado para evitar trabajo extra.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply         = in_array('--apply', $argv, true);
$soloFaltantes = in_array('--solo-faltantes', $argv, true);

echo "Recuperar RACK CUSTODIAS · " . ($apply ? "ESCRITURA" : "DRY-RUN")
   . ($soloFaltantes ? " · solo faltantes" : "") . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR conexión PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// ── Catálogo de operarios activos (los 8 oficiales) ──
$activos = Db::pgFetchAll("
    SELECT numero FROM mant_operarios
     WHERE COALESCE(activo, TRUE) = TRUE
     ORDER BY numero
");
$activos = array_map(fn($r) => (string)$r['numero'], $activos);
if (!$activos) {
    fwrite(STDERR, "Sin operarios activos. Ejecuta antes mant_set_operarios_activos.php --apply" . PHP_EOL);
    exit(3);
}
$JUAN  = '881';
$otros = array_values(array_diff($activos, [$JUAN]));
echo "Operarios activos: " . count($activos) . " (Juan principal: "
   . (in_array($JUAN, $activos, true) ? "SÍ" : "NO — usará reparto uniforme") . ")"
   . PHP_EOL;

function pickOperarioRack(string $juan, array $otros, array $activos): string {
    if (!in_array($juan, $activos, true)) {
        // Fallback: si Juan no está activo, reparto uniforme entre los activos.
        return $activos[mt_rand(0, count($activos) - 1)];
    }
    if (mt_rand(1, 100) <= 70) return $juan;
    return $otros[mt_rand(0, count($otros) - 1)];
}
function horaMananaJuan(): string {
    $h = mt_rand(6, 13);
    $m = mt_rand(0, 11) * 5;
    return sprintf('%02d:%02d:00', $h, $m);
}

// ── Las 51 máquinas RACK CUSTODIAS ──
// Los 'orden' están elegidos para NO chocar con LUNETAS (1045..1070),
// PARABRISAS (1076..1103 + 1206, 1212, 1231), PUERTAS TA (1106..1145)
// ni PUERTAS TB (1169..1182).
$maquinas = [
    // ── TA LH (14 máquinas) → orden 969..982
    969 => 'RACK CUSTODIAS TA LH - 01',
    970 => 'RACK CUSTODIAS TA LH - 03',
    971 => 'RACK CUSTODIAS TA LH - 04',
    972 => 'RACK CUSTODIAS TA LH - 05',
    973 => 'RACK CUSTODIAS TA LH - 06',
    974 => 'RACK CUSTODIAS TA LH - 07',
    975 => 'RACK CUSTODIAS TA LH - 08',
    976 => 'RACK CUSTODIAS TA LH - 09',
    977 => 'RACK CUSTODIAS TA LH - 10',
    978 => 'RACK CUSTODIAS TA LH - 11',
    979 => 'RACK CUSTODIAS TA LH - 12',
    980 => 'RACK CUSTODIAS TA LH - 13',
    981 => 'RACK CUSTODIAS TA LH - 14',
    982 => 'RACK CUSTODIAS TA LH - 15',
    // ── TA RH (12 máquinas) → orden 984..995
    984 => 'RACK CUSTODIAS TA RH - 01',
    985 => 'RACK CUSTODIAS TA RH - 02',
    986 => 'RACK CUSTODIAS TA RH - 03',
    987 => 'RACK CUSTODIAS TA RH - 04',
    988 => 'RACK CUSTODIAS TA RH - 05',
    989 => 'RACK CUSTODIAS TA RH - 08',
    990 => 'RACK CUSTODIAS TA RH - 09',
    991 => 'RACK CUSTODIAS TA RH - 10',
    992 => 'RACK CUSTODIAS TA RH - 11',
    993 => 'RACK CUSTODIAS TA RH - 12',
    994 => 'RACK CUSTODIAS TA RH - 13',
    995 => 'RACK CUSTODIAS TA RH - 14',
    // ── TB LH (12 máquinas) → orden 1183..1194
    1183 => 'RACK CUSTODIAS TB LH - 01',
    1184 => 'RACK CUSTODIAS TB LH - 03',
    1185 => 'RACK CUSTODIAS TB LH - 04',
    1186 => 'RACK CUSTODIAS TB LH - 05',
    1187 => 'RACK CUSTODIAS TB LH - 06',
    1188 => 'RACK CUSTODIAS TB LH - 07',
    1189 => 'RACK CUSTODIAS TB LH - 08',
    1190 => 'RACK CUSTODIAS TB LH - 09',
    1191 => 'RACK CUSTODIAS TB LH - 10',
    1192 => 'RACK CUSTODIAS TB LH - 11',
    1193 => 'RACK CUSTODIAS TB LH - 12',
    1194 => 'RACK CUSTODIAS TB LH - 13',
    // ── TB RH (13 máquinas) → orden 1213..1225
    1213 => 'RACK CUSTODIAS TB RH - 01',
    1214 => 'RACK CUSTODIAS TB RH - 02',
    1215 => 'RACK CUSTODIAS TB RH - 03',
    1216 => 'RACK CUSTODIAS TB RH - 04',
    1217 => 'RACK CUSTODIAS TB RH - 05',
    1218 => 'RACK CUSTODIAS TB RH - 06',
    1219 => 'RACK CUSTODIAS TB RH - 07',
    1220 => 'RACK CUSTODIAS TB RH - 08',
    1221 => 'RACK CUSTODIAS TB RH - 09',
    1222 => 'RACK CUSTODIAS TB RH - 10',
    1223 => 'RACK CUSTODIAS TB RH - 11',
    1224 => 'RACK CUSTODIAS TB RH - 12',
    1225 => 'RACK CUSTODIAS TB RH - 13',
];

// ── 13 tareas TRIMESTRAL de la familia CUSTODIAS ──
$tareasComunes = [
    ['10669', 'LIMPIEZA INTERIOR RACK. Sin restos de vidrios y protecciones en perfecto estado'],
    ['10672', 'ESTRUCTURA METALICA. Comprobar daños en patines, rejas, bastidor y  brazos separadores'],
    ['10673', 'PROTECCIONES ENGOMADAS + BARRA TOPE LATERAL. Protecciones engomadas en buen estado y bien ancladas al chásis (remaches)'],
    ['10675', 'PEINES. Comprobar estado peines y correcta sujeción al chásis (tornillos)'],
    ['10676', 'RESORTES GAS-BRAZOS. Comprobar estado de la tornillería. Reapretar uniones atornilladas empleando una galga de espesores de 0.5mm, asegurando una correcta holgura.'],
    ['10678', 'ESTADO TRAMPILLA. Comprobar movimiento apertura y cierre. Revisar la soldadadura de pestillo. Revisar estado de bisagra (Golpes, grietas). Alineación de trampilla'],
    ['10689', 'ESTADO PINTURA. Repintado de todas las zonas gastadas. Numeración del rack y pintado ralla identificativa según trimestre rosa, azul, morado o rojo'],
    ['10690', 'PRESENCIA Y ESTADO  PORTADOCUMENTOS. Comprobar con un folio que se pueda ubicar.'],
    ['10692', 'DIMENSIONADO RACK. Comprobación de geometría general del bastidor del rack, siguiendo las indicaciones del plano proporcionado.'],
    ['10693', 'FUNDA PALAS. Revisar que no presenta golpes y grietas.'],
    ['10879', 'RESORTES GAS-BRAZOS: Comprobar la fuerza de cada uno de los amortiguadores. Reemplazar en caso de rotura.'],
    ['10888', 'LUBRICACIÓN: Ejes de los brazos separadores. Limpiar el sobrante.'],
    ['10889', 'ETIQUETA: Comprobar que la numeración física del rack coincide con la etiqueta de codigo de barras.'],
];

// ── Detectar columnas mant_maquinas ──
$colNames = array_column(Db::pgFetchAll("
    SELECT column_name FROM information_schema.columns WHERE table_name = 'mant_maquinas'
"), 'column_name');
$hasGrupo  = in_array('grupo',      $colNames, true);
$hasDescGr = in_array('desc_grupo', $colNames, true);

// ── Helper: genera fechas trimestrales desde un punto hasta otro ──
function genFechasTrimestrales(string $desde, string $hasta): array {
    $fechas = [];
    $cursor = $desde;
    while ($cursor <= $hasta) {
        $fechas[] = $cursor;
        $cursor = date('Y-m-d', strtotime($cursor . ' +' . mt_rand(87, 93) . ' days'));
    }
    return $fechas;
}

$hoy    = date('Y-m-d');
$inicio = '2025-09-01';
$grupo  = '10167';
$descGr = 'RACK CUSTODIAS - TRIMESTRAL';

$insMaq = 0; $upMaq = 0;
$insTarea = 0;
$insMarcas = 0;
$saltadasMarcas = 0;

$saltadasYaCompletas = 0;
foreach ($maquinas as $orden => $cod) {
    $desc = $cod;

    // 1. mant_maquinas
    $existe = (bool) Db::pgFetchOne(
        "SELECT 1 FROM mant_maquinas WHERE cod_maquina_mant = :c LIMIT 1",
        [':c' => $cod]
    );

    // Si --solo-faltantes y la máquina ya existe, la saltamos por completo:
    // no tocamos plan ni marcas. Útil para retomar ejecuciones interrumpidas.
    if ($soloFaltantes && $existe) {
        $saltadasYaCompletas++;
        $upMaq++;
        printf("  · %s (orden=%d) [ya existe, saltada]\n", $cod, $orden);
        continue;
    }

    if (!$existe) {
        if ($apply) {
            $cols = ['cod_maquina_mant', 'desc_maquina'];
            $vals = [':cod', ':desc'];
            $params = [':cod' => $cod, ':desc' => $desc];
            if ($hasGrupo)  { $cols[] = 'grupo';      $vals[] = ':gr';  $params[':gr']  = $grupo; }
            if ($hasDescGr) { $cols[] = 'desc_grupo'; $vals[] = ':dgr'; $params[':dgr'] = $descGr; }
            Db::pgExec("INSERT INTO mant_maquinas (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")", $params);
        }
        $insMaq++;
    } else {
        $upMaq++;
    }

    // 2. Calcular visitas comunes (una por trimestre)
    $fechasVisitas = genFechasTrimestrales($inicio, $hoy);
    $visitas = [];
    foreach ($fechasVisitas as $fpo) {
        $offset   = mt_rand(-2, 2);
        $fechaInt = date('Y-m-d', strtotime($fpo . ' ' . sprintf('%+d', $offset) . ' days'));
        if ($fechaInt > $hoy) $fechaInt = $hoy;
        $fechaInt = CalendarioLaboral::ajustarADiaHabil($fechaInt, 'anterior');
        $op = pickOperarioRack($JUAN, $otros, $activos);
        $hora = ($op === $JUAN)
              ? horaMananaJuan()
              : MaintenanceCompletionStore::horaTurnoAleatoria();
        $visitas[] = [
            'fpo'      => $fpo,
            'fechaInt' => $fechaInt,
            'op'       => $op,
            'hora'     => $hora,
        ];
    }

    // 3. Por cada sub-tarea: UPSERT plan + marcas
    foreach ($tareasComunes as [$tarea, $descTarea]) {
        $teMin = mt_rand(20, 30);

        if ($apply) {
            Db::pgExec("
                INSERT INTO mant_plan (
                    orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                    periodicidad, desc_tarea, activa, alta_baja,
                    tiempo_estimado, tipo_mantenimiento
                ) VALUES (
                    :o, :t, :cm, :dm, :g, :dg,
                    'TRIMESTRAL', :dt, 'A', 'ALTA',
                    :te, 'Preventivo'
                )
                ON CONFLICT (orden, tarea) DO UPDATE SET
                    cod_maquina_mant = EXCLUDED.cod_maquina_mant,
                    desc_maquina     = EXCLUDED.desc_maquina,
                    grupo            = EXCLUDED.grupo,
                    desc_grupo       = EXCLUDED.desc_grupo,
                    desc_tarea       = EXCLUDED.desc_tarea,
                    periodicidad     = EXCLUDED.periodicidad,
                    activa           = 'A',
                    alta_baja        = 'ALTA',
                    tiempo_estimado  = EXCLUDED.tiempo_estimado
            ", [
                ':o'  => (string)$orden, ':t' => $tarea,
                ':cm' => $cod, ':dm' => $desc,
                ':g'  => $grupo, ':dg' => $descGr,
                ':dt' => $descTarea,
                ':te' => $teMin,
            ]);
        }
        $insTarea++;

        // 4. Marcas históricas
        $ultIntFecha = null;
        foreach ($visitas as $v) {
            $ultIntFecha = $v['fechaInt'];
            $id = MaintenanceCompletionStore::buildId((string)$orden, $tarea, $v['fpo']);
            // OJO: mant_completions.id es BIGSERIAL numérico, el id estable
            // "orden|tarea|fpo" va en mant_completions.external_id (TEXT).
            $existeMarca = (bool) Db::pgFetchOne(
                "SELECT 1 FROM mant_completions WHERE external_id = :i LIMIT 1",
                [':i' => $id]
            );
            if ($existeMarca) { $saltadasMarcas++; continue; }
            if ($apply) {
                try {
                    MaintenanceCompletionStore::add([
                        'tipo'                   => 'completada',
                        'orden'                  => (string)$orden,
                        'tarea'                  => $tarea,
                        'cod_maquina_mant'       => $cod,
                        'desc_maquina'           => $desc,
                        'grupo'                  => $grupo,
                        'desc_grupo'             => $descGr,
                        'periodicidad'           => 'TRIMESTRAL',
                        'desc_tarea'             => $descTarea,
                        'fecha_proxima_original' => $v['fpo'],
                        'fecha_intervencion'     => $v['fechaInt'],
                        'hora_inicio'            => $v['hora'],
                        'operario'               => $v['op'],
                        'observaciones'          => '',
                        'motivo_no_realizada'    => '',
                        'recuperada'             => false,
                        'recuperada_fecha'       => null,
                        'marcada_at'             => time(),
                        'marcada_por'            => 'seed_racks_custodias',
                        'tiempo_real_segundos'   => MaintenanceCompletionStore::aplicarDecalajeAleatorio($teMin * 60),
                    ]);
                    $insMarcas++;
                } catch (Throwable $e) { /* skip dup */ }
            } else {
                $insMarcas++;
            }
        }

        // 5. Avanzar plan
        if ($apply && $ultIntFecha) {
            $proxima = date('Y-m-d', strtotime($ultIntFecha . ' +90 days'));
            Db::pgExec(
                "UPDATE mant_plan SET ultima_revision = :u, proxima_revision = :p
                  WHERE orden = :o AND tarea = :t",
                [':u' => $ultIntFecha, ':p' => $proxima, ':o' => (string)$orden, ':t' => $tarea]
            );
        }
    }
    printf("  · %s (orden=%d) %s\n", $cod, $orden, $apply ? 'OK' : '[dry]');
}

echo str_repeat('─', 70) . PHP_EOL;
echo "Máquinas insertadas      : $insMaq" . PHP_EOL;
echo "Máquinas ya existían     : $upMaq" . PHP_EOL;
if ($soloFaltantes) {
    echo "  · de las cuales saltadas por --solo-faltantes: $saltadasYaCompletas" . PHP_EOL;
}
echo "Tareas (upserted)        : $insTarea" . PHP_EOL;
echo "Marcas insertadas        : $insMarcas" . PHP_EOL;
echo "Marcas saltadas (ya estaban): $saltadasMarcas" . PHP_EOL;

if (!$apply) {
    echo PHP_EOL . "Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_recuperar_racks_custodias.php --apply"
        . ($soloFaltantes ? " --solo-faltantes" : "") . PHP_EOL;
    echo PHP_EOL . "Modo recomendado tras una ejecución interrumpida:" . PHP_EOL;
    echo "  php tools/mant_recuperar_racks_custodias.php --apply --solo-faltantes" . PHP_EOL;
}
