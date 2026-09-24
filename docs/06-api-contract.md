# API Contract

**B2B Wholesale & Distribution Platform**

| | |
|---|---|
| Document | 06 — API Contract |
| Status | Draft for review — **swept for PostgreSQL 16** |
| Depends on | 02 (entities, keys), 03 (pricing), 04 (inventory), 05.1–05.8 (module behaviour) |
| Feeds into | 07 — NFRs, 09 — Test Strategy |

---

## 1. Purpose and consumers

One HTTP API serves every client. There is no separate "internal" API with different rules — a second surface with its own conventions is how authorisation gaps appear.

| Consumer | Auth | Notes |
|---|---|---|
| Inertia + React app (storefront, order pad) | Session cookie + CSRF (Sanctum) | First-party, same origin, server-rendered routes |
| FilamentPHP admin | Session cookie + CSRF (Sanctum) | Same API/auth, staff-only Policies |
| Warehouse screens | Session cookie | Same API, different permissions |
| Realtime gateway (`services/realtime-gateway`) | Redis Pub/Sub, not HTTP | Relays only — see `11-realtime-gateway.md`. Never authenticates end users itself |
| Rep mobile / PWA (Phase 4) | Session cookie or token | Same endpoints |
| Customer system integrations (Phase 4) | OAuth2 client credentials | Scoped tokens, rate limited separately |
| Webhook receivers | Outbound, signed | §11 |

**Scope at launch:** the SPA and warehouse screens. Public integration endpoints and webhooks are Phase 4, but their conventions are fixed here so the internal API does not have to be reshaped to expose them later.

---

## 2. Conventions

| Aspect | Rule |
|---|---|
| Base path | `/api/v1` |
| Versioning | Path-based major version. Additive changes are not a new version; removals and semantic changes are |
| Format | JSON only. `Content-Type: application/json`, `Accept: application/json` |
| Casing | `snake_case` throughout, matching the schema. No transformation layer to get wrong |
| Identifiers | **ULID strings only.** Auto-increment ids are never exposed (02 §2.1) |
| Dates | ISO 8601 with offset, always UTC: `2026-09-15T14:32:00Z`. Dates without time: `2026-09-15` |
| Booleans | Real JSON booleans, never `0`/`1` |
| Nulls | Explicit `null`. Absent means "not requested", not "empty" |
| Enums | Exactly the schema's string values. Clients must tolerate unknown additions |
| Trailing slashes | Rejected |
| HTTP methods | `GET` safe, `POST` create and action, `PATCH` partial update, `PUT` unused, `DELETE` where a resource genuinely deletes |

Field names carry their schema suffixes unchanged — `unit_price_net_e4`, `line_net_minor`, `base_qty`. The API deliberately does not rename them into something friendlier: the suffix *is* the contract about scale and unit, and stripping it is how a client ends up treating `e4` as pence.

---

## 3. Money and quantities in payloads

The most consequential section, because a client that gets this wrong produces wrong invoices.

### 3.1 Money

Every monetary value is a **JSON integer** at one of the two scales from Doc 02 §2.2, identified by its suffix:

```json
{
  "unit_price_net_e4": 9212,
  "line_net_minor": 132653,
  "line_tax_minor": 26531
}
```

Rules for clients, stated normatively because they are testable:

1. **Never perform floating-point arithmetic on these values.** Integer arithmetic only.
2. **Never mix scales in one expression.** `_e4` and `_minor` are different units.
3. Convert `e4 → minor` only as `round_half_up(e4 × qty / 100)`, and only where the server has not already done it. On an order line the server has; use `line_net_minor`.
4. JSON integers are safe here: realistic values sit far below 2^53, and no money field is a `BIGINT` at risk of precision loss in JavaScript. Identifiers, which could be, are ULID strings.

Where a formatted value is genuinely needed — PDF generation, a screen reader label — the server supplies it alongside rather than leaving the client to format:

