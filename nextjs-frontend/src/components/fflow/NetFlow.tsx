'use client';

import { useState, useEffect } from 'react';
import { Network, Search, Server, Database, Eye } from 'lucide-react';
import CustomSelect from '@/components/ui/CustomSelect';
import { Log } from "@/types/logs";
import { Pagination } from "@/components/ui/Pagination";
import { safeFetch } from "@/lib/safeFetch";
import { Button } from "@/components/ui/Button";

export function NetFlow() {
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
    const queryParams = new URLSearchParams({
      page: page.toString(),
      ...filters
    });
    const data = await safeFetch<{ logs: Log[]; devices: {source_ip: string; appliance_type: string}[]; total_pages: number; total: number }>(
      `/api/get_fflow.php?${queryParams}`, {}, "NetFlow"
    );
    if (data) {
      setLogs(data.logs || []);
      setDevices(data.devices || []);
      setTotalPages(data.total_pages || 1);
      setTotal(data.total || 0);
    }
    setLoading(false);
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
            <Network className="text-accent-primary w-8 h-8" />
            NetFlow Traffic Analyzer
          </h1>
          <p className="text-text-muted mt-2">Analyze firewall traffic logs in real-time or historical mode</p>
        </div>
        <div className="flex bg-bg-raised rounded-2xl p-1 border border-border-subtle">
          <button
            onClick={() => setFilters({ ...filters, view: 'live' })}
            className={`px-4 py-2 rounded-2xl text-sm font-medium transition-colors flex items-center gap-2 ${
              filters.view === 'live' ? 'bg-accent-primary text-foreground' : 'text-text-muted hover:text-foreground'
            }`}
          >
            <Server className="w-4 h-4" /> Live Traffic
          </button>
          <button
            onClick={() => setFilters({ ...filters, view: 'historical' })}
            className={`px-4 py-2 rounded-2xl text-sm font-medium transition-colors flex items-center gap-2 ${
              filters.view === 'historical' ? 'bg-accent-primary text-foreground' : 'text-text-muted hover:text-foreground'
            }`}
          >
            <Database className="w-4 h-4" /> Historical Archive
          </button>
        </div>
      </div>

      <div className="bg-bg-raised rounded-2xl border border-border-subtle p-4">
        <form onSubmit={handleFilterSubmit} className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4 items-end">
          <div className="space-y-1">
            <label className="text-xs font-medium text-text-muted">Device Source</label>
            <CustomSelect
              value={filters.device}
              onChange={val => setFilters({ ...filters, device: val })}
              options={[
                { value: "", label: "All Firewalls" },
                ...devices.map(d => ({ value: d.source_ip, label: `${d.source_ip} (${d.appliance_type})` }))
              ]}
              placeholder="All Firewalls"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-text-muted">Source IP</label>
            <input
              type="text"
              value={filters.srcip}
              onChange={(e) => setFilters({ ...filters, srcip: e.target.value })}
              className="w-full bg-bg-card border border-border-subtle rounded-2xl px-3 py-2 text-sm text-foreground focus:outline-none focus:border-accent-primary"
              placeholder="e.g. 192.168.1.10"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-text-muted">Dest IP</label>
            <input
              type="text"
              value={filters.dstip}
              onChange={(e) => setFilters({ ...filters, dstip: e.target.value })}
              className="w-full bg-bg-card border border-border-subtle rounded-2xl px-3 py-2 text-sm text-foreground focus:outline-none focus:border-accent-primary"
              placeholder="e.g. 8.8.8.8"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-text-muted">Src Port</label>
            <input
              type="text"
              value={filters.srcport}
              onChange={(e) => setFilters({ ...filters, srcport: e.target.value })}
              className="w-full bg-bg-card border border-border-subtle rounded-2xl px-3 py-2 text-sm text-foreground focus:outline-none focus:border-accent-primary"
              placeholder="e.g. 443"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-text-muted">Dest Port</label>
            <input
              type="text"
              value={filters.dstport}
              onChange={(e) => setFilters({ ...filters, dstport: e.target.value })}
              className="w-full bg-bg-card border border-border-subtle rounded-2xl px-3 py-2 text-sm text-foreground focus:outline-none focus:border-accent-primary"
              placeholder="e.g. 443"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-text-muted">Service</label>
            <input
              type="text"
              value={filters.service}
              onChange={(e) => setFilters({ ...filters, service: e.target.value })}
              className="w-full bg-bg-card border border-border-subtle rounded-2xl px-3 py-2 text-sm text-foreground focus:outline-none focus:border-accent-primary"
              placeholder="e.g. HTTPS"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs font-medium text-text-muted">Action</label>
            <CustomSelect
              value={filters.action}
              onChange={val => setFilters({ ...filters, action: val })}
              options={[
                { value: "", label: "Any" },
                { value: "accept", label: "Accept" },
                { value: "deny", label: "Deny" },
                { value: "drop", label: "Drop" },
                { value: "close", label: "Close" }
              ]}
            />
          </div>
          <Button
            type="submit"
            variant="primary"
            className="w-full font-medium py-2 mt-auto"
          >
            <Search className="w-4 h-4 mr-2" /> Filter
          </Button>
        </form>
      </div>

      <div className="bg-bg-raised rounded-2xl border border-border-subtle overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-bg-main/50 border-b border-border-subtle">
                <th className="p-4 font-semibold text-foreground text-sm whitespace-nowrap">Time</th>
                <th className="p-4 font-semibold text-foreground text-sm whitespace-nowrap">Source IP</th>
                <th className="p-4 font-semibold text-foreground text-sm whitespace-nowrap">Src Port</th>
                <th className="p-4 font-semibold text-foreground text-sm whitespace-nowrap">Dest IP</th>
                <th className="p-4 font-semibold text-foreground text-sm whitespace-nowrap">Dst Port</th>
                <th className="p-4 font-semibold text-foreground text-sm whitespace-nowrap">Service</th>
                <th className="p-4 font-semibold text-foreground text-sm whitespace-nowrap">Action</th>
                <th className="p-4 font-semibold text-foreground text-sm whitespace-nowrap">Device</th>
                <th className="p-4 font-semibold text-foreground text-sm whitespace-nowrap text-right">Raw</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={9} className="p-8 text-center text-text-muted">Loading traffic logs...</td>
                </tr>
              ) : logs.length === 0 ? (
                <tr>
                  <td colSpan={9} className="p-8 text-center text-text-muted">No traffic logs found matching criteria.</td>
                </tr>
              ) : (
                logs.map((log) => {
                  const parts = extractMessageParts(log.message);
                  const action = (parts.action || 'unknown').toLowerCase();
                  return (
                    <tr key={`${log.source_table}-${log.log_id}`} className="border-b border-border-subtle/50 hover:bg-slate-700/20 transition-colors">
                      <td className="p-4 text-sm text-foreground whitespace-nowrap">{log.received_at}</td>
                      <td className="p-4 text-sm text-foreground">{parts.srcip || '-'}</td>
                      <td className="p-4 text-sm text-text-muted">{parts.srcport || '-'}</td>
                      <td className="p-4 text-sm text-foreground">{parts.dstip || '-'}</td>
                      <td className="p-4 text-sm text-text-muted">{parts.dstport || '-'}</td>
                      <td className="p-4 text-sm text-foreground">
                        {parts.service && (
                          <span className="bg-slate-700/50 text-foreground px-2 py-0.5 rounded-2xl text-xs">
                            {parts.service}
                          </span>
                        )}
                      </td>
                      <td className="p-4 text-sm">
                        <span className={`px-2 py-1 rounded-full text-xs font-medium ${
                          action === 'accept' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' :
                          action === 'deny' || action === 'drop' ? 'bg-red-500/10 text-red-400 border border-red-500/20' :
                          'bg-slate-700 text-foreground'
                        }`}>
                          {parts.action || '-'}
                        </span>
                      </td>
                      <td className="p-4 text-sm text-text-muted">{log.source_ip}</td>
                      <td className="p-4 text-sm text-right">
                        <Button 
                          variant="ghost"
                          size="icon"
                          className="text-accent-primary hover:text-purple-300 ml-auto"
                          onClick={() => alert(log.message)}
                        >
                          <Eye className="w-4 h-4" />
                        </Button>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
        
        {!loading && totalPages > 1 && (
          <Pagination
            page={page}
            totalPages={totalPages}
            total={total}
            onPrev={() => setPage(p => Math.max(1, p - 1))}
            onNext={() => setPage(p => Math.min(totalPages, p + 1))}
          />
        )}
      </div>
    </div>
  );
}
export default NetFlow;

