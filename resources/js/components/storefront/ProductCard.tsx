/**
 * A storefront grid card (05.15 §5.1): image, brand, name, the "from" price
 * inc or ex VAT, and a stock label — never a stock figure (§12 Q2).
 */
import { Link } from '@inertiajs/react';
import { ImageOff } from 'lucide-react';

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
}

const STOCK: Record<StockLabel, { label: string; dot: string; text: string }> = {
    in_stock: { label: 'In stock', dot: 'bg-emerald-600', text: 'text-emerald-800' },
    low_stock: { label: 'Low stock', dot: 'bg-amber-500', text: 'text-amber-800' },
    backorder: { label: 'Available to order', dot: 'bg-sky-600', text: 'text-sky-800' },
    out_of_stock: { label: 'Out of stock', dot: 'bg-slate-400', text: 'text-muted-foreground' },
};

export function StockBadge({ stock }: { stock: StockLabel }) {
    const s = STOCK[stock];

    // Label and dot together: never colour alone (07 §8).
    return (
        <span className={cn('inline-flex items-center gap-1.5 text-xs font-medium', s.text)}>
            <span aria-hidden className={cn('size-2 rounded-full', s.dot)} />
            {s.label}
        </span>
    );
}

export function ProductCard({ card, mode }: { card: ProductCardData; mode: DisplayMode }) {
    return (
        <li className="group relative flex flex-col overflow-hidden rounded-xl border bg-card transition-shadow hover:shadow-md">
            <div className="aspect-square overflow-hidden bg-muted/50">
                {card.thumbnail_url ? (
                    <img src={card.thumbnail_url} alt="" loading="lazy" className="size-full object-cover transition-transform duration-300 group-hover:scale-[1.03]" />
                ) : (
                    <div className="flex size-full items-center justify-center text-muted-foreground">
                        <ImageOff className="size-8" aria-hidden />
                    </div>
                )}
            </div>
            <div className="flex flex-1 flex-col gap-1 p-3 sm:p-4">
                {card.brand && <p className="text-xs uppercase tracking-wide text-muted-foreground">{card.brand}</p>}
                <h3 className="line-clamp-2 text-sm font-medium leading-snug">
                    <Link href={storefrontLinks.product(card)} className="after:absolute after:inset-0 focus:outline-none">
                        {card.name}
                    </Link>
                </h3>
                <div className="mt-auto flex flex-wrap items-end justify-between gap-2 pt-2">
                    {card.price ? (
                        <p className="leading-tight">
                            {card.price.varies && <span className="text-xs text-muted-foreground">From </span>}
                            <span className="text-base font-semibold tabular-nums">{shelfPrice(card.price.unit_net_e4, card.price.tax_rate_bp, mode)}</span>
                            <span className="block text-[11px] text-muted-foreground">each, {vatLabel(mode)}</span>
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">Price on request</p>
                    )}
                    <StockBadge stock={card.stock} />
                </div>
            </div>
        </li>
    );
}
