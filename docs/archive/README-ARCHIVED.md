# ARCHIVED — superseded 2026-09-15

These documents are **no longer authoritative** and must not be used for schema
or architectural decisions. They are retained for history only.

Superseded by the canonical set in the parent directory: Docs 01+ (`02-domain-model-erd.md`,
`03-pricing-engine.md`, `04-inventory-ledger.md`, and subsequent documents).

## Why superseded — two decisions were reversed

1. **Variant model.** ADR-004 in `00-README-and-decisions.md` states "there is no
   variant table — a colour or size is its own product." **Reversed.** The canonical
   model supports both: `products` (parent) to `skus` (stockable), where a flat item
   is a single-SKU product. See 02 §5.2.

2. **Price break denomination.** `04-pricing-engine.md` stores `min_quantity` **in packs**.
   **Reversed.** Breaks are denominated in **base units** (`min_base_qty`). Pack-denominated
   breaks cannot distinguish 10 inners (120 units) from 10 outers (1,440 units), and break
   whenever a pack size changes. See 03 §3.1.

Additional changes not present in this archived set: `e4` sub-penny price precision,
batch/expiry and serial tracking in the initial migration, `order_spend_breaks`,
collection-at-placement allocation, advisory bin locations.
