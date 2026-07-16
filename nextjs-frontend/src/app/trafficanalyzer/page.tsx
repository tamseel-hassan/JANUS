"use client";

import { useEffect, useState, useCallback } from "react";
import { Search, Play, Pause, Download, Filter, Server, ArrowUp, ArrowDown, Activity, CheckCircle, XCircle, AlertOctagon, Clock, Network, Database } from "lucide-react";

interface Flow {
  log_id: string;
  time: string;
  src: string;
  dst: string;
  service: string;
  action: string;
  icon: string;
  policyid: string;
  devname: string;
  source_ip: string;
  sentbyte: number;
  rcvdbyte: number;
  is_archive: boolean;
  full_parsed: any;
}

export default function TrafficAnalyzerPage() {
  const [flows, setFlows] = useState<Flow[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  
  const [isPaused, setIsPaused] = useState(false);
  const [countdown, setCountdown] = useState(5);
  
  // Filters
  const [viewMode, setViewMode] = useState('live'); // 'live' or 'historical'
  const [srcIp, setSrcIp] = useState('');
  const [dstIp, setDstIp] = useState('');
  const [action, setAction] = useState('');

  const fetchTraffic = useCallback(() => {
    if (isPaused) return;
    
    setLoading(true);
    const params = new URLSearchParams({
      view: viewMode,
      srcip: srcIp,
      dstip: dstIp,
      action: action
    });
    
    fetch(`/api/get_traffic.php?${params.toString()}`, { credentials: "include" })
      .then(res => res.json())
      .then(data => {
        setFlows(data.flows || []);
        setTotal(data.total || 0);
        setLoading(false);
      })
      .catch(err => {
        console.error(err);
        setLoading(false);
      });
  }, [viewMode, srcIp, dstIp, action, isPaused]);

  useEffect(() => {
    fetchTraffic();
  }, [fetchTraffic]);

  // Live countdown timer
  useEffect(() => {
    if (isPaused || viewMode !== 'live') return;
    
    const interval = setInterval(() => {
      setCountdown(prev => {
        if (prev <= 1) {
          fetchTraffic();
          return 5;
        }
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

  const getActionIcon = (act: string) => {
    switch (act.toLowerCase()) {
      case 'accept': return <CheckCircle className="w-4 h-4 text-green-400" />;
      case 'deny': return <XCircle className="w-4 h-4 text-red-400" />;
      case 'close': return <AlertOctagon className="w-4 h-4 text-gray-400" />;
      case 'timeout': return <Clock className="w-4 h-4 text-yellow-400" />;
      default: return <Activity className="w-4 h-4 text-blue-400" />;
    }
  };
  
  const getActionColor = (act: string) => {
    switch (act.toLowerCase()) {
      case 'accept': return 'text-green-400 bg-green-900/30 border-green-500/50';
      case 'deny': return 'text-red-400 bg-red-900/30 border-red-500/50';
      case 'close': return 'text-gray-400 bg-gray-900/30 border-gray-500/50';
      case 'timeout': return 'text-yellow-400 bg-yellow-900/30 border-yellow-500/50';
      default: return 'text-blue-400 bg-blue-900/30 border-blue-500/50';
    }
  };

  // Stats
  const accepted = flows.filter(f => f.action.toLowerCase() === 'accept').length;
  const denied = flows.filter(f => f.action.toLowerCase() === 'deny').length;

  return (
    <div className="space-y-6">
      
      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-wide">Traffic Flow Analyzer</h1>
          <p className="text-text-muted mt-1">Deep packet inspection logs and firewall sessions.</p>
        </div>
        
        <div className="flex space-x-2">
          {viewMode === 'live' && (
             <button 
                onClick={() => setIsPaused(!isPaused)} 
                className={`px-4 py-2 rounded-2xl font-medium flex items-center transition-colors border ${isPaused ? 'bg-yellow-900/50 text-yellow-400 border-yellow-500/50 hover:bg-yellow-900/70' : 'bg-transparent text-foreground border-border-subtle hover:border-accent-primary'}`}
             >
                {isPaused ? <Play className="w-4 h-4 mr-2" /> : <Pause className="w-4 h-4 mr-2" />} 
                {isPaused ? 'Resume' : `Pause (${countdown}s)`}
             </button>
          )}
          <button className="px-4 py-2 bg-transparent border border-border-subtle text-foreground rounded-2xl font-medium hover:border-accent-primary transition-colors flex items-center">
            <Download className="w-4 h-4 mr-2" /> Export
          </button>
        </div>
      </div>

      {/* Stats row */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-4 flex items-center">
          <div className="bg-bg-main p-3 rounded-2xl border border-border-subtle mr-4"><Network className="w-6 h-6 text-accent-primary" /></div>
          <div>
             <div className="text-2xl font-bold text-foreground">{total.toLocaleString()}</div>
             <div className="text-text-muted text-sm uppercase tracking-wider font-bold">Total Flows</div>
          </div>
        </div>
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-4 flex items-center">
          <div className="bg-green-900/30 p-3 rounded-2xl border border-green-500/50 mr-4"><CheckCircle className="w-6 h-6 text-green-400" /></div>
          <div>
             <div className="text-2xl font-bold text-green-400">{accepted.toLocaleString()}</div>
             <div className="text-text-muted text-sm uppercase tracking-wider font-bold">Accepted</div>
          </div>
        </div>
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-4 flex items-center">
          <div className="bg-red-900/30 p-3 rounded-2xl border border-red-500/50 mr-4"><XCircle className="w-6 h-6 text-red-400" /></div>
          <div>
             <div className="text-2xl font-bold text-red-400">{denied.toLocaleString()}</div>
             <div className="text-text-muted text-sm uppercase tracking-wider font-bold">Blocked</div>
          </div>
        </div>
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-4 flex items-center">
          <div className="bg-yellow-900/30 p-3 rounded-2xl border border-yellow-500/50 mr-4"><Activity className="w-6 h-6 text-yellow-400" /></div>
          <div>
             <div className="text-2xl font-bold text-yellow-400">Live</div>
             <div className="text-text-muted text-sm uppercase tracking-wider font-bold">Engine Status</div>
          </div>
        </div>
      </div>

      {/* Main Table */}
      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden flex flex-col h-[600px]">
        
        {/* Filters Bar */}
        <div className="p-4 border-b border-border-subtle bg-bg-main flex flex-wrap gap-4 items-center justify-between">
          <div className="flex gap-2">
             <button 
                onClick={() => setViewMode('live')}
                className={`px-4 py-1.5 rounded-2xl text-sm font-medium transition-colors ${viewMode === 'live' ? 'bg-accent-primary text-foreground' : 'bg-bg-card text-text-muted hover:text-foreground'}`}
             >
               Live Mode
             </button>
             <button 
                onClick={() => setViewMode('historical')}
                className={`px-4 py-1.5 rounded-2xl text-sm font-medium transition-colors ${viewMode === 'historical' ? 'bg-accent-primary text-foreground' : 'bg-bg-card text-text-muted hover:text-foreground'}`}
             >
               Historical (Search)
             </button>
          </div>
          
          <div className="flex gap-2 flex-1 max-w-2xl">
             <div className="relative flex-1">
               <Filter className="w-3 h-3 absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
               <input 
                 type="text" 
                 placeholder="Source IP" 
                 value={srcIp} onChange={(e) => setSrcIp(e.target.value)}
                 className="w-full bg-bg-card border border-border-subtle rounded-2xl py-1.5 pl-8 pr-3 text-sm text-foreground focus:outline-none focus:border-accent-primary" 
               />
             </div>
             <div className="relative flex-1">
               <Filter className="w-3 h-3 absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
               <input 
                 type="text" 
                 placeholder="Dest IP" 
                 value={dstIp} onChange={(e) => setDstIp(e.target.value)}
                 className="w-full bg-bg-card border border-border-subtle rounded-2xl py-1.5 pl-8 pr-3 text-sm text-foreground focus:outline-none focus:border-accent-primary" 
               />
             </div>
             <select 
                value={action} onChange={(e) => setAction(e.target.value)}
                className="bg-bg-card border border-border-subtle rounded-2xl py-1.5 px-3 text-sm text-foreground focus:outline-none focus:border-accent-primary"
             >
               <option value="">All Actions</option>
               <option value="accept">Accept</option>
               <option value="deny">Deny</option>
             </select>
             <button onClick={() => fetchTraffic()} className="bg-border-subtle hover:bg-accent-primary text-foreground px-3 py-1.5 rounded-2xl text-sm transition-colors">
               <Search className="w-4 h-4" />
             </button>
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