```json
{
  "line_net_minor": 132653,
  "display": { "line_net": "£1,326.53", "currency": "GBP" }
}
```

`display` is presentational only. **No client may parse a `display` string back into a number.** It exists so formatting is decided once, server-side, consistently.

### 3.2 Quantities

Every quantity is in base units, with pack context alongside — never one without the other (02 §2.3):

```json
{
  "pack": { "id": "01J8...", "label": "Outer 144", "base_units": 144 },
  "pack_qty": 3,
  "base_qty": 432
}
```

A client sending `pack_qty` without `pack_id` is rejected. A client sending `base_qty` that disagrees with `pack_qty × base_units` is rejected — the same invariant `order_lines_base_qty_chk` enforces in the database (02 §8.3), checked at the boundary so the error message is useful.

---

## 4. Errors

One envelope for every failure:

```json
{
  "error": {
    "code": "insufficient_stock",
    "message": "Not enough stock to fulfil this order.",
    "details": [
      {
        "field": "lines.2.base_qty",
        "code": "shortfall",
        "message": "Only 288 units available.",
        "meta": { "requested_base_qty": 432, "available_base_qty": 288 }
      }
    ],
    "request_id": "01J8XQ...",
    "documentation_url": "https://docs.internal/errors/insufficient_stock"
  }
}
```

- `code` is a stable machine string. `message` is human-readable and may change.
- `details[].meta` carries the numbers a client needs to render a useful message — "only 288 available" rather than "something went wrong".
- `request_id` correlates to server logs and appears in support conversations.

### 4.1 Status codes

| Code | Used for |
|---|---|
| 200 | Success with a body |
| 201 | Resource created; `Location` header set |
| 202 | Accepted for background processing; returns a job resource |
| 204 | Success, no body |
| 400 | Malformed request |
| 401 | Not authenticated |
| 403 | Authenticated, not permitted |
| 404 | Not found, or not visible to this caller |
| 409 | Conflict: version mismatch, state transition invalid, idempotency key reuse with a different body |
| 422 | Validation failed, including business-rule failures (MOQ, minimum order, credit) |
| 423 | Locked: resource held by another operation |
| 409 | Also returned for a PostgreSQL exclusion-constraint violation — an attempt to create an overlapping price list, tax rate, delivery rate, duty rate or commission rule. Code `overlapping_validity_window`, with the conflicting row in `details[0].meta` |
| 429 | Rate limited; `Retry-After` set |
| 500 | Unhandled |
| 503 | Dependency unavailable |

**404 rather than 403 for resources outside the caller's tenancy.** A trade customer probing another company's order id learns nothing about whether it exists.

### 4.2 Business-rule failures are 422, not 400

A credit block, a below-minimum order, a MOQ violation and a stock shortfall are all **422 with a specific code**. They are not malformed requests; they are well-formed requests the business declines. Clients branch on `error.code`, and the order pad (05.1 §6) renders per-line messages from `details`.

Reserved codes for the ones clients must handle distinctly:

`insufficient_stock` · `insufficient_credit` · `credit_account_suspended` · `below_minimum_order` · `moq_not_met` · `order_increment_violation` · `price_changed` · `quote_expired` · `slot_unavailable` · `not_returnable` · `no_eligible_batch` · `approval_required` · `overlapping_validity_window`

---

## 5. Pagination, filtering, sorting

### 5.1 Keyset pagination only

`OFFSET` is not offered (02 §9 rule 8). At 92 pages it degrades linearly, which is a measurable part of why the reference system's order pad is slow.

```
GET /api/v1/products?limit=50&after=eyJuYW1lIjoiR3JhdGVyIiwiaWQiOiIwMUo4In0
```

```json
{
  "data": [ ... ],
  "meta": { "limit": 50, "has_more": true },
  "links": { "next": "/api/v1/products?limit=50&after=eyJ..." }
}
```

