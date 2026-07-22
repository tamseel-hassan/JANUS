export interface Incident {
  id: number;
  title: string;
  description: string;
  severity: 'low' | 'medium' | 'high' | 'critical';
  status: 'open' | 'in_progress' | 'resolved' | 'closed';
  type: string;
  subcategory: string;
  assigned_to: number | null;
  reported_by: number | null;
  device_id: number | null;
  due_date: string | null;
  resolved_at: string | null;
  unread_by_assignee: number;
  created_at: string;
  updated_at: string;
  attachment: string | null;
  reporter_name: string | null;
  assignee_name: string | null;
  device_name: string | null;
  device_ip: string | null;
}

export interface IncidentComment {
  id: number;
  incident_id: number;
  user_id: number | null;
  comment: string;
  created_at: string;
  username: string | null;
}

export interface Observable {
  id: number;
  incident_id: number;
  type: string;
  value: string;
  notes: string;
}

export interface IncidentHistory {
  id: number;
  incident_id: number;
  changed_by: number | null;
  field: string;
  old_value: string;
  new_value: string;
  changed_at: string;
  username: string | null;
}

export interface SocOptions {
  users: { id: number; username: string }[];
  devices: { id: number; name: string; ip: string }[];
}
