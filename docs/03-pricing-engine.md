# Pricing Engine Specification

**B2B Wholesale & Distribution Platform**

| | |
|---|---|
| Document | 03 — Pricing Engine Spec |
| Status | Draft for review — **swept for PostgreSQL 16** |
| Depends on | 02 — Domain Model & ERD (§6 Pricing & Tax) |
| Amends | 02 §6.4 (`e4` precision, §3.3 — **applied**). This doc's §6.3 is superseded by §7A.4 |
| Platform note | Queries and index names below are PostgreSQL 16. Where the engine's behaviour changed with the platform it is called out inline |
| Feeds into | 05 — Module Specs (order pad, quotes), 06 — API Contract |

---

## 1. Purpose

The pricing engine answers one question: **given a SKU, a customer, a quantity and a moment in time, what is the price?**

It is the highest-risk component in the system. A bug here is silent — no exception, no failed request, just wrong money on invoices that have already been sent. It is also the component the reference system lacks entirely: LTC has one flat price per SKU, no volume breaks, no customer pricing, and handles negotiation by inviting buyers to phone up.

This document is the contract for that component. No pricing code is written that is not described here.

---

## 2. Terminology

| Term | Meaning |
|---|---|
| **Base unit** | The SKU's atomic sellable unit — one grater, one metre, one kilogram |
| **Pack** | A transaction unit containing `base_units` base units. Each / inner / outer / pallet |
| **Break** | A quantity threshold at which the unit price changes |
| **Price list** | A dated, scoped collection of break rows |
| **Scope** | What a price list applies to: `base`, `tier`, `company`, `promotion` |
| **Resolution** | Selecting exactly one applicable price from all candidates |
| **Snapshot** | The resolved price copied immutably onto an order line |
| **Net** | Excluding VAT. All stored prices are net |

---

## 3. Core decisions

### 3.1 DECISION: breaks are denominated in base units, not packs

This closes the conflict between this document set and the earlier `04-pricing-engine.md`, which stored `min_quantity` **in packs**.

**Breaks are stored in base units.** `price_list_items.min_base_qty`.

The argument is a worked failure case. Grater `LTC1179`, sold in three packs:

| Pack | `base_units` |
|---|---|
| Each | 1 |
| Inner | 12 |
| Outer | 144 |

Under **pack-denominated** breaks, a break row saying "10+ packs → 6% off" means:

- a customer ordering 10 inners (**120 units**) gets 6% off
- a customer ordering 10 outers (**1,440 units**) gets the same 6% off

Twelve times the volume, identical discount. The break table cannot distinguish them, because "10" means something different depending on which pack row it is attached to. To fix it you need a separate break table per pack — at which point a pack size changing, or a new pack tier being added, invalidates every break attached to it.

Under **base-unit** breaks, one table serves every pack:

| `min_base_qty` | `unit_price` |
|---|---|
| 1 | £0.9800 |
| 144 | £0.9200 |
| 1,440 | £0.8600 |

- 10 inners = 120 base units → £0.9800
- 1 outer = 144 base units → £0.9200
- 10 outers = 1,440 base units → £0.8600

Correct in every case, and **unchanged if pack sizes change**, because the break is a property of volume, not of packaging. A customer permitted to buy eaches is priced on the same ladder as one buying pallets.

Consequences:

- Breaks are pack-agnostic. Adding a "half-pallet of 720" pack requires no pricing change at all.
- The UI must translate: the buyer sees "5+ outers £0.92 each" computed from base-unit data, not stored that way.
- Every quantity entering the engine is `base_qty`, per Doc 02 §2.3. The engine never receives a pack quantity.

### 3.2 Prices are resolved, never stored on the product

There is no `price` column on `products` or `skus`. Reasons:

1. Per-customer contract pricing and break tables both need a second mechanism anyway, so a "simple" base price column buys nothing and creates two sources of truth.
2. A dated price list gives free price history and scheduled changes.
3. A `base` scope list always exists, so there is always a fallback and resolution never returns nothing for an active SKU.

