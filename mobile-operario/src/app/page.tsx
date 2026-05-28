'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';

export default function HomeRedirect() {
  const router = useRouter();
  const { user, loading } = useAuth();

  useEffect(() => {
    if (loading) return;
    router.replace(user ? '/hoy' : '/login');
  }, [user, loading, router]);

  return (
    <div className="min-h-screen grid place-items-center text-kh-text-soft">
      Cargando…
    </div>
  );
}
