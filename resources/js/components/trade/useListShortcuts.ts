/**
 * 05.16 §4 list shortcuts, only outside inputs and editable content, and
 * never overriding browser or system keys (no modifier combinations):
 *   /      focus the list search
 *   ?      open the shortcut help
 *   j / k  move the active row down / up (optional, approvals)
 *   Enter  open the active row — never approves anything
 * They can be switched off from the help dialog; the choice is per browser.
 */
import { useCallback, useEffect, useState } from 'react';

import { SEARCH_INPUT_ATTRIBUTE } from './FilterBar';

const DISABLED_KEY = 'b2b.shortcuts.disabled';

function typing(target: EventTarget | null): boolean {
    return target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
}

export function shortcutsDisabled(): boolean {
    try {
        return window.localStorage.getItem(DISABLED_KEY) === '1';
    } catch {
        return false;
    }
}

export function setShortcutsDisabled(disabled: boolean): void {
    try {
        if (disabled) {
            window.localStorage.setItem(DISABLED_KEY, '1');
        } else {
            window.localStorage.removeItem(DISABLED_KEY);
        }
    } catch {
        // Storage unavailable: the choice lasts for this page.
    }
}

export function useListShortcuts({ rowCount, onOpen }: { rowCount: number; onOpen?: (index: number) => void }) {
    const [active, setActive] = useState<number | null>(null);
    const [helpOpen, setHelpOpen] = useState(false);
    const [disabled, setDisabledState] = useState(false);

    useEffect(() => setDisabledState(shortcutsDisabled()), []);

    const setDisabled = useCallback((value: boolean) => {
        setShortcutsDisabled(value);
        setDisabledState(value);
    }, []);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (disabled || e.ctrlKey || e.metaKey || e.altKey || typing(e.target) || document.querySelector('[role="dialog"], [role="alertdialog"]')) {
                return;
            }
            if (e.key === '/') {
                const search = document.querySelector<HTMLInputElement>(`[${SEARCH_INPUT_ATTRIBUTE}]`);
                if (search) {
                    e.preventDefault();
                    search.focus();
                }
            } else if (e.key === '?') {
                e.preventDefault();
                setHelpOpen(true);
            } else if ((e.key === 'j' || e.key === 'k') && rowCount > 0) {
                e.preventDefault();
                setActive((current) => {
                    const from = current ?? -1;
                    const next = e.key === 'j' ? Math.min(rowCount - 1, from + 1) : Math.max(0, from - 1);
                    document.querySelector(`[data-row-index="${next}"]`)?.scrollIntoView({ block: 'nearest' });

                    return next;
                });
            } else if (e.key === 'Enter' && active !== null && onOpen) {
                e.preventDefault();
                onOpen(active);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [disabled, rowCount, active, onOpen]);

    return { active, helpOpen, setHelpOpen, disabled, setDisabled };
}
