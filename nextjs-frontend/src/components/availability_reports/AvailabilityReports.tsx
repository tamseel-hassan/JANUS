"use client";

import { useState, useEffect } from "react";
import { useSearchParams, useRouter } from "next/navigation";
import { FileBarChart, Server, Activity, Search, Loader2 } from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import { safeFetch } from "@/lib/safeFetch";
import { toast } from "sonner";
import { Button } from "@/components/ui/Button";
import { StatCard } from "@/components/ui/StatCard";
import { Line } from "react-chartjs-2";
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from "chart.js";

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler,
);

export function AvailabilityReports() {
  const router = useRouter();
  const searchParams = useSearchParams();

  const [devices, setDevices] = useState<{ id: number; name: string }[]>([]);
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState<any>(null);

  // Form State
  const [deviceId, setDeviceId] = useState(searchParams.get("device_id") || "");
  const [timeRange, setTimeRange] = useState(
    searchParams.get("time_range") || "24h",
  );
  const [startDate, setStartDate] = useState(
    searchParams.get("start_date") || "",
  );
  const [endDate, setEndDate] = useState(searchParams.get("end_date") || "");

  const fetchData = async () => {
    setLoading(true);
    const query = new URLSearchParams();
    if (deviceId) query.append("device_id", deviceId);
    if (timeRange) query.append("time_range", timeRange);
    if (startDate && timeRange === "custom")
      query.append("start_date", startDate);
    if (endDate && timeRange === "custom") query.append("end_date", endDate);

    const result = await safeFetch<{
      devices: { id: number; name: string }[];
      report_data: any;
    }>(
      `/api/get_availability_reports.php?${query.toString()}`,
      {},
      "AvailabilityReports",
    );
    if (result) {
      setDevices(result.devices || []);
      setData(result.report_data || null);
    }
    setLoading(false);
  };

  useEffect(() => {
    fetchData();
  }, [searchParams]);

  const handleGenerate = (e: React.FormEvent) => {
    e.preventDefault();
    if (!deviceId) {
      toast.warning("Please select a device");
      return;
    }
    const query = new URLSearchParams();
    query.append("device_id", deviceId);
    if (timeRange) query.append("time_range", timeRange);
    if (startDate && timeRange === "custom")
      query.append("start_date", startDate);
    if (endDate && timeRange === "custom") query.append("end_date", endDate);

    router.push(`/availability_reports?${query.toString()}`);
  };

  const formatDuration = (seconds: number) => {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);
    let str = "";
    if (days) str += `${days} days, `;
    if (hours) str += `${hours} hours, `;
    if (minutes) str += `${minutes} minutes, `;
    if (secs) str += `${secs} seconds`;
    return str.replace(/, $/, "");
  };

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      <div className="flex items-center gap-4 border-b border-border-subtle pb-6">
        <div className="w-16 h-16 bg-accent-primary/20 rounded-2xl flex items-center justify-center border border-accent-primary/30">
          <FileBarChart className="w-8 h-8 text-accent-primary" />
        </div>
        <div>
          <h1 className="text-2xl font-bold text-foreground mb-1">
            Availability Reports
          </h1>
          <p className="text-text-muted">
            Generate and export historical SLA reports for network devices
          </p>
        </div>
      </div>

      <div className="bg-bg-raised rounded-2xl border border-border-subtle p-6">
        <form
          onSubmit={handleGenerate}
          className="grid grid-cols-1 md:grid-cols-4 lg:grid-cols-5 gap-4 items-end"
        >
          <div className="lg:col-span-2">
            <label className="block text-sm font-medium text-text-muted mb-1.5">
              Select Device
            </label>
            <CustomSelect
              value={deviceId}
              onChange={setDeviceId}
              options={[
                { value: "", label: "Choose a device" },
                ...devices.map((d) => ({
                  value: d.id.toString(),
                  label: d.name,
                })),
              ]}
              placeholder="-- Choose a device --"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-text-muted mb-1.5">
              Time Range
            </label>
            <CustomSelect
              value={timeRange}
              onChange={setTimeRange}
              options={[
                { value: "24h", label: "Last 24 Hours" },
                { value: "7d", label: "Last 7 Days" },
                { value: "30d", label: "Last 30 Days" },
                { value: "custom", label: "Custom Range" },
              ]}
            />
          </div>
          {timeRange === "custom" && (
            <>
              <div>
                <label className="block text-sm font-medium text-text-muted mb-1.5">
                  Start Date
                </label>
                <input
                  type="date"
                  value={startDate}
                  onChange={(e) => setStartDate(e.target.value)}
                  className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-accent-primary"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-text-muted mb-1.5">
                  End Date
                </label>
                <input
                  type="date"
                  value={endDate}
                  onChange={(e) => setEndDate(e.target.value)}
                  className="w-full bg-bg-card border border-border-subtle rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-accent-primary"
                  required
                />
              </div>
            </>
          )}
          <div>
            <label
              className="block text-sm mb-1.5 opacity-0 pointer-events-none"
              aria-hidden="true"
            >
              &nbsp;
            </label>
            <Button type="submit" variant="primary" className="w-full py-2">
              {/* <Search className="w-5 h-5 mr-2" />  */}
              Generate
            </Button>
          </div>
        </form>
      </div>

      {loading ? (
        <div className="flex flex-col items-center justify-center py-20">
          <Loader2 className="w-12 h-12 text-accent-primary animate-spin mb-4" />
          <p className="text-text-muted">Processing report data...</p>
        </div>
      ) : data ? (
        <div className="space-y-6">
          <div className="flex justify-between items-center bg-bg-raised rounded-2xl p-6 border border-border-subtle">
            <div>
              <h2 className="text-2xl font-bold text-foreground mb-1 flex items-center gap-2">
                <Server className="w-6 h-6 text-emerald-400" />{" "}
                {data.device_info.name}
              </h2>
              <p className="text-text-muted text-sm">
                Report Period: {data.start_time} to {data.end_time}
              </p>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
            <StatCard
              title="SLA Availability"
              value={`${data.availability}%`}
            />
            <StatCard
              title="Average RTT"
              value={data.avg_rtt ? `${data.avg_rtt} ms` : "N/A"}
            />
            <StatCard
              title="Total Uptime"
              value={formatDuration(data.total_up_time)}
              color="text-emerald-400"
            />
            <StatCard
              title="Total Downtime"
              value={formatDuration(data.total_down_time)}
              color="text-red-400"
            />
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="lg:col-span-2 bg-bg-raised rounded-2xl border border-border-subtle p-6">
              <h2 className="text-lg font-bold text-foreground mb-6 flex items-center gap-2">
                <Activity className="w-5 h-5 text-accent-primary" /> Latency
                Over Time
              </h2>
              <div className="h-[400px] w-full">
                {data.rtt_data.length > 0 ? (
                  <Line
                    data={{
                      labels: data.rtt_labels,
                      datasets: [
                        {
                          label: "RTT (ms)",
                          data: data.rtt_data,
                          fill: true,
                          borderColor: "rgba(59, 130, 246, 1)",
                          backgroundColor: "rgba(59, 130, 246, 0.1)",
                          tension: 0.4,
                          pointRadius: 0,
                        },
                      ],
                    }}
                    options={{
                      responsive: true,
                      maintainAspectRatio: false,
                      plugins: { legend: { display: false } },
                      scales: {
                        y: {
                          beginAtZero: true,
                          grid: { color: "rgba(148, 163, 184, 0.1)" },
                          ticks: { color: "#94a3b8" },
                        },
                        x: {
                          grid: { display: false },
                          ticks: { color: "#94a3b8", maxTicksLimit: 8 },
                        },
                      },
                    }}
                  />
                ) : (
                  <div className="w-full h-full flex items-center justify-center text-text-muted">
                    No telemetry data available for this period.
                  </div>
                )}
              </div>
            </div>

            <div className="bg-bg-raised rounded-2xl border border-border-subtle overflow-hidden flex flex-col">
              <h2 className="text-lg font-bold text-foreground p-6 pb-2 flex items-center justify-between">
                State Transitions
                <span className="text-xs bg-slate-700 text-text-muted px-2 py-1 rounded-2xl">
                  {data.periods.length} events
                </span>
              </h2>
              <div className="p-4 flex-1 overflow-y-auto h-[400px]">
                {data.periods.length === 0 ? (
                  <p className="text-text-muted text-center py-8">
                    No status changes recorded.
                  </p>
                ) : (
                  <div className="space-y-4">
                    {data.periods.map((p: any, i: number) => (
                      <div key={i} className="flex gap-4">
                        <div className="flex flex-col items-center">
                          <div
                            className={`w-3 h-3 rounded-full mt-1.5 ${p.status === "up" ? "bg-emerald-500" : "bg-red-500"}`}
                          ></div>
                          {i !== data.periods.length - 1 && (
                            <div className="w-0.5 h-full bg-slate-700 my-1"></div>
                          )}
                        </div>
                        <div>
                          <p
                            className={`font-semibold text-sm ${p.status === "up" ? "text-emerald-400" : "text-red-400"}`}
                          >
                            {p.status === "up"
                              ? "System Online"
                              : "System Offline"}
                          </p>
                          <p className="text-xs text-text-muted mt-1">
                            {p.start} - {p.end}
                          </p>
                          <p className="text-xs text-text-muted mt-0.5">
                            Duration: {formatDuration(p.duration)}
                          </p>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      ) : (
        <div className="bg-bg-raised rounded-2xl border border-border-subtle p-12 text-center">
          <FileBarChart className="w-16 h-16 text-slate-600 mx-auto mb-4" />
          <h3 className="text-xl font-medium text-foreground mb-2">
            Ready to generate report
          </h3>
          <p className="text-text-muted max-w-md mx-auto">
            Select a device and time range from the controls above to generate a
            comprehensive availability SLA report.
          </p>
        </div>
      )}
    </div>
  );
}
export default AvailabilityReports;
