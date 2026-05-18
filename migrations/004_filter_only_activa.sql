-- ============================================================
--  004 - Solo máquinas activas (columna H = 'A' en el Excel)
-- ============================================================
--
-- Especificación olvidada al sembrar la primera versión: del Excel
-- "PROXIMAS REV." sólo deben aparecer las filas cuya columna H valga
-- 'A' (máquinas activas), descartando las 'B' (inactivas/baja).
--
-- Esta migración limpia los datos sembrados desde Excel que sean 'B'
-- y elimina las máquinas que queden huérfanas (sin tareas) tras la
-- limpieza, siempre y cuando NO sean creadas desde la web (esas se
-- conservan tal cual: is_user_added = TRUE).
--
-- mant_completions (histórico de intervenciones) NO se toca: es
-- registro de auditoría y debe conservarse aunque la tarea/máquina
-- haya pasado a inactiva.
-- ============================================================

BEGIN;

-- 1) Borra los overrides de periodicidad asociados a tareas B.
DELETE FROM mant_periodicidad_overrides ovr
 USING mant_plan p
 WHERE ovr.orden = p.orden
   AND ovr.tarea = p.tarea
   AND COALESCE(UPPER(TRIM(p.activa)), '') <> 'A'
   AND p.is_user_added = FALSE;

-- 2) Borra los pendientes manuales asociados a tareas B.
DELETE FROM mant_pendientes pend
 USING mant_plan p
 WHERE pend.orden = p.orden
   AND pend.tarea = p.tarea
   AND COALESCE(UPPER(TRIM(p.activa)), '') <> 'A'
   AND p.is_user_added = FALSE;

-- 3) Borra las propias tareas B del plan (preservando las creadas por usuario).
DELETE FROM mant_plan
 WHERE COALESCE(UPPER(TRIM(activa)), '') <> 'A'
   AND is_user_added = FALSE;

-- 4) Borra del catálogo las máquinas que ya no tienen ninguna tarea
--    y que NO fueron creadas desde la web.
DELETE FROM mant_maquinas m
 WHERE m.is_user_added = FALSE
   AND NOT EXISTS (
       SELECT 1 FROM mant_plan p WHERE p.cod_maquina_mant = m.cod_maquina_mant
   );

INSERT INTO schema_migrations (version, description) VALUES
    ('004', 'Filter to keep only activa=A rows (drop B rows seeded from Excel)')
ON CONFLICT (version) DO NOTHING;

COMMIT;
