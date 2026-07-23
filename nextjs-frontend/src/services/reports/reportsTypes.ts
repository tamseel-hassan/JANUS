export interface ReportsMetaResponse {
  categories?: Record<string, any>;
  error?: string;
}

export interface GenericReportResponse {
  data?: any[];
  chart?: any[];
  summary?: Record<string, any>;
  error?: string;
}
