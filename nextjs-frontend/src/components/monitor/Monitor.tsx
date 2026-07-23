"use client";

import { useEffect, useState } from "react";
import { Activity, AlertTriangle, Filter } from "lucide-react";
import { MonitorDevice as Device, MonitorData } from "@/types/metrics";
import { StatCard } from "@/components/ui/StatCard";
import { LoadingSpinner } from "@/components/ui/LoadingSpinner";
import { AuthError } from "@/components/ui/AuthError";
import { PageHeader } from "@/components/ui/PageHeader";
import { SearchInput } from "@/components/ui/SearchInput";
import { SectionCard } from "@/components/ui/SectionCard";
import { Button } from "@/components/ui/Button";
import { monitorService } from "@/services/monitor/monitorService";

export function Monitor() {
  const [data, setData] = useState<MonitorData | null>(null);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState("");

  useEffect(() => {
    const fetchData = () => {
      monitorService.getMonitor()
        .then(d => { setData(d as any); setLoading(false); });
    };
    fetchData();
    const intervalId = setInterval(fetchData, 30000);
    return () => clearInterval(intervalId);
  }, []);

  if (loading) return <LoadingSpinner fullPage />;
  if (data?.error) return <AuthError />;

  const filteredDevices = data?.devices.filter(d =>
    d.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
    d.ip.includes(searchTerm)
  ) || [];

  return (
    <div className="space-y-6">
      <PageHeader
        title="Network Monitoring"
        subtitle="Real-time device status and latency."
      />

      {/* Summary Stats */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <StatCard title="Total Devices" value={data?.stats.total ?? 0} color="text-blue-400" />
        <StatCard title="Up Devices"    value={data?.stats.up ?? 0}    color="text-green-400" />
        <StatCard title="Down Devices"  value={data?.stats.down ?? 0}  color="text-red-400" />
        <StatCard title="Avg Latency"   value={`${data?.stats.avg_rtt ?? 0} ms`} color="text-accent-primary" />
      </div>

      {/* Main Panel */}
      <SectionCard
        header={
          <>
            <SearchInput
              value={searchTerm}
              onChange={setSearchTerm}
              placeholder="Search devices..."
              className="w-64"
            />
            <Button variant="outline" size="sm">
              <Filter className="w-4 h-4 mr-2" /> Filter
            </Button>
          </>
        }
      >
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
                  <td className="px-6 py-4 text-right text-text-muted text-xs">
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
      </SectionCard>
    </div>
  );
}
export default Monitor;
