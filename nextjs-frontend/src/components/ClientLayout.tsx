"use client";

import { useState, useEffect } from "react";
import { useRouter, usePathname } from "next/navigation";
import Sidebar from "./Sidebar";
import Topbar from "./Topbar";
import { useAuthStore } from "@/store/authStore";
import { Loader2 } from "lucide-react";

import { monitorService } from "@/services/monitor/monitorService";

const PUBLIC_ROUTES = ["/login"];

export default function ClientLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const { isAuthenticated, isLoading, checkSession } = useAuthStore();
  const [navData, setNavData] = useState(null);
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false);

  // Load collapsed sidebar state from localStorage on mount
  useEffect(() => {
    const savedCollapsed = localStorage.getItem("sidebar_collapsed");
    if (savedCollapsed === "true") {
      setIsSidebarCollapsed(true);
    }
  }, []);

  const isPublicRoute = PUBLIC_ROUTES.includes(pathname);

  // On mount, validate the session with the PHP backend
  useEffect(() => {
    if (!isPublicRoute) {
      checkSession().then((authenticated) => {
        if (!authenticated) {
          router.replace("/login");
        }
      });
    }
  }, [pathname]);

  // Fetch nav data once authenticated
  useEffect(() => {
    if (isAuthenticated && !isPublicRoute) {
      monitorService.getNavData()
        .then((data) => setNavData(data as any))
        .catch((err) => console.error("Failed to fetch nav data", err));
    }
  }, [isAuthenticated, pathname]);

  // Public routes (login page) — render without sidebar/topbar
  if (isPublicRoute) {
    return <>{children}</>;
  }

  // Loading state — verifying session
  if (isLoading) {
    return (
      <div className="min-h-screen bg-bg-main flex items-center justify-center">
        <div className="flex flex-col items-center gap-3">
          <Loader2 className="w-8 h-8 animate-spin text-accent-primary" />
          <p className="text-text-muted text-sm">Verifying session...</p>
        </div>
      </div>
    );
  }

  // Not authenticated — render nothing while redirecting
  if (!isAuthenticated) {
    return null;
  }

  // Authenticated — render full layout
  return (
    <div className="flex h-screen bg-bg-main text-foreground overflow-hidden">
      <Sidebar
        isCollapsed={isSidebarCollapsed}
        toggleSidebar={() => {
          const next = !isSidebarCollapsed;
          setIsSidebarCollapsed(next);
          localStorage.setItem("sidebar_collapsed", next ? "true" : "false");
        }}
      />
      <div className="flex-1 flex flex-col min-w-0 h-screen overflow-hidden">
        <Topbar data={navData} />
        <main className="flex-1 overflow-y-auto p-6 bg-bg-main">
          {/* key=pathname forces React to remount on route change → triggers page-enter animation */}
          <div key={pathname} className="page-enter">
            {children}
          </div>
        </main>
      </div>
    </div>
  );
}
