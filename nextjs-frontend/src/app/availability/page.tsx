"use client";

import { useEffect, useState, useCallback } from "react";
import { FileText, Clock, AlertTriangle, Play, Pause, FileCheck, Calendar, Filter, Save, X, Activity } from "lucide-react";
import { Line } from "react-chartjs-2";
import { Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, TimeScale, Filler } from 'chart.js';
import 'chartjs-adapter-date-fns';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, TimeScale, Filler);

interface Device {
  id: number;
  name: string;
  city: string;
  sub_office: string;
  country: string;
}

interface Period {
  status: string;
  start: string;
  end: string;
  duration: number;
  comment_data: {
    comments: string;
    action_taken: string;
    escalation_level: number;
    vendor_contacted: string;
    ticket_number: string;
    resolution_time: string;
  } | null;
}

interface Report {
  device: Device;
  start_time: string;
  end_time: string;
  total_up_time: number;
  total_down_time: number;
  availability_percent: number;
  avg_rtt: number;
  num_outages: number;
  avg_outage_duration: number;
  periods: Period[];
  rtt_data: {time: string, rtt: number | null, status: string}[];
}

export default function AvailabilityPage() {
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
    try {
      const res = await fetch('/api/get_availability.php', { credentials: "include" });
      if (res.status === 401 || res.status === 403) window.location.href = '/home';
      const data = await res.json();
      setDevices(data.devices || []);
    } catch (err) {
      console.error(err);
    }
  }, []);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  const generateReport = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!selectedDevice) return;
    
    setLoading(true);
    try {
      let url = `/api/get_availability.php?device_id=${selectedDevice}&time_range=${timeRange}`;
      if (timeRange === 'custom') url += `&start_datetime=${customStart}&end_datetime=${customEnd}`;
      
      const res = await fetch(url, { credentials: "include" });
      const data = await res.json();
      setReport(data.report || null);
    } catch (err) {
      console.error(err);
    }
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
        alert(data.error);
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
            <select required value={selectedDevice} onChange={e => setSelectedDevice(e.target.value)} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary">
              <option value="">-- Select Device --</option>
              {devices.map(d => (
                <option key={d.id} value={d.id}>{d.name} ({d.city})</option>
              ))}
            </select>
          </div>
          <div className="col-span-1 md:col-span-1">
            <label className="block text-xs font-bold text-text-muted mb-1">Time Range</label>
            <select required value={timeRange} onChange={e => setTimeRange(e.target.value)} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary">
              <option value="24h">Last 24 Hours</option>
              <option value="7d">Last 7 Days</option>
              <option value="30d">Last 30 Days</option>
              <option value="custom">Custom Range</option>
            </select>
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
            <button type="submit" disabled={loading} className="w-full bg-accent-primary hover:bg-accent-hover text-foreground px-4 py-2 rounded-2xl font-bold flex justify-center items-center transition-colors disabled:opacity-50">
              {loading ? <span className="animate-spin w-5 h-5 border-2 border-white border-t-transparent rounded-full mr-2"></span> : <Filter className="w-4 h-4 mr-2" />}
              Generate Report
            </button>
          </div>
        </form>
      </div>

      {report && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <div className="bg-bg-main border border-border-subtle rounded-2xl p-4">
              <h6 className="text-text-muted text-xs font-bold uppercase tracking-wider mb-1">Availability</h6>
              <div className={`text-2xl font-black ${report.availability_percent > 99 ? 'text-green-400' : report.availability_percent > 95 ? 'text-yellow-400' : 'text-red-400'}`}>
                {report.availability_percent.toFixed(2)}%
              </div>
            </div>
            <div className="bg-bg-main border border-border-subtle rounded-2xl p-4">
              <h6 className="text-text-muted text-xs font-bold uppercase tracking-wider mb-1">Total Uptime</h6>
              <div className="text-xl font-black text-foreground">{formatDuration(report.total_up_time)}</div>
            </div>
            <div className="bg-bg-main border border-border-subtle rounded-2xl p-4">
              <h6 className="text-text-muted text-xs font-bold uppercase tracking-wider mb-1">Total Downtime</h6>
              <div className="text-xl font-black text-red-400">{formatDuration(report.total_down_time)}</div>
            </div>
            <div className="bg-bg-main border border-border-subtle rounded-2xl p-4">
              <h6 className="text-text-muted text-xs font-bold uppercase tracking-wider mb-1">Outages</h6>
              <div className="text-2xl font-black text-foreground">{report.num_outages}</div>
            </div>
            <div className="bg-bg-main border border-border-subtle rounded-2xl p-4">
              <h6 className="text-text-muted text-xs font-bold uppercase tracking-wider mb-1">Avg RTT</h6>
              <div className="text-xl font-black text-blue-400">{report.avg_rtt} ms</div>
            </div>
          </div>

          <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden p-6">
            <h4 className="text-foreground font-bold mb-4 flex items-center"><Activity className="w-5 h-5 mr-2 text-accent-primary" /> RTT Timeline</h4>
            <div className="h-64">
              {chartData && <Line data={chartData} options={chartOptions} />}
            </div>
          </div>

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
                        <button onClick={() => openCommentModal(p)} className="p-1.5 bg-bg-card hover:bg-blue-600 text-blue-400 hover:text-foreground rounded-2xl border border-border-subtle hover:border-blue-500" title="Log Incident/Comment">
                          <FileCheck className="w-4 h-4" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {showCommentModal && editingPeriod && (
        <div className="fixed inset-0 bg-black/70 backdrop- z-50 flex items-center justify-center p-4">
          <div className="bg-bg-main border border-border-subtle rounded-2xl w-full max-w-2xl max-h-[90vh] flex flex-col">
            <div className="bg-bg-raised p-4 border-b border-border-subtle flex justify-between items-center shrink-0">
              <h3 className="text-xl font-bold text-foreground flex items-center"><FileText className="w-5 h-5 mr-2 text-accent-primary" /> Incident Report Details</h3>
              <button onClick={() => setShowCommentModal(false)} className="text-text-muted hover:text-foreground"><X className="w-6 h-6"/></button>
            </div>
            <div className="p-4 border-b border-border-subtle bg-bg-card flex gap-4 text-xs font-mono shrink-0">
              <div className="text-foreground"><span className="text-text-muted">Event:</span> {editingPeriod.status.toUpperCase()}</div>
              <div className="text-foreground"><span className="text-text-muted">Start:</span> {editingPeriod.start}</div>
              <div className="text-foreground"><span className="text-text-muted">End:</span> {editingPeriod.end}</div>
              <div className="text-foreground"><span className="text-text-muted">Duration:</span> {formatDuration(editingPeriod.duration)}</div>
            </div>
            <form onSubmit={saveComment} className="p-6 overflow-y-auto custom-scrollbar flex-1 space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="md:col-span-2">
                  <label className="block text-xs font-bold text-text-muted mb-1">Issue Description / Comments</label>
                  <textarea rows={3} value={commentForm.comments} onChange={e => setCommentForm({...commentForm, comments: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" placeholder="Describe the issue..."></textarea>
                </div>
                <div className="md:col-span-2">
                  <label className="block text-xs font-bold text-text-muted mb-1">Action Taken / Resolution</label>
                  <textarea rows={3} value={commentForm.action_taken} onChange={e => setCommentForm({...commentForm, action_taken: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" placeholder="What was done to resolve it..."></textarea>
                </div>
                <div>
                  <label className="block text-xs font-bold text-text-muted mb-1">Escalation Level</label>
                  <select value={commentForm.escalation_level} onChange={e => setCommentForm({...commentForm, escalation_level: parseInt(e.target.value)})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary">
                    <option value={0}>0 - No Escalation</option>
                    <option value={1}>1 - L1 Support</option>
                    <option value={2}>2 - L2 Support</option>
                    <option value={3}>3 - Vendor / Management</option>
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-bold text-text-muted mb-1">Vendor Contacted</label>
                  <input type="text" value={commentForm.vendor_contacted} onChange={e => setCommentForm({...commentForm, vendor_contacted: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" placeholder="e.g. Cisco TAC, ISP" />
                </div>
                <div>
                  <label className="block text-xs font-bold text-text-muted mb-1">Ticket Number</label>
                  <input type="text" value={commentForm.ticket_number} onChange={e => setCommentForm({...commentForm, ticket_number: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" placeholder="INC-12345" />
                </div>
                <div>
                  <label className="block text-xs font-bold text-text-muted mb-1">Resolution Time</label>
                  <input type="datetime-local" value={commentForm.resolution_time} onChange={e => setCommentForm({...commentForm, resolution_time: e.target.value})} className="w-full bg-bg-card border border-border-subtle text-foreground rounded-2xl px-3 py-2 text-sm focus:outline-none focus:border-accent-primary" />
                </div>
              </div>
              <div className="pt-6 flex justify-end space-x-3 border-t border-border-subtle mt-6">
                <button type="button" onClick={() => setShowCommentModal(false)} className="px-4 py-2 bg-bg-card text-foreground hover:bg-border-subtle rounded-2xl font-bold transition-colors">Cancel</button>
                <button type="submit" className="px-6 py-2 bg-accent-primary hover:bg-accent-hover text-foreground rounded-2xl font-bold transition-colors flex items-center">
                  <Save className="w-4 h-4 mr-2" /> Save Details
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