- `after` is an opaque base64 cursor. Clients must treat it as opaque and never construct one. Server-side it decodes to the trailing sort-key tuple and becomes a row-constructor comparison — `(name, id) > (:name, :id)` — which PostgreSQL matches directly against a composite index, so keyset traversal is a single index scan at any depth.
- Every paginated index has a trailing `id` in its sort key, guaranteeing total ordering.
- `limit` default 50, maximum 100. The order pad's 100-row page is the maximum for a reason (05.1 §9).
- **No total count by default.** Counting a filtered catalogue is a second expensive query, and "1 of many" is what the UI actually needs. `?include_total=true` is available and rate-limited more tightly.

### 5.2 Filtering

```
GET /api/v1/products?category=kitchen&brand=x&in_stock=true&price_min_minor=100
```

Filters are explicit named parameters, not a generic query language. A generic filter DSL over this schema would let a client construct queries no index supports, and the performance budgets in Doc 02 §10 would become unenforceable.

### 5.3 Sorting and sparse responses

- `?sort=name` / `?sort=-price` (leading `-` for descending). Only fields with a supporting index are accepted; anything else is 422 with `unsortable_field`.
- `?include=packs,media,stock` expands related data. Default responses are lean; expansion is opt-in so an N+1 cannot be caused by a default.

---

## 6. Idempotency

Every state-changing `POST` accepts, and for the operations below **requires**, an `Idempotency-Key` header (client-generated UUID or ULID).

```
POST /api/v1/orders
Idempotency-Key: 01J8XQK9V3...
```

Behaviour: the first request is processed and its response stored against the key for 24 hours. A repeat with the same key returns the stored response without re-executing. A repeat with the same key and a **different body** is 409 `idempotency_key_reuse`.

Required on:

| Operation | Backed by |
|---|---|
| Create order / checkout | `credit_holds_order_uq`, `stock_allocations_identity_uq` (02 §7.4, 05.2 §7.1) |
| Accept quote | `quotes_converted_order_uq` (05.3 §5.1) |
| Book collection slot | `collection_bookings_order_uq` (05.6 §7.1) |
| Receive goods | `(receipt_id, po_line_id, client_token)` (05.5 §10) |
| Dispatch shipment | `(shipment_id, client_token)` (05.5 §10) |
| Apply account credit | ledger movement (05.4 §7.5A) |
| Accrue commission | `rep_commissions_accrual_uq` (05.8 §10.3) |

The header is the transport-level guarantee; the unique constraints are the durable one. Both exist because the header protects against a retry within the window and the constraint protects against everything else — a delayed duplicate, a second client, a replayed job.

---

## 7. Concurrency

Resources with a `version` column (notably `stock_levels`, 02 §7.3) support optimistic concurrency:

```
PATCH /api/v1/stock-levels/{sku}/{location}
If-Match: "17"
```

A stale version is 409 `version_conflict`, with the current state in `error.details[0].meta` so the client can show a real diff rather than "please reload".

Allocation and checkout use **server-side pessimistic locking** (04 §4.3, 05.2 §8.2) and expose no version header. The lock order is a server concern and must not be influenced by a client.

---

## 8. Resource catalogue

Grouped by domain. `C` create, `R` read, `U` update, `A` action.

### Catalogue
| Endpoint | Ops |
|---|---|
| `/products`, `/products/{id}` | R |
| `/products/{id}/skus` | R |
| `/skus/{id}`, `/skus/{id}/packs`, `/skus/{id}/breaks` | R |
| `/categories`, `/categories/{id}` | R |
| `/brands` | R |
| `/search` | R |
| `/admin/products`, `/admin/skus`, `/admin/packs` | C R U |
| `/admin/products/import` | A (202, job) |
| `/admin/media/bulk-match` | A (202, job) |

