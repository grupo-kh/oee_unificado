import { QueryClient, useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost } from './api';
import type { DashboardData } from './types';

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 30_000,
      refetchOnWindowFocus: false,
    },
  },
});

export function useDashboard() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: () => apiGet<DashboardData>('/api/mant_dashboard.php'),
  });
}

type FinalizarPayload = {
  orden: string;
  tarea: string;
  fecha_proxima_original: string;
  operario: string;
  fecha_intervencion: string;       // hoy YYYY-MM-DD
  hora_inicio: string;              // HH:MM
  tiempo_real_segundos: number;
};

export function useFinalizarTarea() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: FinalizarPayload) =>
      apiPost<{ item: unknown }>('/api/mant_marcar_hecha.php', {
        ...payload,
        tipo: 'completada',
        marcada_por: payload.operario,
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['dashboard'] }),
  });
}
