'use client';

import { useEffect, useState } from 'react';

export type Theme = 'light' | 'dark';
const KEY = 'mobile-operario:theme';

/** Lee el tema actual ya aplicado en <html data-theme> (lo fija el script
 *  anti-flash del layout antes del primer paint). */
function currentTheme(): Theme {
  if (typeof document !== 'undefined') {
    const t = document.documentElement.dataset.theme;
    if (t === 'dark' || t === 'light') return t;
  }
  return 'light';
}

export function useTheme() {
  const [theme, setTheme] = useState<Theme>('light');

  useEffect(() => { setTheme(currentTheme()); }, []);

  const apply = (t: Theme) => {
    setTheme(t);
    if (typeof document !== 'undefined') document.documentElement.dataset.theme = t;
    try { localStorage.setItem(KEY, t); } catch { /* ignore */ }
  };

  const toggle = () => apply(theme === 'dark' ? 'light' : 'dark');

  return { theme, toggle, setTheme: apply };
}
