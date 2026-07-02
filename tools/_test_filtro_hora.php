<?php
require_once __DIR__ . '/../includes/helpers.php';
foreach([['sin hora','',''],['normal','06:00','14:00'],['cruza','22:00','06:00']] as [$lbl,$hd,$hh]){
  [$s,$p]=filtroFechaHora('hpp.Fecha_ini','2026-07-01','2026-07-01',$hd,$hh);
  echo str_pad($lbl,10)." params=".count($p)." | $s | ".json_encode($p)."\n";
}
