import type { Metadata, Viewport } from 'next';
import './globals.css';
import { Providers } from './providers';

export const metadata: Metadata = {
  title: 'KH Mantenimiento Operario',
  description: 'Revisiones preventivas para operarios',
  manifest: '/manifest.json',
  appleWebApp: {
    capable: true,
    statusBarStyle: 'black-translucent',
    title: 'KH Operario',
  },
  icons: {
    apple: '/apple-touch-icon.png',
  },
};

export const viewport: Viewport = {
  themeColor: '#8c181a',
  width: 'device-width',
  initialScale: 1,
  viewportFit: 'cover',
};

// Script anti-flash: fija el tema ANTES del primer paint. Sin preferencia
// guardada, auto-oscuro en horario nocturno (20:00–06:59) para turno de noche.
const themeInitScript = `(function(){try{var t=localStorage.getItem('mobile-operario:theme');if(t!=='light'&&t!=='dark'){var h=new Date().getHours();t=(h>=20||h<7)?'dark':'light';}document.documentElement.dataset.theme=t;}catch(e){document.documentElement.dataset.theme='light';}})();`;

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="es">
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeInitScript }} />
      </head>
      <body>
        <div className="min-h-screen mx-auto max-w-app bg-kh-bg shadow-lg">
          <Providers>{children}</Providers>
        </div>
      </body>
    </html>
  );
}
