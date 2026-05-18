# Migración del módulo de mantenimiento a PostgreSQL 16

Hasta ahora el módulo de mantenimiento preventivo guardaba sus datos en
3 ficheros JSON (`data/maintenance_*.json`) y leía el plan vigente del
Excel. Esta migración mueve todo eso a una base de datos PostgreSQL
local. Los datos de OEE / disponibilidad / calidad **no se tocan** —
siguen viniendo de Mapex y Sage por SQL Server.

---

## 1) Instalar PostgreSQL 16

1. Descarga el instalador de Windows desde
   <https://www.postgresql.org/download/windows/> (EnterpriseDB).
2. Ejecuta el instalador. Acepta los defaults excepto:
   - **Installation directory**: `C:\Program Files\PostgreSQL\16` (default).
   - **Data directory**: `C:\Program Files\PostgreSQL\16\data` (default).
   - **Password** del superusuario `postgres`: anótala, te hará falta.
   - **Port**: `5432` (default).
   - **Locale**: `Spanish, Spain`.
   - Marca "pgAdmin 4" (gestor gráfico, opcional pero útil).
3. Al finalizar el wizard de **Stack Builder** lo puedes cerrar; no hace
   falta nada extra.

Verifica que el servicio está corriendo:

```powershell
Get-Service postgresql-x64-16
# Status debe ser "Running"
```

Y que conectas:

```powershell
& "C:\Program Files\PostgreSQL\16\bin\psql.exe" -U postgres -h 127.0.0.1
# Pide la contraseña que pusiste en la instalación
# Te entra en el prompt: postgres=#  → escribe \q para salir
```

## 2) Habilitar `pdo_pgsql` en XAMPP

1. Abre `C:\xampp\php\php.ini`.
2. Busca y descomenta (quita el `;` inicial):
   ```
   extension=pdo_pgsql
   extension=pgsql
   ```
3. Reinicia Apache desde el **XAMPP Control Panel**.

Comprueba que están cargadas:

```powershell
& "C:\xampp\php\php.exe" -m | findstr -i pgsql
# Debe mostrar:  pdo_pgsql   pgsql
```

## 3) Crear base de datos y usuario de la aplicación

Conéctate como superusuario `postgres` y ejecuta:

```powershell
& "C:\Program Files\PostgreSQL\16\bin\psql.exe" -U postgres -h 127.0.0.1
```

Dentro de psql:

```sql
-- Base de datos para el módulo de mantenimiento
CREATE DATABASE plan_attainment
    WITH OWNER = postgres
    ENCODING = 'UTF8'
    LC_COLLATE = 'Spanish_Spain.1252'
    LC_CTYPE   = 'Spanish_Spain.1252'
    TEMPLATE   = template0;

-- Usuario de aplicación (sin permisos de superusuario)
CREATE ROLE plan_attainment_app WITH LOGIN PASSWORD 'PON_AQUI_UNA_PASS_FUERTE';

-- Permisos sobre la base
GRANT CONNECT ON DATABASE plan_attainment TO plan_attainment_app;
\c plan_attainment
GRANT USAGE, CREATE ON SCHEMA public TO plan_attainment_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO plan_attainment_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO plan_attainment_app;
\q
```

> **Nota**: si quieres collation puro UTF-8 en vez de la `1252` española,
> usa `LC_COLLATE = 'es-ES-x-icu'` (requiere ICU activo, default en PG 16
> de Windows).

## 4) Configurar credenciales en la app

Edita `config/database.php` y ajusta los valores PG (al final del archivo):

```php
define('DB_PG_HOST',   '127.0.0.1');
define('DB_PG_PORT',   '5432');
define('DB_PG_NAME',   'plan_attainment');
define('DB_PG_USER',   'plan_attainment_app');
define('DB_PG_PASS',   'la_que_pusiste_arriba');
define('DB_PG_SCHEMA', 'public');

if (!defined('MANT_USE_PG')) define('MANT_USE_PG', true);
```

Mantén `MANT_USE_PG = true` para usar Postgres. Si lo pones a `false`
la app vuelve al almacenamiento JSON (modo legacy, útil si quieres
volver atrás temporalmente).

## 5) Aplicar el schema

Crea las tablas, índices, vistas y triggers:

```powershell
cd C:\xampp\htdocs\PLAN_ATTAINMENT
& "C:\xampp\php\php.exe" tools\install_postgres.php
```

Salida esperada:

```
Instalador PostgreSQL · plan_attainment
Conectando a 127.0.0.1:5432/plan_attainment (usuario plan_attainment_app)…
  → aplicando 001_init.sql…
    ✓ aplicada

Hecho. Migraciones aplicadas en esta ejecución: 1
Tablas mant_* y filas:
  mant_completions                    0 filas
  mant_operarios                      8 filas
  mant_pendientes                     0 filas
  mant_periodicidad_overrides         0 filas
  mant_plan                           0 filas
```

## 6) Migrar los datos existentes

### 6.1) Cargar el plan vigente desde Excel a `mant_plan`

Una sola vez, para "sembrar" el plan inicial:

```powershell
& "C:\xampp\php\php.exe" tools\seed_plan_from_excel.php --truncate
```

Salida esperada:

