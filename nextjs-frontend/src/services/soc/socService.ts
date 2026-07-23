import { apiClient } from "../client";
import { ENDPOINTS } from "../endpoints";
import {
  IncidentsListResponse,
  IncidentDetailsResponse,
  SocOptions,
  SocActionResponse,
} from "./socTypes";

export const socService = {
  getIncidents: (status = "", severity = "", search = "") => {
    const params = new URLSearchParams({ status, severity, search });
    return apiClient.get<IncidentsListResponse>(
      `${ENDPOINTS.SOC.GET_INCIDENTS}?${params.toString()}`,
      "socService.getIncidents"
    );
  },

  getOptions: () => {
    return apiClient.get<SocOptions>(
      `${ENDPOINTS.SOC.GET_INCIDENTS}?action=options`,
      "socService.getOptions"
    );
  },

  getIncidentDetails: (id: number) => {
    return apiClient.get<IncidentDetailsResponse>(
      `${ENDPOINTS.SOC.GET_INCIDENTS}?action=details&id=${id}`,
      "socService.getIncidentDetails"
    );
  },

  executeAction: (formData: FormData) => {
    return apiClient.postFormData<SocActionResponse>(
      ENDPOINTS.SOC.POST_INCIDENTS,
      formData,
      "socService.executeAction"
    );
  },

  reportIncident: (formData: FormData) => {
    return apiClient.postFormData<SocActionResponse>(
      ENDPOINTS.SOC.POST_INCIDENTS,
      formData,
      "socService.reportIncident"
    );
  },
};
