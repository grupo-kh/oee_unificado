'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useMemo, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { useAuth } from '@/lib/auth-context';
import { useDashboard } from '@/lib/queries';
import { TopBar } from '@/components/TopBar';
import { MachineGroupList } from '@/components/MachineGroup';

function maqKey(t: { desc_maquina?: string; cod_maquina_mant?: string }): string {
  return t.desc_maquina || t.cod_maquina_mant || '—';
}

export default function HoyPage() {
  const router = useRouter();
  const { user, loading: authLoading, logout } = useAuth();
  const { data, isLoading, error } = useDashboard();
  const [maq, setMaq] = useState('');

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

  // Máquinas distintas de hoy + su contador, para el desplegable.
  const maquinas = useMemo(() => {
    if (!data) return [] as { nombre: string; count: number }[];
    const m = new Map<string, number>();
    for (const t of data.hoy) {
      const k = maqKey(t);
      m.set(k, (m.get(k) ?? 0) + 1);
    }
    return Array.from(m.entries())
      .map(([nombre, count]) => ({ nombre, count }))
      .sort((a, b) => a.nombre.localeCompare(b.nombre));
  }, [data]);

  // Tareas filtradas por la máquina seleccionada (o todas).
  const tareas = useMemo(() => {
    if (!data) return [];
    return maq ? data.hoy.filter(t => maqKey(t) === maq) : data.hoy;
  }, [data, maq]);

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

      {/* Selector de máquina (filtro rápido) */}
      <div className="px-4 pt-4">
        <label htmlFor="maq-select" className="block text-xs font-bold uppercase tracking-widest text-kh-text-soft mb-2">
          Máquina
        </label>
        <div className="relative">
          <select
            id="maq-select"
            value={maq}
            onChange={e => setMaq(e.target.value)}
            disabled={!data || maquinas.length === 0}
            className="w-full h-14 rounded-lg border-2 border-kh-line bg-kh-card text-kh-text px-4 pr-12 text-base font-bold appearance-none disabled:opacity-50"
          >
            <option value="">Todas las máquinas{data ? ` (${data.hoy.length})` : ''}</option>
            {maquinas.map(m => (
              <option key={m.nombre} value={m.nombre}>{m.nombre} ({m.count})</option>
            ))}
          </select>
          <ChevronDown className="w-6 h-6 text-kh-text-soft absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" />
        </div>
      </div>

      <div className="flex-1 p-4 pb-28">
        <div className="flex items-baseline justify-between mb-3">
          <h2 className="text-sm font-bold uppercase tracking-widest text-kh-text-soft">
            {maq ? 'Tareas de la máquina' : 'Para hoy · por máquina'}
          </h2>
          <span className="text-sm font-bold text-kh-text">{tareas.length}</span>
        </div>

        {isLoading && <div className="flex items-center gap-3 text-kh-text-soft py-6"><span className="kh-spinner" /> Cargando…</div>}
        {error && <div className="text-kh-danger font-semibold py-4">Error cargando tareas</div>}

        {!isLoading && data && tareas.length === 0 && (
          <div className="bg-kh-card rounded-lg p-6 text-center text-kh-text-soft border border-kh-line">
            No hay tareas programadas para hoy. Revisa los Pendientes.
          </div>
        )}

        {tareas.length > 0 && <MachineGroupList tareas={tareas} />}
      </div>

      <div className="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-app px-4 pt-4 safe-bottom bg-gradient-to-t from-kh-bg via-kh-bg to-transparent">
        <button
          onClick={() => router.push('/pendientes')}
          className="w-full h-16 rounded-lg bg-kh-red text-white text-lg font-bold active:scale-[0.98] flex items-center justify-center gap-2 border-b-4 border-kh-red-dark"
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
