"use client";

import React, { useEffect, useState } from "react";
import { Server, Ban, Route, User, Info, ZoomIn } from "lucide-react";
import { reportsService } from "@/services/reports/reportsService";

interface DnsAttacksRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function DnsAttacksRenderer({ timeRange, selectedDevice }: DnsAttacksRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const result = await reportsService.getDnsAttacksReport({ range: timeRange, device: selectedDevice });
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch DNS attacks report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading DNS analysis...</div>;
  }

  if (!data || data.error) {
    return <div className="text-center p-12 text-red-500">Failed to load data. {data?.error}</div>;
  }

  const clients = data.clients.slice(0, 20);

  return (
    <div className="space-y-6">
      
      <div className="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 flex items-start text-sm text-blue-200">
        <Info className="w-5 h-5 mr-3 flex-shrink-0 text-blue-400" />
        <div>
          "Possible tunneling" flags sources sending an unusual volume of long DNS query names (&gt;50 chars) — a common (but not definitive) exfiltration/tunneling signal. Verify manually before acting.
        </div>
      </div>

      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Server className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_events.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">DNS Events</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Ban className="w-8 h-8 text-red-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.denied.toLocaleString()}</div>
          <div className="text-xs text-red-500 bg-red-500/10 px-2 py-1 rounded-full mt-2">Denied Queries</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Route className="w-8 h-8 text-amber-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.long_query_hits.toLocaleString()}</div>
          <div className="text-xs text-amber-500 bg-amber-500/10 px-2 py-1 rounded-full mt-2">Possible Tunneling Hits</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <User className="w-8 h-8 text-indigo-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.unique_clients.toLocaleString()}</div>
          <div className="text-xs text-indigo-500 bg-indigo-500/10 px-2 py-1 rounded-full mt-2">Unique DNS Clients</div>
        </div>
      </div>

      {/* Table */}
      <div className="bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
        <h5 className="flex items-center text-foreground font-semibold mb-4"><Server className="w-5 h-5 mr-2 text-foreground-muted" /> Top DNS Clients</h5>
        <div className="overflow-x-auto flex-1">
          <table className="w-full text-sm text-left text-foreground-muted">
            <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
              <tr>
                <th className="px-4 py-3">Rank</th>
                <th className="px-4 py-3">Source</th>
                <th className="px-4 py-3">Queries</th>
                <th className="px-4 py-3">Denied</th>
                <th className="px-4 py-3">Long-Name Hits</th>
                <th className="px-4 py-3">Flag</th>
              </tr>
            </thead>
            <tbody>
              {clients.length === 0 ? (
                <tr><td colSpan={6} className="text-center py-8">No DNS activity in this window</td></tr>
              ) : (
                clients.map((s: any, idx: number) => (
                  <tr key={idx} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                    <td className="px-4 py-3">{idx + 1}</td>
                    <td className="px-4 py-3 font-mono text-accent-primary"><ZoomIn className="w-3 h-3 mr-1 inline"/> {s.ip}</td>
                    <td className="px-4 py-3">{s.queries.toLocaleString()}</td>
                    <td className="px-4 py-3">
                      {s.denied > 0 
                        ? <span className="bg-red-500/20 text-red-500 px-2 py-0.5 rounded text-xs font-semibold">{s.denied.toLocaleString()}</span>
                        : '0'
                      }
                    </td>
                    <td className="px-4 py-3">{s.long_queries.toLocaleString()}</td>
                    <td className="px-4 py-3">
                      {s.long_queries > 10 
                        ? <span className="bg-amber-500/10 text-amber-500 border border-amber-500/20 px-2 py-0.5 rounded text-xs font-semibold">TUNNEL SUSPECT</span>
                        : <span className="bg-green-500/10 text-green-500 border border-green-500/20 px-2 py-0.5 rounded text-xs font-semibold">NORMAL</span>
                      }
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

    </div>
  );
}
