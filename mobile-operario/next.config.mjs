/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: 'http://localhost:8080/PLAN_ATTAINMENT/api/:path*',
      },
    ];
  },
};

export default nextConfig;
