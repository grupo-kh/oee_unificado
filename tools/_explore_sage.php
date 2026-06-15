<?php
// TEMP: exploración del esquema Logicclass (Sage) para el Tablero Kanban - Entregas.
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../config/database.php';
$pdo = getConnection('sage');
function q($pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
$mode=$argv[1]??'like';
if($mode==='like'){
  $rows=q($pdo,"SELECT TABLE_NAME,TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE ? ORDER BY TABLE_TYPE,TABLE_NAME",['%'.$argv[2].'%']);
  foreach($rows as $r) echo $r['TABLE_TYPE']."\t".$r['TABLE_NAME'].PHP_EOL;
  echo '--- total: '.count($rows).PHP_EOL;
}
if($mode==='cols'){
  $rows=q($pdo,"SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH len FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=? ORDER BY ORDINAL_POSITION",[$argv[2]]);
  foreach($rows as $r) echo $r['COLUMN_NAME']."\t".$r['DATA_TYPE'].($r['len']?'('.$r['len'].')':'').PHP_EOL;
  echo '--- cols: '.count($rows).PHP_EOL;
}
if($mode==='sql'){
  $rows=q($pdo,$argv[2]);
  if(!$rows){echo '(0 filas)'.PHP_EOL;}
  else{ echo implode("\t",array_keys($rows[0])).PHP_EOL;
    foreach($rows as $r) echo implode("\t",array_map(fn($v)=>(string)$v,array_values($r))).PHP_EOL;
    echo '--- filas: '.count($rows).PHP_EOL; }
}
