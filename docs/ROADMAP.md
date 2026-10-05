# Delivery Roadmap — File-by-File

**B2B source audit: 2026-10-05, branch `feat/b2b`.** Historical delivery requirements
are retained below; the dated audit note on each trade/shared checkbox is authoritative
where its older description names a proposed filename, obsolete blocker or past repo state.
`[x]` means the stated capability exists in source (an equivalent implementation counts);
`[ ]` means absent or **Partly built**, with the remaining scope recorded. A migration file
proves schema implementation, not that a particular database has applied it. Test evidence
means assertions inspected, **not a fresh passing test run**. Per CLAUDE.md, tests and
migrations were not run. Production credentials, deployments, legal review and operational
checks cannot be certified from this checkout. B2C-only history in §23A is outside this
audit; its shared CMS/cancellation/collection dependencies and follow-ups are included.

Conventions used below:

- `[x]` implemented scope with source/test evidence; `[ ]` absent or partly built scope.
  Nested items remain sub-steps of the same file or tightly-coupled siblings.
- Ordered **within each section** so a domain service precedes the controller that calls it,
  which precedes the Filament resource / React page that renders it — per the task brief.
- **⚠ BLOCKING** marks anything that stops other work or must not ship as-is.
- **⛔ DOC GAP** marks a table Appendix A (02) lists as having full DDL, or a module doc implies
  is specified, where no `CREATE TABLE` actually exists anywhere in the doc set. Per CLAUDE.md
  ("do not invent a table/column/index not in `02-domain-model-erd.md`"), these need a doc
  amendment — proposed and signed off in its own commit — **before** a migration is written.

---

## 0. Blocking issues — resolve before the dependent work below

1. **[ ] BLOCKING — realtime tenant-room authorization, not started.**
   `services/realtime-gateway/src/server.ts` still joins arbitrary rooms; no token verifier,
   Laravel publisher or tenant client wiring exists. `docker-compose.yml` and `composer.json`
   do not start the gateway. Keep it out of tenant deployments until §14 is completed.
2. **[x] Schema authority for roles, attachments, carts, payments, invoices and allocations
   is resolved.** 02 §14 and `database/migrations/2026_09_25_*` through `2026_09_29_*`,
   with corresponding `app/Models/` and factories, prove the foundation exists.
3. **[x] The original Phase-2 DDL doc gap is closed by 02 §14.** This is a documentation
   completion only: shipment/stocktake and RMA/credit-note migrations now exist; saved lists,
   promotions/coupons, subscriptions and Xero sync migrations remain ordinary module backlog.
   Do not reinstate the old gap as a blocker or mark those modules built on DDL alone.
4. **[ ] Open client decision — live minimum and carriage-paid thresholds.**
   `app/Domain/Delivery/ThresholdEvaluator.php` handles the independent keys and post-break
   subtotal (`DeliveryRatingTest.php`). It uses a £500 carriage fallback and no minimum when
   unset; neither is evidence of agreed go-live values. Obtain/configure the client values.
5. **[x] Core CI exists.** `.github/workflows/ci.yml` runs the `quality` job on every
   pull request (and pushes to `main`): `composer lint`, JavaScript tests/build, realtime
   build and PHP tests against isolated PostgreSQL 16. **Partly built:** §16's remaining
   concurrency, performance-baseline and accessibility gates still need delivery.
6. **[ ] Partly built — audit foundation resolved, event coverage incomplete.**
   `2026_10_12_090100_create_audit_log_table.php`, `AuditLogger.php`, `AuditLoggerTest.php`
   and auth/application/member callers exist. Privileged warehouse callers and partition/
   retention operations remain (§18 and summary). No audit schema amendment is still needed.
7. **[ ] Partly built — demo/reference data.** `database/seeders/DemoDataSeeder.php` exists
   with companies/tiers/packs and stock variety, but lacks batch/serial/ledger fixtures.
   `docs/08-migration-seed.md` and the reference import do not exist; mapping/data access
   remain dependencies for realistic catalogue/load and redirect validation (§26).
8. **[ ] Pre-go-live framework upgrade, not started.** `composer.json` / `composer.lock`
   still select Laravel 11. `AuthFields.php` and `EmailVerificationLink.php` provide the
   recorded compensating controls. The previously recorded advisory resolution requires a
   CLAUDE.md stack amendment before upgrading (§16); this audit did not query a live advisory
   feed, run an upgrade or certify production security.

---

## 1. `02` — Domain Model: remaining Phase-1 schema

Phase-1 schema exists, including category closure inside the original categories migration.
The dated entries below identify actual filenames; proposed historical dates are not new migration tasks.

- [x] `database/migrations/2026_09_25_090100_create_addresses_table.php` — 02 §4.5. Depends on
      `companies`, `delivery_zones` (both already migrated).
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_30_090100_create_addresses_table.php` (actual filename).
- [x] `app/Models/Address.php` + `database/factories/AddressFactory.php`
  **Audit 2026-10-05:** Built — `app/Models/Address.php`, `database/factories/AddressFactory.php`.
- [x] `database/migrations/2026_09_25_090200_create_b2b_applications_table.php` — 02 §4.6,
      including the `info_requested` status value from 05.2 §4.5's amendment to the enum
      (add it directly in this migration's `CHECK`, not as a later `ALTER`, since the table
      doesn't exist yet).
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_30_090200_create_b2b_applications_table.php` includes `info_requested`; no follow-up status migration is required.
- [x] `app/Models/B2bApplication.php` + `database/factories/B2bApplicationFactory.php`
  **Audit 2026-10-05:** Built — `app/Models/B2bApplication.php`, `database/factories/B2bApplicationFactory.php`.
- [x] `database/migrations/2026_09_25_090300_create_category_closure_table.php` — 02 §5.3 closure storage.
      A separate new migration is unnecessary; see the actual migration below.
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_16_100300_create_categories_table.php` creates `category_closure` and its descendant index; the separate migration and claimed live bug are obsolete.
- [x] `app/Domain/Catalogue/CategoryClosureMaintainer.php` — maintain ancestor/descendant/depth
      rows when a category is created or reparented (02 §5.3).
  **Audit 2026-10-05:** Built — `app/Domain/Catalogue/CategoryClosureMaintainer.php`, called by `CategoryReparenter.php` and Category Filament save hooks; `tests/Feature/CatalogueAuthorizationTest.php`.
- [x] `database/migrations/2026_09_25_090400_create_attributes_table.php` — 02 §5.7
      (`attributes`, `attribute_values`, `product_variant_axes`, `sku_attribute_values` —
      four tables, one migration file per the existing convention of one table per file, so
      split into four: `..._create_attributes_table.php`,
      `..._create_attribute_values_table.php`, `..._create_product_variant_axes_table.php`,
      `..._create_sku_attribute_values_table.php`).
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_30_090300_create_attributes_table.php` through `090600_create_sku_attribute_values_table.php`.
- [x] `app/Models/Attribute.php`, `AttributeValue.php`, `ProductVariantAxis.php`,
      `SkuAttributeValue.php` + factories for each.
  **Audit 2026-10-05:** Built — the four named models and corresponding `database/factories/{Attribute,AttributeValue,ProductVariantAxis,SkuAttributeValue}Factory.php` exist.
- [x] `database/migrations/2026_09_25_090800_create_media_table.php` — 02 §5.8.
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_30_090700_create_media_table.php`.
- [x] `app/Models/Media.php` + `database/factories/MediaFactory.php`.
  **Audit 2026-10-05:** Built — `app/Models/Media.php`, `database/factories/MediaFactory.php`.
- [x] `database/migrations/2026_09_25_090900_create_stock_serials_table.php` — 02 §7.6.
      Depends on `skus`, `batches`, `locations`, `bins`, `order_lines` (all already migrated).
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_30_090800_create_stock_serials_table.php`.
- [x] `app/Models/StockSerial.php` + `database/factories/StockSerialFactory.php` — include a
      `identity()`-style query scope mirroring `StockLevel::identity()` for
      `(sku_id, location_id, batch_id, status='in_stock')`, since `04 §6.1`'s serial-reservation
      query needs exactly that shape.
  **Audit 2026-10-05:** Built — `app/Models/StockSerial.php::identity()` supplies null-safe SKU/location/batch lookup; `database/factories/StockSerialFactory.php`. Reservation is separately pending in §4.

