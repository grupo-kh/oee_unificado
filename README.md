# Plan Attainment · Dashboard Web

Dashboard web de **solo lectura** para seguimiento de producción (Plan Attainment),
OEE, rendimiento por máquina/sección y mantenimiento preventivo.

## ✨ Vistas

1. **Plan Attainment** — Gauge de cumplimiento global + métricas OEE.
2. **Por Sección** — Ranking de secciones (barras horizontales).
3. **Por Máquina** — Cumplimiento por máquina.
4. **Evolución** — Serie temporal.
5. **Detalle Plan / Producido** — Tabla pivote Plan vs. Producido con semáforo.

Incluye además OEE unificado, análisis de rendimiento con drill-down, escandallo de
referencias y un módulo móvil para operarios de mantenimiento.

## 🧱 Stack

- **PHP 8.1** sobre Apache (XAMPP en producción).
- **PostgreSQL** (datos de la aplicación) vía PDO `pgsql`.
- **SQL Server** (orígenes MAPEX y SAGE) vía `pdo_sqlsrv` / `msodbcsql18`.
- **Composer**: PhpSpreadsheet (lectura de Excel) y mPDF (exportación PDF).
- Frontend en HTML + JS vanilla.

## 🚀 Puesta en marcha

```bash
# 1. Dependencias PHP
composer install

# 2. Configuración: copia la plantilla y rellena tus valores
cp .env.example .env
#   Edita .env con los datos de TU entorno (hosts de BD, credenciales,
#   rutas a los Excel, etc.). Ver descripción de cada clave en .env.example.

# 3. Base de datos PostgreSQL
#   Crea la BD y aplica el esquema correspondiente.
```

Sirve el proyecto desde la raíz web de Apache y accede vía navegador.

## ⚙️ Configuración (`.env`)

**Toda** la configuración sensible (hosts, bases de datos, usuarios, contraseñas y
rutas locales/de red) se inyecta exclusivamente por variables de entorno desde `.env`.

- `.env` **nunca** se versiona (está en `.gitignore`).
- El repositorio **no contiene** ningún host, IP, ruta de red ni credencial real:
  los valores por defecto en código están vacíos.
- Usa [`.env.example`](.env.example) como referencia de las claves disponibles.

Las contraseñas de los logins de mantenimiento se almacenan como **hash bcrypt**
(`MANT_*_PASS_HASH`), nunca en texto plano.

## 📂 Estructura

```
PLAN_ATTAINMENT/
├── index.php            Home
├── config/database.php  Conexiones (valores vía .env)
├── includes/            Cabecera, helpers (env, JSON)
├── api/                 Endpoints JSON
├── views/               Vistas HTML/PHP
├── lib/                 Lógica (lectura Excel, agregaciones, stores)
├── assets/              JS y CSS
├── mobile-operario/     Módulo móvil de mantenimiento
└── vendor/              Dependencias Composer
```

## 🔒 Seguridad

- Secretos y configuración del entorno **solo** en `.env` (no versionado).
- El secreto de firma de tokens QR (`config/qr_secret.dat`) está excluido del repo.
- Dashboard de solo lectura sobre los orígenes de datos.
