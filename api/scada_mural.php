<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/ScadaMural.php';

/**
 * Mural SCADA: estado y métricas en tiempo real de todas las máquinas operativas.
 *
 * Una sola llamada devuelve TODAS las máquinas que están trabajando ahora mismo
 * (el frontend refresca cada pocos segundos). Fuente: MAPEX (cfg_maquina Rt_*,
 * his_fase, F_his_ct). Réplica del panel scada.png.
 *
 * Respuesta (vía jsonOk): { ok:true, data:{ ahora, maquinas:[ ... ] } }
 */
try {
    jsonOk(ScadaMural::mural());
} catch (Throwable $e) {
    error_log('scada_mural: ' . $e->getMessage());
    jsonError('No se pudo cargar el mural SCADA', 500);
}
