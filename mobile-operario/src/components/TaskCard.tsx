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
