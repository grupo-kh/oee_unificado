'use client';

import { Suspense, useEffect } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { CheckCircle2 } from 'lucide-react';
import { formatMinutos, today } from '@/lib/utils';

function ConfirmacionContent() {
  const router = useRouter();
  const params = useSearchParams();
  const maq = params.get('maq') ?? '';
  const op = params.get('op') ?? '';
  const tiempo = parseInt(params.get('tiempo') ?? '0', 10);

  useEffect(() => {
    const t = setTimeout(() => router.replace('/hoy'), 5000);
    return () => clearTimeout(t);
  }, [router]);

  return (
    <main className="min-h-screen flex flex-col items-center justify-center p-6 text-center">
      <CheckCircle2 className="w-20 h-20 text-kh-green mb-4" />
      <h1 className="text-2xl font-bold text-kh-text mb-1">Revisión registrada</h1>
      <p className="text-kh-text-soft mb-6">Se ha guardado correctamente en el histórico.</p>

      <section className="w-full max-w-xs bg-white rounded-2xl p-4 shadow-kh-sm border border-kh-line text-left">
        <Row label="Máquina" value={maq || '—'} />
        <Row label="Operario" value={op || '—'} />
        <Row label="Fecha" value={today()} />
        <Row label="Tiempo trabajado" value={formatMinutos(tiempo)} />
      </section>

      <button onClick={() => router.replace('/hoy')}
        className="mt-6 w-full max-w-xs h-12 rounded-2xl bg-kh-red text-white font-bold shadow-kh-md active:scale-[0.98]">
        Volver a la lista
      </button>
    </main>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between py-2 border-b last:border-0 border-kh-line">
      <span className="text-sm text-kh-text-soft">{label}</span>
      <span className="text-sm font-semibold text-kh-text">{value}</span>
    </div>
  );
}

export default function ConfirmacionPage() {
  return (
    <Suspense fallback={<div className="min-h-screen grid place-items-center text-kh-text-soft">Cargando…</div>}>
      <ConfirmacionContent />
    </Suspense>
  );
}
