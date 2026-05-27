# Mobile Operario · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir la app móvil Next.js `mobile-operario/` para que los operarios de mantenimiento entren con su número, vean tareas del día + pendientes, e inicien/pausen/finalicen revisiones que se guardan en `mant_completions` atribuidas al operario logado.

**Architecture:** Next.js 14 (App Router) + TypeScript + Tailwind + shadcn/ui + TanStack Query, hospedado como subcarpeta del repo actual. Talks to the existing PHP backend vía cuatro endpoints JSON nuevos (login, logout, session, dashboard). En dev usa rewrites de Next para mismo-origen; en prod, proxy_pass del subdominio al backend principal. PWA básica (manifest + iconos), sin offline real.

**Tech Stack:** Next.js 14 App Router, TypeScript 5, Tailwind 3, shadcn/ui (Radix), TanStack Query v5, react-hook-form + zod, date-fns, lucide-react, `@ducanh2912/next-pwa`.

**Reference spec:** [docs/superpowers/specs/2026-05-27-mobile-operario-design.md](../specs/2026-05-27-mobile-operario-design.md)

**Local dev URLs:**
- Frontend dev: `http://localhost:3000/`
- Backend PHP: `http://localhost/PLAN_ATTAINMENT/`

---

## File Structure

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `mobile-operario/package.json` | Crear (scaffold) | Manifiesto npm |
| `mobile-operario/next.config.js` | Crear/modificar | Rewrites a backend + plugin PWA |
| `mobile-operario/tailwind.config.ts` | Modificar | Tokens de color KH |
| `mobile-operario/tsconfig.json` | Scaffold | Aliases (@/) |
| `mobile-operario/src/styles/globals.css` | Modificar | Variables CSS KH |
| `mobile-operario/src/app/layout.tsx` | Modificar | Providers (QueryClient, Auth) + meta tags PWA |
| `mobile-operario/src/app/page.tsx` | Modificar | Redirector según sesión |
| `mobile-operario/src/app/login/page.tsx` | Crear | Pantalla login con keypad |
| `mobile-operario/src/app/hoy/page.tsx` | Crear | Lista tareas del día |
| `mobile-operario/src/app/pendientes/page.tsx` | Crear | Vencidas + marcadas |
| `mobile-operario/src/app/tarea/[id]/page.tsx` | Crear | Detalle + timer |
| `mobile-operario/src/app/confirmacion/page.tsx` | Crear | Pantalla éxito |
| `mobile-operario/src/lib/api.ts` | Crear | Wrapper fetch + CSRF |
| `mobile-operario/src/lib/types.ts` | Crear | Types compartidos |
| `mobile-operario/src/lib/queries.ts` | Crear | Hooks de React Query |
| `mobile-operario/src/lib/auth-context.tsx` | Crear | Auth Provider + useAuth |
| `mobile-operario/src/lib/utils.ts` | Crear | cn() + formatters |
| `mobile-operario/src/components/KeypadInput.tsx` | Crear | Teclado numérico |
| `mobile-operario/src/components/TopBar.tsx` | Crear | Cabecera negra |
| `mobile-operario/src/components/TaskCard.tsx` | Crear | Card de tarea en lista |
| `mobile-operario/src/components/TimerCard.tsx` | Crear | Display tiempo trabajado |
| `mobile-operario/src/hooks/useTaskTimer.ts` | Crear | Estado del timer + localStorage |
| `mobile-operario/public/manifest.json` | Crear | PWA manifest |
| `mobile-operario/public/icon-*.png` | Crear | Iconos PWA |
| `mobile-operario/README.md` | Crear | Instrucciones dev + deploy |
| `api/mant_login_json.php` | Crear | Login JSON (~30 líneas) |
| `api/mant_logout_json.php` | Crear | Logout JSON 200 OK |
| `api/mant_session.php` | Crear | Sesión actual (hidratación) |
| `api/mant_dashboard.php` | Crear | `{hoy, vencidas, marcadas}` en una llamada |

---

## Task 1: Scaffold del proyecto Next.js

**Files:**
- Create (vía scaffold): `mobile-operario/*`

- [ ] **Step 1: Crear el proyecto**

Ejecuta desde `C:\xampp\htdocs\PLAN_ATTAINMENT\`:

```powershell
npx --yes create-next-app@14 mobile-operario --typescript --tailwind --eslint --app --src-dir --import-alias "@/*" --use-npm
```

Si pregunta interactivamente, acepta los defaults.

- [ ] **Step 2: Verificar que arranca**

```powershell
cd mobile-operario
npm run dev
```

Abre `http://localhost:3000` → debe verse la pantalla inicial de Next.js. Para el server con Ctrl+C.

- [ ] **Step 3: Commit**

```bash
git add mobile-operario
git commit -m "mobile-operario: scaffold inicial Next.js 14 + TS + Tailwind"
```

---

## Task 2: Dependencias adicionales + tema KH

**Files:**
- Modify: `mobile-operario/package.json` (via npm install)
- Modify: `mobile-operario/tailwind.config.ts`
- Modify: `mobile-operario/src/app/globals.css`

- [ ] **Step 1: Instalar deps**

Desde `mobile-operario/`:

```powershell
npm install @tanstack/react-query@5 react-hook-form zod @hookform/resolvers date-fns lucide-react clsx tailwind-merge class-variance-authority
npm install --save-dev @types/node
```

- [ ] **Step 2: Sustituir el contenido de `src/app/globals.css`**

Ruta: `mobile-operario/src/app/globals.css`. Contenido completo:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

:root {
  --kh-red: #8c181a;
  --kh-red-2: #a52125;
  --kh-red-dark: #6a1213;
  --kh-red-bg: #fbe6e7;
  --kh-black: #0d0d0d;
  --kh-black-2: #1d1d1d;
  --kh-amber: #c47600;
  --kh-amber-bg: #fff5e1;
  --kh-green: #1f8a3c;
  --kh-green-bg: #e3f5e8;
  --kh-text: #1a1a1a;
  --kh-text-soft: #6b6b6b;
  --kh-line: #e7e3e3;
  --kh-bg: #f5f3f3;
  --kh-card: #ffffff;
}

* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body {
  background: var(--kh-bg);
  color: var(--kh-text);
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  overscroll-behavior: none;
}
button { font: inherit; }
```

- [ ] **Step 3: Sustituir `tailwind.config.ts`**

Ruta: `mobile-operario/tailwind.config.ts`. Contenido completo:

```ts
import type { Config } from 'tailwindcss';

const config: Config = {
  content: ['./src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        kh: {
          red: 'var(--kh-red)',
          'red-2': 'var(--kh-red-2)',
          'red-dark': 'var(--kh-red-dark)',
          'red-bg': 'var(--kh-red-bg)',
          black: 'var(--kh-black)',
          'black-2': 'var(--kh-black-2)',
          amber: 'var(--kh-amber)',
          'amber-bg': 'var(--kh-amber-bg)',
          green: 'var(--kh-green)',
          'green-bg': 'var(--kh-green-bg)',
          text: 'var(--kh-text)',
          'text-soft': 'var(--kh-text-soft)',
          line: 'var(--kh-line)',
          bg: 'var(--kh-bg)',
          card: 'var(--kh-card)',
        },
      },
      maxWidth: { 'app': '480px' },
      boxShadow: {
        'kh-sm': '0 2px 8px rgba(140, 24, 26, 0.08)',
        'kh-md': '0 6px 18px rgba(140, 24, 26, 0.16)',
        'kh-lg': '0 8px 28px rgba(140, 24, 26, 0.24)',
      },
    },
  },
  plugins: [],
};
export default config;
```

- [ ] **Step 4: Verificar build**

```powershell
npm run build
```

Expected: build OK (puede tardar). Si falla, leer el error.

- [ ] **Step 5: Commit**

```bash
git add mobile-operario
git commit -m "mobile-operario: deps (react-query, forms, date-fns, icons) + tema KH"
```

---

## Task 3: Core lib (api, types, utils) + next.config rewrites

**Files:**
- Create: `mobile-operario/src/lib/api.ts`
- Create: `mobile-operario/src/lib/types.ts`
- Create: `mobile-operario/src/lib/utils.ts`
- Create: `mobile-operario/src/lib/queries.ts`
- Modify: `mobile-operario/next.config.js`

- [ ] **Step 1: Crear `src/lib/utils.ts`**

```ts
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

