-- ============================================================
--  008 - Pausar tareas preventivas (campo fecha_pausado)
-- ============================================================
--
-- Permite "pausar" una tarea preventiva indicando una fecha manual.
-- Mientras una tarea esté pausada (fecha_pausado IS NOT NULL):
--   - No aparece en planificación (preventivos por semana, próximas).
--   - No cuenta en cumplimiento.
--   - Sigue visible en el modal de tareas de la máquina con su badge
--     "PAUSADA · desde DD/MM/YYYY" y se puede reanudar (limpiando el campo).
--
-- La fecha de pausado es informativa: la introduce el usuario y queda como
-- referencia de "desde cuándo está pausada"; no implica ningún cálculo.
-- ============================================================

BEGIN;

ALTER TABLE mant_plan
    ADD COLUMN IF NOT EXISTS fecha_pausado DATE NULL;

COMMENT ON COLUMN mant_plan.fecha_pausado IS
    'Si NOT NULL la tarea está pausada desde esa fecha — no se planifica ni computa para cumplimiento. NULL = activa.';

CREATE INDEX IF NOT EXISTS idx_plan_fecha_pausado
    ON mant_plan(fecha_pausado) WHERE fecha_pausado IS NOT NULL;

INSERT INTO schema_migrations (version, description) VALUES
    ('008', 'mant_plan: fecha_pausado para pausar tareas preventivas')
ON CONFLICT (version) DO NOTHING;

COMMIT;
