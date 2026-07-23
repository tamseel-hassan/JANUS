import { apiClient } from "../client";
import { ENDPOINTS } from "../endpoints";
import {
  AvailabilityResponse,
  AvailabilityActionResponse,
  AvailabilityReportsResponse,
} from "./availabilityTypes";

export const availabilityService = {
  getAvailability: (params?: Record<string, string>) => {
    const query = params ? `?${new URLSearchParams(params).toString()}` : "";
    return apiClient.get<AvailabilityResponse>(
      `${ENDPOINTS.AVAILABILITY.GET_AVAILABILITY}${query}`,
      "availabilityService.getAvailability"
    );
  },

  executeAction: (data: Record<string, any>) => {
    return apiClient.post<AvailabilityActionResponse>(
      ENDPOINTS.AVAILABILITY.POST_AVAILABILITY,
      data,
      "availabilityService.executeAction"
    );
  },

  getReports: (range = "7d") => {
    return apiClient.get<AvailabilityReportsResponse>(
      `${ENDPOINTS.AVAILABILITY.GET_REPORTS}?range=${range}`,
      "availabilityService.getReports"
    );
  },
};
