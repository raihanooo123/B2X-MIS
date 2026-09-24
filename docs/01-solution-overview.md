# Solution Overview

**B2B Wholesale & Distribution Platform**

| | |
|---|---|
| Document | 01 — Solution Overview |
| Status | Draft for review |
| Position | Entry point to the document set. Read first |
| Stack | Laravel 11 (PHP 8.3+) · React (Inertia.js) · PostgreSQL 16 · Redis · Node.js (Socket.io) · FilamentPHP |

---

## 1. Purpose of this document

This is the orientation document for the platform. It states what is being built, for whom, what is deliberately excluded, and where every detailed decision is recorded. It carries no schema and no algorithms — those live in the documents it points to.

Anyone joining the project reads this first, then the document relevant to their work.

---

## 2. The document set

Sixteen documents (ten originally, six added by the 2026-09-20 completeness correction below). Together they are the **single canonical source of truth** for all schema and architectural decisions. Where code and a signed-off document disagree, the document wins — or the document is amended first, in its own commit.

| # | Document | Contains | State |
|---|---|---|---|
| 01 | Solution Overview | Scope, actors, glossary, decision index, risks | This document |
| 02 | Domain Model & ERD | Every table, full PostgreSQL DDL, index rationale, concurrency, migration order | Complete (§14 draft amendment awaiting sign-off) |
| 03 | Pricing Engine Spec | Resolution chain, break semantics, spend breaks, rounding, caching | Complete |
| 04 | Inventory & Stock Ledger Spec | Ledger, allocation transaction, batch/serial, reconciliation | Complete |
| 05 | Module Specs (05.1–05.8) | One per feature area: user stories, rules, acceptance criteria | In progress |
| 05.9 | Accounting Sync (Xero) | Two-way sync, entity mapping, reconciliation. Closes 02 §14.11's open questions | Pending — not yet written (Correction 2026-09-20) |
| 05.10 | Order Amendment & Cancellation | Amendment-after-hold, credit re-check, cancellation routing (`orders.cancellation_fee_minor`) | Pending — not yet written (Correction 2026-09-20) |
| 05.11 | CMS & SEO | Pages, banners, redirects, structured data, sitemap | Pending — not yet written (Correction 2026-09-20) |
| 05.12 | Notifications | Templates, channels, preferences, delivery tracking | Pending — not yet written (Correction 2026-09-20) |
| 05.13 | Auth & Onboarding | Guest-cart merge at login, unapproved-applicant login, invited-user onboarding | Pending — not yet written (Correction 2026-09-20) |
| 05.14 | Reporting Suite | Aggregates, materialised views, dashboards | Pending — not yet written (Correction 2026-09-20) |
| 06 | API Contract | Endpoints, payloads, error envelope, pagination, versioning | Draft for review (its own header; corrected here 2026-09-20 — was shown as "Pending" though substantially written) |
| 07 | Non-Functional Requirements | Performance budgets, security, GDPR, accessibility | Draft for review (its own header; corrected here 2026-09-20 — was shown as "Pending" though substantially written) |
| 08 | Migration & Seed Plan | Import from the reference system, validation, rejection reporting | Pending — not yet written (Correction 2026-09-20) |
| 09 | Test Strategy | Coverage model, concurrency and property testing, CI gates | Pending — not yet written (Correction 2026-09-20) |
| 11 | Real-Time Gateway | Node.js/TypeScript microservice architecture, Redis Pub/Sub contract, Socket.io rooms | Complete |

**Correction 2026-09-20.** Document 10 ("Delivery Plan") is retired from this index. `docs/ROADMAP.md` already serves that role — generated 2026-09-17, cross-checked against the real repo state, and the one place phased delivery sequencing actually lives. No `docs/10-*.md` exists or should be created; every reference to "Doc 10" below is corrected to point at `docs/ROADMAP.md`. This also folds in a completeness audit that found six more module/system docs than the eight `05.1–05.8` this table originally scoped for (rows added above), a name for `05.9` (`accounting-sync`, resolving the "05.9" cited inline by `xero_contact_id`/`xero_tax_type`/`xero_invoice_id` since before this doc set had a name for it), and status corrections for 06/07, which this table listed as "Pending" despite both being substantial drafts with their own "Draft for review" header.

Superseded documents are in `archive/` with a README naming the reversed decisions. They are not authoritative and must not be cited.

---

## 3. Business context

### 3.1 What the business does

A London-based importer and cash-and-carry distributor supplying trade customers — independent retailers, convenience stores, market traders, catering suppliers — with kitchen, home, bathroom, stationery, toy, DIY and disposable partyware lines. Stock is imported by container, held in a warehouse, and sold in case and pallet quantities for delivery or collection.

