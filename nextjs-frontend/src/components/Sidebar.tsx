"use client";

import Link from "next/link";
import Image from "next/image";
import { useState, useEffect } from "react";
import { useRouter, usePathname } from "next/navigation";
import { useAuthStore } from "@/store/authStore";
import {
  LayoutDashboard,
  Server,
  Monitor,
  Map,
  BarChart2,
  FileBarChart,
  TrendingUp,
  ShieldCheck,
  Radio,
  Bug,
  Wrench,
  Globe,
  Layers,
  HardDrive,
  ClipboardCheck,
  FileText,
  Users,
  ChevronDown,
  Menu,
  Power,
  User,
} from "lucide-react";

interface NavGroup {
  label: string;
  icon: React.ReactNode;
  children: { label: string; href: string; icon: React.ReactNode }[];
}

interface NavItem {
  label: string;
  href: string;
  icon: React.ReactNode;
}

type NavEntry = ({ type: "item" } & NavItem) | ({ type: "group" } & NavGroup);

const NAV: NavEntry[] = [
  {
    type: "item",
    label: "Dashboard",
    href: "/home",
    icon: <LayoutDashboard className="w-5 h-5" />,
  },
  {
    type: "item",
    label: "Inventory",
    href: "/manage_devices",
    icon: <Server className="w-5 h-5" />,
  },
  {
    type: "item",
    label: "Monitoring",
    href: "/monitor",
    icon: <Monitor className="w-5 h-5" />,
  },
  {
    type: "item",
    label: "Topology Map",
    href: "/maps",
    icon: <Map className="w-5 h-5" />,
  },
  {
    type: "item",
    label: "Performance Metrics",
    href: "/resources",
    icon: <BarChart2 className="w-5 h-5" />,
  },
  {
    type: "group",
    label: "Reports & Analytics",
    icon: <FileBarChart className="w-5 h-5" />,
    children: [
      {
        label: "Reports & Analytics",
        href: "/reports",
        icon: <FileBarChart className="w-4 h-4" />,
      },
      {
        label: "Availability Reports",
        href: "/availability_reports",
        icon: <TrendingUp className="w-4 h-4" />,
      },
    ],
  },
  {
    type: "group",
    label: "Traffic & Security",
    icon: <ShieldCheck className="w-5 h-5" />,
    children: [
      {
        label: "Traffic Analyzer",
        href: "/fflow",
        icon: <Radio className="w-4 h-4" />,
      },
      {
        label: "IPS Logs",
        href: "/ips_logs",
        icon: <Bug className="w-4 h-4" />,
      },
      {
        label: "Vulnerability Scanner",
        href: "/vuln_scan",
        icon: <Bug className="w-4 h-4" />,
      },
      {
        label: "Remote Access",
        href: "/remote_access",
        icon: <Radio className="w-4 h-4" />,
      },
    ],
  },
  {
    type: "group",
    label: "NOC Tools",
    icon: <Wrench className="w-5 h-5" />,
    children: [
      {
        label: "IP Address Management",
        href: "/ipam",
        icon: <Globe className="w-4 h-4" />,
      },
      {
        label: "Layer 2 Monitoring",
        href: "/nac",
        icon: <Layers className="w-4 h-4" />,
      },
    ],
  },
  {
    type: "item",
    label: "Appliance Configs",
    href: "/config_backup",
    icon: <HardDrive className="w-5 h-5" />,
  },
  {
    type: "item",
    label: "Policy & Compliance",
    href: "/compliance",
    icon: <ClipboardCheck className="w-5 h-5" />,
  },
  {
    type: "item",
    label: "Log Management",
    href: "/siem_logs",
    icon: <FileText className="w-5 h-5" />,
  },
  {
    type: "item",
    label: "User Management",
    href: "/users",
    icon: <Users className="w-5 h-5" />,
  },
];

