import { Incident, IncidentComment, Observable, IncidentHistory, SocOptions } from "@/types/soc";

export type { Incident, IncidentComment, Observable, IncidentHistory, SocOptions };

export interface IncidentsListResponse {
  incidents: Incident[];
  total?: number;
}

export interface IncidentDetailsResponse {
  incident: Incident;
  comments: IncidentComment[];
  observables: Observable[];
  history: IncidentHistory[];
}

export interface SocActionResponse {
  success?: string | boolean;
  message?: string;
  error?: string;
  id?: number;
}
