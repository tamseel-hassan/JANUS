"use client";

import { useEffect, useState } from "react";
import {
  Chart as ChartJS,
  ArcElement,
  Tooltip,
  Legend,
  DoughnutController,
  BarElement,
  CategoryScale,
  LinearScale,
  BarController
} from 'chart.js';
import { Doughnut, Bar } from 'react-chartjs-2';
import { Activity, AlertTriangle, Users, Shield } from "lucide-react";
import { HomeData } from "@/types/metrics";
import { StatCard } from "@/components/ui/StatCard";
import { LoadingSpinner } from "@/components/ui/LoadingSpinner";
import { AuthError } from "@/components/ui/AuthError";
import { PageHeader } from "@/components/ui/PageHeader";
import { monitorService } from "@/services/monitor/monitorService";

ChartJS.register(ArcElement, Tooltip, Legend, DoughnutController, BarElement, CategoryScale, LinearScale, BarController);

export function Home() {
  const [data, setData] = useState<HomeData | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    monitorService.getHome()
      .then(d => { setData(d as any); setLoading(false); });
  }, []);

  if (loading) return <LoadingSpinner fullPage />;
  if (data?.error) return <AuthError />;

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
      label: 'Firewall Count',
      data: data?.charts.firewalls.data || [],
      backgroundColor: data?.charts.firewalls.colors || ['#ef4444'], // Default to red if undefined
      borderWidth: 0
    }]
  };

  const switchChartData = {
    labels: data?.charts.switches.labels || [],
    datasets: [{
      label: 'Switch Count',
      data: data?.charts.switches.data || [],
      backgroundColor: '#f59e0b', // Default yellow/orange
      borderWidth: 0
    }]
  };

  const doughnutOptions = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '75%',
    plugins: {
      legend: {
        position: 'right' as const,
        labels: {
          color: '#9ca3af', // gray-400
          boxWidth: 12,
          padding: 15,
          font: { size: 11 }
        }
      }
    }
  };

  const barOptions = {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
      y: {
        beginAtZero: true,
        ticks: { color: '#9ca3af' },
        grid: { color: '#374151', drawBorder: false }
      },
      x: {
        ticks: { color: '#9ca3af' },
        grid: { display: false }
      }
    },
    plugins: {
      legend: {
        display: true,
        position: 'bottom' as const,
        labels: {
          color: '#9ca3af',
          boxWidth: 12,
          padding: 15,
          font: { size: 11 }
        }
      }
    }
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Joint Analytics for Networks &amp; Unified Security"
        subtitle="Core monitoring active."
      />

      {/* NOC Summary Stats */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <StatCard title="Total Devices" value={data?.stats.total ?? 0} color="text-blue-400" />
        <StatCard title="Up Devices"    value={data?.stats.up ?? 0}    color="text-green-400" />
        <StatCard title="Down Devices"  value={data?.stats.down ?? 0}  color="text-red-400" />
        <StatCard title="Network Uptime" value={`${data?.stats.uptime ?? 0}%`} color="text-accent-primary" />
      </div>

      {/* Charts Row */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6">
          <h3 className="text-sm font-bold text-foreground mb-4 uppercase tracking-wider">Servers by Type</h3>
          <div className="h-64 relative">
            {data?.charts.servers.data.length ? (
              <Doughnut data={serverChartData} options={doughnutOptions} />
            ) : (
              <div className="absolute inset-0 flex items-center justify-center text-text-muted">
                No server data.
              </div>
            )}
          </div>
        </div>
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6">
          <h3 className="text-sm font-bold text-foreground mb-4 uppercase tracking-wider">Firewalls by Model</h3>
          <div className="h-64 relative">
            {data?.charts.firewalls.data.length ? (
              <Bar data={fwChartData} options={barOptions} />
            ) : (
              <div className="absolute inset-0 flex items-center justify-center text-text-muted">
                No firewall data.
              </div>
            )}
          </div>
        </div>
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6">
          <h3 className="text-sm font-bold text-foreground mb-4 uppercase tracking-wider">Switches by Model</h3>
          <div className="h-64 relative">
            {data?.charts.switches?.data.length ? (
              <Bar data={switchChartData} options={barOptions} />
            ) : (
              <div className="absolute inset-0 flex items-center justify-center text-text-muted">
                No switch data.
              </div>
            )}
          </div>
        </div>
      </div>

      {/* SOC Incident Stats */}
      <div className="pt-4">
        <h2 className="text-xl font-bold text-foreground mb-4">SOC/NOC Incident Overview</h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <StatCard title="My Assigned Tasks"  value={data?.tickets.my_tasks ?? 0} color="text-accent-primary" />
          <StatCard title="Open Tickets"       value={data?.tickets.open ?? 0}     color="text-yellow-400" />
          <StatCard title="Overdue Tickets"    value={data?.tickets.overdue ?? 0}  color="text-red-500" icon={<AlertTriangle className="w-5 h-5" />} iconBgClass="bg-red-500/10 border-red-500/30 text-red-500" />
        </div>
      </div>

      {/* System Status */}
      <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6">
        <h3 className="text-lg font-bold text-foreground mb-4">System Status</h3>
        <div className="space-y-2 text-sm text-text-muted">
          <p><strong className="text-foreground">ICMP, SNMP, Traffic Flows:</strong> Running (every 2 min)</p>
          <p><strong className="text-foreground">Device Inventory:</strong> {data?.stats.total ?? 0} registered</p>
          <p><strong className="text-foreground">Syslog Collection:</strong> Active</p>
          <p><strong className="text-foreground">SOC/NOC Incident Module:</strong> Active (Register, Assign, Track Tickets)</p>
        </div>
      </div>
    </div>
  );
}
export default Home;
