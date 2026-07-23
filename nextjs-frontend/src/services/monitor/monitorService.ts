import { apiClient } from "../client";
import { ENDPOINTS } from "../endpoints";
import { MonitorDataResponse, MonitorActionResponse } from "./monitorTypes";

export const monitorService = {
  getMonitor: () => {
    return apiClient.get<MonitorDataResponse>(
      ENDPOINTS.MONITOR.MONITOR,
      "monitorService.getMonitor"
    );
  },

  getIpam: () => {
    return apiClient.get<MonitorDataResponse>(
      ENDPOINTS.MONITOR.IPAM_GET,
      "monitorService.getIpam"
    );
  },

  postIpam: (data: Record<string, any>) => {
    return apiClient.post<MonitorActionResponse>(
      ENDPOINTS.MONITOR.IPAM_POST,
      data,
      "monitorService.postIpam"
    );
  },

  getResources: () => {
    return apiClient.get<MonitorDataResponse>(
      ENDPOINTS.MONITOR.RESOURCES,
      "monitorService.getResources"
    );
  },

  getHome: () => {
    return apiClient.get<MonitorDataResponse>(
      ENDPOINTS.MONITOR.HOME,
      "monitorService.getHome"
    );
  },

  getNavData: () => {
    return apiClient.get<MonitorDataResponse>(
      ENDPOINTS.MONITOR.NAV_DATA,
      "monitorService.getNavData"
    );
  },

  getMaps: (mapId?: number) => {
    const params = mapId ? { map_id: mapId } : undefined;
    return apiClient.get<MonitorDataResponse>(
      ENDPOINTS.MONITOR.MAPS_GET,
      params,
      "monitorService.getMaps"
    );
  },

  postMaps: (data: Record<string, any>) => {
    return apiClient.post<MonitorActionResponse>(
      ENDPOINTS.MONITOR.MAPS_POST,
      data,
      "monitorService.postMaps"
    );
  },
};