export function formatHora(hhmm?: string | null): string {
  if (!hhmm) return '—';
  return hhmm.slice(0, 5);
}

export function formatMinutos(segundos: number): string {
  const m = Math.floor(segundos / 60);
  const s = segundos % 60;
  if (m === 0) return `${s} s`;
  if (s === 0) return `${m} min`;
  return `${m} min ${s} s`;
}

export function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
```

- [ ] **Step 2: Crear `src/lib/types.ts`**

```ts
export type Role = 'tecnico' | 'operario' | null;

// El backend devuelve user/csrf_token null cuando no hay sesión activa
// (endpoint mant_session.php anónimo). Los hacemos opcionales/nullable.
export type SessionInfo = {
  user: string | null;
  role: Role;
  csrf_token: string | null;
};

export type Tarea = {
  orden: string;
  tarea: string;
  cod_maquina_mant: string;
  desc_maquina: string;
  desc_grupo: string;
  desc_tarea: string;
  periodicidad: string;
  proxima_revision: string;       // YYYY-MM-DD
  ultima_revision: string | null;
  is_pendiente: boolean;          // flag manual mant_pendientes
  estado?: 'vencida' | 'urgente' | 'en_plazo';
  dias_restantes?: number;
};

export type DashboardData = {
  hoy: Tarea[];
  vencidas: Tarea[];
  marcadas: Tarea[];
};

export type ApiOk<T> = { ok: true; data: T };
export type ApiErr  = { ok: false; error: string };
export type ApiRes<T> = ApiOk<T> | ApiErr;
```

- [ ] **Step 3: Crear `src/lib/api.ts`**

```ts
const CSRF_KEY = 'mobile-operario:csrf';

export function setCsrfToken(token: string) {
  sessionStorage.setItem(CSRF_KEY, token);
}

export function getCsrfToken(): string | null {
  if (typeof window === 'undefined') return null;
  return sessionStorage.getItem(CSRF_KEY);
}

export function clearCsrfToken() {
  sessionStorage.removeItem(CSRF_KEY);
}

export class ApiError extends Error {
  constructor(public status: number, message: string) {
    super(message);
  }
}

async function handle<T>(resp: Response): Promise<T> {
  let body: any = null;
  try { body = await resp.json(); } catch { /* sin JSON */ }
  if (!resp.ok || (body && body.ok === false)) {
    const msg = body?.error || `HTTP ${resp.status}`;
    throw new ApiError(resp.status, msg);
  }
  return (body?.data ?? body) as T;
}

export async function apiGet<T>(path: string, params: Record<string, string | number | undefined> = {}): Promise<T> {
  const qs = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== '' && v !== null) qs.set(k, String(v));
  });
  const url = qs.toString() ? `${path}?${qs}` : path;
  const resp = await fetch(url, { credentials: 'include' });
  return handle<T>(resp);
}

export async function apiPost<T>(path: string, body: unknown): Promise<T> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  const csrf = getCsrfToken();
  if (csrf) headers['X-CSRF-Token'] = csrf;
  const resp = await fetch(path, {
    method: 'POST',
    headers,
    credentials: 'include',
    body: JSON.stringify(body ?? {}),
  });
  return handle<T>(resp);
}
```

- [ ] **Step 4: Crear `src/lib/queries.ts` (skeleton, llenamos en tareas posteriores)**

```ts
import { QueryClient } from '@tanstack/react-query';

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 30_000,
      refetchOnWindowFocus: false,
    },
  },
});
```

- [ ] **Step 5: Sustituir `next.config.js`**

```js
/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: 'http://localhost/PLAN_ATTAINMENT/api/:path*',
      },
    ];
  },
};

