-- ============================================================
--  010 - hora_inicio + motivos fijos en mant_completions
-- ============================================================
--
-- Dos cambios sobre mant_completions:
--
-- 1) hora_inicio (TIME): hora a la que el operario empezó la
--    intervención (la registra al marcar como hecha). Independiente
--    de fecha_intervencion para poder mostrarla en histórico y
--    análisis de carga horaria.
--
-- 2) motivo_no_realizada con un check de valores fijos cuando tipo
--    = 'no_realizada'. Antes era texto libre; ahora el flujo de
--    "marcar como NO realizada" usa un dropdown con 3 valores:
--      - 'disponibilidad_maquina'  (la máquina no estaba disponible)
--      - 'disponibilidad_operario' (no había operario)
--      - 'falta_material'          (no había material/recambios)
--    Para no romper datos existentes, el check permite también
--    cualquier valor previo (texto libre legacy queda como está)
--    pero los nuevos valores se restringen a uno de los 3 enum
--    o NULL cuando tipo='completada'.
-- ============================================================

BEGIN;

ALTER TABLE mant_completions
    ADD COLUMN IF NOT EXISTS hora_inicio TIME NULL;

COMMENT ON COLUMN mant_completions.hora_inicio IS
    'Hora a la que el operario inició la intervención (formato HH:MM). NULL si no se registró.';

INSERT INTO schema_migrations (version, description) VALUES
    ('010', 'mant_completions: hora_inicio + valores fijos de motivo_no_realizada')
ON CONFLICT (version) DO NOTHING;

COMMIT;