```
Seed mant_plan desde Excel [TRUNCATE PRIMERO]
Origen: Z:\Mantenimiento\Copia de 260402_Ordenes Mant Prev.xlsx
  → vaciando mant_plan…
  · tareas en PROXIMAS REV.: 3074
    · 500…  1000…  1500…  2000…  2500…  3000…
Hecho. mant_plan tiene 3074 filas (importadas 3074 en esta ejecución).
```

### 6.2) Migrar los 3 JSON a sus tablas correspondientes

```powershell
& "C:\xampp\php\php.exe" tools\migrate_json_to_pg.php
```

Si ya habías ejecutado `tools/generate_audit_data.php` antes de esta
migración, este script importará los ~12 165 registros sintéticos de
auditoría a `mant_completions`. Si los JSON están vacíos no pasa nada.

Salida esperada:

```
Migrador JSON → PostgreSQL
  · maintenance_completed.json: 12165 items
    · 1000…  2000…  …  12000…
    ✓ 12165 items en mant_completions
  · maintenance_periodicidad.json: 0 items
  · maintenance_pendiente.json: 0 items

Estado actual en PostgreSQL:
  mant_completions                    12165 filas
  mant_periodicidad_overrides             0 filas
  mant_pendientes                         0 filas
  mant_plan                            3074 filas
  mant_operarios                          8 filas
```

### 6.3) (Opcional) regenerar datos de auditoría desde cero, en PG

Para tirar lo que haya en `mant_completions` y reinyectar los 12 k
registros simulados (operarios numerados, 98 % cumplimiento en target
months, etc.):

```powershell
& "C:\xampp\php\php.exe" tools\generate_audit_data.php --seed=808
```

El script detecta automáticamente si `MANT_USE_PG` está activo y
escribe directamente en la tabla. Para forzar JSON: `--target=json`.

## 7) Verificación

Abre la web normal:

- **Mantenimiento → Cumplimiento Preventivo** debe mostrar el gauge,
  el desglose por periodicidad y la nueva gráfica "Cumplimiento por
  mes" con los porcentajes 98 / 102 en nov 25 / dic 25 / feb 26 / mar 26.
- **Mantenimiento → Histórico por Máquina** debe mostrar las
  intervenciones con badges `REALIZADA` / `NO REALIZADA` / `RECUPERACIÓN`.

Y desde la consola, una consulta de control:

```sql
SELECT mes, denom, numer, completadas, no_realizadas, recuperaciones,
       ROUND(numer * 100.0 / NULLIF(denom,0), 2) AS pct
  FROM (
        SELECT to_char(date_trunc('month', fecha_proxima_original), 'YYYY-MM') AS mes,
               COUNT(*) FILTER (WHERE tipo IN ('completada','no_realizada')) AS denom,
               COUNT(*) FILTER (WHERE tipo = 'completada') AS completadas,
               COUNT(*) FILTER (WHERE tipo = 'no_realizada') AS no_realizadas,
               0 AS recuperaciones,
               COUNT(*) FILTER (WHERE tipo = 'completada' AND fecha_intervencion IS NOT NULL) AS numer
          FROM mant_completions
         WHERE fecha_proxima_original IS NOT NULL
         GROUP BY 1
        UNION ALL
        SELECT to_char(date_trunc('month', fecha_intervencion), 'YYYY-MM'),
               0, 0, 0, COUNT(*), COUNT(*)
          FROM mant_completions
         WHERE tipo = 'recuperacion' AND fecha_intervencion IS NOT NULL
         GROUP BY 1
       ) t
 GROUP BY mes, denom, numer, completadas, no_realizadas, recuperaciones
 ORDER BY mes;
```

(O simplemente: `SELECT * FROM v_mant_cumpl_mes ORDER BY mes;`).

## 8) Rollback

Si algo falla y hay que volver a JSON inmediatamente:

1. Edita `config/database.php`: `MANT_USE_PG = false`.
2. Reinicia Apache.

Los `data/maintenance_*.json` siguen ahí (el migrador no los borra).
La app vuelve a leer/escribir en JSON sin tocar Postgres.

## 9) Estructura de las tablas

```
schema_migrations              control de versiones del schema
mant_operarios                 catálogo de empleados (numero → nombre)
mant_plan                      plan vigente (sustituye PROXIMAS REV. del Excel)
mant_completions               intervenciones (completada, no_realizada, recuperacion)
mant_periodicidad_overrides    cambios de periodicidad por tarea
mant_pendientes                banderas rojas manuales

v_mant_latest_by_task          última intervención por tarea (auto-reprogramación)
v_mant_cumpl_mes               cumplimiento agregado por mes (filtrable por máquina/periodicidad)
```

## 10) Backup recomendado

Programa un backup diario del módulo (no del Excel, ya no es la fuente
de verdad):

```powershell
# Por ejemplo, en el Programador de tareas de Windows, una vez al día:
& "C:\Program Files\PostgreSQL\16\bin\pg_dump.exe" `
    -U postgres -h 127.0.0.1 `
    -F c -f "C:\backups\plan_attainment_$(Get-Date -Format 'yyyyMMdd').dump" `
    plan_attainment
```

Para restaurar:

```powershell
& "C:\Program Files\PostgreSQL\16\bin\pg_restore.exe" `
    -U postgres -h 127.0.0.1 -d plan_attainment_restore `
    "C:\backups\plan_attainment_YYYYMMDD.dump"
```