module.exports = nextConfig;
```

- [ ] **Step 6: Type-check**

```powershell
npx tsc --noEmit
```

Expected: sin errores.

- [ ] **Step 7: Commit**

```bash
git add mobile-operario/src/lib mobile-operario/next.config.js
git commit -m "mobile-operario: lib api/types/utils + rewrites a backend PHP"
```

---

## Task 4: Backend · endpoints JSON de auth

**Files:**
- Create: `api/mant_login_json.php`
- Create: `api/mant_logout_json.php`
- Create: `api/mant_session.php`

- [ ] **Step 1: Crear `api/mant_login_json.php`**

```php
<?php
/**
 * Login JSON para la app móvil de operarios.
 * POST application/json: { usuario, contrasena }
 * 200 { ok:true, data:{ user, role, csrf_token } }
 * 401 { ok:false, error:'Credenciales inválidas' }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = $_POST;

$user = (string)($payload['usuario']    ?? '');
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

- [ ] **Step 2: Crear `api/mant_logout_json.php`**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::logout();
jsonOk(['logged_out' => true]);
```

- [ ] **Step 3: Crear `api/mant_session.php`**

```php
<?php
/**
 * Devuelve la sesión actual (para hidratación del frontend tras recargar).
 * 200 ok=true con user/role/csrf si hay sesión; 200 ok=true con role=null si no.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

if (Auth::isLoggedIn()) {
    jsonOk([
        'user'       => Auth::user(),
        'role'       => Auth::role(),
        'csrf_token' => Auth::csrfToken(),
    ]);
}
jsonOk(['user' => null, 'role' => null, 'csrf_token' => null]);
```

- [ ] **Step 4: Verificar sintaxis**

```powershell
php -l api/mant_login_json.php
php -l api/mant_logout_json.php
php -l api/mant_session.php
```

Expected: `No syntax errors detected` en los tres.

- [ ] **Step 5: Probar con curl (XAMPP debe estar arrancado)**

```powershell
curl -s "http://localhost/PLAN_ATTAINMENT/api/mant_session.php"
```

Expected: `{"ok":true,"data":{"user":null,"role":null,"csrf_token":null}}`

- [ ] **Step 6: Commit**

```bash
git add api/mant_login_json.php api/mant_logout_json.php api/mant_session.php
git commit -m "api: endpoints JSON de auth (login/logout/session) para la app móvil"
```

---

## Task 5: Backend · endpoint mant_dashboard.php

**Files:**
- Create: `api/mant_dashboard.php`

- [ ] **Step 1: Crear el endpoint**

```php
<?php
/**
 * API: dashboard agregado para la app móvil del operario.
 * Devuelve en una sola llamada tres listas: tareas de hoy, vencidas,
 * y marcadas-pendientes manualmente.
 *
 * Reutiliza la lógica de auto-reprogramación de mant_mobile.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MaintenancePlanStore.php';
require_once __DIR__ . '/../lib/MaintenanceCompletionStore.php';
require_once __DIR__ . '/../lib/MaintenancePeriodicidadStore.php';
require_once __DIR__ . '/../lib/MaintenancePendienteStore.php';

Auth::requireLoginApi();

try {
    $hoy = date('Y-m-d');
    $data = MaintenancePlanStore::load();
    $proximas = $data['proximas'];
    $latestByTask   = MaintenanceCompletionStore::loadLatestByTask();
    $perOverrideIdx = MaintenancePeriodicidadStore::loadIndexed();
    $pendientesIdx  = MaintenancePendienteStore::loadIndexed();

    $hoyArr = [];
    $vencidasArr = [];
    $marcadasArr = [];

    foreach ($proximas as $p) {
        $idOverride = MaintenancePeriodicidadStore::buildId((string)$p['orden'], (string)$p['tarea']);
        $eff = MaintenancePeriodicidadStore::applyOverride($p, $perOverrideIdx[$idOverride] ?? null);

        $taskKey = (string)$p['orden'] . '|' . (string)$p['tarea'];
        $latest  = $latestByTask[$taskKey] ?? null;
        if ($latest && !empty($latest['fecha_intervencion'])) {
            $latestDate  = (string)$latest['fecha_intervencion'];
            $excelUltima = (string)($p['ultima_revision'] ?? '');
            if ($latestDate >= $excelUltima) {
                $diasPer = MaintenancePeriodicidadStore::diasPorPeriodicidad($eff['periodicidad']);
                if ($diasPer !== null) {
                    $eff['ultima_revision']  = $latestDate;
                    $eff['proxima_revision'] = date('Y-m-d', strtotime($latestDate) + $diasPer * 86400);
                    $eff['proxima_recalculada'] = true;
                }
            }
        }

        $px = $eff['proxima_revision'] ?? null;
        if ($px === null) continue;

        $idPend = MaintenancePendienteStore::buildId(
            (string)$p['orden'], (string)$p['tarea'], (string)$p['proxima_revision']
        );
        $isPendiente = isset($pendientesIdx[$idPend]);

        $diff = (int)round((strtotime($px) - strtotime($hoy)) / 86400);

        $row = $eff + ['is_pendiente' => $isPendiente, 'dias_restantes' => $diff];

        if ($px === $hoy) {
            $row['estado'] = 'urgente';
            $hoyArr[] = $row;
        } elseif ($px < $hoy) {
            $row['estado'] = 'vencida';
            $vencidasArr[] = $row;
        }
        if ($isPendiente && $px !== $hoy) {
            // Las marcadas se muestran aparte (pueden ser pasadas o futuras).
            $row['estado'] = $row['estado'] ?? 'urgente';
            $marcadasArr[] = $row;
        }
    }

    // Ordenar: hoy por máquina, vencidas más antiguas primero, marcadas por fecha asc.
    usort($hoyArr,      fn($a, $b) => strcmp((string)$a['desc_maquina'], (string)$b['desc_maquina']));
    usort($vencidasArr, fn($a, $b) => strcmp((string)$a['proxima_revision'], (string)$b['proxima_revision']));
    usort($marcadasArr, fn($a, $b) => strcmp((string)$a['proxima_revision'], (string)$b['proxima_revision']));

    jsonOk([
        'hoy'       => $hoyArr,
        'vencidas'  => $vencidasArr,
        'marcadas'  => $marcadasArr,
        'fecha_hoy' => $hoy,
    ]);
} catch (Exception $e) {
    jsonError('Error: ' . $e->getMessage(), 500);
}
```

- [ ] **Step 2: Sintaxis**

```powershell
php -l api/mant_dashboard.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add api/mant_dashboard.php
git commit -m "api: mant_dashboard.php · {hoy, vencidas, marcadas} en una llamada"
```

---

## Task 6: Auth context + AuthProvider

**Files:**
- Create: `mobile-operario/src/lib/auth-context.tsx`
- Modify: `mobile-operario/src/app/layout.tsx`

- [ ] **Step 1: Crear `src/lib/auth-context.tsx`**

```tsx
'use client';

import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { apiGet, apiPost, setCsrfToken, clearCsrfToken } from './api';
import type { SessionInfo } from './types';

type AuthState = {
  user: string | null;
  role: 'tecnico' | 'operario' | null;
  loading: boolean;
};

type AuthContextValue = AuthState & {
  login: (numero: string) => Promise<void>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = useState<AuthState>({ user: null, role: null, loading: true });

  useEffect(() => {
    let cancelled = false;
    apiGet<SessionInfo>('/api/mant_session.php')
      .then(info => {
        if (cancelled) return;
        if (info.csrf_token) setCsrfToken(info.csrf_token);
        setState({ user: info.user || null, role: info.role || null, loading: false });
      })
      .catch(() => {
        if (!cancelled) setState({ user: null, role: null, loading: false });
      });
    return () => { cancelled = true; };
  }, []);

  const login = useCallback(async (numero: string) => {
    const info = await apiPost<SessionInfo>('/api/mant_login_json.php', {
      usuario: numero,
      contrasena: numero,
    });
    // Tras un login OK del backend, user y csrf_token llegan no nulos;
    // protegemos por si acaso para complacer a TS.
    if (info.csrf_token) setCsrfToken(info.csrf_token);
    setState({ user: info.user ?? null, role: info.role, loading: false });
  }, []);

  const logout = useCallback(async () => {
    try { await apiPost('/api/mant_logout_json.php', {}); } catch { /* ignore */ }
    clearCsrfToken();
    setState({ user: null, role: null, loading: false });
  }, []);

  return (
    <AuthContext.Provider value={{ ...state, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth fuera de AuthProvider');
  return ctx;
}
```

- [ ] **Step 2: Sustituir `src/app/layout.tsx`**

```tsx
import type { Metadata, Viewport } from 'next';
import './globals.css';
import { Providers } from './providers';

export const metadata: Metadata = {
  title: 'KH Mantenimiento Operario',
  description: 'Revisiones preventivas para operarios',
};

export const viewport: Viewport = {
  themeColor: '#8c181a',
  width: 'device-width',
  initialScale: 1,
  viewportFit: 'cover',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="es">
      <body>
        <div className="min-h-screen mx-auto max-w-app bg-kh-bg shadow-lg">
          <Providers>{children}</Providers>
        </div>
      </body>
    </html>
  );
}
```

- [ ] **Step 3: Crear `src/app/providers.tsx`**

```tsx
'use client';

import { QueryClientProvider } from '@tanstack/react-query';
import { queryClient } from '@/lib/queries';
import { AuthProvider } from '@/lib/auth-context';

export function Providers({ children }: { children: React.ReactNode }) {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>{children}</AuthProvider>
    </QueryClientProvider>
  );
}
```

- [ ] **Step 4: Type-check**

```powershell
cd mobile-operario; npx tsc --noEmit; cd ..
```

Expected: sin errores.

- [ ] **Step 5: Commit**

```bash
git add mobile-operario/src
git commit -m "mobile-operario: AuthProvider con hidratación desde /api/mant_session.php"
```

---

## Task 7: KeypadInput + login page

**Files:**
- Create: `mobile-operario/src/components/KeypadInput.tsx`
- Create: `mobile-operario/src/app/login/page.tsx`

- [ ] **Step 1: Crear `src/components/KeypadInput.tsx`**

```tsx
'use client';

import { Delete } from 'lucide-react';
import { cn } from '@/lib/utils';

type Props = {
  value: string;
  onChange: (value: string) => void;
  maxLength?: number;
  disabled?: boolean;
};