### Pricing
| Endpoint | Ops |
|---|---|
| `/pricing/resolve` | A — single SKU (03 §4) |
| `/pricing/bulk-resolve` | A — the order pad path (03 §8) |
| `/admin/price-lists`, `/admin/price-list-items` | C R U |
| `/admin/spend-breaks` | C R U |

### Cart and checkout
| Endpoint | Ops |
|---|---|
| `/cart`, `/cart/lines`, `/cart/lines/{id}` | C R U, DELETE |
| `/cart/bulk-add` | A — SKU paste / CSU (05.1 §7) |
| `/cart/apply-account-credit` | A |
| `/checkout/preview` | A — totals, carriage, credit, no side effects |
| `/checkout` | A — creates the order. Idempotency required |

### Orders
| Endpoint | Ops |
|---|---|
| `/orders`, `/orders/{id}` | R |
| `/orders/{id}/approve`, `/reject` | A |
| `/orders/{id}/cancel` | A |
| `/orders/{id}/reorder` | A — returns a cart |
| `/orders/{id}/documents` | R — invoice, packing list |
| `/saved-lists`, `/saved-lists/{id}` | C R U, DELETE |

### Quotes
| Endpoint | Ops |
|---|---|
| `/quotes`, `/quotes/{id}` | C R |
| `/quotes/{id}/lines` | C U, DELETE (draft only) |
| `/quotes/{id}/submit-for-approval`, `/approve`, `/send`, `/withdraw`, `/revise` | A |
| `/quotes/{id}/accept`, `/reject` | A — accept requires idempotency |
| `/public/quotes/{token}` | R A — tokenised, unauthenticated (05.3 §11) |

### Inventory
| Endpoint | Ops |
|---|---|
| `/stock/availability` | R — bulk, by SKU list |
| `/skus/{id}/back-in-stock` | C |
| `/warehouse/receipts`, `/receipts/{id}/lines` | C R — idempotency required |
| `/warehouse/pick-lists`, `/pick-lists/{id}` | R |
| `/warehouse/pick-lines/{id}/confirm`, `/short-pick`, `/substitute-batch` | A |
| `/warehouse/shipments`, `/shipments/{id}/dispatch` | C A — idempotency required |
| `/warehouse/stocktakes`, `/stocktakes/{id}/lines`, `/post` | C R A |
| `/admin/stock-adjustments` | C |
| `/admin/stock-movements` | R — ledger, read-only by design |

### Accounts and credit
| Endpoint | Ops |
|---|---|
| `/applications` | C R |
| `/applications/{id}/request-info`, `/approve`, `/reject` | A |
| `/company`, `/company/users`, `/company/addresses` | R U, C |
| `/company/credit` | R — limit, used, held, available, balance |
| `/admin/companies/{id}/credit` | U |

### Returns
| Endpoint | Ops |
|---|---|
| `/returns/eligibility?order_id=` | R — fees quoted before submission (05.4 §7.1) |
| `/returns`, `/returns/{id}` | C R |
| `/returns/{id}/approve`, `/reject`, `/cancel` | A |
| `/warehouse/returns/{id}/receive`, `/inspect` | A |
| `/returns/{id}/resolve` | A |

### Delivery and collection
| Endpoint | Ops |
|---|---|
| `/delivery/quote` | A — zone, method, carriage for an address and basket |
| `/collection-slots?location=&from=&to=` | R |
| `/collection-bookings` | C — idempotency required |
| `/admin/delivery-zones`, `/delivery-rates` | C R U |

### Purchasing
| Endpoint | Ops |
|---|---|
| `/admin/suppliers` | C R U |
| `/admin/purchase-orders`, `/{id}/lines` | C R U |
| `/admin/containers`, `/{id}/costs` | C R U |
| `/admin/containers/{id}/apportion` | A — 202, job (05.7 §8) |
| `/admin/reorder-suggestions` | R |

