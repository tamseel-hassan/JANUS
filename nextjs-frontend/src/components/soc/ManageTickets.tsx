"use client";

import { PageHeader } from "@/components/ui/PageHeader";
import IncidentTable from "@/components/soc/IncidentTable";
import { Ticket } from "lucide-react";

export default function ManageTickets() {
  return (
    <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
      <PageHeader 
        title={
          <span className="flex items-center gap-3">
            <Ticket className="w-6 h-6 text-accent-primary" />
            Manage Tickets
          </span>
        }
        subtitle="Full administrative access to all security tickets."
      />

      <IncidentTable 
        type="manage" 
        title="All Tickets" 
        icon={<Ticket className="w-5 h-5 text-accent-primary" />}
        isAdmin={true}
      />
    </div>
  );
}