export function KeypadInput({ value, onChange, maxLength = 6, disabled }: Props) {
  const press = (digit: string) => {
    if (disabled) return;
    if (value.length >= maxLength) return;
    onChange(value + digit);
  };
  const back = () => { if (!disabled) onChange(value.slice(0, -1)); };
  const clear = () => { if (!disabled) onChange(''); };

  return (
    <div className="grid grid-cols-3 gap-3 w-full">
      {[1,2,3,4,5,6,7,8,9].map(d => (
        <button
          key={d}
          type="button"
          onClick={() => press(String(d))}
          disabled={disabled}
          className={cn(
            'h-16 rounded-2xl bg-white text-2xl font-bold text-kh-text',
            'shadow-kh-sm border border-kh-line',
            'active:scale-95 active:bg-kh-line transition-transform',
            'disabled:opacity-50',
          )}
        >
          {d}
        </button>
      ))}
      <button
        type="button" onClick={clear} disabled={disabled}
        className="h-16 rounded-2xl bg-kh-line text-2xl font-bold text-kh-text-soft active:scale-95"
        aria-label="Limpiar"
      >×</button>
      <button
        type="button" onClick={() => press('0')} disabled={disabled}
        className="h-16 rounded-2xl bg-white text-2xl font-bold text-kh-text border border-kh-line shadow-kh-sm active:scale-95"
      >0</button>
      <button
        type="button" onClick={back} disabled={disabled}
        className="h-16 rounded-2xl bg-kh-line text-kh-text-soft active:scale-95 grid place-items-center"
        aria-label="Borrar"
      ><Delete className="w-6 h-6" /></button>
    </div>
  );
}
```

- [ ] **Step 2: Crear `src/app/login/page.tsx`**

```tsx
'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { KeypadInput } from '@/components/KeypadInput';
import { useAuth } from '@/lib/auth-context';
import { ApiError } from '@/lib/api';

