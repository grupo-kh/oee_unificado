# Despliegue en ai-studio (10.0.0.110)

Segundo entorno de la app, además del XAMPP de producción `10.0.5.67`. Montado el 2026-06-08.
Servidor Proxmox Linux (Debian), `sudo` NOPASSWD, Docker + PostgreSQL 16 nativos, sin PHP ni Apache.

## URL pública
`https://10.0.0.110/PLAN_ATTAINMENT/`  (también `https://newpower.grupokh.com/PLAN_ATTAINMENT/`)
Cert **interno de Caddy** (`tls internal`) → Chrome avisa de cert salvo que se confíe la CA de Caddy.

## Topología
```
Browser ──HTTPS:443──> Caddy (/etc/caddy/Caddyfile)
                         ├─ handle /PLAN_ATTAINMENT/*  → 127.0.0.1:8090  (contenedor PHP, ESTA app)
                         └─ handle (resto)             → 127.0.0.1:8189  (power_v2, ya existia)
contenedor `plan_attainment` (--network host, Apache Listen 127.0.0.1:8090)
   ├─ monta  /home/aistudio/PLAN_ATTAINMENT → /var/www/html/PLAN_ATTAINMENT
   ├─ PostgreSQL  → 127.0.0.1:5432 / plan_attainment (host, mismo network)
   └─ SQL Server  → 10.0.0.45:1433 (MAPEX) y SAGE   [driver msodbcsql18 + pdo_sqlsrv]
```

## Artefactos de build (en el server, `~/pa-deploy/`)
- `Dockerfile` — `php:8.3-apache-bookworm` + pdo_pgsql/pgsql + sqlsrv/pdo_sqlsrv + gd/zip/intl/mbstring.
  ⚠️ Base fijada a **bookworm**: la etiqueta `php:8.3-apache` saltó a trixie/13 y el repo MS es para debian/12.
  ⚠️ La key MS debe ir dearmored en `/usr/share/keyrings/microsoft-prod.gpg` (lo exige su `prod.list`, signed-by).
- `pa-apache.conf` — vhost `127.0.0.1:8090`, DocumentRoot `/var/www/html`, `AllowOverride All` (para el `.htaccess`).
- `Caddyfile.new` — versión aplicada del Caddyfile. Backup del original en `/etc/caddy/Caddyfile.bak_<ts>`.

## Comandos clave
```bash
# build / run
cd ~/pa-deploy && docker build -t plan_attainment:latest .
docker run -d --name plan_attainment --restart unless-stopped --network host \
  -v /home/aistudio/PLAN_ATTAINMENT:/var/www/html/PLAN_ATTAINMENT plan_attainment:latest
# migraciones PG (instalador oficial, dentro del contenedor)
docker exec plan_attainment php /var/www/html/PLAN_ATTAINMENT/tools/install_postgres.php
# recargar Caddy tras editar el Caddyfile
sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile && sudo systemctl reload caddy
```

## Estado verificado (2026-06-08)
- ✅ Home, assets, vistas → HTTP 200 por HTTPS.
- ✅ API OEE devuelve datos reales de MAPEX (OEE 78.41 %, 17 máquinas) → driver sqlsrv OK.
- ✅ `power_v2` (8189) intacto tras tocar Caddy.
- ✅ Contenedor con `--restart unless-stopped` (sobrevive reboot).

## ⚠️ Paridad con 10.0.5.67 — NO es 100% (diagnosticado 2026-06-08)
Comparación API a API (md5 con fecha cerrada 05/06):
- ✅ **IDÉNTICO byte a byte**: `oee_unificado`, `calidad_global`, `disponibilidad_global`, `rendimiento_global`,
  `oee_fab_global` → todo lo que viene SOLO de MAPEX/SAGE (SQL Server). La conexión sqlsrv funciona perfecta.
- ❌ **DIFIERE**: `plan_attainment`, `por_seccion`, `por_maquina`, `grid` → todo lo que cruza el **Plan de producción**.
  En ai-studio el **plan sale 0** (ej. plan_attainment 05/06: ai-studio=0 vs 10.0.5.67=21.8).

**Causa raíz**: `lib/PlanExcelReader.php:26` → `EXCEL_BASE_PATH = 'Z:\Produccion\…\Planificaciones diarias'`.
Es un share de red **Windows** con los `.xlsm` de planificación diaria. `glob()` con esa ruta no existe en Linux →
plan vacío. Para igualar Plan Attainment en ai-studio hay que: (a) montar ese share SMB en el server, (b) hacer
`EXCEL_BASE_PATH` configurable por `.env` con ruta POSIX, y (c) revisar separadores `\` → `/` en los `glob()`
(`PlanExcelReader.php:98`, `PlanAttainmentAgg.php:249`). El módulo Plan/Prod NO es portable tal cual a Linux.

## Pendientes / no bloqueantes
- **Datos de mantenimiento vacíos** (`mant_operarios`, `mant_plan` = 0 filas). Venían del Excel `Z:\Mantenimiento\…xlsx`
  (Windows), no accesible desde Linux. Requiere un import aparte si se quiere el módulo de mantenimiento con datos.
- **`APP_ENV=local`** en el `.env` del server (no `production`). Los hashes de login ya están presentes; subir a
  `production` endurece el login (sin fallback dev) si se desea.
- **Plan Attainment (gauge)**: depende del Excel de planificación; revisar de dónde lo toma en este entorno.
- Para versionar el deploy: los artefactos viven en `~/pa-deploy/` del server, fuera del repo git.
