-- 017_cfg_paro_categoria.sql
-- Mapeo paro -> Tipo Paro 1 (categoría agrupadora del árbol de paros), usado por
-- Matriz 2 para compactar la matriz Actividad × Categoría de paro por referencia.
-- El contenido se importa de ArbolParosMapex.xlsx con tools/importar_categoria_paros.php.

BEGIN;

CREATE TABLE IF NOT EXISTS cfg_paro_categoria (
    cod_paro     varchar(80) PRIMARY KEY,   -- Desc_paro (nombre del paro en MAPEX)
    tipo_paro_1  varchar(80) NOT NULL,      -- categoría agrupadora (Excel árbol de paros)
    actualizado  timestamptz DEFAULT now()
);

COMMENT ON TABLE cfg_paro_categoria IS
    'Mapeo paro -> Tipo Paro 1 importado de ArbolParosMapex.xlsx (hoja Consulta Paros Ricardo). Agrupa los paros de MAPEX en categorías legibles para Matriz 2.';

INSERT INTO schema_migrations(version) VALUES ('017') ON CONFLICT DO NOTHING;

COMMIT;
