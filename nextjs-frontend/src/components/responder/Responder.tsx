'use client';

import { useState, useEffect } from 'react';
import { Shield, ShieldAlert, Plus, Server, CheckCircle2, Loader2, AlertCircle, Activity } from 'lucide-react';
import CustomSelect from '@/components/ui/CustomSelect';
import { Button } from "@/components/ui/Button";
import { Firewall, Action } from "@/types/responder";
import { safeFetch } from "@/lib/safeFetch";

export function Responder() {
  const [firewalls, setFirewalls] = useState<Firewall[]>([]);
  const [actions, setActions] = useState<Action[]>([]);
  const [loading, setLoading] = useState(true);

  // Forms state
  const [fwForm, setFwForm] = useState({ firewall_ip: '', api_key: '', username: '' });
  const [blockForm, setBlockForm] = useState({ target_ip: '', firewall_ip: '', reason: '' });

  const [message, setMessage] = useState<{type: 'success'|'error', text: string} | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const fetchData = async () => {
    const data = await safeFetch<{ firewalls: Firewall[]; actions: Action[] }>(
      '/api/get_responder.php', {}, "Responder"
    );
    if (data) {
      setFirewalls(data.firewalls || []);
      setActions(data.actions || []);
    }
    setLoading(false);
  };

  useEffect(() => {
    fetchData();
  }, []);

  const handleAddFirewall = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setMessage(null);
    try {
      const res = await fetch('/api/post_responder.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add_firewall', ...fwForm })
      });
      const data = await res.json();
      if (data.error) {
        setMessage({ type: 'error', text: data.error });
      } else {
        setMessage({ type: 'success', text: data.success });
        setFwForm({ firewall_ip: '', api_key: '', username: '' });
        fetchData();
      }
    } catch (err) {
      setMessage({ type: 'error', text: 'Network error occurred.' });
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleBlockIP = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!blockForm.firewall_ip) {
      setMessage({ type: 'error', text: 'Please select a target firewall.' });
      return;
    }
    setIsSubmitting(true);
    setMessage(null);
    try {
      const res = await fetch('/api/post_responder.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'block_ip', ...blockForm })
      });
      const data = await res.json();
      if (data.error) {
        setMessage({ type: 'error', text: data.error });
      } else {
        setMessage({ type: 'success', text: data.success });
        setBlockForm({ target_ip: '', firewall_ip: blockForm.firewall_ip, reason: '' });
        fetchData();
      }
    } catch (err) {
      setMessage({ type: 'error', text: 'Network error occurred.' });
    } finally {
      setIsSubmitting(false);
    }
  };

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[60vh]">
        <Loader2 className="w-12 h-12 text-red-500 animate-spin mb-4" />
        <p className="text-slate-400">Loading automated responder...</p>
      </div>
    );
  }

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      <div className="flex items-center gap-4 border-b border-slate-700 pb-6">
        <div className="w-16 h-16 bg-red-500/20 rounded-2xl flex items-center justify-center border border-red-500/30">
          <ShieldAlert className="w-8 h-8 text-red-400" />
        </div>
        <div>
          <h1 className="text-2xl font-bold text-foreground mb-1">Automated Incident Response</h1>
          <p className="text-slate-400">Configure firewall integrations and execute immediate containment actions</p>
        </div>
      </div>

      {message && (
        <div className={`p-4 rounded-2xl flex items-center gap-3 border ${
          message.type === 'error' 
            ? 'bg-red-500/10 border-red-500/30 text-red-400' 
            : 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
        }`}>
          {message.type === 'error' ? <AlertCircle className="w-5 h-5 flex-shrink-0" /> : <CheckCircle2 className="w-5 h-5 flex-shrink-0" />}
          <p className="text-sm font-medium">{message.text}</p>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Block IP Form */}
        <div className="bg-slate-800 rounded-2xl border border-slate-700 p-6 relative overflow-hidden">
          <div className="absolute top-0 right-0 w-32 h-32 bg-red-500/5 rounded-full -mr-10 -mt-10 pointer-events-none"></div>
          
          <div className="flex items-center gap-3 mb-6 relative z-10">
            <Shield className="w-5 h-5 text-red-400" />
            <h2 className="text-xl font-bold text-foreground">Execute Block Action</h2>
          </div>
          
          <form onSubmit={handleBlockIP} className="space-y-4 relative z-10">
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Target IP Address</label>
              <input
                type="text"
                value={blockForm.target_ip}
                onChange={e => setBlockForm({ ...blockForm, target_ip: e.target.value })}
                className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-red-500 focus:ring-1 focus:ring-red-500 transition-all font-mono"
                placeholder="e.g. 192.168.1.50 or 8.8.8.8"
                required
              />
            </div>
            
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Target Firewall</label>
              <CustomSelect
                value={blockForm.firewall_ip}
                onChange={val => setBlockForm({ ...blockForm, firewall_ip: val })}
                options={[
                  { value: "", label: "Select Firewall..." },
                  ...firewalls.filter(f => f.is_active).map(f => ({ value: f.firewall_ip, label: `${f.firewall_ip} (added by ${f.username})` }))
                ]}
                placeholder="Select Firewall..."
              />
            </div>
            
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Reason for Block</label>
              <input
                type="text"
                value={blockForm.reason}
                onChange={e => setBlockForm({ ...blockForm, reason: e.target.value })}
                className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-red-500 focus:ring-1 focus:ring-red-500 transition-all"
                placeholder="e.g. Malware C2 connection detected"
                required
              />
            </div>

            <Button
              type="submit"
              variant="danger"
              className="w-full py-3 mt-4"
              disabled={isSubmitting || firewalls.filter(f => f.is_active).length === 0}
              isLoading={isSubmitting}
            >
              <ShieldAlert className="w-5 h-5 mr-2" /> Block IP on Firewall
            </Button>
            {firewalls.filter(f => f.is_active).length === 0 && (
              <p className="text-xs text-amber-400 mt-2 text-center">Configure at least one firewall first.</p>
            )}
          </form>
        </div>

        {/* Add Firewall Form */}
        <div className="bg-slate-800 rounded-2xl border border-slate-700 p-6 relative overflow-hidden">
          <div className="flex items-center gap-3 mb-6">
            <Plus className="w-5 h-5 text-emerald-400" />
            <h2 className="text-xl font-bold text-foreground">Add FortiGate Firewall</h2>
          </div>
          
          <form onSubmit={handleAddFirewall} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">Firewall IP/Hostname</label>
              <input
                type="text"
                value={fwForm.firewall_ip}
                onChange={e => setFwForm({ ...fwForm, firewall_ip: e.target.value })}
                className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all"
                placeholder="e.g. 10.0.0.1"
                required
              />
            </div>
            
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">REST API Key</label>
              <input
                type="password"
                value={fwForm.api_key}
                onChange={e => setFwForm({ ...fwForm, api_key: e.target.value })}
                className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all"
                placeholder="FortiGate REST API Admin Key"
                required
              />
            </div>
            
            <div>
              <label className="block text-sm font-medium text-slate-300 mb-1.5">API Username (Optional)</label>
              <input
                type="text"
                value={fwForm.username}
                onChange={e => setFwForm({ ...fwForm, username: e.target.value })}
                className="w-full bg-slate-900 border border-slate-700 rounded-2xl px-4 py-2.5 text-foreground focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all"
                placeholder="e.g. restadmin"
              />
            </div>

            <Button
              type="submit"
              variant="secondary"
              className="w-full py-3 mt-4 hover:border-emerald-500"
              disabled={isSubmitting}
              isLoading={isSubmitting}
            >
              Test Connection & Save
            </Button>
          </form>
        </div>
      </div>

      {/* Configured Firewalls Table */}
      <div className="bg-slate-800 rounded-2xl border border-slate-700 overflow-hidden mt-8">
        <div className="p-4 border-b border-slate-700 bg-slate-800/50 flex items-center justify-between">
          <h2 className="font-bold text-foreground flex items-center gap-2">
            <Server className="w-5 h-5 text-blue-400" /> Configured Firewalls
          </h2>
          <span className="text-xs font-medium bg-slate-700 text-slate-300 px-2.5 py-1 rounded-full border border-slate-600">
            {firewalls.length} Total
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-slate-900/50 border-b border-slate-700">
                <th className="p-4 font-semibold text-slate-300 text-sm">Firewall IP</th>
                <th className="p-4 font-semibold text-slate-300 text-sm">Status</th>
                <th className="p-4 font-semibold text-slate-300 text-sm">Added By</th>
                <th className="p-4 font-semibold text-slate-300 text-sm">Added At</th>
              </tr>
            </thead>
            <tbody>
              {firewalls.length === 0 ? (
                <tr><td colSpan={4} className="p-6 text-center text-slate-400">No firewalls configured yet.</td></tr>
              ) : (
                firewalls.map((fw, idx) => (
                  <tr key={idx} className="border-b border-slate-700/50 hover:bg-slate-700/30 transition-colors">
                    <td className="p-4 text-sm font-medium text-foreground font-mono">{fw.firewall_ip}</td>
                    <td className="p-4 text-sm">
                      {fw.is_active ? (
                        <span className="inline-flex items-center gap-1.5 text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-2xl text-xs font-medium border border-emerald-500/20">
                          <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 text-slate-400 bg-slate-500/10 px-2 py-0.5 rounded-2xl text-xs font-medium border border-slate-500/20">
                          <span className="w-1.5 h-1.5 rounded-full bg-slate-500"></span> Inactive
                        </span>
                      )}
                    </td>
                    <td className="p-4 text-sm text-slate-400">{fw.username}</td>
                    <td className="p-4 text-sm text-slate-400">{fw.added_at}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Recent Actions Table */}
      <div className="bg-slate-800 rounded-2xl border border-slate-700 overflow-hidden mt-8">
        <div className="p-4 border-b border-slate-700 bg-slate-800/50">
          <h2 className="font-bold text-foreground flex items-center gap-2">
            <Activity className="w-5 h-5 text-purple-400" /> Recent Containment Actions
          </h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse">
            <thead>
              <tr className="bg-slate-900/50 border-b border-slate-700">
                <th className="p-4 font-semibold text-slate-300 text-sm">Time</th>
                <th className="p-4 font-semibold text-slate-300 text-sm">Target IP</th>
                <th className="p-4 font-semibold text-slate-300 text-sm">Action</th>
                <th className="p-4 font-semibold text-slate-300 text-sm">Firewall</th>
                <th className="p-4 font-semibold text-slate-300 text-sm">Status</th>
                <th className="p-4 font-semibold text-slate-300 text-sm">Executed By</th>
              </tr>
            </thead>
            <tbody>
              {actions.length === 0 ? (
                <tr><td colSpan={6} className="p-6 text-center text-slate-400">No containment actions have been executed.</td></tr>
              ) : (
                actions.map((action, idx) => (
                  <tr key={idx} className="border-b border-slate-700/50 hover:bg-slate-700/30 transition-colors">
                    <td className="p-4 text-sm text-slate-400 whitespace-nowrap">{action.created_at}</td>
                    <td className="p-4 text-sm font-medium text-red-400 font-mono">{action.target_ip}</td>
                    <td className="p-4 text-sm text-slate-300">
                      <span className="bg-red-500/10 text-red-400 px-2 py-0.5 rounded-2xl text-xs border border-red-500/20 font-medium">
                        {action.action_type.replace('_', ' ').toUpperCase()}
                      </span>
                    </td>
                    <td className="p-4 text-sm text-slate-400 font-mono">{action.firewall_ip}</td>
                    <td className="p-4 text-sm">
                      {action.status === 'success' ? (
                        <span className="text-emerald-400 flex items-center gap-1"><CheckCircle2 className="w-4 h-4"/> Success</span>
                      ) : (
                        <span className="text-amber-400 flex items-center gap-1"><Loader2 className="w-4 h-4 animate-spin"/> Pending</span>
                      )}
                    </td>
                    <td className="p-4 text-sm text-slate-400">{action.username}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

    </div>
  );
}
export default Responder;
