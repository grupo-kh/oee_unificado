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

  const keyBase = 'h-[64px] rounded-lg text-3xl font-bold active:scale-95 transition-transform disabled:opacity-50';
  return (
    <div className="grid grid-cols-3 gap-3 w-full">
      {[1,2,3,4,5,6,7,8,9].map(d => (
        <button
          key={d}
          type="button"
          onClick={() => press(String(d))}
          disabled={disabled}
          className={cn(keyBase, 'bg-kh-card text-kh-text border-2 border-kh-line active:bg-kh-card-2')}
        >
          {d}
        </button>
      ))}
      <button
        type="button" onClick={clear} disabled={disabled}
        className={cn(keyBase, 'bg-kh-card-2 text-kh-text-soft border-2 border-kh-line')}
        aria-label="Limpiar"
      >×</button>
      <button
        type="button" onClick={() => press('0')} disabled={disabled}
        className={cn(keyBase, 'bg-kh-card text-kh-text border-2 border-kh-line active:bg-kh-card-2')}
      >0</button>
      <button
        type="button" onClick={back} disabled={disabled}
        className={cn(keyBase, 'bg-kh-card-2 text-kh-text-soft border-2 border-kh-line grid place-items-center')}
        aria-label="Borrar"
      ><Delete className="w-7 h-7" /></button>
    </div>
  );
}
