import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  reactCompiler: true,
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: 'http://localhost:8000/api/:path*',
      },
      {
        source: '/ss/:path*',
        destination: 'http://localhost:8000/ss/:path*',
      },
    ];
  },
};

export default nextConfig;
