"use client";

import { useEffect, useState } from "react";
import { Filter, Calendar, Network, Shield, User, Bomb, Cog, Server } from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import { safeFetch } from "@/lib/safeFetch";

const REPORT_CATALOG = {
  network: { label: 'Network Analysis', icon: Network, reports: [
      { type: 'traffic', label: 'Traffic Analysis' },
      { type: 'applications', label: 'Applications' },
      { type: 'bandwidth', label: 'Bandwidth' },
  ]},
  security: { label: 'Security & Threats', icon: Shield, reports: [
      { type: 'security_analysis', label: 'MITRE ATT&CK' },
      { type: 'threat_hunting', label: 'Threat Hunting' },
      { type: 'bruteforce', label: 'Brute Force' },
      { type: 'anomaly', label: 'Anomaly Detection' },
  ]},
  access: { label: 'Access & Auth', icon: User, reports: [
      { type: 'remote_access', label: 'Remote Access' },
      { type: 'user_activity', label: 'User Activity' },
  ]},
  attacks: { label: 'Attack Detection', icon: Bomb, reports: [
      { type: 'dns_attacks', label: 'DNS Attacks' },
      { type: 'ddos', label: 'DDoS' },
      { type: 'malware', label: 'Malware' },
  ]},
  config: { label: 'Config & Compliance', icon: Cog, reports: [
      { type: 'config_changes', label: 'Config Changes' },
      { type: 'compliance', label: 'Compliance' },
  ]},
  assets: { label: 'Asset & Inventory', icon: Server, reports: [
      { type: 'asset_discovery', label: 'Asset Discovery' },
      { type: 'vulnerability', label: 'Vulnerabilities' },
      { type: 'endpoint_activity', label: 'Endpoint Activity' },
  ]},
};

export function Reports() {
  const [devices, setDevices] = useState<string[]>([]);
  const [currentCategory, setCurrentCategory] = useState('network');
  const [currentReport, setCurrentReport] = useState('traffic');
  const [timeRange, setTimeRange] = useState('24h');
  const [selectedDevice, setSelectedDevice] = useState('');
  const [reportHtml, setReportHtml] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    safeFetch<{ devices: string[] }>("/api/get_reports_meta.php", {}, "Reports")
      .then(data => { if (data) setDevices(data.devices || []); });
  }, []);

  useEffect(() => {
    setLoading(true);
    const url = `/ss/${currentReport}.php?range=${timeRange}&device=${selectedDevice}`;
    
    fetch(url, { credentials: "include" })
      .then(res => {
        if (!res.ok) throw new Error("Failed to load report");
        return res.text();
      })
      .then(html => {
        setReportHtml(html);
        setLoading(false);
      })
      .catch(err => {
        setReportHtml(`<div class="text-red-400 p-4">Error loading report: ${err.message}</div>`);
        setLoading(false);
      });
  }, [currentReport, timeRange, selectedDevice]);

  useEffect(() => {
    if (!loading && reportHtml) {
      const container = document.getElementById("legacy-report-container");
      if (container) {
        const scripts = container.querySelectorAll("script");
        scripts.forEach(script => {
          const newScript = document.createElement("script");
          if (script.src) {
            newScript.src = script.src;
          } else {
            newScript.textContent = script.textContent;
          }
          document.body.appendChild(newScript);
          document.body.removeChild(newScript);
        });
      }
    }
  }, [loading, reportHtml]);

  const activeReports = REPORT_CATALOG[currentCategory as keyof typeof REPORT_CATALOG].reports;

  return (
    <div className="space-y-6">
      
      {/* Header & Global Filters */}
      <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-wide">Security Analytics Console</h1>
          <p className="text-text-muted mt-1">Advanced reporting and threat analysis.</p>
        </div>
        
        <div className="flex space-x-4">
          <CustomSelect
            value={selectedDevice}
            onChange={setSelectedDevice}
            options={[
              { value: "", label: "All Devices" },
              ...devices.map(ip => ({ value: ip, label: ip }))
            ]}
            icon={<Filter className="w-4 h-4" />}
          />
          
          <CustomSelect
            value={timeRange}
            onChange={setTimeRange}
            options={[
              { value: "15m", label: "Last 15 Mins" },
              { value: "1h", label: "Last 1 Hour" },
              { value: "6h", label: "Last 6 Hours" },
              { value: "24h", label: "Last 24 Hours" },
              { value: "7d", label: "Last 7 Days" },
              { value: "30d", label: "Last 30 Days" }
            ]}
            icon={<Calendar className="w-4 h-4" />}
          />
        </div>
      </div>

      {/* Two-Tier Navigation */}
      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden">
        
        {/* Category Tabs */}
        <div className="flex overflow-x-auto border-b border-border-subtle bg-bg-main">
          {Object.entries(REPORT_CATALOG).map(([key, cat]) => {
            const Icon = cat.icon;
            const isActive = currentCategory === key;
            return (
              <button 
                key={key}
                onClick={() => {
                  setCurrentCategory(key);
                  setCurrentReport(cat.reports[0].type);
                }}
                className={`flex items-center px-6 py-4 text-sm font-medium transition-colors whitespace-nowrap border-b-2 ${
                  isActive 
                    ? 'border-accent-primary text-accent-primary bg-bg-card/50' 
                    : 'border-transparent text-text-muted hover:text-foreground hover:bg-bg-card/30'
                }`}
              >
                <Icon className="w-4 h-4 mr-2" />
                {cat.label}
              </button>
            )
          })}
        </div>

        {/* Report Chips */}
        <div className="p-4 flex flex-wrap gap-2">
          {activeReports.map(report => (
            <button
              key={report.type}
              onClick={() => setCurrentReport(report.type)}
              className={`px-4 py-1.5 rounded-full text-sm font-medium transition-colors border ${
                currentReport === report.type
                  ? 'bg-accent-primary text-foreground border-accent-primary'
                  : 'bg-transparent text-text-muted border-border-subtle hover:border-accent-primary hover:text-foreground'
              }`}
            >
              {report.label}
            </button>
          ))}
        </div>
        
      </div>

      {/* Legacy Report Container */}
      <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6 min-h-[400px]">
        {loading ? (
          <div className="flex flex-col justify-center items-center h-full mt-20">
            <div className="w-10 h-10 border-4 border-border-subtle border-t-accent-primary rounded-full animate-spin"></div>
            <p className="mt-4 text-text-muted text-sm font-mono tracking-widest uppercase">Loading Analytics...</p>
          </div>
        ) : (
          <div 
            id="legacy-report-container" 
            className="legacy-html-wrapper" 
            dangerouslySetInnerHTML={{ __html: reportHtml || '' }} 
          />
        )}
      </div>

    </div>
  );
}

export default Reports;
