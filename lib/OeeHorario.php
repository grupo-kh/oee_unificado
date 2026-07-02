<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

/**
 * Recálculo de magnitudes OEE por FRANJA HORARIA desde tablas base
 * (his_prod + his_prod_paro), para cuando el filtro por horas está activo.
 *
 * F_his_ct solo agrega por día; la tabla his_horaOEE está vacía en este MAPEX.
 * Este recálculo es APROXIMADO respecto a F_his_ct (la marcha M no cuadra 1:1)
 * pero COMPARABLE entre franjas. Produce las MISMAS columnas que la consulta
 * F_his_ct del proyecto, de modo que el resto del código y _calcDRC no cambian.
 *
 * ASUNCIÓN (verificada en este MAPEX): PPERF = 0 y PCALIDAD = 0 siempre.
 */
class OeeHorario
{
    private const EXCLUIDAS = "'Improductivos','AUX000','AUXI1','SOLD4','SOLD5'";

    /**
     * Devuelve filas con las magnitudes OEE por clave, filtradas por franja.
     * $agrupacion: 'maquina' | 'maquina_producto'.
     * Claves de cada fila: cod_maquina, maquina, M, M_Teo, M_OKNOK_TEO,
     * M_OK_TEO, PPERF, PCALIDAD, PNP (+ cod_referencia, referencia si producto).
     */
    public static function magnitudesPorClave(string $fdesde, string $fhasta,
        string $hDesde, string $hHasta, array $turnos, array $excl, string $agrupacion): array
    {
        $porProducto = ($agrupacion === 'maquina_producto');
        [$fFecha, $pFecha] = filtroFechaHora('hp.Fecha_ini', $fdesde, $fhasta, $hDesde, $hHasta);
        [$fParo,  $pParo]  = filtroFechaHora('hpp.Fecha_ini', $fdesde, $fhasta, $hDesde, $hHasta);

        // Filtros comunes (turnos + exclusiones).
        $extra  = "AND mq.Cod_maquina NOT IN (" . self::EXCLUIDAS . ")";
        $pExtra = [];
        if (!empty($turnos)) {
            $ph = implode(',', array_fill(0, count($turnos), '?'));
            $extra .= " AND ct.Cod_turno IN ($ph)";
            $pExtra = array_merge($pExtra, $turnos);
        }
        if (!empty($excl)) {
            $ph = implode(',', array_fill(0, count($excl), '?'));
            $extra .= " AND mq.Cod_maquina NOT IN ($ph)";
            $pExtra = array_merge($pExtra, $excl);
        }

        $joinFase = $porProducto ? "INNER JOIN his_fase fa ON fa.Id_his_fase = hp.Id_his_fase" : "";
        $joinProd = $porProducto
            ? "LEFT JOIN his_of o ON o.Id_his_of = fa.Id_his_of
               LEFT JOIN cfg_producto prod ON prod.Id_producto = o.Id_producto"
            : "";
        $selProd  = $porProducto ? "LTRIM(RTRIM(prod.Cod_producto)) AS cod_referencia, MAX(prod.Desc_producto) AS referencia," : "";
        $grpProd  = $porProducto ? ", LTRIM(RTRIM(prod.Cod_producto))" : "";

        // 1) Producción: bruto + unidades + ciclo nominal por clave.
        $sqlProd = "
            SELECT mq.Cod_maquina AS cod_maquina, mq.Desc_maquina AS maquina,
                   $selProd
                   SUM(DATEDIFF(SECOND, hp.Fecha_ini, ISNULL(hp.Fecha_fin, hp.Fecha_ini))) AS seg_bruto,
                   SUM(ISNULL(hp.Unidades_ok,0))  AS u_ok,
                   SUM(ISNULL(hp.Unidades_nok,0)) AS u_nok,
                   MAX(COALESCE(NULLIF(hp.SegCicloNominal,0),
                        CASE WHEN hp.Rendimientonominal1 > 0 THEN 3600.0/hp.Rendimientonominal1 END, 0)) AS ciclo_seg
            FROM his_prod hp
            INNER JOIN cfg_maquina mq ON mq.Id_maquina = hp.Id_maquina
            INNER JOIN cfg_turno   ct ON ct.Id_turno   = hp.Id_turno
            $joinFase
            $joinProd
            WHERE $fFecha $extra
            GROUP BY mq.Cod_maquina, mq.Desc_maquina $grpProd";
        $prodRows = fetchAll('mapex', $sqlProd, array_merge($pFecha, $pExtra));

        // 2) Paros por clave (para restar de M y para PNP).
        $sqlParo = "
            SELECT mq.Cod_maquina AS cod_maquina, $selProd
                   SUM(DATEDIFF(SECOND, hpp.Fecha_ini, ISNULL(hpp.Fecha_fin, hpp.Fecha_ini))) AS seg_paro
            FROM his_prod_paro hpp
            INNER JOIN his_prod    hp ON hp.Id_his_prod = hpp.Id_his_prod
            INNER JOIN cfg_maquina mq ON mq.Id_maquina  = hp.Id_maquina
            INNER JOIN cfg_turno   ct ON ct.Id_turno    = hp.Id_turno
            $joinFase
            $joinProd
            WHERE $fParo AND hpp.Fecha_fin IS NOT NULL $extra
            GROUP BY mq.Cod_maquina $grpProd";
        $paroMap = [];
        foreach (fetchAll('mapex', $sqlParo, array_merge($pParo, $pExtra)) as $r) {
            $k = $porProducto
                ? trim((string)$r['cod_maquina']) . '|' . trim((string)$r['cod_referencia'])
                : trim((string)$r['cod_maquina']);
            $paroMap[$k] = (int)$r['seg_paro'];
        }

        // 3) Ensamblar filas con formato F_his_ct.
        $out = [];
        foreach ($prodRows as $r) {
            $cod = trim((string)$r['cod_maquina']);
            $ref = $porProducto ? trim((string)$r['cod_referencia']) : '';
            $k   = $porProducto ? "$cod|$ref" : $cod;
            $segParo = $paroMap[$k] ?? 0;
            $M     = max(0, (int)$r['seg_bruto'] - $segParo);
            $ciclo = (float)$r['ciclo_seg'];
            $uOk   = (int)$r['u_ok'];
            $uNok  = (int)$r['u_nok'];
            $fila = [
                'cod_maquina'  => $cod,
                'maquina'      => trim((string)$r['maquina']),
                'M'            => $M,
                'M_Teo'        => 0,
                'M_OKNOK_TEO'  => ($uOk + $uNok) * $ciclo,
                'M_OK_TEO'     => $uOk * $ciclo,
                'PPERF'        => 0,
                'PCALIDAD'     => 0,
                'PNP'          => $segParo,
            ];
            if ($porProducto) {
                $fila['cod_referencia'] = $ref;
                $fila['referencia']     = trim((string)$r['referencia']);
            }
            $out[] = $fila;
        }
        return $out;
    }
}
