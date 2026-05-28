'use client';

import { useCallback, useEffect, useState } from 'react';

export type TaskTimerState = {
  state: 'idle' | 'running' | 'paused' | 'finished';
  startedAt: string | null;       // ISO timestamp primer Iniciar
  totalAtLastPause: number;       // segundos acumulados antes del último resume
  runningSince: string | null;    // ISO del último Iniciar/Reanudar
};

const initialState: TaskTimerState = {
  state: 'idle',
  startedAt: null,
  totalAtLastPause: 0,
  runningSince: null,
};

function storageKey(id: string) {
  return `mobile-operario:timer:${id}`;
}

function read(id: string): TaskTimerState {
  if (typeof window === 'undefined') return initialState;
  try {
    const raw = localStorage.getItem(storageKey(id));
    if (!raw) return initialState;
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed === 'object') return { ...initialState, ...parsed };
  } catch { /* ignore */ }
  return initialState;
}

function write(id: string, s: TaskTimerState) {
  if (typeof window === 'undefined') return;
  localStorage.setItem(storageKey(id), JSON.stringify(s));
}

export function getTotalSeconds(s: TaskTimerState, now: Date = new Date()): number {
  if (s.state === 'running' && s.runningSince) {
    const delta = Math.floor((now.getTime() - new Date(s.runningSince).getTime()) / 1000);
    return Math.max(0, s.totalAtLastPause + delta);
  }
  return s.totalAtLastPause;
}

export function useTaskTimer(id: string) {
  const [s, setS] = useState<TaskTimerState>(initialState);

  // Hidratar de localStorage al montar / cambiar de id
  useEffect(() => { setS(read(id)); }, [id]);

  const update = useCallback((next: TaskTimerState) => {
    write(id, next);
    setS(next);
  }, [id]);

  const start = useCallback(() => {
    if (s.state !== 'idle') return;
    const nowIso = new Date().toISOString();
    update({ state: 'running', startedAt: nowIso, totalAtLastPause: 0, runningSince: nowIso });
  }, [s, update]);

  const pause = useCallback(() => {
    if (s.state !== 'running') return;
    const now = new Date();
    const acc = getTotalSeconds(s, now);
    update({ ...s, state: 'paused', totalAtLastPause: acc, runningSince: null });
  }, [s, update]);

  const resume = useCallback(() => {
    if (s.state !== 'paused') return;
    update({ ...s, state: 'running', runningSince: new Date().toISOString() });
  }, [s, update]);

  const finish = useCallback((): TaskTimerState & { totalSeconds: number } => {
    const now = new Date();
    const total = getTotalSeconds(s, now);
    const finished: TaskTimerState = {
      ...s, state: 'finished', totalAtLastPause: total, runningSince: null,
    };
    update(finished);
    return { ...finished, totalSeconds: total };
  }, [s, update]);

  const clear = useCallback(() => {
    if (typeof window !== 'undefined') localStorage.removeItem(storageKey(id));
    setS(initialState);
  }, [id]);

  return { state: s, start, pause, resume, finish, clear, totalSeconds: getTotalSeconds(s) };
}
