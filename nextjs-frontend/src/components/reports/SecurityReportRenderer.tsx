"use client";

import React, { useEffect, useState } from "react";
import { Doughnut, Bar } from "react-chartjs-2";
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
  ShieldAlert, 
  Crosshair, 
  Network, 
  Info, 
  ChartPie, 
  List,
  Shield,
  ZoomIn
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

interface SecurityReportRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function SecurityReportRenderer({ timeRange, selectedDevice }: SecurityReportRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const result = await reportsService.getSecurityAnalysisReport({ range: timeRange, device: selectedDevice });
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch security report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading security data...</div>;
  }

  if (!data || data.error) {
    return <div className="text-center p-12 text-red-500">Failed to load data. {data?.error}</div>;
  }

  const tacticData = {
    labels: Object.keys(data.tactic_counts),
    datasets: [{
      data: Object.values(data.tactic_counts),
      backgroundColor: ['#ff4d5e','#ffb020','#29d3ee','#2be8a4','#9d8cff','#ff8a3d','#56626f'],
      borderColor: '#10151d',
      borderWidth: 2
    }]
  };

  const topTechniques = data.techniques.slice(0, 8);
  const techLabels = topTechniques.map((t: any) => t.technique);
  const techCounts = topTechniques.map((t: any) => t.count);

  const techniqueData = {
    labels: techLabels,
    datasets: [{
      label: 'Events',
      data: techCounts,
      backgroundColor: '#ffb020',
      borderRadius: 2,
      maxBarThickness: 28
    }]
  };

  const chartOptions: any = {
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
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <ShieldAlert className="w-8 h-8 text-red-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.total_events.toLocaleString()}</div>
          <div className="text-xs text-red-500 bg-red-500/10 px-2 py-1 rounded-full mt-2">Classified Events</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Crosshair className="w-8 h-8 text-amber-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.techniques_count.toLocaleString()}</div>
          <div className="text-xs text-amber-400 bg-amber-400/10 px-2 py-1 rounded-full mt-2">Techniques Observed</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Network className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.tactics_count.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Tactics Observed</div>
        </div>
      </div>

      <div className="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 flex items-start text-sm text-blue-200">
        <Info className="w-5 h-5 mr-3 flex-shrink-0 text-blue-400" />
        <div>
          Technique mapping is keyword-heuristic (built from message content, not native MITRE tagging). Tune the rules in the backend to match your device's real log fields for higher fidelity.
        </div>
      </div>

      {/* Charts Row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><ChartPie className="w-5 h-5 mr-2 text-foreground-muted" /> Events by Tactic</h5>
          <div className="h-64 relative">
            <Doughnut data={tacticData} options={{ maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'right' } } }} />
          </div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><List className="w-5 h-5 mr-2 text-foreground-muted" /> Top Techniques</h5>
          <div className="h-64">
            <Bar data={techniqueData} options={chartOptions} />
          </div>
        </div>
      </div>

      {/* Technique Detail Table */}
      <div className="bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
        <h5 className="flex items-center text-foreground font-semibold mb-4"><Shield className="w-5 h-5 mr-2 text-foreground-muted" /> Technique Detail</h5>
        <div className="overflow-x-auto">
          <table className="w-full text-sm text-left text-foreground-muted">
            <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
              <tr>
                <th className="px-4 py-3">ID</th>
                <th className="px-4 py-3">Technique</th>
                <th className="px-4 py-3">Tactic</th>
                <th className="px-4 py-3">Events</th>
                <th className="px-4 py-3">Sources</th>
                <th className="px-4 py-3"></th>
              </tr>
            </thead>
            <tbody>
              {data.techniques.length === 0 ? (
                <tr><td colSpan={6} className="text-center py-4">No mapped technique activity in this window</td></tr>
              ) : (
                data.techniques.map((t: any) => (
                  <tr key={t.id} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                    <td className="px-4 py-3">
                      <code className="bg-bg-raised text-accent-primary px-2 py-1 rounded text-xs">{t.id}</code>
                    </td>
                    <td className="px-4 py-3 text-foreground">{t.technique}</td>
                    <td className="px-4 py-3">
                      <span className="bg-bg-raised border border-border-subtle px-2 py-1 rounded-full text-xs text-foreground-muted">{t.tactic}</span>
                    </td>
                    <td className="px-4 py-3">{t.count.toLocaleString()}</td>
                    <td className="px-4 py-3">{t.sources.toLocaleString()}</td>
                    <td className="px-4 py-3 text-right">
                      <button className="text-foreground-muted hover:text-accent-primary transition-colors">
                        <ZoomIn className="w-4 h-4" />
                      </button>
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
