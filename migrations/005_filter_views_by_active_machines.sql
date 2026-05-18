-- ============================================================
--  005 - Restringir las vistas a máquinas activas
-- ============================================================
--
-- mant_maquinas ya solo contiene máquinas activas (col H del Excel = 'A',
-- mig. 004). Pero las vistas auxiliares leen directamente de
-- mant_completions, que sí guarda el histórico de TODAS las máquinas
-- (incluidas las dadas de baja).
--
-- Para que las máquinas inactivas desaparezcan también del histórico, las
-- próximas y los cuadros de cumplimiento, recreamos las dos vistas con un
-- filtro EXISTS contra mant_maquinas. Si más adelante volvemos a activar
-- una máquina (la insertamos en el catálogo), las intervenciones de su
-- mant_completions vuelven a aparecer automáticamente sin pérdida de datos.
-- ============================================================

BEGIN;

DROP VIEW IF EXISTS v_mant_latest_by_task;
CREATE VIEW v_mant_latest_by_task AS
SELECT DISTINCT ON (c.orden, c.tarea)
    c.orden,
    c.tarea,
    c.fecha_intervencion,
    c.operario,
    c.tipo,
    c.id
  FROM mant_completions c
 WHERE c.fecha_intervencion IS NOT NULL
   AND EXISTS (
       SELECT 1 FROM mant_maquinas mm
        WHERE mm.cod_maquina_mant = c.cod_maquina_mant
   )
 ORDER BY c.orden, c.tarea, c.fecha_intervencion DESC, c.id DESC;
COMMENT ON VIEW v_mant_latest_by_task IS
    'Última intervención por tarea, restringida a máquinas presentes en mant_maquinas.';

DROP VIEW IF EXISTS v_mant_cumpl_mes;
CREATE VIEW v_mant_cumpl_mes AS
SELECT
    to_char(mes, 'YYYY-MM')                                     AS mes,
    SUM(denom)                                                   AS denom,
    SUM(numer)                                                   AS numer,
    SUM(completadas)                                             AS completadas,
    SUM(no_realizadas)                                           AS no_realizadas,
    SUM(recuperaciones)                                          AS recuperaciones,
    cod_maquina_mant,
    periodicidad
FROM (
    -- Cycle records (completada / no_realizada): bucket por fpo
    SELECT
        date_trunc('month', c.fecha_proxima_original)::date AS mes,
        1                                                    AS denom,
        CASE WHEN c.fecha_intervencion IS NOT NULL THEN 1 ELSE 0 END AS numer,
        CASE WHEN c.tipo = 'completada'   THEN 1 ELSE 0 END  AS completadas,
        CASE WHEN c.tipo = 'no_realizada' THEN 1 ELSE 0 END  AS no_realizadas,
        0                                                    AS recuperaciones,
        c.cod_maquina_mant,
        c.periodicidad
      FROM mant_completions c
     WHERE c.tipo IN ('completada','no_realizada')
       AND c.fecha_proxima_original IS NOT NULL
       AND EXISTS (
           SELECT 1 FROM mant_maquinas mm
            WHERE mm.cod_maquina_mant = c.cod_maquina_mant
       )
    UNION ALL
    -- Recuperación: bucket por fi
    SELECT
        date_trunc('month', c.fecha_intervencion)::date AS mes,
        0                                                AS denom,
        1                                                AS numer,
        0                                                AS completadas,
        0                                                AS no_realizadas,
        1                                                AS recuperaciones,
        c.cod_maquina_mant,
        c.periodicidad
      FROM mant_completions c
     WHERE c.tipo = 'recuperacion'
       AND c.fecha_intervencion IS NOT NULL
       AND EXISTS (
           SELECT 1 FROM mant_maquinas mm
            WHERE mm.cod_maquina_mant = c.cod_maquina_mant
       )
) t
GROUP BY mes, cod_maquina_mant, periodicidad;
COMMENT ON VIEW v_mant_cumpl_mes IS
    'Cumplimiento agregado por mes, restringido a máquinas presentes en mant_maquinas.';

INSERT INTO schema_migrations (version, description) VALUES
    ('005', 'Restrict v_mant_latest_by_task and v_mant_cumpl_mes to active machines (mant_maquinas)')
ON CONFLICT (version) DO NOTHING;

COMMIT;
