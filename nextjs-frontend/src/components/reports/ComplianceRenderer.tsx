"use client";

import React, { useEffect, useState } from "react";
import { ClipboardCheck, Server, Lock, Ban, Info } from "lucide-react";
import { reportsService } from "@/services/reports/reportsService";

interface ComplianceRendererProps {
  timeRange: string;
  selectedDevice: string;
}

export default function ComplianceRenderer({ timeRange, selectedDevice }: ComplianceRendererProps) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const result = await reportsService.getComplianceReport({ range: timeRange, device: selectedDevice });
        if (result) {
          setData(result);
        }
      } catch (err) {
        console.error("Failed to fetch compliance report:", err);
      }
      setLoading(false);
    };
    fetchData();
  }, [timeRange, selectedDevice]);

  if (loading) {
    return <div className="text-center p-12 text-foreground-muted">Loading compliance posture...</div>;
  }

  if (!data || data.error) {
    return <div className="text-center p-12 text-red-500">Failed to load data. {data?.error}</div>;
  }

  const scoreClass = data.score >= 80 ? 'text-green-500 bg-green-500/10' : (data.score >= 50 ? 'text-amber-500 bg-amber-500/10' : 'text-red-500 bg-red-500/10');
  const scoreIconClass = data.score >= 80 ? 'text-green-500' : (data.score >= 50 ? 'text-amber-500' : 'text-red-500');

  return (
    <div className="space-y-6">
      
      <div className="bg-blue-500/10 border border-blue-500/20 rounded-xl p-4 flex items-start text-sm text-blue-200">
        <Info className="w-5 h-5 mr-3 flex-shrink-0 text-blue-400" />
        <div>
          This is a lightweight posture scorecard built from what this SIEM already observes — not a certified PCI-DSS / ISO 27001 / SOC 2 mapping. Treat the score as directional.
        </div>
      </div>

      {/* Summary Statistics */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <ClipboardCheck className={`w-8 h-8 mb-2 ${scoreIconClass}`} />
          <div className="text-2xl font-bold text-foreground">{data.score}%</div>
          <div className={`text-xs px-2 py-1 rounded-full mt-2 font-semibold ${scoreClass}`}>Posture Score</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Server className="w-8 h-8 text-blue-400 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.devices_reporting}</div>
          <div className="text-xs text-blue-400 bg-blue-400/10 px-2 py-1 rounded-full mt-2">Devices Reporting</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Lock className="w-8 h-8 text-amber-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.auth_fail_ratio}%</div>
          <div className="text-xs text-amber-500 bg-amber-500/10 px-2 py-1 rounded-full mt-2">Auth Failure Ratio</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-5 flex flex-col items-center justify-center">
          <Ban className="w-8 h-8 text-indigo-500 mb-2" />
          <div className="text-2xl font-bold text-foreground">{data.deny_ratio}%</div>
          <div className="text-xs text-indigo-500 bg-indigo-500/10 px-2 py-1 rounded-full mt-2">Traffic Denied</div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        
        {/* Posture Checklist */}
        <div className="lg:col-span-12 bg-bg-card border border-border-subtle rounded-xl p-5 overflow-hidden flex flex-col">
          <h5 className="flex items-center text-foreground font-semibold mb-4">
            <ClipboardCheck className="w-5 h-5 mr-2 text-foreground-muted" /> Posture Checklist
          </h5>
          <div className="overflow-x-auto flex-1">
            <table className="w-full table-fixed text-sm text-left text-foreground-muted">
              <thead className="text-xs uppercase bg-bg-raised text-foreground border-b border-border-subtle">
                <tr>
                  <th className="px-4 py-3 w-1/3">Check</th>
                  <th className="px-4 py-3 w-32">Status</th>
                  <th className="px-4 py-3">Detail</th>
                </tr>
              </thead>
              <tbody>
                {data.checks.map((c: any, idx: number) => (
                  <tr key={idx} className="border-b border-border-subtle/50 hover:bg-bg-raised/50">
                    <td className="px-4 py-3 font-semibold text-foreground truncate" title={c.label}>{c.label}</td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-1 rounded text-xs font-semibold ${c.pass ? 'bg-green-500/20 text-green-500' : 'bg-red-500/20 text-red-500'}`}>
                        {c.pass ? 'PASS' : 'ATTENTION'}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-xs text-foreground-muted truncate" title={c.detail}>{c.detail}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

      </div>

    </div>
  );
}
