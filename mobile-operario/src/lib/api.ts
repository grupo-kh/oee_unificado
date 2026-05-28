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
  let body: unknown = null;
  try { body = await resp.json(); } catch { /* sin JSON */ }
  const b = body as { ok?: boolean; error?: string; data?: T } | null;
  if (!resp.ok || (b && b.ok === false)) {
    const msg = b?.error || `HTTP ${resp.status}`;
    throw new ApiError(resp.status, msg);
  }
  return (b?.data ?? (b as unknown)) as T;
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
