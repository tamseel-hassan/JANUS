"use client";

import React from "react";

export interface CircularGaugeProps {
  value: number;
  size?: number;
  strokeWidth?: number;
  label?: string;
  color?: string;
  variant?: "cpu" | "mem" | "disk" | "snmp" | "custom";
  showValueText?: boolean;
  valueSuffix?: string;
  className?: string;
}

export function CircularGauge({
  value,
  size = 100,
  strokeWidth = 8,
  label,
  color,
  variant = "custom",
  showValueText = true,
  valueSuffix = "%",
  className = "",
}: CircularGaugeProps) {
  const safeValue = Math.min(100, Math.max(0, isNaN(value) ? 0 : value));
  const radius = (size - strokeWidth) / 2;
  const circumference = 2 * Math.PI * radius;
  const strokeDashoffset = circumference - (safeValue / 100) * circumference;

  // Determine threshold color if custom color not explicitly provided
  const getDynamicColor = () => {
    if (color) return color;

    switch (variant) {
      case "cpu":
        return safeValue > 80
          ? "#ef4444" // red
          : safeValue > 60
            ? "#eab308" // yellow
            : "#22c55e"; // green
      case "mem":
        return safeValue > 80
          ? "#ef4444"
          : safeValue > 60
            ? "#eab308"
            : "#3b82f6"; // blue
      case "disk":
        return safeValue > 80
          ? "#ef4444"
          : safeValue > 60
            ? "#eab308"
            : "#f97316"; // orange
      case "snmp":
        return safeValue >= 90
          ? "#22c55e"
          : safeValue >= 70
            ? "#eab308"
            : "#ef4444";
      default:
        return "var(--accent-primary, #c666f4)";
    }
  };

  const strokeColor = getDynamicColor();

  return (
    <div className={`flex flex-col items-center justify-center ${className}`}>
      <div
        className="relative flex items-center justify-center"
        style={{ width: size, height: size }}
      >
        <svg
          width={size}
          height={size}
          className="transform -rotate-90 overflow-visible"
        >
          {/* Background Track Ring */}
          <circle
            cx={size / 2}
            cy={size / 2}
            r={radius}
            stroke="var(--border-subtle)"
            strokeWidth={strokeWidth}
            fill="transparent"
            className="opacity-40 dark:opacity-30"
          />
          {/* Animated Value Ring */}
          <circle
            cx={size / 2}
            cy={size / 2}
            r={radius}
            stroke={strokeColor}
            strokeWidth={strokeWidth}
            strokeDasharray={circumference}
            strokeDashoffset={strokeDashoffset}
            strokeLinecap="round"
            fill="transparent"
            className="transition-all duration-700 ease-out"
          />
        </svg>

        {/* Center Value Text */}
        {showValueText && (
          <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
            <span
              className="font-bold text-foreground tracking-tight"
              style={{ fontSize: Math.max(12, size * 0.22) }}
            >
              {safeValue.toFixed(safeValue % 1 === 0 ? 0 : 1)}
              {valueSuffix}
            </span>
          </div>
        )}
      </div>

      {/* Optional Label */}
      {label && (
        <span className="mt-2 text-xs font-bold uppercase tracking-wider text-text-muted">
          {label}
        </span>
      )}
    </div>
  );
}

export default CircularGauge;
