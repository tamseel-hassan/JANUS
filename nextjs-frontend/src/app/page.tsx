"use client";

import { useEffect, useState } from "react";

interface ProfileData {
  username: string;
  email: string;
  csrf_token?: string;
  error?: string;
}

export default function ProfilePage() {
  const [profile, setProfile] = useState<ProfileData | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Fetch profile data from the PHP API endpoint
    // The rewrite in next.config.ts will proxy this to the PHP backend
    fetch("/api/get_profile.php", {
      // Include credentials so the PHP session cookie is sent!
      credentials: "include",
    })
      .then((res) => res.json())
      .then((data) => {
        setProfile(data);
        setLoading(false);
      })
      .catch((error) => {
        console.error("Error fetching profile:", error);
        setLoading(false);
      });
  }, []);

  return (
    <div className="max-w-4xl mx-auto space-y-8">
      <header className="border-b border-border-subtle pb-6">
        <h1 className="text-2xl font-bold tracking-tight text-[#f8f8f2]">
          User Profile
        </h1>
        <p className="text-text-muted mt-2">
          Manage your account settings and preferences.
        </p>
      </header>

      {loading ? (
        <div className="flex justify-center items-center h-48">
          <div className="w-10 h-10 border-4 border-border-subtle border-t-accent-primary rounded-full animate-spin"></div>
        </div>
      ) : profile?.error ? (
        <div className="bg-bg-card border-l-4 border-red-500 p-6 rounded-2xl">
          <h2 className="text-xl font-semibold text-red-400">
            Authentication Required
          </h2>
          <p className="text-text-muted mt-2">
            The PHP backend rejected the request. Please ensure you are logged
            into the original PHP application so the session cookie is active.
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
          <div className="bg-bg-raised rounded-2xl border border-border-subtle p-6 transition-all hover:border-accent-primary">
            <h2 className="text-xl font-semibold mb-6 flex items-center text-[#f8f8f2]">
              <svg
                className="w-6 h-6 mr-3 text-accent-primary"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                ></path>
              </svg>
              Account Details
            </h2>

            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-text-muted mb-1">
                  Username
                </label>
                <div className="w-full bg-bg-main border border-border-subtle rounded-2xl py-3 px-4 text-gray-300">
                  {profile?.username || "N/A"}
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-text-muted mb-1">
                  Email Address
                </label>
                <div className="w-full bg-bg-main border border-border-subtle rounded-2xl py-3 px-4 text-gray-300">
                  {profile?.email || "N/A"}
                </div>
              </div>
            </div>
          </div>

          <div className="bg-bg-raised rounded-2xl border border-border-subtle p-6 transition-all hover:border-accent-primary">
            <h2 className="text-xl font-semibold mb-6 flex items-center text-[#f8f8f2]">
              <svg
                className="w-6 h-6 mr-3 text-accent-primary"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8V7a4 4 0 00-8 0v4h8z"
                ></path>
              </svg>
              Security Settings
            </h2>

            <form className="space-y-4" onSubmit={(e) => e.preventDefault()}>
              <div>
                <label className="block text-sm font-medium text-text-muted mb-1">
                  New Password
                </label>
                <input
                  type="password"
                  className="w-full bg-bg-main border border-border-subtle rounded-2xl py-2 px-4 text-foreground focus:outline-none focus:border-accent-primary transition-colors"
                  placeholder="Enter new password"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-text-muted mb-1">
                  Confirm Password
                </label>
                <input
                  type="password"
                  className="w-full bg-bg-main border border-border-subtle rounded-2xl py-2 px-4 text-foreground focus:outline-none focus:border-accent-primary transition-colors"
                  placeholder="Confirm new password"
                />
              </div>
              <button
                type="submit"
                className="w-full bg-accent-primary hover:bg-accent-hover text-foreground font-medium py-2 px-4 rounded-2xl transition-colors mt-4"
              >
                Update Password
              </button>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
