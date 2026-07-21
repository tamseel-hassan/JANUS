"use client";

import React, { useEffect, useRef, useState } from "react";

export interface TabItem {
  id: string;
  label: string;
  icon?: React.ReactNode;
}

interface TabsProps {
  tabs: TabItem[];
  activeTab: string;
  onChange: (id: string) => void;
  className?: string;
}

export function Tabs({ tabs, activeTab, onChange, className = "" }: TabsProps) {
  const [indicatorStyle, setIndicatorStyle] = useState({ left: 0, width: 0, opacity: 0 });
  const tabsRef = useRef<{ [key: string]: HTMLButtonElement | null }>({});

  useEffect(() => {
    const updateIndicator = () => {
      const activeElement = tabsRef.current[activeTab];
      if (activeElement) {
        setIndicatorStyle({
          left: activeElement.offsetLeft,
          width: activeElement.offsetWidth,
          opacity: 1,
        });
      }
    };

    updateIndicator();
    // Re-run after a slight delay to catch font loads or layout shifts
    const timeoutId = setTimeout(updateIndicator, 50);

    window.addEventListener("resize", updateIndicator);
    return () => {
      clearTimeout(timeoutId);
      window.removeEventListener("resize", updateIndicator);
    };
  }, [activeTab, tabs]);

  return (
    <div className={`relative flex items-center gap-2 overflow-x-auto [&::-webkit-scrollbar]:hidden [-ms-overflow-style:none] [scrollbar-width:none] ${className}`}>
      {/* Animated Background Indicator */}
      <div
        className="absolute top-0 bottom-0 border border-accent-primary rounded-full shadow-[0_0_10px_rgba(167,139,250,0.1)] transition-all duration-300 ease-out z-0 pointer-events-none"
        style={indicatorStyle}
      />
      {tabs.map((tab) => {
        const isActive = activeTab === tab.id;
        return (
          <button
            key={tab.id}
            ref={(el) => {
              tabsRef.current[tab.id] = el;
            }}
            onClick={() => onChange(tab.id)}
            className={`
              relative z-10 flex shrink-0 items-center justify-center px-6 py-2 text-sm font-bold tracking-wider uppercase transition-colors duration-200 whitespace-nowrap
              ${isActive
                ? "text-accent-primary"
                : "text-text-muted hover:text-foreground rounded-full hover:bg-white/5"}
            `}
          >
            {tab.icon && <span className="mr-2">{tab.icon}</span>}
            {tab.label}
          </button>
        );
      })}
    </div>
  );
}

export default Tabs;
