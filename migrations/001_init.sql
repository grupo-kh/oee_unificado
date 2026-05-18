-- ============================================================
--  PLAN_ATTAINMENT · Mantenimiento preventivo · Schema inicial
--  PostgreSQL 16
-- ============================================================
--
-- Crea las tablas que sustituyen a:
--   - cache/maintenance/data_*.json (PROXIMAS REV. del Excel)  → mant_plan
--   - data/maintenance_completed.json                          → mant_completions
--   - data/maintenance_periodicidad.json                       → mant_periodicidad_overrides
--   - data/maintenance_pendiente.json                          → mant_pendientes
--
-- Las tablas de OEE / Disponibilidad / Calidad NO se tocan: se nutren
-- de Mapex y Sage (ya conectadas vía PDO sqlsrv).
--
-- Usa: psql -U postgres -d plan_attainment -f migrations/001_init.sql
-- ============================================================

BEGIN;

-- ───── Tabla de control de migraciones ─────
CREATE TABLE IF NOT EXISTS schema_migrations (
    version     VARCHAR(20)  PRIMARY KEY,
    applied_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    description TEXT
);

-- ───── Catálogo de operarios ─────
CREATE TABLE IF NOT EXISTS mant_operarios (
    numero      VARCHAR(50)  PRIMARY KEY,
    nombre      TEXT,
    activo      BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
COMMENT ON TABLE  mant_operarios            IS 'Operarios identificados por número de empleado';
COMMENT ON COLUMN mant_operarios.numero     IS 'Número de empleado (clave primaria, usada en intervenciones)';
COMMENT ON COLUMN mant_operarios.nombre     IS 'Nombre legible (opcional, p. ej. "JOSUE")';

-- Inserción del catálogo según la tabla de la auditoría
INSERT INTO mant_operarios (numero, nombre) VALUES
    ('2418', 'JOSUE'),
    ('2417', 'CRISTOFER'),
    ('1004', 'CRISTOBAL'),
    ('1374', 'CHRISTIAN'),
    ('1886', 'RAMÓN'),
    ('1593', 'ALEX'),
    ('2338', 'JASSER'),
    ('2898', 'EMIR')
ON CONFLICT (numero) DO NOTHING;

-- ───── Plan de mantenimiento (sustituye PROXIMAS REV. del Excel) ─────
CREATE TABLE IF NOT EXISTS mant_plan (
    id                BIGSERIAL    PRIMARY KEY,
    orden             VARCHAR(50)  NOT NULL,
    tarea             VARCHAR(50)  NOT NULL,
    cod_maquina_mant  VARCHAR(120) NOT NULL,
    desc_maquina      TEXT         NOT NULL,
    grupo             VARCHAR(50),
    desc_grupo        TEXT,
    periodicidad      VARCHAR(20),
    desc_tarea        TEXT,
    activa            CHAR(1),
    ultima_revision   DATE,
    proxima_revision  DATE,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT mant_plan_orden_tarea_uniq UNIQUE (orden, tarea)
);
CREATE INDEX IF NOT EXISTS idx_plan_maquina  ON mant_plan(cod_maquina_mant);
CREATE INDEX IF NOT EXISTS idx_plan_periodi  ON mant_plan(periodicidad);
CREATE INDEX IF NOT EXISTS idx_plan_proxima  ON mant_plan(proxima_revision);
COMMENT ON TABLE mant_plan IS 'Plan vigente de mantenimiento preventivo (sustituye la hoja PROXIMAS REV. del Excel)';

-- ───── Intervenciones (realizadas, no realizadas, recuperaciones) ─────
CREATE TABLE IF NOT EXISTS mant_completions (
    id                       BIGSERIAL    PRIMARY KEY,
    external_id              TEXT         UNIQUE,                -- id legacy "orden|tarea|fpo[|catchup]"
    tipo                     VARCHAR(20)  NOT NULL DEFAULT 'completada',
    orden                    VARCHAR(50)  NOT NULL,
    tarea                    VARCHAR(50)  NOT NULL,
    cod_maquina_mant         VARCHAR(120),
    desc_maquina             TEXT,
    grupo                    VARCHAR(50),
    desc_grupo               TEXT,
    periodicidad             VARCHAR(20),
    desc_tarea               TEXT,
    activa                   CHAR(1),
    fecha_proxima_original   DATE,
    fecha_intervencion       DATE,
    operario                 VARCHAR(50),
    observaciones            TEXT,
    motivo_no_realizada      TEXT,
    recuperada               BOOLEAN      NOT NULL DEFAULT FALSE,
    recuperada_fecha         DATE,
    marcada_at               TIMESTAMPTZ  NOT NULL DEFAULT now(),
    marcada_por              VARCHAR(100),
    created_at               TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT mant_completions_tipo_chk
        CHECK (tipo IN ('completada','no_realizada','recuperacion')),
    CONSTRAINT mant_completions_coherence_chk CHECK (
        (tipo = 'completada'   AND fecha_proxima_original IS NOT NULL AND fecha_intervencion IS NOT NULL) OR
        (tipo = 'no_realizada' AND fecha_proxima_original IS NOT NULL AND fecha_intervencion IS NULL    ) OR
        (tipo = 'recuperacion' AND fecha_intervencion IS NOT NULL)
    )
);
CREATE INDEX IF NOT EXISTS idx_compl_orden_tarea ON mant_completions(orden, tarea);
CREATE INDEX IF NOT EXISTS idx_compl_fpo         ON mant_completions(fecha_proxima_original);
CREATE INDEX IF NOT EXISTS idx_compl_fi          ON mant_completions(fecha_intervencion);
CREATE INDEX IF NOT EXISTS idx_compl_tipo        ON mant_completions(tipo);
CREATE INDEX IF NOT EXISTS idx_compl_maquina     ON mant_completions(cod_maquina_mant);
CREATE INDEX IF NOT EXISTS idx_compl_periodi     ON mant_completions(periodicidad);
CREATE INDEX IF NOT EXISTS idx_compl_operario    ON mant_completions(operario);
-- Nota: no creamos índices funcionales con date_trunc — esa función es
-- STABLE, no IMMUTABLE, y PG la rechaza en expresiones de índice. Los
-- índices simples sobre fecha_proxima_original y fecha_intervencion ya
-- soportan los rangos típicos por mes (BETWEEN '2025-11-01' AND '2025-11-30').
COMMENT ON TABLE mant_completions IS 'Intervenciones de mantenimiento. tipo describe si es completada, no realizada (con motivo) o recuperación posterior';

-- ───── Overrides de periodicidad ─────
CREATE TABLE IF NOT EXISTS mant_periodicidad_overrides (
    id            BIGSERIAL    PRIMARY KEY,
    orden         VARCHAR(50)  NOT NULL,
    tarea         VARCHAR(50)  NOT NULL,
    periodicidad  VARCHAR(20)  NOT NULL,
    set_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    set_por       VARCHAR(100),
    nota          TEXT,
    CONSTRAINT mant_per_overrides_uniq UNIQUE (orden, tarea)
);
CREATE INDEX IF NOT EXISTS idx_per_ovr_periodi ON mant_periodicidad_overrides(periodicidad);
COMMENT ON TABLE mant_periodicidad_overrides IS 'Overrides de periodicidad por tarea (efectivos hasta que se borren)';

-- ───── Pendientes (banderas rojas manuales) ─────
CREATE TABLE IF NOT EXISTS mant_pendientes (
    id                       BIGSERIAL    PRIMARY KEY,
    orden                    VARCHAR(50)  NOT NULL,
    tarea                    VARCHAR(50)  NOT NULL,
    fecha_proxima_original   DATE         NOT NULL,
    cod_maquina_mant         VARCHAR(120),
    desc_maquina             TEXT,
    desc_grupo               TEXT,
    desc_tarea               TEXT,
    periodicidad             VARCHAR(20),
    set_at                   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    set_por                  VARCHAR(100),
    nota                     TEXT,
    CONSTRAINT mant_pendientes_uniq UNIQUE (orden, tarea, fecha_proxima_original)
);
CREATE INDEX IF NOT EXISTS idx_pend_maquina  ON mant_pendientes(cod_maquina_mant);
CREATE INDEX IF NOT EXISTS idx_pend_periodi  ON mant_pendientes(periodicidad);
COMMENT ON TABLE mant_pendientes IS 'Tareas marcadas manualmente como pendientes de revisión (bandera roja)';

-- ───── Vistas auxiliares para la app ─────

-- Última intervención (no recuperación) por tarea — sirve para auto-reprogramar
CREATE OR REPLACE VIEW v_mant_latest_by_task AS
SELECT DISTINCT ON (orden, tarea)
    orden,
    tarea,
    fecha_intervencion,
    operario,
    tipo,
    id
FROM mant_completions
WHERE fecha_intervencion IS NOT NULL
ORDER BY orden, tarea, fecha_intervencion DESC, id DESC;
COMMENT ON VIEW v_mant_latest_by_task IS 'Última intervención por tarea (cualquier tipo con fecha_intervencion no nula)';

-- Cumplimiento por mes (cycle) — sirve a mant_cumplimiento.php / mant_cumplimiento_meses.php
CREATE OR REPLACE VIEW v_mant_cumpl_mes AS
SELECT
    to_char(mes, 'YYYY-MM')                                    AS mes,
    SUM(denom)                                                  AS denom,
    SUM(numer)                                                  AS numer,
    SUM(completadas)                                            AS completadas,
    SUM(no_realizadas)                                          AS no_realizadas,
    SUM(recuperaciones)                                         AS recuperaciones,
    cod_maquina_mant,
    periodicidad
FROM (
    -- cycle records (completada / no_realizada): bucket por fpo
    SELECT
        date_trunc('month', fecha_proxima_original)::date AS mes,
        1                                                  AS denom,
        CASE WHEN fecha_intervencion IS NOT NULL THEN 1 ELSE 0 END AS numer,
        CASE WHEN tipo = 'completada'   THEN 1 ELSE 0 END  AS completadas,
        CASE WHEN tipo = 'no_realizada' THEN 1 ELSE 0 END  AS no_realizadas,
        0                                                  AS recuperaciones,
        cod_maquina_mant,
        periodicidad
    FROM mant_completions
    WHERE tipo IN ('completada','no_realizada')
      AND fecha_proxima_original IS NOT NULL
    UNION ALL
    -- recuperación records: bucket por fi (suben numer en el mes en que se recuperan)
    SELECT
        date_trunc('month', fecha_intervencion)::date AS mes,
        0                                              AS denom,
        1                                              AS numer,
        0                                              AS completadas,
        0                                              AS no_realizadas,
        1                                              AS recuperaciones,
        cod_maquina_mant,
        periodicidad
    FROM mant_completions
    WHERE tipo = 'recuperacion' AND fecha_intervencion IS NOT NULL
) t
GROUP BY mes, cod_maquina_mant, periodicidad;
COMMENT ON VIEW v_mant_cumpl_mes IS 'Cumplimiento agregado por mes; se filtra/agrega externamente por máquina y/o periodicidad';

-- ───── Trigger updated_at ─────
CREATE OR REPLACE FUNCTION trg_set_updated_at() RETURNS trigger AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_mant_plan_updated      ON mant_plan;
CREATE TRIGGER trg_mant_plan_updated      BEFORE UPDATE ON mant_plan      FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();
DROP TRIGGER IF EXISTS trg_mant_operarios_updated ON mant_operarios;
CREATE TRIGGER trg_mant_operarios_updated BEFORE UPDATE ON mant_operarios FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at();

-- ───── Marca esta migración como aplicada ─────
INSERT INTO schema_migrations (version, description) VALUES
    ('001', 'Init: mant_plan, mant_completions, mant_periodicidad_overrides, mant_pendientes, mant_operarios, vistas')
ON CONFLICT (version) DO NOTHING;

COMMIT;
