interface PageHeaderProps {
  title: React.ReactNode;
  subtitle?: string;
  /** Optional actions/buttons rendered to the right */
  actions?: React.ReactNode;
}

export function PageHeader({ title, subtitle, actions }: PageHeaderProps) {
  return (
    <div className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
      <div>
        <h1 className="text-2xl font-bold text-foreground tracking-wide">{title}</h1>
        {subtitle && <p className="text-text-muted mt-1">{subtitle}</p>}
      </div>
      {actions && <div className="flex items-center gap-2 flex-wrap">{actions}</div>}
    </div>
  );
}

export default PageHeader;
