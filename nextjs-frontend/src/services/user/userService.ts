import { apiClient } from "../client";
import { ENDPOINTS } from "../endpoints";
import { UsersResponse, UserActionResponse, UserProfileResponse, UploadPhotoResponse } from "./userTypes";

export const userService = {
  getUsers: (search = "", role = "") => {
    const params = new URLSearchParams({ search, role });
    return apiClient.get<UsersResponse>(
      `${ENDPOINTS.USER.GET_USERS}?${params.toString()}`,
      "userService.getUsers"
    );
  },

  executeAction: (
    action: string,
    id?: number,
    extraData: Record<string, any> = {}
  ) => {
    return apiClient.post<UserActionResponse>(
      ENDPOINTS.USER.POST_USERS,
      { action, id, ...extraData },
      "userService.executeAction"
    );
  },

  getProfile: () => {
    return apiClient.get<UserProfileResponse>(
      ENDPOINTS.USER.GET_PROFILE,
      "userService.getProfile"
    );
  },

  updateProfile: (data: Record<string, any>) => {
    return apiClient.post<UserActionResponse>(
      ENDPOINTS.USER.POST_PROFILE,
      data,
      "userService.updateProfile"
    );
  },

  uploadPhoto: (formData: FormData) => {
    return apiClient.postFormData<UploadPhotoResponse>(
      ENDPOINTS.USER.UPLOAD_PHOTO,
      formData,
      "userService.uploadPhoto"
    );
  },
};
