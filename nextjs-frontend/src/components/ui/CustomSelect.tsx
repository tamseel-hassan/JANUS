"use client";

import { useState, useEffect, useRef, useCallback } from "react";
import { createPortal } from "react-dom";
import { ChevronDown } from "lucide-react";

interface Option {
  value: string;
  label: string;
}

interface CustomSelectProps {
  value: string;
  onChange: (value: string) => void;
  options: Option[];
  placeholder?: string;
  icon?: React.ReactNode;
  className?: string;
}

export default function CustomSelect({
  value,
  onChange,
  options,
  placeholder,
  icon,
  className = "",
}: CustomSelectProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [dropdownStyle, setDropdownStyle] = useState<React.CSSProperties>({});
  const triggerRef = useRef<HTMLButtonElement>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);

  const selectedOption = options.find((opt) => opt.value === value);
  const displayLabel = selectedOption ? selectedOption.label : placeholder || "";

  // Compute dropdown position relative to viewport
  const openDropdown = useCallback(() => {
    if (!triggerRef.current) return;
    const rect = triggerRef.current.getBoundingClientRect();
    const spaceBelow = window.innerHeight - rect.bottom;
    const dropdownMaxH = 240;
    const openUpward = spaceBelow < dropdownMaxH && rect.top > dropdownMaxH;

    setDropdownStyle({
      position: "fixed",
      left: rect.left,
      width: rect.width,
      zIndex: 9999,
      ...(openUpward
        ? { bottom: window.innerHeight - rect.top + 4 }
        : { top: rect.bottom + 4 }),
    });
    setIsOpen(true);
  }, []);

  // Close when clicking outside
  useEffect(() => {
    if (!isOpen) return;
    function handleClick(e: MouseEvent) {
      if (
        triggerRef.current?.contains(e.target as Node) ||
        dropdownRef.current?.contains(e.target as Node)
      ) return;
      setIsOpen(false);
    }
    document.addEventListener("mousedown", handleClick);
    return () => document.removeEventListener("mousedown", handleClick);
  }, [isOpen]);

  // Close on scroll so it doesn't float away
  useEffect(() => {
    if (!isOpen) return;
    const handler = () => setIsOpen(false);
    window.addEventListener("scroll", handler, true);
    return () => window.removeEventListener("scroll", handler, true);
  }, [isOpen]);

  const dropdown = isOpen ? (
    <div
      ref={dropdownRef}
      style={dropdownStyle}
      className="bg-bg-card border border-border-subtle rounded-2xl py-1 shadow-xl max-h-60 overflow-y-auto custom-scrollbar"
    >
      {options.map((opt) => (
        <button
          key={opt.value}
          type="button"
          onMouseDown={(e) => {
            e.preventDefault();
            onChange(opt.value);
            setIsOpen(false);
          }}
          className={`w-full flex items-center px-4 py-2 text-sm transition-colors text-left ${
            opt.value === value
              ? "bg-accent-primary/20 text-accent-primary font-medium"
              : "text-text-muted hover:text-foreground hover:bg-bg-darker"
          }`}
        >
          <span className="truncate">{opt.label}</span>
        </button>
      ))}
    </div>
  ) : null;

  return (
    <div className={`relative ${className}`}>
      <button
        ref={triggerRef}
        type="button"
        onClick={() => (isOpen ? setIsOpen(false) : openDropdown())}
        className="w-full flex items-center gap-2 bg-bg-card border border-border-subtle rounded-2xl py-1.5 px-4 text-sm text-foreground hover:text-foreground focus:outline-none focus:border-accent-primary transition-all duration-200 select-none text-left justify-between"
      >
        <div className="flex items-center gap-2 truncate">
          {icon && <span className="text-text-muted shrink-0">{icon}</span>}
          <span className="truncate">{displayLabel}</span>
        </div>
        <ChevronDown
          className={`w-3.5 h-3.5 text-text-muted opacity-70 transition-transform duration-200 shrink-0 ml-1 ${
            isOpen ? "rotate-180" : ""
          }`}
        />
      </button>

      {typeof document !== "undefined" && dropdown
        ? createPortal(dropdown, document.body)
        : null}
    </div>
  );
}
