"use client";

import { useEffect, useState, useRef } from "react";
import {
  Filter,
  Calendar,
  Network,
  Shield,
  User,
  Bomb,
  Cog,
  Server,
  Download,
  Clock,
  RefreshCw,
} from "lucide-react";
import CustomSelect from "@/components/ui/CustomSelect";
import { safeFetch } from "@/lib/safeFetch";
import TrafficReportRenderer from "./TrafficReportRenderer";
import SecurityReportRenderer from "./SecurityReportRenderer";
import ApplicationsReportRenderer from "./ApplicationsReportRenderer";
import BandwidthReportRenderer from "./BandwidthReportRenderer";
import ThreatHuntingRenderer from "./ThreatHuntingRenderer";
import BruteForceRenderer from "./BruteForceRenderer";
import AnomalyRenderer from "./AnomalyRenderer";
import { notify } from "@/services/feedback/feedbackService";
import RemoteAccessRenderer from "./RemoteAccessRenderer";
import UserActivityRenderer from "./UserActivityRenderer";
import DnsAttacksRenderer from "./DnsAttacksRenderer";
import DdosRenderer from "./DdosRenderer";
import MalwareRenderer from "./MalwareRenderer";
import ConfigChangesRenderer from "./ConfigChangesRenderer";
import ComplianceRenderer from "./ComplianceRenderer";
import AssetDiscoveryRenderer from "./AssetDiscoveryRenderer";
import VulnerabilityRenderer from "./VulnerabilityRenderer";
import EndpointActivityRenderer from "./EndpointActivityRenderer";

const REPORT_CATALOG = {
  network: {
    label: "Network Analysis",
    icon: Network,
    reports: [
      { type: "traffic", label: "Traffic Analysis" },
      { type: "applications", label: "Applications" },
      { type: "bandwidth", label: "Bandwidth" },
    ],
  },
  security: {
    label: "Security & Threats",
    icon: Shield,
    reports: [
      { type: "security_analysis", label: "MITRE ATT&CK" },
      { type: "threat_hunting", label: "Threat Hunting" },
      { type: "bruteforce", label: "Brute Force" },
      { type: "anomaly", label: "Anomaly Detection" },
    ],
  },
  access: {
    label: "Access & Auth",
    icon: User,
    reports: [
      { type: "remote_access", label: "Remote Access" },
      { type: "user_activity", label: "User Activity" },
    ],
  },
  attacks: {
    label: "Attack Detection",
    icon: Bomb,
    reports: [
      { type: "dns_attacks", label: "DNS Attacks" },
      { type: "ddos", label: "DDoS" },
      { type: "malware", label: "Malware" },
    ],
  },
  config: {
    label: "Config & Compliance",
    icon: Cog,
    reports: [
      { type: "config_changes", label: "Config Changes" },
      { type: "compliance", label: "Compliance" },
    ],
  },
  assets: {
    label: "Asset & Inventory",
    icon: Server,
    reports: [
      { type: "asset_discovery", label: "Asset Discovery" },
      { type: "vulnerability", label: "Vulnerabilities" },
      { type: "endpoint_activity", label: "Endpoint Activity" },
    ],
  },
};

