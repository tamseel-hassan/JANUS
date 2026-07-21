"use client";

import React, { useState } from "react";
import {
  RefreshCw,
  Users,
  Plus,
  Search,
  Filter,
  Server,
  Circle,
  Terminal,
  AlertTriangle,
} from "lucide-react";
import { Button } from "@/components/ui/Button";
import CustomSelect from "@/components/ui/CustomSelect";

export default function NetworkAccessControl() {
  const [searchQuery, setSearchQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all_status");
  const [groupFilter, setGroupFilter] = useState("all_groups");

  const stats = [
    { label: "SWITCHES", value: "configured" },
    { label: "ONLINE", value: "polling OK" },
    { label: "ERRORS", value: "poll failed" },
    { label: "TRACKED MACS", value: "direct devices" },
    { label: "PORTS UP", value: "active links" },
    { label: "ALARMS", value: "unacknowledged" },
  ];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col xl:flex-row justify-between items-start xl:items-center gap-4">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-wide">
            Network Access Control
          </h1>
          <p className="text-text-muted mt-1 text-sm">
            Switch fleet monitoring - Layer 2 MAC tracking - Port-level
            visibility
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
            <input
              type="text"
              placeholder="Search IP, MAC, hostname..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="pl-9 pr-4 py-2 bg-bg-card border border-border-subtle rounded-2xl text-sm text-foreground focus:outline-none focus:border-accent-primary w-64"
            />
          </div>
          <Button variant="secondary" className="rounded-2xl">
            <RefreshCw className="w-4 h-4 mr-2" /> Poll All
          </Button>
          <Button variant="secondary" className="rounded-2xl">
            <Users className="w-4 h-4 mr-2" /> Groups
          </Button>
          <Button variant="primary" className="rounded-2xl">
            <Plus className="w-4 h-4 mr-2" /> Add Switch
          </Button>
        </div>
      </div>

      {/* Stats Row */}
      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
        {stats.map((stat, i) => (
          <div
            key={i}
            className="bg-bg-raised border border-border-subtle rounded-2xl p-4 flex flex-col justify-between h-24"
          >
            <h3 className="text-xs font-bold text-text-muted uppercase tracking-wider">
              {stat.label}
            </h3>
            <p className="text-sm font-medium text-text-muted opacity-80">
              {stat.value}
            </p>
          </div>
        ))}
      </div>

      {/* Main Content Area */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left Panel: Switch Fleet */}
        <div className="lg:col-span-2 space-y-4">
          <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6 min-h-[500px] flex flex-col">
            <div className="flex items-center gap-2 mb-6">
              <Server className="w-5 h-5 text-accent-primary" />
              <h2 className="text-lg font-bold text-foreground">
                Switch Fleet
              </h2>
            </div>

            {/* Legend */}
            <div className="flex flex-wrap items-center gap-4 text-xs font-medium mb-6">
              <span className="text-text-muted mr-2">Port colours:</span>
              <div className="flex items-center gap-1.5">
                <Circle className="w-2.5 h-2.5 fill-green-500 text-green-500" />{" "}
                <span className="text-foreground">connected</span>
              </div>
              <div className="flex items-center gap-1.5">
                <Circle className="w-2.5 h-2.5 fill-purple-500 text-purple-500" />{" "}
                <span className="text-foreground">trunk</span>
              </div>
              <div className="flex items-center gap-1.5">
                <Circle className="w-2.5 h-2.5 fill-yellow-500 text-yellow-500" />{" "}
                <span className="text-foreground">LAG</span>
              </div>
              <div className="flex items-center gap-1.5">
                <Circle className="w-2.5 h-2.5 fill-red-500 text-red-500" />{" "}
                <span className="text-foreground">no link</span>
              </div>
              <div className="flex items-center gap-1.5">
                <Circle className="w-2.5 h-2.5 fill-slate-500 text-slate-500" />{" "}
                <span className="text-foreground">shutdown</span>
              </div>
            </div>

            {/* Filter Bar */}
            <div className="flex flex-col sm:flex-row gap-3 mb-10">
              <div className="relative flex-1">
                <Filter className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted" />
                <input
                  type="text"
                  placeholder="Filter by name, IP, model..."
                  className="w-full pl-9 pr-4 py-2 bg-bg-card border border-border-subtle rounded-2xl text-sm text-foreground focus:outline-none focus:border-accent-primary"
                />
              </div>
              <div className="flex gap-2">
                <CustomSelect
                  value={statusFilter}
                  onChange={setStatusFilter}
                  options={[
                    { value: "all_status", label: "All status" },
                    { value: "online", label: "Online" },
                    { value: "offline", label: "Offline" },
                  ]}
                  className="w-36"
                />
                <CustomSelect
                  value={groupFilter}
                  onChange={setGroupFilter}
                  options={[{ value: "all_groups", label: "All groups" }]}
                  className="w-36"
                />
              </div>
            </div>

            {/* Empty State */}
            <div className="flex-1 flex flex-col items-center justify-center text-center">
              <Server className="w-10 h-10 text-text-muted mb-4 opacity-50" />
              <p className="text-text-muted font-medium mb-1">
                No switches configured
              </p>
              <p className="text-text-muted text-sm">
                Click <strong className="text-foreground">Add Switch</strong> to
                get started
              </p>
            </div>
          </div>
        </div>

        {/* Right Panel: Sidebars */}
        <div className="space-y-6">
          {/* SSH Key Setup */}
          <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6">
            <h2 className="text-lg font-bold text-foreground mb-4">
              SSH Key Setup (One-time)
            </h2>

            <div className="space-y-5">
              <div>
                <div className="flex items-start gap-2 mb-2">
                  <div className="bg-accent-primary/20 text-accent-primary rounded-full w-5 h-5 flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">
                    1
                  </div>
                  <p className="text-sm text-foreground">
                    Generate SSH key on Janusserver:
                  </p>
                </div>
                <div className="bg-bg-card border border-border-subtle rounded-2xl p-3 ml-7 overflow-x-auto">
                  <code className="text-green-400 text-xs font-mono">
                    ssh-keygen -t ed25519 -f /etc/janus/.ssh/nac_key -N ""
                  </code>
                </div>
              </div>

              <div>
                <div className="flex items-start gap-2 mb-2">
                  <div className="bg-accent-primary/20 text-accent-primary rounded-full w-5 h-5 flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">
                    2
                  </div>
                  <p className="text-sm text-foreground">
                    Copy pubkey to each Juniper switch:
                  </p>
                </div>
                <div className="bg-bg-card border border-border-subtle rounded-2xl p-3 ml-7 mb-2 overflow-x-auto">
                  <code className="text-green-400 text-xs font-mono">
                    cat /etc/janus/.ssh/nac_key.pub
                  </code>
                </div>
                <p className="text-xs text-text-muted ml-7 mb-1">
                  Then on switch: set system login user janus-nac authentication
                  ssh-ed25519 "AAAA..."
                </p>
              </div>

              <div>
                <div className="flex items-start gap-2 mb-2">
                  <div className="bg-accent-primary/20 text-accent-primary rounded-full w-5 h-5 flex items-center justify-center text-xs font-bold shrink-0 mt-0.5">
                    3
                  </div>
                  <p className="text-sm text-foreground">
                    Or use password auth (stored securely outside webroot)
                  </p>
                </div>
                <p className="text-xs text-text-muted ml-7">
                  Credentials stored in
                </p>
                <p className="text-xs text-green-400 font-mono ml-7">
                  /etc/janus/switch_credentials.php
                </p>
              </div>
            </div>
          </div>

          {/* Live Alarms */}
          <div className="bg-bg-raised border border-border-subtle rounded-2xl p-6 min-h-[200px] flex flex-col">
            <div className="flex items-center justify-between mb-6">
              <h2 className="text-lg font-bold text-foreground">Live Alarms</h2>
              <span className="text-xs text-text-muted">0 unacked</span>
            </div>

            <div className="flex-1 flex items-center justify-center">
              <p className="text-sm text-text-muted">No active alarms</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
