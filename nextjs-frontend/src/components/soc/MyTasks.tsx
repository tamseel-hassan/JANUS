"use client";

import { PageHeader } from "@/components/ui/PageHeader";
import IncidentTable from "@/components/soc/IncidentTable";
import { ShieldAlert } from "lucide-react";

export default function MyTasks() {
  return (
    <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
      <PageHeader 
        title={
          <span className="flex items-center gap-3">
            <ShieldAlert className="w-6 h-6 text-accent-primary" />
            My Assigned Tasks
          </span>
        }
        subtitle="Manage and respond to security incidents assigned to you."
      />

      <IncidentTable 
        type="my_tasks" 
        title="My Active Tickets" 
        icon={<ShieldAlert className="w-5 h-5 text-accent-primary" />} 
      />
    </div>
  );
}
