-- ============================================================
--  002 - Soporte para tareas creadas via la UI de
--        "Acciones preventivas por máquina"
-- ============================================================
--
-- Cambios:
--   · mant_plan.is_user_added (BOOLEAN) → distingue tareas añadidas
--     desde la web de las venidas del Excel original
--   · mant_plan.created_by (VARCHAR) → traza de quién la creó
--   · sequence mant_plan_user_orden_seq → asigna orden únicos a las
--     tareas nuevas (empieza en 100000, muy por encima del rango
--     existente del Excel: max actual 1286)
-- ============================================================

BEGIN;

ALTER TABLE mant_plan
    ADD COLUMN IF NOT EXISTS is_user_added BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE mant_plan
    ADD COLUMN IF NOT EXISTS created_by VARCHAR(100);

CREATE INDEX IF NOT EXISTS idx_plan_user_added ON mant_plan(is_user_added) WHERE is_user_added;

CREATE SEQUENCE IF NOT EXISTS mant_plan_user_orden_seq
    AS BIGINT
    INCREMENT BY 1
    MINVALUE 1
    START WITH 100000
    NO CYCLE;

GRANT USAGE, SELECT, UPDATE ON SEQUENCE mant_plan_user_orden_seq TO plan_attainment_app;

COMMENT ON COLUMN mant_plan.is_user_added
    IS 'TRUE si la tarea fue creada desde la UI; FALSE si vino del Excel inicial';
COMMENT ON COLUMN mant_plan.created_by
    IS 'Usuario que creó la tarea via la UI';

INSERT INTO schema_migrations (version, description) VALUES
    ('002', 'mant_plan: is_user_added + created_by + sequence mant_plan_user_orden_seq')
ON CONFLICT (version) DO NOTHING;

COMMIT;
