"use client";

import React, { useEffect, useState } from "react";
import { Network, PlusCircle, HelpCircle, Shield, List, ZoomIn, Info } from "lucide-react";
import { reportsService } from "@/services/reports/reportsService";

interface AssetDiscoveryRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function AssetDiscoveryRenderer({ timeRange, selectedDevice }: AssetDiscoveryRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const result = await reportsService.getAssetDiscoveryReport({ range: timeRange, device: selectedDevice });
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch asset discovery report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading asset inventory...</div>;
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

  const assets = data.assets.slice(0, 50);

  return (
    <div className="space-y-6">
      
      <div className="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 flex items-start text-sm text-blue-200">
        <Info className="w-5 h-5 mr-3 flex-shrink-0 text-blue-400" />
        <div>
          Assets here are inferred from observed <code>source_ip</code> values in your syslog stream, not a dedicated asset/CMDB scan. Device role is a rough heuristic from sampled log content.
        </div>
      </div>

      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Network className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_assets.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Assets Observed</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <PlusCircle className="w-8 h-8 text-green-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.new_last_24h.toLocaleString()}</div>
          <div className="text-xs text-green-500 bg-green-500/10 px-2 py-1 rounded-full mt-2">First Seen (24h)</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <HelpCircle className="w-8 h-8 text-amber-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.unclassified_count.toLocaleString()}</div>
          <div className="text-xs text-amber-500 bg-amber-500/10 px-2 py-1 rounded-full mt-2">Unclassified</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Shield className="w-8 h-8 text-indigo-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.firewall_count.toLocaleString()}</div>
          <div className="text-xs text-indigo-500 bg-indigo-500/10 px-2 py-1 rounded-full mt-2">Firewalls/Gateways</div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        
        {/* Asset Inventory */}
        <div className="lg:col-span-12 bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><List className="w-5 h-5 mr-2 text-foreground-muted" /> Asset Inventory</h5>
          <div className="overflow-x-auto flex-1">
            <table className="w-full text-sm text-left text-foreground-muted">
              <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
                <tr>
                  <th className="px-4 py-3">IP Address</th>
                  <th className="px-4 py-3">Inferred Role</th>
                  <th className="px-4 py-3">Events</th>
                  <th className="px-4 py-3">First Seen</th>
                  <th className="px-4 py-3">Last Seen</th>
                </tr>
              </thead>
              <tbody>
                {assets.length === 0 ? (
                  <tr><td colSpan={5} className="text-center py-8">No assets observed in this window</td></tr>
                ) : (
                  assets.map((a: any, idx: number) => (
                    <tr key={idx} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                      <td className="px-4 py-3 font-mono text-accent-primary"><ZoomIn className="w-3 h-3 mr-1 inline"/> {a.source_ip}</td>
                      <td className="px-4 py-3">
                        <span className="bg-bg-raised border border-border-subtle px-2 py-1 rounded text-xs text-foreground-muted">
                          {a.role}
                        </span>
                      </td>
                      <td className="px-4 py-3">{a.events.toLocaleString()}</td>
                      <td className="px-4 py-3 text-xs">
                        {new Date(a.first_seen).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                      </td>
                      <td className="px-4 py-3 text-xs">{timeAgo(a.last_seen)}</td>
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