### Rep and dropship
| Endpoint | Ops |
|---|---|
| `/rep/customers` | R |
| `/rep/sessions` | C — start an order-on-behalf session |
| `/rep/activities` | C R |
| `/rep/commissions?period=` | R |
| `/dropship/orders/batch` | A — 202, job (05.8 §5.4) |
| `/admin/dropship-profiles` | C R U |

### Platform
| Endpoint | Ops |
|---|---|
| `/me` | R |
| `/admin/configurations` | R U |
| `/jobs/{id}` | R — status for any 202 response |
| `/health`, `/health/ready` | R |

---

## 9. Key payloads

### 9.1 Bulk price resolution — the order pad's hot path

```
POST /api/v1/pricing/bulk-resolve
{
  "sku_ids": ["01J8A...", "01J8B..."],
  "include_breaks": true
}
```

```json
{
  "data": [
    {
      "sku_id": "01J8A...",
      "resolved": {
        "unit_price_net_e4": 9200,
        "price_source": "tier",
        "applied_break_qty": 144,
        "tax_rate_bp": 2000,
        "next_break_qty": 1440,
        "next_break_unit_price_net_e4": 8600
      },
      "breaks": [
        { "min_base_qty": 1,    "unit_price_net_e4": 9800, "price_source": "tier" },
        { "min_base_qty": 144,  "unit_price_net_e4": 9200, "price_source": "tier" },
        { "min_base_qty": 1440, "unit_price_net_e4": 8600, "price_source": "tier" }
      ]
    }
  ]
}
```

