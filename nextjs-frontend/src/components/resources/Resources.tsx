"use client";

import { useEffect, useState, useCallback } from "react";
import {
  Server,
  Activity,
  Cpu,
  Database,
  Network,
  ArrowUpRight,
  ArrowDownRight,
  Search,
  Filter,
  LineChart,
  BarChart2,
  HardDrive,
  Clock,
} from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import CircularGauge from "@/components/ui/CircularGauge";
import { ResourceDevice as Device, Totals } from "@/types/metrics";
import { monitorService } from "@/services/monitor/monitorService";

export function Resources() {
  const [devices, setDevices] = useState<Device[]>([]);
  const [allDevices, setAllDevices] = useState<
    { id: number; name: string; ip: string }[]
  >([]);
  const [totals, setTotals] = useState<Totals>({
    devices: 0,
    monitored_devices: 0,
    interfaces: 0,
    avg_cpu: 0,
    avg_mem: 0,
    avg_disk: 0,
    traffic_in_bps: 0,
    traffic_out_bps: 0,
  });
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [selectedDevice, setSelectedDevice] = useState<string>("");
  const [selectedInterface, setSelectedInterface] = useState<
    Record<number, string>
  >({});

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const data = await monitorService.getResources();
      if (data) {
        setDevices((data as any).devices || []);
        setTotals((data as any).totals || totals);
        setAllDevices((data as any).all_devices_list || []);
      }
    } catch (err) {
      console.error("Failed to fetch resource metrics:", err);
    } finally {
      setLoading(false);
    }
  }, [selectedDevice]);

  useEffect(() => {
    fetchData();
    const interval = setInterval(fetchData, 30000); // 30s auto-refresh
    return () => clearInterval(interval);
  }, [fetchData]);

  const formatBits = (b: number | null | undefined) => {
    if (!b || isNaN(b)) return "0 bps";
    if (b < 1000) return b.toFixed(1) + " bps";
    if (b < 1e6) return (b / 1e3).toFixed(1) + " Kbps";
    if (b < 1e9) return (b / 1e6).toFixed(1) + " Mbps";
    return (b / 1e9).toFixed(1) + " Gbps";
  };

  const formatBytes = (b: number | null | undefined) => {
    if (!b || isNaN(b) || b <= 0) return "0 B";
    const u = ["B", "KB", "MB", "GB", "TB"];
    const pow = Math.floor(Math.log(b) / Math.log(1024));
    const val = b / Math.pow(1024, pow);
    return val.toFixed(2) + " " + u[Math.min(pow, u.length - 1)];
  };

  const filteredDevices = devices.filter((d) => {
    const matchesSearch =
      d.name.toLowerCase().includes(search.toLowerCase()) ||
      d.ip.includes(search);
    const matchesDeviceSelect =
      !selectedDevice || d.id.toString() === selectedDevice;
    return matchesSearch && matchesDeviceSelect;
  });

  // Compute SNMP Success rate
  const snmpSuccessRate = totals.devices > 0
    ? (totals.monitored_devices / totals.devices) * 100
    : 0;

  return (
    <div className="space-y-6 max-w-7xl mx-auto pb-10 page-enter">
      {/* Header */}
      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-wide flex items-center">
            <Network className="w-7 h-7 mr-3 text-accent-primary" /> Resource Utilization
          </h1>
          <p className="text-text-muted mt-1 text-sm md:text-base">
            Real-time monitoring of device performance
          </p>
        </div>
      </div>

      {/* Filter Bar */}
      <div className="bg-bg-raised border border-border-subtle rounded-2xl p-4 flex flex-col md:flex-row items-stretch md:items-center gap-4">
        <div className="flex items-center text-text-muted text-sm font-bold shrink-0">
          <Filter className="w-4 h-4 mr-2 text-accent-primary" /> Filter by Device
        </div>
        <div className="flex-1 flex flex-col sm:flex-row gap-3">
          <CustomSelect
            value={selectedDevice}
            onChange={setSelectedDevice}
            options={[
              { value: "", label: "All Devices" },
              ...allDevices.map((d) => ({
                value: d.id.toString(),
                label: `${d.name} (${d.ip})`,
              })),
            ]}
            placeholder="All Devices"
            className="w-full sm:w-64"
          />
          <div className="relative flex-1">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
            <input
              type="text"
              placeholder="Search by device name or IP..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full bg-bg-card border border-border-subtle rounded-2xl py-2 pl-9 pr-3 text-sm text-foreground focus:outline-none focus:border-accent-primary transition-all"
            />
          </div>
        </div>
      </div>

      {/* Top 4 Circular Gauges Summary Row */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-5 flex flex-col items-center justify-center text-center shadow-sm">
          <CircularGauge
            value={totals.avg_cpu}
            size={105}
            strokeWidth={9}
            variant="cpu"
          />
          <span className="mt-3 text-xs font-bold uppercase tracking-wider text-text-muted">
            Avg CPU
          </span>
        </div>

        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-5 flex flex-col items-center justify-center text-center shadow-sm">
          <CircularGauge
            value={totals.avg_mem}
            size={105}
            strokeWidth={9}
            variant="mem"
          />
          <span className="mt-3 text-xs font-bold uppercase tracking-wider text-text-muted">
            Avg Memory
          </span>
        </div>

        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-5 flex flex-col items-center justify-center text-center shadow-sm">
          <CircularGauge
            value={totals.avg_disk}
            size={105}
            strokeWidth={9}
            variant="disk"
          />
          <span className="mt-3 text-xs font-bold uppercase tracking-wider text-text-muted">
            Avg Disk
          </span>
        </div>

        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-5 flex flex-col items-center justify-center text-center shadow-sm">
          <CircularGauge
            value={snmpSuccessRate}
            size={105}
            strokeWidth={9}
            variant="snmp"
          />
          <span className="mt-3 text-xs font-bold uppercase tracking-wider text-text-muted">
            SNMP Success
          </span>
        </div>
      </div>

      {/* Middle Overview Cards: Network Bandwidth & Infrastructure */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Network Bandwidth */}
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-5 shadow-sm flex flex-col justify-between">
          <div className="flex items-center text-sm font-bold text-foreground mb-4 pb-2 border-b border-border-subtle">
            <LineChart className="w-4 h-4 mr-2 text-accent-primary" /> Network Bandwidth
          </div>
          <div className="grid grid-cols-2 gap-4 text-center my-2">
            <div className="bg-bg-card p-3 rounded-2xl border border-border-subtle">
              <div className="text-xs uppercase font-bold text-text-muted mb-1 flex items-center justify-center">
                <ArrowDownRight className="w-3.5 h-3.5 mr-1 text-blue-400" /> INBOUND
              </div>
              <div className="text-xl md:text-2xl font-black text-blue-400 font-mono">
                {formatBits(totals.traffic_in_bps)}
              </div>
            </div>

            <div className="bg-bg-card p-3 rounded-2xl border border-border-subtle">
              <div className="text-xs uppercase font-bold text-text-muted mb-1 flex items-center justify-center">
                <ArrowUpRight className="w-3.5 h-3.5 mr-1 text-accent-primary" /> OUTBOUND
              </div>
              <div className="text-xl md:text-2xl font-black text-accent-primary font-mono">
                {formatBits(totals.traffic_out_bps)}
              </div>
            </div>
          </div>
          {!totals.traffic_in_bps && !totals.traffic_out_bps && (
            <div className="text-xs text-text-muted italic text-center mt-2">
              Bandwidth data will update after active SNMP polling.
            </div>
          )}
        </div>

        {/* Infrastructure */}
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-5 shadow-sm flex flex-col justify-between">
          <div className="flex items-center text-sm font-bold text-foreground mb-4 pb-2 border-b border-border-subtle">
            <BarChart2 className="w-4 h-4 mr-2 text-accent-primary" /> Infrastructure
          </div>
          <div className="grid grid-cols-3 gap-4 text-center my-2">
            <div className="bg-bg-card p-3 rounded-2xl border border-border-subtle">
              <div className="text-xs uppercase font-bold text-text-muted mb-1">Devices</div>
              <div className="text-xl md:text-2xl font-black text-foreground font-mono">
                {totals.devices}
              </div>
            </div>

            <div className="bg-bg-card p-3 rounded-2xl border border-border-subtle">
              <div className="text-xs uppercase font-bold text-text-muted mb-1">SNMP OK</div>
              <div className="text-xl md:text-2xl font-black text-green-400 font-mono">
                {totals.monitored_devices}
              </div>
            </div>

            <div className="bg-bg-card p-3 rounded-2xl border border-border-subtle">
              <div className="text-xs uppercase font-bold text-text-muted mb-1">Interfaces</div>
              <div className="text-xl md:text-2xl font-black text-accent-primary font-mono">
                {totals.interfaces}
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Per-Device Grid */}
      <div>
        {loading && devices.length === 0 ? (
          <div className="flex justify-center items-center py-16">
            <div className="w-8 h-8 border-4 border-border-subtle border-t-accent-primary rounded-full animate-spin"></div>
          </div>
        ) : filteredDevices.length === 0 ? (
          <div className="bg-bg-raised border border-border-subtle rounded-2xl p-12 text-center text-text-muted">
            <Server className="w-12 h-12 mx-auto mb-3 opacity-30 text-accent-primary" />
            <h3 className="text-lg font-bold text-foreground mb-1">No Devices Found</h3>
            <p className="text-sm">No SNMP monitored devices match your criteria.</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {filteredDevices.map((device) => {
              const memPct = device.memory_total && device.memory_total > 0
                ? ((device.memory_used || 0) / device.memory_total) * 100
                : 0;
              const diskPct = device.disk_total && device.disk_total > 0
                ? ((device.disk_used || 0) / device.disk_total) * 100
                : 0;
              const cpuVal = device.cpu_usage ?? 0;

              // Aggregate device bandwidth
              let deviceInBps = 0;
              let deviceOutBps = 0;
              if (device.interfaces && device.interfaces.length > 0) {
                device.interfaces.forEach((intf) => {
                  deviceInBps += intf.traffic_in_bps || 0;
                  deviceOutBps += intf.traffic_out_bps || 0;
                });
              }

              const deviceInterfaceList = device.interfaces || [];
              const currentInterfaceSelection = selectedInterface[device.id] || "";

              const displayedInterfaces = currentInterfaceSelection
                ? deviceInterfaceList.filter(
                    (intf) => intf.if_index.toString() === currentInterfaceSelection
                  )
                : deviceInterfaceList;

              return (
                <div
                  key={device.id}
                  className="bg-bg-raised border border-border-subtle rounded-2xl p-5 shadow-sm hover:border-accent-primary/50 transition-all flex flex-col justify-between"
                >
                  <div>
                    {/* Device Header */}
                    <div className="flex justify-between items-start mb-4 border-b border-border-subtle pb-3">
                      <div>
                        <h3 className="text-base font-bold text-foreground tracking-wide flex items-center">
                          {device.name}
                          {device.ping_status === "up" ? (
                            <span className="ml-3 px-2 py-0.5 bg-green-500/10 text-green-400 border border-green-500/30 rounded-full text-xs font-semibold uppercase tracking-wider">
                              UP
                            </span>
                          ) : (
                            <span className="ml-3 px-2 py-0.5 bg-red-500/10 text-red-400 border border-red-500/30 rounded-full text-xs font-semibold uppercase tracking-wider animate-pulse">
                              DOWN
                            </span>
                          )}
                        </h3>
                        <p className="text-xs text-text-muted mt-1 font-mono">
                          {device.ip} {device.type || device.model ? `• ${device.type || ""} ${device.model || ""}` : ""}
                        </p>
                      </div>
                    </div>

                    {/* 3 Circular Gauges for Device Metrics */}
                    <div className="grid grid-cols-3 gap-2 py-2 text-center border-b border-border-subtle mb-4">
                      <div>
                        <CircularGauge
                          value={cpuVal}
                          size={70}
                          strokeWidth={6}
                          variant="cpu"
                        />
                        <span className="text-[11px] font-bold uppercase tracking-wider text-text-muted mt-1 block">
                          CPU
                        </span>
                      </div>

                      <div>
                        <CircularGauge
                          value={memPct}
                          size={70}
                          strokeWidth={6}
                          variant="mem"
                        />
                        <span className="text-[11px] font-bold uppercase tracking-wider text-text-muted mt-1 block">
                          MEM
                        </span>
                      </div>

                      <div>
                        <CircularGauge
                          value={diskPct}
                          size={70}
                          strokeWidth={6}
                          variant="disk"
                        />
                        <span className="text-[11px] font-bold uppercase tracking-wider text-text-muted mt-1 block">
                          DISK
                        </span>
                      </div>
                    </div>

                    {/* Detailed Metadata Lines */}
                    <div className="space-y-2 text-xs text-foreground font-medium mb-4">
                      <div className="flex items-center">
                        <Database className="w-3.5 h-3.5 mr-2 text-text-muted shrink-0" />
                        <span className="text-text-muted mr-1">Memory:</span>
                        {device.memory_total ? (
                          <span>
                            {formatBytes(device.memory_used)} / {formatBytes(device.memory_total)}{" "}
                            <span className="text-text-muted">({memPct.toFixed(1)}%)</span>
                          </span>
                        ) : (
                          <span className="text-text-muted italic">No data</span>
                        )}
                      </div>

                      <div className="flex items-center">
                        <HardDrive className="w-3.5 h-3.5 mr-2 text-text-muted shrink-0" />
                        <span className="text-text-muted mr-1">Disk:</span>
                        {device.disk_total ? (
                          <span>
                            {formatBytes(device.disk_used)} / {formatBytes(device.disk_total)}{" "}
                            <span className="text-text-muted">({diskPct.toFixed(1)}%)</span>
                          </span>
                        ) : (
                          <span className="text-text-muted italic">No data</span>
                        )}
                      </div>

                      <div className="flex items-center">
                        <Network className="w-3.5 h-3.5 mr-2 text-text-muted shrink-0" />
                        <span className="text-text-muted mr-1">BW:</span>
                        <span className="text-blue-400 font-mono font-bold mr-1">
                          {formatBits(deviceInBps)} IN
                        </span>
                        <span className="text-text-muted mr-1">/</span>
                        <span className="text-accent-primary font-mono font-bold">
                          {formatBits(deviceOutBps)} OUT
                        </span>
                      </div>

                      <div className="flex items-center">
                        <Clock className="w-3.5 h-3.5 mr-2 text-text-muted shrink-0" />
                        <span className="text-text-muted mr-1">Last SNMP:</span>
                        <span>{device.last_snmp_check || "N/A"}</span>
                      </div>
                    </div>
                  </div>

                  {/* Interfaces Dropdown with CustomSelect */}
                  {deviceInterfaceList.length > 0 && (
                    <div className="mt-3 border-t border-border-subtle pt-3 space-y-2">
                      <CustomSelect
                        value={currentInterfaceSelection}
                        onChange={(val) =>
                          setSelectedInterface((prev) => ({
                            ...prev,
                            [device.id]: val,
                          }))
                        }
                        options={[
                          {
                            value: "",
                            label: `Interfaces (${deviceInterfaceList.length})`,
                          },
                          ...deviceInterfaceList.map((intf) => ({
                            value: intf.if_index.toString(),
                            label: `${intf.if_name || intf.if_descr || `Interface ${intf.if_index}`} — IN: ${formatBits(intf.traffic_in_bps)} | OUT: ${formatBits(intf.traffic_out_bps)}`,
                          })),
                        ]}
                        placeholder={`Interfaces (${deviceInterfaceList.length})`}
                        className="w-full"
                      />

                      {/* Interfaces Table */}
                      <div className="overflow-hidden border border-border-subtle rounded-xl bg-bg-card max-h-56 overflow-y-auto custom-scrollbar">
                        <table className="w-full text-xs text-left border-collapse">
                          <thead className="sticky top-0 z-10 bg-bg-main border-b border-border-subtle text-text-muted font-bold uppercase tracking-wider">
                            <tr>
                              <th className="p-2.5">Name</th>
                              <th className="p-2.5 text-right">IN</th>
                              <th className="p-2.5 text-right">OUT</th>
                            </tr>
                          </thead>
                          <tbody>
                            {displayedInterfaces.map((intf, idx) => (
                              <tr
                                key={intf.if_index || idx}
                                className={`border-b border-border-subtle/50 last:border-0 hover:bg-bg-main/40 transition-colors font-mono ${
                                  currentInterfaceSelection === intf.if_index.toString()
                                    ? "bg-accent-primary/10"
                                    : ""
                                }`}
                              >
                                <td
                                  className="p-2.5 font-sans font-medium text-foreground truncate max-w-[160px]"
                                  title={intf.if_descr || intf.if_name}
                                >
                                  {intf.if_name || intf.if_descr || `Interface ${intf.if_index}`}
                                </td>
                                <td className="p-2.5 text-right text-blue-400 font-bold">
                                  {formatBits(intf.traffic_in_bps)}
                                </td>
                                <td className="p-2.5 text-right text-accent-primary font-bold">
                                  {formatBits(intf.traffic_out_bps)}
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
            })}
          </div>
        )}
      </div>
    </div>
  );
}

export default Resources;
