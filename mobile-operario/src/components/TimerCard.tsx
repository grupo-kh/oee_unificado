'use client';

import type { TaskTimerState } from '@/hooks/useTaskTimer';
import { formatMinutos, cn } from '@/lib/utils';

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

  const statusLabel = isIdle ? '⬛ Sin iniciar'
    : isRunning ? '▶ En curso'
    : isPaused ? '⏸ Pausado'
    : '✓ Finalizada';

  const statusColor = isIdle ? 'text-kh-text-soft'
    : isRunning ? 'text-kh-green'
    : isPaused ? 'text-kh-amber'
    : 'text-kh-green';

  return (
    <section className="rounded-2xl bg-white p-6 shadow-kh-md border border-kh-line my-4">
      <div className="text-xs uppercase tracking-wider text-kh-text-soft font-bold mb-2">
        Tiempo trabajado
      </div>
      <div className="text-4xl font-bold text-kh-text mb-3 tabular-nums">
        {formatMinutos(totalSeconds)}
      </div>
      <div className="flex justify-between text-sm">
        <span className="text-kh-text-soft">Iniciado a las {horaInicio}</span>
        <span className={cn('font-semibold', statusColor)}>{statusLabel}</span>
      </div>
    </section>
  );
}
