/**
 * 05.1 §8.1's keyboard contract for the order pad, as DOM helpers.
 *
 * Tab / Shift+Tab reach only quantity fields because every other control
 * in a row (−/+ buttons, pack selector) is `tabIndex={-1}` — native tab
 * order, so leaving the last field tabs on out of the table and there is
 * nothing to trap focus. Enter (commitAndAdvance) moves to the next
 * row's field; past the last row it lands on "Add to cart".
 *
 * Quantity fields carry `data-qty-input`. Only the rendered layout
 * (table or cards) is in the DOM, so document order is row order.
 */

export const QTY_INPUT_ATTRIBUTE = 'data-qty-input';
export const ADD_TO_CART_ID = 'order-pad-add-to-cart';
export const SEARCH_INPUT_ID = 'order-pad-search';

function quantityFields(): HTMLInputElement[] {
    return Array.from(document.querySelectorAll<HTMLInputElement>(`input[${QTY_INPUT_ATTRIBUTE}]`)).filter((el) => !el.disabled);
}

/** Focus the quantity field `delta` rows from `from`. False if there is none. */
export function focusQuantityField(from: HTMLElement, delta: 1 | -1): boolean {
    const fields = quantityFields();
    const next = fields[fields.indexOf(from as HTMLInputElement) + delta];
    if (next === undefined) {
        return false;
    }
    next.focus();
    next.select();

    return true;
}

/** Enter: the next row's field, or "Add to cart" after the last row. */
export function commitAndAdvance(from: HTMLElement): void {
    if (!focusQuantityField(from, 1)) {
        const button = document.getElementById(ADD_TO_CART_ID);
        if (button instanceof HTMLButtonElement && !button.disabled) {
            button.focus();
        }
    }
}

/** A field where `/` is a character someone could mean to type. Quantity fields take digits only. */
function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement) || target.hasAttribute(QTY_INPUT_ATTRIBUTE)) {
        return false;
    }

    return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

/**
 * `/` focuses search from anywhere (05.1 §8.1), quantity fields
 * included — except in a text field, where `/` is just a character.
 * Returns the unsubscribe.
 */
export function bindSearchShortcut(): () => void {
    const onKeyDown = (e: KeyboardEvent) => {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || isTypingTarget(e.target)) {
            return;
        }
        const search = document.getElementById(SEARCH_INPUT_ID);
        if (search instanceof HTMLInputElement) {
            e.preventDefault();
            search.focus();
            search.select();
        }
    };
    window.addEventListener('keydown', onKeyDown);

    return () => window.removeEventListener('keydown', onKeyDown);
}
