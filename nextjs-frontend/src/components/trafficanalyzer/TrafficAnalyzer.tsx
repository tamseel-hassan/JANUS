"use client";

import { useEffect, useState, useCallback } from "react";
import { Play, Pause, Download, Filter, Server, ArrowUp, ArrowDown, Activity, CheckCircle, XCircle, Network, Database, Search } from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import { Flow } from "@/types/logs";
import { StatCard } from "@/components/ui/StatCard";
import { SearchInput } from "@/components/ui/SearchInput";
import { PageHeader } from "@/components/ui/PageHeader";
import { safeFetch } from "@/lib/safeFetch";
import { Button } from "@/components/ui/Button";

export function TrafficAnalyzer() {
  const [flows, setFlows] = useState<Flow[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  
  const [isPaused, setIsPaused] = useState(false);
  const [countdown, setCountdown] = useState(5);
  
  const [viewMode, setViewMode] = useState('live');
  const [srcIp, setSrcIp] = useState('');
  const [dstIp, setDstIp] = useState('');
  const [action, setAction] = useState('');

  const fetchTraffic = useCallback(() => {
    if (isPaused) return;
    setLoading(true);
    const params = new URLSearchParams({ view: viewMode, srcip: srcIp, dstip: dstIp, action });
    safeFetch<{ flows: Flow[]; total: number }>(
      `/api/get_traffic.php?${params.toString()}`, {}, "TrafficAnalyzer"
    ).then(data => {
      if (data) {
        setFlows(data.flows || []);
        setTotal(data.total || 0);
      }
      setLoading(false);
    });
  }, [viewMode, srcIp, dstIp, action, isPaused]);

  useEffect(() => { fetchTraffic(); }, [fetchTraffic]);

  useEffect(() => {
    if (isPaused || viewMode !== 'live') return;
    const interval = setInterval(() => {
      setCountdown(prev => {
        if (prev <= 1) { fetchTraffic(); return 5; }
        return prev - 1;
      });
    }, 1000);
    return () => clearInterval(interval);
  }, [isPaused, viewMode, fetchTraffic]);

  const formatBytes = (bytes: number) => {
    if (!bytes || bytes == 0) return '0 B';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + ['B', 'KB', 'MB', 'GB', 'TB'][i];
  };

  const getActionColor = (act: string) => {
    switch (act.toLowerCase()) {
      case 'accept':  return 'text-green-400 bg-green-900/30 border-green-500/50';
      case 'deny':    return 'text-red-400 bg-red-900/30 border-red-500/50';
      case 'close':   return 'text-gray-400 bg-gray-900/30 border-gray-500/50';
      case 'timeout': return 'text-yellow-400 bg-yellow-900/30 border-yellow-500/50';
      default:        return 'text-blue-400 bg-blue-900/30 border-blue-500/50';
    }
  };

  const accepted = flows.filter(f => f.action.toLowerCase() === 'accept').length;
  const denied   = flows.filter(f => f.action.toLowerCase() === 'deny').length;

  return (
    <div className="space-y-6">
      <PageHeader
        title="Traffic Flow Analyzer"
        subtitle="Deep packet inspection logs and firewall sessions."
        actions={
          <>
            {viewMode === 'live' && (
              <Button
                variant={isPaused ? "outline-warning" : "outline"}
                onClick={() => setIsPaused(!isPaused)}
              >
                {isPaused ? <Play className="w-4 h-4 mr-2" /> : <Pause className="w-4 h-4 mr-2" />}
                {isPaused ? 'Resume' : `Pause (${countdown}s)`}
              </Button>
            )}
            <Button variant="outline">
              <Download className="w-4 h-4 mr-2" /> Export
            </Button>
          </>
        }
      />

      {/* Stats row */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <StatCard title="Total Flows" value={total.toLocaleString()} color="text-foreground"
          icon={<Network className="w-6 h-6 text-accent-primary" />} />
        <StatCard title="Accepted" value={accepted.toLocaleString()} color="text-green-400"
          icon={<CheckCircle className="w-6 h-6 text-green-400" />} iconBgClass="bg-green-900/30 border-green-500/50" />
        <StatCard title="Blocked" value={denied.toLocaleString()} color="text-red-400"
          icon={<XCircle className="w-6 h-6 text-red-400" />} iconBgClass="bg-red-900/30 border-red-500/50" />
        <StatCard title="Engine Status" value="Live" color="text-yellow-400"
          icon={<Activity className="w-6 h-6 text-yellow-400" />} iconBgClass="bg-yellow-900/30 border-yellow-500/50" />
      </div>

      {/* Main Table */}
      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden flex flex-col h-[600px]">
        
        {/* Filters Bar */}
        <div className="p-4 border-b border-border-subtle bg-bg-main flex flex-wrap gap-4 items-center justify-between">
          <div className="flex gap-2">
            <Button
              variant={viewMode === 'live' ? 'primary' : 'secondary'}
              size="sm"
              onClick={() => setViewMode('live')}
            >
              Live Mode
            </Button>
            <Button
              variant={viewMode === 'historical' ? 'primary' : 'secondary'}
              size="sm"
              onClick={() => setViewMode('historical')}
            >
              Historical (Search)
            </Button>
          </div>
          
          <div className="flex gap-2 flex-1 max-w-2xl">
            <SearchInput
              value={srcIp}
              onChange={setSrcIp}
              placeholder="Source IP"
              className="flex-1"
            />
            <SearchInput
              value={dstIp}
              onChange={setDstIp}
              placeholder="Dest IP"
              className="flex-1"
            />
            <CustomSelect
              value={action}
              onChange={setAction}
              options={[
                { value: "", label: "All Actions" },
                { value: "accept", label: "Accept" },
                { value: "deny", label: "Deny" }
              ]}
            />
            <Button variant="secondary" size="icon" onClick={() => fetchTraffic()}>
              <Search className="w-4 h-4" />
            </Button>
          </div>
        </div>

        <div className="flex-1 overflow-auto custom-scrollbar relative">
          <table className="w-full text-left text-sm whitespace-nowrap">
            <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle sticky top-0 z-10">
              <tr>
                <th className="px-4 py-3 font-medium">Time</th>
                <th className="px-4 py-3 font-medium">Source</th>
                <th className="px-4 py-3 font-medium">Destination</th>
                <th className="px-4 py-3 font-medium">Service</th>
                <th className="px-4 py-3 font-medium text-center">Action</th>
                <th className="px-4 py-3 font-medium">Policy</th>
                <th className="px-4 py-3 font-medium">Bandwidth</th>
                <th className="px-4 py-3 font-medium">Device</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-subtle">
              {loading && flows.length === 0 && (
                <tr>
                  <td colSpan={8} className="px-4 py-20 text-center">
                    <div className="w-8 h-8 border-4 border-border-subtle border-t-accent-primary rounded-full animate-spin mx-auto"></div>
                  </td>
                </tr>
              )}
              
              {flows.map((flow) => (
                <tr key={flow.log_id} className="hover:bg-bg-card/50 transition-colors group">
                  <td className="px-4 py-2.5 text-text-muted">
                    {new Date(flow.time).toLocaleTimeString()}
                    {flow.is_archive && <span title="Archived Log"><Database className="w-3 h-3 ml-1 inline text-accent-primary" /></span>}
                  </td>
                  <td className="px-4 py-2.5 font-mono text-foreground text-xs">{flow.src}</td>
                  <td className="px-4 py-2.5 font-mono text-foreground text-xs">{flow.dst}</td>
                  <td className="px-4 py-2.5 text-foreground">{flow.service}</td>
                  <td className="px-4 py-2.5 text-center">
                    <span className={`inline-flex items-center px-2 py-0.5 rounded-2xl text-xs font-medium uppercase border ${getActionColor(flow.action)}`}>
                      {flow.action}
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-text-muted font-mono text-xs">{flow.policyid}</td>
                  <td className="px-4 py-2.5">
                    <div className="flex flex-col text-xs font-mono">
                      <span className="text-green-400 flex items-center"><ArrowUp className="w-3 h-3 mr-1"/> {formatBytes(flow.sentbyte)}</span>
                      <span className="text-blue-400 flex items-center"><ArrowDown className="w-3 h-3 mr-1"/> {formatBytes(flow.rcvdbyte)}</span>
                    </div>
                  </td>
                  <td className="px-4 py-2.5 text-text-muted flex items-center">
                    <Server className="w-3 h-3 mr-1"/> {flow.devname}
                  </td>
                </tr>
              ))}

              {!loading && flows.length === 0 && (
                <tr>
                  <td colSpan={8} className="px-4 py-10 text-center text-text-muted">
                    No traffic flows match the criteria.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
export default TrafficAnalyzer;