### 3.3 ⚠️ AMENDMENT TO DOC 02: unit prices need sub-penny precision

**Doc 02 §6.4 declares `unit_price_minor BIGINT` (whole pence). This is insufficient and must change before the migration is written.**

The failure case. Grater at £0.98 with a 6% volume break:

```
0.98 × 0.94 = 0.9212
```

Stored in whole pence that rounds to `92`. On an order of 1,440 units:

| Storage | Line total | Error |
|---|---|---|
| Pence (`92`) | £1,324.80 | — |
| True (`0.9212`) | £1,326.53 | **£1.73 under** |

£1.73 on one line looks trivial. It is not: the customer negotiated 6%, the invoice shows 6.12%, and on a low-value high-volume catalogue like this one — where the median SKU is under £2 — the error is systematic and always in the same direction. Wholesale price lists are routinely quoted to four decimal places precisely because of this.

**Change:** unit prices are stored as **ten-thousandths of a pound**, in a `BIGINT` column named `unit_price_e4`.

```sql
-- REVISED from Doc 02 §6.4, and now in PostgreSQL 16
CREATE TABLE price_list_items (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  price_list_id bigint      NOT NULL REFERENCES price_lists (id) ON DELETE CASCADE,
  sku_id        bigint      NOT NULL REFERENCES skus (id)        ON DELETE CASCADE,
  min_base_qty  integer     NOT NULL DEFAULT 1,
  unit_price_e4 bigint      NOT NULL,          -- £0.9212 stored as 9212
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT price_list_items_uq UNIQUE (price_list_id, sku_id, min_base_qty),
  CONSTRAINT price_list_items_qty_chk   CHECK (min_base_qty >= 1),
  CONSTRAINT price_list_items_price_chk CHECK (unit_price_e4 >= 0)
);

-- the bulk order-pad path: covering via INCLUDE, not via the key
CREATE INDEX price_list_items_resolve_idx
  ON price_list_items (price_list_id, sku_id, min_base_qty DESC)
  INCLUDE (unit_price_e4);

-- the admin price editor and audit path
CREATE INDEX price_list_items_by_sku_idx
  ON price_list_items (sku_id, price_list_id, min_base_qty DESC)
  INCLUDE (unit_price_e4);
```

The same change applies to `order_lines.unit_price_net_minor` → **`unit_price_net_e4`**, and to `sku_costs.*` (landed cost per unit has the identical problem). Order and line *totals* stay in whole pence (`_minor`) — they are what gets invoiced, and an invoice cannot show fractional pence.

**Scale boundary rule:** `e4` for anything **per unit**, `minor` (pence) for anything **per line or per document**. The conversion happens exactly once, at line level, in §6.

This is a good advertisement for writing the spec before the migration. Found on paper, it is a column definition. Found after launch, it is a data migration across every historical order line, with no way to recover the true prices that were rounded away.

### 3.4 Resolved prices are snapshotted immutably

Once an order line exists, its price never re-resolves. The line carries `unit_price_net_e4`, `tax_rate_bp`, `price_source`, `price_list_id`, `price_list_item_id` and `applied_break_qty`. A ten-year-old invoice reprints identically even if every price list has since been deleted.

---

## 4. Resolution algorithm

### 4.1 Signature

```php
ResolvedPrice resolve(
    int $skuId,
    ?int $companyId,        // null = guest / anonymous
    int $baseQty,
    ?CarbonImmutable $at = null,   // defaults to now
    string $currency = 'GBP',
);
```

### 4.2 Precedence

Candidate lists are ranked, **highest wins**. First rank with any matching break row terminates the search.

| Rank | Scope | Condition | `price_source` |
|---|---|---|---|
| 1 | `company` | `has_contract = 1`, company matches | `contract` |
| 2 | `company` | `has_contract = 0`, company matches | `customer` |
| 3 | `promotion` | within validity window, SKU eligible | `promotion` |
| 4 | `tier` | company's `price_tier_id` matches | `tier` |
| 5 | `base` | always | `base` |