export default function LoginPage() {
  const router = useRouter();
  const { login } = useAuth();
  const [numero, setNumero] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const onSubmit = async (e?: React.FormEvent) => {
    e?.preventDefault();
    if (!numero || busy) return;
    setBusy(true); setError(null);
    try {
      await login(numero);
      router.push('/hoy');
    } catch (err) {
      const msg = err instanceof ApiError && err.status === 401
        ? 'Operario no válido. Vuelve a intentarlo.'
        : 'Error de conexión. Reintenta.';
      setError(msg);
      setNumero('');
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="min-h-screen flex flex-col items-center px-6 py-8">
      <div className="w-20 h-20 rounded-full bg-kh-red grid place-items-center mb-4 shadow-kh-md">
        <span className="text-white text-2xl font-bold">KH</span>
      </div>
      <h1 className="text-2xl font-bold text-kh-text mb-1">Identifícate</h1>
      <p className="text-sm text-kh-text-soft mb-6 text-center">
        Introduce tu número de operario para empezar el turno.
      </p>

      <form onSubmit={onSubmit} className="w-full max-w-xs">
        <input
          readOnly value={numero || ' '} aria-label="Número de operario"
          className="w-full text-center text-3xl font-bold tracking-widest bg-white rounded-xl border border-kh-line py-4 mb-4 shadow-kh-sm"
        />

        <KeypadInput value={numero} onChange={setNumero} disabled={busy} />

        {error && (
          <p className="mt-4 text-center text-sm text-kh-red font-semibold">{error}</p>
        )}

        <button
          type="submit"
          disabled={!numero || busy}
          className="mt-6 w-full h-14 rounded-2xl bg-kh-red text-white text-lg font-bold shadow-kh-md disabled:opacity-50 active:scale-[0.98]"
        >
          {busy ? 'Entrando…' : 'Entrar'}
        </button>
      </form>
    </main>
  );
}
```

- [ ] **Step 3: Probar manualmente**

Arranca el dev server (`npm run dev` en `mobile-operario/`) y XAMPP. Ve a `http://localhost:3000/login`. Pulsa un número de operario válido + Entrar. Debe redirigir a `/hoy` (404 todavía — la creamos en Task 9). Si pones uno inválido, debe mostrar "Operario no válido".

- [ ] **Step 4: Commit**

```bash
git add mobile-operario/src/components/KeypadInput.tsx mobile-operario/src/app/login
git commit -m "mobile-operario: KeypadInput + página /login con auth contra mant_login_json"
```

---

## Task 8: Root redirector según sesión

**Files:**
- Modify: `mobile-operario/src/app/page.tsx`

- [ ] **Step 1: Sustituir `src/app/page.tsx`**

```tsx
'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';

export default function HomeRedirect() {
  const router = useRouter();
  const { user, loading } = useAuth();

  useEffect(() => {
    if (loading) return;
    router.replace(user ? '/hoy' : '/login');
  }, [user, loading, router]);

  return (
    <div className="min-h-screen grid place-items-center text-kh-text-soft">
      Cargando…
    </div>
  );
}
```

- [ ] **Step 2: Commit**

```bash
git add mobile-operario/src/app/page.tsx
git commit -m "mobile-operario: root redirector según estado de sesión"
```

---

## Task 9: TopBar + TaskCard shared components

**Files:**
- Create: `mobile-operario/src/components/TopBar.tsx`
- Create: `mobile-operario/src/components/TaskCard.tsx`

- [ ] **Step 1: Crear `src/components/TopBar.tsx`**

```tsx
'use client';

import { ChevronLeft } from 'lucide-react';

type Props = {
  title: string;
  subtitle?: string;
  onBack?: () => void;
  rightSlot?: React.ReactNode;
};

export function TopBar({ title, subtitle, onBack, rightSlot }: Props) {
  return (
    <header className="sticky top-0 z-10 bg-kh-black text-white px-4 py-4 flex items-center gap-3 border-b-4 border-kh-red shadow-md">
      {onBack && (
        <button
          onClick={onBack}
          aria-label="Volver"
          className="w-9 h-9 rounded-xl bg-white/10 grid place-items-center active:scale-95"
        >
          <ChevronLeft className="w-5 h-5" />
        </button>
      )}
      <div className="flex-1 min-w-0">
        <h1 className="text-base font-bold truncate">{title}</h1>
        {subtitle && <div className="text-xs text-white/70 truncate">{subtitle}</div>}
      </div>
      {rightSlot}
    </header>
  );
}
```

- [ ] **Step 2: Crear `src/components/TaskCard.tsx`**

```tsx
'use client';

import Link from 'next/link';
import { Wrench } from 'lucide-react';
import type { Tarea } from '@/lib/types';
import { cn } from '@/lib/utils';

const ESTADO_STYLES: Record<string, string> = {
  vencida: 'bg-kh-red-bg text-kh-red border-kh-red',
  urgente: 'bg-kh-amber-bg text-kh-amber border-kh-amber',
  en_plazo: 'bg-kh-green-bg text-kh-green border-kh-green',
};

function taskSlug(t: Tarea): string {
  return encodeURIComponent(`${t.orden}__${t.tarea}__${t.proxima_revision}`);
}

export function TaskCard({ task }: { task: Tarea }) {
  const estado = task.estado ?? 'en_plazo';
  const isVenc = estado === 'vencida';
  return (
    <Link href={`/tarea/${taskSlug(task)}`} className="block">
      <article className="bg-white rounded-2xl p-4 mb-3 shadow-kh-sm border border-kh-line active:scale-[0.99]">
        <div className="flex items-start gap-3">
          <div className="w-10 h-10 rounded-xl bg-kh-red-bg grid place-items-center shrink-0">
            <Wrench className="w-5 h-5 text-kh-red" />
          </div>
          <div className="flex-1 min-w-0">
            <div className="font-bold text-kh-text truncate">{task.desc_maquina}</div>
            <div className="text-sm text-kh-text-soft truncate">{task.desc_tarea}</div>
            <div className="text-xs text-kh-text-soft mt-1">
              Periodicidad: {task.periodicidad || '—'}
            </div>
          </div>
          <span className={cn(
            'text-xs font-bold px-2 py-1 rounded-full border whitespace-nowrap',
            ESTADO_STYLES[estado] ?? ESTADO_STYLES['en_plazo'],
          )}>
            {isVenc ? `Vencida${task.dias_restantes !== undefined ? ` ${Math.abs(task.dias_restantes)}d` : ''}` : estado === 'urgente' ? 'Hoy' : 'OK'}
          </span>
        </div>
        {task.is_pendiente && (
          <div className="mt-2 text-xs text-kh-red font-semibold">★ Marcada como pendiente</div>
        )}
      </article>
    </Link>
  );
}
```

- [ ] **Step 3: Type-check**

```powershell
cd mobile-operario; npx tsc --noEmit; cd ..
```

Expected: sin errores.

- [ ] **Step 4: Commit**

```bash
git add mobile-operario/src/components/TopBar.tsx mobile-operario/src/components/TaskCard.tsx
git commit -m "mobile-operario: TopBar + TaskCard compartidos"
```

---

## Task 10: /hoy · lista de tareas del día

**Files:**
- Create: `mobile-operario/src/app/hoy/page.tsx`
- Modify: `mobile-operario/src/lib/queries.ts`

- [ ] **Step 1: Añadir hooks al final de `src/lib/queries.ts`**

```ts
import { useQuery } from '@tanstack/react-query';
import { apiGet } from './api';
import type { DashboardData } from './types';

export function useDashboard() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: () => apiGet<DashboardData & { fecha_hoy: string }>('/api/mant_dashboard.php'),
  });
}
```

(El fichero queda con `queryClient` + `useDashboard`.)

- [ ] **Step 2: Crear `src/app/hoy/page.tsx`**

```tsx
'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useMemo } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useDashboard } from '@/lib/queries';
import { TopBar } from '@/components/TopBar';
import { TaskCard } from '@/components/TaskCard';

export default function HoyPage() {
  const router = useRouter();
  const { user, loading: authLoading, logout } = useAuth();
  const { data, isLoading, error } = useDashboard();

  useEffect(() => {
    if (!authLoading && !user) router.replace('/login');
  }, [user, authLoading, router]);

  const totalPendientes = useMemo(() => {
    if (!data) return 0;
    return data.vencidas.length + data.marcadas.length;
  }, [data]);

  const fechaLarga = useMemo(() => {
    if (!data?.fecha_hoy) return '';
    const d = new Date(data.fecha_hoy + 'T00:00:00');
    return d.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' });
  }, [data?.fecha_hoy]);

  if (!user) return null;

  return (
    <main className="min-h-screen flex flex-col">
      <TopBar
        title={`Hola, Operario ${user}`}
        subtitle={fechaLarga ? `Turno del ${fechaLarga}` : undefined}
        rightSlot={
          <button
            onClick={async () => { await logout(); router.replace('/login'); }}
            className="text-xs font-semibold bg-white/10 px-3 py-2 rounded-lg active:scale-95"
          >Salir</button>
        }
      />

      <div className="flex-1 p-4 pb-28">
        <h2 className="text-sm font-bold uppercase tracking-wider text-kh-text-soft mb-3">
          Para hoy
        </h2>

        {isLoading && <div className="text-kh-text-soft text-sm">Cargando…</div>}
        {error && <div className="text-kh-red text-sm">Error cargando tareas</div>}

        {!isLoading && data && data.hoy.length === 0 && (
          <div className="bg-white rounded-2xl p-6 text-center text-kh-text-soft shadow-kh-sm">
            No hay tareas programadas para hoy. Revisa los Pendientes.
          </div>
        )}

        {data?.hoy.map(t => (
          <TaskCard key={`${t.orden}|${t.tarea}|${t.proxima_revision}`} task={t} />
        ))}
      </div>

      <div className="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-app p-4 bg-gradient-to-t from-kh-bg to-transparent">
        <button
          onClick={() => router.push('/pendientes')}
          className="w-full h-14 rounded-2xl bg-kh-red text-white font-bold shadow-kh-lg active:scale-[0.98] flex items-center justify-center gap-2"
        >
          Pendientes
          {totalPendientes > 0 && (
            <span className="bg-white text-kh-red rounded-full px-2 py-0.5 text-sm">{totalPendientes}</span>
          )}
        </button>
      </div>
    </main>
  );
}
```

- [ ] **Step 3: Verificar en navegador**

Login con un nº de operario válido. Tras login se redirige a `/hoy` → cabecera + lista de tareas de hoy (puede estar vacía si no hay) + botón "Pendientes" abajo con badge.

- [ ] **Step 4: Commit**

```bash
git add mobile-operario/src/lib/queries.ts mobile-operario/src/app/hoy
git commit -m "mobile-operario: /hoy con dashboard endpoint + redirector si no auth"
```

---

## Task 11: /pendientes · vencidas + marcadas

**Files:**
- Create: `mobile-operario/src/app/pendientes/page.tsx`

- [ ] **Step 1: Crear el archivo**

```tsx
'use client';

import { useRouter } from 'next/navigation';
import { useEffect } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useDashboard } from '@/lib/queries';
import { TopBar } from '@/components/TopBar';
import { TaskCard } from '@/components/TaskCard';

export default function PendientesPage() {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();
  const { data, isLoading, error } = useDashboard();

  useEffect(() => {
    if (!authLoading && !user) router.replace('/login');
  }, [user, authLoading, router]);

  if (!user) return null;

  return (
    <main className="min-h-screen flex flex-col">
      <TopBar
        title="Pendientes"
        subtitle="Vencidas y marcadas para revisar"
        onBack={() => router.push('/hoy')}
      />

      <div className="flex-1 p-4">
        {isLoading && <div className="text-kh-text-soft text-sm">Cargando…</div>}
        {error && <div className="text-kh-red text-sm">Error cargando pendientes</div>}

        {data && data.vencidas.length > 0 && (
          <section className="mb-6">
            <h2 className="text-sm font-bold uppercase tracking-wider text-kh-red mb-3">
              Vencidas ({data.vencidas.length})
            </h2>
            {data.vencidas.map(t => (
              <TaskCard key={`v-${t.orden}|${t.tarea}|${t.proxima_revision}`} task={t} />
            ))}
          </section>
        )}

        {data && data.marcadas.length > 0 && (
          <section>
            <h2 className="text-sm font-bold uppercase tracking-wider text-kh-amber mb-3">
              Marcadas para revisar ({data.marcadas.length})
            </h2>
            {data.marcadas.map(t => (
              <TaskCard key={`m-${t.orden}|${t.tarea}|${t.proxima_revision}`} task={t} />
            ))}
          </section>
        )}

        {data && data.vencidas.length === 0 && data.marcadas.length === 0 && (
          <div className="bg-white rounded-2xl p-6 text-center text-kh-text-soft shadow-kh-sm">
            ✓ No hay nada pendiente.
          </div>
        )}
      </div>
    </main>
  );
}
```

- [ ] **Step 2: Verificar en navegador**

Click en el botón "Pendientes" desde /hoy. Ver dos secciones (si hay datos): "Vencidas" en rojo y "Marcadas para revisar" en ámbar.

- [ ] **Step 3: Commit**

```bash
git add mobile-operario/src/app/pendientes
git commit -m "mobile-operario: /pendientes con secciones Vencidas + Marcadas"
```

---

## Task 12: useTaskTimer hook (estado + persistencia)

**Files:**
- Create: `mobile-operario/src/hooks/useTaskTimer.ts`

- [ ] **Step 1: Crear el archivo**

```ts
'use client';

import { useCallback, useEffect, useState } from 'react';

export type TaskTimerState = {
  state: 'idle' | 'running' | 'paused' | 'finished';
  startedAt: string | null;       // ISO timestamp primer Iniciar
  totalAtLastPause: number;       // segundos acumulados antes del último resume
  runningSince: string | null;    // ISO del último Iniciar/Reanudar
};

const initialState: TaskTimerState = {
  state: 'idle',
  startedAt: null,
  totalAtLastPause: 0,
  runningSince: null,
};

function storageKey(id: string) {
  return `mobile-operario:timer:${id}`;
}

function read(id: string): TaskTimerState {
  if (typeof window === 'undefined') return initialState;
  try {
    const raw = localStorage.getItem(storageKey(id));
    if (!raw) return initialState;
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed === 'object') return { ...initialState, ...parsed };
  } catch { /* ignore */ }
  return initialState;
}

function write(id: string, s: TaskTimerState) {
  if (typeof window === 'undefined') return;
  localStorage.setItem(storageKey(id), JSON.stringify(s));
}

export function getTotalSeconds(s: TaskTimerState, now: Date = new Date()): number {
  if (s.state === 'running' && s.runningSince) {
    const delta = Math.floor((now.getTime() - new Date(s.runningSince).getTime()) / 1000);
    return Math.max(0, s.totalAtLastPause + delta);
  }
  return s.totalAtLastPause;
}

export function useTaskTimer(id: string) {
  const [s, setS] = useState<TaskTimerState>(initialState);

  // Hidratar de localStorage al montar
  useEffect(() => { setS(read(id)); }, [id]);

  const update = useCallback((next: TaskTimerState) => {
    write(id, next);
    setS(next);
  }, [id]);

  const start = useCallback(() => {
    if (s.state !== 'idle') return;
    const nowIso = new Date().toISOString();
    update({ state: 'running', startedAt: nowIso, totalAtLastPause: 0, runningSince: nowIso });
  }, [s, update]);

  const pause = useCallback(() => {
    if (s.state !== 'running') return;
    const now = new Date();
    const acc = getTotalSeconds(s, now);
    update({ ...s, state: 'paused', totalAtLastPause: acc, runningSince: null });
  }, [s, update]);

  const resume = useCallback(() => {
    if (s.state !== 'paused') return;
    update({ ...s, state: 'running', runningSince: new Date().toISOString() });
  }, [s, update]);

  const finish = useCallback((): TaskTimerState & { totalSeconds: number } => {
    const now = new Date();
    const total = getTotalSeconds(s, now);
    const finished: TaskTimerState = {
      ...s, state: 'finished', totalAtLastPause: total, runningSince: null,
    };
    update(finished);
    return { ...finished, totalSeconds: total };
  }, [s, update]);

  const clear = useCallback(() => {
    if (typeof window !== 'undefined') localStorage.removeItem(storageKey(id));
    setS(initialState);
  }, [id]);

  return { state: s, start, pause, resume, finish, clear, totalSeconds: getTotalSeconds(s) };
}
```

- [ ] **Step 2: Type-check**

```powershell
cd mobile-operario; npx tsc --noEmit; cd ..
```

Expected: sin errores.

- [ ] **Step 3: Commit**

```bash
git add mobile-operario/src/hooks/useTaskTimer.ts
git commit -m "mobile-operario: useTaskTimer · start/pause/resume/finish persistido en localStorage"
```

---

## Task 13: TimerCard component (sin segundero)

**Files:**
- Create: `mobile-operario/src/components/TimerCard.tsx`

- [ ] **Step 1: Crear el archivo**

```tsx
'use client';

import { Pause, Play, Square } from 'lucide-react';
import type { TaskTimerState } from '@/hooks/useTaskTimer';
import { formatMinutos, formatHora } from '@/lib/utils';
import { cn } from '@/lib/utils';

type Props = {
  state: TaskTimerState;
  totalSeconds: number;
};

export function TimerCard({ state, totalSeconds }: Props) {
  const isRunning = state.state === 'running';
  const isPaused = state.state === 'paused';
  const isIdle = state.state === 'idle';
  const isFinished = state.state === 'finished';

  const horaInicio = state.startedAt
    ? formatHora(new Date(state.startedAt).toTimeString().slice(0, 5))
    : '—';

  const statusLabel = isIdle ? '⬛ Sin iniciar'
    : isRunning ? '▶ En curso'
    : isPaused ? '⏸ Pausado'
    : '✓ Finalizada';

  const statusColor = isIdle ? 'text-kh-text-soft'
    : isRunning ? 'text-kh-green'
    : isPaused ? 'text-kh-amber'
    : 'text-kh-green';

  return (
    <section className={cn(
      'rounded-2xl bg-white p-6 shadow-kh-md border border-kh-line my-4',
    )}>
      <div className="text-xs uppercase tracking-wider text-kh-text-soft font-bold mb-2">
        Tiempo trabajado
      </div>
      <div className="text-4xl font-bold text-kh-text mb-3 tabular-nums">
        {formatMinutos(totalSeconds)}
      </div>
      <div className="flex justify-between text-sm">
        <span className="text-kh-text-soft">Iniciado a las {horaInicio}</span>
        <span className={cn('font-semibold', statusColor)}>{statusLabel}</span>
      </div>
    </section>
  );
}

export { Pause, Play, Square };
```

- [ ] **Step 2: Commit**

```bash
git add mobile-operario/src/components/TimerCard.tsx
git commit -m "mobile-operario: TimerCard muestra total acumulado sin tick en vivo"
```

---

## Task 14: /tarea/[id] · detalle con timer + acción final

**Files:**
- Create: `mobile-operario/src/app/tarea/[id]/page.tsx`
- Modify: `mobile-operario/src/lib/queries.ts` (añadir mutation `finalizarTarea`)

- [ ] **Step 1: Añadir mutation al final de `src/lib/queries.ts`**

```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiPost } from './api';

type FinalizarPayload = {
  orden: string;
  tarea: string;
  fecha_proxima_original: string;
  operario: string;
  fecha_intervencion: string;       // hoy YYYY-MM-DD
  hora_inicio: string;              // HH:MM
  tiempo_real_segundos: number;
};

export function useFinalizarTarea() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: FinalizarPayload) =>
      apiPost<{ item: unknown }>('/api/mant_marcar_hecha.php', {
        ...payload,
        tipo: 'completada',
        marcada_por: payload.operario,
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['dashboard'] }),
  });
}
```

- [ ] **Step 2: Crear `src/app/tarea/[id]/page.tsx`**

```tsx
'use client';

import { useEffect, useMemo, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { TopBar } from '@/components/TopBar';
import { TimerCard } from '@/components/TimerCard';
import { useAuth } from '@/lib/auth-context';
import { useDashboard, useFinalizarTarea } from '@/lib/queries';
import { useTaskTimer } from '@/hooks/useTaskTimer';
import { today as todayStr, formatMinutos } from '@/lib/utils';
import type { Tarea } from '@/lib/types';
import { Pause, Play, Square } from 'lucide-react';

function decodeSlug(slug: string): { orden: string; tarea: string; fpo: string } | null {
  try {
    const decoded = decodeURIComponent(slug);
    const parts = decoded.split('__');
    if (parts.length !== 3) return null;
    return { orden: parts[0], tarea: parts[1], fpo: parts[2] };
  } catch { return null; }
}

function findTask(data: ReturnType<typeof useDashboard>['data'], orden: string, tarea: string, fpo: string): Tarea | null {
  if (!data) return null;
  const all = [...data.hoy, ...data.vencidas, ...data.marcadas];
  return all.find(t => t.orden === orden && t.tarea === tarea && t.proxima_revision === fpo) ?? null;
}

export default function TareaDetailPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const { user } = useAuth();
  const { data } = useDashboard();
  const slug = decodeSlug(params.id);
  const timerId = slug ? `${slug.orden}__${slug.tarea}__${slug.fpo}` : '__invalid__';
  const timer = useTaskTimer(timerId);
  const finalizar = useFinalizarTarea();
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!user) router.replace('/login');
  }, [user, router]);

  const tarea = useMemo(() => slug ? findTask(data, slug.orden, slug.tarea, slug.fpo) : null, [data, slug]);

  if (!slug) return <div className="p-6">Tarea no válida</div>;
  if (!user) return null;

  const onFinalizar = async () => {
    if (submitting) return;
    setSubmitting(true);
    const final = timer.finish();
    const horaInicio = final.startedAt
      ? new Date(final.startedAt).toTimeString().slice(0, 5)
      : new Date().toTimeString().slice(0, 5);
    try {
      await finalizar.mutateAsync({
        orden: slug.orden,
        tarea: slug.tarea,
        fecha_proxima_original: slug.fpo,
        operario: user,
        fecha_intervencion: todayStr(),
        hora_inicio: horaInicio,
        tiempo_real_segundos: final.totalSeconds,
      });
      // Limpia el timer del storage y va a confirmación.
      timer.clear();
      const qs = new URLSearchParams({
        maq: tarea?.desc_maquina || '',
        op: user,
        tiempo: String(final.totalSeconds),
      });
      router.replace(`/confirmacion?${qs.toString()}`);
    } catch (e) {
      setSubmitting(false);
      alert('No se pudo guardar. Reintenta.');
    }
  };

  return (
    <main className="min-h-screen flex flex-col pb-32">
      <TopBar title="Tarea en curso" subtitle={tarea?.desc_maquina ?? '—'} onBack={() => router.push('/hoy')} />

      <div className="flex-1 p-4">
        <section className="bg-white rounded-2xl p-4 shadow-kh-sm border border-kh-line">
          <div className="font-bold text-kh-text">{tarea?.desc_maquina ?? '—'}</div>
          <div className="text-sm text-kh-text-soft">{tarea?.desc_tarea ?? '—'}</div>
          <div className="text-xs text-kh-text-soft mt-2">
            Periodicidad: {tarea?.periodicidad || '—'} · Programada: {tarea?.proxima_revision || '—'}
          </div>
        </section>

        <TimerCard state={timer.state} totalSeconds={timer.totalSeconds} />

        <div className="bg-white rounded-2xl p-4 shadow-kh-sm border border-kh-line">
          <div className="flex justify-between text-sm">
            <span className="text-kh-text-soft">Operario</span>
            <span className="font-semibold">{user}</span>
          </div>
        </div>
      </div>

      <div className="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-app p-4 bg-white border-t border-kh-line">
        {timer.state.state === 'idle' && (
          <button onClick={timer.start}
            className="w-full h-14 rounded-2xl bg-kh-red text-white font-bold shadow-kh-md active:scale-[0.98] flex items-center justify-center gap-2">
            <Play className="w-5 h-5" /> Iniciar
          </button>
        )}

        {timer.state.state === 'running' && (
          <div className="grid grid-cols-2 gap-3">
            <button onClick={timer.pause}
              className="h-14 rounded-2xl bg-kh-amber-bg text-kh-amber font-bold border-2 border-kh-amber active:scale-[0.98] flex items-center justify-center gap-2">
              <Pause className="w-5 h-5" /> Pausar
            </button>
            <button onClick={onFinalizar} disabled={submitting}
              className="h-14 rounded-2xl bg-kh-green text-white font-bold active:scale-[0.98] flex items-center justify-center gap-2 disabled:opacity-50">
              <Square className="w-5 h-5" /> Finalizar
            </button>
          </div>
        )}

        {timer.state.state === 'paused' && (
          <div className="grid grid-cols-2 gap-3">
            <button onClick={timer.resume}
              className="h-14 rounded-2xl bg-kh-red text-white font-bold active:scale-[0.98] flex items-center justify-center gap-2">
              <Play className="w-5 h-5" /> Reanudar
            </button>
            <button onClick={onFinalizar} disabled={submitting}
              className="h-14 rounded-2xl bg-kh-green text-white font-bold active:scale-[0.98] flex items-center justify-center gap-2 disabled:opacity-50">
              <Square className="w-5 h-5" /> Finalizar
            </button>
          </div>
        )}
      </div>
    </main>
  );
}
```

- [ ] **Step 3: Type-check**

```powershell
cd mobile-operario; npx tsc --noEmit; cd ..
```

Expected: sin errores.

- [ ] **Step 4: Commit**

```bash
git add mobile-operario/src/app/tarea mobile-operario/src/lib/queries.ts
git commit -m "mobile-operario: /tarea/[id] con flujo start/pause/resume/finish"
```

---

## Task 15: /confirmacion · resumen tras finalizar

**Files:**
- Create: `mobile-operario/src/app/confirmacion/page.tsx`

- [ ] **Step 1: Crear el archivo**

```tsx
'use client';

import { useEffect } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { CheckCircle2 } from 'lucide-react';
import { formatMinutos, today } from '@/lib/utils';

export default function ConfirmacionPage() {
  const router = useRouter();
  const params = useSearchParams();
  const maq = params.get('maq') ?? '';
  const op = params.get('op') ?? '';
  const tiempo = parseInt(params.get('tiempo') ?? '0', 10);

  useEffect(() => {
    const t = setTimeout(() => router.replace('/hoy'), 5000);
    return () => clearTimeout(t);
  }, [router]);

  return (
    <main className="min-h-screen flex flex-col items-center justify-center p-6 text-center">
      <CheckCircle2 className="w-20 h-20 text-kh-green mb-4" />
      <h1 className="text-2xl font-bold text-kh-text mb-1">Revisión registrada</h1>
      <p className="text-kh-text-soft mb-6">Se ha guardado correctamente en el histórico.</p>

      <section className="w-full max-w-xs bg-white rounded-2xl p-4 shadow-kh-sm border border-kh-line text-left">
        <Row label="Máquina" value={maq || '—'} />
        <Row label="Operario" value={op || '—'} />
        <Row label="Fecha" value={today()} />
        <Row label="Tiempo trabajado" value={formatMinutos(tiempo)} />
      </section>

      <button onClick={() => router.replace('/hoy')}
        className="mt-6 w-full max-w-xs h-12 rounded-2xl bg-kh-red text-white font-bold shadow-kh-md active:scale-[0.98]">
        Volver a la lista
      </button>
    </main>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between py-2 border-b last:border-0 border-kh-line">
      <span className="text-sm text-kh-text-soft">{label}</span>
      <span className="text-sm font-semibold text-kh-text">{value}</span>
    </div>
  );
}
```

- [ ] **Step 2: Commit**

```bash
git add mobile-operario/src/app/confirmacion
git commit -m "mobile-operario: /confirmacion con resumen + auto-redirect a /hoy"
```

---

## Task 16: PWA · manifest + iconos + plugin

**Files:**
- Create: `mobile-operario/public/manifest.json`
- Create: `mobile-operario/public/icon-192.png` (placeholder)
- Create: `mobile-operario/public/icon-512.png` (placeholder)
- Create: `mobile-operario/public/apple-touch-icon.png` (placeholder)
- Modify: `mobile-operario/next.config.js`
- Modify: `mobile-operario/src/app/layout.tsx` (manifest link)

- [ ] **Step 1: Instalar el plugin**

```powershell
cd mobile-operario
npm install @ducanh2912/next-pwa
cd ..
```

- [ ] **Step 2: Crear `mobile-operario/public/manifest.json`**

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
    { "src": "/icon-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any maskable" },
    { "src": "/icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any maskable" }
  ]
}
```

- [ ] **Step 3: Generar iconos placeholder con PowerShell + un PNG simple**

Crea un PNG rojo KH 512×512 + uno 192×192 + apple-touch 180×180. Si no hay GD instalado, copia un fichero PNG cualquiera con el tamaño correcto y se sustituirá más adelante con uno real diseñado. Para empezar:

```powershell
cd mobile-operario\public
# placeholder: usa un PNG cualquiera de iconos del proyecto, o crea uno con PowerShell:
Add-Type -AssemblyName System.Drawing
$bmp = New-Object System.Drawing.Bitmap 512, 512
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.Clear([System.Drawing.ColorTranslator]::FromHtml('#8c181a'))
$font = New-Object System.Drawing.Font 'Arial', 200, ([System.Drawing.FontStyle]::Bold)
$g.DrawString('KH', $font, [System.Drawing.Brushes]::White, 60, 130)
$bmp.Save("$PWD\icon-512.png", [System.Drawing.Imaging.ImageFormat]::Png)
$bmp192 = New-Object System.Drawing.Bitmap 192, 192
$g192 = [System.Drawing.Graphics]::FromImage($bmp192)
$g192.Clear([System.Drawing.ColorTranslator]::FromHtml('#8c181a'))
$f192 = New-Object System.Drawing.Font 'Arial', 78, ([System.Drawing.FontStyle]::Bold)
$g192.DrawString('KH', $f192, [System.Drawing.Brushes]::White, 22, 50)
$bmp192.Save("$PWD\icon-192.png", [System.Drawing.Imaging.ImageFormat]::Png)
$bmp180 = New-Object System.Drawing.Bitmap 180, 180
$g180 = [System.Drawing.Graphics]::FromImage($bmp180)
$g180.Clear([System.Drawing.ColorTranslator]::FromHtml('#8c181a'))
$f180 = New-Object System.Drawing.Font 'Arial', 72, ([System.Drawing.FontStyle]::Bold)
$g180.DrawString('KH', $f180, [System.Drawing.Brushes]::White, 22, 46)
$bmp180.Save("$PWD\apple-touch-icon.png", [System.Drawing.Imaging.ImageFormat]::Png)
$bmp.Dispose(); $bmp192.Dispose(); $bmp180.Dispose(); $g.Dispose(); $g192.Dispose(); $g180.Dispose()
cd ..\..
```

Expected: tres ficheros PNG creados en `public/`.

- [ ] **Step 4: Sustituir `next.config.js` para envolver con next-pwa**

```js
const withPWA = require('@ducanh2912/next-pwa').default({
  dest: 'public',
  disable: process.env.NODE_ENV === 'development',
  cacheOnFrontEndNav: false,
  workboxOptions: {
    runtimeCaching: [
      {
        urlPattern: /^https?.*\/_next\/static\//,
        handler: 'CacheFirst',
        options: { cacheName: 'next-static', expiration: { maxEntries: 60 } },
      },
    ],
  },
});

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: 'http://localhost/PLAN_ATTAINMENT/api/:path*',
      },
    ];
  },
};

