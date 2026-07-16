"use client";

import { useEffect, useState } from "react";
import { Receipt, Search, FileText, Download, Trash2, PowerOff, CheckCircle } from "lucide-react";

interface SiemSource {
  id: string;
  source_ip: string;
  source_type: string;
  is_active: number;
  added_by: string;
  username: string;
  added_at: string;
  log_count: number;
  last_log: string;
}

interface LogFile {
  ip: string;
  date: string;
  file: string;
  size: number;
  lines: number;
}

interface SiemData {
  sources: SiemSource[];
  log_files: LogFile[];
  error?: string;
}

export default function SiemLogsPage() {
  const [data, setData] = useState<SiemData | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<'archives' | 'sources'>('archives');

  useEffect(() => {
    fetch("/api/get_siem_logs.php", { credentials: "include" })
      .then(res => res.json())
      .then(d => { setData(d); setLoading(false); })
      .catch(err => { console.error(err); setLoading(false); });
  }, []);

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="w-10 h-10 border-4 border-border-subtle border-t-accent-primary rounded-full animate-spin"></div>
      </div>
    );
  }

  if (data?.error) {
    return (
      <div className="bg-bg-card border-l-4 border-red-500 p-6 rounded-2xl">
        <h2 className="text-xl font-semibold text-red-400">Authentication Required</h2>
        <p className="text-text-muted mt-2">Please ensure you are logged into the original application.</p>
      </div>
    );
  }

  const formatBytes = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + ['B', 'KB', 'MB', 'GB', 'TB'][i];
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-foreground tracking-wide">SIEM Log Management</h1>
        <p className="text-text-muted mt-1">Manage centralized syslog collections, sources, and archives.</p>
      </div>

      <div className="flex border-b border-border-subtle">
        <button
          className={`px-6 py-3 font-medium text-sm transition-colors ${activeTab === 'archives' ? 'text-accent-primary border-b-2 border-accent-primary' : 'text-text-muted hover:text-foreground'}`}
          onClick={() => setActiveTab('archives')}
        >
          <FileText className="w-4 h-4 inline-block mr-2" /> Log Archives
        </button>
        <button
          className={`px-6 py-3 font-medium text-sm transition-colors ${activeTab === 'sources' ? 'text-accent-primary border-b-2 border-accent-primary' : 'text-text-muted hover:text-foreground'}`}
          onClick={() => setActiveTab('sources')}
        >
          <Receipt className="w-4 h-4 inline-block mr-2" /> Managed Sources
        </button>
      </div>

      {activeTab === 'archives' && (
        <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden">
          <div className="p-4 border-b border-border-subtle bg-bg-main flex justify-between items-center">
            <h3 className="font-semibold text-foreground">Cold Storage Logs</h3>
            <div className="relative">
              <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
              <input type="text" placeholder="Filter IPs..." className="bg-bg-card border border-border-subtle rounded-2xl py-1 pl-9 pr-3 text-sm text-foreground focus:outline-none focus:border-accent-primary" />
            </div>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle">
                <tr>
                  <th className="px-6 py-3 font-medium">Device IP</th>
                  <th className="px-6 py-3 font-medium">Archive Date</th>
                  <th className="px-6 py-3 font-medium text-right">Size</th>
                  <th className="px-6 py-3 font-medium text-right">Events</th>
                  <th className="px-6 py-3 font-medium text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {data?.log_files.map((log) => (
                  <tr key={log.file} className="hover:bg-bg-card/50 transition-colors">
                    <td className="px-6 py-3 font-mono text-foreground">{log.ip}</td>
                    <td className="px-6 py-3 text-text-muted">{log.date}</td>
                    <td className="px-6 py-3 text-text-muted text-right">{formatBytes(log.size)}</td>
                    <td className="px-6 py-3 text-text-muted text-right">{log.lines.toLocaleString()}</td>
                    <td className="px-6 py-3 text-right">
                      <button className="text-text-muted hover:text-accent-primary mx-2" title="Download">
                        <Download className="w-4 h-4 inline" />
                      </button>
                      <button className="text-text-muted hover:text-red-400 mx-2" title="Delete">
                        <Trash2 className="w-4 h-4 inline" />
                      </button>
                    </td>
                  </tr>
                ))}
                {data?.log_files.length === 0 && (
                  <tr><td colSpan={5} className="px-6 py-8 text-center text-text-muted">No archived logs found.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {activeTab === 'sources' && (
        <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden">
           <div className="p-4 border-b border-border-subtle bg-bg-main">
            <h3 className="font-semibold text-foreground">Registered Syslog Sources</h3>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle">
                <tr>
                  <th className="px-6 py-3 font-medium">Status</th>
                  <th className="px-6 py-3 font-medium">Source IP</th>
                  <th className="px-6 py-3 font-medium">Device Type</th>
                  <th className="px-6 py-3 font-medium text-right">Ingested Events</th>
                  <th className="px-6 py-3 font-medium text-right">Last Event Seen</th>
                  <th className="px-6 py-3 font-medium text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {data?.sources.map((source) => (
                  <tr key={source.id} className="hover:bg-bg-card/50 transition-colors">
                    <td className="px-6 py-3">
                      {source.is_active ? 
                        <span className="text-green-400 flex items-center"><CheckCircle className="w-4 h-4 mr-1"/> Active</span> : 
                        <span className="text-red-400 flex items-center"><PowerOff className="w-4 h-4 mr-1"/> Disabled</span>}
                    </td>
                    <td className="px-6 py-3 font-mono text-foreground">{source.source_ip}</td>
                    <td className="px-6 py-3 text-text-muted capitalize">{source.source_type}</td>
                    <td className="px-6 py-3 text-text-muted text-right">{source.log_count?.toLocaleString() || 0}</td>
                    <td className="px-6 py-3 text-text-muted text-right">{source.last_log || 'Never'}</td>
                    <td className="px-6 py-3 text-right">
                      {source.is_active === 1 && (
                        <button className="text-text-muted hover:text-red-400 mx-2" title="Disable Source">
                          <PowerOff className="w-4 h-4 inline" />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

    </div>
  );
}
