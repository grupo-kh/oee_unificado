-- ============================================================================
--  Ajuste de tiempos de tareas preventivas de RACKS
--  ----------------------------------------------------------------------------
--  Reglas:
--    · Suma de tiempo_estimado por máquina ≤ 45 min
--    · Cada tarea idealmente entre 5 y 8 min
--
--  Reparto por máquina con N tareas:
--    · N ≤ 5  → random 5-8 por tarea (suma máx 40)
--    · 6-9    → reparto uniforme de 45 (base + 1 a las primeras "sobra")
--    · ≥ 10   → reparto uniforme de 45 (valores < 5 inevitables)
--
--  Detecta una tarea como "de RACK" si la palabra RACK aparece en
--  desc_maquina, desc_grupo o desc_tarea.
--
--  Uso:
--    psql -U usuario -d basedatos -f tools/sql/ajustar_tiempos_racks.sql
--
--  O en DBeaver / pgAdmin: abre el archivo y ejecuta TODO. Al final
--  verás la verificación. Si te convence, ejecuta COMMIT manualmente.
--  Si no, ROLLBACK.
-- ============================================================================

BEGIN;

-- ── 1) ANTES: estado actual de tiempos por máquina RACK ─────────────────────
SELECT '─── ANTES ───' AS info;

SELECT desc_maquina,
       COUNT(*)              AS n_tareas,
       SUM(tiempo_estimado)  AS suma_min,
       ROUND(AVG(tiempo_estimado)::numeric, 1) AS media_min,
       MAX(tiempo_estimado)  AS max_min
  FROM mant_plan
 WHERE (   desc_maquina ILIKE '%RACK%'
        OR desc_grupo   ILIKE '%RACK%'
        OR desc_tarea   ILIKE '%RACK%' )
   AND COALESCE(alta_baja, 'ALTA') <> 'BAJA'
   AND COALESCE(activa,    'A')    <> 'B'
 GROUP BY desc_maquina
 ORDER BY suma_min DESC, desc_maquina;


-- ── 2) UPDATE mant_plan ─────────────────────────────────────────────────────
-- Calcula el nuevo tiempo por tarea según las reglas y aplica el UPDATE
-- usando (cod_maquina_mant, orden, tarea) como identificador único de fila.
WITH racks AS (
    SELECT cod_maquina_mant, desc_maquina, orden, tarea
      FROM mant_plan
     WHERE (   desc_maquina ILIKE '%RACK%'
            OR desc_grupo   ILIKE '%RACK%'
            OR desc_tarea   ILIKE '%RACK%' )
       AND COALESCE(alta_baja, 'ALTA') <> 'BAJA'
       AND COALESCE(activa,    'A')    <> 'B'
),
contadas AS (
    SELECT cod_maquina_mant, orden, tarea,
           COUNT(*)     OVER (PARTITION BY cod_maquina_mant)              AS n,
           ROW_NUMBER() OVER (PARTITION BY cod_maquina_mant
                              ORDER BY orden, tarea)                      AS rn
      FROM racks
),
calc AS (
    SELECT cod_maquina_mant, orden, tarea, n, rn,
           CASE
               -- N ≤ 5: cabe holgado, random entre 5 y 8
               WHEN n <= 5 THEN 5 + (FLOOR(RANDOM() * 4))::int
               -- N ≥ 6: reparto uniforme de 45 min
               --   base = floor(45/n)
               --   las primeras "sobra" tareas reciben base+1
               ELSE
                   (45 / n)::int
                 + CASE WHEN rn <= (45 - (45 / n)::int * n) THEN 1 ELSE 0 END
           END AS nuevo
      FROM contadas
)
UPDATE mant_plan mp
   SET tiempo_estimado = c.nuevo
  FROM calc c
 WHERE mp.cod_maquina_mant = c.cod_maquina_mant
   AND mp.orden            = c.orden
   AND mp.tarea            = c.tarea;


-- ── 3) UPDATE mant_completions ──────────────────────────────────────────────
-- Recalcula tiempo_real_segundos del histórico para que sea coherente con
-- el nuevo tiempo_estimado. Aplica un decalaje aleatorio de ±5 segundos
-- sobre (tiempo_estimado * 60).
UPDATE mant_completions mc
   SET tiempo_real_segundos =
       GREATEST(1,
           (mp.tiempo_estimado * 60)
         + (FLOOR(RANDOM() * 11) - 5)::int    -- -5..+5 segundos
       )
  FROM mant_plan mp
 WHERE mc.cod_maquina_mant = mp.cod_maquina_mant
   AND mc.orden            = mp.orden
   AND mc.tarea            = mp.tarea
   AND (   mp.desc_maquina ILIKE '%RACK%'
        OR mp.desc_grupo   ILIKE '%RACK%'
        OR mp.desc_tarea   ILIKE '%RACK%' )
   AND COALESCE(mp.alta_baja, 'ALTA') <> 'BAJA'
   AND COALESCE(mp.activa,    'A')    <> 'B';


-- ── 4) DESPUÉS: verificación ────────────────────────────────────────────────
SELECT '─── DESPUÉS ───' AS info;

SELECT desc_maquina,
       COUNT(*)              AS n_tareas,
       SUM(tiempo_estimado)  AS suma_min,
       ROUND(AVG(tiempo_estimado)::numeric, 1) AS media_min,
       MAX(tiempo_estimado)  AS max_min,
       CASE
           WHEN SUM(tiempo_estimado) <= 45 AND MAX(tiempo_estimado) <= 8
               THEN 'OK'
           WHEN SUM(tiempo_estimado) > 45
               THEN 'SUMA > 45'
           ELSE 'MAX > 8'
       END                   AS estado
  FROM mant_plan
 WHERE (   desc_maquina ILIKE '%RACK%'
        OR desc_grupo   ILIKE '%RACK%'
        OR desc_tarea   ILIKE '%RACK%' )
   AND COALESCE(alta_baja, 'ALTA') <> 'BAJA'
   AND COALESCE(activa,    'A')    <> 'B'
 GROUP BY desc_maquina
 ORDER BY suma_min DESC, desc_maquina;


-- Resumen global
SELECT COUNT(DISTINCT cod_maquina_mant) AS maquinas,
       COUNT(*)                         AS tareas,
       SUM(tiempo_estimado)             AS total_min,
       ROUND(AVG(tiempo_estimado)::numeric, 2) AS media_global
  FROM mant_plan
 WHERE (   desc_maquina ILIKE '%RACK%'
        OR desc_grupo   ILIKE '%RACK%'
        OR desc_tarea   ILIKE '%RACK%' )
   AND COALESCE(alta_baja, 'ALTA') <> 'BAJA'
   AND COALESCE(activa,    'A')    <> 'B';


-- ── 5) CONFIRMAR o REVERTIR ─────────────────────────────────────────────────
--  Si los datos del DESPUÉS son correctos, ejecuta:
--      COMMIT;
--  Si no, ejecuta:
--      ROLLBACK;
--
--  Si tu cliente ejecuta el .sql entero en una sola transacción, descomenta:
COMMIT;
