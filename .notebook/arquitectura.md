# Arquitectura

## Stack
PHP 8.x **sin framework**. Servido por Apache/XAMPP. Frontend en **vanilla JS** + ApexCharts (CDN
jsdelivr) + Google Fonts. Sin build step. Persistencia propia en **PostgreSQL**.

## Estructura (capas)
- `index.php` — home con los 3 accesos grandes (`index.php`).
- `views/*.php` — una página por vista; incluyen `includes/header.php` (logo + filtros) y cargan su JS.
- `api/*.php` — endpoints JSON. Devuelven SIEMPRE `{ ok: bool, data: …, error: … }`.
- `lib/*.php` — lógica de negocio y acceso a datos (ver abajo).
- `assets/js/` — `common.js` (helpers compartidos) + un `view_*.js` por vista.
- `assets/css/style.css` (escritorio) + `mobile.css`.
- `config/`, `includes/`, `cache/`, `data/`, `migrations/`, `tools/` — internos (bloqueados por `.htaccess`).

## Contrato de datos front↔back
Todo pasa por `apiFetch(endpoint, params)` en `assets/js/common.js:37`:
- Construye `GET {API_BASE}/{endpoint}?…params`, ignora params vacíos.
- `401` → redirige a `mant_login.php?next=<vista>` (sólo afecta a Mantenimiento).
- Espera `resp.json().ok === true` y devuelve `json.data`; si no, lanza error con `json.error`.
Filtros (fecha/turno/sección) se **persisten en localStorage** entre vistas — ver `loadFiltros`/`saveFiltros` en `common.js`.

## Fuentes de datos (3 BD + Excel)
Configuradas en `.env` (cargado por `lib/EnvLoader.php`):
- **MAPEX** — SQL Server `10.0.0.45 / mapexbp_Test`. Producción real, máquinas, OEE (D·R·C).
- **SAGE** — SQL Server `SERVER2 / Logicclass`. ERP (artículos, secciones).
- **PostgreSQL** — `127.0.0.1:5432 / plan_attainment`. Datos propios de la app (mantenimiento). `lib/Db.php` SOLO expone PG (`pg`, `pgFetchAll`, `pgFetchOne`, `pgExec`).
- **Excel** — `MANT_XLSX_PATH` (`Z:\Mantenimiento\…xlsx`). Origen del plan preventivo; con `MANT_USE_PG=true` migra a PG.

## Quién cruza qué
- `lib/PlanAttainmentAgg.php` — **plan (Excel) + producción real (MAPEX)** → el indicador Plan Attainment.
- `lib/PanelMetaBuilder.php` — bloque `meta` de los APIs que cruzan MAPEX + Excel.
- `lib/PlanExcelReader.php` / `MaintenanceExcelReader.php` — parsers de Excel con caché en disco.
- `lib/Maintenance*Store.php` — almacenes en PG: Completion (intervenciones), Pendiente (bandera revisar),
  Periodicidad (overrides `orden|tarea`), Plan (plan vigente que sustituye la hoja PROXIMAS REV).
- `lib/CalendarioLaboral.php` — festivos Comunidad Valenciana.
- `lib/QrToken.php` — tokens QR (módulo nuevo de marcado por QR para operario, ver git status).
