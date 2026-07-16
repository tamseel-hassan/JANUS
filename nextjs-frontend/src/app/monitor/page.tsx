"use client";

import { useEffect, useState } from "react";
import { Activity, AlertTriangle, Search, Filter } from "lucide-react";

interface Device {
  id: number;
  name: string;
  ip: string;
  type: string;
  model: string;
  city: string;
  sub_office: string;
  country: string;
  status: "up" | "down";
  rtt_avg: number | null;
  checked_at: string | null;
  last_status_change: string | null;
}

interface MonitorData {
  stats: {
    total: number;
    up: number;
    down: number;
    avg_rtt: number;
  };
  devices: Device[];
  error?: string;
}

export default function MonitorPage() {
  const [data, setData] = useState<MonitorData | null>(null);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState("");

  useEffect(() => {
    // Basic polling mechanism for real-time monitoring
    const fetchData = () => {
      fetch("/api/get_monitor.php", { credentials: "include" })
        .then(res => res.json())
        .then(d => { setData(d); setLoading(false); })
        .catch(err => { console.error(err); setLoading(false); });
    };

    fetchData();
    const intervalId = setInterval(fetchData, 30000); // refresh every 30s
    return () => clearInterval(intervalId);
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

  const filteredDevices = data?.devices.filter(d => 
    d.name.toLowerCase().includes(searchTerm.toLowerCase()) || 
    d.ip.includes(searchTerm)
  ) || [];

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-end">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-wide">Network Monitoring</h1>
          <p className="text-text-muted mt-1">Real-time device status and latency.</p>
        </div>
      </div>

      {/* Summary Stats */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <StatCard title="Total Devices" value={data?.stats.total ?? 0} color="text-blue-400" />
        <StatCard title="Up Devices" value={data?.stats.up ?? 0} color="text-green-400" />
        <StatCard title="Down Devices" value={data?.stats.down ?? 0} color="text-red-400" />
        <StatCard title="Avg Latency" value={`${data?.stats.avg_rtt ?? 0} ms`} color="text-accent-primary" />
      </div>

      {/* Main Panel */}
      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden">
        
        {/* Toolbar */}
        <div className="p-4 border-b border-border-subtle flex justify-between items-center bg-bg-main">
          <div className="relative">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
            <input 
              type="text" 
              placeholder="Search devices..." 
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="bg-bg-card border border-border-subtle rounded-2xl py-1.5 pl-10 pr-4 text-sm text-foreground focus:outline-none focus:border-accent-primary w-64"
            />
          </div>
          <button className="flex items-center text-sm text-text-muted hover:text-foreground transition-colors bg-bg-card px-3 py-1.5 rounded-2xl border border-border-subtle">
            <Filter className="w-4 h-4 mr-2" /> Filter
          </button>
        </div>

        {/* Table */}
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle">
              <tr>
                <th className="px-6 py-4 font-medium">Status</th>
                <th className="px-6 py-4 font-medium">Device Name</th>
                <th className="px-6 py-4 font-medium">IP Address</th>
                <th className="px-6 py-4 font-medium">Type</th>
                <th className="px-6 py-4 font-medium">Location</th>
                <th className="px-6 py-4 font-medium">Avg RTT</th>
                <th className="px-6 py-4 font-medium text-right">Last Checked</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-subtle">
              {filteredDevices.map((device) => (
                <tr key={device.id} className="hover:bg-bg-card/50 transition-colors">
                  <td className="px-6 py-4 whitespace-nowrap">
                    {device.status === 'up' ? (
                      <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-900/50 text-green-400 border border-green-800">
                        <span className="w-1.5 h-1.5 bg-green-400 rounded-full mr-2"></span> Up
                      </span>
                    ) : (
                      <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-900/50 text-red-400 border border-red-800">
                        <span className="w-1.5 h-1.5 bg-red-400 rounded-full mr-2 animate-pulse"></span> Down
                      </span>
                    )}
                  </td>
                  <td className="px-6 py-4 font-medium text-foreground">{device.name}</td>
                  <td className="px-6 py-4 text-text-muted font-mono">{device.ip}</td>
                  <td className="px-6 py-4 text-text-muted capitalize">{device.type}</td>
                  <td className="px-6 py-4 text-text-muted">
                    {[device.city, device.country].filter(Boolean).join(', ') || 'Unknown'}
                  </td>
                  <td className="px-6 py-4 text-text-muted">
                    {device.rtt_avg ? `${device.rtt_avg} ms` : '-'}
                  </td>
                  <td className="px-6 py-4 text-right text-text-muted-deep text-xs">
                    {device.checked_at || 'Never'}
                  </td>
                </tr>
              ))}
              {filteredDevices.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-6 py-12 text-center text-text-muted">
                    No devices match your search.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function StatCard({ title, value, color }: { title: string, value: string | number, color: string }) {
  return (
    <div className="bg-bg-raised border border-border-subtle rounded-2xl p-5 flex flex-col justify-center">
      <div className="text-sm font-medium text-text-muted uppercase tracking-wider mb-2">{title}</div>
      <div className={`text-2xl font-bold ${color}`}>{value}</div>
    </div>
  );
}