Rules:

- A guest or company with no tier resolves at rank 5 only.
- Rank 3 sits **above** tier deliberately: a promotion is a decision to undercut standard pricing for a period. It sits **below** customer pricing deliberately: a negotiated contract is not overridden by a general campaign.
- **A promotion never produces a price higher than the rank it displaces.** Enforced by §4.5.
- Within a rank, ties break on `price_lists.priority DESC`, then `price_lists.id DESC`. Deterministic, because MySQL cannot prevent overlapping validity windows (Doc 02 §6.3).

### 4.3 Break selection

Within the winning list, select the row with the **greatest `min_base_qty` that is `<=` the requested `base_qty`**.

If no row qualifies (every break exceeds the quantity), that list **does not match** and resolution falls through to the next rank. This matters: a customer list holding only a `min_base_qty = 1000` row must not block a 50-unit order from reaching tier pricing.

### 4.4 Query

Single round trip for one SKU:

```sql
SELECT pli.price_list_id,
       pli.id           AS price_list_item_id,
       pli.min_base_qty,
       pli.unit_price_e4,
       pl.scope,
       pl.has_contract,
       pl.priority
FROM   price_list_items pli
JOIN   price_lists pl ON pl.id = pli.price_list_id
WHERE  pli.sku_id = :sku_id
  AND  pli.min_base_qty <= :base_qty
  AND  pl.status = 'active'
  AND  pl.currency = :currency
  AND  pl.validity @> :at::timestamptz
  AND  (
         pl.scope = 'base'
      OR (pl.scope = 'tier'      AND pl.price_tier_id = :tier_id)
      OR (pl.scope = 'company'   AND pl.company_id    = :company_id)
      OR (pl.scope = 'promotion' AND pl.id IN (:eligible_promo_list_ids))
       )
ORDER BY CASE
           WHEN pl.scope = 'company'   AND pl.has_contract = 1 THEN 1
           WHEN pl.scope = 'company'                           THEN 2
           WHEN pl.scope = 'promotion'                         THEN 3
           WHEN pl.scope = 'tier'                              THEN 4
           ELSE 5
         END,
         pl.priority DESC,
         pl.id DESC,
         pli.min_base_qty DESC
LIMIT 1;
```

> **Note on the index.** This query leads on `sku_id`, so it uses `price_list_items_by_sku_idx`, not `price_list_items_resolve_idx`. The latter is for the **bulk** path in §8, which supplies `price_list_id = ANY(...)`. Both exist because they serve genuinely different access patterns — Doc 02 §6.4 point 4.
>
> **`pl.validity @> :at` replaces the sentinel-date predicate.** The MySQL edition used `:at BETWEEN valid_from AND valid_to` with `1970-01-01`/`9999-12-31` sentinels, because a nullable upper bound would have forced `OR valid_to IS NULL` and defeated the index. A `tstzrange` containment check is both natural and GiST-indexable (02 §6.3), so the sentinel convention is gone.
>
> **The status and scope predicates are now index predicates, not key columns.** `price_lists_company_idx` and `price_lists_tier_idx` are partial on `status = 'active'` with the scope fixed, so candidate-list resolution scans only live rows of the relevant scope.

Candidate promotion list ids are resolved separately and cached, because promotion eligibility involves category and brand rules that do not belong in this query.

### 4.5 The `ResolvedPrice` object

```php
final readonly class ResolvedPrice
{
    public int     $skuId;
    public int     $baseQty;
    public int     $unitPriceE4;        // per base unit, £ × 10,000
    public string  $priceSource;        // contract|customer|promotion|tier|base|manual
    public ?int    $priceListId;
    public ?int    $priceListItemId;
    public int     $appliedBreakQty;
    public int     $taxRateBp;
    public ?int    $nextBreakQty;       // null if already at the best break
    public ?int    $nextBreakUnitPriceE4;
    public ?int    $unitCostE4;         // for margin, admin/rep contexts only
    public bool    $promotionCapped;    // true if §4.5 cap fired
}
```

