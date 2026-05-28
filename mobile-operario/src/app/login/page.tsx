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
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <div className="kh-logo-enter mt-2 mb-6">
        <img src="/logo_kh.svg" alt="KH Know How" className="kh-logo-float w-60 max-w-[80vw] h-auto" />
      </div>
      <h1 className="text-2xl font-bold text-kh-text mb-1">Identifícate</h1>
      <p className="text-sm text-kh-text-soft mb-6 text-center">
        Introduce tu número de operario para empezar el turno.
      </p>

      <form onSubmit={onSubmit} className="w-full max-w-xs">
        <input
          readOnly value={numero || ' '} aria-label="Número de operario"
          className="w-full text-center text-4xl font-bold tracking-[0.3em] bg-kh-card text-kh-text rounded-lg border-2 border-kh-line py-4 mb-4 tabular-nums"
        />

        <KeypadInput value={numero} onChange={setNumero} disabled={busy} />

        {error && (
          <p className="mt-4 text-center text-sm text-kh-danger font-bold">{error}</p>
        )}

        <button
          type="submit"
          disabled={!numero || busy}
          className="mt-6 w-full h-16 rounded-lg bg-kh-red text-white text-lg font-bold border-b-4 border-kh-red-dark disabled:opacity-50 active:scale-[0.98]"
        >
          {busy ? 'Entrando…' : 'Entrar'}
        </button>
      </form>
    </main>
  );
}
