'use client';

import { ChevronLeft } from 'lucide-react';

type Props = {
  title: string;
  subtitle?: string;
  onBack?: () => void;
  rightSlot?: React.ReactNode;
};

export function TopBar({ title, subtitle, onBack, rightSlot }: Props) {
  return (
    <header className="sticky top-0 z-10 bg-kh-black text-white px-4 py-4 flex items-center gap-3 border-b-4 border-kh-red shadow-md">
      {onBack && (
        <button
          onClick={onBack}
          aria-label="Volver"
          className="w-9 h-9 rounded-xl bg-white/10 grid place-items-center active:scale-95"
        >
          <ChevronLeft className="w-5 h-5" />
        </button>
      )}
      <div className="flex-1 min-w-0">
        <h1 className="text-base font-bold truncate">{title}</h1>
        {subtitle && <div className="text-xs text-white/70 truncate">{subtitle}</div>}
      </div>
      {rightSlot}
    </header>
  );
}