`nextBreakQty` and `nextBreakUnitPriceE4` exist so the order pad can show *"add 24 more, pay £0.92"* without a second request. They are read from the same result set.

**The promotion cap.** If the winning rank is `promotion` and its price exceeds what rank 4 or 5 would have produced, the engine discards the promotion, resolves again ignoring promotions, and sets `promotionCapped = true`. A campaign must never cost a customer more than not having it. This costs one extra query on a rare path.

### 4.6 Failure modes

| Condition | Behaviour |
|---|---|
| SKU has no `base` list row | **Hard error.** SKU cannot be sold. Blocked at activation by a validation rule, and surfaced in the data-quality dashboard |
| SKU not active | `NotPurchasableException`; SKU never reaches the resolver from the storefront |
| `base_qty` below SKU `moq_base_qty` | Cart-level validation, not a pricing error. The engine still returns a price — quotes and admin need it |
| Currency has no list | `PriceUnavailableForCurrencyException` |
| `base_qty = 0` | `InvalidArgumentException`. Never a valid input |

The engine **never** returns null, and never silently substitutes a default. Both would put a wrong number on an invoice.

---

## 5. Pack price display

The engine works in base units; buyers think in packs. Translation happens in the presentation layer only.

```
pack_price_e4 = unit_price_e4 × pack.base_units
pack_price_display = round_half_up(pack_price_e4 / 100)   → pence
```

Worked example — grater, outer of 144, customer ordering 10 outers (1,440 base units):

| | Value |
|---|---|
| Resolved `unit_price_e4` | 8600 (£0.8600) |
| Pack price | 8600 × 144 = 1,238,400 e4 = **£123.84** per outer |
| Line total | £1,238.40 |

The pack price shown is derived for display and **is never stored or used for arithmetic.** The line total is computed from `unit_price_e4 × base_qty`, not from the rounded pack price × pack count. Computing from a rounded intermediate is how a line total ends up disagreeing with the invoice by a few pence per line — a small error that customers do notice, because they check.

---

## 6. Money arithmetic & rounding

### 6.1 The single rounding boundary

Rounding happens **once per order line**, converting `e4` to pence:

```
line_net_minor = round_half_up( unit_price_net_e4 × base_qty / 100 )
```

Before that point every value stays in `e4`. After it, everything is whole pence. No intermediate rounding, ever.

### 6.2 Half-up, and why

`round_half_up` — 0.5 rounds away from zero. Chosen over banker's rounding because it matches what UK accounting software and customers expect, and because our rounding volume is one operation per line rather than millions of operations where statistical bias would matter.

Implementation uses integer arithmetic only. No `float` appears anywhere in the pricing path; this is enforced by a static analysis rule and a test that asserts no float casts in the `Pricing` namespace.

```php
function roundHalfUpDiv(int $numerator, int $denominator): int
{
    $q = intdiv($numerator, $denominator);
    $r = $numerator % $denominator;
    return ($r * 2 >= $denominator) ? $q + 1 : $q;
}
```

### 6.3 Order of operations on a line

```
1. unit_price_net_e4          ← resolver
2. gross_line_e4              = unit_price_net_e4 × base_qty
3. line_discount_e4           ← coupon / manual override (see §7)
4. net_line_e4                = gross_line_e4 − line_discount_e4
5. line_net_minor             = round_half_up(net_line_e4 / 100)      ← ONLY rounding
6. line_tax_minor             = round_half_up(line_net_minor × tax_rate_bp / 10000)
7. line_gross_minor           = line_net_minor + line_tax_minor
```

### 6.4 Order totals

```
subtotal_net_minor = Σ line_net_minor
tax_minor          = Σ line_tax_minor        ← sum of per-line tax, NOT tax on the subtotal
total_gross_minor  = subtotal_net_minor + shipping_net_minor + tax_minor − discount_net_minor
```

VAT is summed from the lines rather than recomputed on the subtotal. The two differ by a penny or two when lines have mixed rates, and HMRC expects per-line VAT on the invoice. The invoice and the order record must agree exactly, so they are computed the same way once.