Three revenue channels the platform must serve:

1. **Delivery** — carriage-paid over a threshold, quoted below it, with surcharges for Northern Ireland, the Highlands and islands.
2. **Collection** — trade customers collecting from the warehouse.
3. **Walk-in** — counter trade taking stock off the shelf, competing for the same inventory as booked collections.

Alongside these: **dropshipping** to the trade customer's own end customer, and **import-on-demand** for larger orders sourced direct from origin.

### 3.2 The reference system and why it is being replaced

`londontopchoice.co.uk` — WordPress, WooCommerce, Elementor, a custom theme, and a table-based bulk-order plugin over roughly 920 SKUs. The platform choice is sound for a retail shop. It fails as a wholesale system:

| Gap | Consequence |
|---|---|
| No volume break pricing | A wholesaler with one flat price per SKU is a retailer. The core proposition is absent |
| No pack structure | Nothing models units per case, cases per pallet, or case weight. Freight cannot be quoted, stock cannot be reconciled against a case count |
| No customer or tier pricing | Negotiation is handled by inviting buyers to telephone |
| No batch or serial traceability | A recall cannot be traced to the customers who received the goods |
| Order pad is 920 SKUs across 92 pages of 10, keyword search only | The primary buying tool is unusable at catalogue scale |
| Large-scale missing imagery and empty descriptions | Trade buyers select by sight; the biggest single conversion leak |
| Contradictory minimum order (£500 in the FAQ, £1,000 in the binding T&Cs) | Two different numbers, one of them on the legally binding page |
| No back-in-stock capture | "Out of stock" with no signup is pure lost demand |
| Mutable stock counter, no ledger | No audit trail; "why is this number wrong" is unanswerable |

The catalogue is retained as the migration and load-test fixture (Doc 08). Its **behaviour** is not a model to follow.

---

## 4. Actors

| Actor | Description | Primary needs |
|---|---|---|
| **Guest** | Unauthenticated visitor | Browse catalogue, see indicative pricing, apply for a trade account or register as a public customer |
| **Public customer** | Registered user with no trade account | Buy at `base` prices, card or prepay only — no tier, contract, credit or multi-user account (05.13 §5.2) |
| **Trade buyer** | Approved company user, `role = buyer` | Order pad, saved lists, reorder, tier and contract pricing, order history |
| **Company owner** | Senior buyer on a multi-user account | All buyer capability, plus managing users and spend limits |
| **Approver** | Signs off orders over a threshold | Approval queue |
| **Sales rep** | Staff member with an assigned customer book | Order on behalf of a customer, quote, override price with a reason |
| **Warehouse operative** | Goods-in, picking, packing, dispatch, stocktake | Pick lists with batch and serial detail, receipt screens, scanning |
| **Purchasing** | Buying and import | Purchase orders, container tracking, landed cost, reorder reporting |
| **Accounts** | Credit and invoicing | Credit limits, invoices, credit notes, accounting export |
| **Administrator** | Catalogue, pricing, configuration | Full PIM, pricing engine admin, user and account management |

Roles are not mutually exclusive. Permissions are enforced by policy gates, never by hiding UI.

**Correction 2026-09-24 — public sales are in scope.** This table previously listed trade and staff actors only, and §5.3 read as excluding public buyers. The platform sells to both: trade accounts, and the public at `base` prices on card or prepay (`ConsumerCheckout`, CLAUDE.md). The public channel reuses the catalogue, pricing engine (03 §4.2 rank 5) and stock ledger unchanged; it adds no pricing or credit machinery of its own. Registration and sign-in for both are specified in 05.13.

---

## 5. Scope

### 5.1 In scope

**Phase 1 — launchable storefront.** Accounts and roles, catalogue and PIM with pack structure, tiered pricing with volume breaks, faceted search, cart with MOQ and case-multiple enforcement, checkout with card and BACS, orders with invoice and packing-list PDFs (via Node/Puppeteer worker), authoritative stock with batch and serial capability, CMS and SEO.

**Phase 2 — the wholesale engine.** Order pad (React/Inertia), bulk add by SKU or CSV, saved lists and reorder, B2B account approval workflow, quotes and RFQ, credit accounts with limit enforcement, RMA with automated restocking fees, delivery zones and collection slots, back-in-stock notification, generated price-list PDFs.

**Phase 3 — operations.** Purchase orders, container tracking, landed cost allocation, pick/pack/dispatch with batch and serial capture, multi-location, sales rep tools and order-on-behalf, dropship, accounting export.

