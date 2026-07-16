export interface Log {
  log_id: number;
  received_at: string;
  message: string;
  source_ip: string;
  source_table: string;
}

export interface IPSLog {
  id: string;
  time: string;
  src: string;
  dst: string;
  action: string;
  severity: string;
  attack: string;
  app: string;
  devname: string;
}

export interface Flow {
  log_id: string;
  time: string;
  src: string;
  dst: string;
  service: string;
  action: string;
  icon: string;
  policyid: string;
  devname: string;
  source_ip: string;
  sentbyte: number;
  rcvdbyte: number;
  is_archive: boolean;
  full_parsed: any;
}

export interface SiemSource {
  id: string;
  source_ip: string;
  source_type: string;
  is_active: number;
  added_by: string;
  username: string;
  added_at: string;
  log_count: number;
  last_log: string;
}

export interface LogFile {
  ip: string;
  date: string;
  file: string;
  size: number;
  lines: number;
}

export interface SiemData {
  sources: SiemSource[];
  log_files: LogFile[];
  error?: string;
}
