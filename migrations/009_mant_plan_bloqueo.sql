-- ============================================================
--  009 - Bloqueo temporal de tareas preventivas
-- ============================================================
--
-- Permite "bloquear" una tarea preventiva con un rango de fechas
-- (inicio + fin). Mientras hoy esté dentro del rango la tarea:
--   - NO se planifica (no aparece en próximas, semana, móvil).
--   - NO computa en cumplimiento (no genera "no realizada").
--   - Sigue visible en el modal de tareas con su badge de bloqueo.
--
-- Pensado para máquinas fuera de producción o racks puestos
-- temporalmente en stand-by donde no tiene sentido contar las
-- preventivas como atrasadas.
--
-- Diferencias frente a `fecha_pausado` (migración 008):
--   - Bloqueo tiene rango (ini + fin), no una sola fecha.
--   - Bloqueo es temporal: al pasar la fecha fin la tarea vuelve.
--   - Pausa sigue siendo "indefinido hasta reanudar manualmente".
-- ============================================================

BEGIN;

ALTER TABLE mant_plan
    ADD COLUMN IF NOT EXISTS fecha_bloqueo_ini DATE NULL,
    ADD COLUMN IF NOT EXISTS fecha_bloqueo_fin DATE NULL;

-- Coherencia: si hay rango, ini <= fin
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'mant_plan_bloqueo_rango_chk'
    ) THEN
        ALTER TABLE mant_plan
            ADD CONSTRAINT mant_plan_bloqueo_rango_chk
            CHECK (
                (fecha_bloqueo_ini IS NULL AND fecha_bloqueo_fin IS NULL)
             OR (fecha_bloqueo_ini IS NOT NULL AND fecha_bloqueo_fin IS NOT NULL
                 AND fecha_bloqueo_ini <= fecha_bloqueo_fin)
            );
    END IF;
END$$;

CREATE INDEX IF NOT EXISTS idx_plan_bloqueo_rango
    ON mant_plan(fecha_bloqueo_ini, fecha_bloqueo_fin)
    WHERE fecha_bloqueo_ini IS NOT NULL;

COMMENT ON COLUMN mant_plan.fecha_bloqueo_ini IS
    'Inicio del bloqueo temporal (inclusive). NULL = no bloqueada.';
COMMENT ON COLUMN mant_plan.fecha_bloqueo_fin IS
    'Fin del bloqueo temporal (inclusive). Mientras CURRENT_DATE esté entre ini y fin la tarea no se planifica ni computa cumplimiento.';

INSERT INTO schema_migrations (version, description) VALUES
    ('009', 'mant_plan: fecha_bloqueo_ini/fin para bloqueo temporal de tareas')
ON CONFLICT (version) DO NOTHING;

COMMIT;
