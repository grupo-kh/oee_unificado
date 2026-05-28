'use client';

/* eslint-disable @next/next/no-img-element */
import { ChevronLeft } from 'lucide-react';
import { ThemeToggle } from './ThemeToggle';

type Props = {
  title: string;
  subtitle?: string;
  onBack?: () => void;
  rightSlot?: React.ReactNode;
};

export function TopBar({ title, subtitle, onBack, rightSlot }: Props) {
  return (
    <header className="sticky top-0 z-10 bg-kh-red text-kh-on-red px-4 py-3 flex items-center gap-3 border-b-4 border-kh-red-dark">
      {onBack && (
        <button
          onClick={onBack}
          aria-label="Volver"
          className="w-10 h-10 rounded-lg bg-white/15 grid place-items-center active:scale-95 shrink-0"
        >
          <ChevronLeft className="w-6 h-6" strokeWidth={2.5} />
        </button>
      )}
      {/* Logo corporativo (SVG) en chip blanco para que quede limpio sobre la
          cabecera roja. */}
      <div className="bg-white rounded-md px-2 py-1 shrink-0 flex items-center kh-logo-header">
        <img src="/logo_kh.svg" alt="KH Know How" className="h-7 w-auto" />
      </div>
      <div className="flex-1 min-w-0">
        <h1 className="text-[17px] font-bold truncate leading-tight">{title}</h1>
        {subtitle && <div className="text-xs text-white/80 truncate">{subtitle}</div>}
      </div>
      <ThemeToggle />
      {rightSlot}
    </header>
  );
}
