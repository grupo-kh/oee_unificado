/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: 'http://localhost/PLAN_ATTAINMENT/api/:path*',
      },
    ];
  },
};

export default nextConfig;
