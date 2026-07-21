"use client";

import React, { useEffect, useState } from "react";
import { Wrench, UserCog, History, ClipboardList, ZoomIn } from "lucide-react";
import { safeFetch } from "@/lib/safeFetch";

interface ConfigChangesRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function ConfigChangesRenderer({ timeRange, selectedDevice }: ConfigChangesRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const url = `/api/get_report_config_changes.php?range=${timeRange}&device=${selectedDevice}`;
        const result = await safeFetch<any>(url, {}, "ConfigChanges");
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch config changes report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading config analysis...</div>;
  }

  if (!data || data.error) {
    return <div className="text-center p-12 text-red-500">Failed to load data. {data?.error}</div>;
  }

  const timeAgo = (dateStr: string) => {
    const diff = Math.floor((new Date().getTime() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60) return diff + 's ago';
    if (diff < 3600) return Math.floor(diff / 60) + 'min ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'hr ago';
    return Math.floor(diff / 86400) + 'd ago';
  };

  const byAdmin = Object.entries(data.by_admin);
  const changes = data.changes.slice(0, 40);

  return (
    <div className="space-y-6">
      
      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Wrench className="w-8 h-8 text-amber-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_changes.toLocaleString()}</div>
          <div className="text-xs text-amber-500 bg-amber-500/10 px-2 py-1 rounded-full mt-2">Config Events</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <UserCog className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{byAdmin.length.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Admins Involved</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <History className="w-8 h-8 text-indigo-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">
            {changes.length > 0 ? timeAgo(changes[0].time) : '—'}
          </div>
          <div className="text-xs text-indigo-500 bg-indigo-500/10 px-2 py-1 rounded-full mt-2">Most Recent Change</div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        
        {/* Changes by Admin */}
        <div className="lg:col-span-4 bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><UserCog className="w-5 h-5 mr-2 text-foreground-muted" /> Changes by Admin</h5>
          <div className="overflow-x-auto flex-1 max-h-[340px]">
            <table className="w-full text-sm text-left text-foreground-muted">
              <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle sticky top-0">
                <tr>
                  <th className="px-4 py-3">Admin</th>
                  <th className="px-4 py-3">Changes</th>
                </tr>
              </thead>
              <tbody>
                {byAdmin.length === 0 ? (
                  <tr><td colSpan={2} className="text-center py-8">No config activity</td></tr>
                ) : (
                  byAdmin.map(([admin, count]: [string, any], idx: number) => (
                    <tr key={idx} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                      <td className="px-4 py-3 font-semibold text-foreground">{admin}</td>
                      <td className="px-4 py-3">
                        <span className="bg-amber-500/20 text-amber-500 px-2 py-0.5 rounded text-xs font-semibold">{count.toLocaleString()}</span>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* Change Log */}
        <div className="lg:col-span-8 bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><ClipboardList className="w-5 h-5 mr-2 text-foreground-muted" /> Change Log</h5>
          <div className="overflow-x-auto flex-1 max-h-[340px]">
            <table className="w-full text-sm text-left text-foreground-muted">
              <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle sticky top-0">
                <tr>
                  <th className="px-4 py-3 min-w-[120px]">Time</th>
                  <th className="px-4 py-3">Admin</th>
                  <th className="px-4 py-3">Source</th>
                  <th className="px-4 py-3">Path</th>
                  <th className="px-4 py-3 w-1/3">Summary</th>
                </tr>
              </thead>
              <tbody>
                {changes.length === 0 ? (
                  <tr><td colSpan={5} className="text-center py-8">No configuration changes in this window</td></tr>
                ) : (
                  changes.map((c: any, idx: number) => (
                    <tr key={idx} className="border-b border-border-subtle/50 hover:bg-bg-raised/50 align-top">
                      <td className="px-4 py-3 text-xs whitespace-nowrap">
                        {new Date(c.time).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                      </td>
                      <td className="px-4 py-3 font-semibold text-foreground">{c.admin}</td>
                      <td className="px-4 py-3 font-mono text-accent-primary"><ZoomIn className="w-3 h-3 mr-1 inline"/> {c.source}</td>
                      <td className="px-4 py-3">
                        <code className="text-xs bg-bg-main px-1 py-0.5 rounded border border-border-subtle">{c.path}</code>
                      </td>
                      <td className="px-4 py-3 text-xs">{c.summary}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

      </div>

    </div>
  );
}
