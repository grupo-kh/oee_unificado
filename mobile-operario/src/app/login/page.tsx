'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { KeypadInput } from '@/components/KeypadInput';
import { useAuth } from '@/lib/auth-context';
import { ApiError } from '@/lib/api';

export default function LoginPage() {
  const router = useRouter();
  const { login } = useAuth();
  const [numero, setNumero] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const onSubmit = async (e?: React.FormEvent) => {
    e?.preventDefault();
    if (!numero || busy) return;
    setBusy(true); setError(null);
    try {
      await login(numero);
      router.push('/hoy');
    } catch (err) {
      const msg = err instanceof ApiError && err.status === 401
        ? 'Operario no válido. Vuelve a intentarlo.'
        : 'Error de conexión. Reintenta.';
      setError(msg);
      setNumero('');
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="min-h-screen flex flex-col items-center px-6 py-8">
      <div className="w-20 h-20 rounded-full bg-kh-red grid place-items-center mb-4 shadow-kh-md">
        <span className="text-white text-2xl font-bold">KH</span>
      </div>
      <h1 className="text-2xl font-bold text-kh-text mb-1">Identifícate</h1>
      <p className="text-sm text-kh-text-soft mb-6 text-center">
        Introduce tu número de operario para empezar el turno.
      </p>

      <form onSubmit={onSubmit} className="w-full max-w-xs">
        <input
          readOnly value={numero || ' '} aria-label="Número de operario"
          className="w-full text-center text-3xl font-bold tracking-widest bg-white rounded-xl border border-kh-line py-4 mb-4 shadow-kh-sm"
        />

        <KeypadInput value={numero} onChange={setNumero} disabled={busy} />

        {error && (
          <p className="mt-4 text-center text-sm text-kh-red font-semibold">{error}</p>
        )}

        <button
          type="submit"
          disabled={!numero || busy}
          className="mt-6 w-full h-14 rounded-2xl bg-kh-red text-white text-lg font-bold shadow-kh-md disabled:opacity-50 active:scale-[0.98]"
        >
          {busy ? 'Entrando…' : 'Entrar'}
        </button>
      </form>
    </main>
  );
}
