"use client";

import React, { useEffect, useState } from "react";
import { Line, Doughnut, Bar } from "react-chartjs-2";
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  ArcElement,
  BarElement,
} from "chart.js";
import { 
  ArrowRightLeft, 
  ArrowUp, 
  ArrowDown, 
  Network, 
  LineChart, 
  ChartPie, 
  Layers, 
  Globe, 
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
  ArcElement,
  BarElement
);

interface TrafficReportRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function TrafficReportRenderer({ timeRange, selectedDevice }: TrafficReportRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const url = `/api/get_report_traffic.php?range=${timeRange}&device=${selectedDevice}`;
        const result = await safeFetch<any>(url, {}, "TrafficReport");
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch traffic report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading traffic data...</div>;
  }

  if (!data || data.error) {
    return <div className="text-center p-12 text-red-500">Failed to load data. {data?.error}</div>;
  }

  const formatBytes = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + ['B','KB','MB','GB','TB'][i];
  };

  const bandwidthChartData = {
    labels: data.bandwidth_timeline.labels,
    datasets: [{
      label: 'Bandwidth (Bytes)',
      data: data.bandwidth_timeline.values,
      borderColor: '#29d3ee',
      backgroundColor: 'rgba(41,211,238,0.10)',
      pointBackgroundColor: '#29d3ee',
      pointRadius: data.bandwidth_timeline.values.length <= 3 ? 4 : 0,
      pointHoverRadius: 5,
      borderWidth: 2,
      fill: true,
      tension: 0.35
    }]
  };

  const trafficDistData = {
    labels: ['Accepted', 'Denied', 'Timeout', 'Other'],
    datasets: [{
      data: [
        data.traffic_dist.accepted || 0,
        data.traffic_dist.denied   || 0,
        data.traffic_dist.timeout  || 0,
        data.traffic_dist.other    || 0
      ],
      backgroundColor: ['#2be8a4', '#ff4d5e', '#ffb020', '#56626f'],
      borderColor: '#10151d',
      borderWidth: 2
    }]
  };

  const protocolData = {
    labels: Object.keys(data.protocols),
    datasets: [{
      label: 'Flows',
      data: Object.values(data.protocols),
      backgroundColor: '#ffb020',
      borderRadius: 2,
      maxBarThickness: 34
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
      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <ArrowRightLeft className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_flows.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Total Traffic Flows</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <ArrowUp className="w-8 h-8 text-green-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{formatBytes(data.total_sent)}</div>
          <div className="text-xs text-green-400 bg-green-400/10 px-2 py-1 rounded-full mt-2">Data Sent</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <ArrowDown className="w-8 h-8 text-blue-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{formatBytes(data.total_received)}</div>
          <div className="text-xs text-blue-500 bg-blue-500/10 px-2 py-1 rounded-full mt-2">Data Received</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Network className="w-8 h-8 text-amber-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{formatBytes(data.total_bw_all)}</div>
          <div className="text-xs text-amber-400 bg-amber-400/10 px-2 py-1 rounded-full mt-2">Bandwidth &middot; {data.accept_pct}% Accepted</div>
        </div>
      </div>

      {/* Charts Row */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><LineChart className="w-5 h-5 mr-2 text-foreground-muted" /> Bandwidth Timeline</h5>
          <div className="h-64">
            <Line data={bandwidthChartData} options={chartOptions} />
          </div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><ChartPie className="w-5 h-5 mr-2 text-foreground-muted" /> Traffic Distribution</h5>
          <div className="h-48 relative">
            <Doughnut data={trafficDistData} options={{ maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false } } }} />
          </div>
          <div className="flex justify-center flex-wrap gap-3 mt-4 text-sm text-foreground-muted">
            <span className="flex items-center"><span className="w-2.5 h-2.5 rounded-full bg-[#2be8a4] mr-2"></span>Accepted</span>
            <span className="flex items-center"><span className="w-2.5 h-2.5 rounded-full bg-[#ff4d5e] mr-2"></span>Denied</span>
            <span className="flex items-center"><span className="w-2.5 h-2.5 rounded-full bg-[#ffb020] mr-2"></span>Timeout</span>
            <span className="flex items-center"><span className="w-2.5 h-2.5 rounded-full bg-[#56626f] mr-2"></span>Other</span>
          </div>
        </div>
      </div>

      {/* Tables Row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><ArrowUp className="w-5 h-5 mr-2 text-foreground-muted" /> Top Source IPs</h5>
          <div className="overflow-x-auto flex-1">
            <table className="w-full text-sm text-left text-foreground-muted">
              <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
                <tr><th className="px-4 py-3">Rank</th><th className="px-4 py-3">Source IP</th><th className="px-4 py-3">Flows</th><th className="px-4 py-3">Bandwidth</th></tr>
              </thead>
              <tbody>
                {data.sources.length === 0 ? (
                  <tr><td colSpan={4} className="text-center py-4">No source data</td></tr>
                ) : (
                  data.sources.map((s: any, idx: number) => {
                    const maxBw = data.sources.length > 0 ? data.sources[0].bandwidth : 1;
                    const pct = Math.round((s.bandwidth / maxBw) * 100);
                    return (
                      <tr key={s.ip} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                        <td className="px-4 py-3">{idx + 1}</td>
                        <td className="px-4 py-3 font-mono text-accent-primary flex items-center"><ZoomIn className="w-3 h-3 mr-1"/> {s.ip}</td>
                        <td className="px-4 py-3">{s.count.toLocaleString()}</td>
                        <td className="px-4 py-3">
                          {formatBytes(s.bandwidth)}
                          <div className="w-full bg-border-subtle h-1 mt-1 rounded-full overflow-hidden">
                            <div className="bg-accent-primary h-full" style={{ width: `${pct}%` }}></div>
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
        
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><ArrowDown className="w-5 h-5 mr-2 text-foreground-muted" /> Top Destination IPs</h5>
          <div className="overflow-x-auto flex-1">
            <table className="w-full text-sm text-left text-foreground-muted">
              <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
                <tr><th className="px-4 py-3">Rank</th><th className="px-4 py-3">Destination IP</th><th className="px-4 py-3">Flows</th><th className="px-4 py-3">Bandwidth</th></tr>
              </thead>
              <tbody>
                {data.destinations.length === 0 ? (
                  <tr><td colSpan={4} className="text-center py-4">No destination data</td></tr>
                ) : (
                  data.destinations.map((d: any, idx: number) => {
                    const maxBw = data.destinations.length > 0 ? data.destinations[0].bandwidth : 1;
                    const pct = Math.round((d.bandwidth / maxBw) * 100);
                    return (
                      <tr key={d.ip} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                        <td className="px-4 py-3">{idx + 1}</td>
                        <td className="px-4 py-3 font-mono text-accent-primary flex items-center"><ZoomIn className="w-3 h-3 mr-1"/> {d.ip}</td>
                        <td className="px-4 py-3">{d.count.toLocaleString()}</td>
                        <td className="px-4 py-3">
                          {formatBytes(d.bandwidth)}
                          <div className="w-full bg-border-subtle h-1 mt-1 rounded-full overflow-hidden">
                            <div className="bg-amber-400 h-full" style={{ width: `${pct}%` }}></div>
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
      
      {/* Applications and Protocols */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><Layers className="w-5 h-5 mr-2 text-foreground-muted" /> Top Applications</h5>
          <div className="overflow-x-auto flex-1">
            <table className="w-full text-sm text-left text-foreground-muted">
              <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
                <tr><th className="px-4 py-3">Application</th><th className="px-4 py-3">Flows</th><th className="px-4 py-3">Bandwidth</th><th className="px-4 py-3">% of Total</th></tr>
              </thead>
              <tbody>
                {data.applications.length === 0 ? (
                  <tr><td colSpan={4} className="text-center py-4">No application data</td></tr>
                ) : (
                  data.applications.map((app: any) => (
                    <tr key={app.name} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                      <td className="px-4 py-3 font-semibold text-foreground">{app.name}</td>
                      <td className="px-4 py-3">{app.count.toLocaleString()}</td>
                      <td className="px-4 py-3">{formatBytes(app.bandwidth)}</td>
                      <td className="px-4 py-3">{data.total_bw_all > 0 ? ((app.bandwidth / data.total_bw_all) * 100).toFixed(2) : 0}%</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
        
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><Network className="w-5 h-5 mr-2 text-foreground-muted" /> Protocol Distribution</h5>
          <div className="h-64">
            <Bar data={protocolData} options={chartOptions} />
          </div>
        </div>
      </div>

    </div>
  );
}
