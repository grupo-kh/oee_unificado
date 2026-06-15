# 🧭 Notebook · Plan Attainment

Inteligencia acumulada sobre el proyecto. Leer ANTES de cualquier misión.

| Nota | De qué trata | Tags |
|------|--------------|------|
| [arquitectura.md](arquitectura.md) | Stack, estructura de carpetas, contrato `apiFetch`, fuentes de datos | arquitectura, datos, api |
| [mapa-vistas-apis.md](mapa-vistas-apis.md) | Las 3 áreas funcionales + sus vistas y endpoints | navegacion, vistas, api |
| [acceso-web-local.md](acceso-web-local.md) | Cómo abrir la app de producción en Chrome (cert self-signed → proxy local) | gotcha, despliegue, chrome |
| [auth-mantenimiento.md](auth-mantenimiento.md) | Login por sesión, roles técnico/operario, flujo 401 | auth, mantenimiento, seguridad |
| [deploy-ai-studio.md](deploy-ai-studio.md) | Despliegue Docker+Caddy en ai-studio (10.0.0.110), URL, build, pendientes | despliegue, docker, caddy, postgres |

## Resumen en una frase
Dashboard PHP de **solo lectura** (XAMPP, sin framework) que replica el Plan Attainment de QlikView,
cruzando **plan (Excel) + producción real (MAPEX) + ERP (SAGE)** y guardando lo propio en **PostgreSQL**.
El único módulo con escritura/login es **Mantenimiento Preventivo**.

## Despliegue conocido
- **Producción:** `https://10.0.5.67/PLAN_ATTAINMENT/` (XAMPP Win64, Apache 2.4.58, PHP 8.1.25, cert self-signed).
- **Local (este repo):** XAMPP en `/Volumes/htdocs/PLAN_ATTAINMENT`; Apache estaba apagado. Para estudio
  rápido vale `php -S 127.0.0.1:PORT` desde la raíz (la home y la navegación renderizan; los datos
  necesitan alcanzar las BD de la red 10.0.x).