module.exports = withPWA(nextConfig);
```

- [ ] **Step 5: Añadir meta tags en `src/app/layout.tsx`**

Sustituye `metadata` y añade los apple tags. Reemplaza el bloque actual de `metadata` por:

```tsx
export const metadata: Metadata = {
  title: 'KH Mantenimiento Operario',
  description: 'Revisiones preventivas para operarios',
  manifest: '/manifest.json',
  appleWebApp: {
    capable: true,
    statusBarStyle: 'black-translucent',
    title: 'KH Operario',
  },
  icons: {
    apple: '/apple-touch-icon.png',
  },
};
```

- [ ] **Step 6: Build de prueba**

```powershell
cd mobile-operario
npm run build
cd ..
```

Expected: build OK, `public/sw.js` generado.

- [ ] **Step 7: Commit**

```bash
git add mobile-operario
git commit -m "mobile-operario: PWA básica (manifest + iconos + next-pwa minimal SW)"
```

---

## Task 17: README + checklist de smoke test

**Files:**
- Create: `mobile-operario/README.md`

- [ ] **Step 1: Crear el README**

```markdown
# KH Mantenimiento · Operario (mobile)

App móvil Next.js para que los operarios de mantenimiento registren sus revisiones preventivas.

## Stack

- Next.js 14 (App Router) + TypeScript
- Tailwind CSS
- TanStack Query (React Query) v5
- PWA básica (manifest + iconos)

