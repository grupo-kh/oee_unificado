-- ============================================================
--  006 - Campos nuevos en mant_plan: alta_baja, ip_interna,
--        tipo_realizacion (Interno/Externo) y
--        tipo_mantenimiento (Preventivo/Predictivo)
-- ============================================================
--
-- Se añaden cuatro columnas a mant_plan que vienen ya en el .xlsx
-- nuevo de Mantenimiento Preventivo (260507_Ordenes Mant Prev):
--   - alta_baja           'ALTA' | 'BAJA'  (default ALTA)
--   - ip_interna          string libre (codigo IP interno)
--   - tipo_realizacion    'Interno' | 'Externo'
--   - tipo_mantenimiento  'Preventivo' | 'Predictivo'
--
-- Migración aditiva: no rompe datos existentes (los 4 campos quedan
-- con valor por defecto / NULL para las filas previas).
-- ============================================================

BEGIN;

ALTER TABLE mant_plan
    ADD COLUMN IF NOT EXISTS alta_baja          VARCHAR(10)  NOT NULL DEFAULT 'ALTA',
    ADD COLUMN IF NOT EXISTS ip_interna         VARCHAR(50),
    ADD COLUMN IF NOT EXISTS tipo_realizacion   VARCHAR(20),
    ADD COLUMN IF NOT EXISTS tipo_mantenimiento VARCHAR(20);

-- Asegurar que alta_baja solo acepta ALTA/BAJA
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'mant_plan_alta_baja_chk'
    ) THEN
        ALTER TABLE mant_plan
            ADD CONSTRAINT mant_plan_alta_baja_chk
            CHECK (alta_baja IN ('ALTA','BAJA'));
    END IF;
END$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'mant_plan_tipo_realizacion_chk'
    ) THEN
        ALTER TABLE mant_plan
            ADD CONSTRAINT mant_plan_tipo_realizacion_chk
            CHECK (tipo_realizacion IS NULL OR tipo_realizacion IN ('Interno','Externo'));
    END IF;
END$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'mant_plan_tipo_mantenimiento_chk'
    ) THEN
        ALTER TABLE mant_plan
            ADD CONSTRAINT mant_plan_tipo_mantenimiento_chk
            CHECK (tipo_mantenimiento IS NULL OR tipo_mantenimiento IN ('Preventivo','Predictivo'));
    END IF;
END$$;

CREATE INDEX IF NOT EXISTS idx_plan_alta_baja ON mant_plan(alta_baja);
CREATE INDEX IF NOT EXISTS idx_plan_ip_interna
    ON mant_plan(ip_interna) WHERE ip_interna IS NOT NULL;

COMMENT ON COLUMN mant_plan.alta_baja          IS 'ALTA = se planifica; BAJA = excluida del plan vigente';
COMMENT ON COLUMN mant_plan.ip_interna         IS 'Identificador interno de la instruccion/procedimiento (col E del xlsx)';
COMMENT ON COLUMN mant_plan.tipo_realizacion   IS 'Quien ejecuta la tarea: Interno o Externo (col F del xlsx)';
COMMENT ON COLUMN mant_plan.tipo_mantenimiento IS 'Naturaleza de la tarea: Preventivo o Predictivo (col G del xlsx)';

INSERT INTO schema_migrations (version, description) VALUES
    ('006', 'mant_plan: alta_baja, ip_interna, tipo_realizacion, tipo_mantenimiento')
ON CONFLICT (version) DO NOTHING;

COMMIT;
