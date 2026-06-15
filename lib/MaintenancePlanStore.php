<?php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/MaintenanceExcelReader.php';
require_once __DIR__ . '/MaintenancePeriodicidadStore.php';
require_once __DIR__ . '/CalendarioLaboral.php';

/**
 * Plan vigente de mantenimiento preventivo (sustituye la hoja PROXIMAS REV.
 * del Excel). Modo:
 *   - PostgreSQL (MANT_USE_PG=true): tabla `mant_plan`
 *   - Legacy: lee del Excel via MaintenanceExcelReader (compatibilidad)
 *
 * Cada item tiene la misma estructura que devolvía el lector de Excel:
 *   orden, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
 *   periodicidad, tarea, desc_tarea, activa, ultima_revision, proxima_revision
 */
class MaintenancePlanStore
{
    private static function usePg(): bool
    {
        return defined('MANT_USE_PG') && MANT_USE_PG === true;
    }

    /**
     * Devuelve un array compatible con el legacy:
     *   ['proximas' => [...], 'historico' => [], 'file_mtime' => int, 'generated_at' => int]
     *
     * El histórico va vacío deliberadamente: las intervenciones viven en
     * mant_completions. file_mtime es el max(updated_at) de la tabla.
     */
    public static function load(): array
    {
        if (self::usePg()) {
            // Excluimos:
            //   - tareas pausadas (fecha_pausado IS NOT NULL): no se planifican
            //   - tareas dadas de baja (alta_baja = 'BAJA' o activa = 'B'):
            //     son máquinas inactivas que no deben aparecer en ningún panel
            //     de la app (planificador, cumplimiento, histórico…).
            //   - tareas con bloqueo temporal vigente
            //     (CURRENT_DATE BETWEEN fecha_bloqueo_ini AND fecha_bloqueo_fin):
            //     máquinas fuera de producción o racks en stand-by; al
            //     pasar la fecha fin la tarea vuelve sola al plan.
            $rows = Db::pgFetchAll("
                SELECT orden, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                       periodicidad, tarea, desc_tarea, activa,
                       to_char(ultima_revision,  'YYYY-MM-DD') AS ultima_revision,
                       to_char(proxima_revision, 'YYYY-MM-DD') AS proxima_revision,
                       tiempo_estimado,
                       to_char(fecha_pausado,     'YYYY-MM-DD') AS fecha_pausado,
                       to_char(fecha_bloqueo_ini, 'YYYY-MM-DD') AS fecha_bloqueo_ini,
                       to_char(fecha_bloqueo_fin, 'YYYY-MM-DD') AS fecha_bloqueo_fin,
                       alta_baja,
                       EXTRACT(EPOCH FROM updated_at)::bigint  AS upd_ts
                  FROM mant_plan
                 WHERE fecha_pausado IS NULL
                   AND COALESCE(alta_baja, 'ALTA') = 'ALTA'
                   AND COALESCE(activa,    'A')    = 'A'
                   AND NOT (
                       fecha_bloqueo_ini IS NOT NULL
                       AND fecha_bloqueo_fin IS NOT NULL
                       AND CURRENT_DATE BETWEEN fecha_bloqueo_ini AND fecha_bloqueo_fin
                   )
                 ORDER BY orden, tarea
            ");
            $proximas = [];
            $maxTs = 0;
            foreach ($rows as $r) {
                $maxTs = max($maxTs, (int)($r['upd_ts'] ?? 0));
                unset($r['upd_ts']);
                $proximas[] = $r;
            }
            return [
                'proximas'     => $proximas,
                'historico'    => [],
                'file_mtime'   => $maxTs ?: time(),
                'generated_at' => time(),
            ];
        }
        // Modo legacy: cae al lector de Excel
        return MaintenanceExcelReader::load();
    }

    /** Inserta o actualiza una tarea del plan. */
    public static function upsert(array $row): void
    {
        if (!self::usePg()) {
            throw new RuntimeException('upsert solo soportado en modo PostgreSQL (MANT_USE_PG=true)');
        }
        Db::pgExec("
            INSERT INTO mant_plan (
                orden, tarea, cod_maquina_mant, desc_maquina, grupo, desc_grupo,
                periodicidad, desc_tarea, activa, ultima_revision, proxima_revision
            ) VALUES (
                :orden, :tarea, :cod_maquina_mant, :desc_maquina, :grupo, :desc_grupo,
                :periodicidad, :desc_tarea, :activa, :ultima_revision, :proxima_revision
            )
            ON CONFLICT (orden, tarea) DO UPDATE SET
                cod_maquina_mant = EXCLUDED.cod_maquina_mant,
                desc_maquina     = EXCLUDED.desc_maquina,
                grupo            = EXCLUDED.grupo,
                desc_grupo       = EXCLUDED.desc_grupo,
                periodicidad     = EXCLUDED.periodicidad,
                desc_tarea       = EXCLUDED.desc_tarea,
                activa           = EXCLUDED.activa,
                ultima_revision  = EXCLUDED.ultima_revision,
                proxima_revision = EXCLUDED.proxima_revision
        ", [
            ':orden'            => (string)($row['orden'] ?? ''),
            ':tarea'            => (string)($row['tarea'] ?? ''),
            ':cod_maquina_mant' => (string)($row['cod_maquina_mant'] ?? ''),
            ':desc_maquina'     => (string)($row['desc_maquina']     ?? ''),
            ':grupo'            => $row['grupo']         !== '' ? (string)($row['grupo']         ?? null) : null,
            ':desc_grupo'       => $row['desc_grupo']    !== '' ? (string)($row['desc_grupo']    ?? null) : null,
            ':periodicidad'     => $row['periodicidad']  !== '' ? (string)($row['periodicidad']  ?? null) : null,
            ':desc_tarea'       => $row['desc_tarea']    !== '' ? (string)($row['desc_tarea']    ?? null) : null,
            ':activa'           => $row['activa']        !== '' ? (string)($row['activa']        ?? null) : null,
            ':ultima_revision'  => $row['ultima_revision']  ?: null,
            ':proxima_revision' => $row['proxima_revision'] ?: null,
        ]);
    }

