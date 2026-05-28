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
