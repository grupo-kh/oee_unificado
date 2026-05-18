-- ============================================================
--  007 - Unificar las dos máquinas TURBOBENDER en una sola
-- ============================================================
--
-- Problema: en mant_maquinas existían dos filas para la misma máquina física
-- (Cod_maquina=DOBL3 / Desc_maquina=TURBOBENDER en MAPEX):
--    cod_maquina_mant='TURBO'        — 7 tareas, principalmente ANUALES
--    cod_maquina_mant='TURBOBENDER'  — 1 tarea MENSUAL
-- Esto duplicaba la máquina en los listados y partía las tareas en dos cards.
--
-- Solución: consolidar todo bajo cod='TURBOBENDER' (el más descriptivo) y
-- eliminar la fila 'TURBO' del catálogo. Las tareas mantienen sus mismos
-- (orden, tarea); el conflicto UNIQUE no se da porque los conjuntos no se
-- solapan (10452/10574/10576/10579/10642/10643/10942 vs 10431).
--
-- Migración idempotente: si ya se ejecutó (no queda fila 'TURBO'), todos los
-- UPDATE/DELETE son no-op.
-- ============================================================

BEGIN;

-- 1) Plan: tareas pasan a TURBOBENDER (mantienen orden/tarea/periodicidad).
UPDATE mant_plan
   SET cod_maquina_mant = 'TURBOBENDER',
       desc_maquina     = 'TURBOBENDER'
 WHERE cod_maquina_mant = 'TURBO';

-- 2) Histórico de intervenciones: re-etiqueta auditoría sin perder filas.
UPDATE mant_completions
   SET cod_maquina_mant = 'TURBOBENDER',
       desc_maquina     = 'TURBOBENDER'
 WHERE cod_maquina_mant = 'TURBO';

-- 3) Pendientes (banderas manuales): re-etiqueta.
UPDATE mant_pendientes
   SET cod_maquina_mant = 'TURBOBENDER',
       desc_maquina     = 'TURBOBENDER'
 WHERE cod_maquina_mant = 'TURBO';

-- 4) Borrar la fila 'TURBO' del catálogo (la máquina deja de existir).
DELETE FROM mant_maquinas
 WHERE cod_maquina_mant = 'TURBO';

INSERT INTO schema_migrations (version, description) VALUES
    ('007', 'Unificar TURBO → TURBOBENDER en mant_*')
ON CONFLICT (version) DO NOTHING;

COMMIT;
