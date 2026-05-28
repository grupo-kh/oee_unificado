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
            className="text-sm font-bold bg-white/15 px-3 h-10 rounded-lg active:scale-95"
          >Salir</button>
        }
      />

      <div className="flex-1 p-4 pb-28">
        <div className="flex items-baseline justify-between mb-3">
          <h2 className="text-sm font-bold uppercase tracking-widest text-kh-text-soft">Para hoy</h2>
          {data && <span className="text-sm font-bold text-kh-text">{data.hoy.length}</span>}
        </div>

        {isLoading && <div className="text-kh-text-soft">Cargando…</div>}
        {error && <div className="text-kh-danger font-semibold">Error cargando tareas</div>}

        {!isLoading && data && data.hoy.length === 0 && (
          <div className="bg-kh-card rounded-lg p-6 text-center text-kh-text-soft border border-kh-line">
            No hay tareas programadas para hoy. Revisa los Pendientes.
          </div>
        )}

        {data?.hoy.map(t => (
          <TaskCard key={`${t.orden}|${t.tarea}|${t.proxima_revision}`} task={t} />
        ))}
      </div>

      <div className="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-app p-4 bg-gradient-to-t from-kh-bg via-kh-bg to-transparent">
        <button
          onClick={() => router.push('/pendientes')}
          className="w-full h-16 rounded-lg bg-kh-red text-kh-on-red text-lg font-bold active:scale-[0.98] flex items-center justify-center gap-2 border-b-4 border-kh-red-dark"
        >
          Pendientes
          {totalPendientes > 0 && (
            <span className="bg-white text-kh-red rounded-md px-2 py-0.5 text-base font-extrabold">{totalPendientes}</span>
          )}
        </button>
      </div>
    </main>
  );
}