**Phase 4 — backoffice & real-time.** FilamentPHP admin panel for backoffice management, Node.js + Socket.io real-time stock updates broadcast over Redis Pub/Sub, reporting suite, multi-user company accounts, public API and webhooks, PWA order pad, multilingual.

### 5.2 Out of scope

- **Point of sale.** The walk-in till is a separate system. This platform holds authoritative stock and exposes availability to it; it does not run the counter.
- **Accounting ledger.** Invoices and credit notes are produced here; the general ledger lives in Xero, Sage or QuickBooks, fed by export.
- **Warehouse automation.** No conveyor, robotics or WMS integration.
- **Freight booking.** Carriage is quoted and charged; courier accounts are not integrated at launch beyond tracking numbers.
- **Customs and duty filing.** HS codes and country of origin are captured; declarations are not filed from here.
- **Marketplace channels.** No Amazon or eBay listing sync.

### 5.3 Non-goals

Stated explicitly because each is a plausible-sounding direction that would damage the design:

- **Not a WooCommerce replacement built like WooCommerce.** The reference system's data model is the problem, not its plugin set.
- **Not a retail storefront with a trade discount bolted on.** Wholesale pricing, pack structure and credit terms are the core, not a feature flag. Public customers are served (§4, Correction 2026-09-24), but as a second channel on the wholesale model — `base` prices, card or prepay — never by reshaping the model around retail.
- **Not multi-tenant.** One business, one catalogue. Tenancy would change every index in Doc 02.
- **Not eventually consistent on stock.** Stock is transactionally correct. Overselling is a correctness bug, not a tolerable trade-off.

---

## 6. Architectural decision index

Every load-bearing decision, with its rationale located rather than repeated. Rejected alternatives for each are in Doc 02 Appendix B.

| # | Decision | Where |
|---|---|---|
| 1 | **Core Stack:** PHP 8.3+ / Laravel 11, PostgreSQL 16, React + Inertia.js, Tailwind CSS + shadcn/ui, Zustand + TanStack Query, FilamentPHP (Backoffice), Node.js/TypeScript (Fastify + Socket.io microservice) | This doc, 11 |
| 2 | Dual variant model: parent product to SKUs; a flat item is a single-SKU product | 02 §5.2 |
| 3 | SKU is the only stockable, priceable, sellable entity. Nothing references `products.id` | 02 §5.2 |
| 4 | All quantities stored in base units. A pack is a transaction unit, never a storage unit | 02 §2.3, §5.6 |
| 5 | Money as integers at two scales: `_e4` per unit, `_minor` per document | 02 §2.2, 03 §3.3 |
| 6 | Prices resolved through a precedence chain, never stored on a product | 03 §3.2, §4 |
| 7 | Volume breaks denominated in base units, not packs | 03 §3.1 |
| 8 | Order-wide spend breaks as a separate second pass, with apportionment to lines | 02 §6.7, 03 §7A |
| 9 | Tax computed last, on post-discount line values | 03 §7A.4 |
| 10 | Resolved prices and costs snapshotted immutably onto order lines | 02 §8.3, 03 §3.4 |
| 11 | Stock ledger append-only; levels are a rebuildable projection | 04 §2.1, §2.2 |
| 12 | Allocation reserves, dispatch consumes | 04 §2.3 |
| 13 | Allocation locks ordered by ascending `(sku_id, location_id, batch_id)` via `SELECT ... FOR UPDATE` | 04 §4.3 |
| 14 | Batch and serial tracking present from the first migration | 02 §7.5, §7.6 |
| 15 | `location_id` present from day one, single warehouse at launch | 02 §7.3 |
| 16 | Collection orders allocate at placement, protecting stock from walk-in sales | 04 §4.7 |
| 17 | Allocation at location level; bins advisory via `suggested_bin_id` | 04 §4.7 |
| 18 | `READ COMMITTED` isolation with explicit row locks — PostgreSQL default; `SERIALIZABLE` rejected | 02 §11.2 |
| 19 | Gapless document numbers from `number_sequences`, not database sequences | 02 §11.3 |
| 20 | In-database search: weighted `tsvector` + `pg_trgm`, no external engine | 02 §5.4a |
| 21 | Closure table for category trees alongside `ltree`, not read-time recursive CTEs | 02 §5.3 |
| 22 | Keyset pagination on catalogue and order pad; `OFFSET` banned | 02 §9 rule 8 |
| 23 | Projection drift reported, never auto-corrected | 04 §9 |
| 24 | Code Quality & Testing: PHPStan Level 8, Laravel Pint, Pest PHP / PHPUnit with `EXPLAIN (ANALYZE, BUFFERS)` assertion tests | Doc 09 |
| 25 | Real-time Gateway & Microservices: Node.js / Socket.io for Redis Pub/Sub events; Puppeteer/Playwright for PDF generation | This doc |
| 26 | Specifications precede implementation. No code before its document is signed off | This doc §9 |

