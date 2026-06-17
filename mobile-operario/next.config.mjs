import withPWAInit from '@ducanh2912/next-pwa';

const withPWA = withPWAInit({
  dest: 'public',
  disable: process.env.NODE_ENV === 'development',
  cacheOnFrontEndNav: false,
  workboxOptions: {
    runtimeCaching: [
      {
        urlPattern: /^https?.*\/_next\/static\//,
        handler: 'CacheFirst',
        options: { cacheName: 'next-static', expiration: { maxEntries: 60 } },
      },
    ],
  },
});

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: 'http://localhost:8080/oee_unificado/api/:path*',
      },
    ];
  },
};

export default withPWA(nextConfig);
