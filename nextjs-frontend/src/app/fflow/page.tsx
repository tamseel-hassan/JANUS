'use client';
import { useState, useEffect } from 'react';
import { Network, Search, Filter, Server, Database, Eye } from 'lucide-react';

interface Log {
  log_id: number;
  received_at: string;
  message: string;
  source_ip: string;
  source_table: string;
}

export default function FFlowPage() {
  const [logs, setLogs] = useState<Log[]>([]);
  const [devices, setDevices] = useState<{source_ip: string, appliance_type: string}[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);

  const [filters, setFilters] = useState({
    device: '',
    srcip: '',
    dstip: '',
    srcport: '',
    dstport: '',
    service: '',
    action: '',
    view: 'live'
  });

  const fetchLogs = async () => {
    setLoading(true);
    try {
      const queryParams = new URLSearchParams({
        page: page.toString(),
        ...filters
      });
      const res = await fetch(`/api/get_fflow.php?${queryParams}`);
      if (res.ok) {
        const data = await res.json();
        setLogs(data.logs || []);
        setDevices(data.devices || []);
        setTotalPages(data.total_pages || 1);
        setTotal(data.total || 0);
      }
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchLogs();
  }, [page, filters.view]);

  const handleFilterSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    fetchLogs();
  };

  const extractMessageParts = (message: string) => {
    const parts: Record<string, string> = {};
    const regex = /(\w+)=("[^"]*"|[^ ]+)/g;
    let match;
    while ((match = regex.exec(message)) !== null) {
      parts[match[1]] = match[2].replace(/"/g, '');
    }
    return parts;
  };

  return (
    <div className="p-6 max-w-[1600px] mx-auto space-y-6">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-2xl font-bold text-foreground flex items-center gap-3">
            <Network className="text-purple-400 w-8 h-8" />
            NetFlow Traffic Analyzer
          </h1>
          <p className="text-slate-400 mt-2">Analyze firewall traffic logs in real-time or historical mode</p>
        </div>
        <div className="flex bg-slate-800 rounded-2xl p-1 border border-slate-700">
          <button
            onClick={() => setFilters({ ...filters, view: 'live' })}
            className={`px-4 py-2 rounded-2xl text-sm font-medium transition-colors flex items-center gap-2 ${
              filters.view === 'live' ? 'bg-purple-500 text-foreground' : 'text-slate-400 hover:text-slate-200'
            }`}
          >
            <Server className="w-4 h-4" /> Live Traffic
          </button>
          <button
            onClick={() => setFilters({ ...filters, view: 'historical' })}
            className={`px-4 py-2 rounded-2xl text-sm font-medium transition-colors flex items-center gap-2 ${
              filters.view === 'historical' ? 'bg-purple-500 text-foreground' : 'text-slate-400 hover:text-slate-200'
            }`}
          >
            <Database className="w-4 h-4" /> Historical Archive
          </button>
        </div>
      </div>

      <div className="bg-slate-800 rounded-2xl border border-slate-700 p-4">
        <form onSubmit={handleFilterSubmit} className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4 items-end">
          <div className="space-y-1">
            <label className="text-xs font-medium text-slate-400">Device Source</label>
            <select
              value={filters.device}
              onChange={(e) => setFilters({ ...filters, device: e.target.value })}
              className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-purple-500"
            >
              <option value="">All Firewalls</option>
              {devices.map((d, i) => (
                <option key={i} value={d.source_ip}>{d.source_ip} ({d.appliance_type})</option>
              ))}
            </select>
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-slate-400">Source IP</label>
            <input
              type="text"
              value={filters.srcip}
              onChange={(e) => setFilters({ ...filters, srcip: e.target.value })}
              className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-purple-500"
              placeholder="e.g. 192.168.1.10"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-slate-400">Dest IP</label>
            <input
              type="text"
              value={filters.dstip}
              onChange={(e) => setFilters({ ...filters, dstip: e.target.value })}
              className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-purple-500"
              placeholder="e.g. 8.8.8.8"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-slate-400">Src Port</label>
            <input
              type="text"
              value={filters.srcport}
              onChange={(e) => setFilters({ ...filters, srcport: e.target.value })}
              className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-purple-500"
              placeholder="e.g. 443"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-slate-400">Dest Port</label>
            <input
              type="text"
              value={filters.dstport}
              onChange={(e) => setFilters({ ...filters, dstport: e.target.value })}
              className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-purple-500"
              placeholder="e.g. 443"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-slate-400">Service</label>
            <input
              type="text"
              value={filters.service}
              onChange={(e) => setFilters({ ...filters, service: e.target.value })}
              className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-purple-500"
              placeholder="e.g. HTTPS"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-slate-400">Action</label>
            <select
              value={filters.action}
              onChange={(e) => setFilters({ ...filters, action: e.target.value })}
              className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-purple-500"
            >
              <option value="">Any</option>
              <option value="accept">Accept</option>
              <option value="deny">Deny</option>
              <option value="drop">Drop</option>
              <option value="close">Close</option>
            </select>
          </div>
          <button
            type="submit"
            className="w-full bg-purple-600 hover:bg-purple-700 text-foreground rounded-2xl px-3 py-2 text-sm font-medium transition-colors flex items-center justify-center gap-2"
          >
            <Search className="w-4 h-4" /> Filter
          </button>
        </form>
      </div>

      <div className="bg-slate-800 rounded-2xl border border-slate-700 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-slate-900/50 border-b border-slate-700">
                <th className="p-4 font-semibold text-slate-300 text-sm whitespace-nowrap">Time</th>
                <th className="p-4 font-semibold text-slate-300 text-sm whitespace-nowrap">Source IP</th>
                <th className="p-4 font-semibold text-slate-300 text-sm whitespace-nowrap">Src Port</th>
                <th className="p-4 font-semibold text-slate-300 text-sm whitespace-nowrap">Dest IP</th>
                <th className="p-4 font-semibold text-slate-300 text-sm whitespace-nowrap">Dst Port</th>
                <th className="p-4 font-semibold text-slate-300 text-sm whitespace-nowrap">Service</th>
                <th className="p-4 font-semibold text-slate-300 text-sm whitespace-nowrap">Action</th>
                <th className="p-4 font-semibold text-slate-300 text-sm whitespace-nowrap">Device</th>
                <th className="p-4 font-semibold text-slate-300 text-sm whitespace-nowrap text-right">Raw</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={9} className="p-8 text-center text-slate-400">Loading traffic logs...</td>
                </tr>
              ) : logs.length === 0 ? (
                <tr>
                  <td colSpan={9} className="p-8 text-center text-slate-400">No traffic logs found matching criteria.</td>
                </tr>
              ) : (
                logs.map((log) => {
                  const parts = extractMessageParts(log.message);
                  const action = (parts.action || 'unknown').toLowerCase();
                  return (
                    <tr key={`${log.source_table}-${log.log_id}`} className="border-b border-slate-700/50 hover:bg-slate-700/20 transition-colors">
                      <td className="p-4 text-sm text-slate-300 whitespace-nowrap">{log.received_at}</td>
                      <td className="p-4 text-sm text-slate-300">{parts.srcip || '-'}</td>
                      <td className="p-4 text-sm text-slate-400">{parts.srcport || '-'}</td>
                      <td className="p-4 text-sm text-slate-300">{parts.dstip || '-'}</td>
                      <td className="p-4 text-sm text-slate-400">{parts.dstport || '-'}</td>
                      <td className="p-4 text-sm text-slate-300">
                        {parts.service && (
                          <span className="bg-slate-700/50 text-slate-300 px-2 py-0.5 rounded-2xl text-xs">
                            {parts.service}
                          </span>
                        )}
                      </td>
                      <td className="p-4 text-sm">
                        <span className={`px-2 py-1 rounded-full text-xs font-medium ${
                          action === 'accept' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' :
                          action === 'deny' || action === 'drop' ? 'bg-red-500/10 text-red-400 border border-red-500/20' :
                          'bg-slate-700 text-slate-300'
                        }`}>
                          {parts.action || '-'}
                        </span>
                      </td>
                      <td className="p-4 text-sm text-slate-400">{log.source_ip}</td>
                      <td className="p-4 text-sm text-right">
                        <button 
                          className="text-purple-400 hover:text-purple-300"
                          onClick={() => alert(log.message)}
                        >
                          <Eye className="w-4 h-4 ml-auto" />
                        </button>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
        
        {/* Pagination */}
        {!loading && totalPages > 1 && (
          <div className="p-4 border-t border-slate-700 flex justify-between items-center">
            <span className="text-sm text-slate-400">
              Showing page {page} of {totalPages} ({total} total records)
            </span>
            <div className="flex gap-2">
              <button
                onClick={() => setPage(p => Math.max(1, p - 1))}
                disabled={page === 1}
                className="px-3 py-1 bg-slate-700 text-slate-300 rounded-2xl hover:bg-slate-600 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                Previous
              </button>
              <button
                onClick={() => setPage(p => Math.min(totalPages, p + 1))}
                disabled={page === totalPages}
                className="px-3 py-1 bg-slate-700 text-slate-300 rounded-2xl hover:bg-slate-600 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
