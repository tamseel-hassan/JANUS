'use client';

import { useState, useEffect } from 'react';
import { Book, CheckCircle, History, Ban, Database, PlusCircle, List, Bolt, AlertCircle, Loader2 } from 'lucide-react';
import { AutomationData } from '@/types/responder';
import Link from 'next/link';
import { format } from 'date-fns';
import { notify } from '@/services/feedback/feedbackService';
import { responderService } from '@/services/responder/responderService';

export default function ResponderAutomation() {
  const [data, setData] = useState<AutomationData | null>(null);
  const [loading, setLoading] = useState(true);
  const [isInstalling, setIsInstalling] = useState(false);

  const fetchData = async () => {
    const result = await responderService.getAutomation();
    if (result) {
      setData(result);
    }
    setLoading(false);
  };

  const handleInstall = async () => {
    setIsInstalling(true);
    try {
      const res = await responderService.installAutomation();
      if (res?.success) {
        notify.success('Database tables installed successfully!');
        await fetchData();
      } else {
        notify.error(res?.error || 'Failed to install tables.');
      }
    } catch (err: any) {
      console.error(err);
      notify.error('Network error during installation.');
    }
    setIsInstalling(false);
  };

  useEffect(() => {
    fetchData();
  }, []);

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[60vh]">
        <Loader2 className="w-12 h-12 text-amber-500 animate-spin mb-4" />
        <p className="text-slate-400">Loading automation dashboard...</p>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="p-6 max-w-7xl mx-auto space-y-6 text-center">
        <p className="text-red-400">Failed to load automation data.</p>
      </div>
    );
  }

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      <div className="flex items-center gap-4 border-b border-slate-700 pb-6">
        <div className="w-16 h-16 bg-amber-500/20 rounded-2xl flex items-center justify-center border border-amber-500/30">
          <Bolt className="w-8 h-8 text-amber-400" />
        </div>
        <div>
          <div className="text-xs font-semibold tracking-widest text-cyan-400 uppercase mb-1 flex items-center gap-1">
            <Bolt className="w-3 h-3" /> JANUS / RESPONDER
          </div>
          <h1 className="text-2xl font-bold text-foreground mb-1">
            <span className="text-amber-400 mr-2">&gt;</span>Response Automation
          </h1>
          <p className="text-slate-400">Orchestration and automated response dashboard</p>
        </div>
      </div>

      {data.needsInstall && (
        <div className="bg-slate-800 border border-amber-500/50 rounded-2xl p-6 mb-6 text-center shadow-lg shadow-amber-500/5">
          <Database className="w-12 h-12 text-amber-400 mx-auto mb-3" />
          <h4 className="text-lg font-bold text-foreground mb-2">Database Tables Not Found</h4>
          <p className="text-slate-400 mb-4">The responder module needs database tables. You can set them up in the legacy backend or click the button below to initialize.</p>
          <button 
            onClick={handleInstall}
            disabled={isInstalling}
            className="bg-amber-500/10 text-amber-400 border border-amber-500/50 hover:bg-amber-500/20 disabled:opacity-50 px-4 py-2 rounded-lg font-medium transition-colors inline-flex items-center gap-2"
          >
            {isInstalling ? <Loader2 className="w-4 h-4 animate-spin" /> : <Database className="w-4 h-4" />} 
            {isInstalling ? 'Installing...' : 'Install Database Tables'}
          </button>
        </div>
      )}

      {/* Stats Cards - Row 1 */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-slate-800 border border-slate-700 hover:border-slate-600 rounded-2xl p-5 transition-all hover:-translate-y-0.5">
          <Book className="w-6 h-6 text-cyan-400 mb-3" />
          <div className="text-3xl font-bold text-foreground mb-1">{data.stats.totalPlaybooks}</div>
          <div className="inline-block px-3 py-1 bg-cyan-500/10 text-cyan-400 text-xs font-bold uppercase tracking-wider rounded mt-2">
            Total Playbooks
          </div>
        </div>
        
        <div className="bg-slate-800 border border-slate-700 hover:border-slate-600 rounded-2xl p-5 transition-all hover:-translate-y-0.5">
          <CheckCircle className="w-6 h-6 text-emerald-400 mb-3" />
          <div className="text-3xl font-bold text-foreground mb-1">{data.stats.enabledPlaybooks}</div>
          <div className="inline-block px-3 py-1 bg-emerald-500/10 text-emerald-400 text-xs font-bold uppercase tracking-wider rounded mt-2">
            Enabled
          </div>
        </div>

        <div className="bg-slate-800 border border-slate-700 hover:border-slate-600 rounded-2xl p-5 transition-all hover:-translate-y-0.5">
          <History className="w-6 h-6 text-amber-400 mb-3" />
          <div className="text-3xl font-bold text-foreground mb-1">{data.stats.totalExecutions}</div>
          <div className="inline-block px-3 py-1 bg-amber-500/10 text-amber-400 text-xs font-bold uppercase tracking-wider rounded mt-2">
            Executions
          </div>
        </div>

        <div className="bg-slate-800 border border-slate-700 hover:border-slate-600 rounded-2xl p-5 transition-all hover:-translate-y-0.5">
          <Ban className="w-6 h-6 text-red-400 mb-3" />
          <div className="text-3xl font-bold text-foreground mb-1">{data.stats.failedExecutions}</div>
          <div className="inline-block px-3 py-1 bg-red-500/10 text-red-400 text-xs font-bold uppercase tracking-wider rounded mt-2">
            Failed
          </div>
        </div>
      </div>

      {/* Stats Cards - Row 2 */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-slate-800 border border-slate-700 hover:border-slate-600 rounded-2xl p-5 transition-all hover:-translate-y-0.5">
          <Ban className="w-6 h-6 text-violet-400 mb-3" />
          <div className="text-3xl font-bold text-foreground mb-1">{data.stats.activeBlocked}</div>
          <div className="inline-block px-3 py-1 bg-violet-500/10 text-violet-400 text-xs font-bold uppercase tracking-wider rounded mt-2">
            Active Blocks
          </div>
        </div>
        
        <div className="bg-slate-800 border border-slate-700 hover:border-slate-600 rounded-2xl p-5 transition-all hover:-translate-y-0.5">
          <List className="w-6 h-6 text-slate-400 mb-3" />
          <div className="text-3xl font-bold text-foreground mb-1">{data.stats.totalBlocked}</div>
          <div className="inline-block px-3 py-1 bg-slate-700 text-slate-300 text-xs font-bold uppercase tracking-wider rounded mt-2">
            Total Blocks
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-2">
        {/* Quick Actions */}
        <div className="bg-slate-800 rounded-2xl border border-slate-700 p-6">
          <h5 className="text-sm font-bold text-foreground mb-4 uppercase tracking-wider flex items-center gap-2">
            <Bolt className="w-4 h-4 text-amber-400" /> Quick Actions
          </h5>
          <div className="grid grid-cols-1 gap-3">
            <Link href="/responder/playbooks" className="flex items-center gap-3 px-4 py-3 bg-slate-900/50 hover:bg-slate-700/50 border border-slate-700 rounded-xl transition-colors">
              <Book className="w-5 h-5 text-cyan-400" />
              <span className="text-foreground font-medium text-sm">Manage Playbooks</span>
            </Link>
            <Link href="/responder/playbooks/new" className="flex items-center gap-3 px-4 py-3 bg-slate-900/50 hover:bg-slate-700/50 border border-slate-700 rounded-xl transition-colors">
              <PlusCircle className="w-5 h-5 text-emerald-400" />
              <span className="text-foreground font-medium text-sm">Create New Playbook</span>
            </Link>
            <Link href="/responder/blocked" className="flex items-center gap-3 px-4 py-3 bg-slate-900/50 hover:bg-slate-700/50 border border-slate-700 rounded-xl transition-colors">
              <Ban className="w-5 h-5 text-red-400" />
              <span className="text-foreground font-medium text-sm">View Blocked IPs</span>
            </Link>
          </div>
        </div>

        {/* Recent Executions */}
        <div className="bg-slate-800 rounded-2xl border border-slate-700 overflow-hidden flex flex-col">
          <div className="p-6 border-b border-slate-700">
            <h5 className="text-sm font-bold text-foreground uppercase tracking-wider flex items-center gap-2">
              <History className="w-4 h-4 text-amber-400" /> Recent Executions
            </h5>
          </div>
          <div className="overflow-x-auto flex-1">
            {data.recentExecutions.length === 0 ? (
              <div className="p-6 text-center text-slate-400 text-sm">No executions yet</div>
            ) : (
              <table className="w-full text-left">
                <thead className="bg-slate-900/50">
                  <tr>
                    <th className="p-4 text-xs font-semibold text-slate-400 uppercase tracking-wider">Playbook</th>
                    <th className="p-4 text-xs font-semibold text-slate-400 uppercase tracking-wider">Status</th>
                    <th className="p-4 text-xs font-semibold text-slate-400 uppercase tracking-wider">Trigger</th>
                    <th className="p-4 text-xs font-semibold text-slate-400 uppercase tracking-wider">Time</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-700/50">
                  {data.recentExecutions.map((exec) => (
                    <tr key={exec.id} className="hover:bg-slate-700/30 transition-colors">
                      <td className="p-4 text-sm font-medium text-foreground">{exec.pb_name || 'Unknown'}</td>
                      <td className="p-4 text-sm">
                        <span className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium border ${
                          exec.status === 'completed' ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20' :
                          exec.status === 'failed' ? 'text-red-400 bg-red-500/10 border-red-500/20' :
                          exec.status === 'running' ? 'text-cyan-400 bg-cyan-500/10 border-cyan-500/20' :
                          'text-slate-400 bg-slate-500/10 border-slate-500/20'
                        }`}>
                          {exec.status === 'completed' && <CheckCircle className="w-3 h-3" />}
                          {exec.status === 'failed' && <AlertCircle className="w-3 h-3" />}
                          {exec.status === 'running' && <Loader2 className="w-3 h-3 animate-spin" />}
                          {exec.status.charAt(0).toUpperCase() + exec.status.slice(1)}
                        </span>
                      </td>
                      <td className="p-4 text-sm text-slate-300">{exec.triggered_by}</td>
                      <td className="p-4 text-sm text-slate-400">
                        {exec.created_at ? format(new Date(exec.created_at), 'MMM dd, HH:mm') : '-'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
