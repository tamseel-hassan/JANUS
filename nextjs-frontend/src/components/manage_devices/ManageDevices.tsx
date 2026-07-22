"use client";

import { useEffect, useState, useCallback } from "react";
import {
  Network,
  Server,
  Shield,
  Wifi,
  HardDrive,
  Cpu,
  Plus,
  Edit,
  Trash2,
  Link as LinkIcon,
  Box,
  ShieldCheck,
  ShieldAlert,
  Search,
  MapPin,
} from "lucide-react";
import { Device, Link } from "@/types/manage_devices";
import { DeviceModal } from "@/components/manage_devices/DeviceModal";
import { LinkModal } from "@/components/manage_devices/LinkModal";
import { safeFetch } from "@/lib/safeFetch";
import { Button } from "@/components/ui/Button";
import { StatCard } from "@/components/ui/StatCard";
import { Tabs } from "@/components/ui/Tabs";
import { confirmDialog } from "@/lib/use-confirm";
import { toast } from "sonner";

export function ManageDevices() {
  const [devices, setDevices] = useState<Device[]>([]);
  const [links, setLinks] = useState<Link[]>([]);
  const [models, setModels] = useState<Record<string, string[]>>({});
  const [loading, setLoading] = useState(true);

  const [activeTab, setActiveTab] = useState<"devices" | "links">("devices");
  const [search, setSearch] = useState("");

  // Modals
  const [showDeviceModal, setShowDeviceModal] = useState(false);
  const [showLinkModal, setShowLinkModal] = useState(false);

  // Forms
  const [editingDevice, setEditingDevice] = useState<Device | null>(null);
  const [editingLink, setEditingLink] = useState<Link | null>(null);

  const emptyDevice: Device = {
    id: 0,
    name: "",
    type: "server",
    ip: "",
    model: "",
    city: "",
    sub_office: "",
    country: "",
    contact_number: "",
    email: "",
    snmp_community: "",
    snmp_version: "2c",
    snmp_port: 161,
  };
  const emptyLink: Link = {
    id: 0,
    name: "",
    ip: "",
    from_device_id: 0,
    to_device_id: 0,
  };

  const [deviceForm, setDeviceForm] = useState<Device>(emptyDevice);
  const [customModel, setCustomModel] = useState("");

  const [linkForm, setLinkForm] = useState<Link>(emptyLink);

  const fetchData = useCallback(async () => {
    setLoading(true);
    const data = await safeFetch<{
      devices: Device[];
      links: Link[];
      models: Record<string, string[]>;
    }>("/api/get_devices.php", {}, "ManageDevices");
    if (data) {
      setDevices(data.devices || []);
      setLinks(data.links || []);
      setModels(data.models || {});
    }
    setLoading(false);
  }, []);

  useEffect(() => {
    fetchData();
  }, [fetchData]);



  const handleDeviceAction = async (e: React.FormEvent) => {
    e.preventDefault();
    const action = editingDevice ? "edit_device" : "add_device";
    const payload = { action, ...deviceForm, custom_model: customModel };

    try {
      const res = await fetch("/api/post_devices.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
        credentials: "include",
      });
      const data = await res.json();
      if (data.success) {
        toast.success( data.success);
        setShowDeviceModal(false);
        fetchData();
      } else {
        toast.error( data.error);
      }
    } catch (err: any) {
      toast.error( err.message);
    }
  };

  const handleDeleteDevice = async (id: number, name: string) => {
    const ok = await confirmDialog({
      title: "Delete Device",
      description: `Are you sure you want to delete ${name}? This will also delete all associated logs and metrics.`,
      variant: "destructive",
      confirmText: "Delete"
    });
    if (!ok) return;
    try {
      const res = await fetch("/api/post_devices.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete_device", id }),
        credentials: "include",
      });
      const data = await res.json();
      if (data.success) {
        toast.success( data.success);
        fetchData();
      } else toast.error( data.error);
    } catch (err: any) {
      toast.error( err.message);
    }
  };

  const handleLinkAction = async (e: React.FormEvent) => {
    e.preventDefault();
    const action = editingLink ? "edit_link" : "add_link";
    const payload = { action, ...linkForm };
    try {
      const res = await fetch("/api/post_devices.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
        credentials: "include",
      });
      const data = await res.json();
      if (data.success) {
        toast.success( data.success);
        setShowLinkModal(false);
        fetchData();
      } else {
        toast.error( data.error);
      }
    } catch (err: any) {
      toast.error( err.message);
    }
  };

  const handleDeleteLink = async (id: number, name: string) => {
    const ok = await confirmDialog({
      title: "Delete Link",
      description: `Are you sure you want to delete link ${name}?`,
      variant: "destructive",
      confirmText: "Delete"
    });
    if (!ok) return;
    try {
      const res = await fetch("/api/post_devices.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete_link", id }),
        credentials: "include",
      });
      const data = await res.json();
      if (data.success) {
        toast.success( data.success);
        fetchData();
      } else toast.error( data.error);
    } catch (err: any) {
      toast.error( err.message);
    }
  };

  const getDeviceIcon = (type: string) => {
    switch (type.toLowerCase()) {
      case "server":
        return <Server className="w-4 h-4 text-blue-400 mr-2 inline" />;
      case "switch":
        return <Network className="w-4 h-4 text-purple-400 mr-2 inline" />;
      case "router":
        return <Box className="w-4 h-4 text-green-400 mr-2 inline" />;
      case "firewall":
        return <Shield className="w-4 h-4 text-red-400 mr-2 inline" />;
      case "wireless":
        return <Wifi className="w-4 h-4 text-yellow-400 mr-2 inline" />;
      case "storage":
        return <HardDrive className="w-4 h-4 text-orange-400 mr-2 inline" />;
      case "iot":
        return <Cpu className="w-4 h-4 text-cyan-400 mr-2 inline" />;
      default:
        return <Server className="w-4 h-4 text-gray-400 mr-2 inline" />;
    }
  };

  const typeCounts = devices.reduce(
    (acc, dev) => {
      acc[dev.type] = (acc[dev.type] || 0) + 1;
      return acc;
    },
    {} as Record<string, number>,
  );

  const filteredDevices = devices.filter(
    (d) =>
      d.name.toLowerCase().includes(search.toLowerCase()) ||
      d.ip.includes(search) ||
      d.type.toLowerCase().includes(search.toLowerCase()),
  );
  const filteredLinks = links.filter(
    (l) =>
      l.name.toLowerCase().includes(search.toLowerCase()) ||
      l.ip.includes(search),
  );

  return (
    <div className="space-y-6 max-w-7xl mx-auto relative pb-10">
      {/* Header */}
      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-wide flex items-center">
            <Network className="w-8 h-8 mr-3 text-accent-primary" /> Network
            Infrastructure
          </h1>
          <p className="text-text-muted mt-1 text-lg">
            Manage all network devices, appliances, and interconnections
          </p>
        </div>
        <div className="flex space-x-3">
          <Button
            onClick={() => {
              setEditingDevice(null);
              setDeviceForm(emptyDevice);
              setCustomModel("");
              setShowDeviceModal(true);
            }}
          >
            <Plus className="w-5 h-5 mr-2" /> Add Device
          </Button>
          <Button
            variant="ghost"
            className="bg-blue-600 hover:bg-blue-500 text-foreground px-4 py-2 font-bold flex items-center"
            onClick={() => {
              setEditingLink(null);
              setLinkForm(emptyLink);
              setShowLinkModal(true);
            }}
          >
            <LinkIcon className="w-5 h-5 mr-2" /> Add Link
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <StatCard title="Total Devices" value={devices.length} />
        <StatCard
          title="Device Types"
          value={Object.keys(typeCounts).length}
          color="text-accent-primary"
        />
        <StatCard
          title="Network Links"
          value={links.length}
          color="text-blue-400"
        />
      </div>

      {/* Tabs */}
      <div className="mb-4 pt-2">
        <Tabs
          tabs={[
            { id: "devices", label: "DEVICES" },
            { id: "links", label: "LINKS" },
          ]}
          activeTab={activeTab}
          onChange={(id) => setActiveTab(id as "devices" | "links")}
        />
      </div>

      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden flex flex-col">
        <div className="p-4 border-b border-border-subtle bg-bg-main flex gap-4 items-center">
          <div className="relative flex-1 max-w-md">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
            <input
              type="text"
              placeholder={`Search ${activeTab}...`}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full bg-bg-card border border-border-subtle rounded-2xl py-2 pl-10 pr-3 text-foreground focus:outline-none focus:border-accent-primary"
            />
          </div>
        </div>

        <div className="flex-1 overflow-auto hide-scrollbar h-[500px]">
          <div key={activeTab} className="page-enter h-full">
            {activeTab === "devices" && (
            <table className="w-full text-left text-sm whitespace-nowrap">
              <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle sticky top-0 z-10">
                <tr>
                  <th className="px-6 py-4 font-bold">Device Name</th>
                  <th className="px-6 py-4 font-bold">IP Address</th>
                  <th className="px-6 py-4 font-bold">Type</th>
                  <th className="px-6 py-4 font-bold">Model</th>
                  <th className="px-6 py-4 font-bold">Location</th>
                  <th className="px-6 py-4 font-bold text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {loading && (
                  <tr className="animate-pulse">
                    <td colSpan={6} className="px-6 py-10 bg-bg-card/20"></td>
                  </tr>
                )}
                {filteredDevices.map((d) => (
                  <tr
                    key={d.id}
                    className="hover:bg-bg-card/50 transition-colors"
                  >
                    <td className="px-6 py-3 font-bold text-foreground">
                      {d.name}
                    </td>
                    <td className="px-6 py-3 font-mono text-xs text-text-muted">
                      {d.ip}
                    </td>
                    <td className="px-6 py-3 capitalize">
                      {getDeviceIcon(d.type)} {d.type}
                    </td>
                    <td className="px-6 py-3 text-[#d8cce2]">{d.model}</td>
                    <td className="px-6 py-3">
                      <div className="flex flex-col">
                        <span className="text-text-muted text-xs">
                          <MapPin className="w-3 h-3 inline mr-1" />
                          {d.city || "N/A"}, {d.country}
                        </span>
                      </div>
                    </td>
                    <td className="px-6 py-3 text-right">
                      <Button
                        variant="outline-primary"
                        size="icon"
                        onClick={() => {
                          setEditingDevice(d);
                          setDeviceForm(d);
                          setCustomModel("");
                          setShowDeviceModal(true);
                        }}
                        className="mr-2"
                        title="Edit"
                      >
                        <Edit className="w-4 h-4" />
                      </Button>
                      <Button
                        variant="outline-danger"
                        size="icon"
                        onClick={() => handleDeleteDevice(d.id, d.name)}
                        title="Delete"
                      >
                        <Trash2 className="w-4 h-4" />
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {activeTab === "links" && (
            <table className="w-full text-left text-sm whitespace-nowrap">
              <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle sticky top-0 z-10">
                <tr>
                  <th className="px-6 py-4 font-bold">Link Name</th>
                  <th className="px-6 py-4 font-bold">IP/Subnet</th>
                  <th className="px-6 py-4 font-bold">From Device</th>
                  <th className="px-6 py-4 font-bold">To Device</th>
                  <th className="px-6 py-4 font-bold text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border-subtle">
                {loading && (
                  <tr className="animate-pulse">
                    <td colSpan={5} className="px-6 py-10 bg-bg-card/20"></td>
                  </tr>
                )}
                {filteredLinks.map((l) => (
                  <tr
                    key={l.id}
                    className="hover:bg-bg-card/50 transition-colors"
                  >
                    <td className="px-6 py-4 font-bold text-foreground">
                      {l.name}
                    </td>
                    <td className="px-6 py-4 font-mono text-xs text-text-muted">
                      {l.ip}
                    </td>
                    <td className="px-6 py-4 text-blue-300 font-semibold">
                      {l.from_name}
                    </td>
                    <td className="px-6 py-4 text-purple-300 font-semibold">
                      {l.to_name}
                    </td>
                    <td className="px-6 py-4 text-right">
                      <Button
                        variant="outline-primary"
                        size="icon"
                        onClick={() => {
                          setEditingLink(l);
                          setLinkForm(l);
                          setShowLinkModal(true);
                        }}
                        className="mr-2"
                        title="Edit"
                      >
                        <Edit className="w-4 h-4" />
                      </Button>
                      <Button
                        variant="outline-danger"
                        size="icon"
                        onClick={() => handleDeleteLink(l.id, l.name)}
                        title="Delete"
                      >
                        <Trash2 className="w-4 h-4" />
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          </div>
        </div>
      </div>

      <DeviceModal
        isOpen={showDeviceModal}
        editingDevice={editingDevice}
        deviceForm={deviceForm}
        setDeviceForm={setDeviceForm}
        customModel={customModel}
        setCustomModel={setCustomModel}
        models={models}
        handleDeviceAction={handleDeviceAction}
        onClose={() => setShowDeviceModal(false)}
      />

      <LinkModal
        isOpen={showLinkModal}
        editingLink={editingLink}
        linkForm={linkForm}
        setLinkForm={setLinkForm}
        devices={devices}
        handleLinkAction={handleLinkAction}
        onClose={() => setShowLinkModal(false)}
      />
    </div>
  );
}
export default ManageDevices;
