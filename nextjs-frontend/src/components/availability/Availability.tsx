"use client";

import { useEffect, useState, useCallback } from "react";
import { FileText, Clock, Play, Pause, FileCheck, Filter } from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import { Device, Period, Report } from "@/types/availability";
import { AvailabilityChart } from "@/components/availability/AvailabilityChart";
import { CommentModal } from "@/components/availability/CommentModal";
import { StatCard } from "@/components/ui/StatCard";
import { Button } from "@/components/ui/Button";
import { safeFetch } from "@/lib/safeFetch";
import { toast } from "sonner";

export function Availability() {
  const [devices, setDevices] = useState<Device[]>([]);
  const [report, setReport] = useState<Report | null>(null);
  
  const [selectedDevice, setSelectedDevice] = useState<string>('');
  const [timeRange, setTimeRange] = useState<string>('24h');
  const [customStart, setCustomStart] = useState<string>('');
  const [customEnd, setCustomEnd] = useState<string>('');
  
  const [loading, setLoading] = useState(false);

  // Comment modal
  const [showCommentModal, setShowCommentModal] = useState(false);
  const [editingPeriod, setEditingPeriod] = useState<Period | null>(null);
  const [commentForm, setCommentForm] = useState({
    comments: '', action_taken: '', escalation_level: 0, vendor_contacted: '', ticket_number: '', resolution_time: ''
  });

  const fetchData = useCallback(async () => {
    const data = await safeFetch<{ devices: Device[] }>('/api/get_availability.php', {}, "Availability");
    if (data) setDevices(data.devices || []);
  }, []);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  const generateReport = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!selectedDevice) {
      toast.warning("Please select a device");
      return;
    }
    
    setLoading(true);
    let url = `/api/get_availability.php?device_id=${selectedDevice}&time_range=${timeRange}`;
    if (timeRange === 'custom') url += `&start_datetime=${customStart}&end_datetime=${customEnd}`;
    const data = await safeFetch<{ report: Report }>( url, {}, "Availability:report");
    if (data) setReport(data.report || null);
    setLoading(false);
  };

  const formatDuration = (seconds: number) => {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);
    let str = '';
    if (days) str += `${days}d `;
    if (hours) str += `${hours}h `;
    if (mins) str += `${mins}m `;
    str += `${secs}s`;
    return str.trim();
  };

  const openCommentModal = (p: Period) => {
    setEditingPeriod(p);
    setCommentForm({
      comments: p.comment_data?.comments || '',
      action_taken: p.comment_data?.action_taken || '',
      escalation_level: p.comment_data?.escalation_level || 0,
      vendor_contacted: p.comment_data?.vendor_contacted || '',
      ticket_number: p.comment_data?.ticket_number || '',
      resolution_time: p.comment_data?.resolution_time || ''
    });
    setShowCommentModal(true);
  };

  const saveComment = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingPeriod || !selectedDevice) return;
    try {
      const payload = {
        action: 'save_comment',
        device_id: selectedDevice,
        event_start: editingPeriod.start,
        event_end: editingPeriod.end,
        ...commentForm
      };
      const res = await fetch('/api/get_availability.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: "include"
      });
      const data = await res.json();
      if (data.success) {
        setShowCommentModal(false);
        generateReport(); // Refresh
      } else {
        toast.error(data.error);
      }
    } catch (err) { console.error(err); }
  };

  const chartData = report ? {
    labels: report.rtt_data.map(d => new Date(d.time)),
    datasets: [{
      label: 'RTT (ms)',
      data: report.rtt_data.map(d => d.rtt),
      borderColor: 'var(--accent-primary)',
      backgroundColor: 'rgba(198, 102, 244, 0.1)',
      borderWidth: 2,
      fill: true,
      pointRadius: 0,
      tension: 0.1
    }]
  } : null;

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
      x: { type: 'time' as const, time: { tooltipFormat: 'PP pp' }, grid: { color: 'var(--border-subtle)' }, ticks: { color: 'var(--text-muted)' } },
      y: { beginAtZero: true, grid: { color: 'var(--border-subtle)' }, ticks: { color: 'var(--text-muted)' } }
    },
    plugins: {
      legend: { display: false },
      tooltip: { mode: 'index' as const, intersect: false }
    }
  };

  return (
    <div className="space-y-6 max-w-7xl mx-auto pb-10">
      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-wide flex items-center">
            <FileText className="w-8 h-8 mr-3 text-accent-primary" /> Availability & Reports
          </h1>
          <p className="text-text-muted mt-1 text-lg">Comprehensive device monitoring reports and incident tracking</p>
        </div>
      </div>

      <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6">
        <form onSubmit={generateReport} className="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
          <div className="col-span-1 md:col-span-1">
            <label className="block text-xs font-bold text-text-muted mb-1">Device</label>
            <CustomSelect
              value={selectedDevice}
              onChange={setSelectedDevice}
              options={[
                { value: "", label: "-- Select Device --" },
                ...devices.map(d => ({ value: d.id.toString(), label: `${d.name} (${d.city})` }))
              ]}
              placeholder="-- Select Device --"
            />
          </div>
          <div className="col-span-1 md:col-span-1">
            <label className="block text-xs font-bold text-text-muted mb-1">Time Range</label>
            <CustomSelect
              value={timeRange}
              onChange={setTimeRange}
              options={[
                { value: "24h", label: "Last 24 Hours" },
                { value: "7d", label: "Last 7 Days" },
                { value: "30d", label: "Last 30 Days" },
                { value: "custom", label: "Custom Range" }
              ]}
            />
          </div>
          {timeRange === 'custom' && (
            <>
              <div className="col-span-1 md:col-span-1">
                <label className="block text-xs font-bold text-text-muted mb-1">Start</label>
                <input type="datetime-local" required value={customStart} onChange={e => setCustomStart(e.target.value)} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
              </div>
              <div className="col-span-1 md:col-span-1">
                <label className="block text-xs font-bold text-text-muted mb-1">End</label>
                <input type="datetime-local" required value={customEnd} onChange={e => setCustomEnd(e.target.value)} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
              </div>
            </>
          )}
          <div className="col-span-1 md:col-span-1">
            <Button type="submit" variant="primary" isLoading={loading} disabled={loading} className="w-full py-2.5">
            {!loading && <><Filter className="w-4 h-4 mr-2" /> Generate Report</>}
          </Button>
          </div>
        </form>
      </div>

      {!report && !loading && (
        <div className="bg-bg-raised border border-border-subtle rounded-2xl p-16 flex flex-col items-center justify-center text-center mt-6">
          <FileText className="w-12 h-12 text-text-muted mb-4 opacity-50" />
          <h3 className="text-xl font-bold text-foreground mb-2">Ready to generate report</h3>
          <p className="text-text-muted max-w-md">Select a device and time range from the controls above to generate a comprehensive availability SLA report.</p>
        </div>
      )}

      {report && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <StatCard title="Availability" value={`${report.availability_percent.toFixed(2)}%`}
              color={report.availability_percent > 99 ? 'text-green-400' : report.availability_percent > 95 ? 'text-yellow-400' : 'text-red-400'} />
            <StatCard title="Total Uptime" value={formatDuration(report.total_up_time)} />
            <StatCard title="Total Downtime" value={formatDuration(report.total_down_time)} color="text-red-400" />
            <StatCard title="Outages" value={report.num_outages} />
            <StatCard title="Avg RTT" value={`${report.avg_rtt} ms`} color="text-blue-400" />
          </div>

          {report && chartData && (
            <AvailabilityChart
              report={report}
              chartData={chartData}
              chartOptions={chartOptions}
            />
          )}

          <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden">
            <div className="p-4 border-b border-border-subtle bg-bg-main">
              <h4 className="text-foreground font-bold flex items-center"><Clock className="w-5 h-5 mr-2 text-accent-primary" /> Incident Timeline</h4>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm whitespace-nowrap">
                <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle">
                  <tr>
                    <th className="px-6 py-4 font-bold">Status</th>
                    <th className="px-6 py-4 font-bold">Start Time</th>
                    <th className="px-6 py-4 font-bold">End Time</th>
                    <th className="px-6 py-4 font-bold">Duration</th>
                    <th className="px-6 py-4 font-bold">Ticket #</th>
                    <th className="px-6 py-4 font-bold text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-subtle">
                  {report.periods.length === 0 && <tr><td colSpan={6} className="px-6 py-10 text-center text-text-muted">No events in this period</td></tr>}
                  {report.periods.map((p, i) => (
                    <tr key={i} className="hover:bg-bg-card/50 transition-colors">
                      <td className="px-6 py-4">
                        {p.status === 'up' ? 
                          <span className="px-2 py-1 bg-green-900/50 text-green-400 border border-green-500/50 rounded-2xl text-xs font-bold flex items-center w-min"><Play className="w-3 h-3 mr-1" /> UP</span> : 
                          <span className="px-2 py-1 bg-red-900/50 text-red-400 border border-red-500/50 rounded-2xl text-xs font-bold flex items-center w-min animate-pulse"><Pause className="w-3 h-3 mr-1" /> DOWN</span>
                        }
                      </td>
                      <td className="px-6 py-4 text-foreground font-mono text-xs">{p.start}</td>
                      <td className="px-6 py-4 text-foreground font-mono text-xs">{p.end}</td>
                      <td className="px-6 py-4 text-text-muted font-mono text-xs">{formatDuration(p.duration)}</td>
                      <td className="px-6 py-4 text-blue-400 font-mono text-xs">{p.comment_data?.ticket_number || '-'}</td>
                      <td className="px-6 py-4 text-right">
                        <Button variant="ghost" size="icon" onClick={() => openCommentModal(p)}
                          className="hover:bg-blue-600 text-blue-400 hover:text-foreground border border-border-subtle hover:border-blue-500"
                          title="Log Incident/Comment"
                        >
                          <FileCheck className="w-4 h-4" />
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {editingPeriod && (
        <CommentModal
          isOpen={showCommentModal}
          editingPeriod={editingPeriod}
          commentForm={commentForm}
          setCommentForm={setCommentForm}
          saveComment={saveComment}
          formatDuration={formatDuration}
          onClose={() => setShowCommentModal(false)}
        />
      )}
    </div>
  );
}
export default Availability;
