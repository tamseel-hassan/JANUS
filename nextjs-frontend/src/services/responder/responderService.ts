import { apiClient } from "../client";
import { ENDPOINTS } from "../endpoints";
import {
  ResponderDataResponse,
  AutomationData,
  ResponderActionResponse,
  InstallAutomationResponse,
} from "./responderTypes";

export const responderService = {
  getResponderData: () => {
    return apiClient.get<ResponderDataResponse>(
      ENDPOINTS.RESPONDER.GET_RESPONDER,
      "responderService.getResponderData"
    );
  },

  executeAction: (data: Record<string, any>) => {
    return apiClient.post<ResponderActionResponse>(
      ENDPOINTS.RESPONDER.POST_RESPONDER,
      data,
      "responderService.executeAction"
    );
  },

  getAutomation: () => {
    return apiClient.get<AutomationData>(
      ENDPOINTS.RESPONDER.GET_AUTOMATION,
      "responderService.getAutomation"
    );
  },

  installAutomation: () => {
    return apiClient.post<InstallAutomationResponse>(
      ENDPOINTS.RESPONDER.POST_INSTALL,
      {},
      "responderService.installAutomation"
    );
  },
};
