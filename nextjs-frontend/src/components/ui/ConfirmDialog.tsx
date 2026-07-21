"use client";

import { useEffect, useState, useRef } from "react";
import { useConfirmStore } from "@/lib/use-confirm";
import { AlertCircle, AlertTriangle, Info, X } from "lucide-react";
import { Button } from "./Button";

export function ConfirmDialog() {
  const { isOpen, options, closeConfirm } = useConfirmStore();
  const [inputValue, setInputValue] = useState("");
  const inputRef = useRef<HTMLInputElement>(null);
  
  // Update input value when options change
  useEffect(() => {
    if (isOpen && options?.inputOptions) {
      setInputValue(options.inputOptions.defaultValue || "");
      // Focus input on next tick
      setTimeout(() => inputRef.current?.focus(), 50);
    }
  }, [isOpen, options]);

  // Handle keyboard shortcuts
  useEffect(() => {
    if (!isOpen) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        closeConfirm(options?.inputOptions ? null : false);
      } else if (e.key === "Enter" && !e.shiftKey) {
        // Only trigger enter-to-confirm if it's not a multiline text area (we use input here so it's fine)
        e.preventDefault();
        closeConfirm(options?.inputOptions ? inputValue : true);
      }
    };

    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [isOpen, options, inputValue, closeConfirm]);

  // Prevent background scrolling when open
  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => {
      document.body.style.overflow = "";
    };
  }, [isOpen]);

  if (!isOpen && !options) return null;

  // Derive styles and icons based on variant
  const variant = options?.variant || 'default';
  
  const getIcon = () => {
    switch (variant) {
      case 'destructive': return <AlertCircle className="w-6 h-6 text-red-500" />;
      case 'warning': return <AlertTriangle className="w-6 h-6 text-amber-500" />;
      default: return <Info className="w-6 h-6 text-blue-500" />;
    }
  };

  const getConfirmVariant = () => {
    switch (variant) {
      case 'destructive': return 'danger';
      case 'warning': return 'primary'; // Could be a warning variant if available
      default: return 'primary';
    }
  };

  return (
    <div
      className={`fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-0 transition-all duration-200 ${
        isOpen ? "opacity-100" : "opacity-0 pointer-events-none"
      }`}
    >
      {/* Backdrop */}
      <div 
        className="absolute inset-0 bg-black/60 backdrop-blur-sm"
        onClick={() => closeConfirm(options?.inputOptions ? null : false)}
      />

      {/* Dialog Container */}
      <div
        className={`relative bg-bg-card border border-border-subtle shadow-2xl rounded-2xl w-full max-w-md overflow-hidden flex flex-col transition-all duration-300 ${
          isOpen ? "scale-100 translate-y-0" : "scale-95 translate-y-4"
        }`}
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirm-dialog-title"
      >
        <div className="p-6">
          <div className="flex items-start gap-4">
            <div className="flex-shrink-0 mt-0.5">
              {getIcon()}
            </div>
            
            <div className="flex-1 min-w-0">
              <h3 id="confirm-dialog-title" className="text-lg font-semibold text-foreground">
                {options?.title}
              </h3>
              
              {options?.description && (
                <div className="mt-2 text-sm text-text-muted">
                  {options.description}
                </div>
              )}

              {options?.inputOptions && (
                <div className="mt-4">
                  <input
                    ref={inputRef}
                    type="text"
                    value={inputValue}
                    onChange={(e) => setInputValue(e.target.value)}
                    placeholder={options.inputOptions.placeholder}
                    className="w-full bg-bg-base border border-border-subtle rounded-xl px-4 py-2.5 text-sm text-foreground focus:outline-none focus:border-accent-primary focus:ring-1 focus:ring-accent-primary"
                  />
                </div>
              )}
            </div>
            
            <button 
              onClick={() => closeConfirm(options?.inputOptions ? null : false)}
              className="text-text-muted hover:text-foreground transition-colors flex-shrink-0"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        <div className="bg-bg-base/50 px-6 py-4 flex items-center justify-end gap-3 border-t border-border-subtle">
          {!options?.hideCancel && (
            <Button
              variant="secondary"
              onClick={() => closeConfirm(options?.inputOptions ? null : false)}
            >
              {options?.cancelText || "Cancel"}
            </Button>
          )}
          <Button
            variant={getConfirmVariant() as any}
            onClick={() => closeConfirm(options?.inputOptions ? inputValue : true)}
          >
            {options?.confirmText || "Confirm"}
          </Button>
        </div>
      </div>
    </div>
  );
}
