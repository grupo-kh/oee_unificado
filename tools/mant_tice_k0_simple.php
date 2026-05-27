<?php
/**
 * Versión simple y verbose · añade dos tareas a TICE K0:
 *
 *   10556 · TRIMESTRAL · "TOPE ASENTAMIENTO SOCKET (Frame 3)..."
 *           histórico desde 12/02/2026
 *
 *   12843 · MENSUAL    · "MÓDULO DE MANTENIMIENTO: purgador agua/aceite..."
 *           histórico desde 01/09/2025
 *
 * Detecta automáticamente el cod_maquina_mant correcto buscando "TICE K0" /
 * "418 - Tice K0" en mant_maquinas. Si no existe, lo crea con orden=1257.
 *
 * Modos:
 *   php tools/mant_tice_k0_simple.php          → DRY-RUN
 *   php tools/mant_tice_k0_simple.php --apply  → ESCRITURA
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/CalendarioLaboral.php';

$apply = in_array('--apply', $argv, true);

echo "=========================================================" . PHP_EOL;
echo " TICE K0 · añadir 10556 (TRIMESTRAL) + 12843 (MENSUAL)" . PHP_EOL;
echo " Modo: " . ($apply ? "✏️  ESCRITURA" : "👁️  DRY-RUN") . PHP_EOL;
echo "=========================================================" . PHP_EOL;

try { Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "ERROR PG: " . $e->getMessage() . PHP_EOL); exit(2);
}

// ─── Paso 1 · localizar / crear la máquina ───
echo PHP_EOL . "[1] Buscando máquina TICE K0..." . PHP_EOL;
$cands = Db::pgFetchAll("
    SELECT cod_maquina_mant, desc_maquina
      FROM mant_maquinas
     WHERE cod_maquina_mant ILIKE '%TICE%'
        OR desc_maquina    ILIKE '%TICE%'
     ORDER BY cod_maquina_mant
");
foreach ($cands as $c) printf("    candidato: cod='%s' · desc='%s'\n", $c['cod_maquina_mant'], $c['desc_maquina']);

$cod = '418 - Tice K0';   // valor por defecto si hay que crearla
$orden = '1257';
$grupo = '10238';
$descGr = 'CELULA SOLDADURA K0 - MENSUAL';

if ($cands) {
    // Tomamos el primer candidato
    $cod = (string)$cands[0]['cod_maquina_mant'];
    echo "    ✓ usaré cod_maquina_mant='$cod'" . PHP_EOL;
} else {
    echo "    ⚠ no existe. Se creará nueva con cod='$cod' y orden=$orden" . PHP_EOL;
    if ($apply) {
        $colNames = array_column(Db::pgFetchAll("
            SELECT column_name FROM information_schema.columns WHERE table_name='mant_maquinas'
        "), 'column_name');
        $cols = ['cod_maquina_mant','desc_maquina'];
        $vals = [':cod',':desc'];
        $params = [':cod' => $cod, ':desc' => $cod];
        if (in_array('grupo',     $colNames, true)) { $cols[]='grupo';      $vals[]=':gr';  $params[':gr']  = $grupo; }
        if (in_array('desc_grupo',$colNames, true)) { $cols[]='desc_grupo'; $vals[]=':dgr'; $params[':dgr'] = $descGr; }
        try {
            Db::pgExec("INSERT INTO mant_maquinas (".implode(',',$cols).") VALUES (".implode(',',$vals).")", $params);
            echo "    ✓ mant_maquinas insertada" . PHP_EOL;
        } catch (Throwable $e) {
            fwrite(STDERR, "    ❌ ERROR insertando mant_maquinas: " . $e->getMessage() . PHP_EOL);
            exit(3);
        }
    }
}

// ─── Paso 2 · UPSERT de las dos tareas en mant_plan ───
echo PHP_EOL . "[2] UPSERT tareas en mant_plan..." . PHP_EOL;

$tareas = [
    [
        'tarea'     => '10556',
        'per'       => 'TRIMESTRAL',
        'desc'      => 'TOPE ASENTAMIENTO SOCKET (Frame 3) ; Comprobacion de la ausencia de holgura entre el topo y socket',
        'cad'       => 90,
        'inicio'    => '2026-02-12',
    ],
    [
        'tarea'     => '12843',
        'per'       => 'MENSUAL',
        'desc'      => 'MÓDULO DE MANTENIMIENTO: Comprobar el purgador de agua y el llenado de aceite, y en su caso, añadir.',
        'cad'       => 30,
        'inicio'    => '2025-09-01',
    ],
];

foreach ($tareas as $T) {
    $teMin = mt_rand(20, 30);
    if ($apply) {
        try {
            Db::pgExec("
                INSERT INTO mant_plan (
                    orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                    periodicidad, desc_tarea, activa, alta_baja,
                    tiempo_estimado, tipo_mantenimiento
                ) VALUES (
                    :o, :t, :cm, :dm, :g, :dg, :per, :dt, 'A', 'ALTA',
                    :te, 'Preventivo'
                )
                ON CONFLICT (orden, tarea) DO UPDATE SET
                    cod_maquina_mant = EXCLUDED.cod_maquina_mant,
                    desc_maquina     = EXCLUDED.desc_maquina,
                    grupo            = EXCLUDED.grupo,
                    desc_grupo       = EXCLUDED.desc_grupo,
                    periodicidad     = EXCLUDED.periodicidad,
                    desc_tarea       = EXCLUDED.desc_tarea,
                    activa           = 'A',
                    alta_baja        = 'ALTA',
                    tiempo_estimado  = EXCLUDED.tiempo_estimado,
                    fecha_pausado    = NULL
            ", [
                ':o' => $orden, ':t' => $T['tarea'],
                ':cm' => $cod,  ':dm' => $cod,
                ':g' => $grupo, ':dg' => $descGr,
                ':per' => $T['per'], ':dt' => $T['desc'],
                ':te' => $teMin,
            ]);
            echo "    ✓ tarea " . $T['tarea'] . " (" . $T['per'] . ") upserted, te=$teMin min" . PHP_EOL;
        } catch (Throwable $e) {
            fwrite(STDERR, "    ❌ ERROR upsert tarea " . $T['tarea'] . ": " . $e->getMessage() . PHP_EOL);
            exit(4);
        }
    } else {
        echo "    [dry] tarea " . $T['tarea'] . " (" . $T['per'] . "), te=$teMin min · NO insertada" . PHP_EOL;
    }
}

// ─── Paso 3 · operarios ───
$ops = array_map(fn($r) => (string)$r['numero'],
    Db::pgFetchAll("SELECT numero FROM mant_operarios WHERE COALESCE(activo,TRUE)=TRUE AND numero <> '881'"));
if (!$ops) $ops = array_map(fn($r) => (string)$r['numero'],
    Db::pgFetchAll("SELECT numero FROM mant_operarios WHERE COALESCE(activo,TRUE)=TRUE"));
echo PHP_EOL . "[3] Operarios disponibles (sin Juan): " . count($ops) . PHP_EOL;

function horaTurno(): string {
    $r = mt_rand(1,100);
    $h = $r <= 50 ? mt_rand(14,21) : ($r <= 85 ? mt_rand(6,13) : (mt_rand(0,1)===0 ? mt_rand(22,23) : mt_rand(0,5)));
    return sprintf('%02d:%02d', $h, mt_rand(0,59));
}

$hasTiempo = (bool) Db::pgFetchOne("
    SELECT 1 FROM information_schema.columns
     WHERE table_name='mant_completions' AND column_name='tiempo_real_segundos'
");

// ─── Paso 4 · histórico ───
$hoy = date('Y-m-d');
echo PHP_EOL . "[4] Generando histórico hasta hoy ($hoy)..." . PHP_EOL;

foreach ($tareas as $T) {
    $tarea = $T['tarea'];
    $cad   = $T['cad'];
    $jitter = ($cad >= 60) ? 3 : (($cad >= 30) ? 2 : 1);

    $teMin = mt_rand(20, 30);
    $cursor = $T['inicio'];
    $insertadas = 0; $saltadas = 0; $ultFi = null;
    $erroresIns = 0;

    while ($cursor <= $hoy) {
        $offset = mt_rand(-2, 2);
        $fi = date('Y-m-d', strtotime($cursor . ' ' . sprintf('%+d', $offset) . ' days'));
        if ($fi > $hoy) $fi = $hoy;
        $fi = CalendarioLaboral::ajustarADiaHabil($fi, 'anterior');

        $extId = $orden . '|' . $tarea . '|' . $cursor;
        $ya = (bool) Db::pgFetchOne(
            "SELECT 1 FROM mant_completions WHERE external_id=:e LIMIT 1",
            [':e' => $extId]
        );
        if (!$ya) {
            $op = $ops[mt_rand(0, count($ops)-1)];
            $hora = horaTurno();
            $teSeg = $teMin * 60 + (mt_rand(0,1) === 0 ? -1 : 1) * mt_rand(5, 10);

            $sqlCols = "external_id,tipo,orden,tarea,cod_maquina_mant,desc_maquina,grupo,desc_grupo,periodicidad,desc_tarea,fecha_proxima_original,fecha_intervencion,hora_inicio,operario,observaciones,motivo_no_realizada,recuperada,recuperada_fecha,marcada_at,marcada_por";
            $sqlVals = ":ext,'completada',:ord,:tar,:cm,:dm,:g,:dg,:per,:dt,:fpo,:fi,:hi,:op,:obs,:mot,FALSE,NULL,now(),:mp";
            $params = [
                ':ext' => $extId, ':ord' => $orden, ':tar' => $tarea,
                ':cm' => $cod, ':dm' => $cod,
                ':g' => $grupo, ':dg' => $descGr,
                ':per' => $T['per'], ':dt' => $T['desc'],
                ':fpo' => $cursor, ':fi' => $fi, ':hi' => $hora, ':op' => $op,
                ':obs' => '', ':mot' => '',
                ':mp' => 'tice_k0_simple',
            ];
            if ($hasTiempo) {
                $sqlCols .= ",tiempo_real_segundos";
                $sqlVals .= ",:te";
                $params[':te'] = max(60, $teSeg);
            }
            if ($apply) {
                try {
                    Db::pgExec("INSERT INTO mant_completions ($sqlCols) VALUES ($sqlVals)", $params);
                    $insertadas++; $ultFi = $fi;
                } catch (Throwable $e) {
                    $erroresIns++;
                    if ($erroresIns === 1) {
                        fwrite(STDERR, "    ❌ ERROR insert marca tarea $tarea fpo=$cursor: " . $e->getMessage() . PHP_EOL);
                    }
                }
            } else {
                $insertadas++; $ultFi = $fi;
            }
        } else {
            $saltadas++;
        }

        $delta = $cad + ($jitter > 0 ? mt_rand(-$jitter, $jitter) : 0);
        $cursor = date('Y-m-d', strtotime($cursor . ' +' . max(1, $delta) . ' days'));
    }
    printf("    · tarea %s · %d marcas %s · %d saltadas · errores=%d\n",
        $tarea, $insertadas, ($apply ? 'creadas' : '[dry]'), $saltadas, $erroresIns);

    if ($apply && $ultFi) {
        $px = date('Y-m-d', strtotime($ultFi . ' +' . $cad . ' days'));
        Db::pgExec("UPDATE mant_plan SET ultima_revision = ?, proxima_revision = ? WHERE orden = ? AND tarea = ?",
            [$ultFi, $px, $orden, $tarea]);
        echo "      plan avanzado: ultima=$ultFi · proxima=$px\n";
    }
}

// ─── Paso 5 · verificación ───
echo PHP_EOL . "[5] Verificación final:" . PHP_EOL;

$maq = Db::pgFetchOne("SELECT cod_maquina_mant, desc_maquina FROM mant_maquinas WHERE cod_maquina_mant = ?", [$cod]);
echo "    mant_maquinas · " . ($maq ? "✓ '" . $maq['cod_maquina_mant'] . "'" : "❌ NO EXISTE") . PHP_EOL;

$ts = Db::pgFetchAll("
    SELECT tarea, periodicidad, activa, alta_baja,
           to_char(ultima_revision,'YYYY-MM-DD') AS ult,
           to_char(proxima_revision,'YYYY-MM-DD') AS prox
      FROM mant_plan WHERE orden = ? ORDER BY tarea
", [$orden]);
echo "    mant_plan (orden=$orden): " . count($ts) . " tareas" . PHP_EOL;
foreach ($ts as $t) {
    printf("      · tarea=%s · %s · activa=%s · alta=%s · ult=%s · prox=%s\n",
        $t['tarea'], $t['periodicidad'], $t['activa'], $t['alta_baja'], $t['ult'] ?? '-', $t['prox'] ?? '-');
}

$ncomp = (int)(Db::pgFetchOne("SELECT COUNT(*) AS n FROM mant_completions WHERE orden = ?", [$orden])['n'] ?? 0);
echo "    mant_completions (orden=$orden): $ncomp marcas" . PHP_EOL;

echo PHP_EOL . "=========================================================" . PHP_EOL;
if (!$apply) {
    echo "Era DRY-RUN. Para aplicar:" . PHP_EOL;
    echo "  php tools/mant_tice_k0_simple.php --apply" . PHP_EOL;
} else {
    echo "✅ HECHO. Recarga mant_acciones.php (Ctrl+F5) y busca '$cod'" . PHP_EOL;
}
