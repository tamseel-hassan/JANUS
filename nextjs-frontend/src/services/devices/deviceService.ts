import { apiClient } from "../client";
import { ENDPOINTS } from "../endpoints";
import { DevicesResponse, DeviceActionResponse, ConfigBackupResponse } from "./deviceTypes";

export const deviceService = {
  getDevices: () => {
    return apiClient.get<DevicesResponse>(
      ENDPOINTS.DEVICES.GET_DEVICES,
      "deviceService.getDevices"
    );
  },

  executeAction: (data: Record<string, any>) => {
    return apiClient.post<DeviceActionResponse>(
      ENDPOINTS.DEVICES.POST_DEVICES,
      data,
      "deviceService.executeAction"
    );
  },

  postDevice: (data: Record<string, any>) => {
    return apiClient.post<DeviceActionResponse>(
      ENDPOINTS.DEVICES.POST_DEVICES,
      data,
      "deviceService.postDevice"
    );
  },

  getConfigBackup: () => {
    return apiClient.get<ConfigBackupResponse>(
      ENDPOINTS.DEVICES.GET_CONFIG_BACKUP,
      "deviceService.getConfigBackup"
    );
  },

  postConfigBackup: (data: Record<string, any>) => {
    return apiClient.post<DeviceActionResponse>(
      ENDPOINTS.DEVICES.POST_CONFIG_BACKUP,
      data,
      "deviceService.postConfigBackup"
    );
  },
};
