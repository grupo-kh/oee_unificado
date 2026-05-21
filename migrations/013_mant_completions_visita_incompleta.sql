-- ============================================================
--  013 - mant_completions: visita_incompleta
-- ============================================================
--
-- Para visitas consolidadas (racks, plataformas) en las que el operario
-- realiza solo un subset de las sub-tareas y deja otras pendientes para
-- la próxima visita. Las marcas creadas por esa visita parcial se
-- etiquetan con visita_incompleta=TRUE para que el histórico muestre
-- una pildora "INCOMPLETA" en cada una.
--
-- En visitas no consolidadas o en consolidadas en las que se marcan
-- TODAS las sub-tareas, el campo queda en FALSE (default).
-- ============================================================

BEGIN;

ALTER TABLE mant_completions
    ADD COLUMN IF NOT EXISTS visita_incompleta BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN mant_completions.visita_incompleta IS
    'TRUE si la visita consolidada solo ejecuto un subset de las sub-tareas. Etiqueta visual en el historico.';

INSERT INTO schema_migrations (version, description) VALUES
    ('013', 'mant_completions: visita_incompleta')
ON CONFLICT (version) DO NOTHING;

COMMIT;
