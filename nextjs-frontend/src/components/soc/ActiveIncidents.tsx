"use client";

import { PageHeader } from "@/components/ui/PageHeader";
import IncidentTable from "@/components/soc/IncidentTable";
import { Zap } from "lucide-react";

export default function ActiveIncidents() {
  return (
    <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
      <PageHeader 
        title={
          <span className="flex items-center gap-3">
            <Zap className="w-6 h-6 text-accent-primary" />
            Active Incidents
          </span>
        }
        subtitle="View all open and in-progress security incidents."
      />

      <IncidentTable 
        type="active" 
        title="Open Incidents" 
        icon={<Zap className="w-5 h-5 text-accent-primary" />} 
      />
    </div>
  );
}
