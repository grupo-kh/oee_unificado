# KH Mantenimiento · Operario (mobile)

App móvil Next.js para que los operarios de mantenimiento registren sus revisiones preventivas.

## Stack

- Next.js 14 (App Router) + TypeScript
- Tailwind CSS (tema corporativo KH)
- TanStack Query (React Query) v5
- PWA básica (manifest + iconos), sin offline real

## Dev

El backend PHP (XAMPP) debe estar arrancado. En este equipo Apache escucha en
`http://localhost:8080/` — el rewrite de `next.config.mjs` apunta ahí.

```powershell
cd mobile-operario
npm install         # solo la primera vez
npm run dev
```

Abre `http://localhost:3000/`.

Login: número de operario activo en `mant_operarios`. El mismo número sirve como
usuario y como contraseña.

Las llamadas a `/api/*` se proxyean a `http://localhost:8080/PLAN_ATTAINMENT/api/*`
vía `next.config.mjs`. Al ser mismo origen para el navegador, la cookie de sesión
PHP funciona transparente.

> Si tu Apache escucha en otro puerto (p. ej. 80), edita el `destination` del
> rewrite en `next.config.mjs`.

## Build

```powershell
npm run build
npm start    # servidor de producción local
```

## Deploy al subdominio

Cuando esté disponible el subdominio:

1. `npm run build` genera `.next/` con assets optimizados y el service worker.
2. Copia `.next/`, `public/`, `package.json` y `package-lock.json` al host; en el
   servidor ejecuta `npm ci --omit=dev`.
3. Arranca con `npm start` o un gestor de procesos (`pm2 start npm -- start`).
4. Configura el front del subdominio (Apache/nginx) para hacer
   `proxy_pass /api/* → http://<backend>/PLAN_ATTAINMENT/api/*`. Mismo origen al
   navegador → cookies de sesión funcionan sin CORS.

## Smoke test (manual)

- [ ] Login con un nº de operario válido → redirige a `/hoy`.
- [ ] Login con un nº incorrecto → muestra "Operario no válido".
- [ ] `/hoy` muestra tareas programadas para hoy.
- [ ] Botón "Pendientes" lleva a `/pendientes` con vencidas + marcadas.
- [ ] Click en una tarea → detalle.
- [ ] Iniciar → estado "▶ En curso" (sin segundero en vivo).
- [ ] Pausar → "⏸ Pausado", tiempo total congelado.
- [ ] Reanudar → "▶ En curso".
- [ ] Finalizar → POST `mant_marcar_hecha.php`, redirige a `/confirmacion`.
- [ ] Confirmación muestra resumen y auto-redirige a `/hoy` en 5s.
- [ ] Cerrar pestaña a mitad de tarea + reabrir → estado restaurado desde `localStorage`.
- [ ] Salir → POST logout, redirige a `/login`.
- [ ] En Chrome Android: "Añadir a pantalla de inicio" → instala con icono KH.

## Endpoints backend usados

| Endpoint | Método | Uso |
|---|---|---|
| `/api/mant_login_json.php` | POST | Login |
| `/api/mant_logout_json.php` | POST | Logout |
| `/api/mant_session.php` | GET | Hidratación de sesión al recargar |
| `/api/mant_dashboard.php` | GET | `{hoy, vencidas, marcadas}` |
| `/api/mant_marcar_hecha.php` | POST | Marcar tarea como completada |

CSRF: el login devuelve `csrf_token`; el wrapper `apiPost` lo envía en
`X-CSRF-Token` en cada POST.

## Notas

- Los iconos `public/icon-*.png` y `apple-touch-icon.png` son placeholders
  generados (cuadro rojo KH con "KH"). Sustitúyelos por el icono definitivo
  cuando esté disponible.
- El service worker (`public/sw.js`, `public/workbox-*.js`) se genera en el
  build y está gitignored.
- Fuera del MVP: sub-tareas de tareas consolidadas, histórico mensual por
  operario/máquina, y modo offline real.
