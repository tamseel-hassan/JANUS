export interface MonitorDataResponse {
  data?: any;
  summary?: Record<string, any>;
  error?: string;
}

export interface MonitorActionResponse {
  success?: boolean;
  message?: string;
  status?: string;
  error?: string;
}
