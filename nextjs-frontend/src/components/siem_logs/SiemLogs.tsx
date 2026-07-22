"use client";

import { useEffect, useState } from "react";
import {
  Receipt,
  Search,
  FileText,
  Download,
  Trash2,
  PowerOff,
  CheckCircle,
  Terminal,
  RefreshCw,
  XCircle,
  Settings,
  Play,
  Database,
} from "lucide-react";
import { SiemData, LiveLog } from "@/types/logs";
import { LoadingSpinner } from "@/components/ui/LoadingSpinner";
import { AuthError } from "@/components/ui/AuthError";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionCard } from "@/components/ui/SectionCard";
import { SearchInput } from "@/components/ui/SearchInput";
import CustomSelect from "@/components/ui/CustomSelect";
import { safeFetch } from "@/lib/safeFetch";
import { Button } from "@/components/ui/Button";
import { Tabs } from "@/components/ui/Tabs";

export function SiemLogs() {
  const [data, setData] = useState<SiemData | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<"live" | "archives" | "sources">(
    "live",
  );
  const [archiveSearch, setArchiveSearch] = useState("");

  // Live Logs state
  const [liveLogs, setLiveLogs] = useState<LiveLog[]>([]);
  const [liveLoading, setLiveLoading] = useState(false);
  const [autoRefresh, setAutoRefresh] = useState(true);

  // Forms state
  const [newSourceIp, setNewSourceIp] = useState("");
  const [newSourceType, setNewSourceType] = useState("");
  const [retentionHours, setRetentionHours] = useState(168);
  const [cleanupHours, setCleanupHours] = useState(168);

  const fetchSiemData = () => {
    safeFetch<SiemData>("/api/get_siem_logs.php", {}, "SiemLogs").then((d) => {
      if (d && !d.error) {
        setData(d);
        if (d.archive_stats) {
          setRetentionHours(d.archive_stats.retention_hours);
          setCleanupHours(d.archive_stats.retention_hours);
        }
      }
      setLoading(false);
    });
  };

  const fetchLiveLogs = () => {
    setLiveLoading(true);
    safeFetch<{ logs: LiveLog[] }>(
      "/api/get_siem_live_logs.php",
      {},
      "SiemLiveLogs",
    ).then((d) => {
      if (d?.logs) setLiveLogs(d.logs);
      setLiveLoading(false);
    });
  };

  useEffect(() => {
    fetchSiemData();
    fetchLiveLogs();
  }, []);

  useEffect(() => {
    if (activeTab === "live" && autoRefresh) {
      const interval = setInterval(fetchLiveLogs, 10000); // Poll every 10s
      return () => clearInterval(interval);
    }
  }, [activeTab, autoRefresh]);

  const handleAddSource = async (e: React.FormEvent) => {
    e.preventDefault();
    const res = await safeFetch<{ success: boolean; error?: string }>(
      "/api/post_siem_logs.php",
      {
        method: "POST",
        body: JSON.stringify({
          action: "add_source",
          appliance_type: newSourceType,
          source_ip: newSourceIp,
        }),
      },
    );
    if (res?.success) {
      setNewSourceIp("");
      setNewSourceType("");
      fetchSiemData();
    } else {
      alert(res?.error || "Failed to add source");
    }
  };

  const handleToggleSource = async (id: string) => {
    const res = await safeFetch<{ success: boolean }>(
      "/api/post_siem_logs.php",
      {
        method: "POST",
        body: JSON.stringify({ action: "toggle_source", id }),
      },
    );
    if (res?.success) fetchSiemData();
  };

  const handleDeleteSource = async (id: string) => {
    if (!confirm("Delete source and all its logs?")) return;
    const res = await safeFetch<{ success: boolean }>(
      "/api/post_siem_logs.php",
      {
        method: "POST",
        body: JSON.stringify({ action: "delete_source", id }),
      },
    );
    if (res?.success) fetchSiemData();
  };

  const handlePurgeLogs = async (id: string) => {
    if (!confirm("Purge all logs for this source?")) return;
    const res = await safeFetch<{ success: boolean }>(
      "/api/post_siem_logs.php",
      {
        method: "POST",
        body: JSON.stringify({ action: "purge_logs", id }),
      },
    );
    if (res?.success) {
      fetchSiemData();
      if (activeTab === "live") fetchLiveLogs();
    }
  };

  const handleSaveRetention = async (e: React.FormEvent) => {
    e.preventDefault();
    const res = await safeFetch<{ success: boolean }>(
      "/api/post_siem_logs.php",
      {
        method: "POST",
        body: JSON.stringify({
          action: "save_retention",
          hours: retentionHours,
        }),
      },
    );
    if (res?.success) fetchSiemData();
  };

  const handleCleanupArchive = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!confirm(`Delete records older than ${cleanupHours} hours?`)) return;
    const res = await safeFetch<{ success: boolean; deleted?: number }>(
      "/api/post_siem_logs.php",
      {
        method: "POST",
        body: JSON.stringify({
          action: "cleanup_archive",
          hours: cleanupHours,
        }),
      },
    );
    if (res?.success) {
      alert(`Successfully deleted ${res.deleted} records.`);
      fetchSiemData();
    }
  };

  if (loading && !data) return <LoadingSpinner fullPage />;
  if (data?.error) return <AuthError />;

  const formatBytes = (bytes: number) => {
    if (bytes === 0) return "0 B";
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (
      parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) +
      " " +
      ["B", "KB", "MB", "GB", "TB"][i]
    );
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="SIEM Log Management"
        subtitle="Manage centralized syslog collections, sources, and archives."
      />

      <div className="mb-4">
        <Tabs
          activeTab={activeTab}
          onChange={(id) => setActiveTab(id as "live" | "archives" | "sources")}
          tabs={[
            {
              id: "live",
              label: "Live Logs",
              icon: <Terminal className="w-4 h-4" />,
            },
            {
              id: "archives",
              label: "Log Archives",
              icon: <FileText className="w-4 h-4" />,
            },
            {
              id: "sources",
              label: "Managed Sources",
              icon: <Receipt className="w-4 h-4" />,
            },
          ]}
        />
      </div>

      {activeTab === "live" && (
        <div className="animate-in fade-in slide-in-from-bottom-2 duration-300">
          <SectionCard
            header={
              <div className="flex justify-between items-center w-full">
                <h3 className="font-semibold text-foreground flex items-center gap-2">
                  <Terminal className="w-5 h-5 text-accent-primary" /> Live Log
                  Stream
                  {liveLoading && (
                    <RefreshCw className="w-4 h-4 animate-spin text-text-muted ml-2" />
                  )}
                </h3>
                <div className="flex gap-2">
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => setAutoRefresh(!autoRefresh)}
                  >
                    {autoRefresh ? (
                      <span className="flex items-center text-green-400">
                        <Play className="w-4 h-4 mr-1" /> Auto-refresh On
                      </span>
                    ) : (
                      <span className="flex items-center text-text-muted">
                        <PowerOff className="w-4 h-4 mr-1" /> Auto-refresh Off
                      </span>
                    )}
                  </Button>
                  <Button variant="outline" size="sm" onClick={fetchLiveLogs}>
                    <RefreshCw className="w-4 h-4 mr-1" /> Refresh
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setLiveLogs([])}
                  >
                    Clear View
                  </Button>
                </div>
              </div>
            }
          >
            <div className="bg-bg-main p-4 rounded-b-2xl h-[600px] overflow-y-auto font-mono text-sm shadow-inner border border-border-subtle">
              {liveLogs.length === 0 ? (
                <div className="text-text-muted">No live logs available.</div>
              ) : (
                <div className="space-y-3">
                  {liveLogs.map((log) => (
                    <div
                      key={log.id}
                      className="p-3 bg-bg-card/50 rounded-2xl border border-border-subtle hover:border-accent-primary/50 transition-colors"
                    >
                      <div className="flex justify-between items-center mb-1">
                        <div className="flex items-center gap-2">
                          <span className="px-2 py-0.5 text-xs font-bold rounded-2xl bg-accent-primary/20 text-accent-primary uppercase tracking-wider">
                            {log.appliance_type || log.source_type || "OTHER"}
                          </span>
                          <span className="text-foreground font-bold">
                            {log.source_ip}
                          </span>
                        </div>
                        <span className="text-text-muted text-xs">
                          {new Date(log.received_at).toLocaleString()}
                        </span>
                      </div>
                      <div className="text-text-secondary whitespace-pre-wrap mt-2">
                        {log.message}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </SectionCard>
        </div>
      )}

      {activeTab === "archives" && (
        <div className="animate-in fade-in slide-in-from-bottom-2 duration-300 space-y-6">
          <SectionCard
            header={
              <h3 className="font-semibold text-foreground flex items-center gap-2">
                <Settings className="w-5 h-5 text-accent-primary" /> Archive
                Management
              </h3>
            }
          >
            <div className="p-6">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div className="space-y-4">
                  <div>
                    <h4 className="text-sm font-semibold text-foreground mb-1">
                      Default Retention Policy
                    </h4>
                    <p className="text-xs text-text-muted mb-3">
                      Set how many hours automated cleanup keeps data in the
                      archive table.
                    </p>
                    <form
                      onSubmit={handleSaveRetention}
                      className="flex gap-2 items-end"
                    >
                      <div>
                        <label className="text-xs text-text-muted block mb-1">
                          Hours
                        </label>
                        <input
                          type="number"
                          min="1"
                          max="8760"
                          value={retentionHours}
                          onChange={(e) =>
                            setRetentionHours(parseInt(e.target.value))
                          }
                          className="bg-bg-input border border-border-subtle text-foreground text-sm rounded-2xl px-3 py-2 w-32 focus:outline-none focus:border-accent-primary"
                        />
                      </div>
                      <Button type="submit" variant="primary">
                        Save Settings
                      </Button>
                    </form>
                  </div>
                </div>

                <div className="space-y-4 md:border-l border-border-subtle md:pl-8">
                  <div>
                    <h4 className="text-sm font-semibold text-red-400 flex items-center gap-1 mb-1">
                      <Trash2 className="w-4 h-4" /> Manual Cleanup
                    </h4>
                    <p className="text-xs text-text-muted mb-3">
                      Immediately delete old records using custom hours.
                    </p>
                    <form
                      onSubmit={handleCleanupArchive}
                      className="flex gap-2 items-end"
                    >
                      <div>
                        <label className="text-xs text-text-muted block mb-1">
                          Older than (Hours)
                        </label>
                        <input
                          type="number"
                          min="1"
                          max="8760"
                          value={cleanupHours}
                          onChange={(e) =>
                            setCleanupHours(parseInt(e.target.value))
                          }
                          className="bg-bg-input border border-border-subtle text-foreground text-sm rounded-2xl px-3 py-2 w-32 focus:outline-none focus:border-accent-primary"
                        />
                      </div>
                      <Button type="submit" variant="danger">
                        Clean Archive Now
                      </Button>
                    </form>
                  </div>
                </div>
              </div>

              {data?.archive_stats && (
                <div className="mt-6 pt-4 border-t border-border-subtle flex gap-8">
                  <div>
                    <span className="block text-xs text-text-muted uppercase tracking-wider mb-1">
                      Archived DB Records
                    </span>
                    <span className="text-xl font-bold text-foreground">
                      {data.archive_stats.count.toLocaleString()}
                    </span>
                  </div>
                  <div>
                    <span className="block text-xs text-text-muted uppercase tracking-wider mb-1">
                      Oldest Record
                    </span>
                    <span className="text-sm text-foreground">
                      {data.archive_stats.oldest
                        ? new Date(data.archive_stats.oldest).toLocaleString()
                        : "N/A"}
                    </span>
                  </div>
                </div>
              )}
            </div>
          </SectionCard>

          <SectionCard
            header={
              <div className="flex justify-between items-center w-full">
                <h3 className="font-semibold text-foreground flex items-center gap-2">
                  <Database className="w-5 h-5 text-accent-primary" /> Cold
                  Storage Log Files
                </h3>
                <SearchInput
                  value={archiveSearch}
                  onChange={setArchiveSearch}
                  placeholder="Filter IPs..."
                  className="w-48"
                />
              </div>
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
                    <th className="px-6 py-3 font-medium text-right">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-subtle">
                  {data?.log_files.map((log) => (
                    <tr
                      key={log.file}
                      className="hover:bg-bg-card/50 transition-colors"
                    >
                      <td className="px-6 py-3 font-mono text-foreground">
                        {log.ip}
                      </td>
                      <td className="px-6 py-3 text-text-muted">{log.date}</td>
                      <td className="px-6 py-3 text-text-muted text-right">
                        {formatBytes(log.size)}
                      </td>
                      <td className="px-6 py-3 text-text-muted text-right">
                        {log.lines.toLocaleString()}
                      </td>
                      <td className="px-6 py-3 text-right">
                        <Button
                          variant="ghost"
                          size="icon"
                          className="hover:text-accent-primary mx-1"
                          title="Download"
                        >
                          <Download className="w-4 h-4" />
                        </Button>
                        <Button
                          variant="ghost-danger"
                          size="icon"
                          className="mx-1"
                          title="Delete"
                        >
                          <Trash2 className="w-4 h-4" />
                        </Button>
                      </td>
                    </tr>
                  ))}
                  {data?.log_files.length === 0 && (
                    <tr>
                      <td
                        colSpan={5}
                        className="px-6 py-8 text-center text-text-muted"
                      >
                        No archived log files found.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </SectionCard>
        </div>
      )}

      {activeTab === "sources" && (
        <div className="animate-in fade-in slide-in-from-bottom-2 duration-300 space-y-6">
          <SectionCard
            header={
              <h3 className="font-semibold text-foreground flex items-center gap-2">
                <CheckCircle className="w-5 h-5 text-accent-primary" /> Add
                Allowed Source
              </h3>
            }
          >
            <div className="p-6">
              <form onSubmit={handleAddSource} className="flex items-end gap-4">
                <div>
                  <label className="text-xs text-text-muted block mb-1">
                    Appliance Type
                  </label>
                  <CustomSelect
                    value={newSourceType}
                    onChange={setNewSourceType}
                    options={[
                      { value: "", label: "Select Type" },
                      { value: "ngfw", label: "NGFW (Firewall)" },
                      { value: "switch", label: "Switch" },
                      { value: "router", label: "Router" },
                      { value: "edr", label: "EDR" },
                      { value: "xdr", label: "XDR" },
                      { value: "other", label: "Other" },
                    ]}
                    className="min-w-[150px]"
                  />
                </div>
                <div>
                  <label className="text-xs text-text-muted block mb-1">
                    Source IP
                  </label>
                  <input
                    required
                    type="text"
                    value={newSourceIp}
                    onChange={(e) => setNewSourceIp(e.target.value)}
                    placeholder="e.g., 192.168.1.100"
                    className="bg-bg-input border border-border-subtle text-foreground text-sm rounded-2xl px-3 py-2 focus:outline-none focus:border-accent-primary"
                  />
                </div>
                <Button type="submit" variant="primary">
                  Allow Source
                </Button>
              </form>
            </div>
          </SectionCard>

          <SectionCard
            header={
              <h3 className="font-semibold text-foreground flex items-center gap-2">
                <Receipt className="w-5 h-5 text-accent-primary" /> Registered
                Syslog Sources
              </h3>
            }
          >
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle">
                  <tr>
                    <th className="px-6 py-3 font-medium">Status</th>
                    <th className="px-6 py-3 font-medium">Source IP</th>
                    <th className="px-6 py-3 font-medium">Device Type</th>
                    <th className="px-6 py-3 font-medium text-right">
                      Ingested Events
                    </th>
                    <th className="px-6 py-3 font-medium text-right">
                      Last Event Seen
                    </th>
                    <th className="px-6 py-3 font-medium text-right">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-subtle">
                  {data?.sources.map((source) => (
                    <tr
                      key={source.id}
                      className="hover:bg-bg-card/50 transition-colors"
                    >
                      <td className="px-6 py-3">
                        {source.is_active ? (
                          <span className="text-green-400 flex items-center">
                            <CheckCircle className="w-4 h-4 mr-1" /> Active
                          </span>
                        ) : (
                          <span className="text-red-400 flex items-center">
                            <XCircle className="w-4 h-4 mr-1" /> Blocked
                          </span>
                        )}
                      </td>
                      <td className="px-6 py-3 font-mono text-foreground font-bold">
                        {source.source_ip}
                      </td>
                      <td className="px-6 py-3 text-text-muted capitalize">
                        <span className="px-2 py-0.5 text-xs font-bold rounded-2xl bg-accent-primary/10 text-accent-primary tracking-wider uppercase">
                          {source.source_type}
                        </span>
                      </td>
                      <td className="px-6 py-3 text-text-muted text-right">
                        {source.log_count?.toLocaleString() || 0}
                      </td>
                      <td className="px-6 py-3 text-text-muted text-right">
                        {source.last_log
                          ? new Date(source.last_log).toLocaleString()
                          : "Never"}
                      </td>
                      <td className="px-6 py-3 text-right">
                        <Button
                          variant="ghost"
                          size="icon"
                          className="mx-1"
                          onClick={() => handleToggleSource(source.id)}
                          title={
                            source.is_active ? "Block Source" : "Unblock Source"
                          }
                        >
                          {source.is_active ? (
                            <XCircle className="w-4 h-4 text-orange-400" />
                          ) : (
                            <CheckCircle className="w-4 h-4 text-green-400" />
                          )}
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="mx-1"
                          onClick={() => handlePurgeLogs(source.id)}
                          title="Purge Logs"
                        >
                          <RefreshCw className="w-4 h-4 text-yellow-500" />
                        </Button>
                        <Button
                          variant="ghost-danger"
                          size="icon"
                          className="mx-1"
                          onClick={() => handleDeleteSource(source.id)}
                          title="Delete Source"
                        >
                          <Trash2 className="w-4 h-4" />
                        </Button>
                      </td>
                    </tr>
                  ))}
                  {data?.sources.length === 0 && (
                    <tr>
                      <td
                        colSpan={6}
                        className="px-6 py-8 text-center text-text-muted"
                      >
                        No sources registered yet.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </SectionCard>
        </div>
      )}
    </div>
  );
}

export default SiemLogs;
