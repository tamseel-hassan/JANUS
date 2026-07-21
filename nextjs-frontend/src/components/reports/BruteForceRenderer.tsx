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
  Filler,
} from "chart.js";
import {
  Lock,
  UserX,
  User,
  ShieldAlert,
  Server,
  DoorOpen,
  TriangleAlert,
  CheckCircle,
  Ban,
  Settings,
  LineChart,
  ZoomIn,
  Crosshair,
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
  Filler,
);

interface BruteForceRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function BruteForceRenderer({
  timeRange,
  selectedDevice,
}: BruteForceRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const url = `/api/get_report_bruteforce.php?range=${timeRange}&device=${selectedDevice}`;
        const result = await safeFetch<any>(url, {}, "BruteForce");
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch bruteforce report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return (
      <div className="text-center p-12 text-foreground-muted">
        Loading brute force analytics...
      </div>
    );
  }

  if (!data || data.error) {
    return (
      <div className="text-center p-12 text-red-500">
        Failed to load data. {data?.error}
      </div>
    );
  }

  const chartData = {
    labels: data.timeline.labels,
    datasets: [
      {
        label: "Failed Attempts",
        data: data.timeline.values,
        borderColor: "#ff4d5e",
        backgroundColor: "rgba(255,77,94,0.10)",
        pointBackgroundColor: "#ff4d5e",
        pointRadius: data.timeline.values.length <= 3 ? 4 : 0,
        pointHoverRadius: 5,
        borderWidth: 2,
        fill: true,
        tension: 0.3,
      },
    ],
  };

  const chartOptions: any = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, grid: { color: "rgba(255,255,255,0.05)" } },
      x: { grid: { display: false } },
    },
  };

  const timeAgo = (dateStr: string) => {
    const diff = Math.floor(
      (new Date().getTime() - new Date(dateStr).getTime()) / 1000,
    );
    if (diff < 60) return diff + "s ago";
    if (diff < 3600) return Math.floor(diff / 60) + "min ago";
    if (diff < 86400) return Math.floor(diff / 3600) + "hr ago";
    return Math.floor(diff / 86400) + "d ago";
  };

  return (
    <div className="space-y-6">
      {data.has_firewall && (
        <div className="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 flex items-start text-sm text-blue-200">
          <ShieldAlert className="w-5 h-5 mr-3 flex-shrink-0 text-blue-400" />
          <div>
            <strong>Automated Response Available:</strong> Connected to firewall{" "}
            <code>{data.firewall_ip}</code>. You can quarantine attacking IPs
            directly from this page.
          </div>
        </div>
      )}

      {/* Threat Meter */}
      <div className="bg-bg-card border border-border-subtle rounded-xl p-4 flex items-center justify-center">
        {data.critical_count > 0 ? (
          <div className="flex items-center text-red-500 font-semibold text-lg">
            <TriangleAlert className="w-6 h-6 mr-2" />
            {data.critical_count} Critical Threat
            {data.critical_count > 1 ? "s" : ""} Detected
          </div>
        ) : (
          <div className="flex items-center text-green-500 font-semibold text-lg">
            <CheckCircle className="w-6 h-6 mr-2" />
            No Critical Threats in Window
          </div>
        )}
      </div>

      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Lock className="w-8 h-8 text-red-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">
            {data.total_failed.toLocaleString()}
          </div>
          <div className="text-xs text-red-500 bg-red-500/10 px-2 py-1 rounded-full mt-2">
            Failed Auth Attempts
          </div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <UserX className="w-8 h-8 text-amber-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">
            {data.brute_force_attacks.length.toLocaleString()}
          </div>
          <div className="text-xs text-amber-500 bg-amber-500/10 px-2 py-1 rounded-full mt-2">
            Brute Force Attacks
          </div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <User className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">
            {data.top_attackers.length.toLocaleString()}
          </div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">
            Unique Attack Sources
          </div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <ShieldAlert className="w-8 h-8 text-foreground-muted mb-2" />
          <div className="text-xl font-bold text-foreground">
            {data.has_firewall ? "ACTIVE" : "INACTIVE"}
          </div>
          <div className="text-xs text-foreground-muted bg-bg-raised px-2 py-1 rounded-full mt-2">
            Auto-Response Status
          </div>
        </div>
      </div>

      {/* Services and Ports */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4">
            <Server className="w-5 h-5 mr-2 text-foreground-muted" /> Targeted
            Services
          </h5>
          <div className="overflow-x-auto max-h-[300px]">
            <table className="w-full text-sm text-left text-foreground-muted">
              <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle sticky top-0">
                <tr>
                  <th>Service</th>
                  <th>Failed Attempts</th>
                </tr>
              </thead>
              <tbody>
                {Object.entries(data.service_breakdown).length === 0 ? (
                  <tr>
                    <td colSpan={2} className="text-center py-4">
                      No data
                    </td>
                  </tr>
                ) : (
                  Object.entries(data.service_breakdown).map(
                    ([svc, count]: [string, any]) => (
                      <tr
                        key={svc}
                        className="border-b border-border-subtle/50 hover:bg-bg-raised/50"
                      >
                        <td className="py-2 font-semibold text-foreground">
                          {svc}
                        </td>
                        <td className="py-2">
                          <span className="bg-red-500/20 text-red-500 px-2 py-0.5 rounded text-xs font-semibold">
                            {count.toLocaleString()}
                          </span>
                        </td>
                      </tr>
                    ),
                  )
                )}
              </tbody>
            </table>
          </div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4">
            <DoorOpen className="w-5 h-5 mr-2 text-foreground-muted" /> Targeted
            Ports
          </h5>
          <div className="overflow-x-auto max-h-[300px]">
            <table className="w-full text-sm text-left text-foreground-muted">
              <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle sticky top-0">
                <tr>
                  <th>Port</th>
                  <th>Failed Attempts</th>
                </tr>
              </thead>
              <tbody>
                {Object.entries(data.port_breakdown).length === 0 ? (
                  <tr>
                    <td colSpan={2} className="text-center py-4">
                      No data
                    </td>
                  </tr>
                ) : (
                  Object.entries(data.port_breakdown).map(
                    ([port, count]: [string, any]) => (
                      <tr
                        key={port}
                        className="border-b border-border-subtle/50 hover:bg-bg-raised/50"
                      >
                        <td className="py-2">
                          <code className="bg-bg-raised text-accent-primary px-1.5 py-0.5 rounded text-xs">
                            {port}
                          </code>
                        </td>
                        <td className="py-2">
                          <span className="bg-red-500/20 text-red-500 px-2 py-0.5 rounded text-xs font-semibold">
                            {count.toLocaleString()}
                          </span>
                        </td>
                      </tr>
                    ),
                  )
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Incidents Table */}
      <div className="bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
        <h5 className="flex items-center text-foreground font-semibold mb-4">
          <UserX className="w-5 h-5 mr-2 text-foreground-muted" />{" "}
          Authentication Brute Force Incidents
        </h5>
        <div className="overflow-x-auto flex-1">
          <table className="w-full text-sm text-left text-foreground-muted">
            <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
              <tr>
                <th className="px-4 py-3">Severity</th>
                <th className="px-4 py-3">ID</th>
                <th className="px-4 py-3">Incident</th>
                <th className="px-4 py-3">Source</th>
                <th className="px-4 py-3">Target</th>
                <th className="px-4 py-3">Last Occurred</th>
                <th className="px-4 py-3">Attempts</th>
              </tr>
            </thead>
            <tbody>
              {data.brute_force_attacks.length === 0 ? (
                <tr>
                  <td colSpan={7} className="text-center py-4">
                    No brute force attempts detected
                  </td>
                </tr>
              ) : (
                data.brute_force_attacks.map((bf: any) => {
                  let sevColor = "bg-amber-500/10 text-amber-500";
                  if (bf.risk_level === "critical")
                    sevColor = "bg-red-600/10 text-red-600";

                  return (
                    <tr
                      key={bf.id}
                      className="border-b border-border-subtle/50 hover:bg-bg-raised/50 cursor-pointer"
                    >
                      <td className="px-4 py-3">
                        <span
                          className={`px-2 py-1 rounded text-xs font-semibold ${sevColor}`}
                        >
                          {bf.risk_level.toUpperCase()}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <code className="bg-bg-raised px-1.5 py-0.5 rounded text-xs">
                          {bf.id}
                        </code>
                      </td>
                      <td className="px-4 py-3">
                        Brute force attack from {bf.source_ip} —{" "}
                        {bf.attempt_count} failed attempts on {bf.port_name} (
                        {bf.service})
                      </td>
                      <td className="px-4 py-3 font-mono text-accent-primary flex items-center mt-1">
                        <ZoomIn className="w-3 h-3 mr-1" /> {bf.source_ip}
                      </td>
                      <td className="px-4 py-3">
                        <code>{bf.target_ip}</code>
                      </td>
                      <td className="px-4 py-3">
                        <small>{timeAgo(bf.last_attempt)}</small>
                      </td>
                      <td className="px-4 py-3">
                        <span className="bg-red-500/20 text-red-500 px-2 py-0.5 rounded text-xs font-semibold">
                          {bf.attempt_count}
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

      {/* Top Attackers and Timeline */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4">
            <Crosshair className="w-5 h-5 mr-2 text-foreground-muted" /> Top
            Attacking IPs
          </h5>
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left text-foreground-muted">
              <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
                <tr>
                  <th>Rank</th>
                  <th>IP Address</th>
                  <th>Total Attempts</th>
                  <th>Risk Level</th>
                </tr>
              </thead>
              <tbody>
                {data.top_attackers.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="text-center py-4">
                      No attackers detected
                    </td>
                  </tr>
                ) : (
                  data.top_attackers.map((att: any, idx: number) => (
                    <tr
                      key={att.ip}
                      className="border-b border-border-subtle/50 hover:bg-bg-raised/50"
                    >
                      <td className="py-2">{idx + 1}</td>
                      <td className="py-2 font-mono text-accent-primary">
                        <ZoomIn className="w-3 h-3 mr-1 inline" />
                        {att.ip}
                      </td>
                      <td className="py-2">{att.count.toLocaleString()}</td>
                      <td className="py-2">
                        <span
                          className={`px-2 py-0.5 rounded text-xs font-semibold ${att.count > 10 ? "bg-red-500/10 text-red-500" : "bg-amber-500/10 text-amber-500"}`}
                        >
                          {att.count > 10 ? "HIGH" : "MEDIUM"}
                        </span>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5">
          <h5 className="flex items-center text-foreground font-semibold mb-4">
            <LineChart className="w-5 h-5 mr-2 text-foreground-muted" /> Attack
            Timeline
          </h5>
          <div className="h-[250px]">
            <Line data={chartData} options={chartOptions} />
          </div>
        </div>
      </div>
    </div>
  );
}
