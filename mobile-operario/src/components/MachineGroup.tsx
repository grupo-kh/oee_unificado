'use client';

import { useState } from 'react';
import Link from 'next/link';
import { ChevronDown, ChevronRight, Wrench } from 'lucide-react';
import type { Tarea } from '@/lib/types';
import { StatusBadge, STRIPE_VAR, type Estado } from './StatusBadge';
import { cn } from '@/lib/utils';

function slug(t: Tarea): string {
  return encodeURIComponent(`${t.orden}__${t.tarea}__${t.proxima_revision}`);
}

const RANK: Record<Estado, number> = { vencida: 3, urgente: 2, en_plazo: 1 };

function worstEstado(list: Tarea[]): Estado {
  let e: Estado = 'en_plazo';
  for (const t of list) {
    const st = (t.estado ?? 'en_plazo') as Estado;
    if (RANK[st] > RANK[e]) e = st;
  }
  return e;
}

/** Lista de máquinas; cada una despliega sus tareas al pulsar. */
export function MachineGroupList({ tareas }: { tareas: Tarea[] }) {
  const groups = new Map<string, Tarea[]>();
  for (const t of tareas) {
    const k = t.desc_maquina || t.cod_maquina_mant || '—';
    if (!groups.has(k)) groups.set(k, []);
    groups.get(k)!.push(t);
  }

  const entries = Array.from(groups.entries()).map(([maquina, list]) => ({
    maquina,
    list,
    worst: list.reduce((acc: number, t: Tarea) => Math.max(acc, RANK[(t.estado ?? 'en_plazo') as Estado]), 0),
  }));
  // Peor estado primero, luego más tareas, luego alfabético.
  entries.sort((a, b) => b.worst - a.worst || b.list.length - a.list.length || a.maquina.localeCompare(b.maquina));

  const single = entries.length === 1;
  return (
    <div>
      {entries.map(e => (
        // La key incluye `single` para que, al filtrar a una sola máquina, el
        // grupo se remonte y respete defaultOpen (auto-desplegado).
        <MachineGroup key={`${e.maquina}|${single}`} maquina={e.maquina} list={e.list} defaultOpen={single} />
      ))}
    </div>
  );
}

function MachineGroup({ maquina, list, defaultOpen = false }: { maquina: string; list: Tarea[]; defaultOpen?: boolean }) {
  const [open, setOpen] = useState(defaultOpen);
  const estado = worstEstado(list);

  return (
    <div
      className="mb-3 bg-kh-card rounded-lg border border-kh-line overflow-hidden"
      style={{ borderLeft: `6px solid ${STRIPE_VAR[estado]}` }}
    >
      <button
        onClick={() => setOpen(o => !o)}
        aria-expanded={open}
        className="w-full flex items-center gap-3 p-4 min-h-[72px] active:bg-kh-card-2"
      >
        <div className="w-11 h-11 rounded-lg bg-kh-card-2 border border-kh-line grid place-items-center shrink-0">
          <Wrench className="w-6 h-6 text-kh-red" strokeWidth={2.2} />
        </div>
        <span className="flex-1 text-left font-bold text-[17px] text-kh-text truncate">{maquina}</span>
        <span className="bg-kh-red text-white rounded-md px-2.5 py-1 text-base font-extrabold tabular-nums shrink-0 min-w-[2rem] text-center">
          {list.length}
        </span>
        <ChevronDown className={cn('w-6 h-6 text-kh-text-soft shrink-0 transition-transform duration-200', open && 'rotate-180')} />
      </button>

      {open && (
        <ul className="border-t border-kh-line">
          {list.map(t => {
            const est = (t.estado ?? 'en_plazo') as Estado;
            return (
              <li key={`${t.orden}|${t.tarea}|${t.proxima_revision}`}>
                <Link
                  href={`/tarea/${slug(t)}`}
                  className="flex items-center gap-3 px-4 py-3 border-b border-kh-line last:border-0 active:bg-kh-card-2"
                  style={{ borderLeft: `4px solid ${STRIPE_VAR[est]}` }}
                >
                  <div className="flex-1 min-w-0">
                    <div className="text-[15px] text-kh-text leading-snug line-clamp-2">{t.desc_tarea}</div>
                    <div className="mt-1.5 flex items-center gap-2 flex-wrap">
                      <StatusBadge estado={est} dias={t.dias_restantes} />
                      <span className="text-xs text-kh-text-soft uppercase tracking-wide">{t.periodicidad || '—'}</span>
                    </div>
                  </div>
                  <ChevronRight className="w-5 h-5 text-kh-text-soft shrink-0" />
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
