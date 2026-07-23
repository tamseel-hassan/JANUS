export interface AuthUser {
  id: number;
  username: string;
  role: string;
}

export interface LoginResponse {
  success: boolean;
  user?: AuthUser;
  error?: string;
}

export interface CheckSessionResponse {
  authenticated: boolean;
  user?: AuthUser;
}

export interface LogoutResponse {
  success: boolean;
}
