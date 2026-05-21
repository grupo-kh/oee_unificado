-- ============================================================
--  011 - Campo nuevo en mant_plan: tiempo_estimado
-- ============================================================
--
--   - tiempo_estimado  INT (minutos) - tiempo previsto para la
--                       intervencion. NULL si no se conoce.
--
-- La pausa de tareas ya existe (fecha_pausado, migracion 008).
-- Esta migracion solo añade el tiempo estimado para alimentar el
-- nuevo campo del modal de "Acciones por máquina" en la UI y el
-- import desde el listado de mantenimiento.
-- ============================================================

BEGIN;

ALTER TABLE mant_plan
    ADD COLUMN IF NOT EXISTS tiempo_estimado INT;

-- Rango sensato: entre 0 y 10000 minutos (~166 horas).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'mant_plan_tiempo_estimado_chk'
    ) THEN
        ALTER TABLE mant_plan
            ADD CONSTRAINT mant_plan_tiempo_estimado_chk
            CHECK (tiempo_estimado IS NULL OR (tiempo_estimado >= 0 AND tiempo_estimado <= 10000));
    END IF;
END$$;

COMMENT ON COLUMN mant_plan.tiempo_estimado IS
    'Tiempo previsto de la intervencion en minutos. NULL = no conocido.';

INSERT INTO schema_migrations (version, description) VALUES
    ('011', 'mant_plan: tiempo_estimado (minutos)')
ON CONFLICT (version) DO NOTHING;

COMMIT;
