# Plan Attainment · Dashboard Web KH

Dashboard web de **solo lectura** que replica el dashboard Plan Attainment de QlikView.

Incluye **home con 5 accesos grandes** a cada vista individual:

1. **Plan Attainment** — Gauge grande con cumplimiento global + métricas OEE
2. **Por Sección** — Barras horizontales ranking de secciones
3. **Por Máquina** — Barras verticales con porcentaje por máquina
4. **Evolución** — Serie temporal amarilla (estilo QlikView)
5. **Detalle Plan / Producido** — Tabla pivote con Plan vs. Prod por fecha y colores semafóricos

## 📁 Estructura

```
PLAN_ATTAINMENT/
├── index.php                      Home con 5 botones
├── config/
│   └── database.php               ⚠️ Credenciales BD (editar)
├── includes/
│   ├── header.php                 Cabecera común (logo + filtros)
│   └── helpers.php                JSON, parámetros
├── api/
│   ├── plan_attainment.php        KPI global + OEE
│   ├── por_seccion.php            Cumplimiento por sección
│   ├── por_maquina.php            Cumplimiento por máquina
│   ├── evolucion.php              Serie temporal
│   └── grid.php                   Tabla pivote Plan/Prod
├── views/
│   ├── plan_attainment.php
│   ├── por_seccion.php
│   ├── por_maquina.php
│   ├── evolucion.php
│   └── grid.php
└── assets/
    ├── css/style.css              Azul corporativo + semáforo
    └── js/
        ├── common.js              Filtros compartidos + utils
        ├── view_gauge.js
        ├── view_seccion.js
        ├── view_maquina.js
        ├── view_evolucion.js
        └── view_grid.js
```

## 🎨 Estilo visual

Replica exactamente la estética del dashboard QlikView original:

- **Cabecera azul oscuro** con logo KH (3 puntos rojos) y filtros verdes
- **Colores semafóricos** en tablas: verde (cumplido), ámbar (parcial), rojo (incumplido)
- **Línea de objetivo 75%** punteada verde en todos los gráficos
- **Tipografía Arial** como el original
- **Filtros persistentes** entre vistas (localStorage) — al navegar entre vistas mantiene fecha y turno

## 🚀 Instalación

### 1. Copiar archivos

Copia toda la carpeta `PLAN_ATTAINMENT` a:
```
C:\xampp\htdocs\PLAN_ATTAINMENT\
```

### 2. Activar driver SQL Server en PHP

XAMPP **no trae el driver `sqlsrv` activo**. Pasos:

**a)** Descarga el driver oficial de Microsoft:
https://learn.microsoft.com/es-es/sql/connect/php/download-drivers-php-sql-server

Elige el ZIP que **coincida con tu versión de PHP** (ejecuta `php -v` en CMD para saberla).

**b)** Copia estos DLLs a `C:\xampp\php\ext\`:
- `php_sqlsrv_XX_ts_x64.dll`
- `php_pdo_sqlsrv_XX_ts_x64.dll`

(Donde `XX` = versión de PHP, `ts` = thread-safe, `x64` = 64 bits)

**c)** Edita `C:\xampp\php\php.ini` y añade al final:
```ini
extension=sqlsrv
extension=pdo_sqlsrv
```

**d)** Instala también el **ODBC Driver 17 (o 18) for SQL Server**:
https://learn.microsoft.com/es-es/sql/connect/odbc/download-odbc-driver-for-sql-server

**e)** Reinicia Apache desde el panel XAMPP.

**f)** Verifica abriendo `http://localhost/dashboard/phpinfo.php` que aparezca `sqlsrv`.

### 3. Configurar credenciales

Edita `config/database.php` y pon las contraseñas reales:
```php
define('DB_MAPEX_PASS', 'TU_PASSWORD_REAL');
define('DB_SAGE_PASS',  'TU_PASSWORD_REAL');
```

### 4. Acceder

```
http://localhost/PLAN_ATTAINMENT/
```

## 🔒 Seguridad

- Todas las queries son **SELECT** (solo lectura) — validado en `fetchAll()`
- Consultas **parametrizadas** (anti SQL-injection)
- Fechas validadas con regex
- Turnos con whitelist (M/T/N)

**Recomendación**: el usuario `sa` tiene permisos totales. Pide a IT un usuario solo-lectura dedicado.

## 🧪 Probar las APIs directamente

```
http://localhost/PLAN_ATTAINMENT/api/plan_attainment.php?fecha_desde=2026-04-15&fecha_hasta=2026-04-22
http://localhost/PLAN_ATTAINMENT/api/por_seccion.php?fecha_desde=2026-04-15&fecha_hasta=2026-04-22
http://localhost/PLAN_ATTAINMENT/api/por_maquina.php?fecha_desde=2026-04-15&fecha_hasta=2026-04-22
http://localhost/PLAN_ATTAINMENT/api/evolucion.php?fecha_desde=2026-03-22&fecha_hasta=2026-04-22
http://localhost/PLAN_ATTAINMENT/api/grid.php?fecha_desde=2026-04-15&fecha_hasta=2026-04-22
```

## 🐛 Troubleshooting

| Error | Causa | Solución |
|-------|-------|----------|
| `could not find driver` | Driver sqlsrv no activo | Revisa paso 2 |
| `Login failed for user` | Password incorrecta | Revisa `database.php` |
| `SQLSTATE[08001]` | Sin conectividad a servidor | Firewall / VPN |
| Gauge/charts vacíos | Sin datos en rango | Amplía rango de fechas |
| Grid vacío | Falta campo `Cantidad_plan` | Ver nota abajo |
| Cache del navegador | Cambios no se ven | `Ctrl + F5` |

## ⚠️ Posibles ajustes según tu BD

El **grid** usa `his_fase.Cantidad_plan` para los planificados. Si ese campo no existe en tu instalación de MAPEX, los planificados saldrán vacíos (el producido sí saldrá). En ese caso dime y lo ajustamos al campo correcto.

Lo mismo con los nombres de columnas de `F_his_ct`: si alguno difiere (p.ej. `M_OKNOK_TEO` se llama distinto), se ajusta rápido.

## 📝 Cómo se calcula el Plan Attainment

Basado en las fórmulas oficiales de MAPEX (tal y como aparecen en el script original `Transformación.qvs`):

```
Disponibilidad  = M / (M + PNP)
Calidad         = M_OK_TEO / M_OKNOK_TEO
Rendimiento     = M_OKNOK_TEO / M_Teo   ← ESTE es el Plan Attainment
OEE             = Disponibilidad × Calidad × Rendimiento
```

Donde:
- `M` = tiempo marcha
- `PNP` = paros no programados
- `M_OK_TEO` = tiempo productivo con piezas OK (teórico)
- `M_OKNOK_TEO` = tiempo productivo total (OK + NOK teórico)
- `M_Teo` = tiempo marcha teórico (planificado)
