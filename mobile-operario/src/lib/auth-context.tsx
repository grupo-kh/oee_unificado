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
        // El backend puede devolver user como number (JSON_NUMERIC_CHECK).
        setState({ user: info.user != null ? String(info.user) : null, role: info.role || null, loading: false });
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
