"use client";

import React, { useEffect, useState } from "react";
import { Bar, Doughnut } from "react-chartjs-2";
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
} from "chart.js";
import { 
  Layers, 
  ArrowRightLeft, 
  Network, 
  Radiation, 
  ChartBar, 
  Shield 
} from "lucide-react";
import { reportsService } from "@/services/reports/reportsService";

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  ArcElement,
  Title,
  Tooltip,
  Legend
);

interface ApplicationsReportRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function ApplicationsReportRenderer({ timeRange, selectedDevice }: ApplicationsReportRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const result = await reportsService.getApplicationsReport({ range: timeRange, device: selectedDevice });
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch applications report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading application data...</div>;
  }

  if (!data || data.error) {
    return <div className="text-center p-12 text-red-500">Failed to load data. {data?.error}</div>;
  }

  const formatBytes = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + ['B','KB','MB','GB','TB'][i];
  };

  const topApps = data.apps.slice(0, 10);
  const appLabels = topApps.map((a: any) => a.name);
  const appBandwidth = topApps.map((a: any) => a.bandwidth);

  const bandwidthChartData = {
    labels: appLabels,
    datasets: [{
      label: 'Bandwidth',
      data: appBandwidth,
      backgroundColor: '#29d3ee',
      borderRadius: 2,
      maxBarThickness: 30
    }]
  };

  const riskData = {
    labels: Object.keys(data.risk_categories),
    datasets: [{
      data: Object.values(data.risk_categories),
      backgroundColor: ['#2be8a4','#ffb020','#ff8a3d','#ff4d5e','#9d8cff','#56626f'],
      borderColor: '#10151d',
      borderWidth: 2
    }]
  };

  const barOptions: any = {
    indexAxis: 'y',
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { color: 'rgba(255,255,255,0.05)' } },
      y: { grid: { display: false } }
    }
  };

  return (
    <div className="space-y-6">
      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Layers className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_apps.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Distinct Applications</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <ArrowRightLeft className="w-8 h-8 text-blue-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_flows.toLocaleString()}</div>
          <div className="text-xs text-blue-500 bg-blue-500/10 px-2 py-1 rounded-full mt-2">Total Flows</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Network className="w-8 h-8 text-green-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{formatBytes(data.total_bw)}</div>
          <div className="text-xs text-green-400 bg-green-400/10 px-2 py-1 rounded-full mt-2">Total Bandwidth</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Radiation className="w-8 h-8 text-red-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.high_risk_flows.toLocaleString()}</div>
          <div className="text-xs text-red-500 bg-red-500/10 px-2 py-1 rounded-full mt-2">High-Risk App Flows</div>
        </div>
      </div>

      {/* Charts Row */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div className="lg:col-span-7 bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><ChartBar className="w-5 h-5 mr-2 text-foreground-muted" /> Top Applications by Bandwidth</h5>
          <div className="h-64">
            <Bar data={bandwidthChartData} options={barOptions} />
          </div>
        </div>
        <div className="lg:col-span-5 bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><Shield className="w-5 h-5 mr-2 text-foreground-muted" /> Application Risk Distribution</h5>
          <div className="h-64 relative">
            <Doughnut data={riskData} options={{ maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'right' } } }} />
          </div>
        </div>
      </div>

      {/* Table Row */}
      <div className="bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
        <h5 className="flex items-center text-foreground font-semibold mb-4"><Layers className="w-5 h-5 mr-2 text-foreground-muted" /> Application Inventory</h5>
        <div className="overflow-x-auto flex-1">
          <table className="w-full text-sm text-left text-foreground-muted">
            <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
              <tr>
                <th className="px-4 py-3">Application</th>
                <th className="px-4 py-3">Category</th>
                <th className="px-4 py-3">Flows</th>
                <th className="px-4 py-3">Bandwidth</th>
                <th className="px-4 py-3">Unique Sources</th>
                <th className="px-4 py-3">Risk</th>
              </tr>
            </thead>
            <tbody>
              {data.apps.length === 0 ? (
                <tr><td colSpan={6} className="text-center py-4">No application data in this window</td></tr>
              ) : (
                data.apps.map((app: any) => (
                  <tr key={app.name} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                    <td className="px-4 py-3 font-semibold text-foreground">{app.name}</td>
                    <td className="px-4 py-3"><small>{app.category}</small></td>
                    <td className="px-4 py-3">{app.flows.toLocaleString()}</td>
                    <td className="px-4 py-3">{formatBytes(app.bandwidth)}</td>
                    <td className="px-4 py-3">{app.sources_count}</td>
                    <td className="px-4 py-3">
                      <span className="px-2 py-1 rounded text-xs" style={{ background: 'rgba(255,176,32,0.12)', color: '#ffb020' }}>
                        {app.risk}
                      </span>
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
