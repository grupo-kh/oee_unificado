<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/MaintenanceProximasMetrics.php';

function assertSameValue($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $label . ' expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assertFloatValue(float $expected, $actual, string $label): void
{
    if (abs($expected - (float)$actual) > 0.001) {
        fwrite(STDERR, $label . ' expected ' . $expected
            . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$rows = [
    [
        'cod_maquina_mant' => 'M1',
        'desc_maquina' => 'Machine 1',
        'estado' => 'vencida',
        'ya_marcada' => true,
        'tipo_marca' => 'completada',
    ],
    [
        'cod_maquina_mant' => 'M1',
        'desc_maquina' => 'Machine 1',
        'estado' => 'vencida',
        'ya_marcada' => true,
        'tipo_marca' => 'no_realizada',
    ],
    [
        'cod_maquina_mant' => 'M2',
        'desc_maquina' => 'Machine 2',
        'estado' => 'urgente',
        'ya_marcada' => false,
        'tipo_marca' => null,
    ],
    [
        'cod_maquina_mant' => 'M3',
        'desc_maquina' => 'Machine 3',
        'estado' => 'en_plazo',
        'ya_marcada' => false,
        'tipo_marca' => null,
    ],
];

$summary = MaintenanceProximasMetrics::summarizeRows($rows);

assertSameValue(4, $summary['total'], 'total');
assertSameValue(1, $summary['vencidas'], 'vencidas');
assertSameValue(1, $summary['urgentes'], 'urgentes');
assertSameValue(2, $summary['en_plazo'], 'en_plazo');
assertSameValue(1, $summary['total_hechas'], 'total_hechas');
assertFloatValue(75.0, $summary['pct_en_plazo'], 'pct_en_plazo');
assertSameValue('M1', $summary['top_maquinas'][0]['cod_maquina_mant'], 'top first cod');
assertSameValue(1, $summary['top_maquinas'][0]['vencidas'], 'top first vencidas');
assertSameValue(1, $summary['top_maquinas'][0]['en_plazo'], 'top first en_plazo');

echo "OK mant_proximas_metrics" . PHP_EOL;
