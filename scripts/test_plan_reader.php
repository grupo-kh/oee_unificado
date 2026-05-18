<?php
ini_set('memory_limit', '2G');
require __DIR__ . '/../lib/PlanExcelReader.php';

// Generar slots de MAÑANA 22/04
$fecha = '2026-04-22';
$turno = 'M';
$dtStart = new DateTime($fecha . ' 06:00:00');
$dtEnd = new DateTime($fecha . ' 14:15:00');
$slots = [];
$cursor = clone $dtStart;
while ($cursor < $dtEnd) {
    $next = new DateTime($cursor->format('Y-m-d H:00:00'));
    $next->modify('+1 hour');
    if ($next > $dtEnd) $next = clone $dtEnd;
    $slots[] = [
        'hora' => (int)$cursor->format('G'),
        'ini' => $cursor->format('Y-m-d H:i:s'),
        'fin' => $next->format('Y-m-d H:i:s'),
    ];
    $cursor = $next;
}
echo "Slots MAÑANA 22/04: " . count($slots) . "\n";

$t0 = microtime(true);
$plans = PlanExcelReader::getPlanPorHora($fecha, $turno, $slots);
echo "Tiempo: " . round(microtime(true)-$t0, 1) . "s\n";
echo "Filas: " . count($plans) . "\n\n";

// Muestra por máquina BMS31
$byMaq = [];
foreach ($plans as $p) {
    $byMaq[$p['maquina']][$p['cod_articulo']][$p['hora']] = round($p['ud']);
}
foreach (['BM30','BMS31','BT','BT 3.2','LARGOIKO','CELDA K0 TICE','MONTAJE AUTOMATICO'] as $maq) {
    echo "-- $maq --\n";
    if (!isset($byMaq[$maq])) { echo "  (no data)\n"; continue; }
    foreach ($byMaq[$maq] as $ref => $h) {
        ksort($h);
        echo "  $ref: " . json_encode($h) . " total=" . array_sum($h) . "\n";
    }
}