---

## 7. Discounts, coupons and manual overrides

Distinct from the resolution chain and applied **after** it.

| Mechanism | Where it applies | Stacks with breaks? |
|---|---|---|
| Promotion price list | Inside resolution, rank 3 | It *is* a break table; replaces, never stacks |
| Coupon, percentage | Line or order level, post-resolution | Yes |
| Coupon, fixed amount | Order level, apportioned across lines by `line_net_minor` | Yes |
| Manual override | Line level, admin/rep only | Replaces the resolved price; `price_source = 'manual'` |

Rules:

- **At most one coupon per order** at launch. Stacking rules are a Phase 2 decision and would need their own precedence table.
- Fixed-amount order discounts are apportioned proportionally, with any remainder pence assigned to the **largest** line. This keeps `Σ line_discount = order_discount` exactly — otherwise the order total and the sum of its lines disagree, which breaks reconciliation and the accounting export.
- A manual override requires a reason code and is written to the price audit log with the actor. Reps overriding price is a normal wholesale behaviour and must be visible, not prevented.
- No discount may produce a negative line. Clamped at zero with a validation error.

---

## 7A. Order-wide spend breaks — the second pass

Item-level pricing and order-wide spend thresholds are **both** supported, in a fixed order: items first, order second. Schema in 02 §6.7.

### 7A.1 Why two passes

A spend break is evaluated against a subtotal, and the subtotal does not exist until every line has resolved. There is no ordering in which these combine into one pass, so the engine has two and the boundary between them is explicit rather than emergent.

### 7A.2 Selection

Once per order, after pass 1:

```sql
SELECT id, discount_type, discount_rate_bp, discount_amount_minor, max_discount_minor
FROM   order_spend_breaks
WHERE  status = 'active'
  AND  currency = :currency
  AND  validity @> :at::timestamptz
  AND  min_subtotal_minor <= :qualifying_subtotal_minor
  AND  ( scope = 'global'
      OR (scope = 'tier'    AND price_tier_id = :tier_id)
      OR (scope = 'company' AND company_id    = :company_id) )
ORDER BY CASE scope WHEN 'company' THEN 1 WHEN 'tier' THEN 2 ELSE 3 END,
         priority DESC, min_subtotal_minor DESC, id DESC
LIMIT 1;
```

Uses `order_spend_breaks_resolve_idx` — partial on `status = 'active'` with a wide `INCLUDE`, so the once-per-order lookup is a single index-only scan. **Exactly one break applies.** They never stack.

An `EXCLUDE USING gist` constraint now makes two active breaks at the same threshold for the same audience in overlapping windows **impossible to persist** (02 §6.7), so the `priority`/`id DESC` tie-break is a determinism guarantee rather than a defence against bad data.

`qualifying_subtotal_minor` excludes shipping, VAT, and — unless `applies_to_contract_lines = 1` — every line whose `price_source = 'contract'`.

### 7A.3 Apportionment

The discount is an order-level amount but **must** be pushed down to the lines. Two reasons, both hard:

1. **VAT.** Lines carry different rates. An unapportioned order discount cannot produce correct per-line VAT, and the invoice must show per-line VAT.
2. **Margin.** An unapportioned discount makes per-line and per-SKU margin reporting meaningless.

```
discount_minor = discount_type = 'percentage'
    ? min( round_half_up(qualifying_subtotal_minor × discount_rate_bp / 10000),
           max_discount_minor ?? PHP_INT_MAX )
    : min( discount_amount_minor, qualifying_subtotal_minor )

for each qualifying line:
    line_spend_discount_minor = round_half_up(
        discount_minor × line_item_net_minor / qualifying_subtotal_minor )

remainder = discount_minor − Σ line_spend_discount_minor
assign remainder to the line with the largest line_item_net_minor
```

`Σ line_spend_discount_minor = spend_break_discount_minor`, **exactly**. Non-qualifying lines receive zero. A fixed discount is clamped so it can never exceed the qualifying subtotal.

