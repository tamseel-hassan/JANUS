"use client";

import { useEffect, useState } from "react";
import { Shield, Search, Globe, Link as LinkIcon, Database, AlertCircle, CheckCircle } from "lucide-react";

interface HistoryItem {
  id: string;
  observable_type: string;
  observable_value: string;
  risk_level: string;
  username: string;
  created_at: string;
}

export default function ThreatIntelPage() {
  const [history, setHistory] = useState<HistoryItem[]>([]);
  const [loading, setLoading] = useState(true);
  
  // Form state
  const [type, setType] = useState('ip');
  const [value, setValue] = useState('');
  const [analyzing, setAnalyzing] = useState(false);
  const [results, setResults] = useState<any>(null);

  const fetchHistory = () => {
    fetch("/api/get_threat_intel.php", { credentials: "include" })
      .then(res => res.json())
      .then(d => { setHistory(d.history || []); setLoading(false); })
      .catch(err => { console.error(err); setLoading(false); });
  };

  useEffect(() => {
    fetchHistory();
  }, []);

  const handleAnalyze = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!value.trim()) return;
    setAnalyzing(true);
    setResults(null);
    
    try {
      const res = await fetch("/api/get_threat_intel.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ observable_type: type, observable_value: value }),
        credentials: "include"
      });
      const data = await res.json();
      if (data.results) {
        setResults(data.results);
      }
      fetchHistory(); // Refresh history table
    } catch (err) {
      console.error(err);
    }
    setAnalyzing(false);
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-foreground tracking-wide">Threat Intelligence & IP Reputation</h1>
        <p className="text-text-muted mt-1">Cross-reference observables against global OSINT databases.</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        {/* Analysis Form */}
        <div className="lg:col-span-1 bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden p-6 h-fit">
          <h2 className="text-lg font-semibold text-foreground mb-4 flex items-center">
            <Search className="w-5 h-5 mr-2 text-accent-primary" /> Analyze Observable
          </h2>
          <form onSubmit={handleAnalyze} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-text-muted mb-1">Observable Type</label>
              <select 
                value={type} 
                onChange={(e) => setType(e.target.value)}
                className="w-full bg-bg-main border border-border-subtle rounded-2xl py-2 px-3 text-foreground focus:outline-none focus:border-accent-primary"
              >
                <option value="ip">IP Address</option>
                <option value="domain">Domain Name</option>
                <option value="url">URL</option>
                <option value="hash">File Hash (MD5/SHA256)</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-text-muted mb-1">Observable Value</label>
              <input 
                type="text" 
                required
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder={type === 'ip' ? '8.8.8.8' : 'example.com'}
                className="w-full bg-bg-main border border-border-subtle rounded-2xl py-2 px-3 text-foreground focus:outline-none focus:border-accent-primary"
              />
            </div>
            <button 
              type="submit" 
              disabled={analyzing}
              className="w-full bg-accent-primary hover:bg-accent-hover disabled:opacity-50 text-foreground font-medium py-2 rounded-2xl transition-colors"
            >
              {analyzing ? 'Analyzing...' : 'Run Analysis'}
            </button>
          </form>
        </div>

        {/* Results Panel */}
        <div className="lg:col-span-2 space-y-6">
          
          {results && (
            <div className="bg-bg-raised border border-accent-primary rounded-2xl overflow-hidden p-6 animate-pulse-once">
              <h2 className="text-lg font-semibold text-foreground mb-4">Analysis Results: <span className="text-accent-primary font-mono">{value}</span></h2>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {Object.entries(results).map(([source, data]: [string, any]) => (
                  <div key={source} className="bg-bg-main border border-border-subtle p-4 rounded-2xl">
                    <h3 className="text-text-muted font-semibold flex items-center mb-2 uppercase text-xs">
                      <Database className="w-3 h-3 mr-1" /> {source}
                    </h3>
                    <div className="flex items-center space-x-2">
                      <span className="text-sm text-foreground">Risk Score:</span>
                      <span className={`font-bold ${data.risk_score > 50 ? 'text-red-400' : 'text-green-400'}`}>
                        {data.risk_score} / 100
                      </span>
                    </div>
                    <div className="flex items-center space-x-2 mt-1">
                      <span className="text-sm text-foreground">Status:</span>
                      {data.is_malicious ? (
                        <span className="text-red-400 text-sm flex items-center"><AlertCircle className="w-4 h-4 mr-1"/> Malicious</span>
                      ) : (
                        <span className="text-green-400 text-sm flex items-center"><CheckCircle className="w-4 h-4 mr-1"/> Clean</span>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden">
            <div className="p-4 border-b border-border-subtle bg-bg-main">
              <h3 className="font-semibold text-foreground flex items-center"><Shield className="w-4 h-4 mr-2" /> Recent Analysis History</h3>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle">
                  <tr>
                    <th className="px-6 py-3 font-medium">Date</th>
                    <th className="px-6 py-3 font-medium">User</th>
                    <th className="px-6 py-3 font-medium">Type</th>
                    <th className="px-6 py-3 font-medium">Observable</th>
                    <th className="px-6 py-3 font-medium text-right">Risk Level</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-subtle">
                  {loading && <tr><td colSpan={5} className="px-6 py-8 text-center text-text-muted">Loading...</td></tr>}
                  {!loading && history.map((item) => (
                    <tr key={item.id} className="hover:bg-bg-card/50 transition-colors">
                      <td className="px-6 py-3 text-text-muted">{item.created_at}</td>
                      <td className="px-6 py-3 text-foreground">{item.username}</td>
                      <td className="px-6 py-3 text-text-muted uppercase text-xs">{item.observable_type}</td>
                      <td className="px-6 py-3 font-mono text-foreground">{item.observable_value}</td>
                      <td className="px-6 py-3 text-right">
                        <span className={`inline-block px-2 py-1 rounded-2xl text-xs font-bold uppercase ${
                          item.risk_level === 'critical' ? 'bg-red-900/50 text-red-400' :
                          item.risk_level === 'high' ? 'bg-orange-900/50 text-orange-400' :
                          item.risk_level === 'medium' ? 'bg-yellow-900/50 text-yellow-400' :
                          'bg-green-900/50 text-green-400'
                        }`}>
                          {item.risk_level}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
