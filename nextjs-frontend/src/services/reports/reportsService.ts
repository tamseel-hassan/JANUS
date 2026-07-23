import { apiClient } from "../client";
import { ENDPOINTS } from "../endpoints";
import { ReportsMetaResponse, GenericReportResponse } from "./reportsTypes";

export const reportsService = {
  getReportsMeta: () => {
    return apiClient.get<ReportsMetaResponse>(
      ENDPOINTS.REPORTS.META,
      "reportsService.getReportsMeta"
    );
  },

  getReport: <T = GenericReportResponse>(
    endpoint: string,
    params: Record<string, string> = {}
  ) => {
    const query = new URLSearchParams(params).toString();
    const url = query ? `${endpoint}?${query}` : endpoint;
    return apiClient.get<T>(url, `reportsService.getReport[${endpoint}]`);
  },

  getTrafficReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.TRAFFIC, params),

  getBandwidthReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.BANDWIDTH, params),

  getBruteForceReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.BRUTEFORCE, params),

  getDdosReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.DDOS, params),

  getAnomalyReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.ANOMALY, params),

  getApplicationsReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.APPLICATIONS, params),

  getAssetDiscoveryReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.ASSET_DISCOVERY, params),

  getComplianceReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.COMPLIANCE, params),

  getConfigChangesReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.CONFIG_CHANGES, params),

  getDnsAttacksReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.DNS_ATTACKS, params),

  getEndpointActivityReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.ENDPOINT_ACTIVITY, params),

  getMalwareReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.MALWARE, params),

  getRemoteAccessReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.REMOTE_ACCESS, params),

  getSecurityAnalysisReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.SECURITY_ANALYSIS, params),

  getThreatHuntingReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.THREAT_HUNTING, params),

  getUserActivityReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.USER_ACTIVITY, params),

  getVulnerabilityReport: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.VULNERABILITY, params),

  getNetFlow: (params?: Record<string, string>) =>
    reportsService.getReport(ENDPOINTS.REPORTS.FFLOW, params),
};
