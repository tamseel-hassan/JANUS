"use client";

import React, { useState, useEffect } from 'react';
import { safeFetch } from '@/lib/safeFetch';
import { reportsService } from '@/services/reports/reportsService';
import { SectionCard } from '@/components/ui/SectionCard';
import { Button } from '@/components/ui/Button';
import { 
  Network, Layers, TriangleAlert, Ban, Shield, ArrowUp, User, Server, Database,
  ArrowRight, AlertCircle, Clock, LineChart, ChartPie, RefreshCw
} from 'lucide-react';
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
  Filler
} from 'chart.js';
import { Line, Doughnut } from 'react-chartjs-2';

ChartJS.register(
  CategoryScale, LinearScale, PointElement, LineElement,
  Title, Tooltip, Legend, ArcElement, Filler
);

interface ProtocolStat {
  name: string;
  count: number;
  bandwidth: number;
  unique_sources: number;
  unique_targets: number;
  risk: 'low' | 'medium' | 'high' | 'critical';
  icon: string;
  color: string;
  accepted: number;
  denied: number;
}

interface SourceStat {
  ip: string;
  protocols: Record<string, number>;
  targets: Record<string, number>;
  total_connections: number;
  total_bandwidth: number;
  denied_count: number;
  last_seen: string;
}

interface DestinationStat {
  ip: string;
  protocols: Record<string, number>;
  sources: Record<string, boolean>;
  total_connections: number;
  total_bandwidth: number;
  ports_accessed: Record<string, boolean>;
}

interface RiskyConnection {
  protocol: string;
  source: string;
  destination: string;
  port: string;
  action: string;
  bandwidth: number;
  duration: number;
  timestamp: string;
  risk_reasons: string[];
  severity: 'low' | 'medium' | 'high' | 'critical';
}

interface UnauthorizedAttempt {
  protocol: string;
  source: string;
  destination: string;
  port: string;
  timestamp: string;
  action: string;
}

interface RemoteAccessData {
  summary: {
    total_connections: number;
    total_protocols: number;
    critical_risk_count: number;
    unauthorized_count: number;
  };
  protocol_stats: ProtocolStat[];
  top_sources: SourceStat[];
  top_destinations: DestinationStat[];
  longest_sessions: any[];
  risky_connections: RiskyConnection[];
  unauthorized_attempts: UnauthorizedAttempt[];
  timeline: Record<string, Record<string, number>>;
  remote_protocols: string[];
}