---

## 7. Glossary

Terms used precisely throughout the set. Ambiguity here becomes bugs downstream.

| Term | Definition |
|---|---|
| **Base unit** | A SKU's atomic sellable unit — one grater, one metre, one kilogram. Every stored quantity is in base units |
| **Pack** | A transaction unit containing `base_units` base units. Levels: each, inner, outer, pallet |
| **Inner / outer** | Trade terms for the intermediate and shipping cases. An outer typically contains several inners |
| **Break** | A quantity threshold at which unit price changes. Stored in base units |
| **Spend break** | An order-wide threshold on net subtotal, applied after item pricing |
| **Price list** | A dated, scoped collection of break rows. Scopes: base, tier, company, promotion |
| **Tier** | A customer price band (e.g. Bronze/Silver/Gold) assigned to a company |
| **Contract price** | A negotiated customer-specific price, highest precedence in resolution |
| **Resolution** | Selecting exactly one applicable price from all candidates |
| **Snapshot** | A resolved value copied immutably onto a transaction, never re-derived |
| **`_e4`** | Money scale: ten-thousandths of £1. £0.9212 stored as `9212`. Per-unit values only |
| **`_minor`** | Money scale: pence. Per-line and per-document values only |
| **bp / basis points** | Rate scale. 20.00% stored as `2000` |
| **MOQ** | Minimum order quantity, per SKU, in base units |
| **Order increment** | The multiple a SKU must be ordered in (e.g. multiples of 12) |
| **Ledger** | `stock_movements`. Append-only record of every stock change. The source of truth |
| **Projection** | `stock_levels`. Derived, rebuildable, maintained transactionally |
| **On hand** | Physically present at a location |
| **Allocated** | Reserved against a confirmed order, still physically present |
| **Available** | `on_hand − allocated`. What can still be sold |
| **Batch / lot** | A group of units sharing a receipt, expiry and cost. `batch_id = 0` means untracked |
| **Serial** | An identifier for one physical unit, with its own lifecycle |
| **FEFO / FIFO** | First-expired-first-out / first-in-first-out batch selection strategies |
| **Landed cost** | True per-unit cost: FOB plus freight, duty and other charges, apportioned |
| **RRP** | Recommended retail price, used to show the buyer their resale margin |
| **RMA** | Return merchandise authorisation. The returns workflow and its reference number |
| **Dropship** | Shipping direct to the trade customer's end customer, unbranded |
| **Indent / container order** | A preorder against stock not yet imported, with deposit and ETA |
| **Order pad** | The table-based rapid-ordering screen (React + Inertia). The primary tool for trade buyers |
| **Net / gross** | Excluding / including VAT. All stored prices are net |

---

## 8. Success criteria

The platform succeeds if, twelve months after launch:

**Commercial**

1. Volume break pricing is live on the majority of the catalogue, and average order value exceeds the reference system's baseline.
2. Trade accounts self-serve. Phone ordering is a convenience, not the default path to a negotiated price.
3. Quote and credit-account workflows remove the "contact us" dead end for larger orders.

**Operational**

4. Stock figures are trusted. A warehouse count matches the system, and discrepancies are explainable from the ledger.
5. A recall can be traced from a batch to every customer who received it, in minutes.
6. Order-to-dispatch requires no spreadsheet outside the system.

**Technical**

7. Zero overselling incidents under concurrent load.
8. Zero unexplained stock drift. Reconciliation drift is investigated, never absorbed.
9. Every performance budget in Doc 02 §10 and Doc 04 §13 met in production.
10. Strict PHPStan Level 8 clean build with full test coverage via Pest PHP.

Headline non-functional targets — full detail in Doc 07:

| Metric | Target |
|---|---|
| Order pad page, 100 rows, warm | under 800 ms |
| Catalogue page, faceted | under 1.5 s |
| Allocation lock hold, 6-line order | under 5 ms |
| Accessibility | WCAG 2.1 AA |
| Uptime | 99.5% |

---

## 9. Constraints and assumptions

**Constraints**

