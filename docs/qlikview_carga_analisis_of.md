# QlikView — Script de carga · "Análisis Histórico de OF por Artículo"

Script de carga original del QlikView que replica la app (pantalla
`load/analisis historico de of por articulo.PNG`). Guardado como referencia de
las fuentes de datos. **No se usa en runtime**; la app lee MAPEX/Logicclass
directamente. Sirve para validar fórmulas y orígenes.

> Nota: el "rendimiento objetivo" (línea verde del gráfico) NO aparece en este
> script — es una línea de referencia visual del gráfico QV. Verificado en MAPEX
> que no hay objetivo configurado (ObjetivoYield/ObjetivoOEE a 0) → es 75% fijo.

## Conexión 1 — MAPEX (`mapexbp_test`, Data Source=MAPEX, User=sa)

Datos OEE por OF desde la función `F_his_ct` (breakdown mensual `WO,PRODUCT`):

```sql
SELECT
    Cod_OF, Desc_maquina, cod_maquina AS CentroTrabajo,
    cod_producto AS codigoArticulo, Desc_Producto,
    YEAR(Fecha_inicio)  AS Año,
    MONTH(Fecha_inicio) AS Mes,
    Fecha_inicio, Fecha_fin,
    M / 3600          AS HORAS_DEDICADAS,
    M,
    (M_Teo - M)       AS HORAS_PERDID,
    (M_Teo - M) / 3600 AS Tiempo_perdido_horas,
    Unidades_planning, Unidades_OK, Unidades_NOK,
    Disp_C, Rend_C, Cal_C, OEE_C,
    DATEPART(WEEK, Fecha_inicio) AS WeekNo
FROM
    F_his_ct('WORKCENTER','MOUNTH','WO,PRODUCT','01/01/2020 14:00:00','31/12/2026 14:00:00',16)
    LEFT JOIN cfg_maquina t1 ON F_his_ct.WorkGroup = t1.Cod_maquina
WHERE OEE_C > '0'
  AND (cod_producto IS NOT NULL OR cod_producto <> ' ')
  AND COD_OF <> '2019-PRUEBA-7-2019-1896'
ORDER BY Fecha_inicio DESC;
```

Equivalencias en la app (mapeo en `api/oee_unificado_art_analisis.php`):
- `HORAS_DEDICADAS = M / 3600`
- `HORAS_PERDID    = M_Teo - M`
- `Rend_C` (rendimiento), `Disp_C`, `Cal_C`, `OEE_C` ya calculados por MAPEX.

## Conexión 2 — Logicclass (`Logicclass`, Data Source=server2, User=khapps)

Productividad nominal (uds/hora) por centro de trabajo + artículo:

```sql
SELECT CentroTrabajo, codigoArticulo,
       CAST(UnidadesHora AS DECIMAL(10,0)) AS UnidadesHora
FROM Oper_Formula
WHERE CENTROTRABAJO <> ' '
  AND (Operacion = 'PRD CNF' OR Operacion = 'PRD SLD' OR Operacion = 'PRD TRQ')
  AND UnidadesHora <> '0'
  AND codigoempresa = 1;
```

Es la 3ª conexión integrada en la app como `logicclass` (claves `DB_LOGIC_*` en
`.env`). Aporta `pzas_hora_nominal` / `pct_nominal` en el análisis de OF cuando
está configurada. Pendiente: rellenar `DB_LOGIC_HOST`/`DB_LOGIC_PASS`.
