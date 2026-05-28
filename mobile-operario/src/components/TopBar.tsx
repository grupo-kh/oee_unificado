'use client';

/* eslint-disable @next/next/no-img-element */
import { ChevronLeft } from 'lucide-react';

type Props = {
  title: string;
  subtitle?: string;
  onBack?: () => void;
  rightSlot?: React.ReactNode;
};

export function TopBar({ title, subtitle, onBack, rightSlot }: Props) {
  return (
    <header className="sticky top-0 z-10 bg-kh-red text-white px-4 py-3 flex items-center gap-3 border-b-4 border-kh-red-dark shadow-md">
      {onBack && (
        <button
          onClick={onBack}
          aria-label="Volver"
          className="w-9 h-9 rounded-xl bg-white/15 grid place-items-center active:scale-95 shrink-0"
        >
          <ChevronLeft className="w-5 h-5" />
        </button>
      )}
      {/* Logo en chip blanco para que el logo corporativo (rojo + gris) se lea
          sobre la cabecera roja. Sustituye public/kh.png por el oficial. */}
      <div className="bg-white rounded-lg px-2 py-1 shrink-0 flex items-center">
        <img src="/kh.png" alt="KH Know How" className="h-7 w-auto" />
      </div>
      <div className="flex-1 min-w-0">
        <h1 className="text-base font-bold truncate">{title}</h1>
        {subtitle && <div className="text-xs text-white/80 truncate">{subtitle}</div>}
      </div>
      {rightSlot}
    </header>
  );
}