Returns the **full break table** so quantity changes recompute client-side with no round trip (05.1 §5.1). The table is the SKU's **effective** ladder, not one price list's rows: each entry states the price and source resolution yields from that `min_base_qty` up to the next entry, evaluated across every candidate list — so a list that only starts winning at a higher quantity (03 §4.3's fall-through, e.g. a contract list holding only a 1,000+ row) and the §4.5 promotion cap are both reflected. `price_source` is per entry because 03 §7A.2's contract-line exclusion depends on it. Added 2026-09-24 for 05.1 §11's exact client/server parity; additive per §2. Cost and margin are absent — this is a customer-facing endpoint (03 §11). Three queries server-side regardless of list length (03 §8); budget 15 ms.

### 9.2 Checkout preview — no side effects

```
POST /api/v1/checkout/preview
{ "delivery_address_id": "01J8...", "fulfilment_type": "delivery",
  "apply_account_credit": true }
```

```json
{
  "subtotal_net_minor": 184260,
  "spend_break": {
    "id": "01J8...", "name": "3% over £1,000",
    "discount_minor": 5528,
    "next_threshold_net_minor": 200000,
    "shortfall_to_next_minor": 15740
  },
  "delivery": {
    "zone": "GB_MAINLAND", "method": "pallet",
    "shipping_net_minor": 4800,
    "carriage_paid_threshold_net_minor": 50000,
    "shortfall_to_free_minor": 0
  },
  "tax_minor": 36706,
  "total_gross_minor": 220238,
  "account_credit_applied_minor": 50000,
  "amount_due_minor": 170238,
  "credit": { "available_minor": 420000, "sufficient": true },
  "blockers": []
}
```

`blockers` is an array of the same `details` shape as §4, empty when checkout will succeed. The client renders it directly — this is the mechanism by which 05.1 §6 surfaces every rule before the payment step rather than at it.

Preview is **explicitly side-effect-free**: no allocation, no credit hold, no slot booking. It may be called on every cart change.

### 9.3 Checkout

```
POST /api/v1/checkout
Idempotency-Key: 01J8XQ...
{ "delivery_address_id": "01J8...", "fulfilment_type": "collection",
  "collection_slot_id": "01J8...", "payment_method": "on_account",
  "apply_account_credit": true, "customer_reference": "PO-4471",
  "expected_total_gross_minor": 220238 }
```

`expected_total_gross_minor` is required. If the server's re-resolution disagrees, the response is 409 `price_changed` with both figures — the customer confirms before anything is committed (05.1 §10). **A silently repriced order is never acceptable.**

Success is 201 with the order, having run the whole transaction in the Doc 05.6 §7.2 lock order: credit → slot → stock.

**As built (2026-09-24), additive per §2:**

- `payment_method` is `card`, `bacs` or `on_account`. `on_account` is refused (422 `payment_method_not_available`) unless the company is on credit terms (05.2 §8.1). Card capture (07 §6.4) is not built: card and BACS orders are placed `unpaid`, to be paid before dispatch, and `orders` has no column recording which method was chosen.
- The delivery address is sent inline as `delivery_address` and snapshotted onto `order_addresses` (02 §8.4); its `country_code` sets the VAT. `delivery_address_id` is refused, as on preview — `addresses` has no `public_id` (02 §4.5), and public customers have no saved addresses.
- Signed in only. While preview would report any blocker, checkout refuses with 422 `checkout_blocked`, the blockers in `details`. Preview's blockers now include who may order (05.13): `sign_in_required`, `application_pending`, `email_unverified`, `not_permitted_to_order` (a company `viewer`, 05.2 §10), listed after the cart's own.
- 409 `price_changed` carries `details[0].meta.expected_total_gross_minor` and `actual_total_gross_minor`. Stock that sold out meanwhile is 409 `insufficient_stock`; credit exceeded is 422 `insufficient_credit`.
- Idempotency (§6) is held in the cache for 24 hours, scoped to the caller; a repeat while the first is running is 409 `idempotency_in_progress`. §16's guarantee after expiry "via the underlying unique constraint" needs an `orders` column 02 does not have yet.
- 201 body: `{ "data": { "id", "order_number", "total_gross_minor", "confirmation_url" } }`.

### 9.4 Stock availability

```
GET /api/v1/stock/availability?sku_ids=01J8A,01J8B
```

```json
{
  "data": [
    { "sku_id": "01J8A...", "available_base_qty": 696,
      "is_stock_tracked": true, "allow_backorder": false,
      "incoming": { "base_qty": 1440, "expected_on": "2026-10-12" } }
  ]
}
```

Batch and serial detail are **not** exposed to customer-facing callers. Which batch a customer will receive is an internal allocation decision (04 §5), and exposing it invites cherry-picking.

---

## 10. Authorisation

- Every endpoint is gated by a policy. **There is no endpoint whose authorisation is "it isn't in the UI"** (Doc 01 §4).
- Tenancy is enforced in the query, not the response: a company-scoped resource is filtered by `company_id` in the `WHERE` clause, so an out-of-scope id cannot be fetched and then hidden.
- Cost, margin and landed cost appear only on rep, manager and admin endpoints. The customer-facing serialiser has no cost field to omit (03 §11).
- Rep order-on-behalf sessions carry the acting rep in the token/session; the server writes all three user references (05.8 §8). A client cannot assert `placed_by_user_id`.
- Admin endpoints live under `/admin` and `/warehouse` prefixes for auditability of the route list itself, not as the authorisation mechanism.

---

## 11. Webhooks (Phase 4, conventions fixed now)

| Event | Payload root |
|---|---|
| `order.confirmed`, `order.dispatched`, `order.cancelled` | order |
| `shipment.dispatched` | shipment with tracking |
| `stock.back_in_stock` | sku, available_base_qty |
| `quote.sent`, `quote.accepted`, `quote.expired` | quote |
| `invoice.issued`, `invoice.overdue` | invoice |
| `return.received`, `return.resolved` | rma |
| `credit.limit_reached` | company |

- Signed with HMAC-SHA256 over the raw body, `X-Signature` header, timestamped to prevent replay.
- At-least-once delivery. Every payload carries a stable `event_id`; **receivers must deduplicate.**
- Retries with exponential backoff for 24 hours, then the endpoint is disabled and the account notified.
- Payloads mirror the `GET` representation of the resource, so a receiver has one shape to parse.

---

## 12. Rate limits

| Caller | Limit |
|---|---|
| Authenticated session, read | 300 / min |
| Authenticated session, write | 60 / min |
| `bulk-resolve`, `availability` | 120 / min (order pad calls these on filter changes) |
| `include_total=true` | 20 / min |
| Unauthenticated (catalogue, public quote) | 60 / min per IP |
| OAuth client | Per-client, configured |
| Login and password reset | 5 / min per IP and per identifier |

429 responses carry `Retry-After` and `X-RateLimit-Remaining`.

---

## 13. Deprecation

- Additive changes — new fields, new endpoints, new enum values — ship without a version bump. Clients must ignore unknown fields.
- Removals and semantic changes require a new major path version, with the previous supported for a stated minimum period.
- A field due for removal returns a `Deprecation` and `Sunset` header on responses that include it.
- The changelog is generated from the OpenAPI diff, not written by hand.

---

## 14. Specification artefact

- **OpenAPI 3.1 is generated from code**, not maintained separately. A hand-written spec drifts, and a drifted spec is worse than none.
- Every endpoint has a request-validation schema (Form Request) and a response resource; the generator reads both.
- CI fails if the generated spec changes without the changelog being regenerated.
- The spec is the input to contract tests (§15) and to client type generation for the SPA.

---

## 15. Test matrix

**Contract:** every endpoint's response validates against the generated schema · every documented error code is reachable by a test · unknown query parameters are rejected rather than ignored · unsortable fields return 422.

**Money and quantity:** for 1,000 generated carts, `checkout/preview` totals equal the server's own re-resolution at `checkout`, exactly · no response contains a float in any `_e4` or `_minor` field, asserted by payload inspection · `pack_qty × base_units ≠ base_qty` is rejected at the boundary · a `display` string is never accepted as input.

**Idempotency:** each required operation, called twice with one key, produces exactly one side effect and two identical responses · same key with a different body is 409 · the guarantee holds with the stored response expired, via the underlying unique constraint.

**Authorisation:** for every company-scoped resource, a foreign id returns 404 and not 403 · no customer-facing response contains a cost or margin field, asserted across all serialisers · a client cannot set `placed_by_user_id`, `sales_rep_user_id`, or any price field on checkout.

**Pagination:** keyset traversal of 5,000 products visits every row exactly once with concurrent inserts · query time is flat from page 1 to page 100 · a hand-constructed cursor is rejected.

---

## 16. Acceptance criteria

1. OpenAPI 3.1 generated from code, with CI failing on undocumented drift.
2. Every money field is an integer with its scale suffix; no response contains a float in a money field.
3. `checkout/preview` is provably side-effect-free and its totals match `checkout` exactly across 1,000 generated carts.
4. `checkout` rejects a stale `expected_total_gross_minor` with 409 `price_changed` and commits nothing.
5. All seven idempotency-required operations produce exactly one side effect under duplicate submission.
6. Business-rule failures return 422 with a documented stable code and per-field `details`.
7. Out-of-tenancy ids return 404, verified for every company-scoped resource.
8. No customer-facing serialiser contains a cost, margin or batch field.
9. Keyset pagination is flat to page 100, and no endpoint offers `OFFSET`.
10. Every endpoint is gated by an explicit policy, verified by a route-to-policy coverage test with no exemptions.

---

## 17. Open questions

| # | Question | Blocking |
|---|---|---|
| 1 | GraphQL alongside REST for the SPA? | No — REST at launch; the resource catalogue would be the schema basis |
| 2 | Should `include_total` be offered at all, given its cost? | No — offered and tightly limited |
| 3 | OAuth scopes granularity for Phase 4 integrations | No — Phase 4 |
| 4 | Do customer integrations need a sandbox environment? | No — Phase 4, but likely yes |
| 5 | Webhook payload versioning independent of the API version | **Worth deciding before Phase 4** — receivers upgrade on their own schedule |
