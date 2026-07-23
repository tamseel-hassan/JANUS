import { apiClient } from "../client";
import { ENDPOINTS } from "../endpoints";
import { LoginResponse, CheckSessionResponse, LogoutResponse } from "./authTypes";

export const authService = {
  login: (username: string, password: string) => {
    return apiClient.post<LoginResponse>(
      ENDPOINTS.AUTH.LOGIN,
      { username, password },
      "authService.login"
    );
  },

  logout: () => {
    return apiClient.post<LogoutResponse>(
      ENDPOINTS.AUTH.LOGOUT,
      {},
      "authService.logout"
    );
  },

  checkSession: () => {
    return apiClient.get<CheckSessionResponse>(
      ENDPOINTS.AUTH.CHECK,
      "authService.checkSession"
    );
  },
};