**Done 2026-09-20** (was: blocked pending doc amendment, §0.2 — resolved by §14's sign-off):

- [x] `database/migrations/2026_09_25_090100_create_roles_table.php`
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_25_090100_create_roles_table.php`. Source present; tests were inspected, not run.
- [x] `database/migrations/2026_09_25_090200_create_role_user_table.php`
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_25_090200_create_role_user_table.php`. Source present; tests were inspected, not run.
- [x] `database/migrations/2026_09_26_090100_create_attachments_table.php` (polymorphic
      `attachable_type`/`attachable_id`, per 02 §4.6's forward reference)
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_26_090100_create_attachments_table.php`. Source present; tests were inspected, not run.
- [x] `database/migrations/2026_09_27_090100_create_carts_table.php`
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_27_090100_create_carts_table.php`. Source present; tests were inspected, not run.
- [x] `database/migrations/2026_09_27_090200_create_cart_lines_table.php`
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_27_090200_create_cart_lines_table.php`. Source present; tests were inspected, not run.
- [x] `database/migrations/2026_09_28_090100_create_payments_table.php`
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_28_090100_create_payments_table.php`. Source present; tests were inspected, not run.
- [x] `database/migrations/2026_09_28_090200_create_invoices_table.php`
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_28_090200_create_invoices_table.php`. Source present; tests were inspected, not run.
- [x] `database/migrations/2026_09_29_090100_create_payment_allocations_table.php` (§14.5.4,
      found during review — not in the original seven)
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_29_090100_create_payment_allocations_table.php`. Source present; tests were inspected, not run.
- [x] `app/Models/{Role,RoleUser,Attachment,Cart,CartLine,Payment,Invoice,PaymentAllocation}.php`
      + factories for each. `Role::users()`/`User::roles()` are read-only convenience relations
      — grant a role via `RoleUser::create()`, not `->attach()` (verified live: Eloquent's
      `BelongsToMany::attach()` with a custom pivot class ignores `RoleUser::UPDATED_AT = null`
      and tries to write a column `role_user` doesn't have; direct `::create()` respects it
      correctly). Documented on both relations' docblocks.
  **Audit 2026-10-05:** Built — `app/Models/{Role,RoleUser,Attachment,Cart,CartLine,Payment,Invoice,PaymentAllocation}.php` and all eight corresponding factories exist.

---

## 2. `03` — Pricing Engine (`app/Domain/Pricing`)

Core integer line/order pricing is implemented. Production promotions, context-gated
cost/margin, caching, coupons, generated PDFs and the full property/performance matrix remain incomplete.

- [ ] `app/Domain/Pricing/Money.php` — the value object CLAUDE.md invariant 1 requires: refuses
      cross-scale (`_e4` vs `_minor`) arithmetic, wraps `roundHalfUpDiv()` (03 §6.2's exact
      integer algorithm). Everything else in this section depends on this existing first.
  **Audit 2026-10-05:** Partly built — `app/Domain/Pricing/Money.php` and `tests/Unit/Domain/Pricing/MoneyTest.php` implement integer half-up division. It is a static helper, not a scale-tagged value object; cross-scale arithmetic is not rejected.
- [ ] `app/Domain/Pricing/Exceptions/NotPurchasableException.php`
  **Audit 2026-10-05:** Not started — no named implementation/test or equivalent capability found in the relevant source, route and test inventories.
- [ ] `app/Domain/Pricing/Exceptions/PriceUnavailableForCurrencyException.php`
  **Audit 2026-10-05:** Not started — no named implementation/test or equivalent capability found in the relevant source, route and test inventories.
- [ ] `app/Domain/Pricing/Exceptions/InvalidQuantityException.php` — 03 §4.6's four failure
      modes, one exception class each (the fourth, "SKU has no base list row", is a hard
      data-integrity error rather than a runtime exception — see the catalogue-validation
      task in §3 below).
  **Audit 2026-10-05:** Partly built — `PriceResolver.php` rejects non-positive quantity using `InvalidArgumentException`, tested by `PriceResolverTest.php`; the specified named exception is absent.
- [ ] `app/Domain/Pricing/ResolvedPrice.php` — the readonly DTO from 03 §4.5 exactly as
      specified, including `unitCostE4` nullable-outside-admin-context per CLAUDE.md invariant
      9 and `promotionCapped`.
  **Audit 2026-10-05:** Partly built — `ResolvedPrice.php` has the readonly fields, but `PriceResolver.php` returns costs without an admin/rep context gate and tax is 0 on its single-SKU path. Bulk resolution nulls costs and resolves tax; see `BulkPriceResolver.php` and `BulkResolveCheckoutTaxConsistencyTest.php`.
- [ ] `app/Domain/Pricing/PriceResolver.php` — implements 03 §4: the precedence chain
      (contract → customer → promotion → tier → base), the break-selection rule (§4.3,
      "falls through to the next rank" when no break row qualifies), the single round-trip
      query of §4.4 against `price_list_items`/`price_lists` using
      `price_list_items_by_sku_idx`, and the promotion-cap re-resolution of §4.5. This is the
      class every other pricing task below calls.
  **Audit 2026-10-05:** Partly built — `PriceResolver.php` / `PriceResolverTest.php` implement rank/break precedence and promotion cap with an injected eligibility fixture. Production defaults to `NullPromotionEligibilityResolver.php`; promotions/rules and real eligibility are absent. Cost-context protection is also pending.
- [ ] `app/Domain/Pricing/BulkPriceResolver.php` — 03 §8's three-queries-per-page path (Q-A/Q-B/Q-C
      candidate lists, break rows via `price_list_items_resolve_idx`, stock levels), returning
      the full break table per SKU rather than one resolved price — this is what the order pad
      (05.1) and `/pricing/bulk-resolve` (06 §9.1) both call, and it is a genuinely different
      code path from `PriceResolver` per 03 §8's own note on which index each uses.
  **Audit 2026-10-05:** Partly built — `BulkPriceResolver.php`, `BulkPriceResolverTest.php`: batched lists/breaks/tax and full break tables exist. Stock is fetched separately, extra SKU/company/tax queries exceed the original three-query description, and production promotion eligibility is empty.
- [x] `app/Domain/Pricing/OrderLinePricer.php` — 03 §6.3, the single-line rounding sequence
      (`unit_price_net_e4 → gross_line_e4 → line_discount_e4 → net_line_e4 →
      line_net_minor` — the *only* `e4→minor` conversion — `line_tax_minor → line_gross_minor`).
      Called once per order line at checkout/quote-build/RMA time.
  **Audit 2026-10-05:** Built — `app/Domain/Pricing/OrderLinePricer.php`, `tests/Feature/Domain/OrderLinePricerTest.php`.
- [x] `app/Domain/Pricing/SpendBreakResolver.php` — 03 §7A.2, the once-per-order query against
      `order_spend_breaks` via `order_spend_breaks_resolve_idx`, returning at most one break.
  **Audit 2026-10-05:** Built — `app/Domain/Pricing/SpendBreakResolver.php`, `tests/Feature/Domain/SpendBreakResolverTest.php`.
- [x] `app/Domain/Pricing/SpendBreakApportioner.php` — 03 §7A.3's apportionment algorithm
      exactly, including the remainder-to-largest-line rule. This is the highest-value place
      to hand-verify against the §7A.5 worked example (three lines, mixed VAT, £32.40 discount
      apportioned as 1860/930/450) as a literal test fixture before anything else touches it.
  **Audit 2026-10-05:** Built — `app/Domain/Pricing/SpendBreakApportioner.php`, `tests/Feature/Domain/SpendBreakApportionerTest.php` (including 1860/930/450).
- [x] `app/Domain/Pricing/OrderPricingPipeline.php` — orchestrates the three-phase sequence of
      03 §7A.4 end to end (item pricing → spend break → tax-last), the object every checkout/
      quote-conversion/RMA-refund call site should call rather than composing the pieces above
      by hand. Tax computed **last**, on post-discount line values — §7A.4's whole point.
  **Audit 2026-10-05:** Built — `app/Domain/Pricing/OrderPricingPipeline.php`, `tests/Feature/Domain/OrderPricingPipelineTest.php`.
- [x] `app/Domain/Pricing/TaxRateResolver.php` — 03 §10: resolves `tax_rates` from
      `skus.tax_class_id` + delivery-address `country_code` + order timestamp; applies
      `companies.tax_exempt`.
  **Audit 2026-10-05:** Built — `app/Domain/Pricing/TaxRateResolver.php`, `tests/Feature/Domain/TaxRateResolverTest.php`; used by checkout and bulk pricing.
- [ ] `app/Domain/Pricing/DiscountAuthorityResolver.php` — 05.3 §6.3's category-tree walk via
      `category_closure_descendant_idx` + `rep_category_discount_limits_resolve_idx`. Placed
      here rather than under a Quotes namespace because 05.8 §10.1 (commission rate
      resolution) reuses the identical query shape — factor the closure-table-walk query into
      a shared `app/Domain/Pricing/CategoryTreeResolver.php` helper both call.
  **Audit 2026-10-05:** Not started — discount/category authority and policy-gated margin service are absent; quotes/rep schema and multi-role default-discount decision (02 §14) are dependencies.
- [ ] `app/Domain/Pricing/MarginCalculator.php` — 03 §11's `line_margin_minor`/`line_margin_pct`
      from the snapshotted `unit_cost_e4`, gated so it is never constructed in a
      customer-facing context (policy gate, not an omitted field — enforce via a constructor
      guard that requires an explicit `AdminContext`/`RepContext` marker object).
  **Audit 2026-10-05:** Not started — discount/category authority and policy-gated margin service are absent; quotes/rep schema and multi-role default-discount decision (02 §14) are dependencies.
- [ ] `app/Domain/Pricing/PricingCache.php` — 03 §9's four cache keys
      (`pricing:lists:{company_id}`, `pricing:breaks:{list_id}:{sku_id}`,
      `pricing:promos:eligible:{company_id}`, `pricing:tax:{tax_class_id}:{country}`),
      event-driven invalidation (never TTL-only) via listeners on writes to `price_list_items`,
      `price_lists`, `promotions`/`promotion_rules` (blocked on §0.3's promotions-table gap —
      build the cache class now, wire the promotion-eligibility key once that table exists),
      `tax_rates`.
  **Audit 2026-10-05:** Partly built — `PricingCache.php` only names/forgets the company-lists key. Resolvers query directly; the other three keys and write-event invalidation are absent.
- [ ] `app/Listeners/FlushPricingCacheOnPriceListItemChanged.php` — dispatched from a
      `PriceListItemChanged` domain event (new `app/Domain/Pricing/Events/PriceListItemChanged.php`)
      fired after commit on any write to `price_list_items`.
  **Audit 2026-10-05:** Not started — no named implementation/test or equivalent capability found in the relevant source, route and test inventories.
- [ ] `app/Rules/Pricing/NoFloatInPricingNamespace.php` — the custom PHPStan rule CLAUDE.md's
      `composer analyse` comment ("static analysis incl. no-float-in-pricing rule") and 03 §12's
      "No floats" property test both require, and which does not exist yet. Register it in
      `phpstan.neon` (currently just `larastan/extension.neon` at level 8 with no custom
      rules) as a rule forbidding `float`/`double` casts and arithmetic anywhere under
      `app/Domain/Pricing`.
  **Audit 2026-10-05:** Not started — `phpstan.neon` contains Larastan level 8 only; no custom no-float rule is registered.
- [ ] `tests/Feature/Domain/PriceResolverTest.php` — the 12 worked-example fixtures from 03 §12
      verbatim, including fixture 7 (the £0.9212 sub-penny break at 1,440 units → £1,326.53).
  **Audit 2026-10-05:** Partly built — `tests/Feature/Domain/PriceResolverTest.php` covers precedence, fall-through, caps, sub-penny unit prices and invalid inputs; the £1,326.53 line-total assertion is in `OrderLinePricerTest.php`. The full numbered fixture matrix is not reproduced verbatim in this file.
- [ ] `tests/Feature/Domain/SpendBreakApportionerTest.php` — the §7A.5 worked example plus
      the 8 additional test scenarios from §7A.7 (#13–#20: threshold off-by-one, competing
      breaks, `max_discount_minor` cap, contract-line exclusion, remainder-penny placement,
      mixed-VAT reproduction, fixed-discount clamping, item-break-and-spend-break-together).
  **Audit 2026-10-05:** Partly built — worked-example, cap, remainder, clamping, threshold, contract-exclusion and combined-break assertions are distributed across `tests/Feature/Domain/{SpendBreakApportioner,SpendBreakResolver,OrderPricingPipeline}Test.php`. The full numbered matrix, including competing-break selection, is not present.
- [ ] `tests/Feature/Domain/PricingPropertyTest.php` — the 8 property tests from 03 §12
      (monotonicity, pack invariance, total consistency, discount apportionment exactness,
      snapshot immutability, determinism at 1,000 runs, precedence completeness, index-only
      resolution) as Pest property/generative tests.
  **Audit 2026-10-05:** Partly built — `PriceResolverTest.php` contains a monotonicity check; line/pipeline and local-parity tests cover arithmetic. No eight-property generative suite, 1,000-run determinism or full snapshot/precedence property matrix exists.
- [ ] `tests/Feature/Performance/PriceListItemsResolveExplainTest.php` — extends the existing
      `HotPathExplainTest.php` pattern to assert Q-B (bulk resolve) shows
      `Index Only Scan` with `Heap Fetches: 0` on `price_list_items_resolve_idx`, per 03 §8's
      15 ms budget — currently one of the ~4 unexplained gaps in the "~18 of Q1–Q22" coverage
      mentioned in the task brief.
  **Audit 2026-10-05:** Partly built — `tests/Feature/Performance/HotPathExplainTest.php` asserts Q1 index-only/zero heap fetches. It does not assert the actual bulk Q-B query and 15 ms budget; the named test is absent.

**Gaps found by the 2026-09-20 completeness audit, folded in here as the natural home:**

- [ ] `app/Domain/Pricing/PriceListPdfGenerator.php` — 01 §5.1's Phase 2 deliverable "price
      list and catalogue PDF generation," currently in no task list at all. Renders a
      customer's resolved price list (via `BulkPriceResolver`) to PDF for offline/print use;
      needs a queued job per `06-api-contract.md` §4.1's `202 Accepted` pattern for
      background-generated documents, not a synchronous request. [G8]
  **Audit 2026-10-05:** Not started — price-list/catalogue generators are absent; real PDF renderer (§16), export scope and access/queue contract are dependencies.
- [ ] `app/Domain/Catalogue/CataloguePdfGenerator.php` — the catalogue half of the same G8
      gap; product/SKU listing export, likely Filament-admin-triggered rather than
      customer-facing. Scope (which fields, per-category vs whole-catalogue) needs deciding
      when this is picked up — not designed here. [G8]
  **Audit 2026-10-05:** Not started — price-list/catalogue generators are absent; real PDF renderer (§16), export scope and access/queue contract are dependencies.
- [ ] `app/Domain/Pricing/CouponRedemptionService.php` — `coupons` is fully signed off
      (02 §14.9; full DDL exists, migration pending) with `coupons_active_redeem_idx` built for exactly
      this lookup, but nothing applies a code: no service resolves a `code` to a coupon,
      validates it (`status`, `validity`, `min_order_value_minor`, `scope`/`price_tier_id`/
      `company_id` eligibility, `usage_limit_total`/`usage_limit_per_company` via
      `orders_coupon_company_idx`), or writes the `orders.coupon_id`/`coupon_discount_minor`
      pair. Sits between `OrderLinePricer` (Pass 1) and `OrderPricingPipeline` in the checkout
      flow — a coupon discount is a `lineDiscountE4` input to `OrderLinePricer`, per 03 §7's
      "coupon / manual override" line-discount path already wired into that class today. [G9]
  **Audit 2026-10-05:** Not started — coupon migration/model/service/tests are absent; implement one coupon per order (stacking beyond one stays deferred), usage locking and cancellation semantics after credit/cancellation foundations.
- [ ] `tests/Feature/Domain/CouponRedemptionServiceTest.php` — including the `times_used`/
      `usage_limit_per_company` race (same lock-ordering discipline as `AllocationService`)
      and the cancelled-order exclusion already documented at 02 §14.9's rebuild formula. [G9]
  **Audit 2026-10-05:** Not started — coupon migration/model/service/tests are absent; implement one coupon per order (stacking beyond one stays deferred), usage locking and cancellation semantics after credit/cancellation foundations.

---

## 3. `03`/`02` — Catalogue validation gap this exposes

- [ ] `app/Domain/Catalogue/SkuActivationValidator.php` — 03 §4.6: "SKU has no `base` list
      row → Hard error. SKU cannot be sold. Blocked at activation." No such validator exists
      yet, and without it `PriceResolver` can be handed an active SKU that has no fallback
      price, which §4.6 says must never happen. Runs on the `skus.status` transition to
      `active` (a model observer or a dedicated `ActivateSku` action — prefer the latter,
      since "thin controllers/models, no business logic in models" per CLAUDE.md conventions).
  **Audit 2026-10-05:** Not started — no activation validator/action checks for a fallback base price; SKU admin status editing in `app/Filament/Resources/SkuResource.php` does not enforce this requirement.

---

## 4. `04` — Inventory Ledger hardening

**Batch-only checkout update (2026-09-26):** `AllocationService` now selects
eligible FEFO/FIFO/LIFO batches inside the order transaction, claiming batch
rows one at a time with `SKIP LOCKED` in strategy order, then locking the
chosen `stock_levels` rows with plain `FOR UPDATE` in global order (§5.2), and
splits a line across batches. Checkout preview uses the same eligibility rule.
Serial-tracked checkout, and batch SKUs with `allocation_strategy = 'none'`,
remain blocked as `batch_tracked_not_supported`.
The configured five-batch soft cap and operator-facing manual-pick warning,
serial reservation/release, and the concurrency matrix below remain pending.
The older file-by-file items in this section describe the intended split
into `BatchSelector`/`BatchSplitter`, not separate code that is required to
duplicate the behavior now inside `AllocationService`.

`AllocationService`, `DeallocationService`, `DeadlockRetryPolicy` are built and match §4.2–4.5
closely (verified by reading `app/Domain/Inventory/AllocationService.php` and
`DeallocationService.php` directly — lock ordering, `NULLS FIRST`, no-external-calls, retry
policy are all correctly in place). What's missing is everything doc 04 specifies *beyond*
the core allocate/deallocate transaction:

- [x] `app/Domain/Inventory/BatchSelector.php` — 04 §5 strategy selection, eligibility
      and candidate locking; equivalent code inside AllocationService counts.
  **Audit 2026-10-05:** Built — equivalent selection is inside `app/Domain/Inventory/AllocationService.php`, using strategy-ordered `SKIP LOCKED` candidates and eligibility checks; `tests/Feature/Domain/BatchCheckoutTest.php` plus actual-candidate Q15 in `HotPathExplainTest.php`. Do not duplicate it solely to obtain the proposed filename.
- [ ] `app/Domain/Inventory/SerialSelector.php` — 04 §6.1's reservation-at-allocation query
      (`stock_serials` at `status='in_stock'`, `ORDER BY id ASC LIMIT :qty FOR UPDATE`) and
      §6.2's status machine transitions (`in_stock → allocated`). Depends on the
      `stock_serials` migration (§1).
  **Audit 2026-10-05:** Not started — no named implementation/test or equivalent capability found in the relevant source, route and test inventories.
- [ ] `app/Domain/Inventory/BatchSplitter.php` — 04 §5.3: greedily consumes batches in
      strategy order until a line's `base_qty` is satisfied, capped at the configured max
      batches per line (default 5, from `system_configurations`), raising a warning rather
      than failing past the cap.
  **Audit 2026-10-05:** Partly built — `AllocationService.php` splits across eligible batches; `BatchCheckoutTest.php` covers FEFO multi-batch allocation. The configured five-batch soft cap/manual-pick warning is absent.
- [ ] `app/Domain/Inventory/Events/StockReceived.php`, `StockAllocated.php`,
      `StockDeallocated.php`, `StockDispatched.php`, `StockAdjusted.php`,
      `LevelBelowReorderPoint.php`, `BatchExpiringSoon.php`, `BatchRecalled.php`,
      `ProjectionDriftDetected.php` — 04 §11's full event list. None exist yet;
      `AllocationService`/`DeallocationService` currently do not dispatch any domain events
      after commit, which every downstream consumer (back-in-stock notifications, warehouse
      queue, dispatch email, recall trace, P1 alerting) needs. Dispatch via
      `DB::afterCommit()` per CLAUDE.md invariant 6/04 §4.4 — never inside the locked
      transaction.
  **Audit 2026-10-05:** Partly built — `app/Domain/Warehouse/Events/{StockReceived,ShipmentDispatched,StocktakePosted,BatchSubstituted,ShortPickRecorded}.php` and `app/Domain/Ordering/Events/OrderPlaced.php` exist. The full inventory event family and allocation/deallocation/reorder/expiry/recall/drift events are absent.
- [ ] `app/Console/Commands/ReconcileStockLevels.php` — 04 §9's nightly ledger-vs-projection
      job (the rebuild query from §2.2, `IS NOT DISTINCT FROM` for null-safe batch
      comparison), writing a result record and firing `ProjectionDriftDetected` on any
      mismatch. **Never auto-corrects** — 04 §9 and CLAUDE.md are explicit that this is a
      hard invariant, not a style choice.
  **Audit 2026-10-05:** Not started — no named implementation/test or equivalent capability found in the relevant source, route and test inventories.
- [ ] `app/Console/Commands/ReconcileStockAllocations.php` — 04 §9's hourly
      `Σ active allocations = allocated_base_qty` check.
  **Audit 2026-10-05:** Not started — no named implementation/test or equivalent capability found in the relevant source, route and test inventories.
- [ ] `app/Console/Commands/ReconcileSerialCounts.php` — 04 §9/§6.3's nightly serial-count
      invariant check. Depends on `stock_serials` (§1).
  **Audit 2026-10-05:** Not started — no named implementation/test or equivalent capability found in the relevant source, route and test inventories.
- [ ] `app/Console/Commands/ReapStaleAllocations.php` — 04 §9's every-15-minutes job releasing
      `pending_payment` allocations past the hold window (default 2 h, configurable via
      `system_configurations`). Must respect 05.2 §10's longer 48 h window for
      `awaiting_approval` orders and 05.6 §7.3's "never reap a paid collection no-show" rule —
      write this *after* those two module features exist, or it will reap orders it
      shouldn't; note the dependency explicitly in the job's docblock.
  **Audit 2026-10-05:** Partly built — `app/Domain/Collection/CollectionExpiry.php` and `app/Console/Commands/ExpireUnpaidCollections.php` release unpaid cash-collection reservations. General pending-payment/48-hour approval reaping is absent; coordinate stock and credit release (§6).
- [ ] `app/Console/Commands/SweepExpiredBatches.php` — 04 §9's nightly `active → expired`
      transition past `expires_on`, plus the 30-day expiring-soon report firing
      `BatchExpiringSoon`.
  **Audit 2026-10-05:** Not started — no named implementation/test or equivalent capability found in the relevant source, route and test inventories.
- [ ] `app/Console/Kernel.php` schedule registration for all five jobs above (currently no
      `routes/console.php` scheduling beyond the default; verify against
      `bootstrap/app.php`'s `withRouting(commands: ...)` wiring).
  **Audit 2026-10-05:** Not started — `routes/console.php` schedules notifications/consumer returns/collection jobs, but none of the required inventory reconciliation/expiry jobs. Laravel 11 scheduling belongs there, not a new Console Kernel.
- [x] `tests/Feature/Domain/BatchSelectorTest.php` — FEFO across 3 batches, `min_remaining_shelf_life`
      exclusion, quarantined-batch exclusion (04 §12's flow fixtures).
  **Audit 2026-10-05:** Built — equivalent fixtures in `tests/Feature/Domain/BatchCheckoutTest.php`: FEFO split, short shelf life, quarantined batches and shortage rollback; no separate selector file is needed.
- [ ] `tests/Feature/Domain/SerialSelectorTest.php` — receipt → allocation → pick → dispatch
      flow fixture; blocked-not-warned assertion for scanning an unallocated serial.
  **Audit 2026-10-05:** Partly built — `tests/Feature/Warehouse/PickingAndDispatchTest.php` tests allocated-serial scan validation and dispatch on fixtures; receipt is covered by `GoodsInServiceTest.php`. Checkout reservation and a full receipt-to-checkout-to-dispatch test are absent.
- [ ] `tests/Feature/Concurrency/StockConcurrencyTest.php` — the C1–C7 concurrency matrix from
      04 §12 that isn't yet covered by the existing `StockAllocationTest.php`/
      `AllocationServiceTest.php` (verify overlap first; at minimum C7 — walk-in till racing a
      collection booking — needs 05.6's collection booking to exist first, so is blocked on
      §9 below).
  **Audit 2026-10-05:** Not started — sequential lock-order/rollback tests exist in `AllocationServiceTest.php` and `DeallocationServiceTest.php`, but no C1–C7 suite using independent parallel connections. C7 also depends on a defined till integration (§25).

---

## 5. `05.1` — Order Pad

Pricing and cart schema prerequisites are built. The pad, structured bulk-add, local
recomputation and cart work; paste/CSV reconciliation, scanning, saved lists and reorder remain.

- [ ] `app/Domain/Ordering/CartService.php` — server-side pad state (05.1 §8.3): add/update/
      remove lines, pack-change-as-line-update (not delete+re-add), last-write-wins on
      concurrent edits with a change notice.
  **Audit 2026-10-05:** Partly built — `app/Domain/Ordering/CartService.php`, `tests/Feature/Domain/CartServiceTest.php`: CRUD, pack updates/merge and guest merge exist. Last-write-wins is implicit; the concurrent-edit change notice is absent.
- [ ] `app/Domain/Ordering/BulkEntryParser.php` — 05.1 §7.1's paste-SKU tolerant parser
      (comma/semicolon/tab/whitespace separators, case-insensitive lookup against
      case-sensitive `sku_code`), producing the reconciliation classification (matched /
      adjusted / not-found / inactive / duplicate) before anything touches the cart.
  **Audit 2026-10-05:** Not started — the named pad convenience capability and tests are absent. Structured `/cart/bulk-add` and warehouse `ScanResolver.php` do not provide paste/CSV reconciliation, pad camera scanning or saved-list/reorder flows.
- [ ] `app/Domain/Ordering/CsvBulkImportJob.php` — 05.1 §7.2, queued for files over 500 rows,
      same reconciliation output as the paste parser, downloadable rejection report.
  **Audit 2026-10-05:** Not started — the named pad convenience capability and tests are absent. Structured `/cart/bulk-add` and warehouse `ScanResolver.php` do not provide paste/CSV reconciliation, pad camera scanning or saved-list/reorder flows.
- [ ] `app/Domain/Ordering/BarcodeResolver.php` — 05.1 §8.2: matches against
      `skus.barcode_ean` then `packs.barcode`.
  **Audit 2026-10-05:** Not started — the named pad convenience capability and tests are absent. Structured `/cart/bulk-add` and warehouse `ScanResolver.php` do not provide paste/CSV reconciliation, pad camera scanning or saved-list/reorder flows.
- [x] `app/Http/Requests/Api/BulkAddCartRequest.php`, `AddCartLineRequest.php`,
      `UpdateCartLineRequest.php`
  **Audit 2026-10-05:** Built — `app/Http/Requests/Api/V1/{BulkAddCart,AddCartLine,UpdateCartLine}Request.php`, exercised by `tests/Feature/Api/CartApiTest.php`.
- [ ] `app/Http/Controllers/Api/CartController.php` — `/cart`, `/cart/lines`,
      `/cart/lines/{id}`, `/cart/bulk-add`, `/cart/apply-account-credit` per 06 §8 (the last
      one blocked on 05.4 §7.5A's account-balance ledger, §8 below).
  **Audit 2026-10-05:** Partly built — `app/Http/Controllers/Api/V1/CartController.php`, `CartApiTest.php`: cart CRUD and structured bulk-add work; `/cart/apply-account-credit` is absent pending the account-credit ledger.
- [ ] `app/Http/Controllers/Api/PricingController.php` — `/pricing/resolve`,
      `/pricing/bulk-resolve` (06 §9.1's exact payload shape — full break table returned,
      cost/margin absent).
  **Audit 2026-10-05:** Partly built — `app/Http/Controllers/Api/V1/PricingController.php` implements bulk-resolve (full breaks, no costs). `/pricing/resolve` is absent from `routes/api.php`.
- [ ] `app/Http/Resources/CartResource.php`, `CartLineResource.php`,
      `BulkResolveResource.php` — money/quantity suffix discipline per 06 §3 enforced at
      serialisation (ULID `public_id` only, never the auto-increment `id`, per 06 §2).
  **Audit 2026-10-05:** Partly built — `app/Http/Resources/Api/V1/CartResource.php` embeds line serialization and ULIDs; bulk pricing serializes in `PricingController.php`. Separate resources are unnecessary, but no common suffix-enforcement mechanism exists (see §13).
- [x] `app/Policies/CartPolicy.php`
  **Audit 2026-10-05:** Built — `app/Policies/CartPolicy.php`, tenancy/guest-access assertions in `tests/Feature/Api/CartApiTest.php`.
- [x] `resources/js/lib/pricing/localRecompute.ts` — 05.1 §5.1's client-side calculator:
      given the full break table from `bulk-resolve`, recompute unit price/line total/
      subtotal/free-delivery progress/spend-break progress on every quantity change with
      **zero network calls**. This is the single most performance-sensitive piece of
      frontend logic in the app (P24 budget: <50ms, no network) and must exactly mirror
      `OrderLinePricer`'s arithmetic or client/server totals will disagree (05.1 §11's
      "any mismatch is a test failure" numeric acceptance criterion).
  **Audit 2026-10-05:** Built — `resources/js/lib/pricing/localRecompute.ts`, its `.test.ts`, `tests/Feature/OrderPad/LocalRecomputeParityTest.php`; the <50 ms production performance budget has not been measured by this audit.
- [x] `resources/js/pages/OrderPad/Index.tsx` — replaces the placeholder
      `resources/js/pages/Dashboard.tsx` as the primary authenticated landing page; 05.1 §4.1's
      layout (search/filter bar, row table, sticky footer).
  **Audit 2026-10-05:** Built — `resources/js/pages/OrderPad/Index.tsx`, `app/Http/Controllers/OrderPadController.php`, `tests/Feature/OrderPad/OrderPadFiltersTest.php`; trade landing selection is in `app/Http/Support/SignIn.php`.
- [x] `resources/js/pages/OrderPad/components/PadRow.tsx` — one row: thumbnail, pack selector,
      price + break table, stock display (05.1 §4.3's exact-figures-not-banding rule), qty
      stepper, line total.
  **Audit 2026-10-05:** Built — `resources/js/pages/OrderPad/components/{PadRow,rowParts}.tsx`, `resources/js/lib/orderPad/display.ts`; `app/Http/Support/StockDisclosure.php` protects figures by audience.
- [ ] `resources/js/pages/OrderPad/components/PasteSkusDialog.tsx`,
      `CsvUploadDialog.tsx` — reconciliation-screen UI per 05.1 §7.
  **Audit 2026-10-05:** Not started — the named pad convenience capability and tests are absent. Structured `/cart/bulk-add` and warehouse `ScanResolver.php` do not provide paste/CSV reconciliation, pad camera scanning or saved-list/reorder flows.
- [x] `resources/js/pages/OrderPad/components/StickyFooter.tsx` — running total, free-delivery
      and spend-break progress (05.1 §5.3).
  **Audit 2026-10-05:** Built — `resources/js/pages/OrderPad/components/StickyFooter.tsx` consumes local totals and separate carriage/spend-break progress.
- [x] `resources/js/lib/keyboard/tabOrder.ts` — 05.1 §8.1's keyboard contract (Tab/Shift+Tab
      skip non-inputs, ↑/↓ step by pack, Enter commits, `/` focuses search, no keyboard traps).
  **Audit 2026-10-05:** Built — `resources/js/lib/keyboard/tabOrder.ts`, `OrderPad/components/rowParts.tsx` implement native Tab, Enter advance, quantity stepping and search shortcut; no automated browser keyboard/a11y test is present.
- [ ] `resources/js/pages/OrderPad/components/BarcodeScanner.tsx` — 05.1 §8.2 mobile camera
      scanning, device-camera-gated (feature-detect, no crash on desktop).
  **Audit 2026-10-05:** Not started — the named pad convenience capability and tests are absent. Structured `/cart/bulk-add` and warehouse `ScanResolver.php` do not provide paste/CSV reconciliation, pad camera scanning or saved-list/reorder flows.
- [ ] `app/Models/Cart.php`, `CartLine.php`, `SavedList.php`, `SavedListLine.php` +
      factories — blocked on `carts`/`cart_lines` (§0.2) and `saved_lists`/`saved_list_lines`
      (§0.3) migrations landing first.
  **Audit 2026-10-05:** Partly built — `app/Models/{Cart,CartLine}.php` and factories exist; `SavedList`/`SavedListLine`, their migrations and factories do not.
- [ ] `app/Domain/Ordering/SavedListService.php`, `ReorderService.php` — 05.1 §7.3.
  **Audit 2026-10-05:** Not started — the named pad convenience capability and tests are absent. Structured `/cart/bulk-add` and warehouse `ScanResolver.php` do not provide paste/CSV reconciliation, pad camera scanning or saved-list/reorder flows.
- [ ] `tests/Feature/OrderPadTest.php` — functional matrix from 05.1 §11 (pack selection,
      break crossing, MOQ/increment adjustment, paste separator styles, reorder flagging
      discontinued lines, barcode matching both `barcode_ean` and `packs.barcode`).
  **Audit 2026-10-05:** Partly built — `tests/Feature/OrderPad/{OrderPadFilters,LocalRecomputeParity}Test.php`, `CartApiTest.php` and `CartServiceTest.php` cover the existing pad/cart slice; paste, CSV, reorder and barcode fixtures are absent with their features.
- [ ] `tests/Feature/Performance/OrderPadExplainTest.php` — the three-query-per-page assertion
      (05.1 §9) plus the "query count constant as rows go 10→100" N+1 guard.
  **Audit 2026-10-05:** Not started — no constant-query-count 10→100 or three-query pad test. Q1 in `HotPathExplainTest.php` is not an end-to-end pad query-count proof.

---

## 6. `05.2` — B2B Accounts & Credit

Application review, legal/verification evidence and company membership are built.
On-account checkout creates credit holds, invoicing converts them, and quantity cancellation
reduces them. Full credit decision/approval/suspension/reconciliation and company credit admin remain.

- [x] `database/migrations/2026_09_26_090100_create_credit_holds_table.php` — 05.2 §7.1, full
      DDL already specified, ready to migrate directly.
  **Audit 2026-10-05:** Built — `database/migrations/2026_10_01_090100_create_credit_holds_table.php`.
- [x] `app/Models/CreditHold.php` + `database/factories/CreditHoldFactory.php`
  **Audit 2026-10-05:** Built — `app/Models/CreditHold.php`, `database/factories/CreditHoldFactory.php`.
- [x] `database/migrations/2026_09_26_090200_create_b2b_application_status_info_requested.php`
      — only needed if §1's `b2b_applications` migration didn't already include
      `info_requested` in the `CHECK` (it should — see §1's note).
  **Audit 2026-10-05:** Built — `database/migrations/2026_09_30_090200_create_b2b_applications_table.php` includes `info_requested`; no follow-up status migration is required.
- [x] `app/Domain/Accounts/ApplicationReviewService.php` — 05.2 Part A: the state machine
      (§4), duplicate detection (§5.2, `ApplicationDuplicates`), the one-transaction approval
      sequence (§5.6: create `companies`, set tier/terms/limit, `account_code` from the
      `account_code` series, link the applicant as `company_users(role=owner)`, copy the
      application address, flush `pricing:lists:{company_id}` after commit). Audited and
      notified after commit. 2026-09-27, pending verification — see 05.2 §16. Migration
      `2026_10_13_090100_seed_account_code_sequence.php`.
  **Audit 2026-10-05:** Built — `app/Domain/Accounts/ApplicationReviewService.php`, `app/Policies/B2bApplicationPolicy.php`, `app/Filament/Resources/TradeApplicationResource.php`; `tests/Feature/Admin/TradeApplicationReviewTest.php`. Covers the stated initial review slice, not follow-ups.
- [ ] Follow-ups from 05.2 §16: reassignment; linking an approval to an existing company
      (additional site); staff-entered applications (`applicant_user_id` NULL); document
      download on the review page. (The `remediable` / applicant message / cooling-period
      amendment is 02 §25.2, signed off 2026-09-28 — built by the tasks below.)
  **Audit 2026-10-05:** Not started — no reassignment/existing-company linking/staff-entry/document-download review flow in `ApplicationReviewService.php` / `ViewTradeApplication.php`; existing approval always creates a company.
- **Application compliance — 02 §25 / 05.2 §17 (signed off 2026-09-28).**
  - **Slice B1 — built 2026-09-28, pending test verification:**
    - [x] Migrations `2026_10_14_090100`–`090400`, with the §25.2 and §25.3 backfills and the
          two settings. The check tables are created empty. Enums `TermsKind`,
          `TermsAcceptanceSource`, `RejectionCategory` and `LegalForm`, each with the §2.5
          CHECK/enum test.
      **Audit 2026-10-05:** Built — `database/migrations/2026_10_14_090100_create_terms_tables.php` through `090400_create_business_verification_check_tables.php`; `tests/Feature/Accounts/ApplicationComplianceMigrationTest.php`.
    - [x] Terms: `TermsVersion` and `TermsAcceptance` models; `TermsPublisher` (SHA-256,
          `configuration.terms_version_published` audit, never in the past, reserved
          `placeholder-` prefix); admin-only Settings → Terms (`TermsVersionResource`,
          `TermsVersionPolicy`; publish and view only); `TermsVersionFactory`;
          `PlaceholderTermsSeeder` (`local` only).
      **Audit 2026-10-05:** Built — `app/Domain/Accounts/TermsPublisher.php`, `app/Filament/Resources/TermsVersionResource.php`, `tests/Feature/Accounts/TermsVersionsTest.php`.
    - [x] Registration: `legal_form`, the Companies House number required for a limited
          company or LLP, `terms_version_id` with a re-prompt when the terms change, and the
          acceptance row (IP through `config/trustedproxy.php`, user agent) in the
          application's transaction. React registration page updated.
      **Audit 2026-10-05:** Built — `app/Domain/Identity/Registration.php`, `app/Http/Requests/Auth/RegisterTradeRequest.php`, `tests/Feature/Auth/TradeRegistrationComplianceTest.php`.
    - [x] Review: rejection category, remediable choice, optional applicant message and
          `reapply_after` from `applications.reapply_cooling_days`; `rejection_category` in
          the audit; the rejection email carries the message (template version 2); legal
          form and terms acceptance on the review screen; legal form copied at approval.
      **Audit 2026-10-05:** Built — `app/Domain/Accounts/ApplicationReviewService.php`, `tests/Feature/Admin/TradeApplicationComplianceTest.php`.
    - [x] Re-application gate, by user (locked) and by email.
      **Audit 2026-10-05:** Built — `app/Domain/Identity/Registration.php`, `app/Http/Requests/Auth/RegisterTradeRequest.php`, `tests/Feature/Auth/TradeRegistrationComplianceTest.php`.
    - [x] PR #20 fixes: a friendly error when two applications with one VAT number are
          approved at the same moment (`companies_vat_uq` caught); postcodes stored as
          `SW1A 1AA`, including the approval address copy.
  - **Slice B2 — built 2026-09-28, pending test verification:**
      **Audit 2026-10-05:** Built — `app/Domain/Accounts/ApplicationReviewService.php`, `tests/Feature/Admin/TradeApplicationComplianceTest.php`.
    - [x] `App\Domain\Accounts\BusinessVerification` with `HmrcVatClient` (OAuth
          client-credentials, token cached), `ViesVatClient` and `CompaniesHouseClient`
          (Laravel HTTP client, 5 s timeout, never throws); the `VerifyApplicationBusiness`
          job (4 tries, backoff 10/60/300 s, only the final result written; first attempt final
          on the sync queue), dispatched after a trade registration commits. Credentials in
          `config/services.php`, documented in `.env.example`. Enums `VatCheckAuthority`,
          `VatCheckOutcome`, `CompaniesHouseCheckOutcome`, `VerificationFailureReason` and
          `VerificationWarning`, plus `CompaniesHouseStatus::REFUSES_APPROVAL`.
      **Audit 2026-10-05:** Built — `app/Domain/Accounts/BusinessVerification.php`, `app/Domain/Accounts/Verification/{HmrcVatClient,ViesVatClient,CompaniesHouseClient}.php`, `app/Jobs/VerifyApplicationBusiness.php`; `tests/Feature/Accounts/BusinessVerificationTest.php`.
    - [x] `UkVatNumber` accepts `XI`; `ApplicationDuplicates` matches the VAT number's
          nine-digit core.
      **Audit 2026-10-05:** Built — `app/Domain/Accounts/BusinessVerification.php`, `app/Domain/Accounts/Verification/{HmrcVatClient,ViesVatClient,CompaniesHouseClient}.php`, `app/Jobs/VerifyApplicationBusiness.php`; `tests/Feature/Accounts/BusinessVerificationTest.php`.
    - [x] Review screen: a Verification section (evidence, age, a stale flag past
          `applications.verification_max_age_days`, history, what approval will require), a
          queue checks badge, and **Re-run checks** (`B2bApplicationPolicy::rerunChecks`,
          audited as `application.verification_requested`). Approval enforces 02 §25.9 —
          refusal, warning codes, acknowledgement — with the approval audit shape per §25.9.
      **Audit 2026-10-05:** Built — `TradeApplicationResource/Pages/ViewTradeApplication.php`, `ApplicationReviewService.php`, `tests/Feature/Admin/TradeApplicationVerificationTest.php`.
    - [x] Tests: each client outcome and failure reason with HTTP fakes (stray requests
          blocked), the retry path, every refusal status and warning code, the stale
          threshold, acknowledgement, re-run access and audit, XI.
      **Audit 2026-10-05:** Built — `app/Domain/Accounts/BusinessVerification.php`, `app/Domain/Accounts/Verification/{HmrcVatClient,ViesVatClient,CompaniesHouseClient}.php`, `app/Jobs/VerifyApplicationBusiness.php`; `tests/Feature/Accounts/BusinessVerificationTest.php`.
    - [x] Fixes from manual testing: the IP and user agent on every staff audit entry
          (`AuditContext`, 07 §6.5); terms "Publish" button and plain-Markdown editor;
          application status badge colours; "Trade applicant — pending" and links to
          applications on customer users; readable subjects in the audit log.
      **Audit 2026-10-05:** Built — `app/Domain/Audit/AuditContext.php`, `tests/Feature/Admin/AuditContextTest.php`, `AdminScreenFixesTest.php`; request-scoped staff context, not console IPs.
    - [ ] Storefront terms page (the current version outside the registration form).
      **Audit 2026-10-05:** Not started — `app/Http/Controllers/Storefront/LegalPageController.php::terms()` serves sale terms only; no current trade-terms page outside registration.
  - [x] **Later slice — public terms of sale at checkout** (02 §25.1, ⚑1): the terms of sale
        version on the checkout page; a `terms_acceptances` row (`kind = 'sale'`, source
        `checkout`, `order_id`) in the order transaction.
    **Audit 2026-10-05:** Built — `app/Domain/Ordering/CheckoutService.php` records accepted sale terms in the transaction; `resources/js/pages/Checkout/Index.tsx`, `tests/Feature/Storefront/PublicCheckoutTest.php`. Shared B2C requirement completed; this does not finish trade compliance.
- [ ] **Credit module — monthly re-checks of approved companies** (02 §25.8 ⚑7): re-run
      the VAT and Companies House checks for every `approved`/`suspended` company monthly, and
      alert `accounts` on a change (deregistered, dissolved, in liquidation). This needs
      its own schema amendment when specified: evidence per company, not per application.
      Build it with credit-limit management (`CompanyResource`, `CreditCheckService`).
  **Audit 2026-10-05:** Not started — only application-level evidence is stored by `BusinessVerification.php`; monthly company checks need the separate signed-off company-evidence schema/spec identified in 02 §25.8 ⚑7.
- [ ] `app/Domain/Accounts/CreditCheckService.php` — 05.2 §8: `available = limit − used −
      held`, the decision table (proceed / prepay-required / awaiting_approval / suspended /
      overdue-blocked). **This must extend, not duplicate, `AllocationService`'s existing
      credit-lock logic** — refactor `AllocationService::lockCompanyCredit()` to call into
      this service rather than reimplementing the arithmetic a second time.
  **Audit 2026-10-05:** Partly built — `AllocationService.php::lockAndCheckCredit()` and `TradeCheckout.php` implement available-credit locking/rejection; `AllocationServiceTest.php`, `CheckoutServiceTest.php`. No full proceed/prepay/approval/suspended/overdue decision service; `TradeCheckout::validate()` is empty.
- [ ] `app/Domain/Accounts/CreditHoldWriter.php` — 05.2 §8.3 full lifecycle: confirmation
      hold, invoice conversion, quantity changes, cancellation and coordinated abandonment release.
      Reuse the existing trade checkout/invoice/cancellation logic rather than duplicate it.
  **Audit 2026-10-05:** Partly built — equivalent hold creation in `TradeCheckout.php`, invoice conversion in `app/Domain/Billing/InvoiceService.php`, cancellation reduction in `PartialCancellations.php`; `CheckoutServiceTest.php`, `InvoiceServiceTest.php`, `PartialCancellationTest.php` (break/access assertions only; no dedicated cancellation hold/refund assertion). General abandoned-order/approval reaper and amendment lifecycle remain absent.
- [ ] `app/Console/Commands/ReconcileCreditProjections.php` — 05.2 §7.2's hourly
      `credit_used_minor`/`credit_held_minor` rebuild-and-compare job, P1 on drift.
  **Audit 2026-10-05:** Not started — no credit reconciliation, coordinated abandoned-order hold reaper or company suspension/reinstatement service. `CustomerSuspensionService.php` suspends a user, not company credit.
- [ ] `app/Console/Commands/ReapStaleCreditHolds.php` — `credit_holds_reaper_idx`-driven,
      mirrors the stock reaper (04 §9); these two reapers should share one scheduled
      transaction per order per 05.2 §8.2's lock order, not run as two independent jobs that
      could race on the same order — implement as one `ReapAbandonedOrders.php` command
      that releases both the stock allocation and the credit hold together, superseding the
      separate `ReapStaleAllocations.php` stub in §4 above. **Resolve this overlap before
      writing either.**
  **Audit 2026-10-05:** Not started — no credit reconciliation, coordinated abandoned-order hold reaper or company suspension/reinstatement service. `CustomerSuspensionService.php` suspends a user, not company credit.
- [ ] `app/Domain/Accounts/SuspensionService.php` — 05.2 §9's automatic suspension threshold
      check and manual reinstatement.
  **Audit 2026-10-05:** Not started — no credit reconciliation, coordinated abandoned-order hold reaper or company suspension/reinstatement service. `CustomerSuspensionService.php` suspends a user, not company credit.
- [ ] `app/Http/Requests/Api/SubmitB2bApplicationRequest.php`,
      `ReviewB2bApplicationRequest.php`
  **Audit 2026-10-05:** Partly built — `app/Http/Requests/Auth/RegisterTradeRequest.php` validates registration; review is Filament actions in `TradeApplicationResource/Pages/ViewTradeApplication.php`. The separate submit/review API requests and signed-in application submission are absent.
- [ ] `app/Http/Controllers/Api/B2bApplicationController.php` — `/applications`,
      `/applications/{id}/request-info`, `/approve`, `/reject` (06 §8).
  **Audit 2026-10-05:** Partly built — `RegisterController.php` creates applications and Filament review calls `ApplicationReviewService.php`; no `/applications` API controller/routes in `routes/api.php`.
- [ ] `app/Http/Controllers/Api/CompanyController.php` — `/company`, `/company/users`,
      `/company/addresses`, `/company/credit` (06 §8).
  **Audit 2026-10-05:** Partly built — `CompanyMemberController.php`, `CompanyInvitationController.php` and `AccountController.php` provide web team management. No full company/profile/addresses/credit API surface in `routes/api.php`.
- [ ] `app/Http/Resources/CompanyCreditResource.php` — limit/used/held/available/balance,
      never exposing raw `id`s.
  **Audit 2026-10-05:** Partly built — `CheckoutPreviewService.php` exposes available credit in preview; the complete limit/used/held/available/balance resource is absent.
- [x] `app/Policies/B2bApplicationPolicy.php` — `admin` and `accounts` review, one ability
      per §4 edge; `grantCredit` limits a credit limit above zero to those roles.
      2026-09-27, pending verification.
  **Audit 2026-10-05:** Built — `app/Domain/Accounts/ApplicationReviewService.php`, `app/Policies/B2bApplicationPolicy.php`, `app/Filament/Resources/TradeApplicationResource.php`; `tests/Feature/Admin/TradeApplicationReviewTest.php`. Covers the stated initial review slice, not follow-ups.
- [ ] `CompanyPolicy.php`, `CompanyUserPolicy.php`
  **Audit 2026-10-05:** Partly built — `app/Policies/CompanyPolicy.php` gates the directory/member management; `CompanyInvitationPolicy.php` and `CompanyMemberService.php` cover membership writes (`CompanyMembersTest.php`). No separate `CompanyUserPolicy` or full credit-management policy exists.
- [x] `app/Filament/Resources/TradeApplicationResource.php` + `Pages/ListTradeApplications.php`,
      `Pages/ViewTradeApplication.php` — the review queue UI (05.2 §5.4), open applications
      oldest first, with start-review/request-info/resume/approve/reject actions, duplicate
      flags, resolved delivery zone and document names (no download yet). 2026-09-27,
      pending verification.
  **Audit 2026-10-05:** Built — `app/Domain/Accounts/ApplicationReviewService.php`, `app/Policies/B2bApplicationPolicy.php`, `app/Filament/Resources/TradeApplicationResource.php`; `tests/Feature/Admin/TradeApplicationReviewTest.php`. Covers the stated initial review slice, not follow-ups.
- [ ] `app/Filament/Resources/CompanyResource.php` — admin credit-limit/terms editing
      (`/admin/companies/{id}/credit`), suspension controls.
  **Audit 2026-10-05:** Partly built — `app/Filament/Resources/CompanyResource.php` is a view-only directory with invitation/member relation managers. Credit-limit/terms editing and company suspension controls are absent; `CompanyPolicy::update()` returns false.
- [ ] `resources/js/pages/Account/Application.tsx` — public application form (05.2 §5.1's
      field list, VAT checksum validation client-side with server re-validation).
  **Audit 2026-10-05:** Partly built — `resources/js/pages/Auth/Register.tsx`, `RegisterTradeRequest.php`, `TradeRegistrationComplianceTest.php` build the registration application. Signed-in application submission, client VAT-checksum validation and the full originally specified field list are absent.
- [ ] `resources/js/pages/Account/ApplicationStatus.tsx` — applicant-facing status page
      (05.2 §4's per-state messaging).
  **Audit 2026-10-05:** Not started — no named implementation/test or equivalent capability found in the relevant source, route and test inventories.
- [ ] `resources/js/pages/Account/Credit.tsx` — buyer-facing available-credit display,
      shown pre-checkout per 05.2 §8.1.
  **Audit 2026-10-05:** Partly built — available credit appears in checkout (`CheckoutPreviewService.php`, `resources/js/pages/Checkout/Index.tsx`); no account Credit page/history or full credit breakdown.
- [ ] `tests/Feature/Domain/CreditCheckServiceTest.php` — CR1–CR5 concurrency matrix (05.2
      §13), especially CR1's 20-parallel-£600-orders-against-£10,000-limit exact-figure
      assertion.
  **Audit 2026-10-05:** Not started — ordinary credit-lock/shortage assertions in `AllocationServiceTest.php` are not CR1–CR5 parallel-connection tests; no 20-parallel-order proof.
- [x] `tests/Feature/Admin/TradeApplicationReviewTest.php` — transitions, refused
      transitions, access, prerequisites, two administrators deciding at once, rollback,
      after-commit notices and audit shapes. Second-site linking is not built, so not tested;
      "prices change immediately" waits on the 03 §9 cache. 2026-09-27, pending verification.
  **Audit 2026-10-05:** Built — `app/Domain/Accounts/ApplicationReviewService.php`, `app/Policies/B2bApplicationPolicy.php`, `app/Filament/Resources/TradeApplicationResource.php`; `tests/Feature/Admin/TradeApplicationReviewTest.php`. Covers the stated initial review slice, not follow-ups.

---

## 7. `05.3` — Quotes & RFQ

Quote/RFQ implementation has not started. Core pricing exists; discount authority,
margin controls, full credit/approval safeguards and a real PDF renderer are still dependencies.

- [ ] `database/migrations/2026_09_27_090100_create_quotes_table.php` — 05.3 §5.1, full DDL
      ready to migrate directly.
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `database/migrations/2026_09_27_090200_create_quote_lines_table.php` — 05.3 §5.2.
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `database/migrations/2026_09_27_090300_create_rep_category_discount_limits_table.php`
      — 05.3 §6.3.
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Models/Quote.php`, `QuoteLine.php`, `RepCategoryDiscountLimit.php` + factories.
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Domain/Quotes/QuoteBuilder.php` — 05.3 §6.1: runs `PriceResolver`/
      `OrderPricingPipeline` per line, applies rep overrides with mandatory reason codes,
      recomputes via the §7A.4 three-phase sequence.
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Domain/Quotes/MarginGuardrailService.php` — 05.3 §6.2's floor logic (proceed /
      warn-with-approval / block-unless-manager-approves), resolved most-specific-first
      (global → category → tier).
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Domain/Quotes/QuoteConversionService.php` — 05.3 §8's one-transaction acceptance:
      lock order companies→stock, copy accepted lines, refresh `unit_cost_e4` (quoted price
      honoured, cost refreshed), credit check + hold, allocate stock, set
      `converted_order_id`. **Must call `AllocationService::allocate()` and
      `CreditCheckService`/`CreditHoldWriter` rather than reimplementing either.**
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Domain/Quotes/QuoteRevisionService.php` — 05.3 §9's supersession chain.
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Console/Commands/SweepExpiredQuotes.php`, `SweepQuoteReminders.php`,
      `SweepStaleDraftQuotes.php` — 05.3 §10's three scheduled jobs.
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Domain/Quotes/QuotePdfGenerator.php` — 05.3 §11, queued (Node/Puppeteer worker per
      doc 01 §5.1 — see §15 below for the worker itself), never includes cost/margin.
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Http/Requests/Api/BuildQuoteRequest.php`, `SendQuoteRequest.php`,
      `AcceptQuoteRequest.php`
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Http/Controllers/Api/QuoteController.php` — full endpoint set from 06 §8 (`/quotes`,
      `/quotes/{id}/lines`, `/submit-for-approval`, `/approve`, `/send`, `/withdraw`,
      `/revise`, `/accept`, `/reject`).
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Http/Controllers/Public/PublicQuoteController.php` — `/public/quotes/{token}`,
      tokenised unauthenticated accept/decline (05.3 §11).
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Http/Resources/QuoteResource.php`, `QuoteLineResource.php` — cost/margin fields
      present only when the authenticated context is rep/manager/admin (03 §11's policy gate,
      never a customer-facing field to accidentally serialise).
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Policies/QuotePolicy.php` — "a rep cannot approve their own quote" (05.3 §14) as an
      explicit policy rule, not a controller `if`.
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `app/Filament/Resources/RepCategoryDiscountLimitResource.php` — grant/revoke UI (05.3
      §6.3), audit-logged.
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `resources/js/pages/Quotes/Builder.tsx` — rep-facing quote builder with live margin
      display (05.3 §6.2) — cost visible here (rep context), never in
      `resources/js/pages/Quotes/PublicView.tsx` (customer-facing, no cost/margin field at
      all in its props, not just hidden in the UI).
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `resources/js/pages/Quotes/ApprovalQueue.tsx` — 05.3 §6.3's approval screen (total
      value, blended discount depth, blended margin, per-line authority breach detail).
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `tests/Feature/Domain/QuoteConversionTest.php` — Q1–Q3 concurrency matrix (05.3 §14):
      simultaneous acceptance producing exactly one order via `quotes_converted_order_uq`.
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).
- [ ] `tests/Feature/Domain/DiscountAuthorityResolutionTest.php` — the six resolution-order
      assertions from 05.3 §14 (explicit category wins over ancestor, ancestor covers
      descendants, rep default fallback, role default fallback, zero-authority-means-zero
      not unlimited, per-line evaluation in a multi-category quote).
  **Audit 2026-10-05:** Not started — no quote/RFQ migration/model/service/route/UI/test implementation. 05.3 specifies the module; full credit/approval, discount authority/margin policy and real PDF generation remain dependencies (not core integer pricing).

---

## 8. `05.4` — RMA & Returns

RMA/credit-note schema and consumer-return services exist. Trade eligibility/fees,
account-credit ledger and trade settlement are absent; consumer settlement explicitly refuses trade RMAs.
There is no remaining credit-note schema doc gap.

- [x] `database/migrations/2026_09_28_090100_create_rmas_table.php` — 05.4 §5.1, full DDL
      ready.
  **Audit 2026-10-05:** Built — `database/migrations/2026_10_18_090200_create_rmas_tables.php` creates both RMA tables (including trade columns); execution against a database was not performed.
- [x] `database/migrations/2026_09_28_090200_create_rma_lines_table.php` — 05.4 §5.2.
  **Audit 2026-10-05:** Built — `database/migrations/2026_10_18_090200_create_rmas_tables.php` creates both RMA tables (including trade columns); execution against a database was not performed.
- [ ] `database/migrations/2026_09_28_090300_create_account_credit_movements_table.php` —
      05.4 §7.5A, including the 2026/default partitions exactly as specified (mirrors the
      existing `stock_movements` partitioning pattern).
  **Audit 2026-10-05:** Not started — trade eligibility/restocking fees/account-credit ledger and customer request flow are absent; consumer `CancellationEligibility.php` / `RefundCalculator.php` do not satisfy trade rules. The ledger migration/lifecycle and concurrency tests are dependencies for trade settlement/apply-balance.
- [ ] `app/Models/Rma.php`, `RmaLine.php`, `AccountCreditMovement.php` + factories.
  **Audit 2026-10-05:** Partly built — `app/Models/{Rma,RmaLine}.php` exist, used by consumer-return tests. No `AccountCreditMovement` or any of the three factories in this entry.
- [ ] `app/Domain/Returns/EligibilityService.php` — 05.4 §6.1's five ordered rules
      (return window, cumulative-returned-quantity cap, `is_refundable`, dispatched-at-all,
      recall-overrides-everything).
  **Audit 2026-10-05:** Not started — trade eligibility/restocking fees/account-credit ledger and customer request flow are absent; consumer `CancellationEligibility.php` / `RefundCalculator.php` do not satisfy trade rules. The ledger migration/lifecycle and concurrency tests are dependencies for trade settlement/apply-balance.
- [ ] `app/Domain/Returns/RestockingFeeCalculator.php` — 05.4 §6.2's
      `max(20% × line_value, £25)` computed per line from **snapshotted**
      `system_configurations` values on the RMA row, never re-read from current config
      after request time. §6.3's fault-based-reasons-are-fee-free table.
  **Audit 2026-10-05:** Not started — trade eligibility/restocking fees/account-credit ledger and customer request flow are absent; consumer `CancellationEligibility.php` / `RefundCalculator.php` do not satisfy trade rules. The ledger migration/lifecycle and concurrency tests are dependencies for trade settlement/apply-balance.
- [ ] `app/Domain/Returns/RefundTaxCalculator.php` — 05.4 §6.4: VAT on net-refund-after-fee
      at the order line's **snapshotted** `tax_rate_bp`.
  **Audit 2026-10-05:** Partly built — `app/Domain/Returns/RefundCalculator.php` uses snapshotted VAT for consumer refunds. Trade restocking-fee/net-refund tax calculation is absent.
- [ ] `app/Domain/Returns/InspectionService.php` — 05.4 §7.4's disposition transaction
      (restock writes `return_in` via `AllocationService`'s sibling stock-movement path —
      **new** `app/Domain/Inventory/RestockService.php` for this, since neither
      `AllocationService` nor `DeallocationService` currently write `return_in` movements;
      quarantine/write-off/return-to-customer have no stock effect).
  **Audit 2026-10-05:** Partly built — `app/Domain/Returns/ReturnInspection.php` implements dispositions and `return_in` restocking with ordered stock locks, tested in `ReturnRefundTest.php`. No trade workflow/serial-return end-to-end coverage or RMA-disposition audit caller.
- [ ] `app/Domain/Returns/ResolutionService.php` — 05.4 §7.5's one-transaction resolution:
      compute fees/refunds, create credit note (blocked on `credit_notes` DDL, §0.3),
      decrement `credit_used_minor`, write `account_credit_movements`, increment
      `order_lines.returned_base_qty`.
  **Audit 2026-10-05:** Partly built — `app/Domain/Returns/ReturnResolution.php` creates consumer credit notes/refunds and returned quantities (`ReturnRefundTest.php`), but explicitly refuses `company_id !== null`. Trade credit-used/balance settlement is absent.
- [ ] `app/Domain/Returns/AccountCreditLedger.php` — 05.4 §7.5A's append-only ledger writer
      and `account_balance_minor` projection maintainer, plus the "apply at checkout" flow
      (§7.5A "Applying balance at checkout" — this is what `/cart/apply-account-credit`
      from §5 above actually calls).
  **Audit 2026-10-05:** Not started — trade eligibility/restocking fees/account-credit ledger and customer request flow are absent; consumer `CancellationEligibility.php` / `RefundCalculator.php` do not satisfy trade rules. The ledger migration/lifecycle and concurrency tests are dependencies for trade settlement/apply-balance.
- [ ] `app/Console/Commands/ReconcileAccountBalances.php` — 05.4 §7.5A's hourly rebuild
      check, P1 on drift, alongside the existing credit reconciliation (§6).
  **Audit 2026-10-05:** Not started — trade eligibility/restocking fees/account-credit ledger and customer request flow are absent; consumer `CancellationEligibility.php` / `RefundCalculator.php` do not satisfy trade rules. The ledger migration/lifecycle and concurrency tests are dependencies for trade settlement/apply-balance.
- [x] `app/Console/Commands/SweepNotReceivedRmas.php` — 05.4 §7.6.
  **Audit 2026-10-05:** Built — equivalent `app/Console/Commands/SweepNotReceivedReturns.php` and schedule in `routes/console.php`; `tests/Feature/Storefront/ReturnSendBackTest.php`. Trade workflow does not yet create these RMAs.
- [ ] `app/Http/Requests/Api/RequestReturnRequest.php`
  **Audit 2026-10-05:** Not started — trade eligibility/restocking fees/account-credit ledger and customer request flow are absent; consumer `CancellationEligibility.php` / `RefundCalculator.php` do not satisfy trade rules. The ledger migration/lifecycle and concurrency tests are dependencies for trade settlement/apply-balance.
- [ ] `app/Http/Controllers/Api/RmaController.php` — `/returns/eligibility`, `/returns`,
      `/{id}/approve`, `/reject`, `/cancel`, `/resolve` (06 §8).
  **Audit 2026-10-05:** Partly built — consumer request/tracking actions in `OrderConfirmationController.php`; staff return actions in `Api/V1/Warehouse/ReturnController.php`. No trade eligibility/request/approval/fee-waiver customer API.
- [x] `app/Http/Controllers/Api/Warehouse/RmaReceiptController.php` — `/warehouse/returns/{id}/receive`,
      `/inspect` (06 §8).
  **Audit 2026-10-05:** Built — `app/Http/Controllers/Api/V1/Warehouse/ReturnController.php` receive/inspect, Form Requests and idempotency; `ReturnRefundTest.php`, `ReturnSendBackTest.php`. Reachable consumer slice, not proof of trade returns.
- [ ] `app/Policies/RmaPolicy.php`
  **Audit 2026-10-05:** Partly built — `app/Policies/RmaPolicy.php` gates staff view/receive/inspect/review/resolve. Trade customer/request and fee-waiver policy coverage is absent; tests are consumer/staff return fixtures.
- [ ] `app/Filament/Resources/RmaResource.php` — handler queue (`rmas_queue_idx`), fee-waiver
      action requiring reason (05.4 §5's `rmas_waiver_chk` mirrored as a required-field UI
      rule, not just a DB constraint the admin discovers on save-failure).
  **Audit 2026-10-05:** Partly built — `resources/js/pages/Warehouse/Returns.tsx` supplies a staff queue via `ReturnsPageController.php`; no Filament RMA resource or trade restocking-fee waiver action.
- [ ] `resources/js/pages/Returns/RequestReturn.tsx` — per-line eligibility + fee/refund
      quote **before submission** (05.4 §7.1's non-negotiable ordering).
  **Audit 2026-10-05:** Partly built — consumer actions/status are embedded in `resources/js/pages/Orders/Confirmation.tsx`; no trade eligibility/fee quote or customer RMA flow.
- [ ] `resources/js/pages/Returns/Tracking.tsx` — customer-facing RMA status (05.4 U4).
  **Audit 2026-10-05:** Partly built — consumer actions/status are embedded in `resources/js/pages/Orders/Confirmation.tsx`; no trade eligibility/fee quote or customer RMA flow.
- [ ] `tests/Feature/Domain/RestockingFeeCalculatorTest.php` — the F1–F8 fee fixtures from
      05.4 §10 verbatim (F1: £371.52 line → £74.30 fee; F2: £11.04 line → £25 fee, £0 refund
      clamp).
  **Audit 2026-10-05:** Not started — trade eligibility/restocking fees/account-credit ledger and customer request flow are absent; consumer `CancellationEligibility.php` / `RefundCalculator.php` do not satisfy trade rules. The ledger migration/lifecycle and concurrency tests are dependencies for trade settlement/apply-balance.
- [ ] `tests/Feature/Domain/AccountCreditLedgerTest.php` — the ledger property tests from
      §7.5A (20 parallel checkouts against one £500 balance apply at most £500 total; no
      `UPDATE`/`DELETE` ever issued, query-log asserted).
  **Audit 2026-10-05:** Not started — trade eligibility/restocking fees/account-credit ledger and customer request flow are absent; consumer `CancellationEligibility.php` / `RefundCalculator.php` do not satisfy trade rules. The ledger migration/lifecycle and concurrency tests are dependencies for trade settlement/apply-balance.

---

## 9. `05.5` — Goods-in, Picking & Dispatch

Receipt, picking, batch substitution, dispatch, per-shipment invoicing and stocktake
are built. Serial checkout reservation, packing, stock-received consumers, substitution audit
and independent-connection concurrency coverage remain. Shipment/stocktake schema is migrated in source
(`2026_10_08_090100`–`090700`), with goods-receipt and serial-count extensions below.

- [x] 02 §23 `goods_receipts`/`goods_receipt_lines` — signed off and migrated 2026-09-25
      (`2026_10_10_090100`, which also seeds `po_number`).
  **Audit 2026-10-05:** Built — `database/migrations/2026_10_10_090100_create_goods_receipts_tables.php`, `tests/Feature/Warehouse/GoodsInServiceTest.php`.
- [x] `app/Domain/Warehouse/GoodsInService.php` — done 2026-09-25: open / receive / close,
      04 §7.1 transaction, 05.5 §4.3 capture rules, variance at close, `sku_costs` at `e4`,
      idempotent on `goods_receipt_lines_idempotency_uq`, incoming per 02 §23.5. With
      `ExpiryPolicy`, `ScanResolver`, `GoodsReceiptPolicy`,
      `Api/V1/Warehouse/GoodsReceiptController` (`/api/v1/warehouse/receipts*`, `/lookup`),
      `Warehouse/GoodsInPageController` and `resources/js/pages/Warehouse/GoodsIn.tsx`.
      Tests: `tests/Feature/Warehouse/GoodsIn{Service,Api}Test.php`,
      `resources/js/lib/goodsIn/entry.test.ts`.
  **Audit 2026-10-05:** Built — `app/Domain/Warehouse/GoodsInService.php`, `resources/js/pages/Warehouse/GoodsIn.tsx`, `resources/js/lib/goodsIn/entry.test.ts`. Source present; tests were inspected, not run.
- [ ] `StockReceived` listeners — back-in-stock, search reindex, backorder auto-allocation
      (05.5 §4.6). The event is dispatched after commit; nothing listens yet.
  **Audit 2026-10-05:** Not started — `AppServiceProvider.php` registers no `StockReceived` consumers; back-in-stock schema/flow, backorder auto-allocation and inventory notification listeners are absent.
- [x] GBP PO confirmation raises `incoming_base_qty` on the NULL-batch row (05.7 §5.1,
      02 §23.5); receiving and cancellation reduce only the outstanding projection.
  **Audit 2026-10-05:** Built — `app/Domain/Purchasing/PurchaseOrderService.php`, `app/Domain/Warehouse/GoodsInService.php`, `tests/Feature/Purchasing/PurchaseOrderServiceTest.php`, `Warehouse/GoodsInServiceTest.php`.
- [x] `app/Domain/Warehouse/PickListGenerator.php` — done 2026-09-25: per shipment, walk
      order, batch/expiry/serials per line (05.5 §5.1–5.2).
  **Audit 2026-10-05:** Built — `app/Domain/Warehouse/{PickListGenerator,PickConfirmationService,DispatchService}.php`, `tests/Feature/Warehouse/PickingAndDispatchTest.php`, `PerShipmentInvoicingTest.php`. Serial paths use fixtures; checkout reservation remains pending.
- [x] `app/Domain/Warehouse/PickConfirmationService.php` — done 2026-09-25: serial scans
      blocked unless allocated to the order; confirm; short pick with `adjustment` + re-plan
      (05.5 §5.4 "as built").
  **Audit 2026-10-05:** Built — `app/Domain/Warehouse/{PickListGenerator,PickConfirmationService,DispatchService}.php`, `tests/Feature/Warehouse/PickingAndDispatchTest.php`, `PerShipmentInvoicingTest.php`. Serial paths use fixtures; checkout reservation remains pending.
- [ ] `app/Domain/Warehouse/BatchSubstitutionService.php` — done 2026-09-25 via the existing
      services. **Audit write pending 02 §15**: movements carry actor/reason meanwhile.
  **Audit 2026-10-05:** Partly built — `BatchSubstitutionService.php`, `PickingAndDispatchTest.php` implement substitution; an `AuditLogger` call is still absent. The audit table exists now (§18), so this is implementation backlog, not a schema block.
- [x] `app/Domain/Warehouse/DispatchService.php` — done 2026-09-25, with per-shipment
      invoicing (`InvoiceService::issueForShipment`, `ShipmentInvoiceShares`) and the
      `shipment.dispatched` notice.
  **Audit 2026-10-05:** Built — `app/Domain/Warehouse/{PickListGenerator,PickConfirmationService,DispatchService}.php`, `tests/Feature/Warehouse/PickingAndDispatchTest.php`, `PerShipmentInvoicingTest.php`. Serial paths use fixtures; checkout reservation remains pending.
- [x] `app/Domain/Warehouse/StocktakeService.php` — done 2026-09-26 per 02 §24 (signed off
      2026-09-26): session per location, nothing written until posting, blind option,
      variance against stock **as counted** (replayed from movements since `counted_at`),
      reason per variance line, serial reconciliation by number (`stocktake_line_serials`).
      `Api/V1/Warehouse/StocktakeController`, `StocktakePolicy`, read-only
      `app/Filament/Resources/StocktakeResource.php`.
  **Audit 2026-10-05:** Built — `app/Domain/Warehouse/StocktakeService.php`, `resources/js/pages/Warehouse/Stocktake.tsx`, `tests/Feature/Warehouse/{StocktakeService,StocktakeApi}Test.php`.
- [ ] Idempotency key handling (05.5 §10) — `app/Http/Middleware/RequireIdempotencyKey.php`,
      applied to receipt/dispatch/serial-scan routes, backed by a
      `idempotency_keys` cache table or Redis-backed store (not in doc 02 — this is
      infrastructure, not a domain table, so it doesn't need a schema doc amendment; confirm
      that framing before building rather than assuming).
  **Audit 2026-10-05:** Partly built — `app/Http/Support/Idempotency.php` is used for receive and dispatch; unique receipt tokens and dispatch state also protect retries (`GoodsInApiTest.php`, `PickingAndDispatchTest.php`). Serial-scan routes lack the specified general key handling; no complete warehouse operation matrix.
- [x] Warehouse endpoints — `Api/V1/Warehouse/GoodsReceiptController`, `ShipmentController`
      (`/api/v1/warehouse/shipments*`) done 2026-09-25; `StocktakeController` 2026-09-26.
  **Audit 2026-10-05:** Built — `app/Http/Controllers/Api/V1/Warehouse/{GoodsReceipt,Shipment,Stocktake}Controller.php`, `app/Policies/{GoodsReceipt,Shipment,Stocktake}Policy.php`, `tests/Feature/Warehouse/{GoodsInApi,FulfilmentApi,StocktakeApi}Test.php`.
- [x] Policies — `GoodsReceiptPolicy`, `ShipmentPolicy` (2026-09-25) instead of one
      `WarehouseOperationPolicy`.
  **Audit 2026-10-05:** Built — `app/Http/Controllers/Api/V1/Warehouse/{GoodsReceipt,Shipment,Stocktake}Controller.php`, `app/Policies/{GoodsReceipt,Shipment,Stocktake}Policy.php`, `tests/Feature/Warehouse/{GoodsInApi,FulfilmentApi,StocktakeApi}Test.php`.
- [x] `resources/js/pages/Warehouse/GoodsIn.tsx` — done 2026-09-25 (see GoodsInService above).
  **Audit 2026-10-05:** Built — the named React page exists and is rendered by its Warehouse page controller; `tests/Feature/Warehouse/WarehouseNavigationTest.php`, `GoodsInApiTest.php` / `FulfilmentApiTest.php`. No browser interaction run in this audit.
- [x] `resources/js/pages/Warehouse/PickList.tsx` — done 2026-09-25.
  **Audit 2026-10-05:** Built — the named React page exists and is rendered by its Warehouse page controller; `tests/Feature/Warehouse/WarehouseNavigationTest.php`, `GoodsInApiTest.php` / `FulfilmentApiTest.php`. No browser interaction run in this audit.
- [x] `resources/js/pages/Warehouse/Dispatch.tsx` — done 2026-09-25.
  **Audit 2026-10-05:** Built — the named React page exists and is rendered by its Warehouse page controller; `tests/Feature/Warehouse/WarehouseNavigationTest.php`, `GoodsInApiTest.php` / `FulfilmentApiTest.php`. No browser interaction run in this audit.
- [x] `resources/js/pages/Warehouse/Stocktake.tsx` — done 2026-09-26.
  **Audit 2026-10-05:** Built — `app/Domain/Warehouse/StocktakeService.php`, `resources/js/pages/Warehouse/Stocktake.tsx`, `tests/Feature/Warehouse/{StocktakeService,StocktakeApi}Test.php`.
- [x] `tests/Feature/Warehouse/PickingAndDispatchTest.php`, `PerShipmentInvoicingTest.php` —
      2026-09-25, including the idempotent-retry assertion (05.5 AC6).
  **Audit 2026-10-05:** Built — `app/Domain/Warehouse/{PickListGenerator,PickConfirmationService,DispatchService}.php`, `tests/Feature/Warehouse/PickingAndDispatchTest.php`, `PerShipmentInvoicingTest.php`. Serial paths use fixtures; checkout reservation remains pending.
- [ ] `tests/Feature/Concurrency/WarehouseConcurrencyTest.php` — W1–W3 from 05.5 §13. Needs
      parallel connections; the sequential paths are covered, the races are not yet.
  **Audit 2026-10-05:** Not started — no W1–W3 independent parallel-connection suite; sequential assertions in warehouse tests do not prove these races.
- [ ] Serial reservation at allocation (ROADMAP §4 `SerialSelector`) — until it exists no
      serial is ever `allocated`, so serial picking, serial substitution and serial re-plan
      are built and tested on fixtures but unreachable from checkout.
  **Audit 2026-10-05:** Not started — checkout blocks serial-tracked lines in `CheckoutService.php` / `CheckoutPreviewService.php`; allocated serial fixtures do not make checkout reservation reachable.
- [ ] Packing (05.5 §6) — parcels, pallet builds, scan-to-parcel verification. Dispatch
      currently records parcel count and weight only; `shipments.status = 'packed'` is unused.
  **Audit 2026-10-05:** Not started — no parcel/pallet packing model/UI/scan verification; `DispatchService.php` records counts/weight without a packing workflow.

---

## 10. `05.6` — Delivery Zones, Rates & Collection Slots

Delivery rating/thresholds and collection placement/cash/handover exist. Slot/bookings
schema is in `2026_10_24_090100_add_collection_and_cash_payment.php`. Recurring-pattern
authoring UI, rescheduling/closed-slot moves, reminders and parallel-connection capacity proof remain.

- [x] `app/Domain/Delivery/ZoneResolver.php` — 05.6 §4.4's specificity-ordered postcode
      resolution query (`delivery_zone_postcodes_resolve_idx`). The `PO3`-vs-`PO31` test case
      from §11 is the canonical correctness check for this class specifically.
  **Audit 2026-10-05:** Built — `app/Domain/Delivery/ZoneResolver.php`, `tests/Feature/Delivery/DeliveryRatingTest.php` postcode dataset including PO3/PO31.
- [x] `app/Domain/Delivery/RateResolver.php` — 05.6 §5.1's weight/method computation and
      §5.3's rate-band resolution + surcharge arithmetic (one rounding operation, integer,
      via the `Money` class from §2).
  **Audit 2026-10-05:** Built — equivalent `app/Domain/Delivery/{ConsignmentWeigher,CarriageCalculator,DeliveryQuoter}.php`, `tests/Feature/Delivery/DeliveryRatingTest.php`.
- [x] `app/Domain/Delivery/ThresholdEvaluator.php` — 05.6 §6: minimum-order and
      carriage-paid-threshold checks against the **post-spend-break** net subtotal (depends
      on §2's `SpendBreakApportioner` having already run).
  **Audit 2026-10-05:** Built — `app/Domain/Delivery/ThresholdEvaluator.php`, `DeliveryRatingTest.php` (minimum boundary and post-spend-break carriage). Go-live values remain a client decision (§0.4).
- [x] `database/migrations/2026_09_29_090100_create_collection_slots_table.php` — 05.6 §7.1,
      full DDL ready.
  **Audit 2026-10-05:** Built — `database/migrations/2026_10_24_090100_add_collection_and_cash_payment.php` creates slots/bookings. No duplicate migrations needed.
- [x] `database/migrations/2026_09_29_090200_create_collection_bookings_table.php` — 05.6
      §7.1.
  **Audit 2026-10-05:** Built — `database/migrations/2026_10_24_090100_add_collection_and_cash_payment.php` creates slots/bookings. No duplicate migrations needed.
- [ ] `app/Models/CollectionSlot.php`, `CollectionBooking.php` + factories.
  **Audit 2026-10-05:** Partly built — `app/Models/{CollectionSlot,CollectionBooking}.php` exist; corresponding factories are absent. `ClickAndCollectTest.php` constructs records directly.
- [x] `app/Domain/Delivery/CollectionBookingService.php` — 05.6 §7.2's lock-order extension
      (companies → collection_slots → stock_levels). **This changes `AllocationService`'s
      contract**: it currently locks companies then stock_levels only (per its own docblock,
      "collection_slots — OMITTED... does not exist yet"). Once this table exists,
      `AllocationService::allocate()` needs a slot-locking hook inserted at position 2 for
      collection-type orders — do not build a parallel allocation path; extend the existing
      one, exactly as the doc requires ("joins the single global lock order rather than
      creating a parallel one").
  **Audit 2026-10-05:** Built — equivalent `app/Domain/Collection/{CollectionSlots,CollectionBookings}.php` plus `CheckoutService.php`/`TradeCheckout.php` slot hook; `ClickAndCollectTest.php` asserts companies → slots → stock locking. Rescheduling remains a separate follow-up below.
- [ ] `app/Http/Controllers/Api/DeliveryController.php` — `/delivery/quote` (06 §8).
  **Audit 2026-10-05:** Partly built — `DeliveryQuoter.php` is exposed through `/checkout/preview` (`CheckoutController.php`, `CheckoutPreviewApiTest.php`); dedicated `/delivery/quote` route is absent.
- [ ] `app/Http/Controllers/Api/CollectionSlotController.php` — `/collection-slots`,
      `/collection-bookings` (idempotency required per 06 §6).
  **Audit 2026-10-05:** Partly built — `app/Http/Controllers/Api/V1/CollectionSlotController.php` lists slots; checkout books them under idempotency. No separate `/collection-bookings` CRUD/reschedule API.
- [x] `app/Policies/CollectionBookingPolicy.php`
  **Audit 2026-10-05:** Built — `app/Policies/CollectionBookingPolicy.php` gates staff counter access, cash/hand-over and cash report; `tests/Feature/Storefront/ClickAndCollectTest.php` includes counter and unpaid-handover authorization. Rescheduling is tracked separately.
- [ ] `app/Filament/Resources/DeliveryZoneResource.php`, `DeliveryRateResource.php` —
      admin zone/rate management (already-migrated tables, no Filament resource yet).
  **Audit 2026-10-05:** Not started — no named implementation/test or equivalent capability found in the relevant source, route and test inventories.
- [ ] `app/Filament/Resources/CollectionSlotResource.php` — recurring weekly pattern
      generation UI (05.6 §7.3).
  **Audit 2026-10-05:** Partly built — `CollectionSlotResource.php` provides close/day-close/reopen. `GenerateCollectionSlots.php` generates from settings daily; recurring-pattern authoring UI and moving existing bookings are absent.
- [x] `resources/js/pages/Checkout/DeliveryQuote.tsx` — carriage shown pre-payment (05.6 §8),
      free-delivery vs spend-break progress shown as **distinct** figures.
  **Audit 2026-10-05:** Built — equivalent inline delivery quote in `resources/js/pages/Checkout/Index.tsx`; separate progress in `OrderPad/components/StickyFooter.tsx`, `CheckoutPreviewApiTest.php` / `DeliveryRatingTest.php`.
- [x] `resources/js/pages/Checkout/CollectionSlotPicker.tsx`.
  **Audit 2026-10-05:** Built — equivalent inline slot picker in `resources/js/pages/Checkout/Index.tsx`, `tests/Feature/Storefront/ClickAndCollectTest.php`.
- [x] `tests/Feature/Domain/ZoneResolverTest.php` — all 11 postcode fixtures from 05.6 §11
      verbatim.
  **Audit 2026-10-05:** Built — equivalent postcode dataset in `tests/Feature/Delivery/DeliveryRatingTest.php` using `database/seeders/DeliveryZoneSeeder.php`.
- [ ] `tests/Feature/Concurrency/CollectionSlotConcurrencyTest.php` — D1–D3 from 05.6 §11
      (20 parallel bookings on a capacity-3 slot → exactly 3 succeed).
  **Audit 2026-10-05:** Not started — `ClickAndCollectTest.php` covers capacity sequentially and inspects lock order; no independent-connection D1–D3 concurrency suite.

---

## 11. `05.7` — Purchasing, Containers & Landed Cost

Phase 3. Supplier, container, PO and PO-line schema exists; later landed-cost DDL
remains draft in 05.7.

- [x] Done 2026-09-25 as `2026_10_09_090100_create_suppliers_table.php` — 05.7 §4.
  **Audit 2026-10-05:** Built — the named `database/migrations/2026_10_09_090100`–`090400` files exist; `tests/Feature/Purchasing/PurchaseOrderServiceTest.php`.
- [x] Done 2026-09-25 as `2026_10_09_090200_create_containers_table.php` — 05.7 §6.
  **Audit 2026-10-05:** Built — the named `database/migrations/2026_10_09_090100`–`090400` files exist; `tests/Feature/Purchasing/PurchaseOrderServiceTest.php`.
- [x] Done 2026-09-25 as `2026_10_09_090300_create_purchase_orders_table.php` — 05.7 §5.
  **Audit 2026-10-05:** Built — the named `database/migrations/2026_10_09_090100`–`090400` files exist; `tests/Feature/Purchasing/PurchaseOrderServiceTest.php`.
- [x] Done 2026-09-25 as `2026_10_09_090400_create_purchase_order_lines_table.php` — 05.7 §5.
  **Audit 2026-10-05:** Built — the named `database/migrations/2026_10_09_090100`–`090400` files exist; `tests/Feature/Purchasing/PurchaseOrderServiceTest.php`.
- [ ] `database/migrations/2026_09_30_090500_create_container_costs_table.php` — 05.7 §6.
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.
- [ ] `database/migrations/2026_09_30_090600_create_container_cost_allocations_table.php` —
      05.7 §7.
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.
- [ ] `database/migrations/2026_09_30_090700_create_commodity_duty_rates_table.php` — 05.7
      §9.2.
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.
- [x] `app/Models/Supplier.php`, `Container.php`, `PurchaseOrder.php`,
      `PurchaseOrderLine.php` + factories — done 2026-09-25.
  **Audit 2026-10-05:** Built — `app/Models/{Supplier,Container,PurchaseOrder,PurchaseOrderLine}.php` and their factories exist.
- [ ] `app/Models/ContainerCost.php`, `ContainerCostAllocation.php`, `CommodityDutyRate.php`
      + factories — with their migrations above (05.7 §6–7, §9.2 still draft).
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.
- [ ] `app/Domain/Purchasing/LandedCostApportioner.php` — 05.7 §8.2's algorithm exactly:
      independent per-bucket apportionment (fob_value/weight/volume/units basis), remainder-
      to-largest-basis-value rule, `rounding_residual_minor` recorded not discarded. **Hand-
      verify against the §8.3 worked example** (3-line container, £25,200 total landed,
      grater at £0.5240/unit, −80p residual on line A) as a literal fixture before anything
      else uses this class.
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.
- [ ] `app/Domain/Purchasing/IncotermValidator.php` — 05.7 §8.4's double-count warnings
      (CIF: don't re-apportion freight; DDP: nothing apportioned).
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.
- [ ] `app/Domain/Purchasing/DutyRateResolver.php` — 05.7 §9.2's most-specific-wins
      resolution (`hs_code` + `origin_country`, NULL origin = general rate).
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.
- [x] `app/Domain/Purchasing/ReorderSuggestionService.php` — 05.7 §10 (amended and signed
      off 2026-09-26): one set-based query per (SKU, location) opted in by a reorder point,
      stock on order as cover, last-PO supplier and pack, percentile outlier exclusion,
      location-then-global config. Done 2026-09-26 with `ReorderSuggestion` read model,
      policy, Filament view and `tests/Feature/Purchasing/ReorderSuggestionTest.php`.
  **Audit 2026-10-05:** Built — `app/Domain/Purchasing/ReorderSuggestionService.php`, `app/Filament/Resources/ReorderSuggestionResource.php`, `tests/Feature/Purchasing/ReorderSuggestionTest.php`; actual query explained in `HotPathExplainTest.php`.
- [ ] `app/Domain/Purchasing/SupplierPerformanceReport.php` — 05.7 §11's on-time/
      short-shipment/quality-rate aggregation.
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.
- [x] `app/Domain/Warehouse/GoodsInService.php` receives against PO lines, records
      references, and updates `purchase_order_lines.received_base_qty`.
  **Audit 2026-10-05:** Built — `app/Domain/Purchasing/PurchaseOrderService.php`, `app/Domain/Warehouse/GoodsInService.php`, `tests/Feature/Purchasing/PurchaseOrderServiceTest.php`, `Warehouse/GoodsInServiceTest.php`.
- [x] First purchasing slice (2026-09-26): GBP supplier administration and draft/edit/
      confirm/cancel PO workflow in Filament, with transactional incoming projection;
      `PurchaseOrderService`, resource policies, and feature tests. Confirmation is
      recorded after supplier acceptance outside B2X; no PO delivery is implemented.
  **Audit 2026-10-05:** Built — `app/Domain/Purchasing/PurchaseOrderService.php`, `app/Domain/Warehouse/GoodsInService.php`, `tests/Feature/Purchasing/PurchaseOrderServiceTest.php`, `Warehouse/GoodsInServiceTest.php`.
- [x] Outstanding POs (2026-09-26): read-only line-level Filament view for admin and
      purchasing, scoped to open PO statuses and `base_qty > received_base_qty`;
      remaining base units and line/PO expected-date fallback, no customer ETA.
  **Audit 2026-10-05:** Built — `app/Filament/Resources/OutstandingPurchaseOrderResource.php`, `tests/Feature/Purchasing/OutstandingPurchaseOrdersTest.php`.
- [ ] `app/Http/Controllers/Api/Admin/SupplierController.php`, `PurchaseOrderController.php`,
      `ContainerController.php` — the `/admin/suppliers`, `/admin/purchase-orders`,
      `/admin/containers`, `/admin/containers/{id}/apportion` (202/job) endpoints (06 §8).
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.
- [ ] `app/Jobs/ApportionContainerCostsJob.php` — the queued job behind
      `/admin/containers/{id}/apportion`.
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.
- [x] `app/Policies/PurchaseOrderPolicy.php` and `SupplierPolicy.php` for admin and
      purchasing; no delete ability.
  **Audit 2026-10-05:** Built — `app/Policies/{PurchaseOrder,Supplier}Policy.php`, `app/Filament/Resources/{Supplier,PurchaseOrder}Resource.php`, `tests/Feature/Purchasing/PurchaseOrderAccessTest.php`.
- [ ] `app/Policies/ContainerPolicy.php`.
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.
- [x] `app/Filament/Resources/SupplierResource.php` and `PurchaseOrderResource.php`.
  **Audit 2026-10-05:** Built — `app/Policies/{PurchaseOrder,Supplier}Policy.php`, `app/Filament/Resources/{Supplier,PurchaseOrder}Resource.php`, `tests/Feature/Purchasing/PurchaseOrderAccessTest.php`.
- [ ] `app/Filament/Resources/ContainerResource.php` and container-cost apportionment UI.
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.
- [x] Reorder suggestions surface — built as the read-only Filament resource
      `ReorderSuggestionResource` (Purchasing → Reorder suggestions) rather than a dashboard
      widget, so it pages, sorts and filters in SQL. The `/admin/reorder-suggestions` API
      endpoint stays with the admin API controllers above.
  **Audit 2026-10-05:** Built — `app/Domain/Purchasing/ReorderSuggestionService.php`, `app/Filament/Resources/ReorderSuggestionResource.php`, `tests/Feature/Purchasing/ReorderSuggestionTest.php`; actual query explained in `HotPathExplainTest.php`.
- [x] Reorder settings (05.7 §10.7, signed off 2026-09-27): `ReorderSettingsService::set()` writes
      the reorder point and quantity on the NULL-batch row and zeroes batch rows' copies; the
      Purchasing → Reorder settings page and an edit action on each suggestion row. **Carry into
      `ReconcileStockLevels` (§4): a rebuild must update quantities in place, never delete and
      re-insert `stock_levels` rows, or reorder settings are lost.**
  **Audit 2026-10-05:** Built — `app/Domain/Purchasing/ReorderSettingsService.php`, `app/Filament/Resources/ReorderSettingResource.php`, `tests/Feature/Purchasing/ReorderSettingsTest.php`.
- [ ] `tests/Feature/Domain/LandedCostApportionerTest.php` — L1–L8 fixtures from 05.7 §14
      verbatim, especially L1 reproducing the §8.3 table exactly including the residual.
  **Audit 2026-10-05:** Not started — container-cost/duty/apportionment/performance and full purchasing API slice are absent. Existing GBP PO/receipt/reorder flows are not container-cost implementation; landed-cost method/incoterm fixtures and container UI must follow the specified schema.

---

## 12. `05.8` — Dropship & Rep Tools

Phase 3. Depends on §11 (landed cost, for margin-based commission per §10.2) and §5's
order placement path (for `orders.fulfilment_type='dropship'`).

- [ ] `database/migrations/2026_10_01_090100_create_dropship_profiles_table.php` — 05.8 §4.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `database/migrations/2026_10_01_090200_create_customer_activities_table.php` — 05.8 §9.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `database/migrations/2026_10_01_090300_create_rep_commission_rules_table.php` — 05.8
      §10.1.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `database/migrations/2026_10_01_090400_create_rep_commissions_table.php` — 05.8 §10.3.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Models/DropshipProfile.php`, `CustomerActivity.php`, `RepCommissionRule.php`,
      `RepCommission.php` + factories.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Domain/Dropship/BlindDocumentGenerator.php` — 05.8 §5.5: a **distinct document
      type** with no price field in its data payload at all (not a template that omits
      rendering it — the payload itself must not carry the field, per the doc's explicit
      "a template cannot accidentally render what was never passed to it" reasoning).
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Domain/Dropship/BatchOrderUploadJob.php` — 05.8 §5.4: credit checked against the
      **batch total**, not per order; allocated in file order; nothing committed until
      confirmed.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Domain/Dropship/DropshipOrderValidator.php` — enforces §5.2's one-destination
      rule at the domain layer (not just a UI constraint).
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Domain/Reps/CustomerBookService.php` — 05.8 §7's rep dashboard aggregation
      (last order, trend, open quotes, credit position, overdue invoices, open RMAs, last
      activity) — one query set per `companies_rep_idx`.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Domain/Reps/LapseDetectionService.php` — 05.8 §7.1's median-order-interval
      calculation with the fewer-than-three-orders absolute-threshold fallback.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Domain/Reps/OrderOnBehalfSession.php` — 05.8 §8's rules as an enforced session
      object: explicit account selection, all three of `user_id`/`placed_by_user_id`/
      `sales_rep_user_id` always written, **no override of credit/suspension/approval
      controls** — implement the "cannot bypass" rules as guard clauses this class exposes,
      not as scattered checks in each controller that touches order-on-behalf.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Domain/Reps/CommissionAccrualService.php` — 05.8 §10.4/§10.4a/§10.4b: accrues on
      invoicing (goods lines only, excluding carriage/fees/cancellation charges), keys on
      `sales_rep_user_id` stamped at placement (not `placed_by_user_id`), moves to
      `payable` on payment, writes negative clawback rows on returns (never edits).
      **Depends on §11's landed cost being live** — commission on FOB-based margin "would
      pay out on a margin that does not exist" per 05.8 §10.2, so do not build this against
      provisional/FOB-only cost data.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Http/Controllers/Api/Rep/CustomerBookController.php`, `SessionController.php`,
      `ActivityController.php`, `CommissionController.php` — the `/rep/*` endpoints (06 §8).
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Http/Controllers/Api/Dropship/BatchOrderController.php`,
      `DropshipProfileController.php` — the `/dropship/*` and `/admin/dropship-profiles`
      endpoints.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Policies/DropshipProfilePolicy.php`, `RepSessionPolicy.php` — the
      cannot-bypass-controls rules enforced here too, as the authorisation layer's own
      check, not solely trusted to `OrderOnBehalfSession`'s guard clauses (defence in depth,
      per 07 §6.2's "privilege escalation explicitly blocked").
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Filament/Resources/DropshipProfileResource.php` — approval workflow
      (`pending_approval → active`).
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `app/Filament/Resources/RepCommissionRuleResource.php`.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `resources/js/pages/Rep/CustomerBook.tsx`, `OrderOnBehalf.tsx` (with the persistent
      "whose account is active" banner from §8), `CommissionStatement.tsx`.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `resources/js/pages/Dropship/BatchUpload.tsx` — reconciliation screen mirroring
      05.1's paste/CSV pattern.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `tests/Feature/Domain/CommissionAccrualServiceTest.php` — the clawback-excludes-the-
      retained-fee assertion from 05.8 §13 (goods commission clawed back in full, retained
      fee generates none), the self-service-accrues-to-assigned-rep case (§10.4b).
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.
- [ ] `tests/Feature/Domain/LapseDetectionServiceTest.php` — median-vs-absolute-threshold
      fixtures.
  **Audit 2026-10-05:** Not started — no dropship/rep/commission migrations, services, endpoints, UI or tests. Shared company membership/payment/stock foundations exist; quote discount authority, credit safeguards and PDF rendering remain dependencies. Rep order-on-behalf authorization must precede these channels.

---

## 13. `06` — API Contract cross-cutting infrastructure

These apply across every module above rather than to one; build early since most controller
tasks in §5–§12 depend on them.

- [ ] `routes/api.php` — complete the first-party v1 route catalogue from 06,
      registered through `bootstrap/app.php`, with session authentication and CSRF.
  **Audit 2026-10-05:** Partly built — `routes/api.php` is loaded by `bootstrap/app.php`; v1 cart, pricing, stock, checkout and warehouse routes exist with session/CSRF. It is not the full 06 contract: applications, quotes, company credit, order approval etc. remain absent.
- [ ] `app/Http/Middleware/EnsureIdempotencyKey.php` — 06 §6's required-on-these-operations
      idempotency handling, backed by a `idempotency_responses` cache table (24h TTL,
      stores request hash + response) — infrastructure, not a doc-02 domain table.
  **Audit 2026-10-05:** Partly built — equivalent `app/Http/Support/Idempotency.php`, used by checkout/receipt/dispatch/returns. Other required operations are unbuilt; after-TTL exactly-once checkout needs a schema-backed unique token (noted in this class).
- [x] `app/Exceptions/ApiExceptionHandler.php` (or `bootstrap/app.php` render callbacks)
      — one 06 §4 error envelope, domain-error mapping and documented status codes.
  **Audit 2026-10-05:** Built — equivalent `app/Http/Exceptions/ApiException.php` and render callbacks in `bootstrap/app.php`; envelope assertions in `CartApiTest.php`, `CheckoutApiTest.php`, `CheckoutPreviewApiTest.php`.
- [ ] `app/Http/Support/KeysetPaginator.php` — 06 §5.1's opaque-cursor row-constructor
      pagination helper (`(name, id) > (:name, :id)`), used by every paginated endpoint;
      centralise here rather than reimplementing per controller.
  **Audit 2026-10-05:** Partly built — keyset cursor implementations in `app/Domain/Catalogue/OrderPadCatalogue.php` and `app/Domain/Storefront/PublicAccountHistory.php`; no common paginator or all-resource cursor coverage.
- [ ] `app/Http/Support/MoneyResourceCast.php` / a shared `JsonResource` trait — enforces
      06 §3's suffix discipline (`_e4`/`_minor`/`_base_qty` never floats, `display` block
      alongside where needed) at the serialisation boundary, so no individual Resource class
      can accidentally cast a money column to float.
  **Audit 2026-10-05:** Partly built — `Api/V1/CartResource.php` and other built resources use integer suffix fields. No shared cast/trait or rule enforcing the entire resource catalogue.
- [ ] `app/Http/Controllers/Api/CheckoutController.php` — `/checkout/preview` (side-effect-
      free, 06 §9.2) and `/checkout` (06 §9.3, `expected_total_gross_minor` mismatch → 409
      `price_changed`, idempotency required). This is the endpoint that finally wires
      together `OrderPricingPipeline` (§2), `CreditCheckService` (§6),
      `CollectionBookingService`/`AllocationService` (§10/existing) into the single checkout
      transaction the whole spec has been building toward — the highest-value integration
      point in the entire roadmap.
  **Audit 2026-10-05:** Partly built — `app/Http/Controllers/Api/V1/CheckoutController.php`, `CheckoutPreviewApiTest.php` / `CheckoutApiTest.php` implement preview/place with price/stock/carriage/credit. Backorders, full credit/approval/suspension/overdue gates remain absent in `TradeCheckout.php`/`CheckoutPreviewService.php`.
- [x] `app/Http/Requests/Api/CheckoutRequest.php`, `CheckoutPreviewRequest.php`
  **Audit 2026-10-05:** Built — `app/Http/Requests/Api/V1/{PlaceOrder,CheckoutPreview}Request.php`, exercised by `CheckoutApiTest.php` and `CheckoutPreviewApiTest.php`.
- [ ] `app/Http/Controllers/Api/OrderController.php` — `/orders`, `/{id}/approve`, `/reject`,
      `/cancel`, `/reorder`, `/{id}/documents` (06 §8).
  **Audit 2026-10-05:** Partly built — web order view/cancellation in `OrderConfirmationController.php`; no full trade `/orders` list/approve/reject API and approval lifecycle.
- [ ] `app/Http/Controllers/Api/StockAvailabilityController.php` — `/stock/availability`
      (06 §9.4 — batch/serial detail explicitly **not** exposed to customer-facing callers).
  **Audit 2026-10-05:** Partly built — `app/Http/Controllers/Api/V1/StockController.php` supplies audience-filtered availability, but incoming `expected_on` is always null: there is no agreed source column. Endpoint exists; the full 06 response contract needs an ETA mapping/schema decision.
- [ ] `app/Http/Middleware/EnforceRateLimits.php` (or Laravel's built-in `throttle:` with
      named limiters registered in a new `app/Providers/RateLimitServiceProvider.php`) — the
      per-endpoint-class limits from 06 §12.
  **Audit 2026-10-05:** Partly built — `routes/api.php` throttles checkout/card-intent; `routes/web.php` throttles auth and invitations. Full 06 per-user/IP expensive/read/write limiter matrix is absent.
- [ ] `app/Console/Commands/GenerateOpenApiSpec.php` + CI step — 06 §14's "generated from
      code, not maintained separately" requirement; nothing currently generates one.
  **Audit 2026-10-05:** Not started — no named implementation/test or equivalent capability found in the relevant source, route and test inventories.
- [ ] `tests/Feature/Api/IdempotencyTest.php` — the seven required-idempotency operations
      from 06 §6's table, each called twice with one key.
  **Audit 2026-10-05:** Partly built — idempotency/reuse assertions in `CheckoutApiTest.php`, `GoodsInApiTest.php`, `PickingAndDispatchTest.php`, return tests. No dedicated seven-operation suite; missing operations and after-expiry guarantee remain.
- [ ] `tests/Feature/Api/TenancyIsolationTest.php` — 06 §16.7's "out-of-tenancy ids return
      404" verified for every company-scoped resource.
  **Audit 2026-10-05:** Partly built — cross-company 404 checks in `CartApiTest.php` and warehouse access tests; no contract-wide tenancy suite over unbuilt quote/company/application/order routes.
- [ ] `tests/Feature/Api/PolicyCoverageTest.php` — 06 §16.10 / 07 §6.2's route-to-policy
      coverage test with **zero exemptions** — this is both an API-contract acceptance
      criterion and a security NFR gate (§16 below references it too; build once, gate both).
  **Audit 2026-10-05:** Partly built — policy-access tests exist per module (`CatalogueAuthorizationTest.php`, `FilamentPanelAccessTest.php`, warehouse/API tests); no automated route-to-policy completeness assertion.

---

## 14. Realtime Gateway hardening (blocking — see §0.1)

- [ ] `app/Http/Controllers/Api/RealtimeTokenController.php` — issues the short-lived,
      room-scoped signed token described in 11 §6's candidate approach (reuses existing
      Policy checks — e.g. a company-room token requires the caller actually belong to that
      company; a warehouse-room token requires a warehouse-staff role).
  **Audit 2026-10-05:** Not started — `services/realtime-gateway/src/server.ts` still joins arbitrary requested rooms; no signer/auth verifier/publisher/listeners/auth tests. Keep tenant-data connections/deployment blocked until authorization is built.
- [ ] `app/Domain/Realtime/RoomTokenSigner.php` — HMAC-signs `{room, exp, sub}`, verified
      independently by the gateway (never re-derives authorisation itself, per 11 §6's own
      constraint that the gateway "has no access to Laravel's session store").
  **Audit 2026-10-05:** Not started — `services/realtime-gateway/src/server.ts` still joins arbitrary requested rooms; no signer/auth verifier/publisher/listeners/auth tests. Keep tenant-data connections/deployment blocked until authorization is built.
- [ ] `services/realtime-gateway/src/auth.ts` — new file: verifies the signed token on the
      `join` event before calling `socket.join(room)`; rejects (and logs) a `join` for a
      room the token's claim doesn't match. This is the fix for the exact gap identified in
      §0.1 — currently `server.ts` line 18-20 joins any room string unconditionally.
  **Audit 2026-10-05:** Not started — `services/realtime-gateway/src/server.ts` still joins arbitrary requested rooms; no signer/auth verifier/publisher/listeners/auth tests. Keep tenant-data connections/deployment blocked until authorization is built.
- [ ] `services/realtime-gateway/src/server.ts` — edit: wire `auth.ts`'s verification into
      the existing `io.on('connection', ...)` handler, replacing the current unguarded
      `socket.on('join', (room) => socket.join(room))`.
  **Audit 2026-10-05:** Not started — `services/realtime-gateway/src/server.ts` still joins arbitrary requested rooms; no signer/auth verifier/publisher/listeners/auth tests. Keep tenant-data connections/deployment blocked until authorization is built.
- [ ] `services/realtime-gateway/test/auth.test.ts` — new test file (no test directory
      currently exists under `services/realtime-gateway/`): a token for `company:A` must not
      be able to join `company:B`; an expired token is rejected; an unsigned/malformed token
      is rejected.
  **Audit 2026-10-05:** Not started — `services/realtime-gateway/src/server.ts` still joins arbitrary requested rooms; no signer/auth verifier/publisher/listeners/auth tests. Keep tenant-data connections/deployment blocked until authorization is built.
- [ ] `app/Domain/Realtime/EventPublisher.php` — the Laravel-side publish contract from 11
      §3 (`DB::afterCommit()`, JSON envelope with `room`/`event`/`payload`, money/quantity
      suffix discipline matching 06 §3). Currently no Laravel code publishes to
      `REDIS_REALTIME_CHANNEL` at all — every `Stock*`/domain event in §4 above needs a
      listener that calls this to actually reach the browser.
  **Audit 2026-10-05:** Not started — `services/realtime-gateway/src/server.ts` still joins arbitrary requested rooms; no signer/auth verifier/publisher/listeners/auth tests. Keep tenant-data connections/deployment blocked until authorization is built.
- [ ] `app/Listeners/PublishStockLevelChanged.php`, `PublishOrderStatusChanged.php`, etc. —
      one listener per event in §4's list that has a real-time UI consumer, dispatched
      after commit.
  **Audit 2026-10-05:** Not started — `services/realtime-gateway/src/server.ts` still joins arbitrary requested rooms; no signer/auth verifier/publisher/listeners/auth tests. Keep tenant-data connections/deployment blocked until authorization is built.
- [ ] Resolve §11 §7's open scaling question (Redis adapter vs. sticky sessions) **only when
      load-testing indicates it matters** — explicitly not blocking per the doc; don't
      build it speculatively.
  **Audit 2026-10-05:** Not started — multi-instance scaling decision remains deferred until an actual deployment target needs it; does not block single-instance token hardening.

---

## 15. Filament Admin — resources not covered above

Sections §5–§12 each list their own module-specific Filament resources inline. This section
covers the base catalogue/pricing/inventory resources for the Phase-1 entities that already
have migrations and models but zero admin UI — `app/Filament/Resources/` is currently
completely empty except for the bare `AdminPanelProvider` with no resources registered.

- [x] `app/Filament/Resources/ProductResource.php` — CRUD per 02 §5.4, cost fields N/A here
      (products don't carry cost; that's `sku_costs`) but `rrp_minor` and completeness-score
      dashboard integration per 01's data-quality risk item.
  **Audit 2026-10-05:** Built — `app/Filament/Resources/ProductResource.php`, `app/Policies/ProductPolicy.php`, `tests/Feature/CatalogueAuthorizationTest.php`.
- [ ] `app/Filament/Resources/SkuResource.php` — CRUD per 02 §5.5, **cost fields
      (`unit_cost_e4` via the current `sku_costs` relation) hidden from any role without
      admin/rep/purchasing permission**, per CLAUDE.md invariant 9 applied to the backoffice
      too — this is not only a customer-facing-API rule.
  **Audit 2026-10-05:** Partly built — `SkuResource.php` and `SkuPolicy.php` implement SKU CRUD with cost-field authorization (`CatalogueAuthorizationTest.php`). Base-price-at-activation enforcement is absent (§3).
- [x] `app/Filament/Resources/PackResource.php` — nested under `SkuResource` as a relation
      manager per 02 §5.6, enforcing "exactly one `is_default_sell`" at the form level
      (the DB partial-unique index is the backstop, not the primary UX).
  **Audit 2026-10-05:** Built — equivalent `app/Filament/Resources/SkuResource/RelationManagers/PacksRelationManager.php`, `PackPolicy.php`, `CatalogueAuthorizationTest.php`.
- [x] `app/Filament/Resources/CategoryResource.php` — tree UI over `category_closure`
      (blocked on §1's closure-table migration + maintainer existing first).
  **Audit 2026-10-05:** Built — `app/Filament/Resources/CategoryResource.php`, `app/Filament/Support/CategoryTree.php`, `CategoryReparenter.php`, `CatalogueAuthorizationTest.php`.
- [x] `app/Filament/Resources/BrandResource.php`.
  **Audit 2026-10-05:** Built — `app/Filament/Resources/BrandResource.php`, `BrandPolicy.php`, `CatalogueAuthorizationTest.php`.
- [ ] `app/Filament/Resources/AttributeResource.php` — with nested `AttributeValueResource`
      relation manager (blocked on §1's attributes migrations).
  **Audit 2026-10-05:** Not started — the named admin resource/widget is absent from `app/Filament/`. Existing models, embedded SKU cost fields and warehouse screens do not supply these dedicated admin capabilities.
- [ ] `app/Filament/Resources/PriceListResource.php`, `PriceListItemResource.php` — the
      break-table editor (03 §2's "adding a tier for one SKU is an INSERT" — the UI should
      make this literally one row-add action), surfacing the `EXCLUDE`-constraint 409
      conflict from 06 §4.1 as a readable form error rather than a raw Postgres exception.
  **Audit 2026-10-05:** Not started — the named admin resource/widget is absent from `app/Filament/`. Existing models, embedded SKU cost fields and warehouse screens do not supply these dedicated admin capabilities.
- [ ] `app/Filament/Resources/PriceTierResource.php`, `TaxClassResource.php`,
      `TaxRateResource.php`, `OrderSpendBreakResource.php`.
  **Audit 2026-10-05:** Not started — the named admin resource/widget is absent from `app/Filament/`. Existing models, embedded SKU cost fields and warehouse screens do not supply these dedicated admin capabilities.
- [ ] `app/Filament/Resources/SkuCostResource.php` — read-heavy, history view per
      `sku_costs_current_idx`.
  **Audit 2026-10-05:** Not started — the named admin resource/widget is absent from `app/Filament/`. Existing models, embedded SKU cost fields and warehouse screens do not supply these dedicated admin capabilities.
- [ ] `app/Filament/Resources/OrderResource.php` — read/action (approve/reject/cancel),
      **cost/margin columns visible only to admin/rep/purchasing roles**, not customers (no
      customer ever reaches Filament, but staff-role granularity still applies per 07 §6.2).
  **Audit 2026-10-05:** Not started — the named admin resource/widget is absent from `app/Filament/`. Existing models, embedded SKU cost fields and warehouse screens do not supply these dedicated admin capabilities.
- [ ] `app/Filament/Resources/StockLevelResource.php` — read-only projection view, with a
      "rebuild from ledger" action gated to a confirmation dialog (04 §9: drift is reported,
      never auto-corrected — the UI must not offer a one-click silent fix).
  **Audit 2026-10-05:** Not started — the named admin resource/widget is absent from `app/Filament/`. Existing models, embedded SKU cost fields and warehouse screens do not supply these dedicated admin capabilities.
- [ ] `app/Filament/Resources/StockMovementResource.php` — **read-only by design** (06 §8's
      `/admin/stock-movements` is `R` only) — do not add create/edit/delete actions to this
      resource; the ledger's append-only invariant should be impossible to violate from the
      admin UI, not just discouraged.
  **Audit 2026-10-05:** Not started — the named admin resource/widget is absent from `app/Filament/`. Existing models, embedded SKU cost fields and warehouse screens do not supply these dedicated admin capabilities.
- [ ] `app/Filament/Resources/BatchResource.php` — recall-reference field prominent, status
      transitions logged.
  **Audit 2026-10-05:** Not started — the named admin resource/widget is absent from `app/Filament/`. Existing models, embedded SKU cost fields and warehouse screens do not supply these dedicated admin capabilities.
- [ ] `app/Filament/Resources/LocationResource.php`, `BinResource.php`.
  **Audit 2026-10-05:** Not started — the named admin resource/widget is absent from `app/Filament/`. Existing models, embedded SKU cost fields and warehouse screens do not supply these dedicated admin capabilities.
- [ ] `app/Filament/Resources/NumberSequenceResource.php` — read-only view over the
      already-built `NumberSequenceService`, for auditing gaplessness.
  **Audit 2026-10-05:** Not started — the named admin resource/widget is absent from `app/Filament/`. Existing models, embedded SKU cost fields and warehouse screens do not supply these dedicated admin capabilities.
- [ ] `app/Filament/Resources/SystemConfigurationResource.php` — the `system_configurations`
      most-specific-wins editor (02 §2.7), with `updated_by_user_id` audit trail surfaced
      inline. This is the UI for every "configurable" value referenced across every module
      doc above (restocking rate/minimum, hold windows, minimum order value, etc.) — build
      this early since §6/§8/§10's services all read from it.
  **Audit 2026-10-05:** Partly built — `app/Filament/Pages/StorefrontSettingsPage.php` and dedicated reorder/terms controls write some configurations with policies/audit. No generic `SystemConfigurationResource.php` with all scopes/types.
- [ ] `app/Filament/Widgets/DataQualityWidget.php` — 01 §10's `completeness_score` dashboard,
      explicitly "flagged, not blocking".
  **Audit 2026-10-05:** Not started — the named admin resource/widget is absent from `app/Filament/`. Existing models, embedded SKU cost fields and warehouse screens do not supply these dedicated admin capabilities.
- [ ] `app/Providers/Filament/AdminPanelProvider.php` — edit: register the resources above
      via `discoverResources` (already points at `app/Filament/Resources`, so resources
      auto-register once created — verify this after the first resource lands rather than
      assuming).
  **Audit 2026-10-05:** Partly built — `app/Providers/Filament/AdminPanelProvider.php` discovers existing resources/pages/widgets. Many listed resources/widgets do not exist; discovery alone does not complete the whole list.
- [ ] `app/Policies/*` for each resource above, gating Filament access per 07 §6.2's
      no-UI-only-authorisation rule.
  **Audit 2026-10-05:** Partly built — Product/SKU/Pack/Category/Brand and other built-resource policies exist (`CatalogueAuthorizationTest.php`); policies for remaining price lists/tiers, stock views, batches, locations and generic configuration resources are absent or incomplete.
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
  **Audit 2026-10-05:** Not started — promotion authoring resource, promotions/rules migrations and live eligibility are absent; resolve category/brand metadata versus live eligibility (02 §14 question 2) before implementation.

---

## 16. `07` — Non-functional requirements

- [x] **Pre-go-live — password hashing is bcrypt, not Argon2id.** Done 2026-09-24:
      `config/hashing.php` sets Argon2id; bcrypt hashes rehash at next sign-in.
  **Audit 2026-10-05:** Built — `config/hashing.php` defaults to Argon2id; `app/Http/Support/SignIn.php` handles rehash; `tests/Feature/Auth/SignInTest.php`. Production HASH_DRIVER override not inspected.
- [ ] **Pre-go-live — populate the offline breached-password list.** Run
      `php artisan auth:refresh-breached-passwords` once on the production host (hours;
      tens of GB under `storage/app/breached-passwords`) and confirm the quarterly schedule
      runs. Until it exists the check fails open with a `critical` log (05.13 §5.4).
  **Audit 2026-10-05:** Not started — `RefreshBreachedPasswords.php` and the quarterly `routes/console.php` schedule exist, but production list population/refresh success cannot be established from this checkout; requires host verification.
- [ ] **Pre-go-live — application compliance prerequisites (02 §25.10).**
      - Register for production HMRC Developer Hub access to "Check a UK VAT number". It
        takes weeks, so apply early.
      - Obtain a Companies House live API key.
      - Put both in the production environment.
      - Set `seller.vat_number`, and optionally `seller.xi_vat_number`.
      - Configure trusted proxies for the load balancer, so terms acceptance records the
        applicant's IP.
      - Have the production terms of trade (and, before checkout, terms of sale) reviewed by
        a solicitor.
      - Publish the first `trade` terms version before opening trade registration.
  **Audit 2026-10-05:** Not started — production credentials, seller details, proxy configuration, legal review and published live terms require operator/client evidence; `.env.example`/local placeholder seeders do not prove completion.
- [ ] **Pre-go-live — upgrade Laravel 11 → 12 (≥ 12.61.1).** Clears the CRLF `email`-rule
      and signed-URL path-confusion advisories (§0.8, 07 §16 Q11). Amend CLAUDE.md's stack
      table first, in its own commit. After the upgrade, `composer audit` is clean and the
      two compensating controls can stay as defence in depth.
  **Audit 2026-10-05:** Not started — `composer.json` / `composer.lock` still select Laravel 11. The recorded advisories are historical; this source audit did not perform a current vulnerability-feed check. Stack amendment/upgrade remain pending.
- [x] `.github/workflows/ci.yml` — core CI exists. The `quality` job runs on every
      PR: `composer lint` (Pint + PHPStan), JavaScript tests and app build, realtime
      gateway build, isolated PostgreSQL migrations and PHP tests.
  **Audit correction 2026-10-05:** The previous audit missed the hidden `.github` directory.
  Workflow source proves configuration, not a successful hosted run or required branch
  protection. **Partly built:** axe, Lighthouse/baseline regression and the complete
  07 §14 gate matrix remain outstanding; there is no separate `composer analyse` step,
  but static analysis is included in `composer lint`.
- [ ] `tests/Feature/Performance/BaselineRegressionTest.php` — 07 §2.5's "CI fails on 20%
      regression against recorded baseline" mechanism; extend the existing
      `HotPathExplainTest.php` pattern with stored baseline timings, not just plan-shape
      assertions.
  **Audit 2026-10-05:** Not started — no baseline-regression or axe/browser accessibility runner/tests; `HotPathExplainTest.php` checks plans, not CI timing regressions or accessibility.
- [ ] `app/Console/Commands/RunAccessibilityAudit.php` or an `npm` script wiring axe-core
      into CI (07 §8) — no accessibility tooling exists yet at all.
  **Audit 2026-10-05:** Not started — no baseline-regression or axe/browser accessibility runner/tests; `HotPathExplainTest.php` checks plans, not CI timing regressions or accessibility.
- [ ] `config/logging.php` — edit: add the redaction channel/processor from 07 §9.1
      (passwords, card data, session tokens, full addresses, API secrets never logged),
      plus `tests/Unit/LogRedactionTest.php` asserting it.
  **Audit 2026-10-05:** Not started — `config/logging.php` has ordinary processors only; no sensitive-field redaction processor or `LogRedactionTest.php`.
- [ ] `docs/09-test-strategy.md` — currently doesn't exist (doc 01 §2 lists it "Pending").
      **Written last, deliberately** — it documents the coverage model, concurrency/property
      testing approach and CI gates as the suite is actually built across every section of
      this roadmap, rather than prescribing a test strategy up front that drifts from what's
      really there. Do not write this until the rest of the roadmap is substantially done.
  **Audit 2026-10-05:** Not started — `docs/09-test-strategy.md` is absent; remains deliberately late, but independent-connection test harness/CI need earlier delivery.
- [ ] `app/Domain/Compliance/AnonymisationService.php` — 07 §7.3's erasure-vs-retention
      workflow: tombstones `users.email`/name/phone, retains `orders`/`order_lines`/
      `invoices`/`order_addresses`, redacts `customer_activities` free text. Depends on
      `invoices` existing (§0.2 doc gap).
  **Audit 2026-10-05:** Not started — no anonymisation, self-service portability export or general retention enforcement. Notification log pruning is only one retention category, not the full NFR table.
- [ ] `app/Http/Controllers/Api/DataExportController.php` — 07 §7.4's self-service
      access/portability export (JSON + PDF, 30-day SLA).
  **Audit 2026-10-05:** Not started — no anonymisation, self-service portability export or general retention enforcement. Notification log pruning is only one retention category, not the full NFR table.
- [ ] `app/Console/Commands/EnforceRetentionPolicy.php` — 07 §7.2's per-data-type retention
      table as scheduled jobs (7-year financial records, 3-year quotes, 90-day session
      logs, etc.).
  **Audit 2026-10-05:** Not started — no anonymisation, self-service portability export or general retention enforcement. Notification log pruning is only one retention category, not the full NFR table.
- [ ] Backup/PITR configuration — 07 §4: `pgBackRest` config, WAL archiving to off-server
      object storage, quarterly restore-test runbook. Infrastructure-as-code, not
      application code; track as a deployment task rather than a repo file, but the
      **quarterly restore test result log** should live somewhere reviewable — e.g.
      `docs/ops/restore-test-log.md` (new, outside the schema-governed doc set, so no
      sign-off gate applies).
  **Audit 2026-10-05:** Not started — no pgBackRest/WAL/restore-log configuration in the tracked checkout; production backup/PITR and restore evidence require deployment work.
- [x] `app/Http/Middleware/EnforceTwoFactorForStaff.php` — 07 §6.1's mandatory-2FA-for-
      staff-roles rule; Laravel Fortify/Sanctum 2FA is not yet configured anywhere in
      `config/auth.php`.
  **Audit 2026-10-05:** Built — equivalent `app/Http/Middleware/RequireStaffTwoFactor.php` plus auth/Filament middleware wiring; `tests/Feature/Auth/TwoFactorEnrolmentTest.php`, `FilamentPanelAccessTest.php`.
- [x] Stripe/payment-gateway integration — 07 §6.4 Stripe tokenization, gateway
      references and customer-safe decline handling; card entry through browser Elements only.
  **Audit 2026-10-05:** Built — equivalent `app/Domain/Billing/{PaymentGateway,StripeGateway,CardPayments}.php`, Stripe Elements in `resources/js/pages/Checkout/Index.tsx`; `tests/Feature/Billing/CardPaymentTest.php` with a fake gateway. Production Stripe validation is separate.
- [ ] `app/Domain/Documents/PdfGenerationClient.php` — the Node/Puppeteer PDF worker
      referenced throughout (01 §5.1, 05.3 §11, 06 §8) doesn't exist as a service at all —
      needs its own `services/pdf-worker/` scaffold (Fastify + Puppeteer, similar shape to
      `services/realtime-gateway/`) plus this Laravel-side client, queued per P25's 3s
      budget.
  **Audit 2026-10-05:** Partly built — `app/Domain/Documents/PdfRenderer.php`, `app/Jobs/ArchiveInvoicePdf.php` and `InvoicePdfArchiver.php` provide a queued seam. `AppServiceProvider.php` binds `NullPdfRenderer.php` (returns null); no real PDF worker or archived-production-PDF proof.
- [ ] `app/Domain/Accounting/XeroSyncService.php`, `SageExportService.php` — native Xero
      sync/Sage CSV per 07 §12. Write 05.9 mappings/reconciliation before services or
      `xero_sync_records` migration; existing financial tables are available dependencies.
  **Audit 2026-10-05:** Not started — `docs/05.9-xero-integration.md` and accounting services/`xero_sync_records` migration are absent. Invoices/payments/credit notes exist now, so obsolete financial-table doc gaps are not blockers; mappings/idempotency/reconciliation spec still is.
- [ ] Resolve 07 §16 Q9 (dedicated Xero module spec 05.9) and its mapping decisions.
      Registering a future spec is not writing it.
  **Audit 2026-10-05:** Partly built — `01-solution-overview.md` registers a dedicated 05.9 doc, so ownership is settled. The doc itself and sync mapping decisions are still unwritten.

---

## 17. Frontend shell / cross-cutting React work

Not owned by any single module but required before most `resources/js/pages/*` tasks above
can run in a real browser.

- [ ] `resources/js/lib/api/client.ts` — typed API client generated from (or hand-aligned to,
      until §13's OpenAPI generator exists) the 06 resource catalogue; TanStack Query hooks
      per resource.
  **Audit 2026-10-05:** Partly built — `resources/js/lib/api/client.ts` and resource-specific typed clients/hooks exist (`orderPad.ts`, `checkout.ts`, warehouse clients). No OpenAPI generation or complete 06 resource catalogue yet.
- [x] `resources/js/lib/money.ts` — the client-side mirror of `Money`/`roundHalfUpDiv`
      (§2), shared by every page that recomputes totals locally (05.1's order pad being the
      hot path, but quotes/RMA screens need it too).
  **Audit 2026-10-05:** Built — `resources/js/lib/money.ts` and `.test.ts`, plus `LocalRecomputeParityTest.php`.
- [ ] `resources/js/components/ui/StockBadge.tsx` — 05.1 §4.3 / 07 §8's "never colour alone"
      rule as one shared component, so every stock display across order pad, warehouse and
      admin screens gets the numeral+label treatment consistently rather than each page
      reimplementing it (and risking the WCAG violation the NFR doc calls "the most likely
      violation in a system with green/amber/red stock").
  **Audit 2026-10-05:** Partly built — stock labels/numerals exist in `OrderPad/components/rowParts.tsx` and `lib/orderPad/display.ts`; no shared `StockBadge.tsx` covering warehouse/admin or browser accessibility verification.
- [ ] `resources/js/stores/cartStore.ts` (Zustand) — client-side cart mirror synced against
      server `carts`/`cart_lines` (blocked on §0.2's doc gap).
  **Audit 2026-10-05:** Partly built — `resources/js/stores/orderPadStore.ts` keeps ephemeral pad selection; server cart is fetched via TanStack Query. No named synchronized Zustand cart mirror (avoid duplicating authoritative server state just to satisfy a filename).
- [x] `resources/js/app.tsx` — edit: replace the placeholder single-page wiring with real
      route-based code-splitting once more than one page exists (currently only
      `Dashboard.tsx`).
  **Audit 2026-10-05:** Built — `resources/js/app.tsx` uses lazy `import.meta.glob` page resolution; many real React pages are registered.
- [x] `resources/js/pages/Dashboard.tsx` — edit: replace the placeholder with the real
      authenticated landing page, or delete it in favour of `OrderPad/Index.tsx` (§5) being
      the landing route — decide which once §5 exists rather than maintaining two.
  **Audit 2026-10-05:** Built — `resources/js/pages/Dashboard.tsx` is removed; `app/Http/Support/SignIn.php` selects the trade pad, staff Filament dashboard and public storefront; `tests/Feature/Auth/SignInTest.php`.

---

## 18. Audit Log (§0.6, blocking — added 2026-09-20 completeness audit)

`07-nfr.md` §6.5 mandates an immutable, append-only, 7-year-retained audit log. The
schema gap was closed in 02 §15 and the storage/logger foundation merged in PR #12;
failed-sign-in events and the read-only admin viewer are built. Other event callers and
retention operations still need delivery.

- [x] **02 §15 signed off and merged in PR #11** (partitioned, DB-trigger append-only,
      admin-only read; decisions in §15.3).
  **Audit 2026-10-05:** Built — `docs/02-domain-model-erd.md` §15 contains signed-off audit DDL; implemented by `2026_10_12_090100_create_audit_log_table.php`.
- [x] Propose `docs/02-domain-model-erd.md` §15 amendment — `audit_log`: partitioned by
      `occurred_at` (mirrors `stock_movements`, 02 §7.4), append-only, composite
      `(id, occurred_at)` PK, BRIN on `occurred_at`, `actor_user_id`, `subject_type`/
      `subject_id`, `action`, `before`/`after` `jsonb`. **Draft and sign off in its own commit
      before any migration** — do not invent this schema while building the logger. [G1]
  **Audit 2026-10-05:** Built — `docs/02-domain-model-erd.md` §15 contains signed-off audit DDL; implemented by `2026_10_12_090100_create_audit_log_table.php`.
- [x] `database/migrations/2026_10_12_090100_create_audit_log_table.php` — PR #12.
  **Audit 2026-10-05:** Built — `database/migrations/2026_10_12_090100_create_audit_log_table.php`. Source present; tests were inspected, not run.
- [x] `app/Domain/Audit/AuditLogger.php` — the single write path for defined actions,
      with action-specific payload validation. Privileged-action callers remain to be wired
      as those workflows are built.
  **Audit 2026-10-05:** Built — `app/Domain/Audit/AuditLogger.php`. Source present; tests were inspected, not run.
- [x] `tests/Feature/Domain/AuditLoggerTest.php` — direct database assertions that
      `UPDATE`, `DELETE` and `TRUNCATE` fail on the parent and named partitions.
  **Audit 2026-10-05:** Built — `tests/Feature/Domain/AuditLoggerTest.php`. Source present; tests were inspected, not run.
- [x] Wire `auth.sign_in_failed` from password and second-factor failures with a dedicated
      configured HMAC key; do not record raw identifiers. Other 05.13 §15 events follow.
  **Audit 2026-10-05:** Built — `app/Http/Support/SignIn.php` and `Auth/TwoFactorChallengeController.php`; `tests/Feature/Auth/SecurityAuditEventsTest.php`.
- [x] Admin-only read-only Filament viewer with family, actor, company, subject and date filters.
      Subjects show a readable name — email, company, application, terms version (2026-09-28).
  **Audit 2026-10-05:** Built — `app/Filament/Resources/AuditLogResource.php`, `tests/Feature/Admin/AuditLogViewerTest.php`.
- [x] Every staff (`actor_type = user`) entry records the client IP and user agent from the
      request (`AuditContext`, a scoped binding; honours trusted proxies; empty in console
      and queue runs). 2026-09-28, pending verification.
  **Audit 2026-10-05:** Built — `app/Domain/Audit/AuditContext.php`, `tests/Feature/Admin/AuditContextTest.php`, `AdminScreenFixesTest.php`; request-scoped staff context, not console IPs.
- [x] 05.13 §15 own-account events: sign-in success (with method), sign-out, session expiry
      (idle / absolute / account inactive), lockout, password reset requested and completed,
      2FA enabled and disabled, recovery codes regenerated, recovery code used. 05.13 §15
      implementation note. No migration needed. 2026-09-28, pending verification.
  **Audit 2026-10-05:** Built — `app/Domain/Audit/AuditLogger.php::ownAuthEvent()`, auth controllers and `EnforceSessionPolicy.php`, `tests/Feature/Auth/SecurityAuditEventsTest.php`; excludes parked own-account changes/closure.
- [ ] Yearly partition creation, default-partition monitoring and seven-year retention job.
  **Audit 2026-10-05:** Not started — no audit partition rollover/default monitoring/seven-year retention command or schedule in `routes/console.php`.

---

## 19. Inventory Transfers (added 2026-09-20 completeness audit)

`stock_movements` already accepts `transfer_in`/`transfer_out` (04 §3: "a transfer is two
movements in one transaction, never one movement with two locations"), but nothing groups
the pair. Latent at single-location launch; blocks the moment a second `locations` row is
real.

- [ ] Propose `docs/02-domain-model-erd.md` §16 amendment — `transfers`, `transfer_lines`.
      Draft and sign off before migrating, same discipline as §18. [G2]
  **Audit 2026-10-05:** Not started — 02 §16 transfer amendment is still reserved/unwritten; no transfer migration/service/UI/test. Existing movement types alone do not group/authorize paired transfers.
- [ ] Once signed off: migration.
  **Audit 2026-10-05:** Not started — 02 §16 transfer amendment is still reserved/unwritten; no transfer migration/service/UI/test. Existing movement types alone do not group/authorize paired transfers.
- [ ] `app/Domain/Inventory/TransferService.php` — locks source and destination
      `stock_levels` rows in the same ascending `(sku_id, location_id, batch_id NULLS FIRST)`
      order as `AllocationService`/`DeallocationService` (CLAUDE.md invariant 6) — a transfer
      touching the same two locations as a concurrent allocation must not be able to deadlock
      against it, which means it has to share the one global lock order, not invent its own.
  **Audit 2026-10-05:** Not started — 02 §16 transfer amendment is still reserved/unwritten; no transfer migration/service/UI/test. Existing movement types alone do not group/authorize paired transfers.
- [ ] `tests/Feature/Domain/TransferServiceTest.php` — including a lock-order test in the
      same style as `AllocationServiceTest.php`'s query-log assertion.
  **Audit 2026-10-05:** Not started — 02 §16 transfer amendment is still reserved/unwritten; no transfer migration/service/UI/test. Existing movement types alone do not group/authorize paired transfers.
- [ ] `app/Filament/Resources/TransferResource.php` — warehouse-facing, alongside §15.
  **Audit 2026-10-05:** Not started — 02 §16 transfer amendment is still reserved/unwritten; no transfer migration/service/UI/test. Existing movement types alone do not group/authorize paired transfers.

---

## 20. Order Amendment & Cancellation — `docs/05.10-order-amendment.md` (added 2026-09-20)

`docs/05.10-order-amendment.md` §2 specifies partial cancellation only. Core trade
quantity cancellation exists, but account-credit ledger integration and dedicated hold/refund test assertions are absent; consumer
whole-order cancellation is built. Amendment increases/address changes, approvals and fees
still need the rest of the spec. Do not treat the B2C whole-cancellation path as trade support.

- [ ] Write `docs/05.10-order-amendment.md` — covers amendment-after-hold (re-run the credit
      check `AllocationService` already does, against the amended total), cancellation fee
      computation, and the exact RMA→cancellation boundary 05.4 §9 gestures at. **Spec
      precedes code — this is a task, not a design to invent here.** [G3]
  **Audit 2026-10-05:** Partly built — `docs/05.10-order-amendment.md` §2 is signed off for partial cancellation. §1 explicitly leaves increases/address changes, credit re-checks, amendment approvals and cancellation fees unwritten.
- [ ] Once signed off: `app/Domain/Orders/OrderAmendmentService.php` — re-resolves changed
      lines through `PriceResolver`/`OrderLinePricer`, re-checks credit via the same
      lock-first-cheapest-check pattern `AllocationService::lockCompanyCredit()` already uses.
  **Audit 2026-10-05:** Not started — no order-increase/address amendment service; `PartialCancellations.php` only reduces quantities. Full 05.10 amendment/credit re-check spec must be signed off first.
- [ ] `app/Domain/Orders/OrderCancellationService.php` — calls the already-built
      `DeallocationService` for stock release; computes `cancellation_fee_minor` per the new
      spec.
  **Audit 2026-10-05:** Partly built — equivalent `app/Domain/Ordering/PartialCancellations.php` supports trade quantity cancellation/hold reduction; `OrderCancellationService.php` supports consumer whole cancellation and refuses trade. Trade whole-order lifecycle and fees remain unbuilt; see missing 05.10 §1 spec and account-credit ledger (§8).
- [ ] Controllers/Form Requests for amend/cancel endpoints — needs a `06-api-contract.md` row
      once the spec exists; none is proposed here.
  **Audit 2026-10-05:** Partly built — `PartialCancellationRequest.php`, `OrderConfirmationController.php` and `Staff/OrderCancellationPageController.php` expose quantity cancellation with `OrderPolicy`; amendment endpoints and full trade whole-order cancellation outside the implemented quantity-cancellation path are absent.
- [ ] `tests/Feature/Domain/OrderAmendmentServiceTest.php`,
      `tests/Feature/Domain/OrderCancellationServiceTest.php` — the cancellation test reuses
      `DeallocationServiceTest.php`'s fixtures where possible rather than duplicating them.
  **Audit 2026-10-05:** Partly built — `tests/Feature/Storefront/PartialCancellationTest.php` includes trade break refusal/override, cumulative rounding and retry/access paths; hold/refund implementation in `PartialCancellations.php` lacks dedicated assertions in this test; `OrderCancellationTest.php` is consumer-only. No amendment or full trade cancellation suite, nor independent parallel race proof.

---

## 21. CMS & SEO — `docs/05.11-cms-seo.md` (added 2026-09-20)

Signed-off 05.11 legal/help pages and SEO are built and shared by the storefront.
Arbitrary CMS pages, banners and migration redirects are explicitly outside that signed-off slice;
redirects depend on the reference import URL mapping.

- [ ] Write `docs/05.11-cms-seo.md` — pages, banners, redirects, structured data, sitemap
      generation. Scope and table shape are this doc's job, not this roadmap entry's. [G4]
  **Audit 2026-10-05:** Partly built — `docs/05.11-cms-seo.md` is signed off for legal/help/SEO. Its §1 explicitly defers arbitrary pages, banners and redirects; that broader original spec item is incomplete.
- [ ] Once signed off: migrations for whatever entities the spec settles on.
  **Audit 2026-10-05:** Partly built — `database/migrations/2026_10_20_090100_create_cms_pages_tables.php` implements legal/help content/version storage; banners/redirects await their later spec and import URL mapping.
- [ ] Filament resources + public React pages, once schema exists.
  **Audit 2026-10-05:** Partly built — `CmsPageResource.php`, `LegalPageController.php`, `resources/js/pages/Storefront/LegalPage.tsx`, `tests/Feature/Storefront/CmsSeoTest.php` cover the signed-off legal/SEO slice. Broader CMS/banner/redirect tooling is absent.

---

## 22. Notification Infrastructure — `docs/05.12-notifications.md` (added 2026-09-20)

Signed-off notification infrastructure, email dispatch/logging/preferences/suppression
and built-domain callers exist. Back-in-stock subscriptions/stock-received consumers do not.

- [x] Write `docs/05.12-notifications.md` — templates, channels, per-user/company
      preferences, delivery tracking. [G5] Signed off 2026-09-25.
  **Audit 2026-10-05:** Built — `docs/05.12-notifications.md` signed-off spec, implemented foundation in §22 below.
- [x] `notification_preferences`, `notification_log` migrations (02 §22).
  **Audit 2026-10-05:** Built — `database/migrations/2026_10_07_090100_create_notification_tables.php` creates preferences, log and suppressions.
- [x] `app/Domain/Notifications/NotificationDispatcher.php` — the single dispatch path every
      other domain service calls rather than each sending its own mail/SMS ad hoc.
  **Audit 2026-10-05:** Built — `app/Domain/Notifications/NotificationDispatcher.php`, `app/Jobs/SendNotification.php`, `tests/Feature/Notifications/NotificationDispatchTest.php`; built auth/application/invoice/order/shipment callers use `Notifications.php`. Not proof of notices for unbuilt modules.
- [ ] Wire `back_in_stock_subscriptions` (02 §14.10, DRAFT) as the first real consumer — it
      already exists specifically to be notified and currently has nothing to call.
  **Audit 2026-10-05:** Not started — no `back_in_stock_subscriptions` migration/model/subscribe flow or stock-received listener. Notification infrastructure exists; this consumer does not.

---

## 23. Auth & Onboarding — `docs/05.13-auth-onboarding.md` (added 2026-09-20)

`07-nfr.md` §6.1 specifies authentication **policy** (Argon2id, 2FA, session timeouts) but
not the B2B-specific **flows**: guest-cart merge at login, whether an unapproved
`b2b_applications` applicant may log in at all, invited-user onboarding.

- [x] Write `docs/05.13-auth-onboarding.md` — the three flows above, at minimum. [G6]
      Draft for review 2026-09-24; eight decisions resolved the same day (05.13 §19).
  **Audit 2026-10-05:** Built — `docs/05.13-auth-onboarding.md` §19 resolved decisions; implemented slices below. This is documentary evidence, not a test result.
- [x] Sign off `docs/02-domain-model-erd.md` §17 — `company_invitations`,
      `user_two_factor_recovery_codes` — and migrate them. Done 2026-09-24.
  **Audit 2026-10-05:** Built — `database/migrations/2026_10_02_090100_create_company_invitations_table.php`, `090200_create_user_two_factor_recovery_codes_table.php`, `CompanyInvitationsTest.php`, `TwoFactorEnrolmentTest.php`.
- [x] Implement 05.13: Sanctum, registration, sign-in/out, lockout, reset, verification,
      2FA with recovery codes, company choice (guest-cart merge moved after it), session
      limits. Done 2026-09-24 — see 05.13 §20.
  **Audit 2026-10-05:** Built — `app/Domain/Identity/`, `app/Http/Controllers/Auth/`, `tests/Feature/Auth/{RegistrationAndRecovery,SignIn,TwoFactorEnrolment,CompanyChoiceAndSession}Test.php`; auth/session/guest-merge slice only.
- [x] Resolve 05.13 §19 Q15/Q17/Q18. Done 2026-09-24.
  **Audit 2026-10-05:** Built — `docs/05.13-auth-onboarding.md` §19 resolved decisions; implemented slices below. This is documentary evidence, not a test result.
- [x] Admin-only staff directory and pending-account creation, including admin-to-admin
      creation, explicit initial role grants with grantor, password-setup email and resend,
      audit events and access tests. No migration needed; uses existing `users`, `role_user`,
      password broker, notification and audit tables. 2026-09-27, pending verification.
  **Audit 2026-10-05:** Built — `app/Domain/Identity/StaffOnboardingService.php`, `app/Filament/Resources/StaffUserResource.php`, `tests/Feature/Admin/StaffOnboardingTest.php`.
- [x] Admin-only grant/revoke of existing staff roles from the Staff detail page
      (`StaffRoleService`, `UserPolicy::manageStaffRoles`): grantor recorded, every change
      audited (`permission.staff_role_granted` / `permission.staff_role_revoked`), no
      self-changes, last active admin and final role protected, changes serialised on the
      `admin` role row. No migration needed. 2026-09-27, verified (PR #16).
  **Audit 2026-10-05:** Built — `app/Domain/Identity/StaffRoleService.php`, `tests/Feature/Admin/StaffRoleManagementTest.php`.
- [x] Admin-only staff suspension and reinstatement from the Staff detail page
      (`StaffSuspensionService`, `UserPolicy::suspendStaff`/`reinstateStaff`): sessions and
      reset tokens removed on suspension, roles kept, audited (`auth.staff_suspended` /
      `auth.staff_reinstated`), no self-suspension, last active admin protected, same
      lock order as role changes. No migration needed. 2026-09-27, verified (PR #17).
  **Audit 2026-10-05:** Built — `app/Domain/Identity/StaffSuspensionService.php`, `tests/Feature/Admin/StaffSuspensionTest.php`.
- [x] Admin-only staff 2FA reset from the Staff detail page (05.13 §12.3,
      `StaffTwoFactorResetService`, `UserPolicy::resetStaffTwoFactor`): active staff with 2FA
      enabled only, never self; secret cleared, 2FA disabled, recovery codes and sessions
      deleted so the next sign-in re-enrols; audited (`auth.staff_two_factor_reset`, before/after
      `two_factor_enabled` only) and the `auth.two_factor_changed` notice sent after commit;
      same lock order as role changes. No migration needed. 2026-09-27, pending verification.
  **Audit 2026-10-05:** Built — `app/Domain/Identity/StaffTwoFactorResetService.php`, `tests/Feature/Admin/StaffTwoFactorResetTest.php`.
- [x] Admin-only customer user suspension and reinstatement (05.13 §4.2,
      `CustomerSuspensionService`, `UserPolicy::suspendCustomer`/`reinstateCustomer`) from a
      read-only Customer users resource listing users with no `role_user` row and their company
      memberships. Sessions and reset tokens removed on suspension; audited
      (`auth.customer_suspended` / `auth.customer_reinstated`, `status` only); the last active
      owner of an approved or suspended company cannot be suspended (05.13 §9 ⚑5, decided
      2026-09-27; applied/rejected/closed exempt), serialised on the `companies` rows; company status and credit untouched. No migration needed. 2026-09-27, pending
      verification.
  **Audit 2026-10-05:** Built — `app/Domain/Identity/CustomerSuspensionService.php`, `tests/Feature/Admin/CustomerSuspensionTest.php`.
- [x] Company invitations (invite, resend, revoke, accept for new and existing accounts) and
      company member management (role, order limit in pounds, approval flag, remove) for owners
      on the storefront and admins in Filament; ⚑5 enforced under the company row lock; all
      audited. 05.13 §9.3 implementation note. No migration needed. 2026-09-28, pending verification.
  **Audit 2026-10-05:** Built — `app/Domain/Identity/{CompanyInvitationService,CompanyMemberService}.php`, `resources/js/pages/Account/Team.tsx`, `tests/Feature/Company/{CompanyInvitations,CompanyMembers}Test.php`.
- [x] 05.13 §5.1 additions: legal form and terms of trade acceptance (slice B1,
      2026-09-28, pending verification). Verification after commit is slice B2 — ROADMAP §6,
      "Application compliance".
  **Audit 2026-10-05:** Built — `RegisterTradeRequest.php`, `app/Domain/Identity/Registration.php`, `tests/Feature/Auth/TradeRegistrationComplianceTest.php`.
- [ ] 05.13 §20 "not built yet": applicant status page and signed-in
      application form, `application_pending`/unverified-email checkout blockers, admin 2FA
      reset for trade users (staff reset done; blocked on ⚑9). §15 audit events are done except
      for flows not built yet (password change, email change, account closure).
      Customer suspension is done.
  **Audit 2026-10-05:** Partly built — `CheckoutService.php::assertBuyerCanCheckout()` and `CheckoutPreviewService.php::identityBlockers()` now reject pending applications/unverified public accounts (`CheckoutApiTest.php`, `CheckoutPreviewApiTest.php`). Applicant status/signed-in application and trade-user admin 2FA reset remain absent; ⚑9 identity verification decision is open.
- [ ] **Parked 2026-09-28 — user management, resume after the storefront.** Deliberately
      deferred, not forgotten:
      1. Applicant status page and "information requested" reply form (05.13 §7, 05.2 §5.5).
      2. Change email address, with notices to the old and new address (05.13 §15).
      3. Password change while signed in (05.13 §15).
      4. Admin 2FA reset for trade users — needs the ⚑9 identity-check decision
         (recommended: call-back to the company phone on file, or confirmation by another owner).
      5. Follow-ups: staff-entered applications, inviting into a chosen company when an owner
         has several, a "my companies" list, closing a user account (§4.2).
  **Audit 2026-10-05:** Not started — parked applicant reply/email change/signed-in password change/trade-user 2FA reset/closure flows remain absent; trade reset needs ⚑9 decision. No new implementation inferred from staff-only controls.
- [x] Reconciles with `carts.company_id`/`carts.user_id` both being nullable (02 §14.3,
      DRAFT) — guest-cart merge is exactly the transition that resolves those columns from
      NULL, so this flow and that table's design are the same piece of work.
  **Audit 2026-10-05:** Built — `app/Http/Support/GuestCartMerge.php` and `CartService.php::mergeGuestCart()` merge on sign-in or after company choice; `CartServiceTest.php`, `CompanyChoiceAndSessionTest.php`. Nullable owner fields are handled.

---

## 23A. Public Storefront (B2C) — `docs/05.15-public-storefront.md` (added 2026-09-28)

B2C first, then back to trade (decided 2026-09-28). Decisions: guest checkout yes; public
prices inc VAT with an Ex VAT switch. Slices S1–S7 in 05.15 §11.

- [x] 05.15 signed off 2026-09-28.
- [x] Amend 02 with 05.15 §9 A1–A3 (and the §19/§21.2 guest-customer note), and 05.6 with A4, each in its own signed-off commit, before S4/S5. 02 §26 and 05.6 §7.1, signed off 2026-10-04.
- [x] S1 demo seeder (branding, images, featured, stock variety, trade and public sign-ins) and
      S2 shell, branding settings, price display switch, home page, error pages. 2026-09-28,
      pending verification.
- [x] S3 category (`/c/{slug}`), search (`/search`) and product (`/p/{slug}`) pages, add to
      basket, home page redesign (hero mosaic, department tiles), basket in the storefront shell,
      "continue shopping" to the storefront for the public. 2026-09-28, pending verification.
- [x] Stock labels (no figures) on the order pad for guests, public customers and applicants
      (06 §9.4); public sign-in and every sign-out land on the storefront home. 2026-09-28,
      pending verification.
- [x] Marketplace stock disclosure ("Only N left" at 10 or fewer; shortage messages without a
      figure above 10) and shopping aids: quick add, search suggestions, recently viewed, sticky
      mobile add-to-basket (05.15 §5.3, §5.3a). 2026-09-28, pending verification.
- [x] S4: migrations A2/A3 (`2026_10_15_090100`, `090200`); terms of sale accepted at checkout and recorded per order;
      `standard_shipping_net_minor` snapshotted on public orders; GB-only rule (422 `country_not_served`);
      pre-contract information and return-cost statement on checkout and in full in the confirmation email,
      with the model cancellation form; "order with obligation to pay" button; cart copy for the public;
      placeholder `sale` terms locally. 2026-10-04, pending verification.
- [x] S5a guest checkout (delivery only), order page by signed link, find my order, claim on verification (A1,
      `2026_10_16_090100`). 2026-10-04, pending verification.
- [x] S5b shared collection foundation — collection slots, checkout booking and counter handover
      are built under 05.6 §7A; remaining follow-ups are listed below.
  **Audit 2026-10-05:** Built — shared collection capability now exists: `CheckoutService.php`, `app/Domain/Collection/CollectionSlots.php`, `ClickAndCollectTest.php`. Delivery-only wording below is superseded.
- [x] S6 spec: 05.4 §13 (consumers), 05.15 §9 A5–A8, 02 §14.5.3 (A8), 05.12 keys `order.cancelled`,
      `rma.refund_due_soon`, `refund.failed`. Signed off 2026-10-04. Q13 (pallet return cost, A9) open.
- [x] S6a cancel before dispatch (05.4 §13.2; A8, `2026_10_17_090100`). 2026-10-04, pending verification.
- [x] A9 `orders.return_cost_estimate_gross_minor` (02 §27), signed off 2026-10-04 (Q13).
- [x] S6b cancellation request after dispatch (05.4 §13.3; A6, A7, A9): `2026_10_18_090100` (A9),
      `2026_10_18_090200` (`rmas`, `rma_lines`, `credit_notes.rma_id` FK, `rma_number` and `credit_note_number`
      series). 2026-10-04, pending verification.
- [x] S6c sending back and the refund deadline (05.4 §13.5, as amended 2026-10-04: deadline from the proof upload;
      only returns with neither proof nor receipt are swept). Staff Returns screen, `returns:refund-due-alerts`,
      `returns:sweep-not-received`. No migration. 2026-10-04, pending verification.
- [x] S6d inspection and refund (05.4 §13.5–13.6). No migration. Merged in PR #34; full test suite passed.
- [x] S6e faulty goods and staff entry (05.4 §13.4, §13.3), including refund deadlines, customer remedy choices and UK notification timestamps. No migration. Merged in PR #34; full test suite passed.
      Not built: the replacement order itself (05.4 §7.5, via 04 §4.2) — a replacement is recorded, not shipped.
**Remaining B2C delivery order (user decision, 2026-10-04):**

1. [x] **My account for public customers:** all my orders in one list, keyset-paginated newest first; open any order using the existing order page with cancel/return/report-a-problem actions; my receipts; saved delivery addresses (add, edit, delete, default) used at checkout. Guest orders claimed on verification appear here. Specs 05.15 §6.4 and 02 §28 signed off; implementation and migration `2026_10_19_090100` built, pending user test verification.
2. [ ] **S7:** write and approve `05.11` first, then legal pages (terms, privacy, returns, cookies), cookie banner (PECR), sitemap and JSON-LD. Required legal work is a pre-go-live requirement; S7 is not deferred to deployment.
3. [ ] **Replacement orders:** create and ship replacements for faulty goods (05.4 §7.5, via 04 §4.2).
4. [ ] **S5b:** click & collect for public customers, with collection slots and the collection workflow (05.6 §7).

**Specs signed off 2026-10-05, built in this order (one commit per slice):** S7 (05.11); replacement
orders (05.4 §14); price sorting (02 §29); partial cancellation before dispatch (05.10 §2);
click & collect with pay at collection (05.6 §7A, 05.15 §6.1). Schema index: 02 §30.

- [x] S7 — 05.11: legal pages, cookies, head tags, JSON-LD, sitemap, robots.
  **Audit 2026-10-05:** Built — shared legal/SEO slice in `CmsSeoTest.php`, `SeoController.php`, `SeoHead.php`, `Sitemap.php`, `StructuredData.php`. 05.11 §2.5 requires no consent banner while cookies are necessary only.
- [x] Replacement orders — 05.4 §14 (no invoice pending the accountant, Q-R3). Migration `2026_10_21_090100`. 2026-10-05, pending verification.
- [x] Price sorting — 02 §29 (guests and public customers only). Migration `2026_10_22_090100`; run `storefront:refresh-price-projection` once after migrating. 2026-10-05, pending verification.
- [ ] Partial cancellation before dispatch — 05.10 §2.
  **Audit 2026-10-05:** Partly built — `app/Domain/Ordering/PartialCancellations.php`, `PartialCancellationTest.php` build consumer and core trade paths; trade account-credit movements required by Q-X2 and complete concurrency/put-away coverage are absent.
- [x] S5b click & collect and pay at collection — 05.6 §7A. Migration `2026_10_24_090100`. Built 2026-10-05, pending verification.
  Follow-ups, not built in S5b:
  **Audit 2026-10-05:** Built — `2026_10_24_090100_add_collection_and_cash_payment.php`, `app/Domain/Collection/`, `ClickAndCollectTest.php` cover booking, cash, expiry and handover. Listed reschedule/reminder/concurrency follow-ups remain unchecked.
  - [ ] Customer reschedule, and staff moving bookings off a closed slot (05.6 §7A.7, build step S5b-6). Closing a slot today leaves its bookings on it.
    **Audit 2026-10-05:** Not started — shared collection rescheduling/moving closed-slot bookings, reminder/reschedule notices and independent parallel-booking proof remain absent; see `CollectionSlots.php::close()` and `ClickAndCollectTest.php`.
  - [ ] Notifications `collection.rescheduled` and `collection.reminder` (05.6 §7A.10, Q-C5).
    **Audit 2026-10-05:** Not started — shared collection rescheduling/moving closed-slot bookings, reminder/reschedule notices and independent parallel-booking proof remain absent; see `CollectionSlots.php::close()` and `ClickAndCollectTest.php`.
  - [ ] Parallel-booking concurrency test: 20 parallel bookings on a capacity-3 slot, exactly 3 succeed (05.6 §7A.16, D1). Needs separate connections, so it is not a single-connection Pest test.
    **Audit 2026-10-05:** Not started — shared collection rescheduling/moving closed-slot bookings, reminder/reschedule notices and independent parallel-booking proof remain absent; see `CollectionSlots.php::close()` and `ClickAndCollectTest.php`.

**Pre-go-live readiness, separate from this coding order:**

- [ ] Legal review (05.15 §12 Q1).
- [ ] Configure real `seller.*` company details.
- [ ] Verify production PDF invoices/receipts, seller details, generation and customer access.

---

## 24. Reporting Suite — `docs/05.14-reporting.md` (added 2026-09-20)

The reporting-suite spec and implementation are absent. A daily cash report exists
for collections; it does not settle suite metrics, aggregate schema or refresh strategy.

- [ ] Write `docs/05.14-reporting.md` — which aggregates, which materialised views, refresh
      strategy. [G7]
  **Audit 2026-10-05:** Not started — `docs/05.14-reporting.md` and the suite are absent; transactional models/metric definitions/refresh strategy must be specified. `app/Domain/Collection/DailyCashReport.php` is one operational cash report, not this reporting suite.
- [ ] Once signed off: whatever the spec specifies; Filament dashboard widgets.
  **Audit 2026-10-05:** Not started — `docs/05.14-reporting.md` and the suite are absent; transactional models/metric definitions/refresh strategy must be specified. `app/Domain/Collection/DailyCashReport.php` is one operational cash report, not this reporting suite.
- [ ] Written **after** the modules it reports on (Phase 6, per the audit) — a reporting spec
      written before the transactional model it summarises is stable would need rewriting.
  **Audit 2026-10-05:** Not started — `docs/05.14-reporting.md` and the suite are absent; transactional models/metric definitions/refresh strategy must be specified. `app/Domain/Collection/DailyCashReport.php` is one operational cash report, not this reporting suite.

---

## 25. Deferred by decision — recorded so it is not mistaken for an oversight (added 2026-09-20)

`06-api-contract.md` §11 fixes the webhook/public-API conventions (signing, retry, scopes)
but no task in this roadmap builds them — 01 §5.1 places public integration endpoints and
webhooks in Phase 4, after the eight core modules. **This is a scope decision, not a gap**:
unlike G1–G10 above, nothing else in the signed-off spec set assumes this exists yet. [G11]

- [ ] Public API and webhook delivery — `06 §11`'s conventions, once Phase 4 is reached.
      Tracked here specifically so a future reviewer finds a decision, not a hole.
  **Audit 2026-10-05:** Not started — general third-party scoped API and outbound signed/retried webhook delivery are deferred; inbound Stripe/Postmark webhook controllers in `routes/api.php` do not implement this item.
- [ ] **Parked 2026-10-05 — POS module: walk-in till in B2X (amends 01 "Point of sale"
      out-of-scope). To be discussed with the product owner.** Until then, 01 §5.2 stands.
      Card payment at collection waits for it, because it needs a terminal (05.6 §7A, DRAFT).
      Recording cash against an order at the collection counter (05.6 §7A.6) is not POS and
      does not depend on it.
  **Audit 2026-10-05:** Not started — explicit product-owner scope decision is still pending; cash collection recording in `CashAtCollection.php` is not a till or terminal integration.

---

## 26. Seed Data & Reference Import — `docs/08-migration-seed.md` (added 2026-09-20, resequenced per §0.7)

Both pieces were previously unscheduled/too-late (see §0.7): Filament and the performance
suite are effectively untestable against empty tables, and the 920-SKU reference catalogue
(01 §3.2) is this project's one realistic load-test fixture.

- [ ] `database/seeders/DemoDataSeeder.php` — a small, realistic seed (companies, price
      tiers, a handful of SKUs across the pack/batch/serial variations) for exercising
      Filament resources and manual testing before real data exists. **Sequence early** —
      before §15's Filament resources are meaningfully testable. [G12]
  **Audit 2026-10-05:** Partly built — `database/seeders/DemoDataSeeder.php` supplies companies/tiers/pack breaks/images and untracked stock varieties; no batch/serial stock fixtures or ledger-backed movements are seeded. The full trade demo fixture item is incomplete.
- [ ] Write `docs/08-migration-seed.md` — import from `londontopchoice.co.uk` (01 §3.2, 02
      §12's own worked mapping table), validation rules, rejection reporting. [G12]
  **Audit 2026-10-05:** Not started — `docs/08-migration-seed.md` and `ImportReferenceCatalogue.php` are absent; source mapping, staging DDL/validation/rejection rules and reference dataset access must be agreed before import.
- [ ] Once signed off: `app/Console/Commands/ImportReferenceCatalogue.php` — the 920-SKU
      import via `COPY` into staging tables per 02 §12's own specified approach (not
      row-by-row Eloquent inserts). **Sequence early** — this is the load-test fixture the
      performance suite and every "at scale" acceptance criterion assumes exists; building
      every module against synthetic factory data first and importing real data last means
      discovering data-shape problems after everything else is already built against a
      fiction.
  **Audit 2026-10-05:** Not started — `docs/08-migration-seed.md` and `ImportReferenceCatalogue.php` are absent; source mapping, staging DDL/validation/rejection rules and reference dataset access must be agreed before import.

---

## B2B audit summary — 2026-10-05

Status describes the complete roadmap module, not just the implemented slice. No module
below is certified for production by this source review. “Dependency” is outstanding build
work; “open question” requires a decision; “missing spec” requires signed-off documentation.

| B2B module / roadmap scope | Status | Remaining work / blocker |
|---|---|---|
| Domain schema (§1) | Built | Existing Phase-1 models/factories/migrations; applying migrations is an operator check. Later module schemas are tracked separately. |
| Catalogue validation (§3) | Not started | Dependency: enforce base-price validation on activation; price authoring/admin tooling (§15). |
| Pricing (§2) | Partly built | Core resolution/rounding/spend breaks/tax built; production promotions, coupons, cache, cost-context guard, margins, PDFs and full test matrix missing. Open question: promotion category/brand eligibility; PDF export scope. |
| Inventory hardening (§4) | Partly built | Allocate/deallocate and batch selection/splitting built; serial reservation, batch-cap warnings, events, reconciliation, expiry/reaping and parallel races missing. Open question: launch tracking SKUs. |
| Order pad (§5) | Partly built | Pad/cart/local totals built; paste/CSV, scanner, saved lists/reorder, edit-conflict notice and query-budget tests missing. Dependencies: saved-list schema and credit ledger for applying balance. |
| Accounts & onboarding (§6, §23) | Partly built | Registration/review/business checks/team controls built; applicant status/reply/signed-in application and parked account changes missing. Open questions: trade-user 2FA reset identity check (⚑9); existing-company/additional-site flow. Production compliance credentials/legal terms remain operational gates. |
| Credit & buyer approvals (§6, §13) | Partly built | Hold/available-credit checks and invoice conversion built; suspended/overdue gates, approval routing/spend limits, management UI, reconciliation and coordinated reaper missing. Missing spec/schema: monthly company verification evidence. |
| Quotes & RFQ (§7) | Not started | Dependencies: full credit/approvals, discount authority/margin policy and PDF renderer. Open question: multi-role default discount authority (02 §14). Core pricing is available; 05.3 still needs sign-off. |
| Trade RMA / account balance (§8) | Partly built | Shared RMA/credit-note schema, receipt/inspection and consumer flows built; trade eligibility, fees, waiver/audit, settlement and append-only balance ledger missing. Dependency: ledger before refunds-as-credit/apply-balance; consumer resolution rejects trade; trade 05.4 needs sign-off. |
| Warehouse (§9) | Partly built | Goods-in/pick/dispatch/stocktake built; packing, serial checkout reservation, event consumers, substitution audit and W1–W3 races missing. Packing storage/scan contract needs specification before adding schema. |
| Delivery & collection (§10; shared §23A) | Partly built | Rating/placement/cash/handover built; rate/zone admin, pattern authoring, rescheduling/moves, reminders and parallel capacity proof missing. Open questions/configuration: live minimum/carriage/collection thresholds, slot hours/capacity and NI rate/manual quote; terminal cards wait on POS decision. |
| Purchasing (§11) | Partly built | GBP suppliers/PO/receipts/reorder built; container UI, duty/cost schema/apportionment/incoterm checks, supplier metrics and full API missing. Dependencies: 05.7 cost/duty draft sign-off and landed-cost machinery before posting. Open question: freight/duty default allocation bases. |
| Dropship & reps (§12) | Not started | Dependencies: credit/approvals, quotes/authority, secure on-behalf sessions, blind PDFs; 05.8 draft needs sign-off; no module schema/services/UI/tests. Nonblocking clawback timing/sender-identity questions remain. |
| API & frontend foundations (§13, §17) | Partly built | First-party v1/session/CSRF, envelopes, typed clients/lazy pages built; full endpoint contract, rate matrix, persistent idempotency and policy/tenancy completeness missing. Dependency: each unbuilt module. |
| Realtime (§14) | Not started | Scaffold only; unauthorized room joins block tenant connections. Dependency: token verification, authorized publisher/listeners and auth tests before deployment; scaling choice deferred. |
| Admin (§15) | Partly built | Catalogue/core operational slices built; pricing, attribute, stock/ledger, batch/recall, location/config/data-quality resources missing. Dependencies: domain services and policies first. |
| Audit (§18) | Partly built | Immutable storage/logger/auth/application/team viewer built; remaining privileged callers, partition rollover/monitoring and retention missing. No remaining audit schema block. |
| Transfers (§19) | Not started | Missing spec/schema: 02 §16 transfers/lines still unwritten; then paired movements, ordered locking and warehouse UI/tests. |
| Amendments & trade cancellation (§20; shared §23A) | Partly built | Partial cancellation core built; whole trade cancellation outside the quantity-cancellation path, ledger integration and amendments absent. Missing spec: amendment increases/address/credit re-checks/approval and fees (05.10 §1). |
| CMS/SEO shared with trade (§21) | Partly built | Signed-off legal/help/SEO slice built; missing later spec for arbitrary pages/banners/redirects. Dependency: import URL mapping for redirects; business legal review/content for launch. |
| Notifications / back-in-stock (§22) | Partly built | Dispatcher/preferences/log/webhooks and existing callers built; subscriptions and stock-received consumer absent. Dependency: subscription schema/flow and inventory event consumers; notices for future modules follow those modules. |
| Accounting export / Xero (§16) | Not started | Missing spec: 05.9 entity/tax/account mappings, OAuth/idempotency/reconciliation. Dependencies: Xero sync schema and stable invoice/payment/credit-note lifecycle; financial foundation tables already exist. |
| Reporting (§24) | Not started | Missing spec: 05.14 metrics/aggregate schema/refresh; dependency: stable transactional modules. Collection daily cash report is already built separately. |
| Public integrations / POS (§25) | Not started | Deliberately deferred API/outbound webhooks; POS scope requires product-owner decision. Inbound payment/mail webhooks and collection cash do not complete either module. |
| Demo seed / reference import (§26) | Partly built | Demo seeder exists but lacks batch/serial/ledger fixtures. Missing spec: 08 source mapping/staging/rejection plan; dependency: reference data access and agreed URL mapping. |
| Quality, compliance, payments & PDFs (§0, §16) | Partly built | Argon2id, staff 2FA and Stripe slice built; core PR CI exists (`.github/workflows/ci.yml`, `quality`); parallel/property/baseline/a11y/redaction/GDPR/PITR coverage remains incomplete. Real PDF renderer absent (`NullPdfRenderer` bound). Recorded Laravel upgrade requires approved stack amendment; live vulnerability/host checks were not performed. |

**Spec sign-off recorded 2026-10-05:** 05.16 (B2B UX standard), 05.17 (trade self-service,
PDF renderer A — Puppeteer via `spatie/browsershot`), 02 §31 and the completion sections
05.1 §14, 05.2 §18, 05.3 §17, 05.4 §15, 05.7 §17 and 05.8 §16 are signed off, with every
recommended answer in their open-question tables accepted. Earlier narrower amendments
(application compliance, purchasing/reorder, consumer returns, collection and partial
cancellation) keep their own sign-off; parts of 05.3/05.4/05.7/05.8 outside those
completion sections are still governed by them.

**Additional trade gaps not represented by a dedicated original checkbox:**

- [ ] Buyer order approval (05.2 §10): owner/approver queue, thresholds, buyer order limits,
      stock/credit holds, approve/reject and 48-hour expiry. Evidence of absence:
      `app/Domain/Ordering/TradeCheckout.php` explicitly excludes this; `routes/api.php`
      has no approve/reject order endpoints. Membership settings exist, enforcement does not.
- [ ] Backorders/automatic allocation on receipt (04/05.1/05.5):
      `CheckoutPreviewService.php` documents the missing backorder branch and no
      `StockReceived` listener implements it; shortage currently blocks checkout.
- [ ] Recall/trace operator workflow (04 §8): the batch trace SQL/index is tested in
      `HotPathExplainTest.php` Q18, but there is no recall service, recall UI or customer
      notice flow. Depends on inventory events and batch/serial tracking decisions.
- [ ] B2B order history/invoice account pages: `PublicAccountHistory.php` and Account
      orders/receipts are public-only; trade has individual order views and staff invoice
      administration, not the complete buyer self-service history/statement suite.
- [ ] Persistent accounting trail on trade card cancellation (05.10 §2 Q-X2):
      `PartialCancellations.php::money()` creates refund/credit-note rows, but no
      `account_credit_movements` table/writer exists for the required credit/payout pair.
- [ ] Privileged warehouse audit callers (07 §6.5): `BatchSubstitutionService.php`,
      `StocktakeService.php` and `ReturnInspection.php` record movement/disposition attribution
      but do not call `AuditLogger`; immutable stock movements are not the required audit log.

- [ ] Partial-cancellation acceptance matrix (05.10 §2.7): `PartialCancellationTest.php`
      has six focused tests, not the full hold/refund, 1,000-order property, packed/dispatch
      races, deadlock, batch/serial release and warehouse put-away matrix. Source branches
      alone do not prove these cases.

**UX retrofit (after module 3):**

- [ ] UX retrofit (after module 3): move existing trade screens (order pad, account, team,
      trade checkout, warehouse) onto the 05.16 shell and components; acceptance =
      Playwright screenshots at 390/1440 + axe, zero serious issues.

**Recommended remaining B2B build order:**

1. Extend existing PR CI with the parallel-connection harness and missing gates; add realistic demo fixtures and import spec first: they make stock/credit/capacity claims testable; start HMRC/legal/threshold decisions alongside them.
2. Credit/overdue/suspension/buyer approvals, account-credit ledger, coordinated reaper/reconciliation, serial allocation and inventory events/audit: protect the shared trade order path.
3. Applicant/buyer self-service, pad bulk/saved-list/reorder tools, collection rescheduling and packing, with pricing admin/activation validation and real PDFs: make daily trade operations usable.
4. Resolve promotion/authority and amendment specs, then finish trade returns/cancellation and quotes; follow with landed costs/containers and rep/dropship, reusing credit/stock/documents.
5. Transfers when multi-location is needed; authorize realtime before tenant connections; reporting after transactional modules, integrations/POS after scope decisions. Clear remaining go-live gates before launch.

**Audit validation:** code/test assertions inspected only; no tests or migrations run.
`composer lint`: Pint passed; parallel PHPStan was blocked by the sandbox local-socket
restriction. Equivalent single-process `vendor/bin/phpstan analyse --no-progress
--memory-limit=1G --debug` passed with no errors. `git diff --check` passed.
Only `docs/ROADMAP.md` was edited. No new schema or implementation decisions are approved
by this audit. Historical proposed filenames do not authorize duplicate migrations/services.
