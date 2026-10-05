/**
 * 05.16 §4 action toasts — ephemeral client UI state, so Zustand (CLAUDE.md
 * conventions). Announced through one polite live region (Toaster).
 * Actionable errors must also stay inline on the page: a toast disappears.
 */
import { create } from 'zustand';

export type ToastTone = 'success' | 'error' | 'info';

export interface Toast {
    id: number;
    tone: ToastTone;
    title: string;
    description?: string;
}

interface ToastState {
    toasts: Toast[];
    push: (toast: Omit<Toast, 'id'>) => number;
    dismiss: (id: number) => void;
}

let nextId = 1;

export const useToastStore = create<ToastState>((set) => ({
    toasts: [],
    push: (toast) => {
        const id = nextId++;
        // At most three on screen; the oldest goes first.
        set((state) => ({ toasts: [...state.toasts.slice(-2), { ...toast, id }] }));

        return id;
    },
    dismiss: (id) => set((state) => ({ toasts: state.toasts.filter((t) => t.id !== id) })),
}));

/** Show a toast from anywhere: `toast.success('Order approved')`. */
export const toast = {
    success: (title: string, description?: string) => useToastStore.getState().push({ tone: 'success', title, description }),
    error: (title: string, description?: string) => useToastStore.getState().push({ tone: 'error', title, description }),
    info: (title: string, description?: string) => useToastStore.getState().push({ tone: 'info', title, description }),
};
