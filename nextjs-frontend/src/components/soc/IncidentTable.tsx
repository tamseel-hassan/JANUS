"use client";

import { useEffect, useState } from "react";
import { LoadingSpinner } from "@/components/ui/LoadingSpinner";
import { SectionCard } from "@/components/ui/SectionCard";
import { SearchInput } from "@/components/ui/SearchInput";
import { Incident } from "@/types/soc";
import { AlertCircle, Clock, Eye, ShieldAlert, Archive } from "lucide-react";
import IncidentModal from "./IncidentModal";
import { socService } from "@/services/soc/socService";

interface IncidentTableProps {
  type: "my_tasks" | "active" | "history" | "manage";
  title: string;
  icon: React.ReactNode;
  isAdmin?: boolean;
}

export default function IncidentTable({ type, title, icon, isAdmin = false }: IncidentTableProps) {
  const [loading, setLoading] = useState(true);
  const [incidents, setIncidents] = useState<Incident[]>([]);
  const [search, setSearch] = useState("");
  const [selectedIncidentId, setSelectedIncidentId] = useState<number | null>(null);

  useEffect(() => {
    fetchIncidents();
  }, [type]);

  const fetchIncidents = async () => {
    setLoading(true);
    const data = await socService.getIncidents("", "", search);
    if (data && data.incidents) {
      setIncidents(data.incidents);
    }
    setLoading(false);
  };

  const filteredIncidents = incidents.filter(i => 
    i.title.toLowerCase().includes(search.toLowerCase()) || 
    (i.description || "").toLowerCase().includes(search.toLowerCase()) ||
    `INC-${i.id}`.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <SectionCard 
      header={
        <h3 className="font-semibold text-foreground flex items-center gap-2">
          {icon} {title}
        </h3>
      } 
      className="flex flex-col min-h-[500px]"
    >
      <div className="p-6 flex-1 flex flex-col">
        <div className="mb-4 max-w-md">
          <SearchInput
            value={search}
            onChange={setSearch}
            placeholder="Search by ID, title, or description..."
          />
        </div>

        <div className="overflow-x-auto rounded-xl border border-border-subtle bg-bg-card flex-1">
          <table className="w-full text-left text-sm whitespace-nowrap">
            <thead className="bg-bg-base/50 text-text-muted border-b border-border-subtle">
              <tr>
                <th className="p-4 text-xs font-semibold uppercase tracking-wider">ID</th>
                <th className="p-4 text-xs font-semibold uppercase tracking-wider">Title</th>
                <th className="p-4 text-xs font-semibold uppercase tracking-wider">Severity</th>
                <th className="p-4 text-xs font-semibold uppercase tracking-wider">Status</th>
                <th className="p-4 text-xs font-semibold uppercase tracking-wider">Assigned</th>
                <th className="p-4 text-xs font-semibold uppercase tracking-wider">Created At</th>
                <th className="p-4 text-xs font-semibold uppercase tracking-wider text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={7} className="p-8 text-center"><LoadingSpinner size="md" /></td></tr>
              ) : filteredIncidents.length === 0 ? (
                <tr>
                  <td colSpan={7} className="p-16">
                    <div className="flex flex-col items-center justify-center text-text-muted opacity-50">
                      <Archive className="w-8 h-8 mb-2" />
                      {type === 'manage' ? 'No tickets found.' : 'No incidents found.'}
                    </div>
                  </td>
                </tr>
              ) : (
                filteredIncidents.map(inc => (
                  <tr key={inc.id} className={`border-b border-border-subtle last:border-0 hover:bg-white/5 transition-colors cursor-pointer ${inc.unread_by_assignee === 1 && type === 'my_tasks' ? 'bg-accent-primary/5' : ''}`} onClick={() => setSelectedIncidentId(inc.id)}>
                    <td className="p-4 font-mono text-xs text-text-muted">
                      INC-{inc.id}
                      {inc.unread_by_assignee === 1 && type === 'my_tasks' && <span className="ml-2 w-2 h-2 rounded-full bg-accent-primary inline-block"></span>}
                    </td>
                    <td className="p-4 font-medium text-foreground max-w-[250px] truncate">{inc.title}</td>
                    <td className="p-4">
                      <span className={`px-2 py-0.5 rounded text-xs font-medium ${
                        inc.severity === 'critical' ? 'bg-red-500/20 text-red-400' :
                        inc.severity === 'high' ? 'bg-orange-500/20 text-orange-400' :
                        inc.severity === 'medium' ? 'bg-yellow-500/20 text-yellow-400' :
                        'bg-blue-500/20 text-blue-400'
                      }`}>
                        {inc.severity}
                      </span>
                    </td>
                    <td className="p-4">
                      <span className={`px-2 py-0.5 rounded text-xs font-medium ${
                        inc.status === 'open' ? 'bg-emerald-500/20 text-emerald-400' :
                        inc.status === 'in_progress' ? 'bg-blue-500/20 text-blue-400' :
                        'bg-slate-500/20 text-slate-400'
                      }`}>
                        {inc.status.replace('_', ' ')}
                      </span>
                    </td>
                    <td className="p-4 text-text-muted">{inc.assignee_name || <span className="italic opacity-50">Unassigned</span>}</td>
                    <td className="p-4 text-text-muted text-xs">{new Date(inc.created_at).toLocaleString()}</td>
                    <td className="p-4 text-right">
                      <button className="text-text-muted hover:text-accent-primary transition-colors p-1" onClick={(e) => { e.stopPropagation(); setSelectedIncidentId(inc.id); }}>
                        <Eye className="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      <IncidentModal 
        incidentId={selectedIncidentId!} 
        isOpen={selectedIncidentId !== null} 
        onClose={() => setSelectedIncidentId(null)} 
        onUpdate={fetchIncidents}
        isAdmin={isAdmin}
      />
    </SectionCard>
  );
}
