"use client";

import React, { useEffect, useState } from "react";
import { Line } from "react-chartjs-2";
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
} from "chart.js";
import { 
  Radiation, 
  Server, 
  Hash, 
  ShieldAlert, 
  ShieldCheck,
  LineChart,
  Settings,
  Info,
  ZoomIn
} from "lucide-react";
import { reportsService } from "@/services/reports/reportsService";

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
);

interface DdosRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function DdosRenderer({ timeRange, selectedDevice }: DdosRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const result = await reportsService.getDdosReport({ range: timeRange, device: selectedDevice });
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch DDoS report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading DDoS analysis...</div>;
  }

  if (!data || data.error) {
    return <div className="text-center p-12 text-red-500">Failed to load data. {data?.error}</div>;
  }

  const chartData = {
    labels: data.timeline.labels,
    datasets: [{
      label: 'Events',
      data: data.timeline.values,
      borderColor: '#ff4d5e',
      backgroundColor: 'rgba(255,77,94,0.10)',
      fill: true,
      tension: 0.3,
      pointRadius: 0,
      borderWidth: 2
    }]
  };

  const chartOptions: any = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } },
      x: { grid: { display: false } }
    }
  };

  return (
    <div className="space-y-6">
      
      <div className="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 flex items-start text-sm text-blue-200">
        <Info className="w-5 h-5 mr-3 flex-shrink-0 text-blue-400" />
        <div>
          Flood threshold: <strong>100+ events from one source within a single minute</strong>. Adjust the threshold in the API to match your baseline traffic levels.
        </div>
      </div>

      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Radiation className="w-8 h-8 text-red-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.floods.length.toLocaleString()}</div>
          <div className="text-xs text-red-500 bg-red-500/10 px-2 py-1 rounded-full mt-2">Flood Windows Detected</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Server className="w-8 h-8 text-amber-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.affected_sources.toLocaleString()}</div>
          <div className="text-xs text-amber-500 bg-amber-500/10 px-2 py-1 rounded-full mt-2">Sources Involved</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Hash className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_flood_events.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Events in Flood Windows</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          {data.floods.length === 0 ? (
            <ShieldCheck className="w-8 h-8 text-green-500 mb-2" />
          ) : (
            <ShieldAlert className="w-8 h-8 text-red-500 mb-2" />
          )}
          <div className="text-2xl font-bold text-foreground">{data.floods.length === 0 ? 'CLEAR' : 'ACTIVE'}</div>
          <div className={`text-xs px-2 py-1 rounded-full mt-2 ${data.floods.length === 0 ? 'bg-green-500/10 text-green-500' : 'bg-red-500/10 text-red-500'}`}>DDoS Status</div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div className="lg:col-span-12 bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><LineChart className="w-5 h-5 mr-2 text-foreground-muted" /> Total Traffic Volume</h5>
          <div className="h-64">
            <Line data={chartData} options={chartOptions} />
          </div>
        </div>
      </div>

      {/* Table */}
      <div className="bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
        <h5 className="flex items-center text-foreground font-semibold mb-4"><Radiation className="w-5 h-5 mr-2 text-foreground-muted" /> Detected Flood Windows</h5>
        <div className="overflow-x-auto flex-1">
          <table className="w-full text-sm text-left text-foreground-muted">
            <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
              <tr>
                <th className="px-4 py-3">Source</th>
                <th className="px-4 py-3">Minute</th>
                <th className="px-4 py-3">Events/min</th>
                <th className="px-4 py-3">Severity</th>
                <th className="px-4 py-3">Actions</th>
              </tr>
            </thead>
            <tbody>
              {data.floods.length === 0 ? (
                <tr><td colSpan={5} className="text-center py-8">No volumetric flood windows detected</td></tr>
              ) : (
                data.floods.map((f: any, idx: number) => {
                  const sev = f.cnt >= 500 ? 'critical' : 'high';
                  const sevColor = sev === 'critical' ? 'bg-red-600/10 text-red-600' : 'bg-amber-500/10 text-amber-500';

                  return (
                    <tr key={idx} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                      <td className="px-4 py-3 font-mono text-accent-primary"><ZoomIn className="w-3 h-3 mr-1 inline"/> {f.source_ip}</td>
                      <td className="px-4 py-3">{f.minute_bucket}</td>
                      <td className="px-4 py-3">
                        <span className="bg-red-500/20 text-red-500 px-2 py-0.5 rounded text-xs font-semibold">{f.cnt.toLocaleString()}</span>
                      </td>
                      <td className="px-4 py-3">
                        <span className={`px-2 py-1 rounded text-xs font-semibold ${sevColor}`}>{sev.toUpperCase()}</span>
                      </td>
                      <td className="px-4 py-3">
                        <button className="bg-bg-main border border-amber-500/50 text-amber-500 hover:bg-amber-500/10 px-2 py-1 rounded text-xs transition-colors" title="Configure Firewall">
                          <Settings className="w-4 h-4" />
                        </button>
                      </td>
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
