-- ============================================================
--  003 - Catalogo de máquinas (mant_maquinas)
-- ============================================================
--
-- Hasta ahora la lista de máquinas se derivaba de mant_plan: una
-- máquina sin tareas no aparecía en ningún sitio. Con esta tabla
-- pasa a ser un catálogo independiente que puede tener cero tareas
-- (para permitir crear máquinas nuevas via UI antes de añadirles
-- ninguna acción preventiva).
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS mant_maquinas (
    cod_maquina_mant VARCHAR(120) PRIMARY KEY,
    desc_maquina     TEXT          NOT NULL,
    is_user_added    BOOLEAN       NOT NULL DEFAULT FALSE,
    created_by       VARCHAR(100),
    notas            TEXT,
    created_at       TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ   NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_maquinas_user_added ON mant_maquinas(is_user_added) WHERE is_user_added;

DROP TRIGGER IF EXISTS trg_mant_maquinas_updated ON mant_maquinas;
CREATE TRIGGER trg_mant_maquinas_updated BEFORE UPDATE ON mant_maquinas
    FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();

-- Sembrar el catálogo con todas las máquinas que ya tienen tareas en
-- mant_plan. ON CONFLICT permite re-ejecutar la migración sin duplicar.
INSERT INTO mant_maquinas (cod_maquina_mant, desc_maquina)
SELECT cod_maquina_mant, MAX(desc_maquina)
  FROM mant_plan
 WHERE cod_maquina_mant <> ''
 GROUP BY cod_maquina_mant
ON CONFLICT (cod_maquina_mant) DO NOTHING;

COMMENT ON TABLE  mant_maquinas IS 'Catálogo de máquinas. Permite máquinas sin tareas (creadas via UI).';
COMMENT ON COLUMN mant_maquinas.is_user_added IS 'TRUE = creada desde la UI; FALSE = sembrada inicialmente desde Excel';

INSERT INTO schema_migrations (version, description) VALUES
    ('003', 'mant_maquinas catalog table seeded from mant_plan')
ON CONFLICT (version) DO NOTHING;

COMMIT;
