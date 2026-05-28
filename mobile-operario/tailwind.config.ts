import type { Config } from 'tailwindcss';

const config: Config = {
  content: ['./src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        kh: {
          red: 'var(--kh-red)',
          'red-2': 'var(--kh-red-2)',
          'red-dark': 'var(--kh-red-dark)',
          'red-bg': 'var(--kh-red-bg)',
          'on-red': 'var(--kh-on-red)',
          black: 'var(--kh-black)',
          amber: 'var(--kh-amber)',
          'amber-bg': 'var(--kh-amber-bg)',
          green: 'var(--kh-green)',
          'green-bg': 'var(--kh-green-bg)',
          danger: 'var(--kh-danger)',
          'danger-bg': 'var(--kh-danger-bg)',
          text: 'var(--kh-text)',
          'text-soft': 'var(--kh-text-soft)',
          line: 'var(--kh-line)',
          bg: 'var(--kh-bg)',
          card: 'var(--kh-card)',
          'card-2': 'var(--kh-card-2)',
        },
      },
      maxWidth: { 'app': '480px' },
      boxShadow: {
        'kh-sm': '0 2px 8px rgba(140, 24, 26, 0.08)',
        'kh-md': '0 6px 18px rgba(140, 24, 26, 0.16)',
        'kh-lg': '0 8px 28px rgba(140, 24, 26, 0.24)',
      },
    },
  },
  plugins: [],
};
export default config;
