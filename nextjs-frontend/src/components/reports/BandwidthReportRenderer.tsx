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
  ArrowUp, 
  ArrowDown, 
  Zap, 
  Server,
  ChartArea,
  ZoomIn
} from "lucide-react";
import { safeFetch } from "@/lib/safeFetch";

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

interface BandwidthReportRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function BandwidthReportRenderer({ timeRange, selectedDevice }: BandwidthReportRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const url = `/api/get_report_bandwidth.php?range=${timeRange}&device=${selectedDevice}`;
        const result = await safeFetch<any>(url, {}, "BandwidthReport");
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch bandwidth report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading bandwidth data...</div>;
  }

  if (!data || data.error) {
    return <div className="text-center p-12 text-red-500">Failed to load data. {data?.error}</div>;
  }

  const formatBytes = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + ['B','KB','MB','GB','TB'][i];
  };

  const chartData = {
    labels: data.timeline.labels,
    datasets: [
      {
        label: 'Sent',
        data: data.timeline.sent,
        borderColor: '#2be8a4',
        backgroundColor: 'rgba(43,232,164,0.12)',
        fill: true,
        tension: 0.3,
        pointRadius: 0,
        borderWidth: 2
      },
      {
        label: 'Received',
        data: data.timeline.rcvd,
        borderColor: '#29d3ee',
        backgroundColor: 'rgba(41,211,238,0.12)',
        fill: true,
        tension: 0.3,
        pointRadius: 0,
        borderWidth: 2
      }
    ]
  };

  const chartOptions: any = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: true, labels: { boxWidth: 10 } } },
    scales: {
      y: { beginAtZero: true, stacked: true, grid: { color: 'rgba(255,255,255,0.05)' } },
      x: { stacked: true, grid: { display: false } }
    }
  };

  return (
    <div className="space-y-6">
      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <ArrowUp className="w-8 h-8 text-green-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{formatBytes(data.total_sent)}</div>
          <div className="text-xs text-green-400 bg-green-400/10 px-2 py-1 rounded-full mt-2">Total Sent</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <ArrowDown className="w-8 h-8 text-blue-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{formatBytes(data.total_rcvd)}</div>
          <div className="text-xs text-blue-500 bg-blue-500/10 px-2 py-1 rounded-full mt-2">Total Received</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Zap className="w-8 h-8 text-amber-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.peak_hour ? formatBytes(data.peak_value) : '—'}</div>
          <div className="text-xs text-amber-400 bg-amber-400/10 px-2 py-1 rounded-full mt-2">Peak Volume</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Server className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.active_devices.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Active Devices</div>
        </div>
      </div>

      {/* Chart Row */}
      <div className="bg-bg-card border border-border-subtle rounded-xl p-5">
        <h5 className="flex items-center text-foreground font-semibold mb-4">
          <ChartArea className="w-5 h-5 mr-2 text-foreground-muted" /> 
          Sent vs Received Over Time {data.peak_hour && <span className="ml-2 font-normal text-text-muted text-sm">&middot; peak at {new Date(data.peak_hour).toLocaleString()}</span>}
        </h5>
        <div className="h-[320px]">
          <Line data={chartData} options={chartOptions} />
        </div>
      </div>

      {/* Table Row */}
      <div className="bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
        <h5 className="flex items-center text-foreground font-semibold mb-4"><Server className="w-5 h-5 mr-2 text-foreground-muted" /> Bandwidth by Device</h5>
        <div className="overflow-x-auto flex-1">
          <table className="w-full text-sm text-left text-foreground-muted">
            <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
              <tr>
                <th className="px-4 py-3">Rank</th>
                <th className="px-4 py-3">Device / IP</th>
                <th className="px-4 py-3">Flows</th>
                <th className="px-4 py-3">Sent</th>
                <th className="px-4 py-3">Received</th>
                <th className="px-4 py-3">Total</th>
              </tr>
            </thead>
            <tbody>
              {data.per_device.length === 0 ? (
                <tr><td colSpan={6} className="text-center py-4">No bandwidth data in this window</td></tr>
              ) : (
                data.per_device.map((d: any, idx: number) => {
                  const tot = d.sent + d.rcvd;
                  const maxTot = data.per_device.length > 0 ? (data.per_device[0].sent + data.per_device[0].rcvd) : 1;
                  const pct = Math.round((tot / maxTot) * 100);
                  
                  return (
                    <tr key={d.ip} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                      <td className="px-4 py-3">{idx + 1}</td>
                      <td className="px-4 py-3 font-mono text-accent-primary flex items-center"><ZoomIn className="w-3 h-3 mr-1"/> {d.ip}</td>
                      <td className="px-4 py-3">{d.flows.toLocaleString()}</td>
                      <td className="px-4 py-3">{formatBytes(d.sent)}</td>
                      <td className="px-4 py-3">{formatBytes(d.rcvd)}</td>
                      <td className="px-4 py-3">
                        {formatBytes(tot)}
                        <div className="inline-block w-20 h-1.5 bg-border-subtle rounded-sm ml-2 align-middle overflow-hidden border border-border-subtle">
                          <div className="bg-blue-400 h-full" style={{ width: `${pct}%` }}></div>
                        </div>
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
