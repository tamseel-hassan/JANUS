"use client";

import { useEffect, useState } from "react";
import {
  Chart as ChartJS,
  ArcElement,
  Tooltip,
  Legend,
  DoughnutController
} from 'chart.js';
import { Doughnut } from 'react-chartjs-2';
import { Shield, Activity, Users, AlertTriangle } from "lucide-react";

ChartJS.register(ArcElement, Tooltip, Legend, DoughnutController);

interface HomeData {
  stats: {
    total: number;
    up: number;
    down: number;
    uptime: number;
  };
  tickets: {
    my_tasks: number;
    open: number;
    overdue: number;
  };
  charts: {
    servers: { labels: string[], data: number[], colors: string[] };
    firewalls: { labels: string[], data: number[], colors: string[] };
    switches: { labels: string[], data: number[] };
  };
  error?: string;
}

export default function HomePage() {
  const [data, setData] = useState<HomeData | null>(null);
  const [loading, setLoading] = useState(true);
  useEffect(() => {
    fetch("/api/get_home.php", { credentials: "include" })
      .then(res => res.json())
      .then(d => { setData(d); setLoading(false); })
      .catch(err => { console.error(err); setLoading(false); });
  }, []);

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="w-10 h-10 border-4 border-border-subtle border-t-accent-primary rounded-full animate-spin"></div>
      </div>
    );
  }

  if (data?.error) {
    return (
      <div className="bg-bg-card border-l-4 border-red-500 p-6 rounded-2xl">
        <h2 className="text-xl font-semibold text-red-400">Authentication Required</h2>
        <p className="text-text-muted mt-2">Please ensure you are logged into the original application.</p>
      </div>
    );
  }

  const serverChartData = {
    labels: data?.charts.servers.labels || [],
    datasets: [{
      data: data?.charts.servers.data || [],
      backgroundColor: data?.charts.servers.colors || [],
      borderWidth: 0,
      hoverOffset: 4
    }]
  };

  const fwChartData = {
    labels: data?.charts.firewalls.labels || [],
    datasets: [{
      data: data?.charts.firewalls.data || [],
      backgroundColor: data?.charts.firewalls.colors || [],
      borderWidth: 0,
      hoverOffset: 4
    }]
  };

  const chartOptions = {
    cutout: '75%',
    plugins: {
      legend: { position: 'right' as const, labels: { color: 'var(--text-muted)' } }
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-foreground tracking-wide">Joint Analytics for Networks & Unified Security</h1>
        <p className="text-text-muted mt-1">Core monitoring active.</p>
      </div>

      {/* NOC Summary Stats */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <StatCard title="Total Devices" value={data?.stats.total ?? 0} icon={<Activity />} color="text-blue-400" />
        <StatCard title="Up Devices" value={data?.stats.up ?? 0} icon={<Activity />} color="text-green-400" />
        <StatCard title="Down Devices" value={data?.stats.down ?? 0} icon={<AlertTriangle />} color="text-red-400" />
        <StatCard title="Uptime (%)" value={data?.stats.uptime ?? 0} icon={<Activity />} color="text-accent-primary" />
      </div>

      {/* SOC Incident Stats */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <StatCard title="My Active Tasks" value={data?.tickets.my_tasks ?? 0} icon={<Users />} color="text-accent-primary" />
        <StatCard title="Total Open Tickets" value={data?.tickets.open ?? 0} icon={<AlertTriangle />} color="text-yellow-400" />
        <StatCard title="Overdue Tickets" value={data?.tickets.overdue ?? 0} icon={<Shield />} color="text-red-500" />
      </div>

      {/* Charts */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6">
          <h3 className="text-lg font-medium text-foreground mb-4">Servers by Type</h3>
          <div className="h-64 flex justify-center">
            {data?.charts.servers.data.length ? <Doughnut data={serverChartData} options={chartOptions} /> : <p className="text-text-muted mt-20">No server data.</p>}
          </div>
        </div>
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6">
          <h3 className="text-lg font-medium text-foreground mb-4">Firewalls by Vendor</h3>
          <div className="h-64 flex justify-center">
            {data?.charts.firewalls.data.length ? <Doughnut data={fwChartData} options={chartOptions} /> : <p className="text-text-muted mt-20">No firewall data.</p>}
          </div>
        </div>
      </div>
    </div>
  );
}

function StatCard({ title, value, icon, color }: { title: string, value: number, icon: React.ReactNode, color: string }) {
  return (
    <div className="bg-bg-raised border border-border-subtle rounded-2xl p-5 flex flex-col items-center justify-center text-center transition-transform hover:-translate-y-1">
      <div className={`text-2xl font-bold ${color} mb-2`}>{value}</div>
      <div className="text-sm font-medium text-text-muted uppercase tracking-wider">{title}</div>
    </div>
  );
}
