import { apiClient } from "../client";
import { ENDPOINTS } from "../endpoints";
import { SiemData, LiveLogsResponse, SiemActionResponse } from "./siemTypes";

export const siemService = {
  getSiemData: () =>
    apiClient.get<SiemData>(ENDPOINTS.SIEM.GET_LOGS, "siemService.getSiemData"),

  getLiveLogs: () =>
    apiClient.get<LiveLogsResponse>(
      ENDPOINTS.SIEM.GET_LIVE_LOGS,
      "siemService.getLiveLogs"
    ),

  addSource: (sourceType: string, sourceIp: string) =>
    apiClient.post<SiemActionResponse>(
      ENDPOINTS.SIEM.POST_ACTION,
      { action: "add_source", appliance_type: sourceType, source_ip: sourceIp },
      "siemService.addSource"
    ),

  toggleSource: (id: string) =>
    apiClient.post<SiemActionResponse>(
      ENDPOINTS.SIEM.POST_ACTION,
      { action: "toggle_source", id },
      "siemService.toggleSource"
    ),

  deleteSource: (id: string) =>
    apiClient.post<SiemActionResponse>(
      ENDPOINTS.SIEM.POST_ACTION,
      { action: "delete_source", id },
      "siemService.deleteSource"
    ),

  purgeLogs: (id: string) =>
    apiClient.post<SiemActionResponse>(
      ENDPOINTS.SIEM.POST_ACTION,
      { action: "purge_logs", id },
      "siemService.purgeLogs"
    ),

  saveRetention: (hours: number) =>
    apiClient.post<SiemActionResponse>(
      ENDPOINTS.SIEM.POST_ACTION,
      { action: "save_retention", hours },
      "siemService.saveRetention"
    ),

  cleanupArchive: (hours: number) =>
    apiClient.post<SiemActionResponse>(
      ENDPOINTS.SIEM.POST_ACTION,
      { action: "cleanup_archive", hours },
      "siemService.cleanupArchive"
    ),
};
