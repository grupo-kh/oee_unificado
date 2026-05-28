'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useMemo } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useDashboard } from '@/lib/queries';
import { TopBar } from '@/components/TopBar';
import { MachineGroupList } from '@/components/MachineGroup';
import type { Tarea } from '@/lib/types';

export default function PendientesPage() {
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();
  const { data, isLoading, error } = useDashboard();

  useEffect(() => {
    if (!authLoading && !user) router.replace('/login');
  }, [user, authLoading, router]);

  // Unificamos vencidas + marcadas, deduplicando por (orden|tarea|fpo).
  // Las vencidas mandan en el estado para el coloreado.
  const tareas = useMemo<Tarea[]>(() => {
    if (!data) return [];
    const seen = new Map<string, Tarea>();
    for (const t of [...data.vencidas, ...data.marcadas]) {
      const k = `${t.orden}|${t.tarea}|${t.proxima_revision}`;
      if (!seen.has(k)) seen.set(k, t);
    }
    return Array.from(seen.values());
  }, [data]);

  if (!user) return null;

  return (
    <main className="min-h-screen flex flex-col">
      <TopBar
        title="Pendientes"
        subtitle="Por máquina · pulsa para desplegar"
        onBack={() => router.push('/hoy')}
      />

      <div className="flex-1 p-4">
        <div className="flex items-baseline justify-between mb-3">
          <h2 className="text-sm font-bold uppercase tracking-widest text-kh-text-soft">Máquinas con pendientes</h2>
          <span className="text-sm font-bold text-kh-text">{tareas.length}</span>
        </div>

        {isLoading && <div className="flex items-center gap-3 text-kh-text-soft py-6"><span className="kh-spinner" /> Cargando…</div>}
        {error && <div className="text-kh-danger font-semibold py-4">Error cargando pendientes</div>}

        {!isLoading && tareas.length > 0 && <MachineGroupList tareas={tareas} />}

        {!isLoading && data && tareas.length === 0 && (
          <div className="bg-kh-card rounded-lg p-6 text-center text-kh-text-soft border border-kh-line">
            ✓ No hay nada pendiente.
          </div>
        )}
      </div>
    </main>
  );
}