    /** Total de tareas en el plan. */
    public static function count(): int
    {
        if (!self::usePg()) {
            return count(MaintenanceExcelReader::load()['proximas']);
        }
        $r = Db::pgFetchOne("SELECT COUNT(*) AS c FROM mant_plan");
        return (int)($r['c'] ?? 0);
    }

    /** Calcula la siguiente revision y la mueve al siguiente dia laborable si cae en no habil. */
    public static function calcularProximaRevisionLaborable(string $fechaIntervencion, string $periodicidad): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaIntervencion)) return null;
        $dias = MaintenancePeriodicidadStore::diasPorPeriodicidad($periodicidad);
        if ($dias === null) return null;
        $ts = strtotime($fechaIntervencion . ' +' . $dias . ' days');
        if ($ts === false) return null;
        return CalendarioLaboral::ajustarADiaHabil(date('Y-m-d', $ts), 'posterior');
    }

    /** Avanza el plan tras una revision completada. */
    public static function avanzarRevisionTrasCompletada(
        string $orden,
        string $tarea,
        string $fechaIntervencion,
        string $periodicidad
    ): ?array {
        $proxima = self::calcularProximaRevisionLaborable($fechaIntervencion, $periodicidad);
        if ($proxima === null) return null;

        if (!self::usePg()) {
            return [
                'ultima_revision'  => $fechaIntervencion,
                'proxima_revision' => $proxima,
            ];
        }

        $updated = Db::pgExec("
            UPDATE mant_plan
               SET ultima_revision = :ultima_revision,
                   proxima_revision = :proxima_revision
             WHERE orden = :orden
               AND tarea = :tarea
        ", [
            ':ultima_revision'  => $fechaIntervencion,
            ':proxima_revision' => $proxima,
            ':orden'            => $orden,
            ':tarea'            => $tarea,
        ]);
        if ($updated < 1) return null;

        return [
            'ultima_revision'  => $fechaIntervencion,
            'proxima_revision' => $proxima,
        ];
    }

    /** Vacia la tabla (uso administrativo, p. ej. antes de re-cargar desde Excel). */
    public static function truncate(): void
    {
        if (!self::usePg()) return;
        Db::pgExec("TRUNCATE TABLE mant_plan RESTART IDENTITY");
    }

    // ────────────── CRUD por máquina (vista "Acciones preventivas") ──────────────

    /**
     * Lista de máquinas con su contador de tareas. Usa el catálogo
     * mant_maquinas como fuente de la verdad: las máquinas sin tareas
     * también aparecen.
     */
    public static function listMaquinasConContador(string $modo = 'activas'): array
    {
        if (!self::usePg()) {
            throw new RuntimeException('listMaquinasConContador requiere modo PostgreSQL');
        }
        // $modo:
        //   'todas'    → cualquier máquina (las pausadas también)
        //   'activas'  → excluye las que tienen TODAS las tareas pausadas
        //                (sigue mostrando máquinas con tareas mixtas)
        //   'pausadas' → cualquier máquina con al menos UNA tarea pausada
        //                (paused_count > 0). Ya no requiere todo pausado.
        $rows = Db::pgFetchAll("
            SELECT m.cod_maquina_mant,
                   m.desc_maquina,
                   m.is_user_added,
                   COALESCE(t.task_count, 0)::int        AS task_count,
                   COALESCE(t.user_added_count, 0)::int  AS user_added_count,
                   COALESCE(t.paused_count, 0)::int      AS paused_count
              FROM mant_maquinas m
              LEFT JOIN (
                    SELECT cod_maquina_mant,
                           COUNT(*)                                       AS task_count,
                           COUNT(*) FILTER (WHERE is_user_added)          AS user_added_count,
                           COUNT(*) FILTER (WHERE fecha_pausado IS NOT NULL) AS paused_count
                      FROM mant_plan
                     WHERE COALESCE(alta_baja, 'ALTA') = 'ALTA'
                       AND COALESCE(activa,    'A')    = 'A'
                     GROUP BY cod_maquina_mant
                   ) t ON t.cod_maquina_mant = m.cod_maquina_mant
             WHERE m.is_user_added = TRUE
                OR EXISTS (
                    SELECT 1 FROM mant_plan p
                     WHERE p.cod_maquina_mant = m.cod_maquina_mant
                       AND COALESCE(p.alta_baja, 'ALTA') = 'ALTA'
                       AND COALESCE(p.activa,    'A')    = 'A'
                )
             ORDER BY m.desc_maquina
        ");
        if ($modo === 'todas') return $rows;

        // Filtramos en PHP para mantener la consulta principal estable.
        //   - 'pausadas' → cualquier máquina con al menos UNA tarea pausada.
        //   - 'activas'  → máquinas que NO están totalmente pausadas (sí
        //                  aparecen las mixtas: tienen tareas activas).
        return array_values(array_filter($rows, function ($r) use ($modo) {
            $task   = (int)($r['task_count']   ?? 0);
            $paused = (int)($r['paused_count'] ?? 0);
            if ($modo === 'pausadas') {
                return $paused > 0;
            }
            // 'activas': excluye solo las que están completamente pausadas.
            $totalmentePausada = ($task > 0 && $paused === $task);
            return !$totalmentePausada;
        }));
    }

    /** Crea una máquina nueva en el catálogo. */
    public static function createMaquina(array $data): array
    {
        if (!self::usePg()) {
            throw new RuntimeException('createMaquina requiere modo PostgreSQL');
        }
        $cod  = trim((string)($data['cod_maquina_mant'] ?? ''));
        $desc = trim((string)($data['desc_maquina'] ?? ''));
        if ($cod === '')  throw new InvalidArgumentException('Falta cod_maquina_mant');
        if ($desc === '') throw new InvalidArgumentException('Falta desc_maquina');
        if (mb_strlen($cod)  > 120) throw new InvalidArgumentException('cod_maquina_mant supera 120 caracteres');

        $exists = Db::pgFetchOne("SELECT 1 FROM mant_maquinas WHERE cod_maquina_mant = ?", [$cod]);
        if ($exists) throw new InvalidArgumentException("Ya existe una máquina con código '$cod'");

        Db::pgExec("
            INSERT INTO mant_maquinas (cod_maquina_mant, desc_maquina, is_user_added, created_by, notas)
            VALUES (:cod, :desc, TRUE, :by, :notas)
        ", [
            ':cod'   => $cod,
            ':desc'  => $desc,
            ':by'    => isset($data['created_by']) && $data['created_by'] !== '' ? (string)$data['created_by'] : null,
            ':notas' => isset($data['notas'])      && $data['notas']      !== '' ? (string)$data['notas']      : null,
        ]);
        return self::getMaquina($cod);
    }

    /** Devuelve una máquina del catálogo (con su contador). */
    public static function getMaquina(string $cod): ?array
    {
        if (!self::usePg()) return null;
        return Db::pgFetchOne("
            SELECT m.cod_maquina_mant, m.desc_maquina, m.is_user_added, m.created_by, m.notas,
                   COALESCE(COUNT(p.id), 0)::int AS task_count
              FROM mant_maquinas m
              LEFT JOIN mant_plan p ON p.cod_maquina_mant = m.cod_maquina_mant
             WHERE m.cod_maquina_mant = :cod
             GROUP BY m.cod_maquina_mant, m.desc_maquina, m.is_user_added, m.created_by, m.notas
        ", [':cod' => $cod]);
    }

    /** Renombra / actualiza una máquina del catálogo. */
    public static function updateMaquina(string $cod, array $data): array
    {
        if (!self::usePg()) {
            throw new RuntimeException('updateMaquina requiere modo PostgreSQL');
        }
        $current = self::getMaquina($cod);
        if (!$current) throw new RuntimeException("Máquina '$cod' no encontrada");

        $editable = ['desc_maquina', 'notas'];
        $sets = []; $params = [':cod' => $cod];
        foreach ($editable as $k) {
            if (!array_key_exists($k, $data)) continue;
            $v = is_string($data[$k]) ? trim($data[$k]) : $data[$k];
            if ($v === '') $v = null;
            $sets[] = "$k = :$k";
            $params[":$k"] = $v;
        }
        if (!$sets) return $current;

        $pdo = Db::pg();
        $pdo->beginTransaction();
        try {
            Db::pgExec("UPDATE mant_maquinas SET " . implode(', ', $sets) . " WHERE cod_maquina_mant = :cod", $params);
            // Mantener desc_maquina coherente en mant_plan también
            if (array_key_exists('desc_maquina', $data) && $data['desc_maquina'] !== '') {
                Db::pgExec(
                    "UPDATE mant_plan SET desc_maquina = ? WHERE cod_maquina_mant = ?",
                    [trim((string)$data['desc_maquina']), $cod]
                );
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::getMaquina($cod);
    }

    /**
     * Borra una máquina del catálogo. Solo se permite si NO tiene tareas
     * activas en mant_plan (para no romper el histórico). Devuelve true
     * si se borró.
     */
    public static function deleteMaquina(string $cod): bool
    {
        if (!self::usePg()) {
            throw new RuntimeException('deleteMaquina requiere modo PostgreSQL');
        }
        $r = Db::pgFetchOne("SELECT COUNT(*) AS c FROM mant_plan WHERE cod_maquina_mant = ?", [$cod]);
        if ((int)$r['c'] > 0) {
            throw new RuntimeException("La máquina tiene {$r['c']} tareas. Bórralas primero.");
        }
        $deleted = Db::pgExec("DELETE FROM mant_maquinas WHERE cod_maquina_mant = ?", [$cod]);
        return $deleted > 0;
    }

    /**
     * Devuelve el "impacto" estimado de un borrado en cascada de la máquina:
     * cuántas tareas, intervenciones de histórico, pendientes y overrides
     * se eliminarían. Para mostrar la confirmación al usuario antes del DELETE.
     */
    public static function getDeleteImpact(string $cod): array
    {
        if (!self::usePg()) {
            throw new RuntimeException('getDeleteImpact requiere modo PostgreSQL');
        }
        $maq = self::getMaquina($cod);
        if (!$maq) throw new RuntimeException("Máquina no encontrada: $cod");
        $tareas       = (int)Db::pgFetchOne("SELECT COUNT(*) c FROM mant_plan WHERE cod_maquina_mant = ?", [$cod])['c'];
        $intervenc    = (int)Db::pgFetchOne("SELECT COUNT(*) c FROM mant_completions WHERE cod_maquina_mant = ?", [$cod])['c'];
        $pendientes   = (int)Db::pgFetchOne("SELECT COUNT(*) c FROM mant_pendientes WHERE cod_maquina_mant = ?", [$cod])['c'];
        $overrides    = (int)Db::pgFetchOne("
            SELECT COUNT(*) c FROM mant_periodicidad_overrides ovr
             WHERE EXISTS (SELECT 1 FROM mant_plan p
                            WHERE p.orden = ovr.orden AND p.tarea = ovr.tarea
                              AND p.cod_maquina_mant = ?)
        ", [$cod])['c'];
        return [
            'cod_maquina_mant' => $cod,
            'desc_maquina'     => $maq['desc_maquina'] ?? '',
            'tareas'           => $tareas,
            'intervenciones'   => $intervenc,
            'pendientes'       => $pendientes,
            'overrides'        => $overrides,
        ];
    }

    /**
     * Borrado en cascada de una máquina: elimina la máquina, todas sus
     * tareas (mant_plan), pendientes manuales (mant_pendientes), overrides
     * de periodicidad (mant_periodicidad_overrides) e histórico de
     * intervenciones (mant_completions). Operación en una transacción:
     * si algo falla, no se borra nada.
     *
     * Devuelve el array de "impacto" (mismas cuentas que getDeleteImpact)
     * con el detalle de lo borrado.
     */
    public static function deleteMaquinaCascade(string $cod): array
    {
        if (!self::usePg()) {
            throw new RuntimeException('deleteMaquinaCascade requiere modo PostgreSQL');
        }
        $impact = self::getDeleteImpact($cod);
        $pdo = Db::pg();
        $pdo->beginTransaction();
        try {
            // 1) Overrides de periodicidad asociados a tareas de la máquina.
            $pdo->prepare("
                DELETE FROM mant_periodicidad_overrides ovr
                 USING mant_plan p
                 WHERE ovr.orden = p.orden
                   AND ovr.tarea = p.tarea
                   AND p.cod_maquina_mant = ?
            ")->execute([$cod]);

            // 2) Pendientes manuales de la máquina.
            $pdo->prepare("DELETE FROM mant_pendientes WHERE cod_maquina_mant = ?")
                ->execute([$cod]);

            // 3) Tareas del plan.
            $pdo->prepare("DELETE FROM mant_plan WHERE cod_maquina_mant = ?")
                ->execute([$cod]);

            // 4) Intervenciones del histórico (auditoría) para esta máquina.
            $pdo->prepare("DELETE FROM mant_completions WHERE cod_maquina_mant = ?")
                ->execute([$cod]);

            // 5) La máquina del catálogo.
            $pdo->prepare("DELETE FROM mant_maquinas WHERE cod_maquina_mant = ?")
                ->execute([$cod]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $impact;
    }

    /**
     * Tareas asociadas a una máquina, con número de intervenciones registradas.
     */
    public static function listTareasByMaquina(string $codMaquina, bool $consolidar = true): array
    {
        if (!self::usePg()) {
            throw new RuntimeException('listTareasByMaquina requiere modo PostgreSQL');
        }
        $rows = Db::pgFetchAll("
            SELECT mp.id,
                   mp.orden, mp.tarea,
                   mp.cod_maquina_mant, mp.desc_maquina,
                   mp.grupo, mp.desc_grupo,
                   mp.periodicidad,
                   mp.desc_tarea,
                   mp.activa,
                   to_char(mp.ultima_revision,  'YYYY-MM-DD') AS ultima_revision,
                   to_char(mp.proxima_revision, 'YYYY-MM-DD') AS proxima_revision,
                   mp.is_user_added,
                   mp.created_by,
                   to_char(mp.created_at, 'YYYY-MM-DD HH24:MI') AS created_at,
                   COALESCE(c.cnt, 0)::int AS intervenciones,
                   mp.alta_baja,
                   mp.ip_interna,
                   mp.tipo_realizacion,
                   mp.tipo_mantenimiento,
                   mp.tiempo_estimado,
                   to_char(mp.fecha_pausado,     'YYYY-MM-DD') AS fecha_pausado,
                   to_char(mp.fecha_bloqueo_ini, 'YYYY-MM-DD') AS fecha_bloqueo_ini,
                   to_char(mp.fecha_bloqueo_fin, 'YYYY-MM-DD') AS fecha_bloqueo_fin
              FROM mant_plan mp
              LEFT JOIN (
                    SELECT orden, tarea, COUNT(*) AS cnt
                      FROM mant_completions
                     GROUP BY orden, tarea
                   ) c ON c.orden = mp.orden AND c.tarea = mp.tarea
             WHERE mp.cod_maquina_mant = :cod
             ORDER BY mp.is_user_added DESC, mp.periodicidad, mp.tarea
        ", [':cod' => $codMaquina]);

        // Si la máquina es un rack o plataforma y el caller no pide vista
        // detallada (consolidar=true por defecto), consolidamos TODAS sus
        // tareas en una sola fila virtual. Las originales quedan accesibles
        // dentro de `sub_tareas` por si el operario quiere editarlas.
        $descMaq = $rows ? (string)($rows[0]['desc_maquina'] ?? '') : '';
        if ($consolidar && self::esConsolidable($descMaq) && count($rows) > 1) {
            return [self::buildConsolidatedTareasRow($rows)];
        }
        return $rows;
    }

    /**
     * Construye UNA fila "virtual" que agrupa todas las tareas de un mismo
     * rack/plataforma. La descripción combina cada sub-tarea como un bullet,
     * la periodicidad mostrada es la más exigente (intervalo más corto), la
     * próxima_revision es la más temprana, intervenciones son la suma, etc.
     *
     * Campos especiales:
     *   - id           : 0 (no es una fila real de mant_plan)
     *   - tarea        : 'CONSOL'
     *   - consolidada  : true
     *   - sub_tareas   : array de las filas originales
     */
    private static function buildConsolidatedTareasRow(array $rows): array
    {
        $first = $rows[0];
        $cod = (string)($first['cod_maquina_mant'] ?? '');

        $rankFn = [self::class, 'periodicidadRank'];
        $sub = [];
        $sumInt = 0;
        $pers = [];
        $proxima = null;
        $ultima = null;
        $perMain = (string)($first['periodicidad'] ?? '');
        $pausados = []; $bloqueados = []; $bajas = 0;
        $bloqIniMin = null; $bloqFinMax = null;

        foreach ($rows as $r) {
            $sumInt += (int)($r['intervenciones'] ?? 0);
            $per = strtoupper((string)($r['periodicidad'] ?? ''));
            if ($per !== '') {
                $pers[$per] = true;
                if (call_user_func($rankFn, $per) < call_user_func($rankFn, $perMain)) {
                    $perMain = $per;
                }
            }
            $px = $r['proxima_revision'] ?? null;
            if ($px !== null && ($proxima === null || $px < $proxima)) $proxima = $px;
            $ul = $r['ultima_revision'] ?? null;
            if ($ul !== null && ($ultima === null || $ul > $ultima)) $ultima = $ul;
            if (!empty($r['fecha_pausado'])) $pausados[] = $r['fecha_pausado'];
            if (!empty($r['fecha_bloqueo_ini']) && !empty($r['fecha_bloqueo_fin'])) {
                $bloqueados[] = [$r['fecha_bloqueo_ini'], $r['fecha_bloqueo_fin']];
                if ($bloqIniMin === null || $r['fecha_bloqueo_ini'] < $bloqIniMin) $bloqIniMin = $r['fecha_bloqueo_ini'];
                if ($bloqFinMax === null || $r['fecha_bloqueo_fin'] > $bloqFinMax) $bloqFinMax = $r['fecha_bloqueo_fin'];
            }
            if (strtoupper((string)($r['alta_baja'] ?? 'ALTA')) === 'BAJA') $bajas++;
            $sub[] = [
                'id'                => (int)($r['id'] ?? 0),
                'orden'             => (string)($r['orden'] ?? ''),
                'tarea'             => (string)($r['tarea'] ?? ''),
                'periodicidad'      => $per,
                'desc_tarea'        => (string)($r['desc_tarea'] ?? ''),
                'ultima_revision'   => $r['ultima_revision']  ?? null,
                'proxima_revision'  => $r['proxima_revision'] ?? null,
                'intervenciones'    => (int)($r['intervenciones'] ?? 0),
                'alta_baja'         => (string)($r['alta_baja']        ?? 'ALTA'),
                'ip_interna'        => (string)($r['ip_interna']       ?? ''),
                'tipo_realizacion'  => (string)($r['tipo_realizacion'] ?? ''),
                'tipo_mantenimiento'=> (string)($r['tipo_mantenimiento']?? ''),
                'fecha_pausado'     => $r['fecha_pausado']     ?? null,
                'fecha_bloqueo_ini' => $r['fecha_bloqueo_ini'] ?? null,
                'fecha_bloqueo_fin' => $r['fecha_bloqueo_fin'] ?? null,
            ];
        }

        // Construye la descripción agregada: encabezado + cada sub-tarea como
        // bullet con su periodicidad y el detalle de qué se hace.
        $n = count($rows);
        $persStr = count($pers) > 1 ? ' (' . implode(', ', array_keys($pers)) . ')' : '';
        $descLines = ['Revisión completa · ' . $n . ' tareas' . $persStr . ':'];
        foreach ($sub as $s) {
            $line = '  • [' . $s['tarea'] . '] ' . ($s['periodicidad'] ? $s['periodicidad'] . ' · ' : '');
            $line .= $s['desc_tarea'] !== '' ? $s['desc_tarea'] : '(sin descripción)';
            $descLines[] = $line;
        }
        $desc = implode("\n", $descLines);

        return [
            'id'                 => 0,
            'orden'              => 'CONSOL:' . $cod,
            'tarea'              => 'CONSOL',
            'cod_maquina_mant'   => $cod,
            'desc_maquina'       => $first['desc_maquina'] ?? '',
            'grupo'              => $first['grupo']        ?? '',
            'desc_grupo'         => $first['desc_grupo']   ?? '',
            'periodicidad'       => $perMain,
            'desc_tarea'         => $desc,
            'activa'             => $bajas === $n ? 'B' : 'A',
            'ultima_revision'    => $ultima,
            'proxima_revision'   => $proxima,
            'is_user_added'      => false,
            'created_by'         => null,
            'created_at'         => null,
            'intervenciones'     => $sumInt,
            'alta_baja'          => $bajas === $n ? 'BAJA' : 'ALTA',
            'ip_interna'         => '',
            'tipo_realizacion'   => '',
            'tipo_mantenimiento' => 'Preventivo',
            'fecha_pausado'      => $pausados ? min($pausados) : null,
            'fecha_bloqueo_ini'  => count($bloqueados) === $n ? $bloqIniMin : null,
            'fecha_bloqueo_fin'  => count($bloqueados) === $n ? $bloqFinMax : null,
            'consolidada'        => true,
            'sub_tareas'         => $sub,
        ];
    }

    /** Devuelve una tarea por id (o null). */
    public static function getTareaById(int $id): ?array
    {
        if (!self::usePg()) return null;
        return Db::pgFetchOne("
            SELECT id, orden, tarea, cod_maquina_mant, desc_maquina,
                   grupo, desc_grupo, periodicidad, desc_tarea, activa,
                   to_char(ultima_revision,  'YYYY-MM-DD') AS ultima_revision,
                   to_char(proxima_revision, 'YYYY-MM-DD') AS proxima_revision,
                   is_user_added, created_by,
                   alta_baja, ip_interna, tipo_realizacion, tipo_mantenimiento,
                   tiempo_estimado,
                   to_char(fecha_pausado,     'YYYY-MM-DD') AS fecha_pausado,
                   to_char(fecha_bloqueo_ini, 'YYYY-MM-DD') AS fecha_bloqueo_ini,
                   to_char(fecha_bloqueo_fin, 'YYYY-MM-DD') AS fecha_bloqueo_fin
              FROM mant_plan
             WHERE id = ?
        ", [$id]);
    }

    /**
     * Crea una nueva tarea de mantenimiento para una máquina existente.
     * Asigna orden = 'U' + nextval(secuencia) para evitar colisiones con
     * los números del Excel.
     *
     * Requeridos:  cod_maquina_mant, desc_maquina, tarea, periodicidad, desc_tarea, fecha_primera_revision
     * Opcionales:  grupo, desc_grupo, activa (default 'A'), created_by
     */
    public static function createTarea(array $data): array
    {
        if (!self::usePg()) {
            throw new RuntimeException('createTarea requiere modo PostgreSQL');
        }
        $required = ['cod_maquina_mant', 'tarea', 'periodicidad', 'fecha_primera_revision'];
        foreach ($required as $k) {
            if (!isset($data[$k]) || $data[$k] === '') {
                throw new InvalidArgumentException("Campo obligatorio: $k");
            }
        }
        $fecha = (string)$data['fecha_primera_revision'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new InvalidArgumentException("fecha_primera_revision debe ser YYYY-MM-DD");
        }
        // La descripción de la máquina se toma del catálogo (la máquina debe existir).
        $maq = self::getMaquina((string)$data['cod_maquina_mant']);
        if (!$maq) {
            throw new InvalidArgumentException("La máquina '{$data['cod_maquina_mant']}' no existe en el catálogo. Créala primero.");
        }
        $data['desc_maquina'] = $maq['desc_maquina'];

        $pdo = Db::pg();
        $next = $pdo->query("SELECT nextval('mant_plan_user_orden_seq') AS n")->fetch();
        $orden = 'U' . $next['n'];

        // Campos nuevos (opcionales) - migracion 006
        $altaBaja = strtoupper(trim((string)($data['alta_baja'] ?? 'ALTA')));
        if ($altaBaja !== 'ALTA' && $altaBaja !== 'BAJA') $altaBaja = 'ALTA';
        $ipInterna = trim((string)($data['ip_interna'] ?? '')) !== '' ? trim((string)$data['ip_interna']) : null;
        $tReal = trim((string)($data['tipo_realizacion'] ?? ''));
        $tReal = ($tReal === 'Interno' || $tReal === 'Externo') ? $tReal : null;
        $tMant = trim((string)($data['tipo_mantenimiento'] ?? ''));
        $tMant = ($tMant === 'Preventivo' || $tMant === 'Predictivo') ? $tMant : null;

        // Tiempo estimado (migracion 011): minutos enteros 0..10000, null aceptado.
        $tiempo = null;
        if (isset($data['tiempo_estimado']) && $data['tiempo_estimado'] !== '' && $data['tiempo_estimado'] !== null) {
            if (!is_numeric($data['tiempo_estimado'])) {
                throw new InvalidArgumentException("tiempo_estimado debe ser numero entero (minutos)");
            }
            $iv = (int)$data['tiempo_estimado'];
            if ($iv < 0 || $iv > 10000) {
                throw new InvalidArgumentException("tiempo_estimado fuera de rango (0..10000)");
            }
            $tiempo = $iv;
        }

        $st = $pdo->prepare("
            INSERT INTO mant_plan (
                orden, tarea, cod_maquina_mant, desc_maquina,
                grupo, desc_grupo, periodicidad, desc_tarea, activa,
                ultima_revision, proxima_revision,
                is_user_added, created_by,
                alta_baja, ip_interna, tipo_realizacion, tipo_mantenimiento,
                tiempo_estimado
            ) VALUES (
                :orden, :tarea, :cmm, :desc_maquina,
                :grupo, :desc_grupo, :periodicidad, :desc_tarea, :activa,
                NULL, :primera,
                TRUE, :created_by,
                :alta, :ip, :tipo_real, :tipo_mant,
                :tiempo
            )
            RETURNING id
        ");
        $st->execute([
            ':orden'        => $orden,
            ':tarea'        => trim((string)$data['tarea']),
            ':cmm'          => (string)$data['cod_maquina_mant'],
            ':desc_maquina' => (string)$data['desc_maquina'],
            ':grupo'        => isset($data['grupo'])      && $data['grupo']      !== '' ? (string)$data['grupo']      : null,
            ':desc_grupo'   => isset($data['desc_grupo']) && $data['desc_grupo'] !== '' ? (string)$data['desc_grupo'] : null,
            ':periodicidad' => strtoupper(trim((string)$data['periodicidad'])),
            ':desc_tarea'   => trim((string)($data['desc_tarea'] ?? '')) ?: null,
            ':activa'       => (string)($data['activa'] ?? 'A'),
            ':primera'      => $fecha,
            ':created_by'   => isset($data['created_by']) && $data['created_by'] !== '' ? (string)$data['created_by'] : null,
            ':alta'         => $altaBaja,
            ':ip'           => $ipInterna,
            ':tipo_real'    => $tReal,
            ':tipo_mant'    => $tMant,
            ':tiempo'       => $tiempo,
        ]);
        $id = (int)$st->fetch()['id'];
        return self::getTareaById($id);
    }

    /**
     * Actualiza una tarea existente (por id).
     * Campos editables: tarea, desc_tarea, periodicidad, ultima_revision,
     * proxima_revision, activa, grupo, desc_grupo.
     * No se permite cambiar cod_maquina_mant ni id.
     */
    public static function updateTarea(int $id, array $data): array
    {
        if (!self::usePg()) {
            throw new RuntimeException('updateTarea requiere modo PostgreSQL');
        }
        $current = self::getTareaById($id);
        if (!$current) throw new RuntimeException("Tarea $id no encontrada");

        $editable = ['tarea','desc_tarea','periodicidad','ultima_revision','proxima_revision',
                     'activa','grupo','desc_grupo',
                     'alta_baja','ip_interna','tipo_realizacion','tipo_mantenimiento',
                     'tiempo_estimado',
                     'fecha_pausado',
                     'fecha_bloqueo_ini','fecha_bloqueo_fin'];
        $dateFields = ['ultima_revision','proxima_revision','fecha_pausado',
                       'fecha_bloqueo_ini','fecha_bloqueo_fin'];
        $sets = []; $params = [':id' => $id];
        foreach ($editable as $k) {
            if (!array_key_exists($k, $data)) continue;
            $v = $data[$k];
            if ($k === 'periodicidad') $v = strtoupper(trim((string)$v));
            if ($k === 'alta_baja') {
                $u = strtoupper(trim((string)$v));
                if ($u !== 'ALTA' && $u !== 'BAJA') {
                    throw new InvalidArgumentException("alta_baja debe ser 'ALTA' o 'BAJA'");
                }
                $v = $u;
            }
            if ($k === 'tipo_realizacion') {
                $u = trim((string)$v);
                if ($u !== '' && $u !== 'Interno' && $u !== 'Externo') {
                    throw new InvalidArgumentException("tipo_realizacion debe ser 'Interno' o 'Externo'");
                }
                $v = $u !== '' ? $u : null;
            }
            if ($k === 'tipo_mantenimiento') {
                $u = trim((string)$v);
                if ($u !== '' && $u !== 'Preventivo' && $u !== 'Predictivo') {
                    throw new InvalidArgumentException("tipo_mantenimiento debe ser 'Preventivo' o 'Predictivo'");
                }
                $v = $u !== '' ? $u : null;
            }
            if ($k === 'tiempo_estimado') {
                if ($v === '' || $v === null) {
                    $v = null;
                } else {
                    if (!is_numeric($v)) {
                        throw new InvalidArgumentException("tiempo_estimado debe ser un entero (minutos) o null");
                    }
                    $iv = (int)$v;
                    if ($iv < 0 || $iv > 10000) {
                        throw new InvalidArgumentException("tiempo_estimado fuera de rango (0..10000 minutos)");
                    }
                    $v = $iv;
                }
            }
            if (in_array($k, $dateFields, true)) {
                $v = ($v !== '' && $v !== null) ? (string)$v : null;
                if ($v !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    throw new InvalidArgumentException("$k debe ser YYYY-MM-DD o null");
                }
            } elseif (!in_array($k, ['alta_baja','tipo_realizacion','tipo_mantenimiento','tiempo_estimado'], true)) {
                $v = is_string($v) ? trim($v) : $v;
                if ($v === '') $v = null;
            }
            $sets[] = "$k = :$k";
            $params[":$k"] = $v;
        }
        if (!$sets) return $current;

        // Coherencia del rango de bloqueo: si llegan los dos, ini <= fin.
        // Si llega solo uno, replicar el otro desde el estado actual.
        $iniIn = array_key_exists('fecha_bloqueo_ini', $data);
        $finIn = array_key_exists('fecha_bloqueo_fin', $data);
        if ($iniIn || $finIn) {
            $ini = $iniIn ? ($data['fecha_bloqueo_ini'] ?: null) : ($current['fecha_bloqueo_ini'] ?? null);
            $fin = $finIn ? ($data['fecha_bloqueo_fin'] ?: null) : ($current['fecha_bloqueo_fin'] ?? null);
            if (($ini && !$fin) || (!$ini && $fin)) {
                throw new InvalidArgumentException('Bloqueo: indica fecha de inicio y de fin (o deja ambos vacíos)');
            }
            if ($ini && $fin && $ini > $fin) {
                throw new InvalidArgumentException('Bloqueo: fecha de inicio no puede ser posterior a la de fin');
            }
        }

        Db::pgExec("UPDATE mant_plan SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        return self::getTareaById($id);
    }

    /**
     * Borra una tarea por id. Devuelve true si se borró.
     * Las intervenciones registradas en mant_completions NO se borran (audit trail).
     * El override de periodicidad y los pendientes asociados sí se limpian.
     */
    public static function deleteTarea(int $id): bool
    {
        if (!self::usePg()) {
            throw new RuntimeException('deleteTarea requiere modo PostgreSQL');
        }
        $row = self::getTareaById($id);
        if (!$row) return false;

        $pdo = Db::pg();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("DELETE FROM mant_periodicidad_overrides WHERE orden = ? AND tarea = ?");
            $st->execute([$row['orden'], $row['tarea']]);
            $st = $pdo->prepare("DELETE FROM mant_pendientes WHERE orden = ? AND tarea = ?");
            $st->execute([$row['orden'], $row['tarea']]);
            $st = $pdo->prepare("DELETE FROM mant_plan WHERE id = ?");
            $st->execute([$id]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ────────────── Consolidación SECUENCIA (racks / plataformas) ──────────────

    /**
     * ¿La descripción de máquina pertenece a SECUENCIA (E66, RACKS, PLATAFORMAS)?
     * Misma lógica que el JS de la vista de mantenimiento (ACC_GROUPS).
     */
    public static function esSecuencia(string $desc): bool
    {
        $s = trim($desc);
        if ($s === '') return false;
        if (preg_match('/^E66\b|^E66[_\s\-]/i', $s)) return true;
        if (preg_match('/^RACK[\s\-]/i', $s))        return true;
        if (preg_match('/^PLATAFORMA/i', $s))        return true;
        return false;
    }

    /**
     * ¿La descripción es de las consolidables (RACKS o PLATAFORMAS)? E66 NO
     * se consolida: tiene tareas variadas que no encajan en una revisión única.
     */
    public static function esConsolidable(string $desc): bool
    {
        $s = trim($desc);
        if ($s === '') return false;
        if (preg_match('/^RACK[\s\-]/i', $s))    return true;
        if (preg_match('/^PLATAFORMA/i', $s))    return true;
        if (preg_match('/^TROLEY[\s\-]/i', $s))  return true;
        return false;
    }

    /**
     * Periodicidad → "rango" de exigencia. Menor número = más exigente
     * (intervalo más corto). Para una máquina con tareas de varias
     * periodicidades elegimos la más exigente como la "principal" de la
     * fila consolidada → todas las tareas se hacen el día que toque esa.
     */
    public static function periodicidadRank(string $per): int
    {
        $tab = [
            'DIARIO'        => 1,
            'SEMANAL'       => 2,
            'QUINCENAL'     => 3,
            'MENSUAL'       => 4,
            'BIMESTRAL'     => 5,
            'BIMENSUAL'     => 5,
            'TRIMESTRAL'    => 6,
            'CUATRIMESTRAL' => 7,
            'SEMESTRAL'     => 8,
            'ANUAL'         => 9,
        ];
        return $tab[strtoupper(trim($per))] ?? 99;
    }

    /**
     * Toma una lista plana de "proximas" (las que devuelve load()) y consolida
     * las máquinas de RACKS / PLATAFORMAS: TODAS las tareas de la misma máquina
     * (sin importar periodicidades) se funden en UNA sola fila "consolidada".
     *
     * La idea: para no generar muchas acciones pequeñas separadas por días en
     * el mismo rack/plataforma, el operario hace TODAS sus revisiones de una
     * vez el día que la primera vence. Las tareas con periodicidad mayor (p.ej.
     * MENSUAL, ANUAL) se ejecutan también en ese visita, aunque eso suponga
     * adelantarlas — es lo que el usuario pide explícitamente.
     *
     * La fila consolidada contiene:
     *   - cod_maquina_mant / desc_maquina / desc_grupo
     *   - periodicidad      → la MÁS EXIGENTE (intervalo más corto) entre
     *                         las periodicidades originales — es la que marca
     *                         la cadencia con la que se vuelve a hacer todo.
     *   - proxima_revision  (la más temprana del grupo)
     *   - ultima_revision   (la más reciente del grupo)
     *   - tarea             (clave sintética 'CONSOL')
     *   - desc_tarea        "Revisión completa · N tareas"
     *   - consolidada       = true
     *   - sub_tareas        = [{orden, tarea, periodicidad, desc_tarea, ultima_revision, proxima_revision}, …]
     *
     * Para máquinas que NO son consolidables (E66, no SECUENCIA), las filas
     * pasan tal cual.
     */
    public static function consolidateSecuenciaProximas(array $proximas): array
    {
        $consolGroups = []; // key: cod_maquina_mant → group (UNA fila por máquina)
        $passThrough  = [];

        foreach ($proximas as $p) {
            $desc = (string)($p['desc_maquina'] ?? '');
            if (!self::esConsolidable($desc)) {
                $passThrough[] = $p;
                continue;
            }
            $cod = (string)($p['cod_maquina_mant'] ?? '');
            $per = strtoupper((string)($p['periodicidad'] ?? ''));
            $key = $cod; // UNA fila por máquina, todas las periodicidades juntas
            if (!isset($consolGroups[$key])) {
                $consolGroups[$key] = [
                    'cod_maquina_mant' => $cod,
                    'desc_maquina'     => $desc,
                    'grupo'            => $p['grupo']      ?? '',
                    'desc_grupo'       => $p['desc_grupo'] ?? '',
                    'periodicidad'     => $per,  // se actualiza a la más exigente
                    'activa'           => $p['activa']     ?? '',
                    'orden'            => 'CONSOL:' . $cod,
                    'tarea'            => 'CONSOL',
                    'desc_tarea'       => '',
                    'ultima_revision'  => null,
                    'proxima_revision' => null,
                    'consolidada'      => true,
                    'sub_tareas'       => [],
                    '_periodicidades'  => [],
                ];
            }
            $g = &$consolGroups[$key];
            $g['sub_tareas'][] = [
                'orden'            => (string)($p['orden'] ?? ''),
                'tarea'            => (string)($p['tarea'] ?? ''),
                'periodicidad'     => $per,
                'desc_tarea'       => (string)($p['desc_tarea'] ?? ''),
                'ultima_revision'  => $p['ultima_revision']  ?? null,
                'proxima_revision' => $p['proxima_revision'] ?? null,
            ];
            // Próxima del grupo = la más temprana de las sub-tareas
            $pxNew = $p['proxima_revision'] ?? null;
            if ($pxNew !== null) {
                if ($g['proxima_revision'] === null || $pxNew < $g['proxima_revision']) {
                    $g['proxima_revision'] = $pxNew;
                }
            }
            // Última = la más reciente
            $ulNew = $p['ultima_revision'] ?? null;
            if ($ulNew !== null) {
                if ($g['ultima_revision'] === null || $ulNew > $g['ultima_revision']) {
                    $g['ultima_revision'] = $ulNew;
                }
            }
            // Periodicidad mostrada = la más exigente del grupo
            if ($per !== '') {
                $g['_periodicidades'][$per] = true;
                if (self::periodicidadRank($per) < self::periodicidadRank($g['periodicidad'])) {
                    $g['periodicidad'] = $per;
                }
            }
            unset($g);
        }

        $consolRows = [];
        foreach ($consolGroups as $g) {
            $n = count($g['sub_tareas']);
            $pers = array_keys($g['_periodicidades']);
            unset($g['_periodicidades']);
            // Texto con cuántas y qué periodicidades incluye
            $persStr = count($pers) > 1 ? ' · ' . implode(', ', $pers) : '';
            $g['desc_tarea'] = 'Revisión completa · ' . $n . ' tarea' . ($n === 1 ? '' : 's') . $persStr;
            $consolRows[] = $g;
        }

        return array_merge($passThrough, $consolRows);
    }
}