export default function Sidebar({
  isCollapsed,
  toggleSidebar,
}: {
  isCollapsed: boolean;
  toggleSidebar: () => void;
}) {
  const pathname = usePathname();
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({});
  const [isDark, setIsDark] = useState(true);

  // Observe theme class changes on <html>
  useEffect(() => {
    const update = () =>
      setIsDark(document.documentElement.classList.contains("dark"));
    update();
    const observer = new MutationObserver(update);
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ["class"],
    });
    return () => observer.disconnect();
  }, []);

  const toggleGroup = (label: string) => {
    setOpenGroups((prev) => ({ ...prev, [label]: !prev[label] }));
  };

  const logoSrc = isDark
    ? "/icons/janus-icon-dark.svg"
    : "/icons/janus-icon-light.svg";

  return (
    <div
      className={`relative h-screen bg-bg-darker border-r border-border-subtle transition-all duration-300 z-50 flex flex-col shrink-0 ${
        isCollapsed ? "w-[72px]" : "w-[260px]"
      }`}
    >
      {/* Header */}
      {isCollapsed ? (
        /* Collapsed: icon centered, toggle appears on hover */
        <div className="relative flex items-center justify-center h-16 px-2 shrink-0 group/header">
          <Image
            src={logoSrc}
            alt="JANUS"
            width={32}
            height={24}
            className="transition-opacity duration-200 group-hover/header:opacity-0"
            priority
          />
          <button
            onClick={toggleSidebar}
            className="absolute inset-0 flex items-center justify-center opacity-0 group-hover/header:opacity-100 transition-opacity duration-200 text-text-muted hover:text-foreground"
            title="Expand Sidebar"
          >
            <Menu className="w-5 h-5" />
          </button>
        </div>
      ) : (
        /* Expanded: logo + name on left, toggle on right */
        <div className="flex items-center justify-between h-16 px-4 shrink-0">
          <div className="flex items-center gap-3">
            <Image
              src={logoSrc}
              alt="JANUS"
              width={36}
              height={27}
              className="shrink-0"
              priority
            />
            <span className="text-foreground font-bold text-base tracking-wider uppercase">
              JANUS
            </span>
          </div>
          <button
            onClick={toggleSidebar}
            className="p-1.5 text-text-muted hover:text-foreground rounded-2xl hover:bg-bg-card transition-colors shrink-0"
            title="Collapse Sidebar"
          >
            <Menu className="w-5 h-5" />
          </button>
        </div>
      )}

      {/* Navigation */}
      <nav className="flex-1 overflow-y-auto overflow-x-hidden hide-scrollbar py-2">
        <ul className="space-y-0.5 px-2">
          {NAV.map((entry) => {
            if (entry.type === "item") {
              const isActive =
                pathname === entry.href ||
                pathname.startsWith(entry.href + "/");
              return (
                <li key={entry.href}>
                  <Link
                    href={entry.href}
                    className={`flex items-center py-2.5 rounded-2xl text-sm font-medium transition-all duration-300 group ${
                      isCollapsed ? "px-4.5" : "px-3"
                    } ${
                      isActive
                        ? "bg-bg-card text-foreground"
                        : "text-text-muted hover:text-foreground hover:bg-bg-card/60"
                    }`}
                    title={isCollapsed ? entry.label : undefined}
                  >
                    <span
                      className={`shrink-0 transition-colors ${isActive ? "text-accent-primary" : "group-hover:text-accent-primary"}`}
                    >
                      {entry.icon}
                    </span>
                    <span
                      className={`whitespace-nowrap overflow-hidden transition-all duration-300 ${
                        isCollapsed
                          ? "w-0 opacity-0 ml-0 min-w-0"
                          : "w-[160px] opacity-100 ml-3"
                      }`}
                    >
                      {entry.label}
                    </span>
                  </Link>
                </li>
              );
            }

            // Group
            const isAnyChildActive = entry.children.some(
              (c) => pathname === c.href || pathname.startsWith(c.href + "/"),
            );
            const isOpen = openGroups[entry.label] ?? isAnyChildActive;

            return (
              <li key={entry.label}>
                <button
                  onClick={() => toggleGroup(entry.label)}
                  className={`w-full flex items-center py-2.5 rounded-2xl text-sm font-medium transition-all duration-300 group ${
                    isCollapsed ? "px-4.5" : "px-3"
                  } ${
                    isAnyChildActive
                      ? "bg-bg-card text-foreground"
                      : "text-text-muted hover:text-foreground hover:bg-bg-card/60"
                  }`}
                  title={isCollapsed ? entry.label : undefined}
                >
                  <span
                    className={`shrink-0 transition-colors ${isAnyChildActive ? "text-accent-primary" : "group-hover:text-accent-primary"}`}
                  >
                    {entry.icon}
                  </span>
                  <div
                    className={`flex items-center justify-between whitespace-nowrap overflow-hidden transition-all duration-300 ${
                      isCollapsed
                        ? "w-0 opacity-0 ml-0 min-w-0"
                        : "w-[160px] opacity-100 ml-3"
                    }`}
                  >
                    <span className="truncate">{entry.label}</span>
                    <ChevronDown
                      className={`w-4 h-4 opacity-60 transition-transform duration-200 shrink-0 ${
                        isOpen ? "rotate-180" : ""
                      }`}
                    />
                  </div>
                </button>

                {/* Submenu */}
                {!isCollapsed && isOpen && (
                  <ul className="mt-0.5 mb-1 space-y-0.5">
                    {entry.children.map((child) => {
                      const childActive = pathname === child.href;
                      return (
                        <li key={child.href}>
                          <Link
                            href={child.href}
                            className={`flex items-center gap-3 pl-10 pr-3 py-2 rounded-2xl text-sm transition-colors group ${
                              childActive
                                ? "text-foreground bg-bg-card/50"
                                : "text-text-muted hover:text-foreground hover:bg-bg-card/30"
                            }`}
                          >
                            <span
                              className={`w-1.5 h-1.5 rounded-full shrink-0 transition-colors ${childActive ? "bg-accent-primary" : "bg-border-subtle group-hover:bg-text-muted"}`}
                            />
                            <span className="truncate">{child.label}</span>
                          </Link>
                        </li>
                      );
                    })}
                  </ul>
                )}
              </li>
            );
          })}
        </ul>
      </nav>

      {/* Bottom: User + Logout */}
      <SidebarFooter isCollapsed={isCollapsed} />
    </div>
  );
}