## Dev

Backend PHP (XAMPP) debe estar arrancado en `http://localhost/PLAN_ATTAINMENT/`.

```powershell
cd mobile-operario
npm install         # solo primera vez
npm run dev
```

Abre `http://localhost:3000/`.

Login: número de operario válido (ej. uno activo en `mant_operarios`). Mismo número como usuario y contraseña.

Las llamadas a `/api/*` se proxyean a `http://localhost/PLAN_ATTAINMENT/api/*` vía `next.config.js`. La sesión PHP cookie funciona transparente al ser mismo origen.

## Build

```powershell
npm run build
npm start    # servidor de producción local
```

## Deploy al subdominio

Cuando esté disponible el subdominio:

1. `npm run build` genera `.next/` con assets optimizados.
2. Copia `.next/`, `public/`, `package.json`, `node_modules/` (o `npm ci --production` en el servidor) al host del subdominio.
3. Arranca con `npm start` o usa un proceso como `pm2 start npm -- start`.
4. Configura el front (Apache/nginx) del subdominio para hacer `proxy_pass /api/* → http://backend.principal/PLAN_ATTAINMENT/api/*`. Mismo origen al navegador, cookies funcionan.

## Smoke test (manual)

- [ ] Login con un nº de operario válido → redirige a `/hoy`.
- [ ] Login con un nº incorrecto → muestra error.
- [ ] `/hoy` muestra tareas programadas para hoy.
- [ ] Botón "Pendientes" lleva a `/pendientes` con vencidas + marcadas.
- [ ] Click en una tarea → detalle.
- [ ] Iniciar → cambia a "▶ En curso" (sin tick en vivo).
- [ ] Pausar → estado pasa a "⏸ Pausado", tiempo total se congela.
- [ ] Reanudar → vuelve a "▶ En curso".
- [ ] Finalizar → POST `mant_marcar_hecha.php`, redirige a `/confirmacion`.
- [ ] Confirmación muestra resumen y auto-redirige a `/hoy` en 5s.
- [ ] Cerrar pestaña a mitad de tarea + reabrir → estado se restaura desde `localStorage`.
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

