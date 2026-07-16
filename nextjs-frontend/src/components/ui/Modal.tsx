"use client";

import { useEffect } from "react";
import { createPortal } from "react-dom";
import { X } from "lucide-react";
import { Button } from "@/components/ui/Button";

export type ModalSize = "sm" | "md" | "lg" | "xl" | "2xl" | "3xl" | "full";

const sizeClasses: Record<ModalSize, string> = {
  sm:   "max-w-sm",
  md:   "max-w-md",
  lg:   "max-w-lg",
  xl:   "max-w-xl",
  "2xl": "max-w-2xl",
  "3xl": "max-w-3xl",
  full: "max-w-full",
};

interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title: React.ReactNode;
  icon?: React.ReactNode;
  size?: ModalSize;
  children: React.ReactNode;
  footer?: React.ReactNode;
  className?: string;
}

export function Modal({
  isOpen,
  onClose,
  title,
  icon,
  size = "md",
  children,
  footer,
  className = "",
}: ModalProps) {
  useEffect(() => {
    if (!isOpen) return;
    const handler = (e: KeyboardEvent) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [isOpen, onClose]);

  useEffect(() => {
    if (isOpen) { document.body.style.overflow = "hidden"; }
    else { document.body.style.overflow = ""; }
    return () => { document.body.style.overflow = ""; };
  }, [isOpen]);

  if (!isOpen) return null;

  const panel = (
    <div
      className="fixed inset-0 bg-black/70 backdrop-blur-sm z-[200] flex items-center justify-center p-4 overflow-y-auto"
      onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div
        className={`relative bg-bg-main border border-border-subtle rounded-2xl w-full ${sizeClasses[size]} my-8 shadow-2xl flex flex-col max-h-[90vh] ${className}`}
        onMouseDown={(e) => e.stopPropagation()}
      >
        <div className="bg-bg-raised px-6 py-4 border-b border-border-subtle flex items-center justify-between shrink-0 rounded-t-2xl">
          <h3 className="text-lg font-semibold text-foreground flex items-center gap-2">
            {icon && <span className="text-accent-primary">{icon}</span>}
            {title}
          </h3>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close modal">
            <X className="w-5 h-5" />
          </Button>
        </div>
        <div className="flex-1 overflow-y-auto p-6">
          {children}
        </div>
        {footer && (
          <div className="px-6 py-4 border-t border-border-subtle bg-bg-raised flex items-center justify-end gap-3 shrink-0 rounded-b-2xl">
            {footer}
          </div>
        )}
      </div>
    </div>
  );

  return typeof document !== "undefined" ? createPortal(panel, document.body) : null;
}

export default Modal;
