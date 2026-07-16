type RiskLevel = "critical" | "high" | "medium" | "low" | string;

interface RiskBadgeProps {
  level: RiskLevel;
  className?: string;
}

const riskStyles: Record<string, string> = {
  critical: "bg-red-900/50 text-red-400",
  high: "bg-orange-900/50 text-orange-400",
  medium: "bg-yellow-900/50 text-yellow-400",
  low: "bg-green-900/50 text-green-400",
};

export function RiskBadge({ level, className = "" }: RiskBadgeProps) {
  const style = riskStyles[level?.toLowerCase()] ?? "bg-gray-800 text-gray-300";
  return (
    <span
      className={`inline-block px-2 py-1 rounded-2xl text-xs font-bold uppercase ${style} ${className}`}
    >
      {level}
    </span>
  );
}

export default RiskBadge;
