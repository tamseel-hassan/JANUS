export interface InterfaceStat {
  if_index: number;
  if_name: string;
  if_descr: string;
  traffic_in_bps: number;
  traffic_out_bps: number;
}

export interface ResourceDevice {
  id: number;
  name: string;
  ip: string;
  type: string;
  model: string;
  ping_status: string;
  cpu_usage: number | null;
  memory_total: number | null;
  memory_used: number | null;
  disk_total: number | null;
  disk_used: number | null;
  interfaces: InterfaceStat[];
}

export interface Totals {
  devices: number;
  monitored_devices: number;
  interfaces: number;
  avg_cpu: number;
  avg_mem: number;
  avg_disk: number;
  traffic_in_bps: number;
  traffic_out_bps: number;
}

export interface MonitorDevice {
  id: number;
  name: string;
  ip: string;
  type: string;
  model: string;
  city: string;
  sub_office: string;
  country: string;
  status: "up" | "down";
  rtt_avg: number | null;
  checked_at: string | null;
  last_status_change: string | null;
}

export interface MonitorData {
  stats: {
    total: number;
    up: number;
    down: number;
    avg_rtt: number;
  };
  devices: MonitorDevice[];
  error?: string;
}

export interface HomeData {
  stats: {
    total: number;
    up: number;
    down: number;
    uptime: number;
  };
  tickets: {
    my_tasks: number;
    open: number;
    overdue: number;
  };
  charts: {
    servers: { labels: string[]; data: number[]; colors: string[] };
    firewalls: { labels: string[]; data: number[]; colors: string[] };
    switches: { labels: string[]; data: number[] };
  };
  error?: string;
}

export interface ProfileData {
  username: string;
  email: string;
  csrf_token?: string;
  error?: string;
}
