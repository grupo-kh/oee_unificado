# Mobile Operario — App Next.js para mantenimiento preventivo

Fecha: 2026-05-27
Tipo: nueva aplicación frontend
Ubicación: `mobile-operario/` (subcarpeta del repo actual)

## Objetivo

Aplicación web móvil (tablet/móvil) para que los operarios de mantenimiento:

1. Entren con su número de operario (mismo nº como usuario y como contraseña, como el login web actual).
2. Vean las revisiones preventivas del **día en curso** en pantalla principal.
3. Accedan a **Pendientes** (vencidas + marcadas) desde un botón.
4. Al iniciar una tarea, registren hora de inicio y duración trabajada **sin segundero visible**, con posibilidad de **pausar / reanudar / finalizar**.
5. La intervención queda atribuida al operario logado en `mant_completions` (campos ya existentes).

Una vista futura "histórico mensual por operario y máquina" está prevista pero **fuera del scope de este MVP** (el usuario lo pedirá explícitamente más adelante).

## Stack

- **Next.js 14** (App Router) + **TypeScript**.
- **Tailwind CSS** + **shadcn/ui** (Radix bajo Tailwind).
- **TanStack Query v5** para fetching + caché.
- **react-hook-form + zod** para forms.
- **date-fns** para fechas; **lucide-react** para iconos.
- **`@ducanh2912/next-pwa`** en modo "manifest-only" (sin service worker complejo).

## Arquitectura

### Despliegue

- **Dev local**: `npm run dev` en `localhost:3000`. `next.config.js` define rewrites: `/api/*` → `http://localhost/PLAN_ATTAINMENT/api/*` — el navegador ve mismo origen, cookies de sesión PHP funcionan sin CORS.
- **Producción** (cuando llegue el subdominio): el subdominio sirve la carpeta `out/` del export estático + proxy_pass de `/api/*` al backend principal vía Apache/nginx. El frontend siempre llama a `/api/...` sin importar el entorno.

### Auth

- Sesión PHP cookie (`PHPSESSID`) reutilizada.
- Login vía nuevo endpoint JSON `api/mant_login_json.php`: POST `{usuario, contrasena}` → `{ok:true, user, role, csrf_token}`.
- Token CSRF almacenado en memoria + `sessionStorage`, enviado en `X-CSRF-Token` en POSTs (mant_marcar_hecha, mant_set_pendiente, etc.).
- Hook `useAuth()` consume `/api/mant_session.php` (existente) para hidratar estado al recargar.

## Estructura de carpetas

```
mobile-operario/
├─ src/
│  ├─ app/
│  │  ├─ layout.tsx                    # shell, providers (QueryClient, ThemeProvider, AuthProvider)
│  │  ├─ page.tsx                      # redirect según sesión: /login o /hoy
│  │  ├─ login/page.tsx
│  │  ├─ hoy/page.tsx                  # home tras login
│  │  ├─ pendientes/page.tsx
│  │  ├─ tarea/[id]/page.tsx           # detalle con timer
│  │  └─ confirmacion/page.tsx
│  ├─ components/
│  │  ├─ ui/                           # shadcn/ui base (button, card, dialog, etc.)
│  │  ├─ KeypadInput.tsx               # teclado numérico para login
│  │  ├─ TaskCard.tsx                  # card de tarea en lista
│  │  ├─ TimerCard.tsx                 # total acumulado, sin tick
│  │  ├─ TopBar.tsx
│  │  └─ BottomBar.tsx
│  ├─ lib/
│  │  ├─ api.ts                        # wrapper fetch con CSRF + manejo de 401
│  │  ├─ auth.ts                       # login / logout / sesion
│  │  ├─ queries.ts                    # hooks de React Query
│  │  ├─ types.ts                      # Task, Operario, Sesion, ...
│  │  └─ utils.ts                      # cn() helper, formatters
│  ├─ hooks/
│  │  ├─ useAuth.ts
│  │  └─ useTaskTimer.ts               # estado del timer en localStorage
│  └─ styles/globals.css                # tema Tailwind + variables CSS KH
├─ public/
│  ├─ manifest.json
│  ├─ icon-192.png
│  ├─ icon-512.png
│  └─ apple-touch-icon.png
├─ next.config.js
├─ tailwind.config.ts
├─ tsconfig.json
├─ package.json
├─ .env.local                           # vars dev
└─ README.md                            # instrucciones dev + deploy
```

## Pantallas

### 1. `/login`

- Logo KH centrado.
- Texto: "Identifícate · Introduce tu número de operario para empezar el turno".
- Input grande readonly + teclado numérico 3×4 (1-9, 0, ×, ⌫).
- Botón "Entrar" (deshabilitado hasta tener ≥1 dígito).
- Submit → POST `/api/mant_login_json.php` con `usuario=NUM`, `contrasena=NUM`. En éxito, guarda token CSRF y redirige a `/hoy`. En error, badge en rojo.

### 2. `/hoy` (home)

