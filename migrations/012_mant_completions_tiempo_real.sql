-- ============================================================
--  012 - Campo nuevo en mant_completions: tiempo_real_segundos
-- ============================================================
--
--   - tiempo_real_segundos  INT (segundos) - tiempo real registrado
--                           para esa intervencion concreta. NULL si
--                           no se conoce.
--
-- Se almacena en segundos (no en minutos) para soportar la variación
-- aleatoria ±10% sobre tiempo_estimado que se aplica al marcar una
-- tarea como hecha. Eso evita que todas las intervenciones de la misma
-- tarea muestren exactamente el mismo número.
--
-- Editable desde el popup del histórico (solo técnico). El operario lo
-- ve pero no lo modifica.
-- ============================================================

BEGIN;

ALTER TABLE mant_completions
    ADD COLUMN IF NOT EXISTS tiempo_real_segundos INT;

-- Rango sensato: 0..36000 segundos (10 horas máximo).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'mant_completions_tiempo_real_chk'
    ) THEN
        ALTER TABLE mant_completions
            ADD CONSTRAINT mant_completions_tiempo_real_chk
            CHECK (tiempo_real_segundos IS NULL OR (tiempo_real_segundos >= 0 AND tiempo_real_segundos <= 36000));
    END IF;
END$$;

COMMENT ON COLUMN mant_completions.tiempo_real_segundos IS
    'Tiempo real de la intervencion en segundos. Generado automaticamente al marcar como hecha (tiempo_estimado*60 +- 10%) y editable desde el popup del historico.';

INSERT INTO schema_migrations (version, description) VALUES
    ('012', 'mant_completions: tiempo_real_segundos')
ON CONFLICT (version) DO NOTHING;

COMMIT;
