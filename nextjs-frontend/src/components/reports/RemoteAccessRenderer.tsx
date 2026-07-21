"use client";

import React, { useEffect, useState } from "react";
import { DoorOpen, User, ArrowRightLeft, ShieldAlert, Shield, ZoomIn } from "lucide-react";
import { safeFetch } from "@/lib/safeFetch";

interface RemoteAccessRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function RemoteAccessRenderer({ timeRange, selectedDevice }: RemoteAccessRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const url = `/api/get_report_remote_access.php?range=${timeRange}&device=${selectedDevice}`;
        const result = await safeFetch<any>(url, {}, "RemoteAccess");
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch remote access report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading remote access data...</div>;
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

  const sessions = data.sessions.slice(0, 30); // show top 30 as in original

  return (
    <div className="space-y-6">
      
      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <DoorOpen className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.sessions.length.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Remote Sessions</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <User className="w-8 h-8 text-indigo-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.unique_users.toLocaleString()}</div>
          <div className="text-xs text-indigo-500 bg-indigo-500/10 px-2 py-1 rounded-full mt-2">Unique Users</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <ArrowRightLeft className="w-8 h-8 text-green-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_events.toLocaleString()}</div>
          <div className="text-xs text-green-500 bg-green-500/10 px-2 py-1 rounded-full mt-2">Total VPN Events</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <ShieldAlert className="w-8 h-8 text-amber-500 mb-2" />
          <div className="text-xl font-bold text-foreground">{data.sessions.length > 0 ? 'MONITORED' : 'IDLE'}</div>
          <div className="text-xs text-amber-500 bg-amber-500/10 px-2 py-1 rounded-full mt-2">Tunnel Status</div>
        </div>
      </div>

      {/* Table */}
      <div className="bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
        <h5 className="flex items-center text-foreground font-semibold mb-4"><Shield className="w-5 h-5 mr-2 text-foreground-muted" /> Remote Access Sessions</h5>
        <div className="overflow-x-auto flex-1">
          <table className="w-full text-sm text-left text-foreground-muted">
            <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
              <tr>
                <th className="px-4 py-3">User</th>
                <th className="px-4 py-3">Source IP</th>
                <th className="px-4 py-3">Tunnel</th>
                <th className="px-4 py-3">Events</th>
                <th className="px-4 py-3">First Seen</th>
                <th className="px-4 py-3">Last Seen</th>
              </tr>
            </thead>
            <tbody>
              {sessions.length === 0 ? (
                <tr><td colSpan={6} className="text-center py-8">No remote access activity in this window</td></tr>
              ) : (
                sessions.map((s: any, idx: number) => (
                  <tr key={idx} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                    <td className="px-4 py-3 font-semibold text-foreground flex items-center">
                      <User className="w-4 h-4 mr-2 text-foreground-muted"/>
                      {s.user}
                    </td>
                    <td className="px-4 py-3 font-mono text-accent-primary"><ZoomIn className="w-3 h-3 mr-1 inline"/> {s.ip}</td>
                    <td className="px-4 py-3"><span className="bg-blue-500/20 text-blue-400 px-2 py-0.5 rounded text-xs font-semibold">{s.tunnel}</span></td>
                    <td className="px-4 py-3">{s.events.toLocaleString()}</td>
                    <td className="px-4 py-3 text-xs">{new Date(s.first).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</td>
                    <td className="px-4 py-3 text-xs">{timeAgo(s.last)}</td>
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
