"use client";

import React, { useEffect, useState } from "react";
import { User, List, Lock, MapPin } from "lucide-react";
import { safeFetch } from "@/lib/safeFetch";

interface UserActivityRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function UserActivityRenderer({ timeRange, selectedDevice }: UserActivityRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const url = `/api/get_report_user_activity.php?range=${timeRange}&device=${selectedDevice}`;
        const result = await safeFetch<any>(url, {}, "UserActivity");
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch user activity report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading user activity data...</div>;
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

  const users = data.users.slice(0, 30); // show top 30

  return (
    <div className="space-y-6">
      
      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <User className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.active_users.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Active Users</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <List className="w-8 h-8 text-indigo-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_events.toLocaleString()}</div>
          <div className="text-xs text-indigo-500 bg-indigo-500/10 px-2 py-1 rounded-full mt-2">Total Events</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Lock className="w-8 h-8 text-red-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_failed.toLocaleString()}</div>
          <div className="text-xs text-red-500 bg-red-500/10 px-2 py-1 rounded-full mt-2">Failed Actions</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <MapPin className="w-8 h-8 text-amber-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.max_ips_per_user.toLocaleString()}</div>
          <div className="text-xs text-amber-500 bg-amber-500/10 px-2 py-1 rounded-full mt-2">Most IPs / User</div>
        </div>
      </div>

      {/* Table */}
      <div className="bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
        <h5 className="flex items-center text-foreground font-semibold mb-4"><User className="w-5 h-5 mr-2 text-foreground-muted" /> User Activity Summary</h5>
        <div className="overflow-x-auto flex-1">
          <table className="w-full text-sm text-left text-foreground-muted">
            <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
              <tr>
                <th className="px-4 py-3">User</th>
                <th className="px-4 py-3">Events</th>
                <th className="px-4 py-3">Failed</th>
                <th className="px-4 py-3">Source IPs Used</th>
                <th className="px-4 py-3">Last Activity</th>
              </tr>
            </thead>
            <tbody>
              {users.length === 0 ? (
                <tr><td colSpan={5} className="text-center py-8">No user-attributed activity in this window</td></tr>
              ) : (
                users.map((u: any, idx: number) => (
                  <tr key={idx} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                    <td className="px-4 py-3 font-semibold text-foreground flex items-center">
                      <User className="w-4 h-4 mr-2 text-foreground-muted"/>
                      {u.user}
                    </td>
                    <td className="px-4 py-3">{u.events.toLocaleString()}</td>
                    <td className="px-4 py-3">
                      {u.failed > 0 
                        ? <span className="bg-red-500/20 text-red-500 px-2 py-0.5 rounded text-xs font-semibold">{u.failed.toLocaleString()}</span>
                        : <span className="bg-green-500/20 text-green-500 px-2 py-0.5 rounded text-xs font-semibold">0</span>
                      }
                    </td>
                    <td className="px-4 py-3">
                      {u.ips.length} 
                      <span className="text-xs text-foreground-muted ml-2">
                        ({u.ips.slice(0, 3).join(', ')}{u.ips.length > 3 ? '…' : ''})
                      </span>
                    </td>
                    <td className="px-4 py-3 text-xs">{timeAgo(u.last)}</td>
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
