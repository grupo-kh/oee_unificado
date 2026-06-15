# Mapa de vistas y APIs

La home (`index.php`) agrupa todo en **3 áreas**. Verificado visualmente en Chrome (08/06/2026).

## 1. Cumplimiento Global Plan de Producción
- Vista: `views/plan_attainment.php` · JS: `assets/js/view_plan_attainment_full.js`
- Paneles (en orden): Cumplimiento Global + gauges **Disponibilidad / Rendimiento / Calidad / OEE**,
  **Cumplimiento por Sección** (barras, clic filtra), **Evolución** (serie, clic fija fecha),
  **Ranking por Máquina** (barras, clic = detalle), **Detalle Plan vs Producido** (tabla pivote por
  máquina/artículo/hora, filas PLAN/PROD, semáforo verde/ámbar/rojo).
- Fórmula del global: `Σ min(producido, planificado) / Σ planificado` por artículo×turno (criterio estricto:
  la sobreproducción de una referencia NO compensa el déficit de otra).
- APIs: `api/plan_attainment.php`, `api/por_seccion.php`, `api/por_maquina.php`, `api/evolucion.php`, `api/grid.php`.
- ⚠️ Observación: con el rango por defecto (ayer) los **gauges D/R/C/OEE salieron vacíos ("—")** mientras
  el Detalle Plan/Prod sí cargaba. No confirmado si es falta de dato en ese rango o un fallo del panel gauge.

## 2. OEE Unificado  (badge "nuevo")
- Vista: `views/oee_unificado.php` · JS: `assets/js/view_oee_unificado.js`
- **Funciona completo.** OEE por sección en barras (ej. VARILLAS 77.2 %, TROQUELADOS 82.9 %, objetivo 75 %),
  Evolución OEE diaria con toggles OEE/Disponibilidad/Rendimiento/Calidad. Export **Excel** y **PDF**.
- Filtros: DESDE/HASTA, rango rápido Hoy/Ayer/Semana/Mes, turnos Mañana/Tarde/Noche, excluir máquinas.
- Family de APIs `api/oee_unificado*.php` (drill por sección, máquina, motivo; export PDF; histórico de referencia).
- Existe además una familia OEE de fábrica: `views/oee_fab*.php` + `api/oee_fab*.php`.

## 3. Mantenimiento Preventivo  (requiere login — ver auth-mantenimiento.md)
- Entrada: `views/mantenimiento.php` → redirige a `views/mant_login.php` si no hay sesión.
- Vistas: `mant_proximas`, `mant_cumplimiento`, `mant_acciones`, `mant_historico`, `mant_semana`,
  `mant_op` (operario), `mant_mobile` (móvil) y prototipos `mant_prev_*`.
- APIs de lectura: `mant_proximas`, `mant_cumplimiento(+meses/detalle)`, `mant_acciones`, `mant_historico`, `mant_tareas`, `mant_dashboard`, `mant_semana`.
- APIs de escritura (rol): `mant_marcar_hecha`, `mant_marcar_qr`, `mant_desmarcar`, `mant_set_pendiente`, `mant_set_periodicidad`, `mant_historico_update`.
- Módulo QR en curso (sin commit): `api/mant_qr_info.php`, `api/mant_marcar_qr.php`, `lib/QrToken.php`, `views/mant_qr_print.php` + app móvil operario (`appmovil.php`, `api/appmovil.php`, `api/mant_login_movil.php`).

## Otras vistas sueltas (familias por KPI)
`calidad*`, `disponibilidad*`, `rendimiento*`, `evolucion`, `por_seccion`, `por_maquina`, `grid`, `manual(_tecnico)` — cada una con su `api/` homónima.
