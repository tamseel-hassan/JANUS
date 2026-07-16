import { ShieldCheck, ShieldAlert } from "lucide-react";
import { useEffect, useState } from "react";

interface ToastProps {
  type: "success" | "error";
  message: string;
  onDismiss: () => void;
  /** Auto-dismiss delay in ms. Defaults to 5000 */
  duration?: number;
}

export function Toast({ type, message, onDismiss, duration = 5000 }: ToastProps) {
  useEffect(() => {
    const t = setTimeout(onDismiss, duration);
    return () => clearTimeout(t);
  }, [onDismiss, duration]);

  return (
    <div
      className={`fixed top-4 right-4 z-50 p-4 rounded-2xl border flex items-center shadow-lg transition-all ${
        type === "success"
          ? "bg-green-900/90 border-green-500 text-green-100"
          : "bg-red-900/90 border-red-500 text-red-100"
      }`}
    >
      {type === "success" ? (
        <ShieldCheck className="w-5 h-5 mr-3 flex-shrink-0" />
      ) : (
        <ShieldAlert className="w-5 h-5 mr-3 flex-shrink-0" />
      )}
      <span>{message}</span>
    </div>
  );
}

export default Toast;
