# Delivery Roadmap — File-by-File

**Generated 2026-09-17.** Source of truth for *what still needs building*, cross-checked
against the actual repo state (43 migrations, `app/Domain/Inventory` + `app/Domain/Reference`,
bare Inertia/Filament/realtime-gateway scaffolding) and every active doc under `docs/*.md`
(`docs/archive/` excluded — superseded, not cited).

Conventions used below:

- `[ ]` one concrete file to create or edit. Nested items under a `[ ]` are sub-steps in the
  same file or tightly-coupled siblings, not separate roadmap entries.
- Ordered **within each section** so a domain service precedes the controller that calls it,
  which precedes the Filament resource / React page that renders it — per the task brief.
- **⚠ BLOCKING** marks anything that stops other work or must not ship as-is.
- **⛔ DOC GAP** marks a table Appendix A (02) lists as having full DDL, or a module doc implies
  is specified, where no `CREATE TABLE` actually exists anywhere in the doc set. Per CLAUDE.md
  ("do not invent a table/column/index not in `02-domain-model-erd.md`"), these need a doc
  amendment — proposed and signed off in its own commit — **before** a migration is written.

---

## 0. Blocking issues — resolve before the dependent work below

1. **⚠ BLOCKING — Realtime gateway has zero room-join authorisation.**
   `docs/11-realtime-gateway.md` §6/§8 explicitly flags this as blocking before any client
   carrying real tenant data connects. `services/realtime-gateway/src/server.ts` currently
   does `socket.on('join', (room) => socket.join(room))` with no verification at all — any
   connected client can join `company:<any-id>` or `warehouse:<any-location>` and receive
   another tenant's stock/order events. Do not wire any real page to this gateway until
   §14 (Realtime Gateway Hardening) below is done.

2. **⚠ BLOCKING (schema authority) — `docs/02-domain-model-erd.md` Appendix A claims "full DDL
   here" for `roles`, `role_user`, `attachments`, `carts`, `cart_lines`, `payments`, `invoices`,
   but no `CREATE TABLE` for any of the seven exists anywhere in the currently active doc set.**
   §8.4 defers `carts`/`cart_lines`/`payments`/`invoices` to "the referenced module spec"
   (05.1/05.2), but 05.1 and 05.2 as written contain no such DDL either — only the key-shape
   summary row in 02 §8.4. `roles`/`role_user` are referenced in passing (`03 §6.3`:
   `roles.default_max_discount_bp`; `07 §6.1`: staff roles) but never defined.
   **Action: propose a doc amendment to `docs/02-domain-model-erd.md` §8.4 (or a new §4.7 for
   roles) with full DDL for these seven tables, signed off in its own commit, before writing
   any of the migrations that depend on them.** Do not improvise the schema to unblock a
   controller — that is exactly the invented-table CLAUDE.md forbids.

3. **⛔ DOC GAP — the same is true for a second cluster of Phase-2 tables**, named in
   Appendix A's Phase 2 inventory and/or a module doc's prose, with only a one-line key
   summary in 02 §8.4 and no `CREATE TABLE` in the module doc that supposedly owns them:
   `saved_lists`, `saved_list_lines` (05.1), `credit_notes` (05.4 — only its ledger,
   `account_credit_movements`, is fully specified), `shipments`, `shipment_lines`,
   `shipment_line_batches`, `shipment_line_serials` (05.5), `stocktakes`, `stocktake_lines`
   (05.5), `promotions`, `promotion_rules`, `coupons` (referenced throughout 03 §7 but never
   defined), `back_in_stock_subscriptions` (04 §8). Each module section below calls this out
   again at the point it blocks; **fix once, here, before starting the affected module.**

4. **Client decision outstanding** — `docs/05.6-delivery-collection.md` §15 Q1: which figure
   is the minimum order value and which is the carriage-paid threshold (the reference
   system's FAQ-vs-T&Cs contradiction). Both are modelled as independent configured values
   already, so this doesn't block schema or code — only the go-live seed values for
   `system_configurations` keys `orders.minimum_value_net_minor` and
   `delivery.carriage_paid_threshold_net_minor`. Do not guess a number into a seeder.

5. **No CI pipeline exists.** No `.github/workflows/*`. `composer test`, `composer lint`,
   `composer analyse` all run only if a human remembers to. Given NFR §2.5/§14 requires CI
   gates on performance-budget regression, correctness properties, and security scans, this
   is worth fixing early rather than after the first regression ships silently — see §16.

6. **⛔ DOC GAP — audit log has no table.** `07-nfr.md` §6.5 mandates an immutable, 7-year
   append-only audit log across nine event families (auth, credit-limit changes, price
   changes, manual overrides, fee waivers, stock adjustments, config changes, rep
   impersonation, RMA dispositions). `02-domain-model-erd.md` §1 declared audit log storage
   out of scope (corrected 2026-09-20, see that section) — a hole between the two documents,
   not a decision either made. Already-signed-off constraints assume a trail exists to write
   to: `rmas_waiver_chk` (05.4 §5) and `roles.granted_by_user_id` (02 §14.1). No call site in
   the built code currently writes to a nonexistent audit table (verified 2026-09-20 — the
   only hit is a docblock comment in `OrderLinePricer.php`, not a write), so this is not yet
   a live bug, but it blocks correct implementation of every privileged action once those
   constraints' call sites are built. **Action: propose `docs/02-domain-model-erd.md` §15
   (`audit_log`), signed off in its own commit, before any code writes to it.** See §18.

7. **Seed/demo data and the 920-SKU reference import are unscheduled and block earlier work
   than their natural place suggests.** Filament resources (§15) and the `HotPathExplainTest`
   performance suite are both effectively untestable against empty tables — the former
   because an admin screen with nothing in it proves little, the latter because several of
   its assertions (BRIN bitmap-scan choice, planner statistics) are explicitly volume-
   dependent (see the BRIN flakiness fix already documented for `Q10`). The 920-SKU catalogue
   (01 §3.2, "the migration and load-test fixture") is the one realistic dataset this project
   has; used late, every module gets built and demoed against synthetic factory data instead.
   **Action: a demo seeder early, the real import once `docs/08-migration-seed.md` exists —
   see §26.**

---

## 1. `02` — Domain Model: remaining Phase-1 schema

Tables from Appendix A's Phase-1 list that have complete DDL in `02-domain-model-erd.md`
but no migration yet. Ordered per Appendix A's own dependency order (§Appendix A
"Migration ordering").

- [ ] `database/migrations/2026_09_25_090100_create_addresses_table.php` — 02 §4.5. Depends on
      `companies`, `delivery_zones` (both already migrated).
