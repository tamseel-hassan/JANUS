"use client";

import { useEffect, useState, useMemo } from "react";
import {
  Network,
  Search,
  Plus,
  Check,
  FileText,
  History,
  Zap,
  Bell,
  Server,
  LayoutGrid,
  Activity,
  Shuffle,
  ShieldAlert,
} from "lucide-react";
import { PageHeader } from "@/components/ui/PageHeader";
import { StatCard } from "@/components/ui/StatCard";
import { SearchInput } from "@/components/ui/SearchInput";
import CustomSelect from "@/components/ui/CustomSelect";
import { toast } from "sonner";
import { Button } from "@/components/ui/Button";
import { Tabs } from "@/components/ui/Tabs";
import { Modal } from "@/components/ui/Modal";
import { safeFetch } from "@/lib/safeFetch";

interface IpamDevice {
  id: string;
  ip: string;
  mac: string;
  assigned_to: string;
  status: string;
  vlan: string;
  notes: string;
  first_seen: string;
  last_seen: string;
  alert_type: string | null;
  acknowledged: boolean;
  log_id: string | null;
}

export default function Ipam() {
  const [ips, setIps] = useState<IpamDevice[]>([]);
  const [subnets, setSubnets] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [subnetFilter, setSubnetFilter] = useState("all");
  const [scanInterval, setScanInterval] = useState("600");
  const [pinging, setPinging] = useState<Record<string, boolean>>({});
  const [activeTab, setActiveTab] = useState("scanner");
  const [selectedIps, setSelectedIps] = useState<Set<string>>(new Set());

  // Modal states
  const [showAddIpModal, setShowAddIpModal] = useState(false);
  const [showAddSubnetModal, setShowAddSubnetModal] = useState(false);
  const [showAddMappingModal, setShowAddMappingModal] = useState(false);

  // Modal form states
  const [addIpForm, setAddIpForm] = useState({ ip: "", mac: "", assigned_to: "", vlan: "", notes: "" });
  const [addIpStatus, setAddIpStatus] = useState("active");

  const [addSubnetForm, setAddSubnetForm] = useState({ cidr: "", vlan_id: "", label: "", interface: "", description: "" });
  const [addSubnetScan, setAddSubnetScan] = useState("yes");

  const [addMappingForm, setAddMappingForm] = useState({ public_ip: "", private_ip: "", description: "" });
  const [addMappingType, setAddMappingType] = useState("dnat");

  const [isSaving, setIsSaving] = useState(false);

  const fetchData = async () => {
    setLoading(true);
    const data = await safeFetch<{ ips: IpamDevice[]; subnets: any[] }>(
      "/api/get_ipam.php?action=load",
      {},
      "IPAM",
    );
    if (data) {
      setIps(data.ips || []);
      setSubnets(data.subnets || []);
    }
    setLoading(false);
  };

  useEffect(() => {
    fetchData();
  }, []);

  const handlePing = async (ip: string) => {
    setPinging((prev) => ({ ...prev, [ip]: true }));
    const data = await safeFetch<{ success: boolean; status: string }>(
      "/api/post_ipam.php",
      {
        method: "POST",
        body: JSON.stringify({ action: "ping", ip }),
      },
    );
    if (data && data.success) {
      setIps((prev) =>
        prev.map((device) =>
          device.ip === ip
            ? {
                ...device,
                status: data.status,
                last_seen: new Date().toISOString(),
              }
            : device,
        ),
      );
    }
    setPinging((prev) => ({ ...prev, [ip]: false }));
  };

  const handleAck = async (log_id: string, ip: string) => {
    const data = await safeFetch<{ success: boolean }>("/api/post_ipam.php", {
      method: "POST",
      body: JSON.stringify({ action: "acknowledge", log_id }),
    });
    if (data && data.success) {
      setIps((prev) =>
        prev.map((device) =>
          device.ip === ip ? { ...device, acknowledged: true } : device,
        ),
      );
    }
  };

  const handleSaveIp = async () => {
    if (!addIpForm.ip) return;
    setIsSaving(true);
    const data = await safeFetch<{ success: boolean; error?: string }>("/api/post_ipam.php", {
      method: "POST",
      body: JSON.stringify({ action: "save", ...addIpForm, status: addIpStatus }),
    });
    setIsSaving(false);
    if (data && data.success) {
      setShowAddIpModal(false);
      setAddIpForm({ ip: "", mac: "", assigned_to: "", vlan: "", notes: "" });
      fetchData();
    } else if (data && data.error) {
      toast.error("Error: " + data.error);
    }
  };

  const handleSaveSubnet = async () => {
    if (!addSubnetForm.cidr) return;
    setIsSaving(true);
    const data = await safeFetch<{ success: boolean; error?: string }>("/api/post_ipam.php", {
      method: "POST",
      body: JSON.stringify({ action: "subnet_save", ...addSubnetForm, scan_enabled: addSubnetScan === "yes" ? 1 : 0 }),
    });
    setIsSaving(false);
    if (data && data.success) {
      setShowAddSubnetModal(false);
      setAddSubnetForm({ cidr: "", vlan_id: "", label: "", interface: "", description: "" });
      fetchData();
    } else if (data && data.error) {
      toast.error("Error: " + data.error);
    }
  };

  const handleSaveMapping = async () => {
    if (!addMappingForm.public_ip || !addMappingForm.private_ip) return;
    setIsSaving(true);
    const data = await safeFetch<{ success: boolean; error?: string }>("/api/post_ipam.php", {
      method: "POST",
      body: JSON.stringify({ action: "mapping_save", ...addMappingForm, type: addMappingType }),
    });
    setIsSaving(false);
    if (data && data.success) {
      setShowAddMappingModal(false);
      setAddMappingForm({ public_ip: "", private_ip: "", description: "" });
      fetchData();
    } else if (data && data.error) {
      toast.error("Error: " + data.error);
    }
  };

  const stats = useMemo(() => {
    const total = ips.length;
    let online = 0,
      offline = 0,
      reserved = 0,
      alerts = 0,
      newToday = 0;

    ips.forEach((d) => {
      if (d.status === "active" || d.status === "online") online++;
      else if (d.status === "offline") offline++;
      else if (d.status === "reserved") reserved++;

      if (d.alert_type && !d.acknowledged && d.alert_type !== "info") alerts++;
      if (d.alert_type === "new" && !d.acknowledged) newToday++;
    });

    return { total, online, offline, reserved, alerts, newToday };
  }, [ips]);

  const filteredIps = ips.filter((ip) => {
    const matchSearch =
      ip.ip.includes(search) ||
      (ip.mac || "").toLowerCase().includes(search.toLowerCase()) ||
      (ip.assigned_to || "").toLowerCase().includes(search.toLowerCase());

    const matchStatus =
      statusFilter === "all" ||
      (statusFilter === "alerts"
        ? ip.alert_type && !ip.acknowledged
        : ip.status === statusFilter);

    return matchSearch && matchStatus;
  });

  const toggleSelect = (ip: string) => {
    const newSet = new Set(selectedIps);
    if (newSet.has(ip)) newSet.delete(ip);
    else newSet.add(ip);
    setSelectedIps(newSet);
  };

  const toggleSelectAll = () => {
    if (selectedIps.size === filteredIps.length) {
      setSelectedIps(new Set());
    } else {
      setSelectedIps(new Set(filteredIps.map((ip) => ip.ip)));
    }
  };

  return (
    <div className="p-6 max-w-[1600px] mx-auto space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
      {/* Header and Actions */}
      <div className="flex flex-col justify-between items-start gap-4">
        <div>
          <h2 className="text-2xl font-semibold text-foreground flex items-center">
            <Network className="w-6 h-6 mr-3 text-accent-primary" />
            IP Address Management
          </h2>
          <div className="text-sm text-text-muted mt-1 flex items-center gap-3">
            <span className="flex items-center">
              <Server className="w-3.5 h-3.5 mr-1 text-emerald-500" /> ARP +
              ICMP discovery
            </span>{" "}
            |
            <span className="flex items-center">
              <ShieldAlert className="w-3.5 h-3.5 mr-1 text-red-500" /> MAC
              spoofing detection
            </span>{" "}
            |
            <span className="flex items-center">
              <Activity className="w-3.5 h-3.5 mr-1 text-blue-500" /> Auto NIC
              selection
            </span>{" "}
            |
            <span className="flex items-center">
              <History className="w-3.5 h-3.5 mr-1 text-amber-500" /> Scheduled
              auto-scan
            </span>
          </div>
        </div>
        <div className="flex gap-2 flex-wrap">
          <Button
            variant="outline"
            className="border-amber-500/50 text-amber-400 hover:bg-amber-500/10"
          >
            <Check className="w-4 h-4 mr-2" /> Clear All Alerts
          </Button>
          <Button variant="outline">
            <FileText className="w-4 h-4 mr-2" /> Export CSV
          </Button>
          <Button
            variant="outline"
            className="border-cyan-500/50 text-cyan-400 hover:bg-cyan-500/10"
          >
            <History className="w-4 h-4 mr-2" /> Change Log
          </Button>
          <Button className="bg-purple-600 hover:bg-purple-700 text-white" onClick={() => setShowAddIpModal(true)}>
            <Plus className="w-4 h-4 mr-2" /> Add IP
          </Button>
        </div>
      </div>

      {/* Stats row */}
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        <StatCard title="Total IPs" value={stats.total} color="text-blue-500" />
        <StatCard
          title="Online"
          value={stats.online}
          color="text-emerald-500"
        />
        <StatCard
          title="Offline"
          value={stats.offline}
          color="text-slate-400"
        />
        <StatCard
          title="Reserved"
          value={stats.reserved}
          color="text-amber-500"
        />
        <StatCard title="Alerts" value={stats.alerts} color="text-red-500" />
        <StatCard
          title="New Today"
          value={stats.newToday}
          color="text-purple-500"
        />
      </div>

      {/* Main Layout */}
      <div className="flex flex-col gap-6">
        {/* Top Tabs Card */}
        <div className="w-full">
          <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden shadow-lg h-full">
            <div className="p-3 border-b border-border-subtle bg-bg-main flex justify-center">
              <Tabs
                tabs={[
                  {
                    id: "scanner",
                    label: "Scanner",
                    icon: <Server className="w-4 h-4" />,
                  },
                  {
                    id: "subnets",
                    label: "Subnets",
                    icon: <LayoutGrid className="w-4 h-4" />,
                  },
                  {
                    id: "util",
                    label: "Util",
                    icon: <Activity className="w-4 h-4" />,
                  },
                  {
                    id: "nat",
                    label: "NAT",
                    icon: <Shuffle className="w-4 h-4" />,
                  },
                ]}
                activeTab={activeTab}
                onChange={(id) => setActiveTab(id)}
              />
            </div>
            <div className="p-6 min-h-62.5">
              {activeTab === "nat" && (
                <div className="space-y-4 animate-in fade-in duration-300 max-w-3xl mx-auto">
                  <div className="flex justify-between items-center">
                    <span className="text-sm font-bold text-foreground">
                      NAT/VIP Mappings
                    </span>
                    <Button size="sm" variant="primary" onClick={() => setShowAddMappingModal(true)}>
                      <Plus className="w-4 h-4 mr-1" /> Add Mapping
                    </Button>
                  </div>
                  <p className="text-xs text-text-muted leading-relaxed max-w-3xl">
                    Map firewall Virtual IPs / NAT addresses to their real
                    internal hosts. This helps correlate NAC MAC/IP data when a
                    firewall owns a public or virtual IP that is forwarded to a
                    real server.
                  </p>
                  <div className="text-center text-xs text-text-muted mt-8 opacity-50">
                    No NAT/VIP mappings yet. Click Add Mapping to create one.
                  </div>
                </div>
              )}
              {activeTab === "scanner" && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-8 items-center justify-center animate-in fade-in duration-300 max-w-3xl mx-auto">
                  <div>
                    <div className="flex justify-between items-center mb-4">
                      <span className="text-sm font-bold text-foreground">
                        Auto-Scan
                      </span>
                      <label className="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" className="sr-only peer" />
                        <div className="w-9 h-5 bg-bg-main peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-accent-primary border border-border-subtle"></div>
                      </label>
                    </div>
                    <CustomSelect
                      value={scanInterval}
                      onChange={setScanInterval}
                      options={[
                        { value: "300", label: "Every 5 min" },
                        { value: "600", label: "Every 10 min" },
                        { value: "1800", label: "Every 30 min" },
                        { value: "3600", label: "Every 1 hour" },
                      ]}
                      className="w-full mb-4"
                    />
                  </div>
                  <div className="space-y-2 text-sm bg-bg-card border border-border-subtle p-4 rounded-2xl">
                    <div className="flex justify-between py-1 border-b border-border-subtle">
                      <span className="text-text-muted">Last scan</span>
                      <span className="text-foreground">Never</span>
                    </div>
                    <div className="flex justify-between py-1 border-b border-border-subtle">
                      <span className="text-text-muted">Duration</span>
                      <span className="text-foreground">—</span>
                    </div>
                    <div className="flex justify-between py-1 border-b border-border-subtle">
                      <span className="text-text-muted">Interface</span>
                      <span className="text-foreground">—</span>
                    </div>
                    <div className="flex justify-between py-1 border-b border-border-subtle">
                      <span className="text-text-muted">Found</span>
                      <span className="text-foreground">—</span>
                    </div>
                    <div className="flex justify-between py-1">
                      <span className="text-text-muted">Changes</span>
                      <span className="text-foreground">—</span>
                    </div>
                  </div>
                </div>
              )}
              {activeTab === "subnets" && (
                <div className="animate-in fade-in duration-300 max-w-3xl mx-auto">
                  <div className="flex justify-between items-center mb-4">
                    <span className="text-sm font-bold text-foreground">
                      Registered Subnets
                    </span>
                    <Button size="sm" variant="primary" onClick={() => setShowAddSubnetModal(true)}>
                      <Plus className="w-4 h-4 mr-1" /> Add Subnet
                    </Button>
                  </div>
                  <div className="space-y-3">
                    {subnets.length === 0 ? (
                      <div className="text-center text-xs text-text-muted mt-8 opacity-50">
                        Loading or no subnets found.
                      </div>
                    ) : (
                      subnets.map((subnet) => (
                        <div
                          key={subnet.id}
                          className="p-3 bg-bg-card border border-border-subtle rounded-xl flex justify-between items-center hover:border-accent-primary transition-colors"
                        >
                          <div>
                            <div className="font-semibold text-foreground text-sm">
                              {subnet.name || subnet.network}
                            </div>
                            <div className="text-xs text-text-muted font-mono">
                              {subnet.network} / {subnet.mask}
                            </div>
                          </div>
                          <Activity className="w-4 h-4 text-accent-primary opacity-50" />
                        </div>
                      ))
                    )}
                  </div>
                </div>
              )}
              {activeTab === "util" && (
                <div className="animate-in fade-in duration-300 max-w-3xl mx-auto">
                  <span className="text-sm font-bold text-foreground mb-4 block">
                    Subnet Utilization
                  </span>
                  <div className="p-6 bg-bg-card border border-border-subtle rounded-2xl flex flex-col items-center justify-center min-h-[150px]">
                    <Activity className="w-8 h-8 text-text-muted mb-2 opacity-50" />
                    <div className="text-center text-xs text-text-muted opacity-80">
                      Utilization metrics and charts will appear here.
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Main Table */}
        <div className="w-full">
          <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden shadow-lg h-full">
            <div className="p-4 border-b border-border-subtle flex flex-wrap items-center justify-between gap-4 bg-bg-main">
              <div className="flex items-center text-foreground font-bold tracking-wider">
                <LayoutGrid className="w-5 h-5 mr-2 text-accent-primary" />
                IP INVENTORY
              </div>

              <div className="flex gap-2 items-center flex-wrap">
                <SearchInput
                  value={search}
                  onChange={setSearch}
                  placeholder="IP, MAC, host..."
                  className="w-48"
                />
                <CustomSelect
                  value={statusFilter}
                  onChange={setStatusFilter}
                  options={[
                    { value: "all", label: "All Status" },
                    { value: "online", label: "Online" },
                    { value: "offline", label: "Offline" },
                    { value: "reserved", label: "Reserved" },
                    { value: "alerts", label: "Alerts Only" },
                  ]}
                  className="w-36"
                />
                <CustomSelect
                  value={subnetFilter}
                  onChange={setSubnetFilter}
                  options={[
                    { value: "all", label: "All Subnets" },
                    ...subnets.map((s) => ({
                      value: s.id,
                      label: s.name || s.network,
                    })),
                  ]}
                  className="w-48"
                />
                <button className="p-1.5 text-amber-500 hover:bg-amber-500/10 rounded-full border border-amber-500/30 transition-colors ml-2">
                  <Bell className="w-4 h-4" />
                </button>
                <button
                  onClick={fetchData}
                  className="p-1.5 text-text-muted hover:text-foreground hover:bg-bg-card rounded-full border border-border-subtle transition-colors"
                >
                  <History className="w-4 h-4" />
                </button>
              </div>
            </div>

            <div className="overflow-x-auto min-h-[400px]">
              <table className="w-full text-left text-sm whitespace-nowrap">
                <thead className="text-text-muted text-xs font-bold bg-bg-card border-b border-border-subtle">
                  <tr>
                    <th className="p-3 w-10 text-center">
                      <input
                        type="checkbox"
                        onChange={toggleSelectAll}
                        checked={
                          filteredIps.length > 0 &&
                          selectedIps.size === filteredIps.length
                        }
                        className="rounded border-border-subtle bg-bg-main"
                      />
                    </th>
                    <th className="p-3 uppercase tracking-wider">IP</th>
                    <th className="p-3 uppercase tracking-wider">MAC</th>
                    <th className="p-3 uppercase tracking-wider">
                      Hostname / Owner
                    </th>
                    <th className="p-3 uppercase tracking-wider text-center">
                      Status
                    </th>
                    <th className="p-3 uppercase tracking-wider text-center">
                      Ping
                    </th>
                    <th className="p-3 uppercase tracking-wider">VLAN</th>
                    <th className="p-3 uppercase tracking-wider">First Seen</th>
                    <th className="p-3 uppercase tracking-wider">Last Seen</th>
                    <th className="p-3 uppercase tracking-wider">Notes</th>
                    <th className="p-3 uppercase tracking-wider text-right">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-subtle">
                  {loading ? (
                    <tr>
                      <td
                        colSpan={11}
                        className="p-12 text-center text-text-muted"
                      >
                        <History className="w-6 h-6 animate-spin mx-auto mb-2 opacity-50" />{" "}
                        Loading...
                      </td>
                    </tr>
                  ) : filteredIps.length === 0 ? (
                    <tr>
                      <td
                        colSpan={11}
                        className="p-12 text-center text-text-muted"
                      >
                        No IPs found.
                      </td>
                    </tr>
                  ) : (
                    filteredIps.map((device, idx) => (
                      <tr
                        key={idx}
                        className="hover:bg-bg-card transition-colors group"
                      >
                        <td className="p-3 text-center">
                          <input
                            type="checkbox"
                            checked={selectedIps.has(device.ip)}
                            onChange={() => toggleSelect(device.ip)}
                            className="rounded border-border-subtle bg-bg-main"
                          />
                        </td>
                        <td className="p-3 font-medium text-foreground">
                          {device.ip}
                        </td>
                        <td className="p-3 text-text-muted font-mono text-xs">
                          {device.mac || "—"}
                        </td>
                        <td className="p-3 text-text-muted">
                          {device.assigned_to || "—"}
                        </td>
                        <td className="p-3 text-center">
                          <span
                            className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider ${
                              device.status === "online" ||
                              device.status === "active"
                                ? "bg-emerald-500/10 text-emerald-400"
                                : device.status === "reserved"
                                  ? "bg-amber-500/10 text-amber-400"
                                  : "bg-slate-500/10 text-slate-400"
                            }`}
                          >
                            {device.status}
                          </span>
                        </td>
                        <td className="p-3 text-center">
                          <button
                            onClick={() => handlePing(device.ip)}
                            disabled={pinging[device.ip]}
                            className="text-text-muted hover:text-foreground transition-colors disabled:opacity-50"
                          >
                            {pinging[device.ip] ? (
                              <History className="w-4 h-4 animate-spin mx-auto" />
                            ) : (
                              <Zap className="w-4 h-4 mx-auto" />
                            )}
                          </button>
                        </td>
                        <td className="p-3 text-text-muted">
                          {device.vlan || "—"}
                        </td>
                        <td className="p-3 text-text-muted text-xs">
                          {device.first_seen
                            ? new Date(device.first_seen).toLocaleDateString()
                            : "—"}
                        </td>
                        <td className="p-3 text-text-muted text-xs">
                          {device.last_seen
                            ? new Date(device.last_seen).toLocaleDateString()
                            : "—"}
                        </td>
                        <td
                          className="p-3 text-text-muted max-w-[150px] truncate"
                          title={device.notes}
                        >
                          {device.notes || "—"}
                        </td>
                        <td className="p-3 text-right">
                          <div className="flex items-center justify-end gap-2">
                            {device.alert_type &&
                              !device.acknowledged &&
                              device.log_id && (
                                <button
                                  onClick={() =>
                                    handleAck(device.log_id!, device.ip)
                                  }
                                  className="text-amber-400 hover:text-amber-300 transition-colors p-1"
                                  title="Acknowledge Alert"
                                >
                                  <ShieldAlert className="w-4 h-4" />
                                </button>
                              )}
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      {/* Modals */}
      <Modal
        isOpen={showAddIpModal}
        onClose={() => setShowAddIpModal(false)}
        title="Add IP Address"
        icon={<Plus className="w-5 h-5" />}
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowAddIpModal(false)}>Cancel</Button>
            <Button variant="primary" onClick={handleSaveIp} isLoading={isSaving}>Save IP</Button>
          </>
        }
      >
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-text-muted mb-2">IP Address</label>
            <input type="text" value={addIpForm.ip} onChange={e => setAddIpForm({...addIpForm, ip: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="e.g. 192.168.1.50" />
          </div>
          <div>
            <label className="block text-sm font-medium text-text-muted mb-2">MAC Address</label>
            <input type="text" value={addIpForm.mac} onChange={e => setAddIpForm({...addIpForm, mac: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="e.g. 00:1A:2B:3C:4D:5E" />
          </div>
          <div>
            <label className="block text-sm font-medium text-text-muted mb-2">Assigned To</label>
            <input type="text" value={addIpForm.assigned_to} onChange={e => setAddIpForm({...addIpForm, assigned_to: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="e.g. Web Server" />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-text-muted mb-2">Status</label>
              <CustomSelect
                value={addIpStatus}
                onChange={setAddIpStatus}
                options={[
                  { value: "active", label: "Active" },
                  { value: "reserved", label: "Reserved" },
                  { value: "offline", label: "Offline" },
                ]}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-text-muted mb-2">VLAN</label>
              <input type="text" value={addIpForm.vlan} onChange={e => setAddIpForm({...addIpForm, vlan: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="e.g. 100" />
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-text-muted mb-2">Notes</label>
            <textarea rows={2} value={addIpForm.notes} onChange={e => setAddIpForm({...addIpForm, notes: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="Optional notes..."></textarea>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={showAddSubnetModal}
        onClose={() => setShowAddSubnetModal(false)}
        title="Add Subnet"
        icon={<Plus className="w-5 h-5" />}
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowAddSubnetModal(false)}>Cancel</Button>
            <Button variant="primary" onClick={handleSaveSubnet} isLoading={isSaving}>Save Subnet</Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-text-muted mb-2">
                CIDR <span className="text-red-500">*</span>
              </label>
              <input type="text" value={addSubnetForm.cidr} onChange={e => setAddSubnetForm({...addSubnetForm, cidr: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="192.168.1.0/24" />
            </div>
            <div>
              <label className="block text-sm font-medium text-text-muted mb-2">VLAN ID</label>
              <input type="text" value={addSubnetForm.vlan_id} onChange={e => setAddSubnetForm({...addSubnetForm, vlan_id: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="10" />
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-text-muted mb-2">Label / Name</label>
            <input type="text" value={addSubnetForm.label} onChange={e => setAddSubnetForm({...addSubnetForm, label: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="Office LAN" />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-text-muted mb-2">Interface Override</label>
              <input type="text" value={addSubnetForm.interface} onChange={e => setAddSubnetForm({...addSubnetForm, interface: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="Auto-detect" />
              <p className="text-xs text-text-muted opacity-60 mt-1">Leave blank to auto-detect via routing table</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-text-muted mb-2">Scan Enabled</label>
              <CustomSelect
                value={addSubnetScan}
                onChange={setAddSubnetScan}
                options={[
                  { value: "yes", label: "Yes - include in auto-scan" },
                  { value: "no", label: "No - exclude" },
                ]}
              />
            </div>
          </div>
          <div>
            <label className="block text-sm font-medium text-text-muted mb-2">Description</label>
            <textarea rows={2} value={addSubnetForm.description} onChange={e => setAddSubnetForm({...addSubnetForm, description: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="e.g. Server VLAN, 3rd floor..."></textarea>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={showAddMappingModal}
        onClose={() => setShowAddMappingModal(false)}
        title="Add NAT / VIP Mapping"
        icon={<Plus className="w-5 h-5" />}
        footer={
          <>
            <Button variant="secondary" onClick={() => setShowAddMappingModal(false)}>Cancel</Button>
            <Button variant="primary" onClick={handleSaveMapping} isLoading={isSaving}>Save Mapping</Button>
          </>
        }
      >
        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-text-muted mb-2">
              Virtual / Public IP <span className="text-red-500">*</span>
            </label>
            <input type="text" value={addMappingForm.public_ip} onChange={e => setAddMappingForm({...addMappingForm, public_ip: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="e.g. 203.0.113.10" />
            <p className="text-xs text-text-muted opacity-60 mt-1">The IP address that external/firewall traffic arrives on</p>
          </div>
          <div>
            <label className="block text-sm font-medium text-text-muted mb-2">
              Real Internal IP <span className="text-red-500">*</span>
            </label>
            <input type="text" value={addMappingForm.private_ip} onChange={e => setAddMappingForm({...addMappingForm, private_ip: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="e.g. 172.17.125.10" />
            <p className="text-xs text-text-muted opacity-60 mt-1">The actual host IP this forwards/maps to</p>
          </div>
          <div>
            <label className="block text-sm font-medium text-text-muted mb-2">Type</label>
            <CustomSelect
              value={addMappingType}
              onChange={setAddMappingType}
              options={[
                { value: "dnat", label: "DNAT (Destination NAT / Port Forward)" },
                { value: "snat", label: "SNAT (Source NAT)" },
                { value: "1to1", label: "1:1 NAT (Static)" },
              ]}
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-text-muted mb-2">Description</label>
            <input type="text" value={addMappingForm.description} onChange={e => setAddMappingForm({...addMappingForm, description: e.target.value})} className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary" placeholder="e.g. Web server public access" />
          </div>
        </div>
      </Modal>

    </div>
  );
}
