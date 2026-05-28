'use client';

import { Delete } from 'lucide-react';
import { cn } from '@/lib/utils';

type Props = {
  value: string;
  onChange: (value: string) => void;
  maxLength?: number;
  disabled?: boolean;
};

export function KeypadInput({ value, onChange, maxLength = 6, disabled }: Props) {
  const press = (digit: string) => {
    if (disabled) return;
    if (value.length >= maxLength) return;
    onChange(value + digit);
  };
  const back = () => { if (!disabled) onChange(value.slice(0, -1)); };
  const clear = () => { if (!disabled) onChange(''); };

  return (
    <div className="grid grid-cols-3 gap-3 w-full">
      {[1,2,3,4,5,6,7,8,9].map(d => (
        <button
          key={d}
          type="button"
          onClick={() => press(String(d))}
          disabled={disabled}
          className={cn(
            'h-16 rounded-2xl bg-white text-2xl font-bold text-kh-text',
            'shadow-kh-sm border border-kh-line',
            'active:scale-95 active:bg-kh-line transition-transform',
            'disabled:opacity-50',
          )}
        >
          {d}
        </button>
      ))}
      <button
        type="button" onClick={clear} disabled={disabled}
        className="h-16 rounded-2xl bg-kh-line text-2xl font-bold text-kh-text-soft active:scale-95"
        aria-label="Limpiar"
      >×</button>
      <button
        type="button" onClick={() => press('0')} disabled={disabled}
        className="h-16 rounded-2xl bg-white text-2xl font-bold text-kh-text border border-kh-line shadow-kh-sm active:scale-95"
      >0</button>
      <button
        type="button" onClick={back} disabled={disabled}
        className="h-16 rounded-2xl bg-kh-line text-kh-text-soft active:scale-95 grid place-items-center"
        aria-label="Borrar"
      ><Delete className="w-6 h-6" /></button>
    </div>
  );
}