- [ ] `app/Models/Address.php` + `database/factories/AddressFactory.php`
- [ ] `database/migrations/2026_09_25_090200_create_b2b_applications_table.php` — 02 §4.6,
      including the `info_requested` status value from 05.2 §4.5's amendment to the enum
      (add it directly in this migration's `CHECK`, not as a later `ALTER`, since the table
      doesn't exist yet).
- [ ] `app/Models/B2bApplication.php` + `database/factories/B2bApplicationFactory.php`
- [ ] `database/migrations/2026_09_25_090300_create_category_closure_table.php` — 02 §5.3.
      **Note:** `app/Models/CategoryClosure.php` and `database/factories/CategoryClosureFactory.php`
      already exist and reference a `category_closure` table that has no migration — this is a
      live bug (model references a non-existent table), not just a missing feature. Fix first.
- [ ] `app/Domain/Catalogue/CategoryClosureMaintainer.php` — 02 §5.3 says closure-table
      maintenance "is domain logic and lives with the catalogue service, not on this model"
      (per the model's own docblock). Nothing under `app/Domain/Catalogue/` exists yet;
      this is the first file in that context. Recomputes ancestor/descendant/depth rows
      whenever a category's `parent_id` changes.
- [ ] `database/migrations/2026_09_25_090400_create_attributes_table.php` — 02 §5.7
      (`attributes`, `attribute_values`, `product_variant_axes`, `sku_attribute_values` —
      four tables, one migration file per the existing convention of one table per file, so
      split into four: `..._create_attributes_table.php`,
      `..._create_attribute_values_table.php`, `..._create_product_variant_axes_table.php`,
      `..._create_sku_attribute_values_table.php`).
- [ ] `app/Models/Attribute.php`, `AttributeValue.php`, `ProductVariantAxis.php`,
      `SkuAttributeValue.php` + factories for each.
- [ ] `database/migrations/2026_09_25_090800_create_media_table.php` — 02 §5.8.
- [ ] `app/Models/Media.php` + `database/factories/MediaFactory.php`.
- [ ] `database/migrations/2026_09_25_090900_create_stock_serials_table.php` — 02 §7.6.
      Depends on `skus`, `batches`, `locations`, `bins`, `order_lines` (all already migrated).
- [ ] `app/Models/StockSerial.php` + `database/factories/StockSerialFactory.php` — include a
      `identity()`-style query scope mirroring `StockLevel::identity()` for
      `(sku_id, location_id, batch_id, status='in_stock')`, since `04 §6.1`'s serial-reservation
      query needs exactly that shape.

**Blocked pending doc amendment (§0.2 above)** — do not start until the amendment lands:

- [ ] `database/migrations/xxxx_create_roles_table.php`
- [ ] `database/migrations/xxxx_create_role_user_table.php`
- [ ] `database/migrations/xxxx_create_attachments_table.php` (polymorphic
      `attachable_type`/`attachable_id`, per 02 §4.6's forward reference)
- [ ] `database/migrations/xxxx_create_carts_table.php`
- [ ] `database/migrations/xxxx_create_cart_lines_table.php`
- [ ] `database/migrations/xxxx_create_payments_table.php`
- [ ] `database/migrations/xxxx_create_invoices_table.php`
- [ ] Corresponding `app/Models/*.php` + factories once each migration exists.

---

## 2. `03` — Pricing Engine (`app/Domain/Pricing`)

**Currently empty.** This is the single highest-risk gap: CLAUDE.md invariant 3/4/9 and all
of doc 03 are fully specified with worked examples and a 20-fixture/8-property test matrix,
and none of it is implemented. Order placement, the order pad, quotes and RMA refunds all
depend on this. Build in this order:

- [ ] `app/Domain/Pricing/Money.php` — the value object CLAUDE.md invariant 1 requires: refuses
      cross-scale (`_e4` vs `_minor`) arithmetic, wraps `roundHalfUpDiv()` (03 §6.2's exact
      integer algorithm). Everything else in this section depends on this existing first.
- [ ] `app/Domain/Pricing/Exceptions/NotPurchasableException.php`
- [ ] `app/Domain/Pricing/Exceptions/PriceUnavailableForCurrencyException.php`
- [ ] `app/Domain/Pricing/Exceptions/InvalidQuantityException.php` — 03 §4.6's four failure
      modes, one exception class each (the fourth, "SKU has no base list row", is a hard
      data-integrity error rather than a runtime exception — see the catalogue-validation
      task in §3 below).
- [ ] `app/Domain/Pricing/ResolvedPrice.php` — the readonly DTO from 03 §4.5 exactly as
      specified, including `unitCostE4` nullable-outside-admin-context per CLAUDE.md invariant
      9 and `promotionCapped`.
- [ ] `app/Domain/Pricing/PriceResolver.php` — implements 03 §4: the precedence chain
      (contract → customer → promotion → tier → base), the break-selection rule (§4.3,
      "falls through to the next rank" when no break row qualifies), the single round-trip
      query of §4.4 against `price_list_items`/`price_lists` using
      `price_list_items_by_sku_idx`, and the promotion-cap re-resolution of §4.5. This is the
      class every other pricing task below calls.
- [ ] `app/Domain/Pricing/BulkPriceResolver.php` — 03 §8's three-queries-per-page path (Q-A/Q-B/Q-C
      candidate lists, break rows via `price_list_items_resolve_idx`, stock levels), returning
      the full break table per SKU rather than one resolved price — this is what the order pad
      (05.1) and `/pricing/bulk-resolve` (06 §9.1) both call, and it is a genuinely different
      code path from `PriceResolver` per 03 §8's own note on which index each uses.
- [ ] `app/Domain/Pricing/OrderLinePricer.php` — 03 §6.3, the single-line rounding sequence
      (`unit_price_net_e4 → gross_line_e4 → line_discount_e4 → net_line_e4 →
      line_net_minor` — the *only* `e4→minor` conversion — `line_tax_minor → line_gross_minor`).
      Called once per order line at checkout/quote-build/RMA time.
- [ ] `app/Domain/Pricing/SpendBreakResolver.php` — 03 §7A.2, the once-per-order query against
      `order_spend_breaks` via `order_spend_breaks_resolve_idx`, returning at most one break.
- [ ] `app/Domain/Pricing/SpendBreakApportioner.php` — 03 §7A.3's apportionment algorithm
      exactly, including the remainder-to-largest-line rule. This is the highest-value place
      to hand-verify against the §7A.5 worked example (three lines, mixed VAT, £32.40 discount
      apportioned as 1860/930/450) as a literal test fixture before anything else touches it.
- [ ] `app/Domain/Pricing/OrderPricingPipeline.php` — orchestrates the three-phase sequence of
      03 §7A.4 end to end (item pricing → spend break → tax-last), the object every checkout/
      quote-conversion/RMA-refund call site should call rather than composing the pieces above
      by hand. Tax computed **last**, on post-discount line values — §7A.4's whole point.
- [ ] `app/Domain/Pricing/TaxRateResolver.php` — 03 §10: resolves `tax_rates` from
      `skus.tax_class_id` + delivery-address `country_code` + order timestamp; applies
      `companies.tax_exempt`.
- [ ] `app/Domain/Pricing/DiscountAuthorityResolver.php` — 05.3 §6.3's category-tree walk via
      `category_closure_descendant_idx` + `rep_category_discount_limits_resolve_idx`. Placed
      here rather than under a Quotes namespace because 05.8 §10.1 (commission rate
      resolution) reuses the identical query shape — factor the closure-table-walk query into
      a shared `app/Domain/Pricing/CategoryTreeResolver.php` helper both call.
- [ ] `app/Domain/Pricing/MarginCalculator.php` — 03 §11's `line_margin_minor`/`line_margin_pct`
      from the snapshotted `unit_cost_e4`, gated so it is never constructed in a
      customer-facing context (policy gate, not an omitted field — enforce via a constructor
      guard that requires an explicit `AdminContext`/`RepContext` marker object).
- [ ] `app/Domain/Pricing/PricingCache.php` — 03 §9's four cache keys
      (`pricing:lists:{company_id}`, `pricing:breaks:{list_id}:{sku_id}`,
      `pricing:promos:eligible:{company_id}`, `pricing:tax:{tax_class_id}:{country}`),
      event-driven invalidation (never TTL-only) via listeners on writes to `price_list_items`,
      `price_lists`, `promotions`/`promotion_rules` (blocked on §0.3's promotions-table gap —
      build the cache class now, wire the promotion-eligibility key once that table exists),
      `tax_rates`.
- [ ] `app/Listeners/FlushPricingCacheOnPriceListItemChanged.php` — dispatched from a
      `PriceListItemChanged` domain event (new `app/Domain/Pricing/Events/PriceListItemChanged.php`)
      fired after commit on any write to `price_list_items`.
- [ ] `app/Rules/Pricing/NoFloatInPricingNamespace.php` — the custom PHPStan rule CLAUDE.md's
      `composer analyse` comment ("static analysis incl. no-float-in-pricing rule") and 03 §12's
      "No floats" property test both require, and which does not exist yet. Register it in
      `phpstan.neon` (currently just `larastan/extension.neon` at level 8 with no custom
      rules) as a rule forbidding `float`/`double` casts and arithmetic anywhere under
      `app/Domain/Pricing`.
- [ ] `tests/Feature/Domain/PriceResolverTest.php` — the 12 worked-example fixtures from 03 §12
      verbatim, including fixture 7 (the £0.9212 sub-penny break at 1,440 units → £1,326.53).
- [ ] `tests/Feature/Domain/SpendBreakApportionerTest.php` — the §7A.5 worked example plus
      the 8 additional test scenarios from §7A.7 (#13–#20: threshold off-by-one, competing
      breaks, `max_discount_minor` cap, contract-line exclusion, remainder-penny placement,
      mixed-VAT reproduction, fixed-discount clamping, item-break-and-spend-break-together).
- [ ] `tests/Feature/Domain/PricingPropertyTest.php` — the 8 property tests from 03 §12
      (monotonicity, pack invariance, total consistency, discount apportionment exactness,
      snapshot immutability, determinism at 1,000 runs, precedence completeness, index-only
      resolution) as Pest property/generative tests.
- [ ] `tests/Feature/Performance/PriceListItemsResolveExplainTest.php` — extends the existing
      `HotPathExplainTest.php` pattern to assert Q-B (bulk resolve) shows
      `Index Only Scan` with `Heap Fetches: 0` on `price_list_items_resolve_idx`, per 03 §8's
      15 ms budget — currently one of the ~4 unexplained gaps in the "~18 of Q1–Q22" coverage
      mentioned in the task brief.

**Gaps found by the 2026-09-20 completeness audit, folded in here as the natural home:**

- [ ] `app/Domain/Pricing/PriceListPdfGenerator.php` — 01 §5.1's Phase 2 deliverable "price
      list and catalogue PDF generation," currently in no task list at all. Renders a
      customer's resolved price list (via `BulkPriceResolver`) to PDF for offline/print use;
      needs a queued job per `06-api-contract.md` §4.1's `202 Accepted` pattern for
      background-generated documents, not a synchronous request. [G8]
- [ ] `app/Domain/Catalogue/CataloguePdfGenerator.php` — the catalogue half of the same G8
      gap; product/SKU listing export, likely Filament-admin-triggered rather than
      customer-facing. Scope (which fields, per-category vs whole-catalogue) needs deciding
      when this is picked up — not designed here. [G8]
- [ ] `app/Domain/Pricing/CouponRedemptionService.php` — `coupons` is fully signed off
      (02 §14.9, DRAFT — awaiting sign-off) with `coupons_active_redeem_idx` built for exactly
      this lookup, but nothing applies a code: no service resolves a `code` to a coupon,
      validates it (`status`, `validity`, `min_order_value_minor`, `scope`/`price_tier_id`/
      `company_id` eligibility, `usage_limit_total`/`usage_limit_per_company` via
      `orders_coupon_company_idx`), or writes the `orders.coupon_id`/`coupon_discount_minor`
      pair. Sits between `OrderLinePricer` (Pass 1) and `OrderPricingPipeline` in the checkout
      flow — a coupon discount is a `lineDiscountE4` input to `OrderLinePricer`, per 03 §7's
      "coupon / manual override" line-discount path already wired into that class today. [G9]
- [ ] `tests/Feature/Domain/CouponRedemptionServiceTest.php` — including the `times_used`/
      `usage_limit_per_company` race (same lock-ordering discipline as `AllocationService`)
      and the cancelled-order exclusion already documented at 02 §14.9's rebuild formula. [G9]

---

## 3. `03`/`02` — Catalogue validation gap this exposes

- [ ] `app/Domain/Catalogue/SkuActivationValidator.php` — 03 §4.6: "SKU has no `base` list
      row → Hard error. SKU cannot be sold. Blocked at activation." No such validator exists
      yet, and without it `PriceResolver` can be handed an active SKU that has no fallback
      price, which §4.6 says must never happen. Runs on the `skus.status` transition to
      `active` (a model observer or a dedicated `ActivateSku` action — prefer the latter,
      since "thin controllers/models, no business logic in models" per CLAUDE.md conventions).

---

## 4. `04` — Inventory Ledger hardening

`AllocationService`, `DeallocationService`, `DeadlockRetryPolicy` are built and match §4.2–4.5
closely (verified by reading `app/Domain/Inventory/AllocationService.php` and
`DeallocationService.php` directly — lock ordering, `NULLS FIRST`, no-external-calls, retry
policy are all correctly in place). What's missing is everything doc 04 specifies *beyond*
the core allocate/deallocate transaction:

- [ ] `app/Domain/Inventory/BatchSelector.php` — 04 §5: FEFO/FIFO/LIFO candidate selection
      per `skus.allocation_strategy`, the eligibility filter (§5.1: active status, available
      qty, shelf-life, sellable location), and `FOR UPDATE SKIP LOCKED` for concurrent
      candidate selection. `AllocationService::allocate()` currently takes `AllocationLine`s
      with the batch already chosen by the caller (see `AllocationLine.php`'s constructor) —
      this class is what a caller uses to *produce* that batch choice before calling
      `AllocationService`, and it does not exist yet. Depends on the `batches` migration
      (already built) and `stock_serials` migration (§1 above).
- [ ] `app/Domain/Inventory/SerialSelector.php` — 04 §6.1's reservation-at-allocation query
      (`stock_serials` at `status='in_stock'`, `ORDER BY id ASC LIMIT :qty FOR UPDATE`) and
      §6.2's status machine transitions (`in_stock → allocated`). Depends on the
      `stock_serials` migration (§1).
- [ ] `app/Domain/Inventory/BatchSplitter.php` — 04 §5.3: greedily consumes batches in
      strategy order until a line's `base_qty` is satisfied, capped at the configured max
      batches per line (default 5, from `system_configurations`), raising a warning rather
      than failing past the cap.
- [ ] `app/Domain/Inventory/Events/StockReceived.php`, `StockAllocated.php`,
      `StockDeallocated.php`, `StockDispatched.php`, `StockAdjusted.php`,
      `LevelBelowReorderPoint.php`, `BatchExpiringSoon.php`, `BatchRecalled.php`,
      `ProjectionDriftDetected.php` — 04 §11's full event list. None exist yet;
      `AllocationService`/`DeallocationService` currently do not dispatch any domain events
      after commit, which every downstream consumer (back-in-stock notifications, warehouse
      queue, dispatch email, recall trace, P1 alerting) needs. Dispatch via
      `DB::afterCommit()` per CLAUDE.md invariant 6/04 §4.4 — never inside the locked
      transaction.
- [ ] `app/Console/Commands/ReconcileStockLevels.php` — 04 §9's nightly ledger-vs-projection
      job (the rebuild query from §2.2, `IS NOT DISTINCT FROM` for null-safe batch
      comparison), writing a result record and firing `ProjectionDriftDetected` on any
      mismatch. **Never auto-corrects** — 04 §9 and CLAUDE.md are explicit that this is a
      hard invariant, not a style choice.
- [ ] `app/Console/Commands/ReconcileStockAllocations.php` — 04 §9's hourly
      `Σ active allocations = allocated_base_qty` check.
- [ ] `app/Console/Commands/ReconcileSerialCounts.php` — 04 §9/§6.3's nightly serial-count
      invariant check. Depends on `stock_serials` (§1).
- [ ] `app/Console/Commands/ReapStaleAllocations.php` — 04 §9's every-15-minutes job releasing
      `pending_payment` allocations past the hold window (default 2 h, configurable via
      `system_configurations`). Must respect 05.2 §10's longer 48 h window for
      `awaiting_approval` orders and 05.6 §7.3's "never reap a paid collection no-show" rule —
      write this *after* those two module features exist, or it will reap orders it
      shouldn't; note the dependency explicitly in the job's docblock.
- [ ] `app/Console/Commands/SweepExpiredBatches.php` — 04 §9's nightly `active → expired`
      transition past `expires_on`, plus the 30-day expiring-soon report firing
      `BatchExpiringSoon`.
- [ ] `app/Console/Kernel.php` schedule registration for all five jobs above (currently no
      `routes/console.php` scheduling beyond the default; verify against
      `bootstrap/app.php`'s `withRouting(commands: ...)` wiring).
- [ ] `tests/Feature/Domain/BatchSelectorTest.php` — FEFO across 3 batches, `min_remaining_shelf_life`
      exclusion, quarantined-batch exclusion (04 §12's flow fixtures).
- [ ] `tests/Feature/Domain/SerialSelectorTest.php` — receipt → allocation → pick → dispatch
      flow fixture; blocked-not-warned assertion for scanning an unallocated serial.
- [ ] `tests/Feature/Concurrency/StockConcurrencyTest.php` — the C1–C7 concurrency matrix from
      04 §12 that isn't yet covered by the existing `StockAllocationTest.php`/
      `AllocationServiceTest.php` (verify overlap first; at minimum C7 — walk-in till racing a
      collection booking — needs 05.6's collection booking to exist first, so is blocked on
      §9 below).

---

## 5. `05.1` — Order Pad

Blocked on §2 (`PricingEngine`, specifically `BulkPriceResolver`) and §1's `carts`/`cart_lines`
migrations (§0.2 doc-gap block). Sequence once unblocked:

- [ ] `app/Domain/Ordering/CartService.php` — server-side pad state (05.1 §8.3): add/update/
      remove lines, pack-change-as-line-update (not delete+re-add), last-write-wins on
      concurrent edits with a change notice.
- [ ] `app/Domain/Ordering/BulkEntryParser.php` — 05.1 §7.1's paste-SKU tolerant parser
      (comma/semicolon/tab/whitespace separators, case-insensitive lookup against
      case-sensitive `sku_code`), producing the reconciliation classification (matched /
      adjusted / not-found / inactive / duplicate) before anything touches the cart.
- [ ] `app/Domain/Ordering/CsvBulkImportJob.php` — 05.1 §7.2, queued for files over 500 rows,
      same reconciliation output as the paste parser, downloadable rejection report.
- [ ] `app/Domain/Ordering/BarcodeResolver.php` — 05.1 §8.2: matches against
      `skus.barcode_ean` then `packs.barcode`.
- [ ] `app/Http/Requests/Api/BulkAddCartRequest.php`, `AddCartLineRequest.php`,
      `UpdateCartLineRequest.php`
- [ ] `app/Http/Controllers/Api/CartController.php` — `/cart`, `/cart/lines`,
      `/cart/lines/{id}`, `/cart/bulk-add`, `/cart/apply-account-credit` per 06 §8 (the last
      one blocked on 05.4 §7.5A's account-balance ledger, §8 below).
- [ ] `app/Http/Controllers/Api/PricingController.php` — `/pricing/resolve`,
      `/pricing/bulk-resolve` (06 §9.1's exact payload shape — full break table returned,
      cost/margin absent).
- [ ] `app/Http/Resources/CartResource.php`, `CartLineResource.php`,
      `BulkResolveResource.php` — money/quantity suffix discipline per 06 §3 enforced at
      serialisation (ULID `public_id` only, never the auto-increment `id`, per 06 §2).
- [ ] `app/Policies/CartPolicy.php`
- [ ] `resources/js/lib/pricing/localRecompute.ts` — 05.1 §5.1's client-side calculator:
      given the full break table from `bulk-resolve`, recompute unit price/line total/
      subtotal/free-delivery progress/spend-break progress on every quantity change with
      **zero network calls**. This is the single most performance-sensitive piece of
      frontend logic in the app (P24 budget: <50ms, no network) and must exactly mirror
      `OrderLinePricer`'s arithmetic or client/server totals will disagree (05.1 §11's
      "any mismatch is a test failure" numeric acceptance criterion).
- [ ] `resources/js/pages/OrderPad/Index.tsx` — replaces the placeholder
      `resources/js/pages/Dashboard.tsx` as the primary authenticated landing page; 05.1 §4.1's
      layout (search/filter bar, row table, sticky footer).
- [ ] `resources/js/pages/OrderPad/components/PadRow.tsx` — one row: thumbnail, pack selector,
      price + break table, stock display (05.1 §4.3's exact-figures-not-banding rule), qty
      stepper, line total.
- [ ] `resources/js/pages/OrderPad/components/PasteSkusDialog.tsx`,
      `CsvUploadDialog.tsx` — reconciliation-screen UI per 05.1 §7.
- [ ] `resources/js/pages/OrderPad/components/StickyFooter.tsx` — running total, free-delivery
      and spend-break progress (05.1 §5.3).
- [ ] `resources/js/lib/keyboard/tabOrder.ts` — 05.1 §8.1's keyboard contract (Tab/Shift+Tab
      skip non-inputs, ↑/↓ step by pack, Enter commits, `/` focuses search, no keyboard traps).
- [ ] `resources/js/pages/OrderPad/components/BarcodeScanner.tsx` — 05.1 §8.2 mobile camera
      scanning, device-camera-gated (feature-detect, no crash on desktop).
- [ ] `app/Models/Cart.php`, `CartLine.php`, `SavedList.php`, `SavedListLine.php` +
      factories — blocked on `carts`/`cart_lines` (§0.2) and `saved_lists`/`saved_list_lines`
      (§0.3) migrations landing first.
- [ ] `app/Domain/Ordering/SavedListService.php`, `ReorderService.php` — 05.1 §7.3.
- [ ] `tests/Feature/OrderPadTest.php` — functional matrix from 05.1 §11 (pack selection,
      break crossing, MOQ/increment adjustment, paste separator styles, reorder flagging
      discontinued lines, barcode matching both `barcode_ean` and `packs.barcode`).
- [ ] `tests/Feature/Performance/OrderPadExplainTest.php` — the three-query-per-page assertion
      (05.1 §9) plus the "query count constant as rows go 10→100" N+1 guard.

---

## 6. `05.2` — B2B Accounts & Credit

Note: `companies` already has `credit_held_minor` and `account_balance_minor` columns from
the original migration (verified in `database/migrations/2026_09_15_160400_create_companies_table.php`)
— **05.2 §7.2's "amendment to Doc 02 §4.3" is already satisfied**; do not re-apply it.
`AllocationService` already locks the `companies` row first per §8.2's lock order and checks
`credit_limit − used − held + balance`, but per its own docblock deliberately does **not**
write to `credit_held_minor` because `credit_holds` doesn't exist yet. Build order:

- [ ] `database/migrations/2026_09_26_090100_create_credit_holds_table.php` — 05.2 §7.1, full
      DDL already specified, ready to migrate directly.
- [ ] `app/Models/CreditHold.php` + `database/factories/CreditHoldFactory.php`
- [ ] `database/migrations/2026_09_26_090200_create_b2b_application_status_info_requested.php`
      — only needed if §1's `b2b_applications` migration didn't already include
      `info_requested` in the `CHECK` (it should — see §1's note).
- [ ] `app/Domain/Accounts/ApplicationReviewService.php` — 05.2 Part A: the state machine
      (§4), duplicate detection (§5.2), the one-transaction approval sequence (§5.6:
      create/link `companies`, set tier/terms/limit, generate `account_code` via
      `NumberSequenceService` — already built in `app/Domain/Reference/` — link/create
      `users` + `company_users(role=owner)`, copy application address, flush
      `pricing:lists:{company_id}`).
- [ ] `app/Domain/Accounts/CreditCheckService.php` — 05.2 §8: `available = limit − used −
      held`, the decision table (proceed / prepay-required / awaiting_approval / suspended /
      overdue-blocked). **This must extend, not duplicate, `AllocationService`'s existing
      credit-lock logic** — refactor `AllocationService::lockCompanyCredit()` to call into
      this service rather than reimplementing the arithmetic a second time.
- [ ] `app/Domain/Accounts/CreditHoldWriter.php` — writes the `credit_holds` row and updates
      `credit_held_minor` at confirmation, converts hold→invoiced at invoicing, releases on
      cancellation/reaper (05.2 §8.3's full lifecycle table). **Wire this into
      `AllocationService::writeAllocations()`** so the existing service starts actually using
      `credit_holds` instead of the current credit-check-without-a-ledger-entry state
      described in its docblock.
- [ ] `app/Console/Commands/ReconcileCreditProjections.php` — 05.2 §7.2's hourly
      `credit_used_minor`/`credit_held_minor` rebuild-and-compare job, P1 on drift.
- [ ] `app/Console/Commands/ReapStaleCreditHolds.php` — `credit_holds_reaper_idx`-driven,
      mirrors the stock reaper (04 §9); these two reapers should share one scheduled
      transaction per order per 05.2 §8.2's lock order, not run as two independent jobs that
      could race on the same order — implement as one `ReapAbandonedOrders.php` command
      that releases both the stock allocation and the credit hold together, superseding the
      separate `ReapStaleAllocations.php` stub in §4 above. **Resolve this overlap before
      writing either.**
- [ ] `app/Domain/Accounts/SuspensionService.php` — 05.2 §9's automatic suspension threshold
      check and manual reinstatement.
- [ ] `app/Http/Requests/Api/SubmitB2bApplicationRequest.php`,
      `ReviewB2bApplicationRequest.php`
- [ ] `app/Http/Controllers/Api/B2bApplicationController.php` — `/applications`,
      `/applications/{id}/request-info`, `/approve`, `/reject` (06 §8).
- [ ] `app/Http/Controllers/Api/CompanyController.php` — `/company`, `/company/users`,
      `/company/addresses`, `/company/credit` (06 §8).
- [ ] `app/Http/Resources/CompanyCreditResource.php` — limit/used/held/available/balance,
      never exposing raw `id`s.
- [ ] `app/Policies/B2bApplicationPolicy.php`, `CompanyPolicy.php`, `CompanyUserPolicy.php`
- [ ] `app/Filament/Resources/B2bApplicationResource.php` + `Pages/ListB2bApplications.php`,
      `Pages/ReviewB2bApplication.php` — the review queue UI (05.2 §5.4), sorted by
      `b2b_applications_queue_idx`, with approve/reject/request-info actions and attachment
      display (blocked on `attachments`, §0.2).
- [ ] `app/Filament/Resources/CompanyResource.php` — admin credit-limit/terms editing
      (`/admin/companies/{id}/credit`), suspension controls.
- [ ] `resources/js/pages/Account/Application.tsx` — public application form (05.2 §5.1's
      field list, VAT checksum validation client-side with server re-validation).
- [ ] `resources/js/pages/Account/ApplicationStatus.tsx` — applicant-facing status page
      (05.2 §4's per-state messaging).
- [ ] `resources/js/pages/Account/Credit.tsx` — buyer-facing available-credit display,
      shown pre-checkout per 05.2 §8.1.
- [ ] `tests/Feature/Domain/CreditCheckServiceTest.php` — CR1–CR5 concurrency matrix (05.2
      §13), especially CR1's 20-parallel-£600-orders-against-£10,000-limit exact-figure
      assertion.
- [ ] `tests/Feature/ApplicationReviewTest.php` — the full workflow fixture list from 05.2
      §13 (submit → info-requested → approved with tier; duplicate VAT flagged not
      auto-rejected; second-site linking).

---

## 7. `05.3` — Quotes & RFQ

Blocked on §2 (pricing engine, specifically `OrderPricingPipeline` and
`DiscountAuthorityResolver`) and §6 (credit check, for acceptance-time checking per §7.2).

- [ ] `database/migrations/2026_09_27_090100_create_quotes_table.php` — 05.3 §5.1, full DDL
      ready to migrate directly.
- [ ] `database/migrations/2026_09_27_090200_create_quote_lines_table.php` — 05.3 §5.2.
- [ ] `database/migrations/2026_09_27_090300_create_rep_category_discount_limits_table.php`
      — 05.3 §6.3.
- [ ] `app/Models/Quote.php`, `QuoteLine.php`, `RepCategoryDiscountLimit.php` + factories.
- [ ] `app/Domain/Quotes/QuoteBuilder.php` — 05.3 §6.1: runs `PriceResolver`/
      `OrderPricingPipeline` per line, applies rep overrides with mandatory reason codes,
      recomputes via the §7A.4 three-phase sequence.
- [ ] `app/Domain/Quotes/MarginGuardrailService.php` — 05.3 §6.2's floor logic (proceed /
      warn-with-approval / block-unless-manager-approves), resolved most-specific-first
      (global → category → tier).
- [ ] `app/Domain/Quotes/QuoteConversionService.php` — 05.3 §8's one-transaction acceptance:
      lock order companies→stock, copy accepted lines, refresh `unit_cost_e4` (quoted price
      honoured, cost refreshed), credit check + hold, allocate stock, set
      `converted_order_id`. **Must call `AllocationService::allocate()` and
      `CreditCheckService`/`CreditHoldWriter` rather than reimplementing either.**
- [ ] `app/Domain/Quotes/QuoteRevisionService.php` — 05.3 §9's supersession chain.
- [ ] `app/Console/Commands/SweepExpiredQuotes.php`, `SweepQuoteReminders.php`,
      `SweepStaleDraftQuotes.php` — 05.3 §10's three scheduled jobs.
- [ ] `app/Domain/Quotes/QuotePdfGenerator.php` — 05.3 §11, queued (Node/Puppeteer worker per
      doc 01 §5.1 — see §15 below for the worker itself), never includes cost/margin.
- [ ] `app/Http/Requests/Api/BuildQuoteRequest.php`, `SendQuoteRequest.php`,
      `AcceptQuoteRequest.php`
- [ ] `app/Http/Controllers/Api/QuoteController.php` — full endpoint set from 06 §8 (`/quotes`,
      `/quotes/{id}/lines`, `/submit-for-approval`, `/approve`, `/send`, `/withdraw`,
      `/revise`, `/accept`, `/reject`).
- [ ] `app/Http/Controllers/Public/PublicQuoteController.php` — `/public/quotes/{token}`,
      tokenised unauthenticated accept/decline (05.3 §11).
- [ ] `app/Http/Resources/QuoteResource.php`, `QuoteLineResource.php` — cost/margin fields
      present only when the authenticated context is rep/manager/admin (03 §11's policy gate,
      never a customer-facing field to accidentally serialise).
- [ ] `app/Policies/QuotePolicy.php` — "a rep cannot approve their own quote" (05.3 §14) as an
      explicit policy rule, not a controller `if`.
- [ ] `app/Filament/Resources/RepCategoryDiscountLimitResource.php` — grant/revoke UI (05.3
      §6.3), audit-logged.
- [ ] `resources/js/pages/Quotes/Builder.tsx` — rep-facing quote builder with live margin
      display (05.3 §6.2) — cost visible here (rep context), never in
      `resources/js/pages/Quotes/PublicView.tsx` (customer-facing, no cost/margin field at
      all in its props, not just hidden in the UI).
- [ ] `resources/js/pages/Quotes/ApprovalQueue.tsx` — 05.3 §6.3's approval screen (total
      value, blended discount depth, blended margin, per-line authority breach detail).
- [ ] `tests/Feature/Domain/QuoteConversionTest.php` — Q1–Q3 concurrency matrix (05.3 §14):
      simultaneous acceptance producing exactly one order via `quotes_converted_order_uq`.
- [ ] `tests/Feature/Domain/DiscountAuthorityResolutionTest.php` — the six resolution-order
      assertions from 05.3 §14 (explicit category wins over ancestor, ancestor covers
      descendants, rep default fallback, role default fallback, zero-authority-means-zero
      not unlimited, per-line evaluation in a multi-category quote).

---

## 8. `05.4` — RMA & Returns

Blocked on §2 (pricing, for fee/refund/tax arithmetic reusing `Money`/`roundHalfUpDiv`) and
`credit_notes` (§0.3 doc gap — build the ledger and service now, wire the credit-note *table*
itself once its DDL is amended in).

- [ ] `database/migrations/2026_09_28_090100_create_rmas_table.php` — 05.4 §5.1, full DDL
      ready.
- [ ] `database/migrations/2026_09_28_090200_create_rma_lines_table.php` — 05.4 §5.2.
- [ ] `database/migrations/2026_09_28_090300_create_account_credit_movements_table.php` —
      05.4 §7.5A, including the 2026/default partitions exactly as specified (mirrors the
      existing `stock_movements` partitioning pattern).
- [ ] `app/Models/Rma.php`, `RmaLine.php`, `AccountCreditMovement.php` + factories.
- [ ] `app/Domain/Returns/EligibilityService.php` — 05.4 §6.1's five ordered rules
      (return window, cumulative-returned-quantity cap, `is_refundable`, dispatched-at-all,
      recall-overrides-everything).
- [ ] `app/Domain/Returns/RestockingFeeCalculator.php` — 05.4 §6.2's
      `max(20% × line_value, £25)` computed per line from **snapshotted**
      `system_configurations` values on the RMA row, never re-read from current config
      after request time. §6.3's fault-based-reasons-are-fee-free table.
- [ ] `app/Domain/Returns/RefundTaxCalculator.php` — 05.4 §6.4: VAT on net-refund-after-fee
      at the order line's **snapshotted** `tax_rate_bp`.
- [ ] `app/Domain/Returns/InspectionService.php` — 05.4 §7.4's disposition transaction
      (restock writes `return_in` via `AllocationService`'s sibling stock-movement path —
      **new** `app/Domain/Inventory/RestockService.php` for this, since neither
      `AllocationService` nor `DeallocationService` currently write `return_in` movements;
      quarantine/write-off/return-to-customer have no stock effect).
- [ ] `app/Domain/Returns/ResolutionService.php` — 05.4 §7.5's one-transaction resolution:
      compute fees/refunds, create credit note (blocked on `credit_notes` DDL, §0.3),
      decrement `credit_used_minor`, write `account_credit_movements`, increment
      `order_lines.returned_base_qty`.
- [ ] `app/Domain/Returns/AccountCreditLedger.php` — 05.4 §7.5A's append-only ledger writer
      and `account_balance_minor` projection maintainer, plus the "apply at checkout" flow
      (§7.5A "Applying balance at checkout" — this is what `/cart/apply-account-credit`
      from §5 above actually calls).
- [ ] `app/Console/Commands/ReconcileAccountBalances.php` — 05.4 §7.5A's hourly rebuild
      check, P1 on drift, alongside the existing credit reconciliation (§6).
- [ ] `app/Console/Commands/SweepNotReceivedRmas.php` — 05.4 §7.6.
- [ ] `app/Http/Requests/Api/RequestReturnRequest.php`
- [ ] `app/Http/Controllers/Api/RmaController.php` — `/returns/eligibility`, `/returns`,
      `/{id}/approve`, `/reject`, `/cancel`, `/resolve` (06 §8).
- [ ] `app/Http/Controllers/Api/Warehouse/RmaReceiptController.php` — `/warehouse/returns/{id}/receive`,
      `/inspect` (06 §8).
- [ ] `app/Policies/RmaPolicy.php`
- [ ] `app/Filament/Resources/RmaResource.php` — handler queue (`rmas_queue_idx`), fee-waiver
      action requiring reason (05.4 §5's `rmas_waiver_chk` mirrored as a required-field UI
      rule, not just a DB constraint the admin discovers on save-failure).
- [ ] `resources/js/pages/Returns/RequestReturn.tsx` — per-line eligibility + fee/refund
      quote **before submission** (05.4 §7.1's non-negotiable ordering).
- [ ] `resources/js/pages/Returns/Tracking.tsx` — customer-facing RMA status (05.4 U4).
- [ ] `tests/Feature/Domain/RestockingFeeCalculatorTest.php` — the F1–F8 fee fixtures from
      05.4 §10 verbatim (F1: £371.52 line → £74.30 fee; F2: £11.04 line → £25 fee, £0 refund
      clamp).
- [ ] `tests/Feature/Domain/AccountCreditLedgerTest.php` — the ledger property tests from
      §7.5A (20 parallel checkouts against one £500 balance apply at most £500 total; no
      `UPDATE`/`DELETE` ever issued, query-log asserted).

---

## 9. `05.5` — Goods-in, Picking & Dispatch

Blocked on §4's `BatchSelector`/`SerialSelector` and §0.3's `shipments`/`shipment_lines`/
`shipment_line_batches`/`shipment_line_serials`/`stocktakes`/`stocktake_lines` doc gap.

- [ ] `app/Domain/Inventory/GoodsInService.php` — 04 §7.1 / 05.5 §4: the receipt transaction
      (batch-tracked-without-batch-code rejected at entry, `requires_expiry` mandatory
      capture, variance with mandatory reason code, `sku_costs` row creation at `e4` scale).
- [ ] `app/Domain/Inventory/PickListGenerator.php` — 05.5 §5.1's per-shipment (not
      per-order) generation, sorted by `bins.walk_sequence` then SKU code.
- [ ] `app/Domain/Inventory/PickConfirmationService.php` — 05.5 §5.2/§5.4: blocks scanning
      an unallocated serial, short-pick handling (writes an `adjustment` movement with
      reason, re-plans the line).
- [ ] `app/Domain/Inventory/BatchSubstitutionService.php` — 05.5 §5.3's explicit
      reallocation (deallocation + allocation + audit entry), reusing
      `DeallocationService`/`AllocationService` rather than writing new stock-movement
      logic.
- [ ] `app/Domain/Inventory/DispatchService.php` — 04 §7.2 / 05.5 §7's atomic dispatch
      transaction (decrements `on_hand` and `allocated` together, updates
      `stock_allocations.status`/`stock_serials.status`, writes `shipment_lines` +
      `shipment_line_batches`/`_serials`, recomputes `orders.status`). **This is the one
      remaining core stock-mutation transaction (alongside restock, §8) that doesn't exist
      yet** — everything else in `app/Domain/Inventory/` handles allocation/deallocation
      only.
- [ ] `app/Domain/Inventory/StocktakeService.php` — 05.5 §8: session-based counting,
      variance-at-posting-time (not count-time), nothing written until posting.
- [ ] Idempotency key handling (05.5 §10) — `app/Http/Middleware/RequireIdempotencyKey.php`,
      applied to receipt/dispatch/serial-scan routes, backed by a
      `idempotency_keys` cache table or Redis-backed store (not in doc 02 — this is
      infrastructure, not a domain table, so it doesn't need a schema doc amendment; confirm
      that framing before building rather than assuming).
- [ ] `app/Http/Controllers/Api/Warehouse/ReceiptController.php`,
      `PickListController.php`, `ShipmentController.php`, `StocktakeController.php` — the
      full `/warehouse/*` endpoint set from 06 §8.
- [ ] `app/Policies/WarehouseOperationPolicy.php`
- [ ] `resources/js/pages/Warehouse/GoodsIn.tsx` — 05.5 §4.2's screen flow, conditional
      batch/expiry/serial capture per `tracking_mode` (§4.3), pack-quantity-with-live-base-unit-
      equivalent display.
- [ ] `resources/js/pages/Warehouse/PickList.tsx` — bin-ordered, batch/serial shown per line,
      48px touch targets, scan-everywhere (05.5 §9).
- [ ] `resources/js/pages/Warehouse/Dispatch.tsx` — partial-dispatch UI, delivery-type
      differentiation (delivery/collection/dropship per §7.1).
- [ ] `resources/js/pages/Warehouse/Stocktake.tsx` — blind-counting toggle, serial
      reconciliation as an individual-missing-serial list, not a quantity variance.
- [ ] `tests/Feature/Domain/DispatchServiceTest.php` — the atomicity + idempotent-retry
      assertion from 05.5 §14 acceptance criterion 6.
- [ ] `tests/Feature/Concurrency/WarehouseConcurrencyTest.php` — W1–W3 from 05.5 §13.

---

## 10. `05.6` — Delivery Zones, Rates & Collection Slots

Schema is **already fully migrated** (`delivery_zones`, `delivery_zone_postcodes`,
`delivery_rates` all present with models/factories per the task brief). What's missing is
the domain logic and everything downstream:

- [ ] `app/Domain/Delivery/ZoneResolver.php` — 05.6 §4.4's specificity-ordered postcode
      resolution query (`delivery_zone_postcodes_resolve_idx`). The `PO3`-vs-`PO31` test case
      from §11 is the canonical correctness check for this class specifically.
- [ ] `app/Domain/Delivery/RateResolver.php` — 05.6 §5.1's weight/method computation and
      §5.3's rate-band resolution + surcharge arithmetic (one rounding operation, integer,
      via the `Money` class from §2).
- [ ] `app/Domain/Delivery/ThresholdEvaluator.php` — 05.6 §6: minimum-order and
      carriage-paid-threshold checks against the **post-spend-break** net subtotal (depends
      on §2's `SpendBreakApportioner` having already run).
- [ ] `database/migrations/2026_09_29_090100_create_collection_slots_table.php` — 05.6 §7.1,
      full DDL ready.
- [ ] `database/migrations/2026_09_29_090200_create_collection_bookings_table.php` — 05.6
      §7.1.
- [ ] `app/Models/CollectionSlot.php`, `CollectionBooking.php` + factories.
- [ ] `app/Domain/Delivery/CollectionBookingService.php` — 05.6 §7.2's lock-order extension
      (companies → collection_slots → stock_levels). **This changes `AllocationService`'s
      contract**: it currently locks companies then stock_levels only (per its own docblock,
      "collection_slots — OMITTED... does not exist yet"). Once this table exists,
      `AllocationService::allocate()` needs a slot-locking hook inserted at position 2 for
      collection-type orders — do not build a parallel allocation path; extend the existing
      one, exactly as the doc requires ("joins the single global lock order rather than
      creating a parallel one").
- [ ] `app/Http/Controllers/Api/DeliveryController.php` — `/delivery/quote` (06 §8).
- [ ] `app/Http/Controllers/Api/CollectionSlotController.php` — `/collection-slots`,
      `/collection-bookings` (idempotency required per 06 §6).
- [ ] `app/Policies/CollectionBookingPolicy.php`
- [ ] `app/Filament/Resources/DeliveryZoneResource.php`, `DeliveryRateResource.php` —
      admin zone/rate management (already-migrated tables, no Filament resource yet).
- [ ] `app/Filament/Resources/CollectionSlotResource.php` — recurring weekly pattern
      generation UI (05.6 §7.3).
- [ ] `resources/js/pages/Checkout/DeliveryQuote.tsx` — carriage shown pre-payment (05.6 §8),
      free-delivery vs spend-break progress shown as **distinct** figures.
- [ ] `resources/js/pages/Checkout/CollectionSlotPicker.tsx`.
- [ ] `tests/Feature/Domain/ZoneResolverTest.php` — all 11 postcode fixtures from 05.6 §11
      verbatim.
- [ ] `tests/Feature/Concurrency/CollectionSlotConcurrencyTest.php` — D1–D3 from 05.6 §11
      (20 parallel bookings on a capacity-3 slot → exactly 3 succeed).

---

## 11. `05.7` — Purchasing, Containers & Landed Cost

Phase 3. No schema exists yet; all DDL is fully specified in 05.7 itself (no doc-gap issue
here — safe to migrate directly).

- [ ] `database/migrations/2026_09_30_090100_create_suppliers_table.php` — 05.7 §4.
- [ ] `database/migrations/2026_09_30_090200_create_containers_table.php` — 05.7 §6 (create
      before `purchase_orders` since the latter FKs to it).
- [ ] `database/migrations/2026_09_30_090300_create_purchase_orders_table.php` — 05.7 §5.
- [ ] `database/migrations/2026_09_30_090400_create_purchase_order_lines_table.php` — 05.7 §5.
- [ ] `database/migrations/2026_09_30_090500_create_container_costs_table.php` — 05.7 §6.
- [ ] `database/migrations/2026_09_30_090600_create_container_cost_allocations_table.php` —
      05.7 §7.
- [ ] `database/migrations/2026_09_30_090700_create_commodity_duty_rates_table.php` — 05.7
      §9.2.
- [ ] `app/Models/Supplier.php`, `Container.php`, `PurchaseOrder.php`,
      `PurchaseOrderLine.php`, `ContainerCost.php`, `ContainerCostAllocation.php`,
      `CommodityDutyRate.php` + factories for each.
- [ ] `app/Domain/Purchasing/LandedCostApportioner.php` — 05.7 §8.2's algorithm exactly:
      independent per-bucket apportionment (fob_value/weight/volume/units basis), remainder-
      to-largest-basis-value rule, `rounding_residual_minor` recorded not discarded. **Hand-
      verify against the §8.3 worked example** (3-line container, £25,200 total landed,
      grater at £0.5240/unit, −80p residual on line A) as a literal fixture before anything
      else uses this class.
- [ ] `app/Domain/Purchasing/IncotermValidator.php` — 05.7 §8.4's double-count warnings
      (CIF: don't re-apportion freight; DDP: nothing apportioned).
- [ ] `app/Domain/Purchasing/DutyRateResolver.php` — 05.7 §9.2's most-specific-wins
      resolution (`hs_code` + `origin_country`, NULL origin = general rate).
- [ ] `app/Domain/Purchasing/ReorderSuggestionService.php` — 05.7 §10's advisory reorder
      calculation from `stock_levels_reorder_idx` and dispatched-order-line sales history.
- [ ] `app/Domain/Purchasing/SupplierPerformanceReport.php` — 05.7 §11's on-time/
      short-shipment/quality-rate aggregation.
- [ ] Extend `app/Domain/Inventory/GoodsInService.php` (§9) to write `container_id`/
      `purchase_order_id` references and update `purchase_order_lines.received_base_qty` —
      this is an edit to an existing file once §9 exists, not a new one.
- [ ] `app/Http/Controllers/Api/Admin/SupplierController.php`, `PurchaseOrderController.php`,
      `ContainerController.php` — the `/admin/suppliers`, `/admin/purchase-orders`,
      `/admin/containers`, `/admin/containers/{id}/apportion` (202/job) endpoints (06 §8).
- [ ] `app/Jobs/ApportionContainerCostsJob.php` — the queued job behind
      `/admin/containers/{id}/apportion`.
- [ ] `app/Policies/PurchaseOrderPolicy.php`, `ContainerPolicy.php`
- [ ] `app/Filament/Resources/SupplierResource.php`, `PurchaseOrderResource.php`,
      `ContainerResource.php` — including the container-costs apportionment trigger UI.
- [ ] `app/Filament/Widgets/ReorderSuggestionsWidget.php` — purchasing dashboard.
- [ ] `tests/Feature/Domain/LandedCostApportionerTest.php` — L1–L8 fixtures from 05.7 §14
      verbatim, especially L1 reproducing the §8.3 table exactly including the residual.

---

## 12. `05.8` — Dropship & Rep Tools

Phase 3. Depends on §11 (landed cost, for margin-based commission per §10.2) and §5's
order placement path (for `orders.fulfilment_type='dropship'`).

- [ ] `database/migrations/2026_10_01_090100_create_dropship_profiles_table.php` — 05.8 §4.
- [ ] `database/migrations/2026_10_01_090200_create_customer_activities_table.php` — 05.8 §9.
- [ ] `database/migrations/2026_10_01_090300_create_rep_commission_rules_table.php` — 05.8
      §10.1.
- [ ] `database/migrations/2026_10_01_090400_create_rep_commissions_table.php` — 05.8 §10.3.
- [ ] `app/Models/DropshipProfile.php`, `CustomerActivity.php`, `RepCommissionRule.php`,
      `RepCommission.php` + factories.
- [ ] `app/Domain/Dropship/BlindDocumentGenerator.php` — 05.8 §5.5: a **distinct document
      type** with no price field in its data payload at all (not a template that omits
      rendering it — the payload itself must not carry the field, per the doc's explicit
      "a template cannot accidentally render what was never passed to it" reasoning).
- [ ] `app/Domain/Dropship/BatchOrderUploadJob.php` — 05.8 §5.4: credit checked against the
      **batch total**, not per order; allocated in file order; nothing committed until
      confirmed.
- [ ] `app/Domain/Dropship/DropshipOrderValidator.php` — enforces §5.2's one-destination
      rule at the domain layer (not just a UI constraint).
- [ ] `app/Domain/Reps/CustomerBookService.php` — 05.8 §7's rep dashboard aggregation
      (last order, trend, open quotes, credit position, overdue invoices, open RMAs, last
      activity) — one query set per `companies_rep_idx`.
- [ ] `app/Domain/Reps/LapseDetectionService.php` — 05.8 §7.1's median-order-interval
      calculation with the fewer-than-three-orders absolute-threshold fallback.
- [ ] `app/Domain/Reps/OrderOnBehalfSession.php` — 05.8 §8's rules as an enforced session
      object: explicit account selection, all three of `user_id`/`placed_by_user_id`/
      `sales_rep_user_id` always written, **no override of credit/suspension/approval
      controls** — implement the "cannot bypass" rules as guard clauses this class exposes,
      not as scattered checks in each controller that touches order-on-behalf.
- [ ] `app/Domain/Reps/CommissionAccrualService.php` — 05.8 §10.4/§10.4a/§10.4b: accrues on
      invoicing (goods lines only, excluding carriage/fees/cancellation charges), keys on
      `sales_rep_user_id` stamped at placement (not `placed_by_user_id`), moves to
      `payable` on payment, writes negative clawback rows on returns (never edits).
      **Depends on §11's landed cost being live** — commission on FOB-based margin "would
      pay out on a margin that does not exist" per 05.8 §10.2, so do not build this against
      provisional/FOB-only cost data.
- [ ] `app/Http/Controllers/Api/Rep/CustomerBookController.php`, `SessionController.php`,
      `ActivityController.php`, `CommissionController.php` — the `/rep/*` endpoints (06 §8).
- [ ] `app/Http/Controllers/Api/Dropship/BatchOrderController.php`,
      `DropshipProfileController.php` — the `/dropship/*` and `/admin/dropship-profiles`
      endpoints.
- [ ] `app/Policies/DropshipProfilePolicy.php`, `RepSessionPolicy.php` — the
      cannot-bypass-controls rules enforced here too, as the authorisation layer's own
      check, not solely trusted to `OrderOnBehalfSession`'s guard clauses (defence in depth,
      per 07 §6.2's "privilege escalation explicitly blocked").
- [ ] `app/Filament/Resources/DropshipProfileResource.php` — approval workflow
      (`pending_approval → active`).
- [ ] `app/Filament/Resources/RepCommissionRuleResource.php`.
- [ ] `resources/js/pages/Rep/CustomerBook.tsx`, `OrderOnBehalf.tsx` (with the persistent
      "whose account is active" banner from §8), `CommissionStatement.tsx`.
- [ ] `resources/js/pages/Dropship/BatchUpload.tsx` — reconciliation screen mirroring
      05.1's paste/CSV pattern.
- [ ] `tests/Feature/Domain/CommissionAccrualServiceTest.php` — the clawback-excludes-the-
      retained-fee assertion from 05.8 §13 (goods commission clawed back in full, retained
      fee generates none), the self-service-accrues-to-assigned-rep case (§10.4b).
- [ ] `tests/Feature/Domain/LapseDetectionServiceTest.php` — median-vs-absolute-threshold
      fixtures.

---

## 13. `06` — API Contract cross-cutting infrastructure

These apply across every module above rather than to one; build early since most controller
tasks in §5–§12 depend on them.

- [ ] `routes/api.php` — **does not exist at all yet** (confirmed: no file present, and
      `bootstrap/app.php`'s `withRouting()` call doesn't register an `api:` path). Every
      `/api/v1/*` route in every section above needs this file to exist first, with
      `Route::prefix('v1')->group(...)` per 06 §2.
- [ ] `app/Http/Middleware/EnsureIdempotencyKey.php` — 06 §6's required-on-these-operations
      idempotency handling, backed by a `idempotency_responses` cache table (24h TTL,
      stores request hash + response) — infrastructure, not a doc-02 domain table.
- [ ] `app/Exceptions/ApiExceptionHandler.php` (or a `render()` override in
      `bootstrap/app.php`'s `withExceptions()`, currently an empty closure) — the single
      error envelope from 06 §4, mapping domain exceptions
      (`InsufficientStockException`, `InsufficientCreditException`, etc. — already thrown by
      `AllocationService`) to the documented `code`/`details`/`request_id` shape and the
      §4.1 status-code table (404-not-403 for out-of-tenancy, 422 for business-rule
      failures, 409 for exclusion-constraint violations).
- [ ] `app/Http/Support/KeysetPaginator.php` — 06 §5.1's opaque-cursor row-constructor
      pagination helper (`(name, id) > (:name, :id)`), used by every paginated endpoint;
      centralise here rather than reimplementing per controller.
- [ ] `app/Http/Support/MoneyResourceCast.php` / a shared `JsonResource` trait — enforces
      06 §3's suffix discipline (`_e4`/`_minor`/`_base_qty` never floats, `display` block
      alongside where needed) at the serialisation boundary, so no individual Resource class
      can accidentally cast a money column to float.
- [ ] `app/Http/Controllers/Api/CheckoutController.php` — `/checkout/preview` (side-effect-
      free, 06 §9.2) and `/checkout` (06 §9.3, `expected_total_gross_minor` mismatch → 409
      `price_changed`, idempotency required). This is the endpoint that finally wires
      together `OrderPricingPipeline` (§2), `CreditCheckService` (§6),
      `CollectionBookingService`/`AllocationService` (§10/existing) into the single checkout
      transaction the whole spec has been building toward — the highest-value integration
      point in the entire roadmap.
- [ ] `app/Http/Requests/Api/CheckoutRequest.php`, `CheckoutPreviewRequest.php`
- [ ] `app/Http/Controllers/Api/OrderController.php` — `/orders`, `/{id}/approve`, `/reject`,
      `/cancel`, `/reorder`, `/{id}/documents` (06 §8).
- [ ] `app/Http/Controllers/Api/StockAvailabilityController.php` — `/stock/availability`
      (06 §9.4 — batch/serial detail explicitly **not** exposed to customer-facing callers).
- [ ] `app/Http/Middleware/EnforceRateLimits.php` (or Laravel's built-in `throttle:` with
      named limiters registered in a new `app/Providers/RateLimitServiceProvider.php`) — the
      per-endpoint-class limits from 06 §12.
- [ ] `app/Console/Commands/GenerateOpenApiSpec.php` + CI step — 06 §14's "generated from
      code, not maintained separately" requirement; nothing currently generates one.
- [ ] `tests/Feature/Api/IdempotencyTest.php` — the seven required-idempotency operations
      from 06 §6's table, each called twice with one key.
- [ ] `tests/Feature/Api/TenancyIsolationTest.php` — 06 §16.7's "out-of-tenancy ids return
      404" verified for every company-scoped resource.
- [ ] `tests/Feature/Api/PolicyCoverageTest.php` — 06 §16.10 / 07 §6.2's route-to-policy
      coverage test with **zero exemptions** — this is both an API-contract acceptance
      criterion and a security NFR gate (§16 below references it too; build once, gate both).

---

## 14. Realtime Gateway hardening (blocking — see §0.1)

- [ ] `app/Http/Controllers/Api/RealtimeTokenController.php` — issues the short-lived,
      room-scoped signed token described in 11 §6's candidate approach (reuses existing
      Policy checks — e.g. a company-room token requires the caller actually belong to that
      company; a warehouse-room token requires a warehouse-staff role).
- [ ] `app/Domain/Realtime/RoomTokenSigner.php` — HMAC-signs `{room, exp, sub}`, verified
      independently by the gateway (never re-derives authorisation itself, per 11 §6's own
      constraint that the gateway "has no access to Laravel's session store").
- [ ] `services/realtime-gateway/src/auth.ts` — new file: verifies the signed token on the
      `join` event before calling `socket.join(room)`; rejects (and logs) a `join` for a
      room the token's claim doesn't match. This is the fix for the exact gap identified in
      §0.1 — currently `server.ts` line 18-20 joins any room string unconditionally.
- [ ] `services/realtime-gateway/src/server.ts` — edit: wire `auth.ts`'s verification into
      the existing `io.on('connection', ...)` handler, replacing the current unguarded
      `socket.on('join', (room) => socket.join(room))`.
- [ ] `services/realtime-gateway/test/auth.test.ts` — new test file (no test directory
      currently exists under `services/realtime-gateway/`): a token for `company:A` must not
      be able to join `company:B`; an expired token is rejected; an unsigned/malformed token
      is rejected.
- [ ] `app/Domain/Realtime/EventPublisher.php` — the Laravel-side publish contract from 11
      §3 (`DB::afterCommit()`, JSON envelope with `room`/`event`/`payload`, money/quantity
      suffix discipline matching 06 §3). Currently no Laravel code publishes to
      `REDIS_REALTIME_CHANNEL` at all — every `Stock*`/domain event in §4 above needs a
      listener that calls this to actually reach the browser.
- [ ] `app/Listeners/PublishStockLevelChanged.php`, `PublishOrderStatusChanged.php`, etc. —
      one listener per event in §4's list that has a real-time UI consumer, dispatched
      after commit.
- [ ] Resolve §11 §7's open scaling question (Redis adapter vs. sticky sessions) **only when
      load-testing indicates it matters** — explicitly not blocking per the doc; don't
      build it speculatively.

---

## 15. Filament Admin — resources not covered above

Sections §5–§12 each list their own module-specific Filament resources inline. This section
covers the base catalogue/pricing/inventory resources for the Phase-1 entities that already
have migrations and models but zero admin UI — `app/Filament/Resources/` is currently
completely empty except for the bare `AdminPanelProvider` with no resources registered.

- [ ] `app/Filament/Resources/ProductResource.php` — CRUD per 02 §5.4, cost fields N/A here
      (products don't carry cost; that's `sku_costs`) but `rrp_minor` and completeness-score
      dashboard integration per 01's data-quality risk item.
- [ ] `app/Filament/Resources/SkuResource.php` — CRUD per 02 §5.5, **cost fields
      (`unit_cost_e4` via the current `sku_costs` relation) hidden from any role without
      admin/rep/purchasing permission**, per CLAUDE.md invariant 9 applied to the backoffice
      too — this is not only a customer-facing-API rule.
- [ ] `app/Filament/Resources/PackResource.php` — nested under `SkuResource` as a relation
      manager per 02 §5.6, enforcing "exactly one `is_default_sell`" at the form level
      (the DB partial-unique index is the backstop, not the primary UX).
- [ ] `app/Filament/Resources/CategoryResource.php` — tree UI over `category_closure`
      (blocked on §1's closure-table migration + maintainer existing first).
- [ ] `app/Filament/Resources/BrandResource.php`.
- [ ] `app/Filament/Resources/AttributeResource.php` — with nested `AttributeValueResource`
      relation manager (blocked on §1's attributes migrations).
- [ ] `app/Filament/Resources/PriceListResource.php`, `PriceListItemResource.php` — the
      break-table editor (03 §2's "adding a tier for one SKU is an INSERT" — the UI should
      make this literally one row-add action), surfacing the `EXCLUDE`-constraint 409
      conflict from 06 §4.1 as a readable form error rather than a raw Postgres exception.
- [ ] `app/Filament/Resources/PriceTierResource.php`, `TaxClassResource.php`,
      `TaxRateResource.php`, `OrderSpendBreakResource.php`.
- [ ] `app/Filament/Resources/SkuCostResource.php` — read-heavy, history view per
      `sku_costs_current_idx`.
- [ ] `app/Filament/Resources/OrderResource.php` — read/action (approve/reject/cancel),
      **cost/margin columns visible only to admin/rep/purchasing roles**, not customers (no
      customer ever reaches Filament, but staff-role granularity still applies per 07 §6.2).
- [ ] `app/Filament/Resources/StockLevelResource.php` — read-only projection view, with a
      "rebuild from ledger" action gated to a confirmation dialog (04 §9: drift is reported,
      never auto-corrected — the UI must not offer a one-click silent fix).
- [ ] `app/Filament/Resources/StockMovementResource.php` — **read-only by design** (06 §8's
      `/admin/stock-movements` is `R` only) — do not add create/edit/delete actions to this
      resource; the ledger's append-only invariant should be impossible to violate from the
      admin UI, not just discouraged.
- [ ] `app/Filament/Resources/BatchResource.php` — recall-reference field prominent, status
      transitions logged.
- [ ] `app/Filament/Resources/LocationResource.php`, `BinResource.php`.
- [ ] `app/Filament/Resources/NumberSequenceResource.php` — read-only view over the
      already-built `NumberSequenceService`, for auditing gaplessness.
- [ ] `app/Filament/Resources/SystemConfigurationResource.php` — the `system_configurations`
      most-specific-wins editor (02 §2.7), with `updated_by_user_id` audit trail surfaced
      inline. This is the UI for every "configurable" value referenced across every module
      doc above (restocking rate/minimum, hold windows, minimum order value, etc.) — build
      this early since §6/§8/§10's services all read from it.
- [ ] `app/Filament/Widgets/DataQualityWidget.php` — 01 §10's `completeness_score` dashboard,
      explicitly "flagged, not blocking".
- [ ] `app/Providers/Filament/AdminPanelProvider.php` — edit: register the resources above
      via `discoverResources` (already points at `app/Filament/Resources`, so resources
      auto-register once created — verify this after the first resource lands rather than
      assuming).
- [ ] `app/Policies/*` for each resource above, gating Filament access per 07 §6.2's
      no-UI-only-authorisation rule.
- [ ] `app/Filament/Resources/PromotionResource.php` + a `price_list_items` authoring action —
      02 §14.8 states category/brand promotions are authored into `price_list_items` "by an
      admin tool," but that tool doesn't exist. Build alongside the other Filament pricing
      resources above (`PriceListResource` et al.) rather than separately, since it's the same
      admin surface: authoring a promotion means creating a `price_lists` row (`scope =
      'promotion'`) plus one `price_list_items` row per eligible SKU, driven by the
      `category_id`/`brand_id` audience metadata on `promotion_rules`. **Depends on 02 §14's
      still-open question #2** (whether `category_id`/`brand_id` stay authoring-time metadata,
      which this tool assumes, or need to become a live resolution-time check) — resolve that
      before building this, not while building it. [G10]

---

## 16. `07` — Non-functional requirements

- [ ] `.github/workflows/ci.yml` — **does not exist.** Runs `composer lint`,
      `composer analyse`, `composer test`, `npm run build`, and the axe-core accessibility
      check (below) on every PR; fails the build on any CI gate from 07 §14's table.
- [ ] `tests/Feature/Performance/BaselineRegressionTest.php` — 07 §2.5's "CI fails on 20%
      regression against recorded baseline" mechanism; extend the existing
      `HotPathExplainTest.php` pattern with stored baseline timings, not just plan-shape
      assertions.
- [ ] `app/Console/Commands/RunAccessibilityAudit.php` or an `npm` script wiring axe-core
      into CI (07 §8) — no accessibility tooling exists yet at all.
- [ ] `config/logging.php` — edit: add the redaction channel/processor from 07 §9.1
      (passwords, card data, session tokens, full addresses, API secrets never logged),
      plus `tests/Unit/LogRedactionTest.php` asserting it.
- [ ] `docs/09-test-strategy.md` — currently doesn't exist (doc 01 §2 lists it "Pending").
      **Written last, deliberately** — it documents the coverage model, concurrency/property
      testing approach and CI gates as the suite is actually built across every section of
      this roadmap, rather than prescribing a test strategy up front that drifts from what's
      really there. Do not write this until the rest of the roadmap is substantially done.
- [ ] `app/Domain/Compliance/AnonymisationService.php` — 07 §7.3's erasure-vs-retention
      workflow: tombstones `users.email`/name/phone, retains `orders`/`order_lines`/
      `invoices`/`order_addresses`, redacts `customer_activities` free text. Depends on
      `invoices` existing (§0.2 doc gap).
- [ ] `app/Http/Controllers/Api/DataExportController.php` — 07 §7.4's self-service
      access/portability export (JSON + PDF, 30-day SLA).
- [ ] `app/Console/Commands/EnforceRetentionPolicy.php` — 07 §7.2's per-data-type retention
      table as scheduled jobs (7-year financial records, 3-year quotes, 90-day session
      logs, etc.).
- [ ] Backup/PITR configuration — 07 §4: `pgBackRest` config, WAL archiving to off-server
      object storage, quarterly restore-test runbook. Infrastructure-as-code, not
      application code; track as a deployment task rather than a repo file, but the
      **quarterly restore test result log** should live somewhere reviewable — e.g.
      `docs/ops/restore-test-log.md` (new, outside the schema-governed doc set, so no
      sign-off gate applies).
- [ ] `app/Http/Middleware/EnforceTwoFactorForStaff.php` — 07 §6.1's mandatory-2FA-for-
      staff-roles rule; Laravel Fortify/Sanctum 2FA is not yet configured anywhere in
      `config/auth.php`.
- [ ] Stripe/payment-gateway integration — `app/Domain/Payments/PaymentGatewayClient.php` —
      07 §6.4's SAQ-A-scope tokenisation (Stripe Elements/Checkout client-side only, gateway
      reference + last-four stored server-side, card data never touching our servers).
      Nothing payment-related exists in the repo yet.
- [ ] `app/Domain/Documents/PdfGenerationClient.php` — the Node/Puppeteer PDF worker
      referenced throughout (01 §5.1, 05.3 §11, 06 §8) doesn't exist as a service at all —
      needs its own `services/pdf-worker/` scaffold (Fastify + Puppeteer, similar shape to
      `services/realtime-gateway/`) plus this Laravel-side client, queued per P25's 3s
      budget.
- [ ] `app/Domain/Accounting/XeroSyncService.php`, `SageExportService.php` — 07 §12's Xero
      native sync and Sage CSV export. Blocked on `invoices`/`payments`/`credit_notes` (§0.2/
      §0.3 doc gaps) and on `xero_sync_records` having a migration (full DDL exists only as
      a one-line key summary in 02 §8.4 — **another §0.3-class doc gap**, add to that list).
- [ ] Resolve 07 §16 Q9 ("does Xero integration warrant its own module spec 05.9?") — the
      task brief's own module list stops at 05.8, but 02 §4.3 and several DDL comments
      already reference a `05.9` doc (`xero_contact_id`, `xero_tax_type` inline comments cite
      "05.9"). **This module doc does not exist** — write `docs/05.9-xero-integration.md`
      before building `XeroSyncService` in depth, per the same "spec precedes implementation"
      principle as everything else (CLAUDE.md, doc 01 §12). **Renamed 2026-09-20** from this
      task's earlier working title `05.9-accounting-sync.md` to match the 2026-09-20
      completeness-audit's naming and doc 01 §2's index (Correction 2026-09-20) — same task,
      same gap, one name. Must also close 02 §14.11's three open questions (syncable entity
      list, sync direction, retry/backoff policy) before `xero_sync_records` can be migrated.

---

## 17. Frontend shell / cross-cutting React work

Not owned by any single module but required before most `resources/js/pages/*` tasks above
can run in a real browser.

- [ ] `resources/js/lib/api/client.ts` — typed API client generated from (or hand-aligned to,
      until §13's OpenAPI generator exists) the 06 resource catalogue; TanStack Query hooks
      per resource.
- [ ] `resources/js/lib/money.ts` — the client-side mirror of `Money`/`roundHalfUpDiv`
      (§2), shared by every page that recomputes totals locally (05.1's order pad being the
      hot path, but quotes/RMA screens need it too).
- [ ] `resources/js/components/ui/StockBadge.tsx` — 05.1 §4.3 / 07 §8's "never colour alone"
      rule as one shared component, so every stock display across order pad, warehouse and
      admin screens gets the numeral+label treatment consistently rather than each page
      reimplementing it (and risking the WCAG violation the NFR doc calls "the most likely
      violation in a system with green/amber/red stock").
- [ ] `resources/js/stores/cartStore.ts` (Zustand) — client-side cart mirror synced against
      server `carts`/`cart_lines` (blocked on §0.2's doc gap).
- [ ] `resources/js/app.tsx` — edit: replace the placeholder single-page wiring with real
      route-based code-splitting once more than one page exists (currently only
      `Dashboard.tsx`).
- [ ] `resources/js/pages/Dashboard.tsx` — edit: replace the placeholder with the real
      authenticated landing page, or delete it in favour of `OrderPad/Index.tsx` (§5) being
      the landing route — decide which once §5 exists rather than maintaining two.

---

## 18. Audit Log (§0.6, blocking — added 2026-09-20 completeness audit)

`07-nfr.md` §6.5 mandates an immutable, append-only, 7-year-retained audit log; `02
§1`(Correction 2026-09-20) no longer disclaims it. This is a hole between two documents,
not a decision either one made — see §0.6.

- [ ] Propose `docs/02-domain-model-erd.md` §15 amendment — `audit_log`: partitioned by
      `occurred_at` (mirrors `stock_movements`, 02 §7.4), append-only, composite
      `(id, occurred_at)` PK, BRIN on `occurred_at`, `actor_user_id`, `subject_type`/
      `subject_id`, `action`, `before`/`after` `jsonb`. **Draft and sign off in its own commit
      before any migration** — do not invent this schema while building the logger. [G1]
- [ ] Once signed off: `database/migrations/*_create_audit_log_table.php`.
- [ ] `app/Domain/Audit/AuditLogger.php` — the single write path every privileged action
      calls, rather than each domain service writing its own ad hoc log row. First real
      callers: `roles.granted_by_user_id` (02 §14.1) once role-granting exists, and
      `rmas_waiver_chk`'s fee-waiver path (05.4 §5) once the RMA domain service exists —
      neither is built yet, so wiring this is this task's responsibility, not a retrofit.
- [ ] `tests/Feature/Domain/AuditLoggerTest.php` — including an append-only assertion in the
      same style as `stock_movements`' (query-log assertion, no `UPDATE`/`DELETE` possible).

---

## 19. Inventory Transfers (added 2026-09-20 completeness audit)

`stock_movements` already accepts `transfer_in`/`transfer_out` (04 §3: "a transfer is two
movements in one transaction, never one movement with two locations"), but nothing groups
the pair. Latent at single-location launch; blocks the moment a second `locations` row is
real.

- [ ] Propose `docs/02-domain-model-erd.md` §16 amendment — `transfers`, `transfer_lines`.
      Draft and sign off before migrating, same discipline as §18. [G2]
- [ ] Once signed off: migration.
- [ ] `app/Domain/Inventory/TransferService.php` — locks source and destination
      `stock_levels` rows in the same ascending `(sku_id, location_id, batch_id NULLS FIRST)`
      order as `AllocationService`/`DeallocationService` (CLAUDE.md invariant 6) — a transfer
      touching the same two locations as a concurrent allocation must not be able to deadlock
      against it, which means it has to share the one global lock order, not invent its own.
- [ ] `tests/Feature/Domain/TransferServiceTest.php` — including a lock-order test in the
      same style as `AllocationServiceTest.php`'s query-log assertion.
- [ ] `app/Filament/Resources/TransferResource.php` — warehouse-facing, alongside §15.

---

## 20. Order Amendment & Cancellation — `docs/05.10-order-amendment.md` (added 2026-09-20)

`orders.cancellation_fee_minor` (02 §8.2) exists with no rules behind it. `05.3 §13`
references an order "amended upward after the hold" needing a credit re-check; `05.4 §9`
routes an undispatched return to "cancellation, not a return" with no document defining
what that means operationally.

- [ ] Write `docs/05.10-order-amendment.md` — covers amendment-after-hold (re-run the credit
      check `AllocationService` already does, against the amended total), cancellation fee
      computation, and the exact RMA→cancellation boundary 05.4 §9 gestures at. **Spec
      precedes code — this is a task, not a design to invent here.** [G3]
- [ ] Once signed off: `app/Domain/Orders/OrderAmendmentService.php` — re-resolves changed
      lines through `PriceResolver`/`OrderLinePricer`, re-checks credit via the same
      lock-first-cheapest-check pattern `AllocationService::lockCompanyCredit()` already uses.
- [ ] `app/Domain/Orders/OrderCancellationService.php` — calls the already-built
      `DeallocationService` for stock release; computes `cancellation_fee_minor` per the new
      spec.
- [ ] Controllers/Form Requests for amend/cancel endpoints — needs a `06-api-contract.md` row
      once the spec exists; none is proposed here.
- [ ] `tests/Feature/Domain/OrderAmendmentServiceTest.php`,
      `tests/Feature/Domain/OrderCancellationServiceTest.php` — the cancellation test reuses
      `DeallocationServiceTest.php`'s fixtures where possible rather than duplicating them.

---

## 21. CMS & SEO — `docs/05.11-cms-seo.md` (added 2026-09-20)

01 §5.1 lists "CMS and SEO" in Phase 1 scope; 02 §1 disclaimed CMS page storage
(Correction 2026-09-20). No pages/banners/redirects tables, no structured-data or sitemap
work, anywhere in this roadmap before today.

- [ ] Write `docs/05.11-cms-seo.md` — pages, banners, redirects, structured data, sitemap
      generation. Scope and table shape are this doc's job, not this roadmap entry's. [G4]
- [ ] Once signed off: migrations for whatever entities the spec settles on.
- [ ] Filament resources + public React pages, once schema exists.

---

## 22. Notification Infrastructure — `docs/05.12-notifications.md` (added 2026-09-20)

Every module spec written so far assumes notifications exist (order confirmation,
back-in-stock, approval requests, RMA status, quote-approval, etc.) without any of them
specifying templates, channels, preferences, or delivery tracking.

- [ ] Write `docs/05.12-notifications.md` — templates, channels, per-user/company
      preferences, delivery tracking. [G5]
- [ ] Once signed off: `notification_preferences`, `notification_log` migrations (naming per
      the spec, not fixed here).
- [ ] `app/Domain/Notifications/NotificationDispatcher.php` — the single dispatch path every
      other domain service calls rather than each sending its own mail/SMS ad hoc.
- [ ] Wire `back_in_stock_subscriptions` (02 §14.10, DRAFT) as the first real consumer — it
      already exists specifically to be notified and currently has nothing to call.

---

## 23. Auth & Onboarding — `docs/05.13-auth-onboarding.md` (added 2026-09-20)

`07-nfr.md` §6.1 specifies authentication **policy** (Argon2id, 2FA, session timeouts) but
not the B2B-specific **flows**: guest-cart merge at login, whether an unapproved
`b2b_applications` applicant may log in at all, invited-user onboarding.

- [ ] Write `docs/05.13-auth-onboarding.md` — the three flows above, at minimum. [G6]
- [ ] Once signed off: controllers/Form Requests/Policies implementing it.
- [ ] Reconciles with `carts.company_id`/`carts.user_id` both being nullable (02 §14.3,
      DRAFT) — guest-cart merge is exactly the transition that resolves those columns from
      NULL, so this flow and that table's design are the same piece of work.

---

## 24. Reporting Suite — `docs/05.14-reporting.md` (added 2026-09-20)

01 §5.1 Phase 4 scope; 02 §1 disclaimed reporting aggregates and materialised views
(Correction 2026-09-20). No spec anywhere.

- [ ] Write `docs/05.14-reporting.md` — which aggregates, which materialised views, refresh
      strategy. [G7]
- [ ] Once signed off: whatever the spec specifies; Filament dashboard widgets.
- [ ] Written **after** the modules it reports on (Phase 6, per the audit) — a reporting spec
      written before the transactional model it summarises is stable would need rewriting.

---

## 25. Deferred by decision — recorded so it is not mistaken for an oversight (added 2026-09-20)

`06-api-contract.md` §11 fixes the webhook/public-API conventions (signing, retry, scopes)
but no task in this roadmap builds them — 01 §5.1 places public integration endpoints and
webhooks in Phase 4, after the eight core modules. **This is a scope decision, not a gap**:
unlike G1–G10 above, nothing else in the signed-off spec set assumes this exists yet. [G11]

- [ ] Public API and webhook delivery — `06 §11`'s conventions, once Phase 4 is reached.
      Tracked here specifically so a future reviewer finds a decision, not a hole.

---

## 26. Seed Data & Reference Import — `docs/08-migration-seed.md` (added 2026-09-20, resequenced per §0.7)

Both pieces were previously unscheduled/too-late (see §0.7): Filament and the performance
suite are effectively untestable against empty tables, and the 920-SKU reference catalogue
(01 §3.2) is this project's one realistic load-test fixture.

- [ ] `database/seeders/DemoDataSeeder.php` — a small, realistic seed (companies, price
      tiers, a handful of SKUs across the pack/batch/serial variations) for exercising
      Filament resources and manual testing before real data exists. **Sequence early** —
      before §15's Filament resources are meaningfully testable. [G12]
- [ ] Write `docs/08-migration-seed.md` — import from `londontopchoice.co.uk` (01 §3.2, 02
      §12's own worked mapping table), validation rules, rejection reporting. [G12]
- [ ] Once signed off: `app/Console/Commands/ImportReferenceCatalogue.php` — the 920-SKU
      import via `COPY` into staging tables per 02 §12's own specified approach (not
      row-by-row Eloquent inserts). **Sequence early** — this is the load-test fixture the
      performance suite and every "at scale" acceptance criterion assumes exists; building
      every module against synthetic factory data first and importing real data last means
      discovering data-shape problems after everything else is already built against a
      fiction.

---

## Summary

This roadmap identifies **269 concrete file-level tasks across the original 17 sections**
(migrations, models, factories, domain services, jobs, controllers, Form Requests, Policies,
API Resources, Filament Resources, React pages/components, and their accompanying tests),
plus **7 blocking issues** (§0, two added 2026-09-20) and **2 clusters of genuine
schema/doc gaps — roughly 20 tables total** — that need a signed-off doc amendment to
`docs/02-domain-model-erd.md` or the relevant `05.x` module spec before their migrations
can be written at all. Since 2026-09-17, `app/Domain/Pricing` (§2) has been substantially
built (`Money`, `PriceResolver`, `BulkPriceResolver`, `OrderLinePricer`,
`SpendBreakResolver`, `SpendBreakApportioner`, `OrderPricingPipeline`) and doc 02 §14 has
drafted DDL for the ~20-table gap (DRAFT — awaiting sign-off) — this summary paragraph is
not re-audited against current completion state as part of this merge; see the task-level
notes throughout for what's since landed.

**Completeness audit, 2026-09-20** — twelve additional gaps (`G1`–`G12`), found by checking
what each document *mandates* against what every other document *owns*, folded in above:

- **Two new schema amendments**, both blocking-tier: `docs/02-domain-model-erd.md` §15
  (`audit_log`, §18) and §16 (`transfers`/`transfer_lines`, §19) — proposals only, not
  designed here, signed off in their own commits before migrating, same as §14.
- **Six new module/system specs registered as tasks** (not written — that is explicitly out
  of scope for this merge): `docs/05.9-xero-integration.md` (renamed from this roadmap's
  own earlier `05.9-accounting-sync.md` working title, §16), `docs/05.10-order-amendment.md`
  (§20), `docs/05.11-cms-seo.md` (§21), `docs/05.12-notifications.md` (§22),
  `docs/05.13-auth-onboarding.md` (§23), `docs/05.14-reporting.md` (§24). Plus
  `docs/08-migration-seed.md` (§26) and `docs/09-test-strategy.md` (§16, written last).
- **Four smaller gaps folded into existing sections** rather than given new ones: price-list/
  catalogue PDF generation and coupon redemption (§2), a promotion-authoring Filament tool
  (§15).
- **One deferral recorded, not built**: webhooks/public API (§25) — a scope decision already
  made in 01 §5.1 Phase 4, not a hole, tracked so it reads as intentional.
- **One resequencing**: demo seed data and the 920-SKU reference import, previously
  unscheduled, now in §0.7/§26 — both block realistic testing of Filament and the
  performance suite if left until late.

New tasks are tagged `[G1]`–`[G12]` inline for traceability to this audit. ROADMAP.md had no
formal task-ID scheme before this merge (plain `[ ]` checkboxes grouped under numbered §
sections) — every existing checkbox is unchanged; nothing was renumbered or removed.

The load-bearing sequencing point is unchanged: **`app/Domain/Pricing` (§2) was the single
highest-leverage section**, and per the note above is now substantially built. The next
highest-leverage items are the two blocking-tier doc gaps this audit surfaced (§18 audit
log, §0.6) and closing the ~20-table §14 draft's sign-off, since a wide swath of §5–§12's
module work (payments, invoices, coupons, shipments) is drafted but not migrated pending it.
