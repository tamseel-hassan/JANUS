import { ReactNode } from "react";
import { ConfirmOptions, ConfirmVariant } from "@/lib/use-confirm";

export type { ConfirmOptions, ConfirmVariant };

export interface ToastOptions {
  description?: string | ReactNode;
  duration?: number;
  id?: string | number;
}
