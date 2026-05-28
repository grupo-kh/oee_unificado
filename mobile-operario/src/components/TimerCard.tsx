'use client';

import type { TaskTimerState } from '@/hooks/useTaskTimer';
import { formatMinutos } from '@/lib/utils';
import { cn } from '@/lib/utils';

type Props = {
  state: TaskTimerState;
  totalSeconds: number;
};

export function TimerCard({ state, totalSeconds }: Props) {
  const isRunning = state.state === 'running';
  const isPaused = state.state === 'paused';
  const isIdle = state.state === 'idle';

  const horaInicio = state.startedAt
    ? new Date(state.startedAt).toTimeString().slice(0, 5)
    : '—';

  const statusLabel = isIdle ? 'SIN INICIAR'
    : isRunning ? 'EN CURSO'
    : isPaused ? 'PAUSADO'
    : 'FINALIZADA';

  // Bloque de estado con color de fondo (no solo texto): glanceable.
  const statusChip = isRunning ? 'bg-kh-green-bg text-kh-green border-kh-green'
    : isPaused ? 'bg-kh-amber-bg text-kh-amber border-kh-amber'
    : 'bg-kh-card-2 text-kh-text-soft border-kh-line';

  return (
    <section className="rounded-lg bg-kh-card p-6 border border-kh-line my-4">
      <div className="text-xs uppercase tracking-widest text-kh-text-soft font-bold mb-2">
        Tiempo trabajado
      </div>
      <div className="text-5xl font-extrabold text-kh-text mb-4 tabular-nums leading-none">
        {formatMinutos(totalSeconds)}
      </div>
      <div className="flex items-center justify-between">
        <span className="text-sm text-kh-text-soft">Iniciado a las {horaInicio}</span>
        <span className={cn(
          'inline-flex items-center px-3 py-1.5 rounded-md border-2 text-xs font-bold uppercase tracking-wide',
          statusChip,
        )}>
          {statusLabel}
        </span>
      </div>
    </section>
  );
}
