"use client";

import { PageHeader } from "@/components/ui/PageHeader";
import IncidentTable from "@/components/soc/IncidentTable";
import { History } from "lucide-react";

export default function IncidentsHistory() {
  return (
    <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
      <PageHeader 
        title={
          <span className="flex items-center gap-3">
            <History className="w-6 h-6 text-accent-primary" />
            Incidents History
          </span>
        }
        subtitle="View previously resolved and closed security incidents."
      />

      <IncidentTable 
        type="history" 
        title="Closed Incidents" 
        icon={<History className="w-5 h-5 text-accent-primary" />} 
      />
    </div>
  );
}
