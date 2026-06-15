-- ════════════════════════════════════════════════════════════════════════
-- Migración 016: Registro de OFs lanzadas desde las tablets de planta.
--
-- Cada vez que un operario pulsa "LANZA OF" en la tablet, se registra una
-- fila aquí. NO se toca Sage en esta primera versión — solo dejamos
-- trazabilidad en nuestra BD (quién lanzó qué OF, en qué máquina, a qué hora).
--
-- Más adelante, cuando integremos con Sage, esta tabla seguirá siendo útil
-- como log histórico independiente del sistema externo.
-- ════════════════════════════════════════════════════════════════════════
BEGIN;

CREATE TABLE IF NOT EXISTS ofs_lanzadas (
    id              BIGSERIAL    PRIMARY KEY,
    of_codigo       VARCHAR(50)  NOT NULL,       -- p.ej. "OF1234"
    referencia      VARCHAR(50),                 -- código de producto/referencia
    cod_maquina     VARCHAR(50)  NOT NULL,       -- estación/máquina (BT3.4…)
    desc_maquina    VARCHAR(120),
    cantidad        NUMERIC(12, 2),
    duracion_horas  NUMERIC(8, 2),
    ubicacion_galga VARCHAR(120),                -- nuevo campo previsto en Sage
    notas           TEXT,                        -- notas del planificador
    notas_operario  TEXT,                        -- notas que el operario añade
    operario        VARCHAR(50),                 -- código de operario (4 cifras)
    lanzada_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    pdf_path        TEXT,                        -- ruta del PDF archivado
    estado          VARCHAR(20)  NOT NULL DEFAULT 'lanzada'
                    CHECK (estado IN ('lanzada','cancelada','en_curso','cerrada')),
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

COMMENT ON TABLE  ofs_lanzadas               IS 'Log de OFs lanzadas desde las tablets de planta';
COMMENT ON COLUMN ofs_lanzadas.of_codigo     IS 'Identificador de la OF (Sage)';
COMMENT ON COLUMN ofs_lanzadas.cod_maquina   IS 'Estación / máquina en la que se lanza';
COMMENT ON COLUMN ofs_lanzadas.estado        IS 'lanzada (default) | cancelada | en_curso | cerrada';

CREATE INDEX IF NOT EXISTS idx_ofs_lanzadas_maquina_at
    ON ofs_lanzadas(cod_maquina, lanzada_at DESC);
CREATE INDEX IF NOT EXISTS idx_ofs_lanzadas_of_codigo
    ON ofs_lanzadas(of_codigo);

DROP TRIGGER IF EXISTS trg_ofs_lanzadas_updated ON ofs_lanzadas;
CREATE TRIGGER trg_ofs_lanzadas_updated
    BEFORE UPDATE ON ofs_lanzadas
    FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();

INSERT INTO schema_migrations (version, description) VALUES
    ('016', 'Registro de OFs lanzadas desde tablets (ofs_lanzadas)')
ON CONFLICT (version) DO NOTHING;

COMMIT;
