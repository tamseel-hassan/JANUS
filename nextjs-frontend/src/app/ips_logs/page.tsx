"use client";

import { useEffect, useState, useCallback } from "react";
import { Shield, Search, Download, Filter, Ban, TriangleAlert, Skull, Flame, Info, Activity } from "lucide-react";

interface IPSLog {
  id: string;
  time: string;
  src: string;
  dst: string;
  action: string;
  severity: string;
  attack: string;
  app: string;
  devname: string;
}

export default function IPSLogsPage() {
  const [logs, setLogs] = useState<IPSLog[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  
  // Filters
  const [search, setSearch] = useState('');
  const [type, setType] = useState(''); // ips, app
  const [action, setAction] = useState(''); // blocked, detected, allowed
  const [severity, setSeverity] = useState(''); // critical, high, medium, low

  const fetchLogs = useCallback(() => {
    setLoading(true);
    const params = new URLSearchParams({
      page: page.toString(),
      search: search,
      type: type,
      action: action,
      severity: severity
    });
    
    fetch(`/api/get_ips.php?${params.toString()}`, { credentials: "include" })
      .then(res => res.json())
      .then(data => {
        setLogs(data.logs || []);
        setTotal(data.total || 0);
        setTotalPages(data.total_pages || 1);
        setLoading(false);
      })
      .catch(err => {
        console.error(err);
        setLoading(false);
      });
  }, [page, search, type, action, severity]);

  useEffect(() => {
    fetchLogs();
  }, [fetchLogs]);

  // Use setInterval for pseudo-realtime feeling (every 10s)
  useEffect(() => {
    const interval = setInterval(() => {
      if (page === 1) fetchLogs();
    }, 10000);
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

  // Quick stats
  const blockedCount = logs.filter(l => ['dropped', 'blocked'].includes(l.action.toLowerCase())).length;
  const detectedCount = logs.filter(l => l.action.toLowerCase() === 'detected').length;
  const criticalCount = logs.filter(l => l.severity.toLowerCase() === 'critical').length;
  const highCount = logs.filter(l => l.severity.toLowerCase() === 'high').length;

  return (
    <div className="space-y-6">
      
      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-wide"><Shield className="w-6 h-6 inline-block mr-2" />IPS & App Control Logs</h1>
          <p className="text-text-muted mt-1">Real-time intrusion prevention and app control events.</p>
        </div>
        
        <div className="flex flex-wrap gap-2">
          <button onClick={() => setType('')} className={`px-3 py-1 text-sm rounded-2xl ${type === '' ? 'bg-accent-primary text-foreground' : 'border border-border-subtle text-text-muted hover:text-foreground'}`}>All</button>
          <button onClick={() => setType('ips')} className={`px-3 py-1 text-sm rounded-2xl ${type === 'ips' ? 'bg-orange-500 text-foreground' : 'border border-border-subtle text-text-muted hover:text-foreground'}`}>IPS Only</button>
          <button onClick={() => setType('app')} className={`px-3 py-1 text-sm rounded-2xl ${type === 'app' ? 'bg-blue-500 text-foreground' : 'border border-border-subtle text-text-muted hover:text-foreground'}`}>App Control</button>
          <button onClick={() => setAction('blocked')} className={`px-3 py-1 text-sm rounded-2xl ${action === 'blocked' ? 'bg-red-500 text-foreground' : 'border border-border-subtle text-text-muted hover:text-foreground'}`}>Blocked</button>
          <button onClick={() => setAction('detected')} className={`px-3 py-1 text-sm rounded-2xl ${action === 'detected' ? 'bg-yellow-500 text-foreground' : 'border border-border-subtle text-text-muted hover:text-foreground'}`}>Detected</button>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-4 flex items-center">
          <div className="bg-bg-main p-3 rounded-2xl border border-border-subtle mr-3"><Activity className="w-5 h-5 text-accent-primary" /></div>
          <div>
             <div className="text-xl font-bold text-foreground">{total.toLocaleString()}</div>
             <div className="text-text-muted text-xs uppercase tracking-wider font-bold">Total Events</div>
          </div>
        </div>
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-4 flex items-center">
          <div className="bg-red-900/30 p-3 rounded-2xl border border-red-500/50 mr-3"><Ban className="w-5 h-5 text-red-400" /></div>
          <div>
             <div className="text-xl font-bold text-red-400">{blockedCount}</div>
             <div className="text-text-muted text-xs uppercase tracking-wider font-bold">Blocked</div>
          </div>
        </div>
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-4 flex items-center">
          <div className="bg-yellow-900/30 p-3 rounded-2xl border border-yellow-500/50 mr-3"><TriangleAlert className="w-5 h-5 text-yellow-400" /></div>
          <div>
             <div className="text-xl font-bold text-yellow-400">{detectedCount}</div>
             <div className="text-text-muted text-xs uppercase tracking-wider font-bold">Detected</div>
          </div>
        </div>
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-4 flex items-center">
          <div className="bg-red-900/30 p-3 rounded-2xl border border-red-500/50 mr-3"><Skull className="w-5 h-5 text-red-500" /></div>
          <div>
             <div className="text-xl font-bold text-red-500">{criticalCount}</div>
             <div className="text-text-muted text-xs uppercase tracking-wider font-bold">Critical</div>
          </div>
        </div>
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-4 flex items-center">
          <div className="bg-orange-900/30 p-3 rounded-2xl border border-orange-500/50 mr-3"><Flame className="w-5 h-5 text-orange-500" /></div>
          <div>
             <div className="text-xl font-bold text-orange-500">{highCount}</div>
             <div className="text-text-muted text-xs uppercase tracking-wider font-bold">High</div>
          </div>
        </div>
      </div>

      {/* Main Table */}
      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden flex flex-col h-[600px]">
        
        {/* Filters Bar */}
        <div className="p-4 border-b border-border-subtle bg-bg-main flex gap-2 items-center justify-end">
          <div className="relative w-64">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
            <input 
              type="text" 
              placeholder="Search IPs, attacks, apps..." 
              value={search} onChange={(e) => setSearch(e.target.value)}
              className="w-full bg-bg-card border border-border-subtle rounded-2xl py-1.5 pl-9 pr-3 text-sm text-foreground focus:outline-none focus:border-accent-primary" 
            />
          </div>
          <select 
            value={severity} onChange={(e) => setSeverity(e.target.value)}
            className="bg-bg-card border border-border-subtle rounded-2xl py-1.5 px-3 text-sm text-foreground focus:outline-none focus:border-accent-primary"
          >
            <option value="">All Severities</option>
            <option value="critical">Critical</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="info">Info</option>
          </select>
          <button className="bg-transparent border border-border-subtle text-text-muted px-3 py-1.5 rounded-2xl hover:text-foreground hover:border-accent-primary">
            <Download className="w-4 h-4" />
          </button>
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
        
        {/* Pagination */}
        <div className="p-3 border-t border-border-subtle bg-bg-main flex justify-between items-center text-sm">
          <span className="text-text-muted">Showing page {page} of {totalPages || 1}</span>
          <div className="flex gap-2">
            <button 
              disabled={page <= 1} onClick={() => setPage(p => p - 1)}
              className="px-3 py-1 bg-bg-card border border-border-subtle text-foreground rounded-2xl disabled:opacity-50"
            >
              Prev
            </button>
            <button 
              disabled={page >= totalPages} onClick={() => setPage(p => p + 1)}
              className="px-3 py-1 bg-bg-card border border-border-subtle text-foreground rounded-2xl disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
