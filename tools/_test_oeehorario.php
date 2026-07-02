<?php
require_once __DIR__ . '/../lib/OeeHorario.php';
echo "=== día completo (comparar con PoC: DOBL10 D~61, DOBL13 D~78) ===\n";
$rows=OeeHorario::magnitudesPorClave('2026-07-01','2026-07-01','00:00','23:59',[],[],'maquina');
echo "filas: ".count($rows)."\n";
foreach($rows as $r) if(in_array($r['cod_maquina'],['DOBL10','DOBL13'])){
  $D=($r['M']+$r['PNP'])>0?$r['M']/($r['M']+$r['PNP'])*100:0;
  $R=$r['M']>0?$r['M_OKNOK_TEO']/$r['M']*100:0;
  printf("  %-8s M=%-7s PNP=%-6s D=%.1f%% R=%.1f%%\n",$r['cod_maquina'],$r['M'],$r['PNP'],$D,$R);
}
echo "=== franja 09-14 (menos) ===\n";
$f=OeeHorario::magnitudesPorClave('2026-07-01','2026-07-01','09:00','14:00',[],[],'maquina');
echo "filas: ".count($f)."\n";
echo "=== por producto (franja) ===\n";
$p=OeeHorario::magnitudesPorClave('2026-07-01','2026-07-01','09:00','14:00',[],[],'maquina_producto');
echo "filas producto: ".count($p)." | ejemplo: ".(isset($p[0])?$p[0]['cod_maquina'].'/'.$p[0]['cod_referencia']:'-')."\n";
