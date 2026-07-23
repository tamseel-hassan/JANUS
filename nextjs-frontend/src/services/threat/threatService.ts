import { apiClient } from "../client";
import { ENDPOINTS } from "../endpoints";
import { ThreatActionResponse, GenericThreatResponse } from "./threatTypes";

export const threatService = {
  getIpsLogs: () => {
    return apiClient.get<GenericThreatResponse>(
      ENDPOINTS.THREAT.IPS,
      "threatService.getIpsLogs"
    );
  },

  getMalware: () => {
    return apiClient.get<GenericThreatResponse>(
      ENDPOINTS.THREAT.MALWARE_GET,
      "threatService.getMalware"
    );
  },

  postMalware: (data: Record<string, any>) => {
    return apiClient.post<ThreatActionResponse>(
      ENDPOINTS.THREAT.MALWARE_POST,
      data,
      "threatService.postMalware"
    );
  },

  getThreatIntel: () => {
    return apiClient.get<GenericThreatResponse>(
      ENDPOINTS.THREAT.INTEL_GET,
      "threatService.getThreatIntel"
    );
  },

  postThreatIntel: (data: Record<string, any>) => {
    return apiClient.post<ThreatActionResponse>(
      ENDPOINTS.THREAT.INTEL_POST,
      data,
      "threatService.postThreatIntel"
    );
  },

  getTrafficAnalyzer: (range = "24h") => {
    return apiClient.get<GenericThreatResponse>(
      `${ENDPOINTS.THREAT.TRAFFIC_ANALYZER}?range=${range}`,
      "threatService.getTrafficAnalyzer"
    );
  },

  postScan: (data: Record<string, any>) => {
    return apiClient.post<ThreatActionResponse>(
      ENDPOINTS.THREAT.VULN_SCAN_POST,
      data,
      "threatService.postScan"
    );
  },

  search: (query: string) => {
    return apiClient.get<GenericThreatResponse>(
      `${ENDPOINTS.THREAT.SEARCH}?q=${encodeURIComponent(query)}`,
      "threatService.search"
    );
  },
};
