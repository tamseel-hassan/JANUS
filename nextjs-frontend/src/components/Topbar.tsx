"use client";

import { useState, useEffect, useRef } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useAuthStore } from "@/store/authStore";
import {
  Search,
  Activity,
  ArrowUp,
  ArrowDown,
  Bell,
  User,
  ChevronDown,
  Moon,
  Sun,
  FileText,
  ShieldAlert,
  History,
  Ticket,
  Shield,
  Bug,
  Globe,
  BookOpen,
  Zap,
  LogOut,
} from "lucide-react";

interface NavData {
  stats: {
    totalDevices: number;
    upDevices: number;
    downDevices: number;
    uptimePct: number;
  };
  notifications: number;
  user: {
    username: string;
  };
}

interface DropdownItem {
  label: string;
  href: string;
  icon: React.ReactNode;
  badge?: number;
}

function NavDropdown({
  label,
  items,
}: {
  label: string;
  items: DropdownItem[];
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClick);
    return () => document.removeEventListener("mousedown", handleClick);
  }, []);

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen(!open)}
        className="flex items-center text-sm font-medium text-text-muted hover:text-foreground transition-colors gap-1"
      >
        {label}
        <ChevronDown
          className={`w-3.5 h-3.5 opacity-70 transition-transform duration-200 ${open ? "rotate-180" : ""}`}
        />
      </button>
      {open && (
        <div className="absolute top-full left-0 mt-2 min-w-[200px] bg-bg-card border border-border-subtle rounded-2xl py-1 z-50 shadow-lg">
          {items.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              onClick={() => setOpen(false)}
              className="flex items-center gap-3 px-4 py-2.5 text-sm text-text-muted hover:text-foreground hover:bg-bg-darker transition-colors"
            >
              <span className="text-accent-primary opacity-80">
                {item.icon}
              </span>
              <span className="flex-1">{item.label}</span>
              {item.badge !== undefined && item.badge > 0 && (
                <span className="bg-red-500 text-foreground text-xs px-1.5 py-0.5 rounded-full">
                  {item.badge}
                </span>
              )}
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}

export default function Topbar({ data }: { data: NavData | null }) {
  const router = useRouter();
  const { logout } = useAuthStore();
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const [theme, setTheme] = useState<"dark" | "light">("dark");
  const notifRef = useRef<HTMLDivElement>(null);
  const profileRef = useRef<HTMLDivElement>(null);

  // Initialize theme from localStorage
  useEffect(() => {
    const savedTheme = localStorage.getItem("theme") as "dark" | "light" | null;
    if (savedTheme === "light" || savedTheme === "dark") {
      setTheme(savedTheme);
      if (savedTheme === "dark") {
        document.documentElement.classList.add("dark");
      } else {
        document.documentElement.classList.remove("dark");
      }
    } else {
      document.documentElement.classList.add("dark");
      localStorage.setItem("theme", "dark");
    }
  }, []);

  const toggleTheme = () => {
    const nextTheme = theme === "dark" ? "light" : "dark";
    setTheme(nextTheme);
    if (nextTheme === "dark") {
      document.documentElement.classList.add("dark");
    } else {
      document.documentElement.classList.remove("dark");
    }
    localStorage.setItem("theme", nextTheme);
  };

  // Close notifications when clicking outside
  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (notifRef.current && !notifRef.current.contains(e.target as Node)) {
        setNotificationsOpen(false);
      }
      if (
        profileRef.current &&
        !profileRef.current.contains(e.target as Node)
      ) {
        setProfileOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClick);
    return () => document.removeEventListener("mousedown", handleClick);
  }, []);

  const incidentItems: DropdownItem[] = [
    {
      label: "Report Incident",
      href: "/report_incident",
      icon: <FileText className="w-4 h-4" />,
    },
    {
      label: "My Tasks",
      href: "/my_tasks",
      icon: <ShieldAlert className="w-4 h-4" />,
      badge: data?.notifications,
    },
    {
      label: "Active Incidents",
      href: "/active_incidents",
      icon: <Zap className="w-4 h-4" />,
    },
    {
      label: "Incidents History",
      href: "/incidents_history",
      icon: <History className="w-4 h-4" />,
    },
    {
      label: "Manage Tickets",
      href: "/manage_tickets",
      icon: <Ticket className="w-4 h-4" />,
    },
  ];

  const threatItems: DropdownItem[] = [
    {
      label: "Vulnerability Scanner",
      href: "/vuln_scan",
      icon: <Shield className="w-4 h-4" />,
    },
    {
      label: "Malware Analysis",
      href: "/malware_analysis",
      icon: <Bug className="w-4 h-4" />,
    },
    {
      label: "Check IP Reputation",
      href: "/threat_intel",
      icon: <Globe className="w-4 h-4" />,
    },
  ];

  const remediationItems: DropdownItem[] = [
    {
      label: "Playbooks",
      href: "/responder",
      icon: <BookOpen className="w-4 h-4" />,
    },
    {
      label: "Response Automation",
      href: "/responder",
      icon: <Zap className="w-4 h-4" />,
    },
  ];

  return (
    <nav className="h-16 bg-bg-card border-b border-border-subtle flex items-center justify-between px-6 sticky top-0 z-40">
      {/* Left section: Search and Nav */}
      <div className="flex items-center space-x-6">
        <form
          action="/search"
          method="GET"
          className="relative hidden md:block"
        >
          <button
            type="submit"
            className="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted"
          >
            <Search className="w-4 h-4" />
          </button>
          <input
            type="text"
            name="q"
            placeholder="Search..."
            className="bg-bg-darker border border-border-subtle rounded-full py-1.5 pl-10 pr-4 text-sm text-foreground placeholder:text-text-muted focus:outline-none focus:border-accent-primary transition-colors w-48 md:w-56"
          />
        </form>

        <div className="hidden lg:flex items-center space-x-5">
          <NavDropdown label="Incidents" items={incidentItems} />
          <NavDropdown label="Threat Detection" items={threatItems} />
          <NavDropdown
            label="Remediation & Automation"
            items={remediationItems}
          />
        </div>
      </div>

      {/* Right section: Stats & Profile */}
      <div className="flex items-center space-x-4">
        {/* Stats */}
        <div className="hidden md:flex items-center space-x-4 pr-4 border-r border-border-subtle">
          <div
            className="flex items-center text-sm gap-1"
            title="Network Uptime"
          >
            <Activity className="w-4 h-4 text-[#3b82f6]" />
            <span className="text-foreground font-medium">
              {data?.stats?.uptimePct ?? 0}%
            </span>
          </div>
          <div className="flex items-center text-sm gap-1" title="Up Devices">
            <ArrowUp className="w-4 h-4 text-[#10b981]" />
            <span className="text-foreground font-medium">
              {data?.stats?.upDevices ?? 0}
            </span>
          </div>
          <div className="flex items-center text-sm gap-1" title="Down Devices">
            <ArrowDown className="w-4 h-4 text-[#ef4444]" />
            <span className="text-foreground font-medium">
              {data?.stats?.downDevices ?? 0}
            </span>
          </div>
        </div>

        {/* Notifications */}
        <div className="relative" ref={notifRef}>
          <button
            onClick={() => setNotificationsOpen(!notificationsOpen)}
            className="relative flex items-center justify-center gap-1 h-8 px-3 rounded-2xl bg-bg-darker border border-border-subtle text-text-muted hover:text-foreground transition-colors"
          >
            <Bell className="w-4 h-4" />
            <ChevronDown
              className={`w-3 h-3 opacity-70 transition-transform duration-200 ${notificationsOpen ? "rotate-180" : ""}`}
            />
            {data && data.notifications > 0 && (
              <span className="absolute -top-1 -right-1 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-bg-card"></span>
            )}
          </button>
          {notificationsOpen && (
            <div className="absolute right-0 top-full mt-2 w-64 bg-bg-card border border-border-subtle rounded-2xl py-1 z-50 shadow-lg text-left">
              <div className="px-4 py-2.5 text-xs font-semibold text-text-muted border-b border-border-subtle">
                Notifications
              </div>
              {data && data.notifications > 0 ? (
                <div className="py-1">
                  <Link
                    href="/my_tasks"
                    onClick={() => setNotificationsOpen(false)}
                    className="flex items-start gap-3 px-4 py-3 hover:bg-bg-darker transition-colors"
                  >
                    <ShieldAlert className="w-4 h-4 text-yellow-500 mt-0.5 shrink-0" />
                    <div className="flex flex-col">
                      <span className="text-sm font-medium text-foreground">
                        Tasks Assigned
                      </span>
                      <span className="text-xs text-text-muted">
                        You have {data.notifications} task
                        {data.notifications > 1 ? "s" : ""} assigned to you.
                      </span>
                    </div>
                  </Link>
                  <div className="my-1 border-t border-border-subtle" />
                  <Link
                    href="/my_tasks"
                    onClick={() => setNotificationsOpen(false)}
                    className="block text-center py-2 text-xs text-accent-primary hover:text-foreground font-medium transition-colors"
                  >
                    View all ({data.notifications})
                  </Link>
                </div>
              ) : (
                <div className="px-4 py-5 text-center text-sm text-text-muted">
                  No new notifications
                </div>
              )}
            </div>
          )}
        </div>

        {/* Theme Toggle */}
        <button
          onClick={toggleTheme}
          className="relative flex items-center w-12 h-6 rounded-full bg-bg-darker border border-border-subtle transition-colors"
          title={`Switch to ${theme === "dark" ? "light" : "dark"} mode`}
        >
          <div
            className={`w-5 h-5 rounded-full flex items-center justify-center absolute top-1/2 -translate-y-1/2 left-[2px] transition-transform duration-300 ${
              theme === "dark" ? "translate-x-0" : "translate-x-6"
            }`}
          >
            {theme === "dark" ? (
              <Moon className="w-3 h-3 text-yellow-400" />
            ) : (
              <Sun className="w-3 h-3 text-yellow-500" />
            )}
          </div>
        </button>

        {/* User Profile Dropdown */}
        <div className="relative" ref={profileRef}>
          <button
            onClick={() => setProfileOpen(!profileOpen)}
            className="flex items-center space-x-2 pl-4 hover:opacity-80 transition-opacity"
          >
            <div className="w-8 h-8 rounded-2xl border border-border-subtle flex items-center justify-center text-accent-primary bg-bg-darker">
              <User className="w-4 h-4" />
            </div>
            <span className="text-sm font-medium text-text-muted hidden sm:block">
              {data?.user?.username || "admin"}
            </span>
            <ChevronDown
              className={`w-3.5 h-3.5 text-text-muted opacity-70 transition-transform duration-200 ${profileOpen ? "rotate-180" : ""}`}
            />
          </button>
          {profileOpen && (
            <div className="absolute right-0 top-full mt-2 w-44 bg-bg-card border border-border-subtle rounded-2xl py-1 z-50 shadow-lg">
              <Link
                href="/profile"
                onClick={() => setProfileOpen(false)}
                className="flex items-center gap-3 px-4 py-2.5 text-sm text-text-muted hover:text-foreground hover:bg-bg-darker transition-colors"
              >
                <User className="w-4 h-4 text-accent-primary opacity-80" />
                Profile
              </Link>
              <div className="my-1 border-t border-border-subtle" />
              <button
                onClick={async () => {
                  setProfileOpen(false);
                  await logout();
                  router.replace("/login");
                }}
                className="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-red-400 hover:text-red-300 hover:bg-bg-darker transition-colors"
              >
                <LogOut className="w-4 h-4" />
                Logout
              </button>
            </div>
          )}
        </div>
      </div>
    </nav>
  );
}