### 7A.4 Revised rounding sequence — supersedes §6.3

§6.3 assumed a single pass. The correct sequence has three phases, with **tax computed last**, once the spend discount is known:

```
PASS 1 — per line, item-level
  1. unit_price_net_e4          ← resolver (§4)
  2. gross_line_e4              = unit_price_net_e4 × base_qty
  3. line_discount_e4           ← coupon / manual override (§7)
  4. item_net_line_e4           = gross_line_e4 − line_discount_e4
  5. line_item_net_minor        = round_half_up(item_net_line_e4 / 100)   ← ONLY e4→minor conversion

PASS 2 — order-level spend break
  6. qualifying_subtotal_minor  = Σ line_item_net_minor over qualifying lines
  7. select break               ← §7A.2
  8. spend_break_discount_minor ← §7A.3
  9. line_spend_discount_minor  ← apportioned, §7A.3
 10. line_net_minor             = line_item_net_minor − line_spend_discount_minor

PASS 3 — tax and totals
 11. line_tax_minor             = round_half_up(line_net_minor × tax_rate_bp / 10000)
 12. line_gross_minor           = line_net_minor + line_tax_minor
 13. subtotal_net_minor         = Σ line_net_minor
 14. tax_minor                  = Σ line_tax_minor
 15. total_gross_minor          = subtotal_net_minor + shipping_net_minor + tax_minor
```

**Tax is computed on the post-spend-break line value.** Charging VAT on a discount the customer did not pay is wrong, and preventing it is why this ordering is fixed.

The single `e4 → minor` conversion at step 5 is unchanged. Steps 8–11 are integer pence throughout.

### 7A.5 Worked example

Three lines, mixed VAT, a global 3%-over-£1,000 break:

| Line | Item net | VAT rate |
|---|---|---|
| A | £620.00 | 20% |
| B | £310.00 | 20% |
| C | £150.00 | 0% (zero-rated) |
| **Qualifying subtotal** | **£1,080.00** | |

Break qualifies. `discount_minor = round_half_up(108000 × 300 / 10000) = 3240` (£32.40).

| Line | Apportioned share | Net after | VAT | Gross |
|---|---|---|---|---|
| A | 3240 × 62000/108000 = **1860** | £601.40 | £120.28 | £721.68 |
| B | 3240 × 31000/108000 = **930** | £300.70 | £60.14 | £360.84 |
| C | 3240 × 15000/108000 = **450** | £145.50 | £0.00 | £145.50 |
| **Σ** | **3240** ✓ | £1,047.60 | £180.42 | £1,228.02 |

VAT on line A is charged on £601.40, not £620.00 — a £3.72 difference that an unapportioned implementation gets wrong, in the customer's disfavour, on every discounted order.

### 7A.6 Display

The order pad shows progress toward the next threshold — *"spend £140 more for 3% off your order"* — computed from the cached break table with no extra query. A strong conversion lever in wholesale, and something the reference system has no equivalent of.

### 7A.7 Additional tests

Appended to the §12 matrix:

| # | Scenario | Assertion |
|---|---|---|
| 13 | Subtotal £999.99 against a £1,000 break | No discount. Off-by-one at the threshold |
| 14 | Company, tier and global breaks all qualify | Company wins; exactly one applies |
| 15 | Percentage break hitting `max_discount_minor` | Capped, not proportional |
| 16 | Contract line, `applies_to_contract_lines = 0` | Excluded from subtotal **and** apportionment; receives zero |
| 17 | Apportionment leaving a remainder penny | Σ equals order discount exactly; remainder on largest line |
| 18 | Mixed-VAT order with spend break | §7A.5 values reproduced exactly |
| 19 | Fixed discount exceeding the subtotal | Clamped; no negative line, no negative order |
| 20 | Item break and spend break together | Both applied, in §7A.4 order |

**Property:** for any order and any break configuration, `Σ line_net_minor + shipping + Σ line_tax = total_gross_minor`, exactly.


---

