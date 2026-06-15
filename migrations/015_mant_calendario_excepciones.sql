-- ════════════════════════════════════════════════════════════════════════
-- Migración 015: Excepciones del calendario laboral de mantenimiento.
--
-- Permite al técnico marcar días concretos como:
--   - NO_LABORABLE:    día normalmente hábil que NO se trabaja
--                      (puente, festivo local de la empresa, parada técnica…)
--   - LABORABLE_EXTRA: día normalmente NO hábil (sábado, domingo, festivo
--                      CV) en el que SÍ se trabaja por carga laboral.
--
-- La combinación con CalendarioLaboral (regla L-V + festivos CV hardcoded)
-- queda así:
--    esHabil = (esLunesAViernes && !esFestivoCV && !excepcion[NO_LABORABLE])
--              || excepcion[LABORABLE_EXTRA]
--
-- Las excepciones MANDAN sobre la regla por defecto.
-- ════════════════════════════════════════════════════════════════════════
BEGIN;

CREATE TABLE IF NOT EXISTS mant_calendario_excepciones (
    fecha       DATE         PRIMARY KEY,
    tipo        VARCHAR(20)  NOT NULL,
    motivo      TEXT,
    created_by  TEXT,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT mant_calendario_tipo_chk
        CHECK (tipo IN ('NO_LABORABLE', 'LABORABLE_EXTRA'))
);

COMMENT ON TABLE  mant_calendario_excepciones        IS 'Excepciones del calendario laboral de mantenimiento (festivos extra y laborables extra)';
COMMENT ON COLUMN mant_calendario_excepciones.fecha  IS 'Fecha a la que aplica la excepción';
COMMENT ON COLUMN mant_calendario_excepciones.tipo   IS 'NO_LABORABLE (festivo/puente extra) o LABORABLE_EXTRA (sáb/dom/festivo CV trabajado)';
COMMENT ON COLUMN mant_calendario_excepciones.motivo IS 'Texto libre con la razón (visible en la UI)';

CREATE INDEX IF NOT EXISTS idx_mant_calendario_tipo
    ON mant_calendario_excepciones(tipo);

-- Trigger updated_at (reusa la función ya creada en mig 001)
DROP TRIGGER IF EXISTS trg_mant_calendario_updated ON mant_calendario_excepciones;
CREATE TRIGGER trg_mant_calendario_updated
    BEFORE UPDATE ON mant_calendario_excepciones
    FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();

INSERT INTO schema_migrations (version, description) VALUES
    ('015', 'Excepciones del calendario laboral (mant_calendario_excepciones)')
ON CONFLICT (version) DO NOTHING;

COMMIT;
