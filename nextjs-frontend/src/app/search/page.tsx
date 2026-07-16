'use client';
import { useState, useEffect, Suspense } from 'react';
import { useSearchParams, useRouter } from 'next/navigation';
import { Search as SearchIcon, Server, Link as LinkIcon, ArrowLeft, Clock, Activity, Loader2 } from 'lucide-react';
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

function SearchContent() {
  const searchParams = useSearchParams();
  const router = useRouter();
  
  const q = searchParams.get('q') || '';
  const type = searchParams.get('type') || '';
  const id = searchParams.get('id') || '';

  const [loading, setLoading] = useState(true);
  const [data, setData] = useState<any>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!q && (!type || !id)) {
      setLoading(false);
      return;
    }

    const fetchData = async () => {
      setLoading(true);
      setError('');
      try {
        const queryStr = q ? `?q=${encodeURIComponent(q)}` : `?type=${type}&id=${id}`;
        const res = await fetch(`/api/get_search.php${queryStr}`);
        const result = await res.json();
        
        if (result.error) {
          setError(result.error);
        } else {
          setData(result);
        }
      } catch (err) {
        setError('Failed to fetch search results.');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [q, type, id]);

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

  const handleRowClick = (type: string, id: number) => {
    router.push(`/search?type=${type}&id=${id}`);
  };

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[60vh]">
        <Loader2 className="w-12 h-12 text-purple-500 animate-spin mb-4" />
        <p className="text-slate-400">Loading results...</p>
      </div>
    );
  }

  // Render detail view
  if (type && id && data?.detail_data) {
    const { detail_data, report_data } = data;
    const isDevice = type === 'device';

    const chartData = {
      labels: report_data.rtt_labels,
      datasets: [
        {
          label: 'RTT (ms)',
          data: report_data.rtt_data,
          fill: true,
          borderColor: 'rgba(168, 85, 247, 1)', // purple-500
          backgroundColor: 'rgba(168, 85, 247, 0.1)',
          tension: 0.4,
          pointRadius: 0,
        },
      ],
    };

    const chartOptions = {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: { color: 'rgba(148, 163, 184, 0.1)' },
          ticks: { color: '#94a3b8' }
        },
        x: {
          grid: { display: false },
          ticks: {
            color: '#94a3b8',
            maxTicksLimit: 8
          }
        }
      }
    };

    return (
      <div className="space-y-6">
        <button 
          onClick={() => router.back()}
          className="flex items-center gap-2 text-slate-400 hover:text-foreground transition-colors"
        >
          <ArrowLeft className="w-4 h-4" /> Back to results
        </button>

        <div className="flex items-center gap-4 border-b border-slate-700 pb-6">
          <div className="w-16 h-16 bg-slate-800 rounded-2xl flex items-center justify-center border border-slate-700">
            {isDevice ? <Server className="w-8 h-8 text-blue-400" /> : <LinkIcon className="w-8 h-8 text-emerald-400" />}
          </div>
          <div>
            <h1 className="text-2xl font-bold text-foreground mb-1">
              {detail_data.name}
            </h1>
            <p className="text-slate-400 text-lg flex items-center gap-2">
              <span className="capitalize px-2 py-0.5 bg-slate-800 rounded-2xl text-sm border border-slate-700">{type}</span>
              {isDevice ? detail_data.ip : `${detail_data.from_name} ➔ ${detail_data.to_name}`}
            </p>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
          <div className="bg-slate-800 rounded-2xl p-5 border border-slate-700 flex flex-col justify-center">
            <p className="text-slate-400 text-sm mb-1 font-medium">Availability (24h)</p>
            <h3 className="text-2xl font-bold text-foreground flex items-baseline gap-2">
              {report_data.availability}%
              {report_data.availability >= 99 ? (
                <span className="text-emerald-400 text-sm">Optimal</span>
              ) : (
                <span className="text-amber-400 text-sm">Degraded</span>
              )}
            </h3>
          </div>
          <div className="bg-slate-800 rounded-2xl p-5 border border-slate-700 flex flex-col justify-center">
            <p className="text-slate-400 text-sm mb-1 font-medium">Average RTT</p>
            <h3 className="text-2xl font-bold text-foreground flex items-baseline gap-2">
              {report_data.avg_rtt ?? 'N/A'} {report_data.avg_rtt && <span className="text-sm text-slate-500">ms</span>}
            </h3>
          </div>
          <div className="bg-slate-800 rounded-2xl p-5 border border-slate-700 flex flex-col justify-center">
            <p className="text-slate-400 text-sm mb-1 font-medium flex items-center gap-2">
              <span className="w-2 h-2 rounded-full bg-emerald-500"></span> Total Uptime
            </p>
            <h3 className="text-lg font-bold text-emerald-400">
              {formatDuration(report_data.total_up_time)}
            </h3>
          </div>
          <div className="bg-slate-800 rounded-2xl p-5 border border-slate-700 flex flex-col justify-center">
            <p className="text-slate-400 text-sm mb-1 font-medium flex items-center gap-2">
              <span className="w-2 h-2 rounded-full bg-red-500"></span> Total Downtime
            </p>
            <h3 className="text-lg font-bold text-red-400">
              {formatDuration(report_data.total_down_time)}
            </h3>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2 bg-slate-800 rounded-2xl border border-slate-700 p-6">
            <h2 className="text-lg font-bold text-foreground mb-6 flex items-center gap-2">
              <Activity className="w-5 h-5 text-purple-400" /> Latency Over Time (24h)
            </h2>
            <div className="h-[300px] w-full">
              {report_data.rtt_data.length > 0 ? (
                <Line data={chartData} options={chartOptions} />
              ) : (
                <div className="w-full h-full flex items-center justify-center text-slate-500">No telemetry data available for the last 24 hours.</div>
              )}
            </div>
          </div>

          <div className="bg-slate-800 rounded-2xl border border-slate-700 overflow-hidden flex flex-col">
            <h2 className="text-lg font-bold text-foreground p-6 pb-2 flex items-center gap-2">
              <Clock className="w-5 h-5 text-purple-400" /> State Transitions
            </h2>
            <div className="p-4 flex-1 overflow-y-auto max-h-[300px]">
              {report_data.periods.length === 0 ? (
                <p className="text-slate-500 text-center py-8">No status changes recorded.</p>
              ) : (
                <div className="space-y-4">
                  {report_data.periods.map((p: any, i: number) => (
                    <div key={i} className="flex gap-4">
                      <div className="flex flex-col items-center">
                        <div className={`w-3 h-3 rounded-full mt-1.5 ${p.status === 'up' ? 'bg-emerald-500' : 'bg-red-500'}`}></div>
                        {i !== report_data.periods.length - 1 && <div className="w-0.5 h-full bg-slate-700 my-1"></div>}
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
    );
  }

  // Render search results view
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4 border-b border-slate-700 pb-6">
        <div className="w-16 h-16 bg-purple-500/20 rounded-2xl flex items-center justify-center border border-purple-500/30">
          <SearchIcon className="w-8 h-8 text-purple-400" />
        </div>
        <div>
          <h1 className="text-2xl font-bold text-foreground mb-1">Search Results</h1>
          {q ? (
            <p className="text-slate-400">
              Showing results for <span className="text-foreground font-medium">"{q}"</span>
            </p>
          ) : (
            <p className="text-slate-400">Enter a query to search across the network</p>
          )}
        </div>
      </div>

      {error ? (
        <div className="bg-red-500/10 border border-red-500/30 rounded-2xl p-6 text-center text-red-400">
          {error}
        </div>
      ) : !q ? (
        <div className="bg-slate-800 rounded-2xl border border-slate-700 p-12 text-center text-slate-400">
          Use the global search bar in the top navigation to search for devices, IP addresses, or links.
        </div>
      ) : data?.results?.length > 0 ? (
        <div className="bg-slate-800 rounded-2xl border border-slate-700 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-slate-900/50 border-b border-slate-700">
                  <th className="p-4 font-semibold text-slate-300 text-sm">Type</th>
                  <th className="p-4 font-semibold text-slate-300 text-sm">Name</th>
                  <th className="p-4 font-semibold text-slate-300 text-sm">Details / IP Address</th>
                  <th className="p-4 font-semibold text-slate-300 text-sm">Location</th>
                </tr>
              </thead>
              <tbody>
                {data.results.map((r: any) => (
                  <tr 
                    key={`${r.type}-${r.id}`} 
                    onClick={() => handleRowClick(r.type, r.id)}
                    className="border-b border-slate-700/50 hover:bg-slate-700/30 transition-colors cursor-pointer group"
                  >
                    <td className="p-4">
                      <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-2xl text-xs font-medium border ${
                        r.type === 'device' 
                          ? 'bg-blue-500/10 text-blue-400 border-blue-500/20' 
                          : 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                      }`}>
                        {r.type === 'device' ? <Server className="w-3 h-3" /> : <LinkIcon className="w-3 h-3" />}
                        <span className="capitalize">{r.type}</span>
                      </span>
                    </td>
                    <td className="p-4 text-sm font-semibold text-foreground group-hover:text-purple-400 transition-colors">
                      {r.name}
                    </td>
                    <td className="p-4 text-sm text-slate-300 font-mono">
                      {r.type === 'device' ? r.ip : `${r.from_name} ➔ ${r.to_name}`}
                    </td>
                    <td className="p-4 text-sm text-slate-400">
                      {r.type === 'device' && (r.city || r.country) ? `${r.city || ''} ${r.country ? `(${r.country})` : ''}` : '-'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : (
        <div className="bg-slate-800 rounded-2xl border border-slate-700 p-12 text-center">
          <SearchIcon className="w-12 h-12 text-slate-600 mx-auto mb-4" />
          <h3 className="text-lg font-medium text-foreground mb-2">No results found</h3>
          <p className="text-slate-400">We couldn't find any devices or links matching "{q}".</p>
        </div>
      )}
    </div>
  );
}

export default function SearchPage() {
  return (
    <div className="p-6 max-w-7xl mx-auto">
      <Suspense fallback={<div className="p-6 text-center text-slate-400">Loading search...</div>}>
        <SearchContent />
      </Suspense>
    </div>
  );
}
