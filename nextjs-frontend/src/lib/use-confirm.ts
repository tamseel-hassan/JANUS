import { create } from 'zustand';
import { ReactNode } from 'react';

export type ConfirmVariant = 'default' | 'destructive' | 'warning';

export interface ConfirmOptions {
  title: string | ReactNode;
  description?: string | ReactNode;
  confirmText?: string;
  cancelText?: string;
  variant?: ConfirmVariant;
  hideCancel?: boolean;
  inputOptions?: {
    placeholder?: string;
    defaultValue?: string;
  };
}

interface ConfirmState {
  isOpen: boolean;
  options: ConfirmOptions | null;
  resolver: ((value: boolean | string | null) => void) | null;
  
  openConfirm: (options: ConfirmOptions) => Promise<boolean | string | null>;
  closeConfirm: (value: boolean | string | null) => void;
}

export const useConfirmStore = create<ConfirmState>((set, get) => ({
  isOpen: false,
  options: null,
  resolver: null,

  openConfirm: (options: ConfirmOptions) => {
    return new Promise((resolve) => {
      set({
        isOpen: true,
        options,
        resolver: resolve,
      });
    });
  },

  closeConfirm: (value: boolean | string | null) => {
    const { resolver } = get();
    if (resolver) {
      resolver(value);
    }
    set({
      isOpen: false,
      resolver: null,
      // We keep options briefly for the exit animation
    });
    // Clear options after animation
    setTimeout(() => {
      set((state) => (!state.isOpen ? { options: null } : state));
    }, 300);
  },
}));

// Functional API
export const confirmDialog = (options: ConfirmOptions): Promise<boolean> => {
  return useConfirmStore.getState().openConfirm(options) as Promise<boolean>;
};

export const promptDialog = (options: ConfirmOptions): Promise<string | null> => {
  return useConfirmStore.getState().openConfirm(options) as Promise<string | null>;
};