- Single development resource. Sequencing in `docs/ROADMAP.md` assumes it.
- Single PostgreSQL 16 instance at launch (managed with strict DDL constraints, partial covering indexes, and PgBouncer connection pooling in higher environments).
- Hostinger VPS (Ubuntu 24.04 LTS, London) at launch running Docker containerized environments. AWS (ECS/RDS/ElastiCache) is the recorded enterprise migration path. Consequences and sizing triggers in Doc 07 §11.5–11.6.
- Accounting integration is native Xero API sync plus a Sage-compatible CSV export. The general ledger remains outside this platform (§5.2).
- GBP only at launch. Currency columns exist; FX is additive.
- Single warehouse at launch. `location_id` exists throughout so a second is additive.
- UK VAT only. Reverse-charge and export scenarios are deferred (Doc 03 §13).

**Assumptions**

- Catalogue grows to roughly 5,000 SKUs. Index design assumes this order of magnitude.
- Order volume of the order of 200 orders per day. `stock_movements` growth in Doc 04 §10 derives from it.
- Stock remains authoritative here. No future ERP takes ownership (Doc 02 §13 Q5, recorded as closed unless challenged).
- Product imagery can be supplied or recovered. Bulk ingest matches filenames against `sku_code`, so the reference system's existing `LTC*.png` files resolve most of the backlog (Doc 02 §5.8).

---

## 10. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Pricing bug puts wrong money on sent invoices | High. Silent — no exception, no failed request | Integer-only arithmetic, one rounding boundary, 20 fixtures and 8 property tests (Doc 03 §12) |
| Overselling under concurrent load | High. Customer-facing and reputational | Ordered locking (`SELECT ... FOR UPDATE`), bounded retry, C1–C7 concurrency tests with zero-oversell acceptance (Doc 04 §12) |
| Stock projection drift | Medium. Erodes trust in every figure | Nightly reconciliation, P1 alerting, drift never auto-corrected |
| Catalogue data quality blocks launch | Medium. Missing images and descriptions are inherited | `completeness_score` and dashboard in FilamentPHP; flagged, not blocking |
| Scope creep from the reference system's feature list | Medium. Phase 1 never ships | Phased scope in §5, stage gates in `docs/ROADMAP.md` |
| Solo-developer bus factor | Medium | The document set is the mitigation. It is written to be handed over |
| Index design not matching real query plans | Medium. Found late, expensive to fix | Stage 1 is migrations plus `EXPLAIN (ANALYZE, BUFFERS)` assertions for Q1–Q19 in Pest PHP before feature work |

---

## 11. Open decisions register

Consolidated from all documents. Each is either additive or configuration; none blocks the schema.

| # | Question | Source | Blocking |
|---|---|---|---|
| 1 | Multi-currency at launch | 02 §13 | No — additive |
| 2 | `stock_movements` retention before partition pruning | 02 §13 | No — PK already prepared |
| 3 | Per-tier pack visibility | 03 §13 | No — additive table |
| 4 | Coupon stacking beyond one per order | 03 §13 | No — Phase 2 |
| 5 | Reverse-charge and export VAT | 03 §13 | No |
| 6 | Which SKUs enable batch/serial at launch; expiry capture per category | 02 §13 | No — configuration |
| 7 | Allocation hold window for unpaid orders (2 h assumed) | 04 §14 | No — configurable |
| 8 | Max batches per line (5 assumed) | 04 §14 | No — configurable |

**Closed by decision:** dual variant model · base-unit breaks · `e4` precision · batch and serial from migration one · both pricing mechanisms with two-pass application · collection allocation at placement · location-level allocation with advisory bins · PostgreSQL 16 + Laravel 11 + React + FilamentPHP stack selection · sixteen-document structure (Correction 2026-09-20 — was ten) · **rep commission basis, net margin or net revenue, never gross** (Correction 2026-09-20 — `05.8-dropship-rep-tools.md` line 10 states it explicitly closes "02 §13 Q3 (commission basis)"; `rep_commission_rules.basis`/`rep_commissions.basis` are `CHECK (... IN ('net_margin','net_revenue'))`, no `gross` option, `net_margin` the default — this was listed above as still open, which no longer matches the signed-off schema).

---

## 12. Working principle

**Documentation precedes implementation.** This was not a preference, and the set has already paid for itself three times:

1. Writing Doc 03 exposed that whole-pence price storage under-invoices systematically on a low-value catalogue. On paper, a column definition. After launch, a migration across every historical order line with the true prices already rounded away.
2. Deciding batch and serial tracking early revealed that one order line is routinely filled from several batches — which required widening the unique key on `stock_allocations`. Later, that means rebuilding the index guarding against overselling, on a live system, while orders are being placed.
3. Specifying the spend-break pass exposed that an unapportioned order discount produces incorrect per-line VAT on mixed-rate orders, in the customer's disfavour, on every discounted order.

None of these would have surfaced from writing code first. Each would have surfaced from a customer.