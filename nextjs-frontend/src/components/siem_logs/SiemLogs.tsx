"use client";

import { useEffect, useState } from "react";
import { Receipt, Search, FileText, Download, Trash2, PowerOff, CheckCircle } from "lucide-react";
import { SiemData } from "@/types/logs";
import { LoadingSpinner } from "@/components/ui/LoadingSpinner";
import { AuthError } from "@/components/ui/AuthError";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionCard } from "@/components/ui/SectionCard";
import { SearchInput } from "@/components/ui/SearchInput";
import { safeFetch } from "@/lib/safeFetch";
import { Button } from "@/components/ui/Button";

export function SiemLogs() {
  const [data, setData] = useState<SiemData | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<'archives' | 'sources'>('archives');
  const [archiveSearch, setArchiveSearch] = useState('');

  useEffect(() => {
    safeFetch<SiemData>("/api/get_siem_logs.php", {}, "SiemLogs")
      .then(d => { setData(d); setLoading(false); });
  }, []);

  if (loading) return <LoadingSpinner fullPage />;
  if (data?.error) return <AuthError />;

  const formatBytes = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + ['B', 'KB', 'MB', 'GB', 'TB'][i];
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="SIEM Log Management"
        subtitle="Manage centralized syslog collections, sources, and archives."
      />

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
        <SectionCard
          header={
            <>
              <h3 className="font-semibold text-foreground">Cold Storage Logs</h3>
              <SearchInput
                value={archiveSearch}
                onChange={setArchiveSearch}
                placeholder="Filter IPs..."
                className="w-48"
              />
            </>
          }
        >
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
                      <Button variant="ghost" size="icon" className="hover:text-accent-primary mx-1" title="Download">
                        <Download className="w-4 h-4" />
                      </Button>
                      <Button variant="ghost-danger" size="icon" className="mx-1" title="Delete">
                        <Trash2 className="w-4 h-4" />
                      </Button>
                    </td>
                  </tr>
                ))}
                {data?.log_files.length === 0 && (
                  <tr><td colSpan={5} className="px-6 py-8 text-center text-text-muted">No archived logs found.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </SectionCard>
      )}

      {activeTab === 'sources' && (
        <SectionCard header={<h3 className="font-semibold text-foreground">Registered Syslog Sources</h3>}>
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
                        <Button variant="ghost-danger" size="icon" className="mx-1" title="Disable Source">
                          <PowerOff className="w-4 h-4" />
                        </Button>
                      )}
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
export default SiemLogs;
