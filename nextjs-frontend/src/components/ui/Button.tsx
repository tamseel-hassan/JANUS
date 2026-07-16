import React from "react";
import { Loader2 } from "lucide-react";

export type ButtonVariant =
  | "primary"
  | "secondary"
  | "danger"
  | "outline"
  | "outline-danger"
  | "outline-primary"
  | "outline-warning"
  | "outline-success"
  | "ghost"
  | "ghost-danger"
  | "icon";

export type ButtonSize = "sm" | "md" | "lg" | "icon";

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  isLoading?: boolean;
}

export function Button({
  children,
  className = "",
  variant = "primary",
  size = "md",
  isLoading = false,
  disabled,
  ...props
}: ButtonProps) {
  const getVariantClasses = () => {
    switch (variant) {
      case "primary":
        return "bg-accent-primary hover:bg-accent-hover text-foreground border border-transparent";
      case "secondary":
        return "bg-bg-card hover:bg-border-subtle text-foreground border border-transparent";
      case "danger":
        return "bg-red-600 hover:bg-red-500 text-foreground border border-transparent";
      case "outline":
        return "bg-transparent hover:bg-bg-card text-foreground border border-border-subtle";
      case "outline-danger":
        return "bg-bg-card hover:bg-red-600 text-red-400 hover:text-foreground border border-border-subtle hover:border-red-500";
      case "outline-primary":
        return "bg-bg-card hover:bg-blue-600 text-blue-400 hover:text-foreground border border-border-subtle hover:border-blue-500";
      case "outline-warning":
        return "bg-bg-card hover:bg-yellow-600 text-yellow-400 hover:text-foreground border border-border-subtle hover:border-yellow-500";
      case "outline-success":
        return "bg-bg-card hover:bg-green-600 text-green-400 hover:text-foreground border border-border-subtle hover:border-green-500";
      case "ghost":
        return "bg-transparent text-text-muted hover:text-foreground hover:bg-bg-card border border-transparent";
      case "ghost-danger":
        return "bg-transparent text-text-muted hover:text-red-400 hover:bg-bg-card border border-transparent";
      case "icon":
        return "bg-transparent text-text-muted hover:text-foreground border border-transparent";
      default:
        return "bg-accent-primary hover:bg-accent-hover text-foreground border border-transparent";
    }
  };

  const getSizeClasses = () => {
    switch (size) {
      case "sm":
        return "px-3 py-1.5 text-sm rounded-2xl";
      case "md":
        return "px-4 py-2 rounded-2xl";
      case "lg":
        return "px-6 py-3 text-lg rounded-2xl";
      case "icon":
        return "p-1.5 rounded-2xl flex items-center justify-center";
      default:
        return "px-4 py-2 rounded-2xl";
    }
  };

  const baseClasses =
    "inline-flex items-center justify-center font-light transition-colors disabled:opacity-50 disabled:cursor-not-allowed";

  return (
    <button
      className={`${baseClasses} ${getVariantClasses()} ${getSizeClasses()} ${className}`}
      disabled={disabled || isLoading}
      {...props}
    >
      {isLoading ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : null}
      {children}
    </button>
  );
}
