interface SectionCardProps {
  children: React.ReactNode;
  /** Optional header content rendered in a top bar with border-bottom */
  header?: React.ReactNode;
  className?: string;
  /** Removes overflow-hidden, useful when children need to overflow */
  noOverflow?: boolean;
}

/**
 * Standard card container matching the design system:
 * bg-bg-raised + border-border-subtle + rounded-2xl
 */
export function SectionCard({ children, header, className = "", noOverflow = false }: SectionCardProps) {
  return (
    <div
      className={`bg-bg-raised border border-border-subtle rounded-2xl ${noOverflow ? "" : "overflow-hidden"} ${className}`}
    >
      {header && (
        <div className="p-4 border-b border-border-subtle bg-bg-main flex items-center justify-between">
          {header}
        </div>
      )}
      {children}
    </div>
  );
}

export default SectionCard;
