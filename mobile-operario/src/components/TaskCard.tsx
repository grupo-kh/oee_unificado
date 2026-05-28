'use client';

import Link from 'next/link';
import { Wrench, ChevronRight, Star } from 'lucide-react';
import type { Tarea } from '@/lib/types';
import { StatusBadge, STRIPE_VAR, type Estado } from './StatusBadge';

function taskSlug(t: Tarea): string {
  return encodeURIComponent(`${t.orden}__${t.tarea}__${t.proxima_revision}`);
}

export function TaskCard({ task }: { task: Tarea }) {
  const estado = (task.estado ?? 'en_plazo') as Estado;
  return (
    <Link href={`/tarea/${taskSlug(task)}`} className="block mb-3 active:scale-[0.99] transition-transform">
      <article
        className="relative flex items-stretch bg-kh-card rounded-lg border border-kh-line overflow-hidden min-h-[84px]"
      >
        {/* Franja de estado a la izquierda (glanceable) */}
        <span className="w-1.5 shrink-0" style={{ background: STRIPE_VAR[estado] }} aria-hidden />

        <div className="flex items-center gap-3 flex-1 min-w-0 p-4">
          <div className="w-11 h-11 rounded-lg bg-kh-card-2 border border-kh-line grid place-items-center shrink-0">
            <Wrench className="w-6 h-6 text-kh-red" strokeWidth={2.2} />
          </div>

          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 mb-1">
              <span className="font-bold text-kh-text text-[17px] leading-tight truncate">{task.desc_maquina}</span>
              {task.is_pendiente && <Star className="w-4 h-4 text-kh-amber shrink-0" fill="currentColor" aria-label="Marcada" />}
            </div>
            <div className="text-[15px] text-kh-text-soft leading-snug line-clamp-2">{task.desc_tarea}</div>
            <div className="mt-2 flex items-center gap-2">
              <StatusBadge estado={estado} dias={task.dias_restantes} />
              <span className="text-xs text-kh-text-soft uppercase tracking-wide">{task.periodicidad || '—'}</span>
            </div>
          </div>

          <ChevronRight className="w-6 h-6 text-kh-text-soft shrink-0" />
        </div>
      </article>
    </Link>
  );
}
