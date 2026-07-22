"use client";

import { useEffect, useState, useRef } from "react";
import {
  HardDrive,
  Upload,
  Download,
  Trash2,
  Search,
  FileArchive,
} from "lucide-react";
import { LoadingSpinner } from "@/components/ui/LoadingSpinner";
import { AuthError } from "@/components/ui/AuthError";
import { PageHeader } from "@/components/ui/PageHeader";
import { SectionCard } from "@/components/ui/SectionCard";
import { SearchInput } from "@/components/ui/SearchInput";
import CustomSelect from "@/components/ui/CustomSelect";
import { safeFetch } from "@/lib/safeFetch";
import { Button } from "@/components/ui/Button";
import { confirmDialog } from "@/lib/use-confirm";
import { toast } from "sonner";

interface Device {
  id: string;
  name: string;
  ip: string;
}

interface BackupMeta {
  device_id: number;
  notes: string;
  uploaded_by: string;
  original_name: string;
  size: number;
  compressed: boolean;
  uploaded_at: string;
}

interface Backup {
  path: string;
  name: string;
  size: number;
  modified: string;
  meta: BackupMeta;
}

export default function ConfigBackup() {
  const [devices, setDevices] = useState<Device[]>([]);
  const [backups, setBackups] = useState<Backup[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const [search, setSearch] = useState("");
  const [uploading, setUploading] = useState(false);
  const [selectedDevice, setSelectedDevice] = useState("");
  const formRef = useRef<HTMLFormElement>(null);

  const fetchBackups = async () => {
    setLoading(true);
    const data = await safeFetch<{ devices: Device[]; backups: Backup[] }>(
      "/api/get_config_backup.php",
      {},
      "ConfigBackup",
    );
    if (data) {
      setDevices(data.devices || []);
      setBackups(data.backups || []);
    } else {
      setError("Failed to load backups.");
    }
    setLoading(false);
  };

  useEffect(() => {
    fetchBackups();
  }, []);

  const handleUpload = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setUploading(true);
    setError(null);
    setSuccess(null);

    const formData = new FormData(e.currentTarget);
    try {
      const res = await fetch("/api/post_config_backup.php", {
        method: "POST",
        body: formData,
        credentials: "omit", // or "include" depending on safeFetch config... Wait, if safeFetch includes credentials, let's include them.
      });
      const data = await res.json();
      if (data.error) {
        setError(data.error);
      } else {
        setSuccess(
          data.success ? "File uploaded successfully!" : "Upload complete.",
        );
        if (formRef.current) formRef.current.reset();
        setSelectedDevice("");
        fetchBackups();
      }
    } catch (err: any) {
      setError(err.message || "Failed to upload file.");
    }
    setUploading(false);
  };

  const handleDelete = async (file: string) => {
    const ok = await confirmDialog({
      title: "Delete Backup",
      description: "Are you sure you want to delete this backup?",
      variant: "destructive",
      confirmText: "Delete",
    });
    if (!ok) return;
    try {
      const data = await safeFetch<{ success: boolean; error?: string }>(
        "/api/post_config_backup.php",
        {
          method: "DELETE",
          body: JSON.stringify({ file }),
        },
      );
      if (data && data.success) {
        fetchBackups();
      } else {
        toast.error(data?.error || "Failed to delete backup");
      }
    } catch (e: any) {
      toast.error("Error deleting backup");
    }
  };

  const filteredBackups = backups.filter(
    (b) =>
      b.name.toLowerCase().includes(search.toLowerCase()) ||
      (b.meta?.notes || "").toLowerCase().includes(search.toLowerCase()) ||
      (
        devices
          .find((d) => d.id == b.meta?.device_id.toString())
          ?.name.toLowerCase() || ""
      ).includes(search.toLowerCase()),
  );

  return (
    <div className="p-6 max-w-[1600px] mx-auto space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
      <PageHeader
        title={
          <span className="flex items-center gap-2">
            <HardDrive className="w-6 h-6 text-accent-primary" />
            Appliance Configs
          </span>
        }
        subtitle="Upload and manage configuration backups for network devices."
      />

      {error && (
        <div className="bg-red-500/10 border border-red-500/20 text-red-400 p-4 rounded-xl text-sm">
          {error}
        </div>
      )}
      {success && (
        <div className="bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 p-4 rounded-xl text-sm">
          {success}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Upload Form */}
        <SectionCard
          header={
            <h3 className="font-semibold text-foreground">Upload Backup</h3>
          }
          className="lg:col-span-1 h-fit"
        >
          <div className="p-6">
            <form ref={formRef} onSubmit={handleUpload} className="space-y-6">
              <div>
                <label className="block text-sm font-medium text-text-muted mb-2">
                  Device
                </label>
                <input
                  type="hidden"
                  name="device_id"
                  value={selectedDevice}
                  required
                />
                <CustomSelect
                  value={selectedDevice}
                  onChange={setSelectedDevice}
                  options={[
                    { value: "", label: "Select Device" },
                    ...devices.map((d) => ({
                      value: d.id,
                      label: `${d.name} (${d.ip})`,
                    })),
                  ]}
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-text-muted mb-2">
                  Notes
                </label>
                <textarea
                  name="notes"
                  rows={2}
                  className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary"
                  placeholder="Optional description..."
                ></textarea>
              </div>

              <div>
                <label className="block text-sm font-medium text-text-muted mb-2">
                  Backup File (max 50 MB)
                </label>
                <input
                  type="file"
                  name="backup_file"
                  required
                  className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-accent-primary/10 file:text-accent-primary hover:file:bg-accent-primary/20 cursor-pointer"
                />
              </div>

              <Button type="submit" disabled={uploading} className="w-full">
                {uploading ? (
                  <>
                    <LoadingSpinner size="sm" /> Uploading...
                  </>
                ) : (
                  <>
                    <Upload className="w-4 h-4 mr-2" /> Upload File
                  </>
                )}
              </Button>
            </form>
          </div>
        </SectionCard>

        {/* Backups List */}
        <SectionCard
          header={
            <h3 className="font-semibold text-foreground">Available Backups</h3>
          }
          className="lg:col-span-2 flex flex-col min-h-[400px]"
        >
          <div className="p-6 flex-1 flex flex-col">
            <div className="mb-4">
              <SearchInput
                value={search}
                onChange={setSearch}
                placeholder="Search backups by name, device, or notes..."
              />
            </div>

            <div className="overflow-x-auto rounded-xl border border-border-subtle">
              <table className="w-full text-left text-sm whitespace-nowrap">
                <thead className="bg-bg-card text-text-muted border-b border-border-subtle">
                  <tr>
                    <th className="p-4 text-xs font-semibold text-text-muted uppercase tracking-wider">
                      Device
                    </th>
                    <th className="p-4 text-xs font-semibold text-text-muted uppercase tracking-wider">
                      Filename
                    </th>
                    <th className="p-4 text-xs font-semibold text-text-muted uppercase tracking-wider">
                      Size
                    </th>
                    <th className="p-4 text-xs font-semibold text-text-muted uppercase tracking-wider">
                      Uploaded
                    </th>
                    <th className="p-4 text-xs font-semibold text-text-muted uppercase tracking-wider">
                      Notes
                    </th>
                    <th className="p-4 text-xs font-semibold text-text-muted uppercase tracking-wider text-right">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {loading ? (
                    <tr>
                      <td
                        colSpan={6}
                        className="p-8 text-center text-text-muted"
                      >
                        <LoadingSpinner size="md" />
                        Loading backups...
                      </td>
                    </tr>
                  ) : filteredBackups.length === 0 ? (
                    <tr>
                      <td
                        colSpan={6}
                        className="p-8 text-center text-text-muted flex flex-col items-center"
                      >
                        <FileArchive className="w-8 h-8 opacity-20 mb-2" />
                        No backups found.
                      </td>
                    </tr>
                  ) : (
                    filteredBackups.map((b, idx) => {
                      const deviceName =
                        devices.find(
                          (d) => d.id == b.meta?.device_id.toString(),
                        )?.name || "Unknown";
                      const sizeKB = (b.size / 1024).toFixed(1);
                      const uploadedDate = new Date(
                        b.modified,
                      ).toLocaleString();

                      return (
                        <tr
                          key={idx}
                          className="border-b border-border-subtle last:border-0 hover:bg-white/5 transition-colors group"
                        >
                          <td className="p-4 text-sm font-medium text-foreground">
                            {deviceName}
                          </td>
                          <td className="p-4 text-sm text-text-muted">
                            <div className="flex items-center gap-2">
                              <FileArchive className="w-4 h-4 text-accent-primary" />
                              {b.name}
                            </div>
                          </td>
                          <td className="p-4 text-sm text-text-muted">
                            {sizeKB} KB
                          </td>
                          <td className="p-4 text-sm text-text-muted">
                            {uploadedDate}
                          </td>
                          <td
                            className="p-4 text-sm text-text-muted max-w-[150px] truncate"
                            title={b.meta?.notes}
                          >
                            {b.meta?.notes || "-"}
                          </td>
                          <td className="p-4 text-right space-x-2">
                            <a
                              href={`/api/get_config_backup.php?action=download&file=${encodeURIComponent(b.path)}`}
                              className="inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors hover:bg-slate-800 text-text-muted hover:text-emerald-400 h-9 w-9"
                            >
                              <Download className="w-4 h-4" />
                            </a>
                            <button
                              onClick={() => handleDelete(b.path)}
                              className="inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors hover:bg-slate-800 text-text-muted hover:text-red-400 h-9 w-9"
                            >
                              <Trash2 className="w-4 h-4" />
                            </button>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </SectionCard>
      </div>
    </div>
  );
}
