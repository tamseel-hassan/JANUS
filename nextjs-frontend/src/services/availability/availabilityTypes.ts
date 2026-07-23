export interface AvailabilityResponse {
  targets?: any[];
  summary?: Record<string, any>;
  report?: any;
  error?: string;
}

export interface AvailabilityActionResponse {
  success?: boolean;
  message?: string;
  error?: string;
}

export interface AvailabilityReportsResponse {
  reports?: any[];
  summary?: Record<string, any>;
  error?: string;
}
