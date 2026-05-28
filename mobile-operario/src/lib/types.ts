export type Role = 'tecnico' | 'operario' | null;

// El backend devuelve user/csrf_token null cuando no hay sesión activa
// (endpoint mant_session.php anónimo). Los hacemos opcionales/nullable.
export type SessionInfo = {
  user: string | null;
  role: Role;
  csrf_token: string | null;
};

export type Tarea = {
  orden: string;
  tarea: string;
  cod_maquina_mant: string;
  desc_maquina: string;
  desc_grupo: string;
  desc_tarea: string;
  periodicidad: string;
  proxima_revision: string;       // YYYY-MM-DD
  ultima_revision: string | null;
  is_pendiente: boolean;          // flag manual mant_pendientes
  estado?: 'vencida' | 'urgente' | 'en_plazo';
  dias_restantes?: number;
};

export type DashboardData = {
  hoy: Tarea[];
  vencidas: Tarea[];
  marcadas: Tarea[];
  fecha_hoy?: string;
};

export type ApiOk<T> = { ok: true; data: T };
export type ApiErr  = { ok: false; error: string };
export type ApiRes<T> = ApiOk<T> | ApiErr;
