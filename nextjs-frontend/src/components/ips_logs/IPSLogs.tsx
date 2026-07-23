"use client";

import { useEffect, useState, useCallback } from "react";
import { Shield, Download, Ban, TriangleAlert, Skull, Flame, Info, Activity, Search } from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import { IPSLog } from "@/types/logs";
import { StatCard } from "@/components/ui/StatCard";
import { SearchInput } from "@/components/ui/SearchInput";
import { Pagination } from "@/components/ui/Pagination";
import { RiskBadge } from "@/components/ui/RiskBadge";
import { PageHeader } from "@/components/ui/PageHeader";
import { Button } from "@/components/ui/Button";
import { threatService } from "@/services/threat/threatService";

export function IPSLogs() {
  const [logs, setLogs] = useState<IPSLog[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  
  const [search, setSearch] = useState('');
  const [type, setType] = useState('');
  const [action, setAction] = useState('');
  const [severity, setSeverity] = useState('');

  const fetchLogs = useCallback(() => {
    setLoading(true);
    threatService.getIpsLogs().then(data => {
      if (data) {
        setLogs((data.logs || (data as any).data) || []);
        setTotal((data as any).total || 0);
        setTotalPages((data as any).total_pages || 1);
      }
      setLoading(false);
    });
  }, [page, search, type, action, severity]);

  useEffect(() => { fetchLogs(); }, [fetchLogs]);

  useEffect(() => {
    const interval = setInterval(() => { if (page === 1) fetchLogs(); }, 10000);
    return () => clearInterval(interval);
  }, [page, fetchLogs]);

  const getActionStyles = (act: string) => {
    switch(act.toLowerCase()) {
      case 'dropped':
      case 'blocked': return 'bg-red-900/50 text-red-400 border-red-500';
      case 'detected': return 'bg-yellow-900/50 text-yellow-400 border-yellow-500';
      case 'allowed': return 'bg-green-900/50 text-green-400 border-green-500';
      default: return 'bg-gray-800 text-gray-300 border-gray-600';
    }
  };

  const getSeverityIcon = (sev: string) => {
    switch(sev.toLowerCase()) {
      case 'critical': return <span title="Critical"><Skull className="w-4 h-4 text-red-500" /></span>;
      case 'high': return <span title="High"><Flame className="w-4 h-4 text-orange-500" /></span>;
      case 'medium': return <span title="Medium"><TriangleAlert className="w-4 h-4 text-yellow-500" /></span>;
      case 'low': return <span title="Low"><Info className="w-4 h-4 text-blue-400" /></span>;
      default: return <span title="Info"><Activity className="w-4 h-4 text-gray-400" /></span>;
    }
  };

  const blockedCount  = logs.filter(l => ['dropped','blocked'].includes(l.action.toLowerCase())).length;
  const detectedCount = logs.filter(l => l.action.toLowerCase() === 'detected').length;
  const criticalCount = logs.filter(l => l.severity.toLowerCase() === 'critical').length;
  const highCount     = logs.filter(l => l.severity.toLowerCase() === 'high').length;

  return (
    <div className="space-y-6">
      <PageHeader
        title={<><Shield className="w-6 h-6 inline-block mr-2" />IPS &amp; App Control Logs</>}
        subtitle="Real-time intrusion prevention and app control events."
        actions={
          <div className="flex flex-wrap gap-2">
            <button onClick={() => setType('')}        className={`px-3 py-1 text-sm rounded-2xl ${type === '' ? 'bg-accent-primary text-foreground' : 'border border-border-subtle text-text-muted hover:text-foreground'}`}>All</button>
            <button onClick={() => setType('ips')}     className={`px-3 py-1 text-sm rounded-2xl ${type === 'ips' ? 'bg-orange-500 text-foreground' : 'border border-border-subtle text-text-muted hover:text-foreground'}`}>IPS Only</button>
            <button onClick={() => setType('app')}     className={`px-3 py-1 text-sm rounded-2xl ${type === 'app' ? 'bg-blue-500 text-foreground' : 'border border-border-subtle text-text-muted hover:text-foreground'}`}>App Control</button>
            <button onClick={() => setAction('blocked')}  className={`px-3 py-1 text-sm rounded-2xl ${action === 'blocked' ? 'bg-red-500 text-foreground' : 'border border-border-subtle text-text-muted hover:text-foreground'}`}>Blocked</button>
            <button onClick={() => setAction('detected')} className={`px-3 py-1 text-sm rounded-2xl ${action === 'detected' ? 'bg-yellow-500 text-foreground' : 'border border-border-subtle text-text-muted hover:text-foreground'}`}>Detected</button>
          </div>
        }
      />

      {/* Stats Cards */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
        <StatCard title="Total Events" value={total.toLocaleString()} color="text-foreground"
          icon={<Activity className="w-5 h-5 text-accent-primary" />} />
        <StatCard title="Blocked" value={blockedCount} color="text-red-400"
          icon={<Ban className="w-5 h-5 text-red-400" />} iconBgClass="bg-red-900/30 border-red-500/50" />
        <StatCard title="Detected" value={detectedCount} color="text-yellow-400"
          icon={<TriangleAlert className="w-5 h-5 text-yellow-400" />} iconBgClass="bg-yellow-900/30 border-yellow-500/50" />
        <StatCard title="Critical" value={criticalCount} color="text-red-500"
          icon={<Skull className="w-5 h-5 text-red-500" />} iconBgClass="bg-red-900/30 border-red-500/50" />
        <StatCard title="High" value={highCount} color="text-orange-500"
          icon={<Flame className="w-5 h-5 text-orange-500" />} iconBgClass="bg-orange-900/30 border-orange-500/50" />
      </div>

      {/* Main Table */}
      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden flex flex-col h-[600px]">
        
        {/* Filters Bar */}
        <div className="p-4 border-b border-border-subtle bg-bg-main flex gap-2 items-center justify-end">
          <SearchInput
            value={search}
            onChange={setSearch}
            placeholder="Search IPs, attacks, apps..."
            className="w-64"
          />
          <CustomSelect
            value={severity}
            onChange={setSeverity}
            options={[
              { value: "", label: "All Severities" },
              { value: "critical", label: "Critical" },
              { value: "high", label: "High" },
              { value: "medium", label: "Medium" },
              { value: "info", label: "Info" }
            ]}
          />
          <Button variant="outline" size="sm">
            <Download className="w-4 h-4" />
          </Button>
        </div>

        <div className="flex-1 overflow-auto custom-scrollbar relative">
          <table className="w-full text-left text-sm whitespace-nowrap">
            <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle sticky top-0 z-10">
              <tr>
                <th className="px-4 py-3 font-medium">Time</th>
                <th className="px-4 py-3 font-medium">Source</th>
                <th className="px-4 py-3 font-medium">Destination</th>
                <th className="px-4 py-3 font-medium text-center">Sev</th>
                <th className="px-4 py-3 font-medium">Threat / App</th>
                <th className="px-4 py-3 font-medium text-center">Action</th>
                <th className="px-4 py-3 font-medium">Device</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-subtle">
              {loading && logs.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-20 text-center">
                    <div className="w-8 h-8 border-4 border-border-subtle border-t-accent-primary rounded-full animate-spin mx-auto"></div>
                  </td>
                </tr>
              )}
              
              {logs.map((log) => (
                <tr key={log.id} className="hover:bg-bg-card/50 transition-colors">
                  <td className="px-4 py-3 text-text-muted">{log.time}</td>
                  <td className="px-4 py-3 font-mono text-foreground text-xs">{log.src}</td>
                  <td className="px-4 py-3 font-mono text-foreground text-xs">{log.dst}</td>
                  <td className="px-4 py-3 text-center">{getSeverityIcon(log.severity)}</td>
                  <td className="px-4 py-3 font-bold text-foreground">{log.attack || log.app}</td>
                  <td className="px-4 py-3 text-center">
                    <span className={`inline-block px-2 py-0.5 rounded-2xl text-xs font-bold uppercase border ${getActionStyles(log.action)}`}>
                      {log.action}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-text-muted text-xs">{log.devname}</td>
                </tr>
              ))}

              {!loading && logs.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-10 text-center text-text-muted">
                    No IPS logs match the criteria.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        
        <Pagination
          page={page}
          totalPages={totalPages}
          onPrev={() => setPage(p => p - 1)}
          onNext={() => setPage(p => p + 1)}
        />
      </div>
    </div>
  );
}
export default IPSLogs;
