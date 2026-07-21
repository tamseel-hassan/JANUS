"use client";

import { useState } from "react";
import {
  Satellite,
  Network,
  Search,
  CheckCircle,
  Info,
  List,
  Terminal,
  AlertTriangle,
} from "lucide-react";
import { RiskBadge } from "@/components/ui/RiskBadge";
import { safeFetch } from "@/lib/safeFetch";
import { Button } from "@/components/ui/Button";

export function VulnScan() {
  const [target, setTarget] = useState("");
  const [scanning, setScanning] = useState(false);
  const [result, setResult] = useState<any>(null);
  const [error, setError] = useState<string | null>(null);

  const handleScan = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!target.trim()) return;

    setScanning(true);
    setResult(null);
    setError(null);

    try {
      const data = await safeFetch<any>(
        "/api/get_scan.php",
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ target }),
        },
        "VulnScan",
      );
      if (!data) {
        setError("Server returned an unexpected response.");
      } else if (data.error) {
        setError(data.error);
      } else {
        setResult(data);
      }
    } catch (err: any) {
      setError(err.message);
    }
    setScanning(false);
  };

  return (
    <div className="space-y-6">
      <div className="text-center mb-8">
        <h2 className="text-2xl font-bold text-foreground mb-2 flex justify-center items-center">
          <Satellite className="w-8 h-8 mr-3 text-accent-primary" /> Deep Packet
          Inspection Scanner
        </h2>
        <p className="text-text-muted">
          Janus Scanning Engine | Service Version Detection | OS Fingerprinting
        </p>
      </div>

      <div className="max-w-3xl mx-auto bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden p-6">
        <form onSubmit={handleScan} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-text-muted mb-2">
              Target Host / IP Address
            </label>
            <div className="relative flex">
              <span className="inline-flex items-center px-4 rounded-l-2xl border border-r-0 border-border-subtle bg-bg-main text-accent-primary">
                <Network className="w-5 h-5" />
              </span>
              <input
                type="text"
                required
                value={target}
                onChange={(e) => setTarget(e.target.value)}
                placeholder="e.g. 192.168.1.10 or example.com"
                className="flex-1 block w-full rounded-none bg-bg-card border border-border-subtle text-foreground px-4 py-3 focus:outline-none focus:border-accent-primary"
              />
              <Button
                type="submit"
                variant="primary"
                className="rounded-l-none rounded-r-2xl px-6 font-bold"
                disabled={scanning}
                isLoading={scanning}
              >
                {!scanning && <Search className="w-5 h-5 mr-2" />}
                INITIATE SCAN
              </Button>
            </div>
          </div>
          <div className="text-sm text-text-muted flex items-center">
            <Info className="w-4 h-4 mr-1" /> This scan performs active service
            fingerprinting (-sV). Please allow 15-45 seconds.
          </div>
        </form>
      </div>

      {scanning && (
        <div className="flex flex-col items-center justify-center p-12">
          <div className="relative w-24 h-24 mb-6">
            <div className="absolute inset-0 border-4 border-border-subtle rounded-full"></div>
            <div className="absolute inset-0 border-4 border-accent-primary rounded-full border-t-transparent animate-spin"></div>
            <div className="absolute inset-2 border-4 border-text-muted rounded-full border-b-transparent animate-spin animation-delay-150"></div>
          </div>
          <h4 className="text-xl font-bold text-foreground mb-2">
            Analyzing Network Topology...
          </h4>
          <p className="text-text-muted">
            Performing TCP handshake and banner grabbing
          </p>
        </div>
      )}

      {error && (
        <div className="max-w-3xl mx-auto bg-red-900/30 border border-red-500/50 p-6 rounded-2xl flex items-start">
          <AlertTriangle className="w-6 h-6 text-red-400 mr-3 flex-shrink-0 mt-0.5" />
          <div>
            <h4 className="text-red-400 font-bold mb-1">Scan Failed</h4>
            <p className="text-red-200">{error}</p>
          </div>
        </div>
      )}

      {result && result.status === "down" && (
        <div className="max-w-3xl mx-auto bg-bg-card border border-border-subtle p-6 rounded-2xl text-center">
          <p className="text-text-muted font-semibold">{result.message}</p>
        </div>
      )}

      {result && result.status === "up" && (
        <div className="space-y-6">
          {/* Status Cards */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div className="bg-bg-raised border-t-4 border-t-green-500 border-border-subtle rounded-2xl p-6 text-center">
              <h6 className="text-text-muted text-xs font-bold uppercase tracking-wider mb-3">
                Target Status
              </h6>
              <h3 className="text-2xl font-bold text-green-400 flex items-center justify-center mb-1">
                <CheckCircle className="w-6 h-6 mr-2" /> ONLINE
              </h3>
              <p className="text-foreground font-mono">{result.address}</p>
              <small className="text-text-muted">{result.hostname}</small>
            </div>

            <div className="bg-bg-raised border-t-4 border-t-yellow-500 border-border-subtle rounded-2xl p-6 text-center">
              <h6 className="text-text-muted text-xs font-bold uppercase tracking-wider mb-3">
                Open Ports
              </h6>
              <h3 className="text-2xl font-bold text-foreground mb-1">
                {result.ports.length}
              </h3>
              <p className="text-text-muted">Services Detected</p>
            </div>

            <div className="bg-bg-raised border-t-4 border-t-blue-500 border-border-subtle rounded-2xl p-6 text-center">
              <h6 className="text-text-muted text-xs font-bold uppercase tracking-wider mb-3">
                Scan Type
              </h6>
              <h3 className="text-2xl font-bold text-blue-400 mb-1">INTENSE</h3>
              <p className="text-text-muted">Version Detection</p>
            </div>
          </div>

          {/* Services Table */}
          <div className="bg-bg-raised border border-border-subtle rounded-2xl overflow-hidden">
            <div className="p-4 border-b border-border-subtle bg-bg-main flex justify-between items-center">
              <h5 className="font-semibold text-foreground flex items-center mb-0">
                <List className="w-5 h-5 mr-2 text-accent-primary" /> Detected
                Services
              </h5>
              <span className="bg-bg-card border border-border-subtle px-3 py-1 rounded-2xl text-xs font-mono text-foreground">
                {result.address}
              </span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-bg-main text-text-muted uppercase text-xs tracking-wider border-b border-border-subtle">
                  <tr>
                    <th className="px-6 py-3 font-medium">Port</th>
                    <th className="px-6 py-3 font-medium">Protocol</th>
                    <th className="px-6 py-3 font-medium">State</th>
                    <th className="px-6 py-3 font-medium">Service</th>
                    <th className="px-6 py-3 font-medium">Version / Product</th>
                    <th className="px-6 py-3 font-medium">Risk Assessment</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-subtle">
                  {result.ports.map((port: any, idx: number) => (
                    <tr
                      key={idx}
                      className="hover:bg-bg-card/50 transition-colors"
                    >
                      <td className="px-6 py-4 font-bold text-blue-400">
                        {port.port}
                      </td>
                      <td className="px-6 py-4 text-foreground">
                        {port.protocol}
                      </td>
                      <td className="px-6 py-4">
                        <span className="bg-green-900/50 text-green-400 px-2 py-1 rounded-2xl text-xs font-bold">
                          {port.state}
                        </span>
                      </td>
                      <td className="px-6 py-4 font-bold text-foreground">
                        {port.service}
                      </td>
                      <td className="px-6 py-4">
                        {port.product && (
                          <span className="text-foreground block">
                            {port.product}
                          </span>
                        )}
                        <span className="text-text-muted text-xs font-mono">
                          ({port.version})
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <RiskBadge level={port.risk_level} className="mb-1" />
                        <small className="block text-text-muted text-xs">
                          {port.desc}
                        </small>
                      </td>
                    </tr>
                  ))}
                  {result.ports.length === 0 && (
                    <tr>
                      <td
                        colSpan={6}
                        className="px-6 py-8 text-center text-text-muted"
                      >
                        No open ports detected.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>

          {/* Raw Output */}
          <div className="bg-bg-main border border-border-subtle rounded-2xl overflow-hidden mt-6">
            <div className="p-3 border-b border-border-subtle flex items-center text-xs font-mono text-text-muted uppercase tracking-wider bg-black/40">
              <Terminal className="w-4 h-4 mr-2" /> RAW SCAN OUTPUT LOG
            </div>
            <pre className="p-4 text-text-muted text-xs font-mono overflow-x-auto whitespace-pre-wrap max-h-96 custom-scrollbar">
              {result.raw_xml}
            </pre>
          </div>
        </div>
      )}
    </div>
  );
}
export default VulnScan;
