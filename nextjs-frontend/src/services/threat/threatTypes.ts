export interface ThreatActionResponse {
  success?: boolean;
  message?: string;
  error?: string;
  data?: any;
}

export interface GenericThreatResponse {
  data?: any;
  logs?: any[];
  scans?: any[];
  intel?: any[];
  history?: any[];
  flows?: any[];
  error?: string;
}
