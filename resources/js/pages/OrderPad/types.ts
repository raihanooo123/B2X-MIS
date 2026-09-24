import type { PadPack } from '@/lib/orderPad/display';
import type { TotalsContext } from '@/lib/pricing/localRecompute';

/** One SKU row, as OrderPadCatalogue serialises it (Inertia prop). */
export interface PadRowData {
    sku_id: string;
    sku_code: string;
    variant_label: string | null;
    product_name: string;
    thumbnail_url: string | null;
    /** Sellable packs, ascending by base_units. */
    packs: PadPack[];
    default_pack_code: string | null;
}

/** Echoed back normalised by OrderPadRequest; also the query-string keys. */
export interface PadFilters {
    q: string | null;
    /** Category slug; includes its subcategories. */
    category: string | null;
    /** Brand slug. */
    brand: string | null;
    in_stock: boolean;
}

export interface PadFacets {
    /** Tree order; `depth` for indenting. */
    categories: { slug: string; name: string; depth: number }[];
    brands: { slug: string; name: string }[];
}

export interface OrderPadProps {
    catalogue: {
        rows: PadRowData[];
        /** 1-based row number of the first row on this page. */
        start_row: number;
        /** Opaque; pass back as ?after= to get the next page. */
        next_cursor: string | null;
    };
    filters: PadFilters;
    facets: PadFacets;
    page_size: number;
    /** Spend breaks and carriage-paid threshold for local recompute (OrderPadTotalsContext.php). */
    totals_context: TotalsContext;
}
