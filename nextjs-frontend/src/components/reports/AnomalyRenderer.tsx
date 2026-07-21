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
  TriangleAlert, 
  ChartBar, 
  Ruler, 
  Server, 
  Info,
  LineChart,
  Crosshair,
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

interface AnomalyRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function AnomalyRenderer({ timeRange, selectedDevice }: AnomalyRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const url = `/api/get_report_anomaly.php?range=${timeRange}&device=${selectedDevice}`;
        const result = await safeFetch<any>(url, {}, "Anomaly");
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch anomaly report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading anomaly analysis...</div>;
  }

  if (!data || data.error) {
    return <div className="text-center p-12 text-red-500">Failed to load data. {data?.error}</div>;
  }

  const chartData = {
    labels: data.timeline.labels,
    datasets: [{
      label: 'Events',
      data: data.timeline.values,
      borderColor: '#9d8cff',
      backgroundColor: 'rgba(157,140,255,0.10)',
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

  const getScoreColor = (score: number) => {
    if (score >= 70) return 'bg-red-500/20 text-red-500';
    if (score >= 45) return 'bg-amber-500/20 text-amber-500';
    return 'bg-green-500/20 text-green-500';
  };

  return (
    <div className="space-y-6">
      
      <div className="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 flex items-start text-sm text-blue-200">
        <Info className="w-5 h-5 mr-3 flex-shrink-0 text-blue-400" />
        <div>
          Anomaly = a source whose event volume this window is <strong>2 or more standard deviations</strong> above the mean across all reporting sources — a lightweight z-score outlier check, not a trained baseline model.
        </div>
      </div>

      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <TriangleAlert className="w-8 h-8 text-red-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.anomalies.length.toLocaleString()}</div>
          <div className="text-xs text-red-500 bg-red-500/10 px-2 py-1 rounded-full mt-2">Anomalous Sources</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <ChartBar className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.mean.toLocaleString()}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Mean Events / Source</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Ruler className="w-8 h-8 text-amber-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.stddev.toLocaleString()}</div>
          <div className="text-xs text-amber-400 bg-amber-400/10 px-2 py-1 rounded-full mt-2">Std. Deviation</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Server className="w-8 h-8 text-blue-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.analyzed_count.toLocaleString()}</div>
          <div className="text-xs text-blue-500 bg-blue-500/10 px-2 py-1 rounded-full mt-2">Sources Analyzed</div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div className="lg:col-span-4 bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><LineChart className="w-5 h-5 mr-2 text-foreground-muted" /> Total Event Volume</h5>
          <div className="h-64">
            <Line data={chartData} options={chartOptions} />
          </div>
        </div>

        <div className="lg:col-span-8 bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
          <h5 className="flex items-center text-foreground font-semibold mb-4"><Crosshair className="w-5 h-5 mr-2 text-foreground-muted" /> Detected Anomalies</h5>
          <div className="overflow-x-auto flex-1">
            <table className="w-full text-sm text-left text-foreground-muted">
              <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
                <tr>
                  <th className="px-4 py-3">Source</th>
                  <th className="px-4 py-3">Event Count</th>
                  <th className="px-4 py-3">Z-Score</th>
                  <th className="px-4 py-3">Deviation</th>
                </tr>
              </thead>
              <tbody>
                {data.anomalies.length === 0 ? (
                  <tr><td colSpan={4} className="text-center py-8">No statistical outliers detected in this window</td></tr>
                ) : (
                  data.anomalies.map((a: any) => {
                    const score = Math.min(99, Math.round(a.z * 25));
                    return (
                      <tr key={a.ip} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                        <td className="px-4 py-3 font-mono text-accent-primary"><ZoomIn className="w-3 h-3 mr-1 inline"/> {a.ip}</td>
                        <td className="px-4 py-3">{a.count.toLocaleString()}</td>
                        <td className="px-4 py-3">
                          <span className={`px-2 py-0.5 rounded text-xs font-semibold ${getScoreColor(score)}`}>{a.z}</span>
                        </td>
                        <td className="px-4 py-3">
                          <span className={`px-2 py-1 rounded text-xs font-semibold ${a.z >= 3 ? 'bg-red-500/10 text-red-500' : 'bg-amber-500/10 text-amber-500'}`}>
                            {a.z >= 3 ? 'SEVERE' : 'ELEVATED'}
                          </span>
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

    </div>
  );
}
