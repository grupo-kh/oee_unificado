<?php

class MaintenanceProximasMetrics
{
    public static function isCompletedMark(?array $mark): bool
    {
        if (!$mark) return false;
        $tipo = (string)($mark['tipo'] ?? '');
        if ($tipo === 'completada' || $tipo === 'recuperacion') return true;
        if ($tipo === '' && !empty($mark['fecha_intervencion'])) return true;
        return false;
    }

    /**
     * Resume las filas de Proximas Revisiones.
     *
     * Una fila completada deja de penalizar el resumen aunque su fecha
     * programada ya haya pasado; una no_realizada sigue contando como no OK.
     */
    public static function summarizeRows(array $rows): array
    {
        $vencidas = 0;
        $urgentes = 0;
        $enPlazo = 0;
        $totalHechas = 0;
        $totalNoRealizadas = 0;
        $countByMaq = [];

        foreach ($rows as $r) {
            $bucket = self::summaryBucket($r);
            if ($bucket === 'vencida') {
                $vencidas++;
            } elseif ($bucket === 'urgente') {
                $urgentes++;
            } else {
                $enPlazo++;
            }

            if (self::rowHasCompletedMark($r)) $totalHechas++;
            if ((string)($r['tipo_marca'] ?? '') === 'no_realizada') $totalNoRealizadas++;

            $cm = (string)($r['cod_maquina_mant'] ?? '');
            if ($cm === '') continue;
            if (!isset($countByMaq[$cm])) {
                $countByMaq[$cm] = [
                    'cod_maquina_mant' => $cm,
                    'desc_maquina' => (string)($r['desc_maquina'] ?? ''),
                    'total' => 0,
                    'vencidas' => 0,
                    'urgentes' => 0,
                    'en_plazo' => 0,
                ];
            }
            $countByMaq[$cm]['total']++;
            $countByMaq[$cm][$bucket === 'vencida' ? 'vencidas' : ($bucket === 'urgente' ? 'urgentes' : 'en_plazo')]++;
        }

        $total = count($rows);
        $pctEnPlazo = $total > 0 ? round(($enPlazo + $urgentes) / $total * 100, 2) : 0;

        $topMaquinas = array_values($countByMaq);
        usort($topMaquinas, function ($a, $b) {
            if ($a['vencidas'] !== $b['vencidas']) return $b['vencidas'] - $a['vencidas'];
            if ($a['urgentes'] !== $b['urgentes']) return $b['urgentes'] - $a['urgentes'];
            return $b['total'] - $a['total'];
        });

        return [
            'total' => $total,
            'vencidas' => $vencidas,
            'urgentes' => $urgentes,
            'en_plazo' => $enPlazo,
            'pct_en_plazo' => $pctEnPlazo,
            'top_maquinas' => $topMaquinas,
            'total_hechas' => $totalHechas,
            'total_no_realizadas' => $totalNoRealizadas,
        ];
    }

    private static function summaryBucket(array $row): string
    {
        if (self::rowHasCompletedMark($row)) return 'en_plazo';
        if ((string)($row['tipo_marca'] ?? '') === 'no_realizada') return 'vencida';

        $estado = (string)($row['estado'] ?? '');
        if ($estado === 'vencida') return 'vencida';
        if ($estado === 'urgente') return 'urgente';
        return 'en_plazo';
    }

    private static function rowHasCompletedMark(array $row): bool
    {
        if (array_key_exists('marca_completada', $row)) {
            return (bool)$row['marca_completada'];
        }
        if (empty($row['ya_marcada'])) return false;
        $tipo = (string)($row['tipo_marca'] ?? '');
        return $tipo === 'completada' || $tipo === 'recuperacion';
    }
}