function SidebarFooter({ isCollapsed }: { isCollapsed: boolean }) {
  const router = useRouter();
  const { user, logout } = useAuthStore();

  const handleLogout = async () => {
    await logout();
    router.replace("/login");
  };

  return (
    <div className="border-t border-border-subtle px-3 py-3 shrink-0">
      {isCollapsed ? (
        /* Collapsed: just the power button centered */
        <div className="flex justify-center transition-all duration-300">
          <button
            onClick={handleLogout}
            className="p-2 text-text-muted hover:text-red-400 hover:bg-bg-card rounded-2xl transition-colors"
            title="Logout"
          >
            <Power className="w-5 h-5" />
          </button>
        </div>
      ) : (
        /* Expanded: user info + power button */
        <div className="flex items-center justify-between gap-2 overflow-hidden transition-all duration-300 h-[48px]">
          <div className="flex items-center gap-2 min-w-0 whitespace-nowrap">
            <div className="w-10 h-10 rounded-2xl bg-bg-card border border-border-subtle flex items-center justify-center shrink-0 text-accent-primary">
              <User className="w-5 h-5" />
            </div>
            <div className="min-w-0">
              <p className="text-sm font-semibold text-foreground truncate leading-tight">
                {user?.username || "admin"}
              </p>
              <p className="text-xs text-text-muted capitalize truncate leading-tight">
                {user?.role || "Admin"}
              </p>
            </div>
          </div>
          <button
            onClick={handleLogout}
            className="p-2 text-text-muted hover:text-red-400 hover:bg-bg-card rounded-2xl transition-colors shrink-0"
            title="Logout"
          >
            <Power className="w-5 h-5" />
          </button>
        </div>
      )}
    </div>
  );
}
