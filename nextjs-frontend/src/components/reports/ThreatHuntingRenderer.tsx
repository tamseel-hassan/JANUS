"use client";

import React, { useEffect, useState } from "react";
import { Crosshair, Biohazard, TriangleAlert, Info, Search, ZoomIn } from "lucide-react";
import { safeFetch } from "@/lib/safeFetch";

interface ThreatHuntingRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function ThreatHuntingRenderer({ timeRange, selectedDevice }: ThreatHuntingRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [activeSearch, setActiveSearch] = useState("");

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const url = `/api/get_report_threat_hunting.php?range=${timeRange}&device=${selectedDevice}&q=${encodeURIComponent(activeSearch)}`;
        const result = await safeFetch<any>(url, {}, "ThreatHunting");
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch threat hunting report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice, activeSearch]);

  if (loading && !data) {
    return <div className="text-center p-12 text-foreground-muted">Loading threat intelligence data...</div>;
  }

  if (!data || data.error) {
    return <div className="text-center p-12 text-red-500">Failed to load data. {data?.error}</div>;
  }

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    setActiveSearch(searchQuery);
  };

  return (
    <div className="space-y-6">
      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Crosshair className="w-8 h-8 text-red-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_notable.toLocaleString()}</div>
          <div className="text-xs text-red-500 bg-red-500/10 px-2 py-1 rounded-full mt-2">Notable Events</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Biohazard className="w-8 h-8 text-red-600 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.severity_counts.critical.toLocaleString()}</div>
          <div className="text-xs text-red-600 bg-red-600/10 px-2 py-1 rounded-full mt-2">Critical</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <TriangleAlert className="w-8 h-8 text-amber-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.severity_counts.high.toLocaleString()}</div>
          <div className="text-xs text-amber-500 bg-amber-500/10 px-2 py-1 rounded-full mt-2">High</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Info className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.severity_counts.medium.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Medium</div>
        </div>
      </div>

      {/* Table Row */}
      <div className="bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
        <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-4">
          <h5 className="flex items-center text-foreground font-semibold">
            <Search className="w-5 h-5 mr-2 text-foreground-muted" /> 
            Notable Event Feed
          </h5>
          <form onSubmit={handleSearch} className="flex gap-2">
            <input 
              type="text" 
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="bg-bg-main border border-border-subtle text-foreground text-sm rounded-md px-3 py-1.5 focus:outline-none focus:border-accent-primary w-64"
              placeholder="Search message content..."
            />
            <button type="submit" className="bg-bg-main border border-border-subtle text-foreground hover:bg-bg-raised hover:text-accent-primary px-3 py-1.5 rounded-md transition-colors">
              <Search className="w-4 h-4" />
            </button>
          </form>
        </div>
        
        <div className="overflow-x-auto flex-1 max-h-[520px]">
          <table className="w-full text-sm text-left text-foreground-muted">
            <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle sticky top-0">
              <tr>
                <th className="px-4 py-3">Time</th>
                <th className="px-4 py-3">Severity</th>
                <th className="px-4 py-3">Source</th>
                <th className="px-4 py-3">Action</th>
                <th className="px-4 py-3">Summary</th>
              </tr>
            </thead>
            <tbody>
              {data.events.length === 0 ? (
                <tr><td colSpan={5} className="text-center py-8">No notable events matched in this window</td></tr>
              ) : (
                data.events.map((e: any, idx: number) => {
                  let sevColor = "bg-blue-400/10 text-blue-400";
                  if (e.severity === 'critical') sevColor = "bg-red-600/10 text-red-600";
                  if (e.severity === 'high') sevColor = "bg-amber-500/10 text-amber-500";
                  
                  return (
                    <tr key={idx} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                      <td className="px-4 py-3 whitespace-nowrap text-xs">{new Date(e.time).toLocaleString()}</td>
                      <td className="px-4 py-3">
                        <span className={`px-2 py-1 rounded text-xs font-semibold ${sevColor}`}>
                          {e.severity.toUpperCase()}
                        </span>
                      </td>
                      <td className="px-4 py-3 font-mono text-accent-primary whitespace-nowrap"><ZoomIn className="w-3 h-3 inline mr-1"/> {e.source}</td>
                      <td className="px-4 py-3 text-foreground whitespace-nowrap">{e.action}</td>
                      <td className="px-4 py-3 font-mono text-xs truncate max-w-md" title={e.summary}>{e.summary}</td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
