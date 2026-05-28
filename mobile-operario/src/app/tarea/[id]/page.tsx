'use client';

import { useEffect, useMemo, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { Pause, Play, Square } from 'lucide-react';
import { TopBar } from '@/components/TopBar';
import { TimerCard } from '@/components/TimerCard';
import { useAuth } from '@/lib/auth-context';
import { useDashboard, useFinalizarTarea } from '@/lib/queries';
import { useTaskTimer } from '@/hooks/useTaskTimer';
import { today as todayStr } from '@/lib/utils';
import type { DashboardData, Tarea } from '@/lib/types';

function decodeSlug(slug: string): { orden: string; tarea: string; fpo: string } | null {
  try {
    const decoded = decodeURIComponent(slug);
    const parts = decoded.split('__');
    if (parts.length !== 3) return null;
    return { orden: parts[0], tarea: parts[1], fpo: parts[2] };
  } catch { return null; }
}

function findTask(data: DashboardData | undefined, orden: string, tarea: string, fpo: string): Tarea | null {
  if (!data) return null;
  const all = [...data.hoy, ...data.vencidas, ...data.marcadas];
  // OJO: el backend usa JSON_NUMERIC_CHECK, así que `tarea` (y a veces otros
  // campos) pueden llegar como number en el JSON. Comparamos con String()
  // para no fallar el match contra los valores string del slug de la URL.
  return all.find(t =>
    String(t.orden) === orden &&
    String(t.tarea) === tarea &&
    String(t.proxima_revision) === fpo
  ) ?? null;
}

export default function TareaDetailPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const { user, loading: authLoading } = useAuth();
  const { data } = useDashboard();
  const slug = decodeSlug(params.id);
  const timerId = slug ? `${slug.orden}__${slug.tarea}__${slug.fpo}` : '__invalid__';
  const timer = useTaskTimer(timerId);
  const finalizar = useFinalizarTarea();
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    // Solo redirigir cuando la sesión ya se resolvió; si no, un usuario
    // logado que abre /tarea/… directamente (deep link/refresh) sería
    // expulsado al /login antes de hidratar la sesión.
    if (!authLoading && !user) router.replace('/login');
  }, [user, authLoading, router]);

  const tarea = useMemo(
    () => slug ? findTask(data, slug.orden, slug.tarea, slug.fpo) : null,
    [data, slug],
  );

  if (!slug) return <div className="p-6">Tarea no válida</div>;
  if (!user) return null;

  const onFinalizar = async () => {
    if (submitting) return;
    setSubmitting(true);
    const final = timer.finish();
    const horaInicio = final.startedAt
      ? new Date(final.startedAt).toTimeString().slice(0, 5)
      : new Date().toTimeString().slice(0, 5);
    try {
      await finalizar.mutateAsync({
        orden: slug.orden,
        tarea: slug.tarea,
        fecha_proxima_original: slug.fpo,
        operario: user,
        fecha_intervencion: todayStr(),
        hora_inicio: horaInicio,
        tiempo_real_segundos: final.totalSeconds,
      });
      timer.clear();
      const qs = new URLSearchParams({
        maq: tarea?.desc_maquina || '',
        op: user,
        tiempo: String(final.totalSeconds),
      });
      router.replace(`/confirmacion?${qs.toString()}`);
    } catch {
      setSubmitting(false);
      alert('No se pudo guardar. Reintenta.');
    }
  };

  return (
    <main className="min-h-screen flex flex-col pb-32">
      <TopBar title="Tarea en curso" subtitle={tarea?.desc_maquina ?? '—'} onBack={() => router.push('/hoy')} />

      <div className="flex-1 p-4">
        <section className="bg-white rounded-2xl p-4 shadow-kh-sm border border-kh-line">
          <div className="font-bold text-kh-text">{tarea?.desc_maquina ?? '—'}</div>
          <div className="text-sm text-kh-text-soft">{tarea?.desc_tarea ?? '—'}</div>
          <div className="text-xs text-kh-text-soft mt-2">
            Periodicidad: {tarea?.periodicidad || '—'} · Programada: {tarea?.proxima_revision || '—'}
          </div>
        </section>

        <TimerCard state={timer.state} totalSeconds={timer.totalSeconds} />

        <div className="bg-white rounded-2xl p-4 shadow-kh-sm border border-kh-line">
          <div className="flex justify-between text-sm">
            <span className="text-kh-text-soft">Operario</span>
            <span className="font-semibold">{user}</span>
          </div>
        </div>
      </div>

      <div className="fixed bottom-0 left-1/2 -translate-x-1/2 w-full max-w-app p-4 bg-white border-t border-kh-line">
        {timer.state.state === 'idle' && (
          <button onClick={timer.start}
            className="w-full h-14 rounded-2xl bg-kh-red text-white font-bold shadow-kh-md active:scale-[0.98] flex items-center justify-center gap-2">
            <Play className="w-5 h-5" /> Iniciar
          </button>
        )}

        {timer.state.state === 'running' && (
          <div className="grid grid-cols-2 gap-3">
            <button onClick={timer.pause}
              className="h-14 rounded-2xl bg-kh-amber-bg text-kh-amber font-bold border-2 border-kh-amber active:scale-[0.98] flex items-center justify-center gap-2">
              <Pause className="w-5 h-5" /> Pausar
            </button>
            <button onClick={onFinalizar} disabled={submitting}
              className="h-14 rounded-2xl bg-kh-green text-white font-bold active:scale-[0.98] flex items-center justify-center gap-2 disabled:opacity-50">
              <Square className="w-5 h-5" /> Finalizar
            </button>
          </div>
        )}

        {timer.state.state === 'paused' && (
          <div className="grid grid-cols-2 gap-3">
            <button onClick={timer.resume}
              className="h-14 rounded-2xl bg-kh-red text-white font-bold active:scale-[0.98] flex items-center justify-center gap-2">
              <Play className="w-5 h-5" /> Reanudar
            </button>
            <button onClick={onFinalizar} disabled={submitting}
              className="h-14 rounded-2xl bg-kh-green text-white font-bold active:scale-[0.98] flex items-center justify-center gap-2 disabled:opacity-50">
              <Square className="w-5 h-5" /> Finalizar
            </button>
          </div>
        )}
      </div>
    </main>
  );
}
