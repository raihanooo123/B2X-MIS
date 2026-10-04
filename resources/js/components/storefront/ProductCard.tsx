/**
 * A storefront grid card (05.15 §5.1): image, brand, name, the "from" price
 * inc or ex VAT, a stock label ("Only N left" at 10 or fewer, never a larger
 * figure, §5.3) and, where one tap is enough, quick add (§5.3a).
 */
import { Link, router } from '@inertiajs/react';
import { Check, ImageOff, Plus } from 'lucide-react';
import { useState } from 'react';

import { useAddCartLine } from '@/lib/api/orderPad';
import { vatLabel, type DisplayMode } from '@/lib/cart/display';
import { storefrontLinks } from '@/lib/storefront/links';
import { shelfPrice } from '@/lib/storefront/price';
import { cn } from '@/lib/utils';

export type StockLabel = 'in_stock' | 'low_stock' | 'backorder' | 'out_of_stock';

export interface ProductCardData {
    id: string;
    name: string;
    slug: string;
    brand: string | null;
    thumbnail_url: string | null;
    price: { unit_net_e4: number; tax_rate_bp: number; varies: boolean } | null;
    stock: StockLabel;
    /** 1–10 only: "Only N left" (05.15 §5.3). Never a larger figure. */
    stock_left: number | null;
    /** One tap adds the default pack (05.15 §5.3a); null means "choose on the product page". */
    quick_add: { sku_id: string; pack_code: string } | null;
}

const STOCK: Record<StockLabel, { label: string; dot: string; text: string }> = {
    in_stock: { label: 'In stock', dot: 'bg-emerald-600', text: 'text-emerald-800' },
    low_stock: { label: 'Low stock', dot: 'bg-amber-500', text: 'text-amber-800' },
    backorder: { label: 'Available to order', dot: 'bg-sky-600', text: 'text-sky-800' },
    out_of_stock: { label: 'Out of stock', dot: 'bg-slate-400', text: 'text-muted-foreground' },
};

export function StockBadge({ stock, left = null }: { stock: StockLabel; left?: number | null }) {
    const s = STOCK[stock];
    const label = stock === 'low_stock' && left !== null ? `Only ${left} left` : s.label;

    // Label and dot together: never colour alone (07 §8).
    return (
        <span className={cn('inline-flex items-center gap-1.5 text-xs font-medium', s.text)}>
            <span aria-hidden className={cn('size-2 rounded-full', s.dot)} />
            {label}
        </span>
    );
}

export function ProductCard({ card, mode }: { card: ProductCardData; mode: DisplayMode }) {
    return (
        <li className="group relative flex flex-col">
            <div className="relative aspect-square overflow-hidden rounded-2xl bg-muted/60 ring-offset-2 ring-offset-background transition-shadow group-hover:shadow-lg group-has-[a:focus-visible]:ring-2 group-has-[a:focus-visible]:ring-ring">
                {card.thumbnail_url ? (
                    <img
                        src={card.thumbnail_url}
                        alt=""
                        loading="lazy"
                        className="size-full object-contain p-5 mix-blend-multiply transition-transform duration-500 ease-out motion-reduce:transition-none motion-safe:group-hover:scale-105 sm:p-6"
                    />
                ) : (
                    <div className="flex size-full items-center justify-center text-muted-foreground">
                        <ImageOff className="size-8" aria-hidden />
                    </div>
                )}
                {card.stock === 'low_stock' && (
                    <span className="absolute left-2.5 top-2.5 rounded-full bg-background/95 px-2.5 py-1 text-[11px] font-semibold text-amber-800 shadow-sm">
                        {card.stock_left !== null ? `Only ${card.stock_left} left` : 'Low stock'}
                    </span>
                )}
            </div>
            <div className="flex flex-1 flex-col px-0.5 pt-3">
                {card.brand && <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{card.brand}</p>}
                <h3 className="mt-1 line-clamp-2 text-sm font-medium leading-snug text-foreground/90">
                    <Link href={storefrontLinks.product(card)} className="after:absolute after:inset-0 after:z-0 focus:outline-none">
                        {card.name}
                    </Link>
                </h3>
                <div className="mt-auto flex flex-wrap items-end justify-between gap-x-2 gap-y-1 pt-2.5">
                    {card.price ? (
                        <p className="leading-none">
                            {card.price.varies && <span className="mr-1 text-xs text-muted-foreground">From</span>}
                            <span className="text-lg font-bold tracking-tight tabular-nums">{shelfPrice(card.price.unit_net_e4, card.price.tax_rate_bp, mode)}</span>
                            <span className="mt-1 block text-[11px] text-muted-foreground">each, {vatLabel(mode)}</span>
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">Price on request</p>
                    )}
                    {card.stock !== 'low_stock' && <StockBadge stock={card.stock} left={card.stock_left} />}
                </div>
                {card.quick_add && <QuickAdd item={card.quick_add} name={card.name} />}
            </div>
        </li>
    );
}

/** 05.15 §5.3a: add one default pack without leaving the grid. */
function QuickAdd({ item, name }: { item: { sku_id: string; pack_code: string }; name: string }) {
    const add = useAddCartLine();
    const [done, setDone] = useState(false);

    const onClick = () => {
        add.mutate(
            { sku_id: item.sku_id, pack_code: item.pack_code, pack_qty: 1 },
            {
                onSuccess: () => {
                    setDone(true);
                    router.reload({ only: ['shell'] });
                    window.setTimeout(() => setDone(false), 2000);
                },
            },
        );
    };

    return (
        <div className="relative z-10 mt-3">
            <button
                type="button"
                onClick={onClick}
                disabled={add.isPending}
                aria-label={`Add ${name} to basket`}
                className={cn(
                    'inline-flex min-h-11 w-full items-center justify-center gap-1.5 rounded-full border text-sm font-semibold transition-colors disabled:opacity-60',
                    done ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-foreground/15 bg-background hover:border-primary hover:bg-primary hover:text-primary-foreground',
                )}
            >
                {done ? <Check className="size-4" aria-hidden /> : <Plus className="size-4" aria-hidden />}
                {done ? 'Added' : add.isPending ? 'Adding…' : 'Add to basket'}
            </button>
            {add.isError && (
                <p role="alert" className="mt-1 text-xs text-red-700">
                    {add.error.message || 'Could not add. Please try again.'}
                </p>
            )}
        </div>
    );
}