- TopBar negra con: "Hola, Operario {NUM}" + botón "Salir" → POST `/api/mant_logout.php` + redirect a `/login`.
- Cabecera: día con día y fecha grandes ("Jueves, 27 de mayo").
- Pill counter: "Hoy: {N} tareas".
- Lista de cards de tareas con `proxima_revision = today`, filtrando máquinas SEC (E66, RACK, PLATAFORMA, TROLEY).
- Card de tarea: máquina (negrita) · periodicidad · tarea · tap → `/tarea/[id]`.
- Botón flotante inferior: **"Pendientes →"** con badge `N` del nº total de pendientes.

### 3. `/pendientes`

- TopBar con back arrow + "Pendientes".
- Sección 1 "Vencidas" — `proxima_revision < today` y aún no marcadas.
- Sección 2 "Marcadas para revisar" — del store `mant_pendientes`.
- Cards iguales que en `/hoy`. Tap → `/tarea/[id]`.
- Si una sección queda vacía, se oculta su título.

### 4. `/tarea/[id]`

- `[id]` codifica `orden|tarea|fecha_proxima_original` (slug url-encoded).
- TopBar con back arrow + "Tarea en curso".
- Cabecera del detalle: máquina · periodicidad · tarea + fecha_proxima_original ("Programada el 25/05/2026").
- **TimerCard sin segundero**:
  - "Tiempo trabajado: 23 min"
  - "Iniciado a las 09:15"
  - Estado: ▶ En curso · ⏸ Pausado · ⬛ Sin iniciar · ✓ Finalizada
  - **El total se recalcula solo al pulsar un botón**, no hay `setInterval`.
- Botones según estado:
  - `idle` → `[Iniciar]` (grande, rojo KH)
  - `running` → `[Pausar]` + `[Finalizar]`
  - `paused` → `[Reanudar]` + `[Finalizar]`
- Botón "Cancelar" en topbar → vuelve a la lista sin guardar.
- Al pulsar Finalizar: POST `/api/mant_marcar_hecha.php` con:
  - `orden`, `tarea`, `fecha_proxima_original` (del slug)
  - `tipo = 'completada'`
  - `operario` = nº del usuario logado
  - `fecha_intervencion` = hoy
  - `hora_inicio` = `startedAt` formateado HH:MM
  - `tiempo_real_segundos` = total computado
  - `marcada_por` = nº del operario
- En éxito → redirige a `/confirmacion?ok=1` y limpia el timer del `localStorage`.

### 5. `/confirmacion`

- Icono ✓ verde grande.
- "Revisión registrada".
- Resumen: Máquina · Operario · Fecha · Tiempo trabajado.
- Botón "Volver a lista" → `/hoy`.
- Auto-redirige a `/hoy` tras 5 segundos.

## Modelo del timer

```ts
// hooks/useTaskTimer.ts
type TaskTimerState = {
  state: 'idle' | 'running' | 'paused' | 'finished';
  startedAt: string | null;        // ISO, primera pulsación de Iniciar
  totalAtLastPause: number;        // segundos acumulados antes de la última reanudación
  runningSince: string | null;     // ISO del último Iniciar/Reanudar; null si pausado
};

function getTotalSeconds(t: TaskTimerState, now: Date): number {
  if (t.state === 'running' && t.runningSince) {
    return t.totalAtLastPause + Math.floor((now.getTime() - new Date(t.runningSince).getTime()) / 1000);
  }
  return t.totalAtLastPause;
}
```

Acciones (idempotentes):
- `start()`: `idle → running` · setea `startedAt = now`, `runningSince = now`, `totalAtLastPause = 0`.
- `pause()`: `running → paused` · `totalAtLastPause += now - runningSince`, `runningSince = null`.
- `resume()`: `paused → running` · `runningSince = now`.
- `finish()`: cualquiera → `finished` · calcula total final, ejecuta API call, limpia el storage.

Persistencia: clave `mobile-operario:timer:{id}` en `localStorage`. Sirve para:
- Si la pestaña se duerme o se cierra, al volver la tarea sigue donde estaba.
- Si el operario cambia de pantalla y vuelve, sigue.

Una sola tarea puede estar en curso a la vez. Al iniciar tarea B con A todavía running, mostramos modal "Tienes la tarea X en curso — ¿pausarla?" antes de empezar B.

## API · Cambios backend mínimos

### Nuevo: `api/mant_login_json.php`

```php
<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);

$payload = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$user = (string)($payload['usuario'] ?? '');
$pass = (string)($payload['contrasena'] ?? '');

if (Auth::login($user, $pass)) {
    jsonOk([
        'user'       => Auth::user(),
        'role'       => Auth::role(),
        'csrf_token' => Auth::csrfToken(),
    ]);
}
jsonError('Credenciales inválidas', 401);
```

### Posible nuevo: `api/mant_pendientes.php`

A verificar — puede que ya exista parcial. Si no, lo creamos como combinación de:
- `mant_proximas.php?solo_vencidas=1` para vencidas no marcadas
- Lectura de `MaintenancePendienteStore::loadAll()` para flag manual

