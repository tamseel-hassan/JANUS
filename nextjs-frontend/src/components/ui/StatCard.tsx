interface StatCardProps {
  title: string;
  value: string | number;
  /** Icon node. When provided, renders in icon+text (left-aligned) layout */
  icon?: React.ReactNode;
  /** Tailwind text color class applied to the value, e.g. "text-green-400" */
  color?: string;
  /** Tailwind classes for the icon container background, e.g. "bg-red-900/30 border-red-500/50" */
  iconBgClass?: string;
  /**
   * Large decorative icon shown as a watermark in the bottom-right corner.
   * When set, renders the simple (no-icon) layout with an absolute watermark.
   */
  watermarkIcon?: React.ReactNode;
  /** Extra className on the root card element */
  className?: string;
}

/**
 * Unified stat card with three layout modes:
 *
 * 1. `watermarkIcon` — title/value left-aligned, large icon watermark bottom-right (Resources)
 * 2. `icon`          — icon box on left, value + label on right   (IPSLogs / TrafficAnalyzer)
 * 3. Plain           — centered column layout                      (Home / Monitor)
 */
export function StatCard({
  title,
  value,
  icon,
  color = "text-foreground",
  iconBgClass,
  watermarkIcon,
  className = "",
}: StatCardProps) {
  // — Watermark layout (Resources-style) —
  if (watermarkIcon) {
    return (
      <div
        className={`bg-bg-raised border border-border-subtle rounded-2xl p-6 relative overflow-hidden group ${className}`}
      >
        <div className="absolute -right-4 -bottom-4 opacity-10 transition-transform pointer-events-none select-none">
          {watermarkIcon}
        </div>
        <div className="text-text-muted text-sm font-bold uppercase tracking-wider mb-2 relative z-10">
          {title}
        </div>
        <div className={`text-2xl font-black relative z-10 ${color}`}>
          {value}
        </div>
      </div>
    );
  }

  // — Icon layout (IPSLogs / TrafficAnalyzer-style) —
  if (icon) {
    return (
      <div className={`bg-bg-raised border border-border-subtle rounded-2xl p-4 flex items-center ${className}`}>
        <div className={`p-3 rounded-2xl border mr-4 shrink-0 ${iconBgClass ?? "bg-bg-main border-border-subtle"}`}>
          {icon}
        </div>
        <div>
          <div className={`text-2xl font-bold ${color}`}>{value}</div>
          <div className="text-text-muted text-xs uppercase tracking-wider font-bold">{title}</div>
        </div>
      </div>
    );
  }

  // — Plain layout (Home / Monitor-style) —
  return (
    <div className={`bg-bg-raised border border-border-subtle rounded-2xl p-5 flex flex-col justify-center ${className}`}>
      <div className="text-sm font-medium text-text-muted uppercase tracking-wider mb-2">{title}</div>
      <div className={`text-2xl font-bold ${color}`}>{value}</div>
    </div>
  );
}

export default StatCard;
