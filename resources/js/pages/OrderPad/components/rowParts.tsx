/**
 * What one order pad row knows and the controls it is made of, shared by
 * the desktop table row (PadRow) and the mobile card (PadCard) so the two
 * layouts can never price, step or validate differently.
 *
 * Every money figure goes through lib/money.ts via lib/orderPad/display.ts
 * and lib/pricing/localRecompute.ts — no floating-point arithmetic here.
 *
 * Keyboard (05.1 §8.1), on the quantity field:
 *   Tab / Shift+Tab  next / previous quantity field — every other row
 *                    control is tabIndex -1, so native order does it
 *   ↑ / ↓            one pack more / fewer
 *   Enter            commit and move to the next row
 *   Esc              revert to the value the field had when focused
 *   Alt+↓            open the pack selector; closing it returns here
 */
import { ImageOff, Minus, Plus } from 'lucide-react';
import { useMemo, useRef, useState, type KeyboardEvent, type RefObject } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { BulkResolveEntry } from '@/lib/api/orderPad';
import { commitAndAdvance } from '@/lib/keyboard/tabOrder';
import { nextBreakPrompt, packBreakRows, packNoun, packPriceDisplay, type PadPack, type StockDisplay, type StockTone } from '@/lib/orderPad/display';
import { baseQtyOf, linePrice, pricingFromEntry, type LinePricing } from '@/lib/pricing/localRecompute';
import { cn } from '@/lib/utils';
import { useOrderPadStore, useRowDraft, useRowRejection } from '@/stores/orderPadStore';

import type { PadRowData } from '../types';

/** 06 §9.1 / AddCartLineRequest's own ceiling. */
const MAX_PACK_QTY = 1_000_000;
/** 05.1 §4.2: up to three break rows, the rest collapse to "more". */
const VISIBLE_BREAKS = 3;

/** `touch` is the mobile card: 44 px targets (05.1 §8.2). */
export type ControlSize = 'dense' | 'touch';

export function usePadRow(row: PadRowData, price: BulkResolveEntry | undefined) {
    const draft = useRowDraft(row.sku_id);
    const rejection = useRowRejection(row.sku_id);
    const setPack = useOrderPadStore((s) => s.setPack);
    const setQty = useOrderPadStore((s) => s.setQty);

    const packCode = draft.packCode ?? row.default_pack_code;
    const pack = row.packs.find((p) => p.code === packCode) ?? row.packs[0];

    const pricing = useMemo(() => pricingFromEntry(price), [price]);
    const baseQty = pack !== undefined && draft.packQty !== null ? baseQtyOf(draft.packQty, pack.base_units) : null;
    const line = pricing !== null && baseQty !== null ? linePrice(pricing, baseQty) : null;
    const prompt = pricing !== null && pack !== undefined && draft.packQty !== null ? nextBreakPrompt(pricing.breaks, pack, draft.packQty) : null;

    const [packOpen, setPackOpen] = useState(false);
    const qtyRef = useRef<HTMLInputElement>(null);

    return {
        draft,
        rejection,
        pack,
        pricing,
        line,
        prompt,
        packOpen,
        setPackOpen,
        qtyRef,
        selectPack: (code: string) => {
            const next = row.packs.find((p) => p.code === code);
            if (next) setPack(row, next);
        },
        changeQty: (qty: number | null) => {
            if (pack) setQty(row, pack, qty);
        },
        qtyLabel: `Quantity of ${row.sku_code} in ${pack ? packNoun(pack, 2) : 'packs'}`,
    };
}

export function Thumbnail({ url, alt, className }: { url: string | null; alt: string; className?: string }) {
    const [failed, setFailed] = useState(false);

    if (url === null || failed) {
        return (
            <div className={cn('flex size-9 shrink-0 items-center justify-center rounded border bg-muted text-muted-foreground', className)} aria-hidden>
                <ImageOff className="size-4" />
            </div>
        );
    }

    return (
        <img
            src={url}
            alt={alt}
            loading="lazy"
            decoding="async"
            width={36}
            height={36}
            onError={() => setFailed(true)}
            className={cn('size-9 shrink-0 rounded border object-cover', className)}
        />
    );
}

interface PackSelectorProps {
    packs: PadPack[];
    value: string | null;
    onChange: (code: string) => void;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Where focus goes when the list closes: the row's quantity field. */
    returnFocusTo: RefObject<HTMLInputElement>;
    size: ControlSize;
}

