import { User } from "@/types/users";

export type { User };

export interface UsersResponse {
  users: User[];
}

export interface UserActionResponse {
  success?: string;
  error?: string;
}

export interface UserProfileResponse {
  username?: string;
  email?: string;
  role?: string;
  profile_photo?: string;
  created_at?: string;
  error?: string;
}

export interface UploadPhotoResponse {
  success?: boolean;
  photo_url?: string;
  error?: string;
}
