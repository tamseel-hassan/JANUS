"use client";

import React, { useEffect, useState } from "react";
import { Monitor, Signal, Grid, Info, AlertTriangle } from "lucide-react";
import { notify } from "@/services/feedback/feedbackService";
import { reportsService } from "@/services/reports/reportsService";

interface EndpointActivityRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function EndpointActivityRenderer({ timeRange, selectedDevice }: EndpointActivityRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const result = await reportsService.getEndpointActivityReport({ range: timeRange, device: selectedDevice });
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch endpoint activity report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading endpoint fleet data...</div>;
  }

  if (!data || data.error && !data.error_type) {
    return <div className="text-center p-12 text-red-500">Failed to load data. {data?.error}</div>;
  }

  if (data.error_type === 'missing_table') {
    return (
      <div className="bg-amber-500/10 border border-amber-500/20 rounded-xl p-6 flex flex-col items-center justify-center text-center">
        <AlertTriangle className="w-12 h-12 text-amber-500 mb-4" />
        <h3 className="text-lg font-semibold text-foreground mb-2">Endpoint Tables Not Found</h3>
        <p className="text-amber-200/80 max-w-lg text-sm">
          {data.error}
        </p>
      </div>
    );
  }

  const timeAgo = (dateStr: string) => {
    const diff = Math.floor((new Date().getTime() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60) return diff + 's ago';
    if (diff < 3600) return Math.floor(diff / 60) + 'min ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'hr ago';
    return Math.floor(diff / 86400) + 'd ago';
  };

  const handleDrillDown = (id: number, hostname: string) => {
    notify.info(`Drill-down for endpoint ${hostname} (ID: ${id}) would open here. Modal functionality can be integrated later.`);
  };

  return (
    <div className="space-y-6">
      
      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Monitor className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_endpoints.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Enrolled Endpoints</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Signal className="w-8 h-8 text-green-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.online_count.toLocaleString()}</div>
          <div className="text-xs text-green-500 bg-green-500/10 px-2 py-1 rounded-full mt-2">Reporting Now</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Grid className="w-8 h-8 text-indigo-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_apps.toLocaleString()}</div>
          <div className="text-xs text-indigo-500 bg-indigo-500/10 px-2 py-1 rounded-full mt-2">Distinct Apps Across Fleet</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Monitor className="w-8 h-8 text-amber-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.rdp_24h.toLocaleString()}</div>
          <div className="text-xs text-amber-500 bg-amber-500/10 px-2 py-1 rounded-full mt-2">Remote Session Events (24h)</div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        
        {/* Endpoint Fleet */}
        <div className="lg:col-span-12 bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
          <h5 className="flex items-center text-foreground font-semibold mb-1"><Monitor className="w-5 h-5 mr-2 text-foreground-muted" /> Endpoint Fleet</h5>
          <p className="text-xs text-foreground-muted mb-4">Click a row for logons, installed apps, listening ports, remote sessions, and correlated network traffic for that host</p>
          <div className="overflow-x-auto flex-1">
            <table className="w-full text-sm text-left text-foreground-muted">
              <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
                <tr>
                  <th className="px-4 py-3 w-8"></th>
                  <th className="px-4 py-3">Hostname</th>
                  <th className="px-4 py-3">IP Address</th>
                  <th className="px-4 py-3">OS</th>
                  <th className="px-4 py-3">Agent</th>
                  <th className="px-4 py-3">Last Check-in</th>
                </tr>
              </thead>
              <tbody>
                {data.endpoints.length === 0 ? (
                  <tr><td colSpan={6} className="text-center py-8">No endpoints have checked in yet</td></tr>
                ) : (
                  data.endpoints.map((e: any, idx: number) => {
                    const isOnline = new Date(e.last_seen).getTime() >= new Date(data.online_cutoff).getTime();
                    return (
                      <tr key={idx} onClick={() => handleDrillDown(e.id, e.hostname)} className="border-b border-border-subtle/50 hover:bg-bg-raised/50 cursor-pointer transition-colors">
                        <td className="px-4 py-3">
                          <div className={`w-2.5 h-2.5 rounded-full ${isOnline ? 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.6)]' : 'bg-red-500'}`}></div>
                        </td>
                        <td className="px-4 py-3 font-semibold text-foreground">{e.hostname}</td>
                        <td className="px-4 py-3">
                          <code className="text-xs bg-bg-main px-1 py-0.5 rounded border border-border-subtle">{e.ip_address}</code>
                        </td>
                        <td className="px-4 py-3 text-xs">{e.os_version}</td>
                        <td className="px-4 py-3 text-xs">{e.agent_version}</td>
                        <td className="px-4 py-3 text-xs">{timeAgo(e.last_seen)}</td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>

      </div>

    </div>
  );
}
