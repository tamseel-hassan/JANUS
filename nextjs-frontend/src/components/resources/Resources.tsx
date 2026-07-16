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
} from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import { ResourceDevice as Device, Totals } from "@/types/metrics";
import { StatCard } from "@/components/ui/StatCard";
import { safeFetch } from "@/lib/safeFetch";

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

  const fetchData = useCallback(async () => {
    setLoading(true);
    const url = selectedDevice
      ? `/api/get_resources.php?device=${selectedDevice}`
      : "/api/get_resources.php";
    const data = await safeFetch<{ devices: Device[]; totals: Totals; all_devices_list: { id: number; name: string; ip: string }[] }>(
      url, {}, "Resources"
    );
    if (data) {
      setDevices(data.devices || []);
      setTotals(data.totals || totals);
      setAllDevices(data.all_devices_list || []);
    }
    setLoading(false);
  }, [selectedDevice, totals]);

  useEffect(() => {
    fetchData();
    const interval = setInterval(fetchData, 30000); // 30s auto-refresh
    return () => clearInterval(interval);
  }, [fetchData]);

  const formatBits = (b: number) => {
    if (!b) return "0 bps";
    if (b < 1000) return b.toFixed(1) + " bps";
    if (b < 1e6) return (b / 1e3).toFixed(1) + " Kbps";
    if (b < 1e9) return (b / 1e6).toFixed(1) + " Mbps";
    return (b / 1e9).toFixed(1) + " Gbps";
  };

  const formatBytes = (b: number | null) => {
    if (!b) return "0 B";
    const u = ["B", "KB", "MB", "GB", "TB"];
    const pow = Math.floor(Math.log(b) / Math.log(1024));
    return (b / Math.pow(1024, pow)).toFixed(2) + " " + u[pow];
  };

  const filteredDevices = devices.filter(
    (d) =>
      d.name.toLowerCase().includes(search.toLowerCase()) ||
      d.ip.includes(search),
  );

  return (
    <div className="space-y-6 max-w-7xl mx-auto pb-10">
      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-wide flex items-center">
            <Server className="w-8 h-8 mr-3 text-accent-primary" /> Resource
            Utilization
          </h1>
          <p className="text-text-muted mt-1 text-lg">
            System metrics and interface traffic monitoring
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <StatCard
          title="Average CPU"
          value={`${totals.avg_cpu}%`}
          watermarkIcon={<Cpu className="w-24 h-24" />}
        />
        <StatCard
          title="Average Memory"
          value={`${totals.avg_mem}%`}
          watermarkIcon={<Database className="w-24 h-24" />}
        />
        <StatCard
          title="Total Traffic In"
          value={formatBits(totals.traffic_in_bps)}
          color="text-blue-400"
          watermarkIcon={<ArrowDownRight className="w-24 h-24 text-blue-500" />}
        />
        <StatCard
          title="Total Traffic Out"
          value={formatBits(totals.traffic_out_bps)}
          color="text-accent-primary"
          watermarkIcon={<ArrowUpRight className="w-24 h-24 text-accent-primary" />}
        />
      </div>

      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden flex flex-col">
        <div className="p-4 border-b border-border-subtle bg-bg-main flex gap-4 items-center">
          <div className="relative flex-1 max-w-md">
            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" />
            <input
              type="text"
              placeholder="Search devices..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full bg-bg-card border border-border-subtle rounded-2xl py-2 pl-10 pr-3 text-foreground focus:outline-none focus:border-accent-primary"
            />
          </div>
          <CustomSelect
            value={selectedDevice}
            onChange={setSelectedDevice}
            options={[
              { value: "", label: "All SNMP Devices" },
              ...allDevices.map((d) => ({
                value: d.id.toString(),
                label: `${d.name} (${d.ip})`,
              })),
            ]}
            placeholder="All SNMP Devices"
          />
        </div>

        <div className="flex-1 overflow-auto custom-scrollbar h-[600px]">
          {loading && devices.length === 0 && (
            <div className="flex justify-center items-center h-40">
              <div className="w-8 h-8 border-4 border-border-subtle border-t-accent-primary rounded-full animate-spin"></div>
            </div>
          )}

          <div className="p-4 space-y-6">
            {filteredDevices.map((device) => {
              const memPct = device.memory_total
                ? (device.memory_used! / device.memory_total) * 100
                : 0;
              const diskPct = device.disk_total
                ? (device.disk_used! / device.disk_total) * 100
                : 0;

              return (
                <div
                  key={device.id}
                  className="bg-bg-main border border-border-subtle rounded-2xl overflow-hidden hover:border-accent-primary/50 transition-colors"
                >
                  <div className="bg-bg-card p-4 border-b border-border-subtle flex justify-between items-center">
                    <div>
                      <h3 className="text-lg font-bold text-foreground flex items-center">
                        {device.name}
                        {device.ping_status === "up" ? (
                          <span className="ml-3 px-2 py-0.5 bg-green-900/50 text-green-400 border border-green-500/50 rounded-2xl text-xs">
                            UP
                          </span>
                        ) : (
                          <span className="ml-3 px-2 py-0.5 bg-red-900/50 text-red-400 border border-red-500/50 rounded-2xl text-xs animate-pulse">
                            DOWN
                          </span>
                        )}
                      </h3>
                      <p className="text-xs text-text-muted mt-1 font-mono">
                        {device.ip} • {device.model || device.type}
                      </p>
                    </div>
                  </div>

                  <div className="p-4 grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* System Resources */}
                    <div>
                      <h4 className="text-foreground font-bold mb-4 flex items-center border-b border-border-subtle pb-2">
                        <Activity className="w-4 h-4 mr-2 text-accent-primary" />{" "}
                        System Metrics
                      </h4>
                      {device.cpu_usage !== null ? (
                        <div className="space-y-4">
                          {/* CPU */}
                          <div>
                            <div className="flex justify-between text-xs mb-1">
                              <span className="text-text-muted font-bold">
                                CPU Usage
                              </span>
                              <span className="text-foreground">
                                {device.cpu_usage}%
                              </span>
                            </div>
                            <div className="w-full bg-bg-card rounded-full h-2">
                              <div
                                className={`h-2 rounded-full ${device.cpu_usage > 80 ? "bg-red-500" : device.cpu_usage > 60 ? "bg-yellow-500" : "bg-green-500"}`}
                                style={{ width: `${device.cpu_usage}%` }}
                              ></div>
                            </div>
                          </div>
                          {/* Memory */}
                          <div>
                            <div className="flex justify-between text-xs mb-1">
                              <span className="text-text-muted font-bold">
                                Memory ({formatBytes(device.memory_used)} /{" "}
                                {formatBytes(device.memory_total)})
                              </span>
                              <span className="text-foreground">
                                {memPct.toFixed(1)}%
                              </span>
                            </div>
                            <div className="w-full bg-bg-card rounded-full h-2">
                              <div
                                className={`h-2 rounded-full ${memPct > 80 ? "bg-red-500" : memPct > 60 ? "bg-yellow-500" : "bg-blue-500"}`}
                                style={{ width: `${memPct}%` }}
                              ></div>
                            </div>
                          </div>
                          {/* Disk */}
                          {device.disk_total ? (
                            <div>
                              <div className="flex justify-between text-xs mb-1">
                                <span className="text-text-muted font-bold">
                                  Disk ({formatBytes(device.disk_used)} /{" "}
                                  {formatBytes(device.disk_total)})
                                </span>
                                <span className="text-foreground">
                                  {diskPct.toFixed(1)}%
                                </span>
                              </div>
                              <div className="w-full bg-bg-card rounded-full h-2">
                                <div
                                  className={`h-2 rounded-full ${diskPct > 80 ? "bg-red-500" : diskPct > 60 ? "bg-yellow-500" : "bg-accent-primary"}`}
                                  style={{ width: `${diskPct}%` }}
                                ></div>
                              </div>
                            </div>
                          ) : null}
                        </div>
                      ) : (
                        <div className="text-text-muted text-sm italic py-4">
                          No SNMP metrics available
                        </div>
                      )}
                    </div>

                    {/* Interface Traffic */}
                    <div>
                      <h4 className="text-foreground font-bold mb-4 flex items-center border-b border-border-subtle pb-2">
                        <Network className="w-4 h-4 mr-2 text-blue-400" />{" "}
                        Interface Traffic
                      </h4>
                      {device.interfaces && device.interfaces.length > 0 ? (
                        <div className="space-y-3 overflow-y-auto max-h-40 custom-scrollbar pr-2">
                          {device.interfaces.map((intf) => (
                            <div
                              key={intf.if_index}
                              className="bg-bg-card p-2 rounded-2xl text-xs flex items-center justify-between"
                            >
                              <div className="truncate w-1/3">
                                <div
                                  className="text-foreground font-bold truncate"
                                  title={intf.if_descr}
                                >
                                  {intf.if_name || intf.if_descr}
                                </div>
                              </div>
                              <div className="flex space-x-4 w-2/3 justify-end text-right">
                                <div className="flex items-center text-blue-400">
                                  <ArrowDownRight className="w-3 h-3 mr-1" />{" "}
                                  {formatBits(intf.traffic_in_bps)}
                                </div>
                                <div className="flex items-center text-accent-primary">
                                  <ArrowUpRight className="w-3 h-3 mr-1" />{" "}
                                  {formatBits(intf.traffic_out_bps)}
                                </div>
                              </div>
                            </div>
                          ))}
                        </div>
                      ) : (
                        <div className="text-text-muted text-sm italic py-4">
                          No active interface traffic
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              );
            })}

            {!loading && filteredDevices.length === 0 && (
              <div className="text-center text-text-muted py-10">
                No devices found matching your criteria.
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
export default Resources;
