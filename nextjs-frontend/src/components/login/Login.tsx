"use client";

import { useState, useEffect } from "react";
import { useRouter } from "next/navigation";
import Image from "next/image";
import {
  Eye,
  EyeOff,
  Lock,
  User,
  AlertCircle,
  Loader2,
  Sun,
  Moon,
} from "lucide-react";
import { useAuthStore } from "@/store/authStore";
import { Button } from "@/components/ui/Button";
import { GradientShaderBackground } from "@/components/ui/GradientShaderBackground";

export function Login() {
  const router = useRouter();
  const { login, checkSession, isAuthenticated } = useAuthStore();

  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [checking, setChecking] = useState(true);
  const [isDark, setIsDark] = useState(true);

  useEffect(() => {
    const savedTheme = localStorage.getItem("theme");
    if (savedTheme === "light") {
      setIsDark(false);
      document.documentElement.classList.remove("dark");
    } else {
      setIsDark(true);
      document.documentElement.classList.add("dark");
    }
  }, []);

  const toggleTheme = () => {
    const nextIsDark = !isDark;
    setIsDark(nextIsDark);
    localStorage.setItem("theme", nextIsDark ? "dark" : "light");

    // Fast sync (140ms) so card elements update as the 550ms radial glow wave sweeps over the card
    setTimeout(() => {
      if (nextIsDark) {
        document.documentElement.classList.add("dark");
      } else {
        document.documentElement.classList.remove("dark");
      }
    }, 50);
  };

  // If already authenticated, redirect to home
  useEffect(() => {
    checkSession().then((authenticated) => {
      if (authenticated) router.replace("/home");
      else setChecking(false);
    });
  }, []);

  useEffect(() => {
    if (isAuthenticated) router.replace("/home");
  }, [isAuthenticated]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!username.trim() || !password.trim()) {
      setError("Please enter both username and password.");
      return;
    }
    setError("");
    setLoading(true);
    const result = await login(username, password);
    setLoading(false);
    if (result.success) {
      router.replace("/home");
    } else {
      setError(result.error || "Login failed");
    }
  };

  if (checking) {
    return (
      <div className="min-h-screen bg-bg-main flex items-center justify-center">
        <Loader2 className="w-8 h-8 animate-spin text-accent-primary" />
      </div>
    );
  }
  const logoSrc = isDark
    ? "/icons/janus-icon-dark.svg"
    : "/icons/janus-icon-light.svg";

  return (
    <div className="relative min-h-screen bg-bg-main flex items-center justify-center px-4 overflow-hidden">
      {/* Undertone Gradient Shader Looped Background with Tilted Bars & Radial Theme Glow */}
      <GradientShaderBackground
        speed={0.5}
        intensity={0.9}
        tiltAngle={-25}
        barCount={5}
        isDark={isDark}
      />

      {/* Background decorative elements */}
      <div className="absolute inset-0 overflow-hidden pointer-events-none">
        <div className="absolute top-1/4 left-1/4 w-72 h-72 rounded-full bg-accent-primary/10 blur-[100px]" />
        <div className="absolute bottom-1/4 right-1/4 w-96 h-96 rounded-full bg-indigo-500/10 blur-[120px]" />
      </div>

      <div className="w-full max-w-md relative z-10">
        {/* Card */}
        <div className="bg-bg-card/85 backdrop-blur-xl border border-border-subtle/80 shadow-2xl rounded-2xl p-8">
          {/* Logo / Brand */}
          <div className="text-center mb-8">
            <div className="inline-flex items-center justify-center w-24 h-16 rounded-2xl mb-1 px-3">
              <Image
                src={logoSrc}
                alt="JANUS"
                width={60}
                height={50}
                priority
              />
            </div>
            <h1 className="text-2xl font-medium text-foreground tracking-tight">
              JANUS
            </h1>
          </div>

          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-medium text-foreground">SIGN IN</h2>
            {/* Theme Toggle Button */}
            <button
              type="button"
              onClick={toggleTheme}
              className="relative flex items-center w-11 h-6 rounded-full bg-bg-darker border border-border-subtle transition-colors cursor-pointer focus:outline-none"
              title={`Switch to ${isDark ? "light" : "dark"} mode`}
            >
              <div
                className={`w-4 h-4 rounded-full flex items-center justify-center absolute top-1/2 -translate-y-1/2 left-[3px] transition-transform duration-300 ${
                  isDark ? "translate-x-0" : "translate-x-5"
                }`}
              >
                {isDark ? (
                  <Moon className="w-3 h-3 text-yellow-400" />
                ) : (
                  <Sun className="w-3 h-3 text-yellow-500" />
                )}
              </div>
            </button>
          </div>

          {/* Error Banner */}
          {error && (
            <div className="flex items-center gap-2 bg-red-500/10 border border-red-500/30 text-red-400 rounded-2xl px-4 py-3 mb-5 text-sm">
              <AlertCircle className="w-4 h-4 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-5">
            {/* Username */}
            <div>
              <label className="block text-sm font-medium text-text-muted mb-1.5">
                Username
              </label>
              <div className="relative">
                <User className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted pointer-events-none" />
                <input
                  id="username"
                  type="text"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  autoComplete="username"
                  autoFocus
                  placeholder="Enter username"
                  disabled={loading}
                  className="w-full bg-bg-darker border border-border-subtle rounded-2xl py-2.5 pl-10 pr-4 text-foreground placeholder:text-text-muted text-sm focus:outline-none focus:border-accent-primary focus:ring-1 focus:ring-accent-primary/30 transition-all disabled:opacity-60"
                />
              </div>
            </div>

            {/* Password */}
            <div>
              <label className="block text-sm font-medium text-text-muted mb-1.5">
                Password
              </label>
              <div className="relative">
                <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted pointer-events-none" />
                <input
                  id="password"
                  type={showPassword ? "text" : "password"}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  autoComplete="current-password"
                  placeholder="Enter password"
                  disabled={loading}
                  className="w-full bg-bg-darker border border-border-subtle rounded-2xl py-2.5 pl-10 pr-10 text-foreground placeholder:text-text-muted text-sm focus:outline-none focus:border-accent-primary focus:ring-1 focus:ring-accent-primary/30 transition-all disabled:opacity-60"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-text-muted hover:text-foreground transition-colors"
                  tabIndex={-1}
                >
                  {showPassword ? (
                    <EyeOff className="w-4 h-4" />
                  ) : (
                    <Eye className="w-4 h-4" />
                  )}
                </button>
              </div>
            </div>

            {/* Submit */}
            <Button
              id="login-submit"
              type="submit"
              variant="primary"
              className="w-full font-medium py-2.5 mt-2"
              disabled={loading}
              isLoading={loading}
            >
              {!loading && "Sign in"}
            </Button>
          </form>
        </div>

        {/* <p className="text-center text-xs text-text-muted mt-6">
          JANUS © {new Date().getFullYear()} — Network Operations Platform
        </p> */}
      </div>
    </div>
  );
}
export default Login;