## 8. Bulk resolution — the order pad path

The order pad renders 50–100 rows per page, each needing a resolved price, a break table and a stock figure. Per-row resolution would be 100 round trips.

**Three queries per page, regardless of row count:**

```
Q-A  Candidate price list ids for this customer      → 1 row set, cached per company
Q-B  All break rows for (those lists × those SKUs)   → price_list_items_resolve_idx, covering
Q-C  Stock levels for those SKUs                     → stock_levels PRIMARY, range
```

Q-B is where `price_list_items_resolve_idx (price_list_id, sku_id, min_base_qty DESC, unit_price_e4)` earns its existence:

```sql
SELECT price_list_id, sku_id, min_base_qty, unit_price_e4
FROM   price_list_items
WHERE  price_list_id = ANY(:list_ids)    -- 2-5 values
  AND  sku_id        = ANY(:sku_ids);    -- 50-100 values
```

No `min_base_qty` predicate: the **full** break table for every row comes back, so the UI can render *"144+ £0.92, 1440+ £0.86"* inline and recompute instantly when the buyer changes a quantity — no round trip per keystroke. Ranking and break selection then happen in PHP over an in-memory collection, which is where they belong: the data is tiny and the logic is testable without a database.

Budget: Q-B under 15 ms warm (Doc 02 §10, Q1), asserted by `EXPLAIN (ANALYZE, BUFFERS)` showing **Index Only Scan with `Heap Fetches: 0`**.

That assertion does double duty on PostgreSQL. An index-only scan requires a current visibility map, which requires autovacuum to have run — so a rising `Heap Fetches` count is an early warning that autovacuum is falling behind, not merely a latency regression. `price_list_items` gets per-table autovacuum tuning (07 §11.6) because it sees bulk updates followed by long read-heavy periods.

---

## 9. Caching

| Key | Contents | TTL | Invalidated by |
|---|---|---|---|
| `pricing:lists:{company_id}` | ranked candidate list ids | 1 h | write to `price_lists`, company tier change |
| `pricing:breaks:{list_id}:{sku_id}` | full break table | 6 h | write to `price_list_items` for that SKU |
| `pricing:promos:eligible:{company_id}` | eligible promotion list ids | 15 min | write to `promotions` / `promotion_rules` |
| `pricing:tax:{tax_class_id}:{country}` | current rate | 24 h | write to `tax_rates` |

Rules:

- Cache holds **inputs to resolution**, never resolved prices. Resolution is cheap in memory; the expensive part is fetching candidates. Caching the output would multiply keys by every quantity band and make invalidation unreliable.
- Invalidation is **event-driven, not TTL-dependent**. TTLs are a backstop. A price change must be live immediately — a buyer seeing a stale price and a checkout charging a different one is the worst failure this component can produce.
- Any write to `price_list_items` dispatches `PriceListItemChanged`, which flushes the affected keys and appends to the audit log.
- **A cold cache must be survivable, not a cliff.** This is exactly what the index design in Doc 02 §6.4 guarantees: at 5,000 SKUs × 5 lists × 3 breaks the whole `price_list_items_resolve_idx` is a few megabytes and stays resident in `shared_buffers`.

---

## 10. Tax

- Rate resolved from `skus.tax_class_id` → `tax_rates` filtered on `country_code` (from the **delivery** address) and the order's timestamp.
- Company-level `tax_exempt` zeroes the rate and records the exemption basis on the invoice.
- Stored prices are **always net**. `companies.price_display_mode` controls presentation only; changing it never changes what is charged.
- The rate is snapshotted to `order_lines.tax_rate_bp` at placement. A VAT change does not retroactively alter historical invoices.
- Reverse-charge and export scenarios are **out of scope at launch** and recorded in §13.

---

## 11. Margin & RRP

At order placement each line snapshots `unit_cost_e4` from the current `sku_costs` row (Doc 02 §6.6, index `sku_costs_current_idx`). This makes margin a stored fact rather than a reconstruction:

