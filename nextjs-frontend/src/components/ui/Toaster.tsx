"use client"

import { Toaster as Sonner } from "sonner"

type ToasterProps = React.ComponentProps<typeof Sonner>

export function Toaster({ ...props }: ToasterProps) {
  return (
    <Sonner
      className="toaster group"
      toastOptions={{
        classNames: {
          toast:
            "group toast group-[.toaster]:bg-bg-card group-[.toaster]:text-foreground group-[.toaster]:border-border-subtle group-[.toaster]:shadow-lg rounded-2xl p-4",
          description: "group-[.toast]:text-text-muted text-sm",
          actionButton:
            "group-[.toast]:bg-accent-primary group-[.toast]:text-white",
          cancelButton:
            "group-[.toast]:bg-bg-card group-[.toast]:text-text-muted group-[.toast]:border-border-subtle",
          success: "group-[.toaster]:bg-green-900/90 group-[.toaster]:text-green-100 group-[.toaster]:border-green-500",
          error: "group-[.toaster]:bg-red-900/90 group-[.toaster]:text-red-100 group-[.toaster]:border-red-500",
          warning: "group-[.toaster]:bg-amber-900/90 group-[.toaster]:text-amber-100 group-[.toaster]:border-amber-500",
          info: "group-[.toaster]:bg-blue-900/90 group-[.toaster]:text-blue-100 group-[.toaster]:border-blue-500",
        },
      }}
      {...props}
    />
  )
}