function formatBytes(bytes: number) {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

const protocolColors: Record<string, string> = {
  'SSH': '#3b82f6',
  'Telnet': '#ef4444',
  'RDP': '#f59e0b',
  'VNC': '#f59e0b',
  'TightVNC': '#f59e0b',
  'TeamViewer': '#3b82f6',
  'AnyDesk': '#3b82f6',
  'SFTP': '#10b981',
  'FTP': '#f59e0b',
  'WinRM': '#3b82f6',
  'X11': '#3b82f6'
};

export function RemoteAccess() {
  const [data, setData] = useState<RemoteAccessData | null>(null);
  const [activeTab, setActiveTab] = useState<'protocols' | 'sources' | 'destinations' | 'risky'>('protocols');
  const [loading, setLoading] = useState(true);

  const fetchData = async () => {
    setLoading(true);
    const res = await reportsService.getRemoteAccessReport();
    if (res) {
      setData(res as any);
    }
    setLoading(false);
  };

  useEffect(() => {
    fetchData();
  }, []);

  if (loading && !data) {
    return (
      <div className="flex h-[calc(100vh-8rem)] items-center justify-center">
        <div className="flex flex-col items-center">
          <RefreshCw className="w-8 h-8 text-accent-primary animate-spin mb-4" />
          <p className="text-text-muted">Loading remote access data...</p>
        </div>
      </div>
    );
  }

  if (!data) return null;

  // Chart Data
  const timelineLabels = Object.keys(data.timeline);
  const timelineDatasets = data.remote_protocols.map(proto => ({
    label: proto,
    data: timelineLabels.map(hour => data.timeline[hour][proto] || 0),
    borderColor: protocolColors[proto] || '#888',
    backgroundColor: (protocolColors[proto] || '#888') + '33',
    tension: 0.3,
  })).filter(ds => ds.data.some(val => val > 0)); // Only show protocols with activity

  const activeStats = data.protocol_stats.filter(s => s.count > 0);
  const doughnutData = {
    labels: activeStats.map(s => s.name),
    datasets: [{
      data: activeStats.map(s => s.count),
      backgroundColor: activeStats.map(s => protocolColors[s.name] || '#888'),
      borderWidth: 0
    }]
  };

  const getRiskColor = (risk: string) => {
    switch(risk) {
      case 'critical': return 'bg-red-500/20 text-red-400 border-red-500/50';
      case 'high': return 'bg-orange-500/20 text-orange-400 border-orange-500/50';
      case 'medium': return 'bg-blue-500/20 text-blue-400 border-blue-500/50';
      case 'low': return 'bg-green-500/20 text-green-400 border-green-500/50';
      default: return 'bg-gray-500/20 text-gray-400';
    }
  };

  return (
    <div className="space-y-6 max-w-[1600px] mx-auto pb-10">
      <div className="flex justify-between items-end">
        <div>
          <h1 className="text-3xl font-bold text-foreground">Remote Access Monitor</h1>
          <p className="text-text-muted mt-1">Track remote protocols like SSH, RDP, VNC, and Telnet</p>
        </div>
        <Button variant="outline" onClick={fetchData} disabled={loading}>
          <RefreshCw className={`w-4 h-4 mr-2 ${loading ? 'animate-spin' : ''}`} />
          Refresh Data
        </Button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-bg-card border border-border-subtle rounded-xl p-6 flex flex-col justify-center items-center text-center">
          <Network className="w-8 h-8 text-accent-primary mb-3" />
          <div className="text-3xl font-bold text-foreground">{data.summary.total_connections.toLocaleString()}</div>
          <div className="text-sm text-text-muted mt-1 uppercase tracking-wider font-semibold">Remote Connections</div>
        </div>
        <div className="bg-bg-card border border-border-subtle rounded-xl p-6 flex flex-col justify-center items-center text-center">
          <Layers className="w-8 h-8 text-blue-400 mb-3" />
          <div className="text-3xl font-bold text-foreground">{data.summary.total_protocols.toLocaleString()}</div>
          <div className="text-sm text-text-muted mt-1 uppercase tracking-wider font-semibold">Active Protocols</div>
        </div>
        <div className="bg-bg-card border border-red-500/30 rounded-xl p-6 flex flex-col justify-center items-center text-center shadow-[0_0_15px_rgba(239,68,68,0.1)]">
          <TriangleAlert className="w-8 h-8 text-red-400 mb-3" />
          <div className="text-3xl font-bold text-foreground">{data.risky_connections.length.toLocaleString()}</div>
          <div className="text-sm text-text-muted mt-1 uppercase tracking-wider font-semibold">Risky Connections</div>
        </div>
        <div className="bg-bg-card border border-orange-500/30 rounded-xl p-6 flex flex-col justify-center items-center text-center shadow-[0_0_15px_rgba(245,158,11,0.1)]">
          <Ban className="w-8 h-8 text-orange-400 mb-3" />
          <div className="text-3xl font-bold text-foreground">{data.summary.unauthorized_count.toLocaleString()}</div>
          <div className="text-sm text-text-muted mt-1 uppercase tracking-wider font-semibold">Unauthorized Attempts</div>
        </div>
      </div>

      {data.summary.critical_risk_count > 0 && (
        <div className="bg-red-500/10 border border-red-500/50 rounded-xl p-4 flex gap-4 items-center">
          <div className="bg-red-500/20 p-3 rounded-full">
            <TriangleAlert className="w-6 h-6 text-red-400" />
          </div>
          <div>
            <h4 className="font-bold text-red-400 text-lg">Security Warning</h4>
            <p className="text-red-400/80">Detected {data.summary.critical_risk_count} critical-risk protocol(s) in use (Telnet, unencrypted connections). Review immediately.</p>
          </div>
        </div>
      )}

      <SectionCard header={<h3 className="font-semibold flex items-center gap-2"><Shield className="w-5 h-5 text-accent-primary" /> Remote Access Protocols Detected</h3>}>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {data.protocol_stats.filter(s => s.count > 0).map(stats => (
            <div key={stats.name} className="bg-bg-main border border-border-subtle rounded-lg p-4">
              <div className="flex justify-between items-start mb-4">
                <div>
                  <h6 className="font-bold text-foreground flex items-center gap-2 text-lg">
                    {stats.name}
                  </h6>
                  <span className={`inline-block mt-1 px-2 py-0.5 text-[10px] uppercase font-bold rounded border ${getRiskColor(stats.risk)}`}>
                    {stats.risk} Risk
                  </span>
                </div>
                <div className="text-right">
                  <div className="text-2xl font-bold text-foreground">{stats.count.toLocaleString()}</div>
                  <div className="text-xs text-text-muted uppercase tracking-wider">Connections</div>
                </div>
              </div>
              
              <div className="grid grid-cols-2 gap-y-3 gap-x-2 text-sm mt-4 pt-4 border-t border-border-subtle/50">
                <div className="flex items-center gap-2 text-text-muted">
                  <ArrowUp className="w-4 h-4 text-green-400" /> Accepted: <strong className="text-foreground">{stats.accepted.toLocaleString()}</strong>
                </div>
                <div className="flex items-center gap-2 text-text-muted">
                  <Ban className="w-4 h-4 text-red-400" /> Denied: <strong className="text-foreground">{stats.denied.toLocaleString()}</strong>
                </div>
                <div className="flex items-center gap-2 text-text-muted">
                  <User className="w-4 h-4 text-accent-primary" /> Sources: <strong className="text-foreground">{stats.unique_sources.toLocaleString()}</strong>
                </div>
                <div className="flex items-center gap-2 text-text-muted">
                  <Server className="w-4 h-4 text-blue-400" /> Targets: <strong className="text-foreground">{stats.unique_targets.toLocaleString()}</strong>
                </div>
                <div className="col-span-2 flex items-center gap-2 text-text-muted">
                  <Database className="w-4 h-4 text-orange-400" /> Data: <strong className="text-foreground">{formatBytes(stats.bandwidth)}</strong>
                </div>
              </div>
            </div>
          ))}
          {data.protocol_stats.filter(s => s.count > 0).length === 0 && (
            <div className="col-span-full p-8 text-center text-text-muted bg-bg-main rounded-lg border border-border-subtle">
              No remote access protocol activity detected in this time period.
            </div>
          )}
        </div>
      </SectionCard>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2">
          <SectionCard header={<h3 className="font-semibold flex items-center gap-2"><LineChart className="w-5 h-5 text-accent-primary" /> Activity Timeline</h3>}>
            <div className="h-[300px]">
              <Line 
                data={{ labels: timelineLabels, datasets: timelineDatasets }} 
                options={{
                  responsive: true,
                  maintainAspectRatio: false,
                  interaction: { mode: 'index', intersect: false },
                  plugins: { legend: { position: 'bottom', labels: { color: '#888' } } },
                  scales: { 
                    y: { grid: { color: '#333' }, ticks: { color: '#888' } },
                    x: { grid: { color: '#333' }, ticks: { color: '#888' } }
                  }
                }} 
              />
            </div>
          </SectionCard>
        </div>
        <div>
          <SectionCard header={<h3 className="font-semibold flex items-center gap-2"><ChartPie className="w-5 h-5 text-accent-primary" /> Protocol Distribution</h3>}>
            <div className="h-[300px] flex items-center justify-center">
              {activeStats.length > 0 ? (
                <Doughnut 
                  data={doughnutData}
                  options={{
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { color: '#888' } } },
                    cutout: '70%'
                  }}
                />
              ) : (
                <span className="text-text-muted">No data</span>
              )}
            </div>
          </SectionCard>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <SectionCard header={<h3 className="font-semibold flex items-center gap-2"><User className="w-5 h-5 text-accent-primary" /> Top Remote Access Sources</h3>}>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle">
                <tr>
                  <th className="px-4 py-3 font-medium">Source IP</th>
                  <th className="px-4 py-3 font-medium">Protocols</th>
                  <th className="px-4 py-3 font-medium text-right">Targets</th>
                  <th className="px-4 py-3 font-medium text-right">Conns</th>
                  <th className="px-4 py-3 font-medium text-right">Data</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {data.top_sources.map(src => {
                  const protos = Object.entries(src.protocols).sort((a,b) => b[1]-a[1]);
                  const topProto = protos[0]?.[0];
                  return (
                    <tr key={src.ip} className="hover:bg-bg-main/50 transition-colors">
                      <td className="px-4 py-3 font-mono font-bold text-foreground">
                        {src.ip}
                        {src.denied_count > 0 && <span className="ml-2 inline-block px-1.5 py-0.5 bg-red-500/20 text-red-400 text-[10px] rounded" title={`${src.denied_count} failed attempts`}>{src.denied_count} failed</span>}
                      </td>
                      <td className="px-4 py-3">
                        <span className="px-2 py-0.5 bg-bg-main rounded text-xs border border-border-subtle">{topProto}</span>
                        {protos.length > 1 && <span className="ml-1 text-xs text-text-muted">+{protos.length - 1}</span>}
                      </td>
                      <td className="px-4 py-3 text-right text-text-muted">{Object.keys(src.targets).length}</td>
                      <td className="px-4 py-3 text-right text-text-muted">{src.total_connections.toLocaleString()}</td>
                      <td className="px-4 py-3 text-right text-text-muted">{formatBytes(src.total_bandwidth)}</td>
                    </tr>
                  )
                })}
                {data.top_sources.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-text-muted">No source data</td></tr>}
              </tbody>
            </table>
          </div>
        </SectionCard>
        <SectionCard header={<h3 className="font-semibold flex items-center gap-2"><Server className="w-5 h-5 text-accent-primary" /> Top Remote Access Targets</h3>}>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle">
                <tr>
                  <th className="px-4 py-3 font-medium">Target IP</th>
                  <th className="px-4 py-3 font-medium">Protocols</th>
                  <th className="px-4 py-3 font-medium text-right">Sources</th>
                  <th className="px-4 py-3 font-medium text-right">Conns</th>
                  <th className="px-4 py-3 font-medium text-right">Data</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {data.top_destinations.map(dst => {
                  const protos = Object.entries(dst.protocols).sort((a,b) => b[1]-a[1]);
                  const topProto = protos[0]?.[0];
                  return (
                    <tr key={dst.ip} className="hover:bg-bg-main/50 transition-colors">
                      <td className="px-4 py-3 font-mono font-bold text-foreground">{dst.ip}</td>
                      <td className="px-4 py-3">
                        <span className="px-2 py-0.5 bg-bg-main rounded text-xs border border-border-subtle">{topProto}</span>
                        {protos.length > 1 && <span className="ml-1 text-xs text-text-muted">+{protos.length - 1}</span>}
                      </td>
                      <td className="px-4 py-3 text-right text-text-muted">{Object.keys(dst.sources).length}</td>
                      <td className="px-4 py-3 text-right text-text-muted">{dst.total_connections.toLocaleString()}</td>
                      <td className="px-4 py-3 text-right text-text-muted">{formatBytes(dst.total_bandwidth)}</td>
                    </tr>
                  )
                })}
                {data.top_destinations.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-text-muted">No destination data</td></tr>}
              </tbody>
            </table>
          </div>
        </SectionCard>
      </div>

      {data.risky_connections.length > 0 && (
        <SectionCard header={<h3 className="font-semibold flex items-center gap-2 text-red-400"><TriangleAlert className="w-5 h-5" /> High-Risk Connections Detected</h3>}>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {data.risky_connections.slice(0, 10).map((risk, i) => (
              <div key={i} className={`p-4 rounded-xl border ${risk.severity === 'critical' ? 'bg-red-500/5 border-red-500/30' : 'bg-orange-500/5 border-orange-500/30'}`}>
                <div className="flex justify-between items-start mb-3">
                  <div>
                    <strong className="text-foreground text-lg">{risk.protocol}</strong>
                    <span className={`ml-2 px-2 py-0.5 text-xs font-bold rounded uppercase ${risk.severity === 'critical' ? 'bg-red-500/20 text-red-400' : 'bg-orange-500/20 text-orange-400'}`}>
                      {risk.severity} Risk
                    </span>
                  </div>
                  <span className="text-xs text-text-muted">{new Date(risk.timestamp).toLocaleString()}</span>
                </div>
                
                <div className="flex items-center gap-3 bg-bg-main p-3 rounded-lg border border-border-subtle font-mono text-sm mb-3">
                  <div className="text-blue-400">{risk.source}</div>
                  <ArrowRight className="w-4 h-4 text-text-muted" />
                  <div className="text-accent-primary">{risk.destination}:{risk.port}</div>
                  <div className="ml-auto">
                    <span className={`px-2 py-1 text-xs rounded font-bold uppercase ${risk.action === 'accept' ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'}`}>
                      {risk.action}
                    </span>
                  </div>
                </div>

                <div className="flex flex-col gap-2 text-sm">
                  <div className="flex items-start gap-2">
                    <AlertCircle className="w-4 h-4 text-red-400 mt-0.5 shrink-0" />
                    <span className="text-text-secondary"><strong>Risks:</strong> {risk.risk_reasons.join(', ')}</span>
                  </div>
                  {risk.duration > 0 && (
                    <div className="flex items-center gap-4 text-text-muted ml-6">
                      <span className="flex items-center gap-1"><Clock className="w-3.5 h-3.5" /> {(risk.duration/60).toFixed(1)} min</span>
                      <span className="flex items-center gap-1"><Database className="w-3.5 h-3.5" /> {formatBytes(risk.bandwidth)}</span>
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        </SectionCard>
      )}

      {data.unauthorized_attempts.length > 0 && (
        <SectionCard header={<h3 className="font-semibold flex items-center gap-2 text-orange-400"><Ban className="w-5 h-5" /> Unauthorized Access Attempts</h3>}>
          <div className="overflow-x-auto max-h-[400px]">
            <table className="w-full text-left text-sm relative">
              <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle sticky top-0 z-10">
                <tr>
                  <th className="px-4 py-3 font-medium">Time</th>
                  <th className="px-4 py-3 font-medium">Protocol</th>
                  <th className="px-4 py-3 font-medium">Source</th>
                  <th className="px-4 py-3 font-medium">Target</th>
                  <th className="px-4 py-3 font-medium">Port</th>
                  <th className="px-4 py-3 font-medium text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {data.unauthorized_attempts.slice(0, 50).map((attempt, i) => (
                  <tr key={i} className="hover:bg-bg-main/50 transition-colors">
                    <td className="px-4 py-3 text-text-muted whitespace-nowrap">{new Date(attempt.timestamp).toLocaleTimeString()}</td>
                    <td className="px-4 py-3">
                      <span className="px-2 py-0.5 bg-bg-main border border-border-subtle rounded text-xs text-foreground font-semibold">
                        {attempt.protocol}
                      </span>
                    </td>
                    <td className="px-4 py-3 font-mono text-text-secondary">{attempt.source}</td>
                    <td className="px-4 py-3 font-mono text-text-secondary">{attempt.destination}</td>
                    <td className="px-4 py-3 text-text-muted">{attempt.port}</td>
                    <td className="px-4 py-3 text-right">
                      <span className="px-2 py-0.5 bg-red-500/20 text-red-400 text-xs font-bold rounded uppercase">
                        {attempt.action}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </SectionCard>
      )}

    </div>
  );
}

export default RemoteAccess;
