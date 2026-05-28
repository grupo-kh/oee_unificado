'use client';

import { AlertTriangle, Clock3, CircleCheck } from 'lucide-react';
import { cn } from '@/lib/utils';

export type Estado = 'vencida' | 'urgente' | 'en_plazo';

// Triple-codificado: color + icono + etiqueta en mayúscula. Robusto ante
// daltonismo, glare y guantes (no se depende solo del color).
const MAP: Record<Estado, { label: string; Icon: typeof AlertTriangle; cls: string }> = {
  vencida:  { label: 'VENCIDA',  Icon: AlertTriangle, cls: 'bg-kh-danger-bg text-kh-danger border-kh-danger' },
  urgente:  { label: 'HOY',      Icon: Clock3,        cls: 'bg-kh-amber-bg text-kh-amber border-kh-amber' },
  en_plazo: { label: 'EN PLAZO', Icon: CircleCheck,   cls: 'bg-kh-green-bg text-kh-green border-kh-green' },
};

export function StatusBadge({ estado, dias }: { estado: Estado; dias?: number }) {
  const m = MAP[estado] ?? MAP.en_plazo;
  const suffix = estado === 'vencida' && dias !== undefined ? ` ${Math.abs(dias)}d` : '';
  return (
    <span className={cn(
      'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border-2 font-bold text-xs uppercase tracking-wide whitespace-nowrap',
      m.cls,
    )}>
      <m.Icon className="w-4 h-4" strokeWidth={2.5} />
      {m.label}{suffix}
    </span>
  );
}

/** Color sólido de la franja lateral por estado (CSS var directa). */
export const STRIPE_VAR: Record<Estado, string> = {
  vencida:  'var(--kh-danger)',
  urgente:  'var(--kh-amber)',
  en_plazo: 'var(--kh-green)',
};