export function Reports() {
  const [devices, setDevices] = useState<string[]>([]);
  const [currentCategory, setCurrentCategory] = useState("network");
  const [currentReport, setCurrentReport] = useState("traffic");
  const [timeRange, setTimeRange] = useState("24h");
  const [selectedDevice, setSelectedDevice] = useState("");
  const [reportHtml, setReportHtml] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshKey, setRefreshKey] = useState(0);
  const [indicatorStyle, setIndicatorStyle] = useState({
    left: 0,
    width: 0,
    opacity: 0,
  });
  const tabsRef = useRef<{ [key: string]: HTMLButtonElement | null }>({});

  useEffect(() => {
    const updateIndicator = () => {
      const activeTab = tabsRef.current[currentCategory];
      if (activeTab) {
        setIndicatorStyle({
          left: activeTab.offsetLeft,
          width: activeTab.clientWidth,
          opacity: 1,
        });
      }
    };

    updateIndicator();
    // Slight delay in case of font loading or layout shift
    setTimeout(updateIndicator, 50);

    window.addEventListener("resize", updateIndicator);
    return () => window.removeEventListener("resize", updateIndicator);
  }, [currentCategory]);

  useEffect(() => {
    safeFetch<{ devices: string[] }>(
      "/api/get_reports_meta.php",
      {},
      "Reports",
    ).then((data) => {
      if (data) setDevices(data.devices || []);
    });
  }, []);

  useEffect(() => {
    const nativeReports = [
      "traffic",
      "security_analysis",
      "applications",
      "bandwidth",
      "threat_hunting",
      "bruteforce",
      "anomaly",
      "remote_access",
      "user_activity",
      "dns_attacks",
      "ddos",
      "malware",
      "config_changes",
      "compliance",
      "asset_discovery",
      "vulnerability",
      "endpoint_activity",
    ];
    if (nativeReports.includes(currentReport)) {
      setReportHtml(null);
      setLoading(false);
      return;
    }

    setLoading(true);
    const url = `/ss/${currentReport}.php?range=${timeRange}&device=${selectedDevice}`;

    fetch(url, { credentials: "include" })
      .then((res) => {
        if (!res.ok) throw new Error("Failed to load report");
        return res.text();
      })
      .then((html) => {
        setReportHtml(html);
        setLoading(false);
      })
      .catch((err) => {
        setReportHtml(
          `<div class="text-red-400 p-4">Error loading report: ${err.message}</div>`,
        );
        setLoading(false);
      });
  }, [currentReport, timeRange, selectedDevice, refreshKey]);

  useEffect(() => {
    if (!loading && reportHtml) {
      const container = document.getElementById("legacy-report-container");
      if (container) {
        const scripts = container.querySelectorAll("script");

        const executeScriptsSequentially = async () => {
          for (const script of Array.from(scripts)) {
            await new Promise<void>((resolve) => {
              const newScript = document.createElement("script");
              if (script.src) {
                newScript.src = script.src;
                newScript.onload = () => resolve();
                newScript.onerror = () => resolve();
                document.body.appendChild(newScript);
              } else {
                newScript.textContent = script.textContent;
                document.body.appendChild(newScript);
                document.body.removeChild(newScript);
                resolve();
              }
            });
          }
        };

        executeScriptsSequentially();
      }
    }
  }, [loading, reportHtml]);

  const activeReports =
    REPORT_CATALOG[currentCategory as keyof typeof REPORT_CATALOG].reports;

  return (
    <div className="space-y-6">
      {/* Header & Global Filters */}
      <div className="flex flex-col xl:flex-row justify-between items-start xl:items-end gap-4">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-wide">
            Security Analytics Console
          </h1>
          <p className="text-text-muted mt-1">
            Advanced reporting and threat analysis.
          </p>
        </div>

        <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3">
          <div className="flex items-center space-x-2">
            <button
              onClick={() =>
                notify.info(
                  "Export functionality will be available in a future update.",
                )
              }
              className="flex items-center px-3 py-2 text-sm font-medium text-green-500 bg-green-500/10 border border-green-500/20 rounded-xl hover:bg-green-500/20 transition-colors"
            >
              <Download className="w-4 h-4 mr-1.5" />
              Export
            </button>
            <button
              onClick={() =>
                notify.info(
                  "Scheduled reports configuration will be available soon.",
                )
              }
              className="flex items-center px-3 py-2 text-sm font-medium text-accent-primary border border-border-subtle bg-bg-card rounded-xl hover:bg-bg-darker transition-colors"
            >
              <Clock className="w-4 h-4 mr-1.5" />
              Schedule
            </button>
            <button
              onClick={() => setRefreshKey((prev) => prev + 1)}
              className="flex items-center px-3 py-2 text-sm font-medium text-foreground bg-accent-primary rounded-xl hover:bg-accent-hover transition-colors"
            >
              <RefreshCw className="w-4 h-4 mr-1.5" />
              Refresh
            </button>
          </div>

          <div className="hidden sm:block w-px h-8 bg-border-subtle mx-1"></div>

          <div className="flex items-center space-x-2">
            <CustomSelect
              value={selectedDevice}
              onChange={setSelectedDevice}
              options={[
                { value: "", label: "All Devices" },
                ...devices.map((ip) => ({ value: ip, label: ip })),
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
                { value: "30d", label: "Last 30 Days" },
              ]}
              icon={<Calendar className="w-4 h-4" />}
            />
          </div>
        </div>
      </div>

      {/* Two-Tier Navigation */}
      <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden">
        {/* Category Tabs */}
        <div className="tab-indicator relative flex overflow-x-auto border-b border-border-subtle bg-bg-main p-2 gap-2 hide-scrollbar">
          {/* Animated Background Pill */}
          <div
            className="tab-indicator absolute top-2 bottom-2 bg-bg-card/50 border border-accent-primary rounded-full transition-all duration-300 ease-out z-0 pointer-events-none"
            style={indicatorStyle}
          />
          {Object.entries(REPORT_CATALOG).map(([key, cat]) => {
            const Icon = cat.icon;
            const isActive = currentCategory === key;
            return (
              <button
                key={key}
                ref={(el) => {
                  tabsRef.current[key] = el;
                }}
                onClick={() => {
                  setCurrentCategory(key);
                  setCurrentReport(cat.reports[0].type);
                }}
                className={`relative z-10 flex shrink-0 items-center px-6 py-2.5 text-sm font-medium transition-all duration-300 whitespace-nowrap rounded-full border border-transparent ${
                  isActive
                    ? "text-accent-primary"
                    : "text-text-muted hover:text-foreground hover:bg-bg-card/30"
                }`}
              >
                <Icon className="w-4 h-4 mr-2" />
                {cat.label}
              </button>
            );
          })}
        </div>

        {/* Report Chips */}
        <div className="p-4 flex flex-wrap gap-2">
          {activeReports.map((report) => (
            <button
              key={report.type}
              onClick={() => setCurrentReport(report.type)}
              className={`px-4 py-1.5 rounded-full text-sm font-medium transition-colors border ${
                currentReport === report.type
                  ? "bg-accent-primary text-foreground border-accent-primary"
                  : "bg-transparent text-text-muted border-border-subtle hover:border-accent-primary hover:text-foreground"
              }`}
            >
              {report.label}
            </button>
          ))}
        </div>
      </div>

      {/* Report Container */}
      <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6 min-h-[400px]">
        <div
          key={`${currentReport}-${refreshKey}`}
          className="page-enter h-full"
        >
          {currentReport === "traffic" ? (
            <TrafficReportRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "security_analysis" ? (
            <SecurityReportRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "applications" ? (
            <ApplicationsReportRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "bandwidth" ? (
            <BandwidthReportRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "threat_hunting" ? (
            <ThreatHuntingRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "bruteforce" ? (
            <BruteForceRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "anomaly" ? (
            <AnomalyRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "remote_access" ? (
            <RemoteAccessRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "user_activity" ? (
            <UserActivityRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "dns_attacks" ? (
            <DnsAttacksRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "ddos" ? (
            <DdosRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "malware" ? (
            <MalwareRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "config_changes" ? (
            <ConfigChangesRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "compliance" ? (
            <ComplianceRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "asset_discovery" ? (
            <AssetDiscoveryRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "vulnerability" ? (
            <VulnerabilityRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : currentReport === "endpoint_activity" ? (
            <EndpointActivityRenderer
              timeRange={timeRange}
              selectedDevice={selectedDevice}
            />
          ) : loading ? (
            <div className="flex flex-col justify-center items-center h-full mt-20">
              <div className="w-10 h-10 border-4 border-border-subtle border-t-accent-primary rounded-full animate-spin"></div>
              <p className="mt-4 text-text-muted text-sm font-mono tracking-widest uppercase">
                Loading Analytics...
              </p>
            </div>
          ) : (
            <div
              id="legacy-report-container"
              className="legacy-html-wrapper"
              dangerouslySetInnerHTML={{ __html: reportHtml || "" }}
            />
          )}
        </div>
      </div>
    </div>
  );
}

export default Reports;
