/**
 * One order pad row (05.1 §4.2): row number, thumbnail, SKU code, product
 * name, pack selector, price, break table, stock, quantity, line total.
 *
 * Prices come from /pricing/bulk-resolve, stock from /stock/availability;
 * both are passed in so the page fetches once for all rows. Every money
 * figure goes through lib/money.ts via lib/orderPad/display.ts — no
 * floating-point arithmetic here.
 *
 * Typing a quantity recomputes locally (05.1 §5.1): the price cell moves
 * to the reached break and marks it, the line total is the item net from
 * lib/pricing/localRecompute.ts (before any order-wide spend discount,
 * which the footer shows), and a prompt names the next cheaper break
 * (05.1 §5.2). No request per keystroke.
 */
import { ImageOff, Minus, Plus } from 'lucide-react';
import { memo, useMemo, useState, type KeyboardEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { TableCell, TableRow } from '@/components/ui/table';
import type { BulkResolveEntry, StockAvailabilityEntry } from '@/lib/api/orderPad';
import { formatMinor } from '@/lib/money';
import { nextBreakPrompt, packBreakRows, packNoun, packPriceDisplay, stockDisplay, type PadPack, type StockTone } from '@/lib/orderPad/display';
import { baseQtyOf, linePrice, pricingFromEntry, type LinePricing } from '@/lib/pricing/localRecompute';
import { cn } from '@/lib/utils';
import { useOrderPadStore, useRowDraft } from '@/stores/orderPadStore';

import type { PadRowData } from '../types';

/** 06 §9.1 / AddCartLineRequest's own ceiling. */
const MAX_PACK_QTY = 1_000_000;
/** 05.1 §4.2: up to three break rows, the rest collapse to "more". */
const VISIBLE_BREAKS = 3;

interface PadRowProps {
    row: PadRowData;
    rowNumber: number;
    price: BulkResolveEntry | undefined;
    priceLoading: boolean;
    stock: StockAvailabilityEntry | undefined;
    stockLoading: boolean;
}

export const PadRow = memo(function PadRow({ row, rowNumber, price, priceLoading, stock, stockLoading }: PadRowProps) {
    const draft = useRowDraft(row.sku_id);
    const setPack = useOrderPadStore((s) => s.setPack);
    const setQty = useOrderPadStore((s) => s.setQty);

    const packCode = draft.packCode ?? row.default_pack_code;
    const pack = row.packs.find((p) => p.code === packCode) ?? row.packs[0];

    const pricing = useMemo(() => pricingFromEntry(price), [price]);
    const baseQty = pack !== undefined && draft.packQty !== null ? baseQtyOf(draft.packQty, pack.base_units) : null;
    const line = pricing !== null && baseQty !== null ? linePrice(pricing, baseQty) : null;
    const prompt = pricing !== null && pack !== undefined && draft.packQty !== null ? nextBreakPrompt(pricing.breaks, pack, draft.packQty) : null;

    return (
        <TableRow className="text-[13px]">
            <TableCell className="w-10 py-1.5 pr-0 text-right tabular-nums text-muted-foreground">{rowNumber}</TableCell>

            <TableCell className="w-12 py-1.5">
                <Thumbnail url={row.thumbnail_url} alt={row.product_name} />
            </TableCell>

            <TableCell className="w-28 whitespace-nowrap py-1.5 font-mono text-xs">{row.sku_code}</TableCell>

            <TableCell className="min-w-48 py-1.5">
                <div className="font-medium leading-tight">{row.product_name}</div>
                {row.variant_label && <div className="text-xs leading-tight text-muted-foreground">{row.variant_label}</div>}
            </TableCell>

            <TableCell className="w-40 py-1.5">
                <PackSelector
                    packs={row.packs}
                    value={pack?.code ?? null}
                    onChange={(code) => {
                        const next = row.packs.find((p) => p.code === code);
                        if (next) setPack(row.sku_id, code, next.base_units);
                    }}
                />
            </TableCell>

            {pack === undefined ? (
                <TableCell colSpan={2} className="py-1.5 text-xs text-muted-foreground">
                    No sellable pack
                </TableCell>
            ) : (
                <PriceCells price={price} pricing={pricing} loading={priceLoading} pack={pack} packQty={draft.packQty} />
            )}

            <TableCell className="w-32 py-1.5">
                {stockLoading ? <Skeleton className="h-4 w-20" /> : pack && <StockCell display={stockDisplay(stock, pack)} />}
            </TableCell>

            <TableCell className="w-32 py-1.5">
                <QuantityStepper
                    value={draft.packQty}
                    onChange={(qty) => pack && setQty(row.sku_id, qty, pack.base_units)}
                    disabled={pack === undefined}
                    label={`Quantity of ${row.sku_code} in ${pack ? packNoun(pack, 2) : 'packs'}`}
                />
                {prompt && <div className="mt-0.5 max-w-40 text-[11px] leading-tight text-emerald-700">{prompt}</div>}
            </TableCell>

            <TableCell className="w-24 py-1.5 text-right tabular-nums">
                {line !== null ? (
                    <span className="font-medium">{formatMinor(line.itemNetMinor)}</span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>
        </TableRow>
    );
});

function Thumbnail({ url, alt }: { url: string | null; alt: string }) {
    const [failed, setFailed] = useState(false);

    if (url === null || failed) {
        return (
            <div className="flex size-9 items-center justify-center rounded border bg-muted text-muted-foreground" aria-hidden>
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
            className="size-9 rounded border object-cover"
        />
    );
}

/** 05.1 §4.2: single-pack SKUs render as static text, not a one-option dropdown. */
function PackSelector({ packs, value, onChange }: { packs: PadPack[]; value: string | null; onChange: (code: string) => void }) {
    if (packs.length === 0) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    if (packs.length === 1) {
        return <span className="whitespace-nowrap">{packs[0].label}</span>;
    }

    return (
        <Select value={value ?? undefined} onValueChange={onChange}>
            <SelectTrigger className="h-8 text-[13px]" aria-label="Pack size">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {packs.map((p) => (
                    <SelectItem key={p.code} value={p.code} className="text-[13px]">
                        {p.label}
                        {p.base_units > 1 && <span className="ml-1 text-muted-foreground">· {p.base_units.toLocaleString('en-GB')}</span>}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

interface PriceCellsProps {
    price: BulkResolveEntry | undefined;
    pricing: LinePricing | null;
    loading: boolean;
    pack: PadPack;
    packQty: number | null;
}

function PriceCells({ price, pricing, loading, pack, packQty }: PriceCellsProps) {
    if (loading && price === undefined) {
        return (
            <>
                <TableCell className="w-28 py-1.5">
                    <Skeleton className="ml-auto h-4 w-16" />
                    <Skeleton className="ml-auto mt-1 h-3 w-12" />
                </TableCell>
                <TableCell className="w-44 py-1.5">
                    <Skeleton className="h-3 w-28" />
                    <Skeleton className="mt-1 h-3 w-24" />
                </TableCell>
            </>
        );
    }

    const display = pricing === null ? null : packPriceDisplay(pricing.breaks, pack, packQty === null ? pack.base_units : baseQtyOf(packQty, pack.base_units));

    if (pricing === null || display === null) {
        return (
            <TableCell colSpan={2} className="py-1.5 text-xs text-muted-foreground" title={price && 'error' in price ? price.error.message : undefined}>
                Price unavailable
            </TableCell>
        );
    }

    const rows = packBreakRows(pricing.breaks, pack);
    const noun = packNoun(pack, 1);
    // The crossed break is marked (05.1 §5.2): the last row the typed quantity reaches.
    let reachedIndex = -1;
    rows.forEach((r, i) => {
        if (packQty !== null && r.packQty <= packQty) reachedIndex = i;
    });

    return (
        <>
            <TableCell className="w-28 py-1.5 text-right tabular-nums">
                <div className="font-medium">
                    {display.pack}
                    <span className="font-normal text-muted-foreground">/{noun}</span>
                </div>
                {pack.base_units > 1 && <div className="text-xs text-muted-foreground">{display.unit}/unit</div>}
            </TableCell>
            <TableCell className="w-44 py-1.5">
                {rows.length <= 1 ? (
                    <span className="text-xs text-muted-foreground">No volume breaks</span>
                ) : (
                    <ul className="space-y-px text-xs tabular-nums">
                        {rows.slice(0, VISIBLE_BREAKS).map((r, i) => (
                            <li
                                key={r.packQty}
                                className={cn('flex justify-between gap-3 rounded-sm px-1 -mx-1', i === reachedIndex && 'bg-emerald-50 font-medium text-emerald-800')}
                                aria-current={i === reachedIndex ? 'true' : undefined}
                            >
                                <span className="text-muted-foreground">{r.packQty.toLocaleString('en-GB')}+</span>
                                <span>
                                    {r.packPrice}
                                    {pack.base_units > 1 && <span className="text-muted-foreground"> · {r.unitPrice}</span>}
                                </span>
                            </li>
                        ))}
                        {rows.length > VISIBLE_BREAKS && (
                            <li className="text-muted-foreground">+{rows.length - VISIBLE_BREAKS} more</li>
                        )}
                    </ul>
                )}
            </TableCell>
        </>
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
function StockCell({ display }: { display: ReturnType<typeof stockDisplay> }) {
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

/**
 * In packs, stepping by one pack. Empty by default, never 0 (05.1 §4.2):
 * clearing the field or stepping down from 1 leaves it empty. ↑/↓ step
 * too (05.1 §8.1).
 */
function QuantityStepper({ value, onChange, disabled, label }: { value: number | null; onChange: (qty: number | null) => void; disabled: boolean; label: string }) {
    const step = (delta: number) => {
        const next = (value ?? 0) + delta;
        onChange(next <= 0 ? null : Math.min(next, MAX_PACK_QTY));
    };

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            step(e.key === 'ArrowUp' ? 1 : -1);
        }
    };

    return (
        <div className="flex items-center">
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-8 rounded-r-none"
                onClick={() => step(-1)}
                disabled={disabled || value === null}
                aria-label="One fewer"
                tabIndex={-1}
            >
                <Minus />
            </Button>
            <Input
                inputMode="numeric"
                pattern="[0-9]*"
                autoComplete="off"
                aria-label={label}
                disabled={disabled}
                value={value ?? ''}
                onChange={(e) => {
                    const digits = e.target.value.replace(/\D/g, '');
                    onChange(digits === '' ? null : Math.min(Number.parseInt(digits, 10), MAX_PACK_QTY));
                }}
                onKeyDown={onKeyDown}
                className="h-8 w-14 rounded-none border-x-0 px-1 text-center text-[13px] tabular-nums shadow-none"
            />
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-8 rounded-l-none"
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