/**
 * 05.1 §4.2: single-pack SKUs render as static text, not a one-option
 * dropdown. Out of the tab order (Tab moves between quantities); reached
 * with Alt+↓ from the quantity field, or by pointer.
 */
export function PackSelector({ packs, value, onChange, open, onOpenChange, returnFocusTo, size }: PackSelectorProps) {
    if (packs.length === 0) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    if (packs.length === 1) {
        return <span className="whitespace-nowrap">{packs[0].label}</span>;
    }

    return (
        <Select value={value ?? undefined} onValueChange={onChange} open={open} onOpenChange={onOpenChange}>
            <SelectTrigger tabIndex={-1} className={cn('text-[13px]', size === 'touch' ? 'h-11' : 'h-8')} aria-label="Pack size">
                <SelectValue />
            </SelectTrigger>
            <SelectContent
                onCloseAutoFocus={(e) => {
                    if (returnFocusTo.current) {
                        e.preventDefault();
                        returnFocusTo.current.focus();
                    }
                }}
            >
                {packs.map((p) => (
                    <SelectItem key={p.code} value={p.code} className={cn('text-[13px]', size === 'touch' && 'min-h-11')}>
                        {p.label}
                        {p.base_units > 1 && <span className="ml-1 text-muted-foreground">· {p.base_units.toLocaleString('en-GB')}</span>}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

interface QuantityStepperProps {
    value: number | null;
    onChange: (qty: number | null) => void;
    disabled: boolean;
    label: string;
    inputRef: RefObject<HTMLInputElement>;
    /** Alt+↓; absent when the row has a single pack. */
    onOpenPacks?: () => void;
    invalid: boolean;
    size: ControlSize;
}

/**
 * In packs, stepping by one pack. Empty by default, never 0 (05.1 §4.2):
 * clearing the field or stepping down from 1 leaves it empty. The −/+
 * buttons are pointer and touch targets only; the keyboard steps with
 * ↑/↓, so they stay out of the tab order.
 */
export function QuantityStepper({ value, onChange, disabled, label, inputRef, onOpenPacks, invalid, size }: QuantityStepperProps) {
    const valueAtFocus = useRef<number | null>(null);

    const step = (delta: number) => {
        const next = (value ?? 0) + delta;
        onChange(next <= 0 ? null : Math.min(next, MAX_PACK_QTY));
    };

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.altKey && e.key === 'ArrowDown' && onOpenPacks) {
            e.preventDefault();
            onOpenPacks();
        } else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            step(e.key === 'ArrowUp' ? 1 : -1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            commitAndAdvance(e.currentTarget);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            onChange(valueAtFocus.current);
        }
    };

    const touch = size === 'touch';
    const buttonClass = touch ? 'size-11 [&_svg]:size-5' : 'size-8';

    return (
        <div className="flex items-center">
            <Button
                type="button"
                variant="outline"
                size="icon"
                className={cn(buttonClass, 'rounded-r-none')}
                onClick={() => step(-1)}
                disabled={disabled || value === null}
                aria-label="One fewer"
                tabIndex={-1}
            >
                <Minus />
            </Button>
            <Input
                ref={inputRef}
                data-qty-input=""
                inputMode="numeric"
                pattern="[0-9]*"
                autoComplete="off"
                enterKeyHint="next"
                aria-label={label}
                aria-invalid={invalid || undefined}
                aria-keyshortcuts={onOpenPacks ? 'Alt+ArrowDown' : undefined}
                disabled={disabled}
                value={value ?? ''}
                onFocus={(e) => {
                    valueAtFocus.current = value;
                    e.currentTarget.select();
                }}
                onChange={(e) => {
                    const digits = e.target.value.replace(/\D/g, '');
                    onChange(digits === '' ? null : Math.min(Number.parseInt(digits, 10), MAX_PACK_QTY));
                }}
                onKeyDown={onKeyDown}
                className={cn(
                    'rounded-none border-x-0 px-1 text-center tabular-nums shadow-none',
                    touch ? 'h-11 w-16 text-base' : 'h-8 w-14 text-[13px]',
                    invalid && 'border-red-500 bg-red-50',
                )}
            />
            <Button
                type="button"
                variant="outline"
                size="icon"
                className={cn(buttonClass, 'rounded-l-none')}
                onClick={() => step(1)}
                disabled={disabled}
                aria-label="One more"
                tabIndex={-1}
            >
                <Plus />
            </Button>
        </div>
    );
}

/** Pack price at the reached break, with the unit price beneath (05.1 §4.2). */
export function PackPrice({ pricing, pack, packQty, align = 'right' }: { pricing: LinePricing; pack: PadPack; packQty: number | null; align?: 'left' | 'right' }) {
    const display = packPriceDisplay(pricing.breaks, pack, packQty === null ? pack.base_units : baseQtyOf(packQty, pack.base_units));
    if (display === null) {
        return <span className="text-xs text-muted-foreground">Price unavailable</span>;
    }

    return (
        <div className={cn('tabular-nums', align === 'right' && 'text-right')}>
            <div className="font-medium">
                {display.pack}
                <span className="font-normal text-muted-foreground">/{packNoun(pack, 1)}</span>
            </div>
            {pack.base_units > 1 && <div className="text-xs text-muted-foreground">{display.unit}/unit</div>}
        </div>
    );
}

/** The break ladder in packs, the reached row marked (05.1 §5.2). */
export function BreakList({ pricing, pack, packQty }: { pricing: LinePricing; pack: PadPack; packQty: number | null }) {
    const rows = packBreakRows(pricing.breaks, pack);
    if (rows.length <= 1) {
        return <span className="text-xs text-muted-foreground">No volume breaks</span>;
    }

    let reachedIndex = -1;
    rows.forEach((r, i) => {
        if (packQty !== null && r.packQty <= packQty) reachedIndex = i;
    });

    return (
        <ul className="space-y-px text-xs tabular-nums">
            {rows.slice(0, VISIBLE_BREAKS).map((r, i) => (
                <li
                    key={r.packQty}
                    className={cn('-mx-1 flex justify-between gap-3 rounded-sm px-1', i === reachedIndex && 'bg-emerald-50 font-medium text-emerald-800')}
                    aria-current={i === reachedIndex ? 'true' : undefined}
                >
                    <span className="text-muted-foreground">{r.packQty.toLocaleString('en-GB')}+</span>
                    <span>
                        {r.packPrice}
                        {pack.base_units > 1 && <span className="text-muted-foreground"> · {r.unitPrice}</span>}
                    </span>
                </li>
            ))}
            {rows.length > VISIBLE_BREAKS && <li className="text-muted-foreground">+{rows.length - VISIBLE_BREAKS} more</li>}
        </ul>
    );
}

const TONE_CLASSES: Record<StockTone, { dot: string; text: string }> = {
    in: { dot: 'bg-emerald-500', text: 'text-emerald-700' },
    part: { dot: 'bg-amber-500', text: 'text-amber-700' },
    backorder: { dot: 'bg-sky-500', text: 'text-sky-700' },
    out: { dot: 'bg-red-500', text: 'text-red-700' },
    untracked: { dot: 'bg-zinc-300', text: 'text-muted-foreground' },
    unknown: { dot: 'bg-zinc-300', text: 'text-muted-foreground' },
};

/** Numeral plus label, always — colour is never the only signal (05.1 §4.2). */
export function StockCell({ display }: { display: StockDisplay }) {
    const tone = TONE_CLASSES[display.tone];

    return (
        <div className="leading-tight">
            {display.figure && <div className="font-medium tabular-nums">{display.figure}</div>}
            <div className={cn('flex items-center gap-1.5 text-xs', tone.text)}>
                <span className={cn('size-1.5 shrink-0 rounded-full', tone.dot)} aria-hidden />
                {display.label}
            </div>
            {display.detail && <div className="text-xs text-muted-foreground">{display.detail}</div>}
        </div>
    );
}

/** The next-break nudge, and — when bulk-add refused the line — why. */
export function RowNotes({ prompt, rejection }: { prompt: string | null; rejection: string | undefined }) {
    return (
        <>
            {rejection && (
                <p className="mt-0.5 max-w-56 text-[11px] leading-tight text-red-700" role="alert">
                    {rejection}
                </p>
            )}
            {prompt && <p className="mt-0.5 max-w-44 text-[11px] leading-tight text-emerald-700">{prompt}</p>}
        </>
    );
}
