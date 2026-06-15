<?php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/PlanExcelReader.php';

/**
 * Capa de datos para "Lanzamiento de OFs" (tablet de planta).
 *
 * Lee la planificación del MISMO XLSX que usa el módulo "OEE planificado"
 * (carpeta `Planificaciones diarias\*.xlsm`, hoja PLANIFICACIÓN). Reusa
 * `PlanExcelReader::parseExcel()` que ya tiene caché en JSON.
 *
 * Estructura devuelta por parseExcel():
 *   - pedidos[maquinaDesc][orden] = ['ref','ud','h','ud_hora']
 *   - schedule[maquinaDesc][slotIdx15min] = ordenNumero
 *
 * Identificamos las OFs por orden numérico (col D del Excel). La prioridad
 * se infiere del schedule: las OFs que aparecen primero en el día son las
 * primeras 2 → se marcan como prioritarias en la UI.
 */
class OfsStore
{
    /**
     * Carga la planificación del Excel para un día (formato YYYY-MM-DD).
     * Si no existe fichero para ese día, devuelve null.
     */
    private static function cargarExcelDelDia(string $fechaYMD): ?array
    {
        $d = DateTime::createFromFormat('Y-m-d', $fechaYMD);
        if (!$d) return null;
        $dmy   = $d->format('d.m.Y');
        $local = PlanExcelReader::ensureLocalCopy($dmy);
        if (!$local) return null;
        try {
            return PlanExcelReader::parseExcel($local, $dmy);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Catálogo de estaciones desde MAPEX (cfg_maquina). Identificamos cada
     * máquina por su `Cod_maquina` (único, lo que enviará la tablet) y la
     * etiqueta legible es `Desc_maquina`.
     *
     * Excluimos las máquinas "auxiliares" (Improductivos, AUX000, AUXI1,
     * SOLD4, SOLD5) que no se usan para lanzar OFs.
     *
     * Opcionalmente filtramos a las que SÍ tienen plan en el Excel del día,
     * para que el dropdown solo muestre las útiles ahora mismo.
     *
     * @return array<int, array{cod:string, desc:string}>
     */
    public static function listarEstaciones(?string $fecha = null): array
    {
        // Si no hay conexión a MAPEX o falla, no podemos devolver catálogo.
        try {
            $rows = fetchAll('mapex', "
                SELECT Cod_maquina, Desc_maquina
                  FROM cfg_maquina
                 WHERE Cod_maquina NOT IN ('Improductivos','AUX000','AUXI1','SOLD4','SOLD5')
                 ORDER BY Desc_maquina
            ");
        } catch (Throwable $e) {
            return [];
        }

        // Set de descripciones normalizadas que tienen plan en el Excel
        $conPlan = [];
        if ($fecha) {
            $data = self::cargarExcelDelDia($fecha);
            if ($data && !empty($data['pedidos'])) {
                foreach ($data['pedidos'] as $maqDescNorm => $peds) {
                    if (!empty($peds)) $conPlan[(string)$maqDescNorm] = true;
                }
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $cod  = (string)($r['Cod_maquina']  ?? '');
            $desc = (string)($r['Desc_maquina'] ?? $cod);
            if ($cod === '') continue;
            $descNorm = PlanExcelReader::normalizeDesc($desc);
            // Si tenemos info de plan del día, ocultamos las máquinas sin OFs.
            // Si no hay Excel cargado, devolvemos TODAS para que el operario
            // pueda seleccionar y ver el error "no hay plan" si procede.
            if ($conPlan && !isset($conPlan[$descNorm])) continue;
            $out[] = ['cod' => $cod, 'desc' => $desc];
        }
        return $out;
    }

    /**
     * Devuelve la `Desc_maquina` (sin normalizar) de MAPEX para un Cod_maquina.
     * Devuelve null si no existe.
     */
    private static function descPorCod(string $cod): ?string
    {
        if ($cod === '') return null;
        static $cache = [];
        if (array_key_exists($cod, $cache)) return $cache[$cod];
        try {
            $r = fetchAll('mapex',
                "SELECT TOP 1 Desc_maquina FROM cfg_maquina WHERE Cod_maquina = ?",
                [$cod]
            );
            $cache[$cod] = $r ? (string)($r[0]['Desc_maquina'] ?? $cod) : null;
        } catch (Throwable $e) {
            $cache[$cod] = null;
        }
        return $cache[$cod];
    }

    /**
     * Devuelve hasta 8 OFs planificadas para una estación + fecha.
     *
     * El "código" de máquina aquí coincide con `Desc_maquina` (es lo que
     * trae el Excel). El identificador de la OF es "OF" + número de orden.
     *
     * @return array<int, array{
     *     of:string, ref:string, ubicacion_galga:string,
     *     cantidad:float, uds:string, duracion_horas:float,
     *     notas:string, responsable:string, prioridad:bool, slot:int
     * }>
     */
    public static function listarPlanificadas(string $codMaquina, string $fecha): array
    {
        $data = self::cargarExcelDelDia($fecha);
        if (!$data) return [];

        // Traducimos Cod_maquina (MAPEX) → Desc_maquina normalizada que es
        // la clave usada por PlanExcelReader en pedidos[]/schedule[].
        $desc = self::descPorCod($codMaquina);
        if ($desc === null) return [];
        $maqKey = PlanExcelReader::normalizeDesc($desc);
        if (empty($data['pedidos'][$maqKey])) return [];

        $pedidos  = $data['pedidos'][$maqKey];
        $schedule = $data['schedule'][$maqKey] ?? [];

        // 1) Orden de aparición en el schedule = orden de ejecución del día.
        //    La primera vez que aparece un orden en los slots 15 min marca
        //    su prioridad. Las que aparecen al principio = más urgentes.
        $primeraAparicion = []; // ordenNum → slotIdx
        foreach ($schedule as $slot => $ordenNum) {
            $ordenNum = (int)$ordenNum;
            if ($ordenNum > 0 && !isset($primeraAparicion[$ordenNum])) {
                $primeraAparicion[$ordenNum] = (int)$slot;
            }
        }

        // 2) Construir la lista de OFs (orden + ref + ud + h)
        $ofs = [];
        foreach ($pedidos as $ordenNum => $p) {
            $ofs[] = [
                'of'              => 'OF' . (int)$ordenNum,
                '_orden'          => (int)$ordenNum,
                '_aparicion'      => $primeraAparicion[$ordenNum] ?? PHP_INT_MAX,
                'ref'             => (string)($p['ref'] ?? ''),
                'ubicacion_galga' => '',                       // pendiente en Sage
                'cantidad'        => (float)($p['ud'] ?? 0),
                'uds'             => 'UDS',
                'duracion_horas'  => (float)($p['h']  ?? 0),
                'notas'           => '',
                'responsable'     => '',
                'prioridad'       => false,
            ];
        }
        // Ordenar por aparición en el cronograma (las que entran antes, primero)
        usort($ofs, function ($a, $b) {
            if ($a['_aparicion'] !== $b['_aparicion']) return $a['_aparicion'] <=> $b['_aparicion'];
            return $a['_orden'] <=> $b['_orden'];
        });

        // 3) Las dos primeras = prioritarias (color naranja saturado en UI)
        foreach ($ofs as $i => &$o) {
            $o['prioridad'] = ($i < 2);
            unset($o['_aparicion'], $o['_orden']);
        }
        unset($o);

        // 4) Excluir las ya lanzadas hoy desde la tablet
        $excluidas = self::ofsLanzadasHoy($codMaquina, $fecha);
        $ofs = array_values(array_filter($ofs, fn($r) => !in_array($r['of'], $excluidas, true)));

        // 5) Truncar a los 8 huecos del mockup y añadir slot 1..8
        $out = [];
        foreach ($ofs as $i => $r) {
            $out[] = array_merge($r, ['slot' => $i + 1]);
            if (count($out) >= 8) break;
        }
        return $out;
    }

    /**
     * Códigos de OF ya lanzados hoy por esa máquina. Sirven para excluir
     * del listado lo que ya está hecho.
     *
     * @return array<int, string>
     */
    private static function ofsLanzadasHoy(string $codMaquina, string $fecha): array
    {
        if (!self::usePg()) return [];
        try {
            $rows = Db::pgFetchAll("
                SELECT DISTINCT of_codigo
                  FROM ofs_lanzadas
                 WHERE cod_maquina = :m
                   AND CAST(lanzada_at AS DATE) = :f
                   AND estado IN ('lanzada','en_curso')
            ", [':m' => $codMaquina, ':f' => $fecha]);
            return array_map(fn($r) => (string)$r['of_codigo'], $rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Detalle ampliado de UNA OF para la pantalla del mockup 2.
     * Busca directamente en los pedidos del Excel (sin truncar a 8) y, si
     * la OF ya fue lanzada hoy, recupera el detalle desde BD.
     */
    public static function detalleOf(string $codMaquina, string $fecha, string $ofCodigo): ?array
    {
        $data = self::cargarExcelDelDia($fecha);
        $desc = self::descPorCod($codMaquina);
        $maqKey = $desc !== null ? PlanExcelReader::normalizeDesc($desc) : null;
        if ($data && $maqKey && !empty($data['pedidos'][$maqKey])) {
            // OF en formato "OF1234" → orden numérico 1234
            $ordenNum = (int)ltrim((string)$ofCodigo, 'OFof');
            if (isset($data['pedidos'][$maqKey][$ordenNum])) {
                $p = $data['pedidos'][$maqKey][$ordenNum];
                return [
                    'of'              => 'OF' . $ordenNum,
                    'ref'             => (string)($p['ref'] ?? ''),
                    'ubicacion_galga' => '',
                    'cantidad'        => (float)($p['ud'] ?? 0),
                    'uds'             => 'UDS',
                    'duracion_horas'  => (float)($p['h']  ?? 0),
                    'notas'           => '',
                    'responsable'     => '',
                    'prioridad'       => false,
                ];
            }
        }
        // Si la OF ya fue lanzada hoy (excluida del listado), miramos en BD
        if (self::usePg()) {
            try {
                $r = Db::pgFetchOne("
                    SELECT of_codigo, referencia, cod_maquina, desc_maquina,
                           cantidad, duracion_horas, ubicacion_galga, notas,
                           operario, estado,
                           to_char(lanzada_at, 'YYYY-MM-DD HH24:MI:SS') AS lanzada_at
                      FROM ofs_lanzadas
                     WHERE of_codigo = :o
                  ORDER BY lanzada_at DESC LIMIT 1
                ", [':o' => $ofCodigo]);
                if ($r) {
                    return [
                        'of'              => (string)$r['of_codigo'],
                        'ref'             => (string)($r['referencia'] ?? ''),
                        'ubicacion_galga' => (string)($r['ubicacion_galga'] ?? ''),
                        'cantidad'        => (float)($r['cantidad'] ?? 0),
                        'uds'             => 'UDS',
                        'duracion_horas'  => (float)($r['duracion_horas'] ?? 0),
                        'notas'           => (string)($r['notas'] ?? ''),
                        'responsable'     => '',
                        'prioridad'       => false,
                        'estado'          => (string)($r['estado'] ?? 'lanzada'),
                        'lanzada_at'      => (string)($r['lanzada_at'] ?? ''),
                        'operario'        => (string)($r['operario'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {
                // ignoramos: si BD no responde, devolvemos null
            }
        }
        return null;
    }

    /**
     * Registra el lanzamiento de una OF desde la tablet. Devuelve el id
     * insertado y la ruta del PDF generado (a futuro). En esta versión el
     * PDF es opcional (no se genera todavía).
     */
    public static function registrarLanzamiento(array $data): int
    {
        if (!self::usePg()) throw new RuntimeException('Requiere PostgreSQL');
        $required = ['of_codigo','cod_maquina'];
        foreach ($required as $k) {
            if (empty($data[$k])) {
                throw new InvalidArgumentException("Falta $k");
            }
        }
        Db::pgExec("
            INSERT INTO ofs_lanzadas
                (of_codigo, referencia, cod_maquina, desc_maquina,
                 cantidad, duracion_horas, ubicacion_galga, notas, notas_operario,
                 operario, estado)
            VALUES
                (:oc, :ref, :cm, :dm,
                 :cant, :dur, :galga, :notas, :notop,
                 :op, 'lanzada')
        ", [
            ':oc'    => (string)$data['of_codigo'],
            ':ref'   => $data['ref']             ?? null,
            ':cm'    => (string)$data['cod_maquina'],
            ':dm'    => $data['desc_maquina']    ?? null,
            ':cant'  => $data['cantidad']        ?? null,
            ':dur'   => $data['duracion_horas']  ?? null,
            ':galga' => $data['ubicacion_galga'] ?? null,
            ':notas' => $data['notas']           ?? null,
            ':notop' => $data['notas_operario']  ?? null,
            ':op'    => $data['operario']        ?? null,
        ]);
        $r = Db::pgFetchOne("SELECT lastval() AS id");
        return (int)($r['id'] ?? 0);
    }

    private static function usePg(): bool
    {
        return defined('MANT_USE_PG') && MANT_USE_PG === true;
    }
}
