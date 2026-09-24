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

export interface OrderPadProps {
    catalogue: {
        rows: PadRowData[];
        /** 1-based row number of the first row on this page. */
        start_row: number;
        /** Opaque; pass back as ?after= to get the next page. */
        next_cursor: string | null;
    };
    page_size: number;
    /** Spend breaks and carriage-paid threshold for local recompute (OrderPadTotalsContext.php). */
    totals_context: TotalsContext;
}
