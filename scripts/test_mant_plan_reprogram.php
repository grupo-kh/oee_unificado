<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/MaintenancePlanStore.php';

function assertSameValue($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $label . ' expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

assertSameValue('2026-06-15', MaintenancePlanStore::calcularProximaRevisionLaborable('2026-06-08', 'SEMANAL'), 'weekly monday');
assertSameValue('2026-06-25', MaintenancePlanStore::calcularProximaRevisionLaborable('2026-06-17', 'SEMANAL'), 'weekly holiday adjusted next workday');
assertSameValue('2026-06-25', MaintenancePlanStore::calcularProximaRevisionLaborable('2026-06-23', 'DIARIO'), 'daily holiday adjusted next workday');
assertSameValue('2026-06-15', MaintenancePlanStore::calcularProximaRevisionLaborable('2026-06-12', 'DIARIO'), 'daily weekend adjusted next workday');
assertSameValue(null, MaintenancePlanStore::calcularProximaRevisionLaborable('2026-06-08', 'SIN PERIODICIDAD'), 'unknown periodicity');

echo "OK mant_plan_reprogram" . PHP_EOL;
