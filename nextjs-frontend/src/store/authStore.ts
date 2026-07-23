import { create } from "zustand";
import { persist } from "zustand/middleware";
import { authService } from "@/services/auth/authService";
import { AuthUser } from "@/services/auth/authTypes";

export type { AuthUser };

interface AuthState {
  user: AuthUser | null;
  isAuthenticated: boolean;
  isLoading: boolean;

  // Actions
  setUser: (user: AuthUser) => void;
  clearUser: () => void;
  setLoading: (loading: boolean) => void;

  // Async thunks
  login: (username: string, password: string) => Promise<{ success: boolean; error?: string }>;
  logout: () => Promise<void>;
  checkSession: () => Promise<boolean>;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      isAuthenticated: false,
      isLoading: true,

      setUser: (user) => set({ user, isAuthenticated: true, isLoading: false }),
      clearUser: () => set({ user: null, isAuthenticated: false, isLoading: false }),
      setLoading: (loading) => set({ isLoading: loading }),

      login: async (username, password) => {
        set({ isLoading: true });
        try {
          const data = await authService.login(username, password);
          if (data?.success && data.user) {
            set({ user: data.user, isAuthenticated: true, isLoading: false });
            return { success: true };
          } else {
            set({ isLoading: false });
            return { success: false, error: data?.error || "Login failed" };
          }
        } catch {
          set({ isLoading: false });
          return { success: false, error: "Network error. Please try again." };
        }
      },

      logout: async () => {
        try {
          await authService.logout();
        } finally {
          set({ user: null, isAuthenticated: false, isLoading: false });
        }
      },

      checkSession: async () => {
        const wasAuthenticated = get().isAuthenticated;
        if (!wasAuthenticated) {
          set({ isLoading: true });
        }
        try {
          const data = await authService.checkSession();
          if (data?.authenticated && data.user) {
            set({ user: data.user, isAuthenticated: true, isLoading: false });
            return true;
          } else {
            set({ user: null, isAuthenticated: false, isLoading: false });
            return false;
          }
        } catch {
          set({ user: null, isAuthenticated: false, isLoading: false });
          return false;
        }
      },
    }),
    {
      name: "janus-auth",
      // Only persist user + isAuthenticated; always re-validate with server on load
      partialize: (state) => ({
        user: state.user,
        isAuthenticated: state.isAuthenticated,
      }),
    }
  )
);
