import { toast } from "sonner";
import { confirmDialog, promptDialog, ConfirmOptions } from "@/lib/use-confirm";
import { ToastOptions } from "./feedbackTypes";

/**
 * Unified Notification Service (Toast wrapper)
 */
export const notify = {
  success: (message: string, options?: ToastOptions) => {
    return toast.success(message, options);
  },

  error: (message: string, options?: ToastOptions) => {
    return toast.error(message, options);
  },

  info: (message: string, options?: ToastOptions) => {
    return toast.info(message, options);
  },

  warning: (message: string, options?: ToastOptions) => {
    return toast.warning(message, options);
  },

  promise: <T>(
    promise: Promise<T>,
    messages: { loading: string; success: string; error: string }
  ) => {
    return toast.promise(promise, messages);
  },
};

/**
 * Unified Dialog & Prompt Service (Confirm/Prompt modal wrapper)
 */
export const promptService = {
  confirm: (options: ConfirmOptions): Promise<boolean> => {
    return confirmDialog(options);
  },

  input: (options: ConfirmOptions): Promise<string | null> => {
    return promptDialog(options);
  },

  alert: (message: string, title = "Alert"): Promise<boolean> => {
    return confirmDialog({
      title,
      description: message,
      hideCancel: true,
      confirmText: "OK",
    });
  },
};
