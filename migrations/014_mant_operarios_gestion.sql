-- ════════════════════════════════════════════════════════════════════════
-- Migración 014: Gestión de Operarios (datos personales, puesto, capacitación)
--
-- Extiende mant_operarios con datos administrativos (apellidos, fechas,
-- puesto) y añade una tabla pivote para las capacitaciones marcadas.
-- Las capacitaciones se almacenan literalmente — la regla "acumulativo"
-- (marcar 75 implica 25 y 50) la fuerza el backend al guardar.
--
-- Vista para el rol "técnico": permite gestionar quién está habilitado para
-- realizar las tareas preventivas según su nivel de capacitación.
-- ════════════════════════════════════════════════════════════════════════
BEGIN;

-- ───── 1) Columnas nuevas en mant_operarios ─────────────────────────────
ALTER TABLE mant_operarios
    ADD COLUMN IF NOT EXISTS apellidos  VARCHAR(120),
    ADD COLUMN IF NOT EXISTS fecha_alta DATE,
    ADD COLUMN IF NOT EXISTS fecha_baja DATE,
    ADD COLUMN IF NOT EXISTS puesto     VARCHAR(40);

COMMENT ON COLUMN mant_operarios.apellidos  IS 'Apellidos del operario (opcional, complementa nombre)';
COMMENT ON COLUMN mant_operarios.fecha_alta IS 'Fecha de alta del operario en la organización';
COMMENT ON COLUMN mant_operarios.fecha_baja IS 'Fecha de baja (NULL = sigue activo). El backend deriva activo = (fecha_baja IS NULL).';
COMMENT ON COLUMN mant_operarios.puesto     IS 'Rol del operario: responsable | tecnico_mantenimiento | tecnico_taller | operario_mantenimiento | operario_taller';

-- Constraint del puesto (CHECK con los 5 valores admitidos). NULL se
-- permite porque los operarios actuales no tienen puesto definido.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'mant_operarios_puesto_chk'
    ) THEN
        ALTER TABLE mant_operarios
        ADD CONSTRAINT mant_operarios_puesto_chk
        CHECK (puesto IS NULL OR puesto IN (
            'responsable',
            'tecnico_mantenimiento',
            'tecnico_taller',
            'operario_mantenimiento',
            'operario_taller'
        ));
    END IF;
END$$;

-- ───── 2) Tabla pivote de capacitaciones ────────────────────────────────
-- Una fila por (operario, capacitación marcada). El frontend pinta los
-- checkboxes y el backend persiste las marcas literales (P25, P50, P75,
-- P100, TALLER) — el acumulado se fuerza al guardar.
CREATE TABLE IF NOT EXISTS mant_operario_capacitacion (
    numero        VARCHAR(50)  NOT NULL REFERENCES mant_operarios(numero) ON DELETE CASCADE,
    capacitacion  VARCHAR(20)  NOT NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (numero, capacitacion),
    CONSTRAINT mant_operario_capacitacion_val_chk
        CHECK (capacitacion IN ('P25', 'P50', 'P75', 'P100', 'TALLER'))
);

COMMENT ON TABLE  mant_operario_capacitacion              IS 'Capacitaciones marcadas por operario (acumulativas: P25/P50/P75/P100 + Taller independiente)';
COMMENT ON COLUMN mant_operario_capacitacion.numero       IS 'FK a mant_operarios.numero';
COMMENT ON COLUMN mant_operario_capacitacion.capacitacion IS 'Etiqueta de la capacitación: P25 P50 P75 P100 (acumulativas) o TALLER (independiente)';

CREATE INDEX IF NOT EXISTS idx_mant_operario_capacitacion_cap
    ON mant_operario_capacitacion(capacitacion);

-- ───── 3) Marca esta migración como aplicada ────────────────────────────
INSERT INTO schema_migrations (version, description) VALUES
    ('014', 'Gestión de operarios: extensión mant_operarios + tabla mant_operario_capacitacion')
ON CONFLICT (version) DO NOTHING;

COMMIT;