```
line_margin_minor = line_net_minor − round_half_up(unit_cost_e4 × base_qty / 100)
line_margin_pct   = line_margin_minor × 10000 / line_net_minor      (basis points)
```

Cost figures are **never exposed to customer-facing contexts.** Enforced by a policy gate, not by remembering to omit them from a view — the `ResolvedPrice` object returned on the storefront has `unitCostE4 = null`, populated only for admin and rep contexts.

**RRP margin calculator** (a trade-facing selling tool the reference system lacks): given `products.rrp_minor` and the resolved unit price, show the retailer *"buy at £0.92, sell at £2.99 — 69% margin"*. This is presentation over data already resolved; no extra queries.

---

## 12. Test matrix

**Property tests** (generative, the ones that catch real bugs):

| Property | Assertion |
|---|---|
| Monotonicity | `base_qty` increasing never increases `unit_price_e4` within one list |
| Pack invariance | Same `base_qty` resolves to the same unit price regardless of pack composition |
| Total consistency | `Σ line_net_minor + shipping + Σ line_tax = total_gross_minor`, exactly, always |
| Discount apportionment | `Σ line_discount_minor = order_discount_minor`, exactly |
| Snapshot immutability | Mutating or deleting any price list leaves existing order lines byte-identical |
| Determinism | Identical inputs resolve identically across 1,000 runs with overlapping lists present |
| Precedence completeness | Every rank combination resolves to the documented winner |
| No floats | Static analysis: no float cast or arithmetic in the `Pricing` namespace. `numeric` is not used either — the rounding boundary is explicit integer arithmetic (02 §2.2) |
| Index-only resolution | Q-B plan shows Index Only Scan with `Heap Fetches: 0` |
| No overlapping lists | Attempting to activate a second overlapping tier or base list raises an exclusion-constraint violation |

**Worked example fixtures** — each a table test with hand-calculated expected values:

1. Guest, base price, qty 1
2. Tier customer, break 2 of 3
3. Contract price overriding an active promotion
4. Promotion beating tier
5. Promotion **worse** than tier → cap fires, `promotionCapped = true`
6. Customer list holding only a 1000+ break, 50 ordered → falls through to tier
7. Sub-penny break (£0.9212) at 1,440 units → **£1,326.53**, the §3.3 case
8. Mixed VAT rates across lines → order tax equals sum of line tax
9. Fixed £50 order discount across 7 lines → remainder pence on largest line
10. Manual override with reason code → audit entry written
11. VAT rate change dated between two orders → each uses its own rate
12. Zero-rated SKU → `line_tax_minor = 0`, line still appears on the VAT return

---

## 13. Open questions

| # | Question | Blocking? |
|---|---|---|
| 1 | Coupon stacking beyond one per order | No — Phase 2 |
| 2 | Reverse-charge / export VAT | No — but affects `tax_rates.region` usage if EU export happens |
| 3 | Per-tier pack visibility (tier A eaches, tier C outers only) | No — additive `pack_tier_visibility` table |
| 4 | ~~Order-wide spend breaks?~~ | **CLOSED — both mechanisms supported.** `order_spend_breaks` (02 §6.7), two-pass application in §7A |
| 5 | Does any customer need currency other than GBP at launch? | No — `currency` columns already in place |

All remaining questions here are additive. The two-pass structure in §7A is the load-bearing decision and it is settled.

---

## 14. Acceptance criteria

1. All 12 fixtures in §12 pass with hand-verified expected values.
2. All 8 property tests pass at 1,000 iterations.
3. Q-B (§8) executes under 15 ms warm at 100 SKUs × 5 lists, verified by `EXPLAIN` assertion showing `Using index`.
4. Cold-cache order pad page renders under 800 ms at 100 rows.
5. A price change is live within one request of the write, verified by an integration test asserting no stale read.
6. No float arithmetic in the pricing path, verified by static analysis in CI.
7. Every manual override in a 100-order simulation appears in the audit log with actor and reason.
8. Deleting an entire price list leaves all existing order line values unchanged, verified by row-level comparison.