CSRF: el login devuelve `csrf_token`, el wrapper `apiPost` lo envía en `X-CSRF-Token`.
```

- [ ] **Step 2: Commit**

```bash
git add mobile-operario/README.md
git commit -m "mobile-operario: README con dev/build/deploy + checklist smoke"
```

---

## Verification matrix

| Pantalla | Estado base | Acción | Resultado esperado |
|---|---|---|---|
| `/login` | sin sesión | nº válido + Entrar | redirect `/hoy`, cookie sesión, csrf en sessionStorage |
| `/login` | sin sesión | nº inválido | mensaje "Operario no válido" |
| `/hoy` | sesión activa | carga | tareas con `proxima_revision = today` + botón Pendientes |
| `/pendientes` | sesión activa | carga | secciones Vencidas + Marcadas |
| `/tarea/[id]` | idle | Iniciar | estado running, `startedAt` set, NO tick visible |
| `/tarea/[id]` | running | Pausar | estado paused, total congelado |
| `/tarea/[id]` | paused | Reanudar | estado running |
| `/tarea/[id]` | running/paused | Finalizar | POST OK, `localStorage` limpio, redirect `/confirmacion` |
| `/confirmacion` | params en URL | tras 5s | redirect `/hoy` |
| `/tarea/[id]` | running, reload | volver | estado restaurado de localStorage |

## Notas para el ejecutor

- **No hay test runner** en el repo. Verifica con `npx tsc --noEmit`, `npm run build` y prueba manual en `http://localhost:3000`.
- **XAMPP debe estar corriendo** (Apache en 8080 con vhost a 80, o desde HTTPS 443) — el rewrite apunta a `http://localhost/PLAN_ATTAINMENT/api/*`. Si tu Apache escucha en `localhost:8080`, cambia el destino del rewrite a `http://localhost:8080/PLAN_ATTAINMENT/api/:path*`.
- **No tocar nada del PHP existente** salvo crear los 4 endpoints nuevos del Task 4 y Task 5.
- **Iconos**: los PNG generados con PowerShell son placeholders. Pídele al usuario el icono final más adelante o cuando quiera diseño definitivo.
- Todos los commits añaden solo ficheros del `mobile-operario/` o de `api/` para los 4 endpoints nuevos. Nada más se toca.
