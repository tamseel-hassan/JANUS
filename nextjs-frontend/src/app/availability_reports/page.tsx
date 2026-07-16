'use client';
import { useState, useEffect } from 'react';
import { useSearchParams, useRouter } from 'next/navigation';
import { FileBarChart, Server, Activity, Download, Search, Loader2 } from 'lucide-react';
import { Line } from 'react-chartjs-2';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
} from 'chart.js';

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
);

export default function AvailabilityReportsPage() {
  const router = useRouter();
  const searchParams = useSearchParams();

  const [devices, setDevices] = useState<{id: number, name: string}[]>([]);
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState<any>(null);

  // Form State
  const [deviceId, setDeviceId] = useState(searchParams.get('device_id') || '');
  const [timeRange, setTimeRange] = useState(searchParams.get('time_range') || '24h');
  const [startDate, setStartDate] = useState(searchParams.get('start_date') || '');
  const [endDate, setEndDate] = useState(searchParams.get('end_date') || '');

  const fetchData = async () => {
    setLoading(true);
    try {
      const query = new URLSearchParams();
      if (deviceId) query.append('device_id', deviceId);
      if (timeRange) query.append('time_range', timeRange);
      if (startDate && timeRange === 'custom') query.append('start_date', startDate);
      if (endDate && timeRange === 'custom') query.append('end_date', endDate);

      const res = await fetch(`/api/get_availability_reports.php?${query.toString()}`);
      if (res.ok) {
        const result = await res.json();
        setDevices(result.devices || []);
        setData(result.report_data || null);
      }
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, [searchParams]);

  const handleGenerate = (e: React.FormEvent) => {
    e.preventDefault();
    const query = new URLSearchParams();
    if (deviceId) query.append('device_id', deviceId);
    if (timeRange) query.append('time_range', timeRange);
    if (startDate && timeRange === 'custom') query.append('start_date', startDate);
    if (endDate && timeRange === 'custom') query.append('end_date', endDate);
    
    router.push(`/availability_reports?${query.toString()}`);
  };

  const formatDuration = (seconds: number) => {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);
    let str = '';
    if (days) str += `${days} days, `;
    if (hours) str += `${hours} hours, `;
    if (minutes) str += `${minutes} minutes, `;
    if (secs) str += `${secs} seconds`;
    return str.replace(/, $/, '');
  };

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      <div className="flex items-center gap-4 border-b border-slate-700 pb-6">
        <div className="w-16 h-16 bg-blue-500/20 rounded-2xl flex items-center justify-center border border-blue-500/30">
          <FileBarChart className="w-8 h-8 text-blue-400" />
        </div>
        <div>
          <h1 className="text-2xl font-bold text-foreground mb-1">Availability Reports</h1>
          <p className="text-slate-400">Generate and export historical SLA reports for network devices</p>
        </div>
      </div>

      <div className="bg-slate-800 rounded-2xl border border-slate-700 p-6">
        <form onSubmit={handleGenerate} className="grid grid-cols-1 md:grid-cols-4 lg:grid-cols-5 gap-4 items-end">
          <div className="lg:col-span-2">
            <label className="block text-sm font-medium text-slate-300 mb-1.5">Select Device</label>
            <select
              value={deviceId}
              onChange={e => setDeviceId(e.target.value)}
              className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-blue-500"
              required
            >
              <option value="">-- Choose a device --</option>
              {devices.map(d => (
                <option key={d.id} value={d.id}>{d.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-slate-300 mb-1.5">Time Range</label>
            <select
              value={timeRange}
              onChange={e => setTimeRange(e.target.value)}
              className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-blue-500"
            >
              <option value="24h">Last 24 Hours</option>
              <option value="7d">Last 7 Days</option>
              <option value="30d">Last 30 Days</option>
              <option value="custom">Custom Range</option>
            </select>
          </div>
          {timeRange === 'custom' && (
            <>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-1.5">Start Date</label>
                <input
                  type="date"
                  value={startDate}
                  onChange={e => setStartDate(e.target.value)}
                  className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-blue-500"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-slate-300 mb-1.5">End Date</label>
                <input
                  type="date"
                  value={endDate}
                  onChange={e => setEndDate(e.target.value)}
                  className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-blue-500"
                  required
                />
              </div>
            </>
          )}
          <button
            type="submit"
            className="w-full bg-blue-600 hover:bg-blue-700 text-foreground rounded-2xl px-4 py-2.5 font-medium transition-colors flex items-center justify-center gap-2"
          >
            <Search className="w-5 h-5" /> Generate
          </button>
        </form>
      </div>

      {loading ? (
        <div className="flex flex-col items-center justify-center py-20">
          <Loader2 className="w-12 h-12 text-blue-500 animate-spin mb-4" />
          <p className="text-slate-400">Processing report data...</p>
        </div>
      ) : data ? (
        <div className="space-y-6">
          <div className="flex justify-between items-center bg-slate-800 rounded-2xl p-6 border border-slate-700">
            <div>
              <h2 className="text-2xl font-bold text-foreground mb-1 flex items-center gap-2">
                <Server className="w-6 h-6 text-emerald-400" /> {data.device_info.name}
              </h2>
              <p className="text-slate-400 text-sm">
                Report Period: {data.start_time} to {data.end_time}
              </p>
            </div>
            {/* <button className="bg-slate-700 hover:bg-slate-600 text-foreground px-4 py-2 rounded-2xl text-sm font-medium flex items-center gap-2 transition-colors border border-slate-600">
              <Download className="w-4 h-4" /> Export CSV
            </button> */}
          </div>

          <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div className="bg-slate-800 rounded-2xl p-5 border border-slate-700 flex flex-col justify-center">
              <p className="text-slate-400 text-sm mb-1 font-medium">SLA Availability</p>
              <h3 className="text-2xl font-bold text-foreground flex items-baseline gap-2">
                {data.availability}%
              </h3>
            </div>
            <div className="bg-slate-800 rounded-2xl p-5 border border-slate-700 flex flex-col justify-center">
              <p className="text-slate-400 text-sm mb-1 font-medium">Average RTT</p>
              <h3 className="text-2xl font-bold text-foreground flex items-baseline gap-2">
                {data.avg_rtt ?? 'N/A'} {data.avg_rtt && <span className="text-sm text-slate-500">ms</span>}
              </h3>
            </div>
            <div className="bg-slate-800 rounded-2xl p-5 border border-slate-700 flex flex-col justify-center">
              <p className="text-slate-400 text-sm mb-1 font-medium flex items-center gap-2">
                <span className="w-2 h-2 rounded-full bg-emerald-500"></span> Total Uptime
              </p>
              <h3 className="text-lg font-bold text-emerald-400">
                {formatDuration(data.total_up_time)}
              </h3>
            </div>
            <div className="bg-slate-800 rounded-2xl p-5 border border-slate-700 flex flex-col justify-center">
              <p className="text-slate-400 text-sm mb-1 font-medium flex items-center gap-2">
                <span className="w-2 h-2 rounded-full bg-red-500"></span> Total Downtime
              </p>
              <h3 className="text-lg font-bold text-red-400">
                {formatDuration(data.total_down_time)}
              </h3>
            </div>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="lg:col-span-2 bg-slate-800 rounded-2xl border border-slate-700 p-6">
              <h2 className="text-lg font-bold text-foreground mb-6 flex items-center gap-2">
                <Activity className="w-5 h-5 text-blue-400" /> Latency Over Time
              </h2>
              <div className="h-[400px] w-full">
                {data.rtt_data.length > 0 ? (
                  <Line 
                    data={{
                      labels: data.rtt_labels,
                      datasets: [{
                        label: 'RTT (ms)',
                        data: data.rtt_data,
                        fill: true,
                        borderColor: 'rgba(59, 130, 246, 1)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.4,
                        pointRadius: 0
                      }]
                    }} 
                    options={{
                      responsive: true,
                      maintainAspectRatio: false,
                      plugins: { legend: { display: false } },
                      scales: {
                        y: { beginAtZero: true, grid: { color: 'rgba(148, 163, 184, 0.1)' }, ticks: { color: '#94a3b8' } },
                        x: { grid: { display: false }, ticks: { color: '#94a3b8', maxTicksLimit: 8 } }
                      }
                    }} 
                  />
                ) : (
                  <div className="w-full h-full flex items-center justify-center text-slate-500">No telemetry data available for this period.</div>
                )}
              </div>
            </div>

            <div className="bg-slate-800 rounded-2xl border border-slate-700 overflow-hidden flex flex-col">
              <h2 className="text-lg font-bold text-foreground p-6 pb-2 flex items-center justify-between">
                State Transitions
                <span className="text-xs bg-slate-700 text-slate-300 px-2 py-1 rounded-2xl">{data.periods.length} events</span>
              </h2>
              <div className="p-4 flex-1 overflow-y-auto h-[400px]">
                {data.periods.length === 0 ? (
                  <p className="text-slate-500 text-center py-8">No status changes recorded.</p>
                ) : (
                  <div className="space-y-4">
                    {data.periods.map((p: any, i: number) => (
                      <div key={i} className="flex gap-4">
                        <div className="flex flex-col items-center">
                          <div className={`w-3 h-3 rounded-full mt-1.5 ${p.status === 'up' ? 'bg-emerald-500' : 'bg-red-500'}`}></div>
                          {i !== data.periods.length - 1 && <div className="w-0.5 h-full bg-slate-700 my-1"></div>}
                        </div>
                        <div>
                          <p className={`font-semibold text-sm ${p.status === 'up' ? 'text-emerald-400' : 'text-red-400'}`}>
                            {p.status === 'up' ? 'System Online' : 'System Offline'}
                          </p>
                          <p className="text-xs text-slate-400 mt-1">{p.start} - {p.end}</p>
                          <p className="text-xs text-slate-500 mt-0.5">Duration: {formatDuration(p.duration)}</p>
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
        <div className="bg-slate-800 rounded-2xl border border-slate-700 p-12 text-center">
          <FileBarChart className="w-16 h-16 text-slate-600 mx-auto mb-4" />
          <h3 className="text-xl font-medium text-foreground mb-2">Ready to generate report</h3>
          <p className="text-slate-400 max-w-md mx-auto">Select a device and time range from the controls above to generate a comprehensive availability SLA report.</p>
        </div>
      )}
    </div>
  );
}