Output unificado:
```json
{
  "ok": true,
  "data": {
    "vencidas": [...],
    "marcadas": [...]
  }
}
```

### Sin cambios

- `api/mant_proximas.php` — usado para `/hoy` con `fecha_desde=fecha_hasta=today`.
- `api/mant_marcar_hecha.php` — acepta `tiempo_real_segundos` y `hora_inicio` (verificado).
- `api/mant_logout.php` — existente.
- `api/mant_session.php` — para hidratar `useAuth` al recargar.

### CSRF

- El token llega en la respuesta del login JSON.
- Frontend lo guarda en `sessionStorage` (clave `mobile-operario:csrf`).
- Wrapper `apiPost()` lo envía en header `X-CSRF-Token` automáticamente.

### CORS

- En dev no hace falta gracias a los rewrites del `next.config.js`.
- En prod, si el subdominio no proxy-passea el backend, añadir Allow-Origin/Allow-Credentials desde `.env`. Probablemente innecesario.

## PWA

`public/manifest.json`:

```json
{
  "name": "KH Mantenimiento Operario",
  "short_name": "KH Operario",
  "description": "Revisiones preventivas para operarios",
  "start_url": "/",
  "display": "standalone",
  "orientation": "portrait",
  "background_color": "#0d0d0d",
  "theme_color": "#8c181a",
  "icons": [
    { "src": "/icon-192.png", "sizes": "192x192", "type": "image/png" },
    { "src": "/icon-512.png", "sizes": "512x512", "type": "image/png" }
  ]
}
```

Tags en `layout.tsx`:

```tsx
export const metadata: Metadata = {
  title: 'KH Mantenimiento Operario',
  manifest: '/manifest.json',
  themeColor: '#8c181a',
  appleWebApp: { capable: true, statusBarStyle: 'black-translucent', title: 'KH Operario' },
};
```

`@ducanh2912/next-pwa` configurado con `disable: process.env.NODE_ENV === 'development'` y SW mínimo (cache-first para assets `_next/static`, nada para `/api/*`).

## Tema visual

Variables CSS en `globals.css` (idénticas a `prototipos/operario_mobile.html`):

```css
:root {
  --kh-red: #8c181a;
  --kh-red-2: #a52125;
  --kh-red-dark: #6a1213;
  --kh-black: #0d0d0d;
  --kh-amber: #c47600;
  --kh-green: #1f8a3c;
  --kh-bg: #f5f3f3;
  --kh-card: #ffffff;
  --kh-text: #1a1a1a;
  --kh-text-soft: #6b6b6b;
}
```

Tailwind extends con estas variables:

```ts
// tailwind.config.ts
theme: {
  extend: {
    colors: {
      kh: {
        red: 'var(--kh-red)',
        'red-2': 'var(--kh-red-2)',
        'red-dark': 'var(--kh-red-dark)',
        black: 'var(--kh-black)',
        amber: 'var(--kh-amber)',
        green: 'var(--kh-green)',
      },
    },
  },
}
```

Componentes shadcn (`button`, `card`, `dialog`, `badge`, `separator`) se importan y se personalizan al estilo KH (rojo CTA, esquinas redondeadas, sombras suaves).

## Manejo de errores

| Caso | Respuesta UX |
|---|---|
| Login fallido | Badge rojo bajo el input "Operario no válido. Vuelve a intentarlo." |
| 401 en una llamada autenticada | Logout automático + redirect a `/login` con toast "Sesión expirada" |
| Error de red (5xx, timeout) | Toast rojo "Error de conexión, reintentando…" + retry automático via React Query |
| `marcar_hecha` falla | Modal con error + el timer NO se limpia (el operario puede reintentar finalizar) |
| Pestaña dormida más de 12h | Al volver, si hay timer activo, mostramos "Tarea sigue activa desde ayer · pausada automáticamente". |

## Testing

- **Manual**: instalable en Chrome Android (Add to Home Screen) y Safari iOS.
- **Componentes**: Storybook diferido. Para MVP, prueba manual en dispositivo.
- **Lint + typecheck**: `tsc --noEmit` + `eslint` configurados.

## Out of scope (MVP)

- ❌ Sub-tareas para tareas consolidadas (BT, racks). v2 después de MVP.
- ❌ Histórico mensual por operario/máquina (el usuario lo pedirá más tarde).
- ❌ Offline real / sincronización en segundo plano.
- ❌ Push notifications.
- ❌ Edición de intervenciones desde móvil (operario solo marca hechas).

## Entregables

1. Carpeta `mobile-operario/` con la app Next.js completa, lista para `npm install && npm run dev`.
2. `api/mant_login_json.php` en el backend PHP (nuevo, ~30 líneas).
3. `api/mant_pendientes.php` si fuera necesario crear/extender.
4. `mobile-operario/README.md` con instrucciones de dev, build, y deploy al subdominio.
5. Spec + plan de implementación en `docs/superpowers/`.
