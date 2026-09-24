# Wholesale Platform — Project Instructions

Laravel (PHP 8.3+) · Inertia.js + React (TypeScript) · PostgreSQL 16 · Redis ·
Node.js (TypeScript) real-time gateway

B2B wholesale/distribution platform. Trade accounts, tiered and contract pricing,
volume breaks, pack structure, authoritative stock with batch and serial traceability.

## Approved stack — do not introduce alternatives

| Layer | Technology |
|---|---|
| Backend | PHP 8.3, Laravel 11, PostgreSQL 16 |
| Real-time | Node.js (TypeScript), Fastify, Socket.io, subscribing to Redis Pub/Sub — `services/realtime-gateway/`, doc `11` |
| Frontend | Inertia.js + React (TypeScript), Tailwind, shadcn/ui, Zustand, TanStack Query, Vite |
| Admin | FilamentPHP v3 |
| Quality | Pest, PHPStan level 8, Pint |

Vue, Blade page views (beyond Inertia's single unavoidable root template), MySQL/MariaDB
and Laravel Mix are not part of this stack. Do not reintroduce them.

## Specifications are binding

`/docs` holds signed-off specs. **They are the source of truth, not suggestions.**
Read the relevant spec before writing code in that area. If code and spec disagree,
the spec wins — or the spec gets amended first, in a separate commit.

| Doc | Read before touching |
|---|---|
| `docs/01-solution-overview.md` | anything, first time |
| `docs/02-domain-model-erd.md` | migrations, models, any query |
| `docs/03-pricing-engine.md` | anything under `app/Domain/Pricing` |
| `docs/04-inventory-ledger.md` | anything under `app/Domain/Inventory` |
| `docs/05.*.md` | the corresponding feature |
| `docs/06-api-contract.md` | controllers, resources, routes |
| `docs/07-nfr.md` | performance or security work |
| `docs/11-realtime-gateway.md` | anything under `services/realtime-gateway`, or Redis Pub/Sub events |
| `docs/ROADMAP.md` | before picking up any new task — it is the delivery plan; there is no `docs/10-*` |

Do not invent a table, column or index that is not in `02-domain-model-erd.md`.
Propose an amendment to the doc instead, and wait.

## Non-negotiable invariants

These have specific, non-obvious reasons documented in the specs. Violating any of
them is a correctness bug, not a style issue.

1. **Money is integer only. No floats, ever, anywhere in a money path.**
   - Per-unit amounts: `_e4` suffix, ten-thousandths of a pound. £0.9212 → `9212`
   - Per-line and per-document amounts: `_minor` suffix, whole pence
   - Conversion `e4 → minor` happens exactly once per line. See `03` §6
   - Rounding is half-up, integer arithmetic, via `Money::roundHalfUpDiv()`

2. **All quantities are base units.** Column suffix `_base_qty`. A pack is a
   transaction unit, never a storage unit. Pack-denominated quantities appear only
   alongside their base equivalent. See `02` §5.6

3. **Prices are resolved, never stored on a product or SKU.** There is no `price`
   column on `products` or `skus`. Resolution precedence is in `03` §4.2

4. **Resolved prices and costs are snapshotted immutably onto `order_lines`.**
   Never re-resolve an existing line. A ten-year-old invoice must reprint identically

5. **`stock_movements` is append-only.** No UPDATE, no DELETE, ever. Corrections are
   compensating movements with a reason code. `stock_levels` is a rebuildable
   projection, not a source of truth

6. **Allocation locks follow one global order: `companies` → `collection_slots` →
   `stock_levels`.** `stock_levels` rows are locked ascending by `sku_id`,
   `location_id`, `batch_id` (`NULLS FIRST`, since Postgres orders NULLs last by
   default and untracked SKUs have `batch_id IS NULL`). `collection_slots` is
   locked only when the order is a collection. Consistent ordering — not just
   within `stock_levels`, but across all three — is what prevents deadlocks. No
   external calls (payment, email, HTTP) inside that transaction. See `04` and
   `02` §11.1

7. **Isolation level is `READ COMMITTED`** — already the PostgreSQL default, so do not
   override it. Postgres takes no gap locks, so the MySQL-era concern is gone.
   `SERIALIZABLE` was considered and rejected (02 §11.2): explicit `FOR UPDATE` in a
   documented order is more auditable than a probabilistic retry loop

8. **Document numbers come from `number_sequences` with `FOR UPDATE`,** never
   database sequences. Sequences are non-transactional and gap on rollback;
   document series must be gapless for accounting

9. **Cost figures never reach customer-facing contexts.** `ResolvedPrice::$unitCostE4`
   is null outside admin/rep contexts, enforced by policy gate

## Commands

```bash
composer test                # full suite
composer test -- --filter=X  # single test
composer lint                # Pint + PHPStan level 8
composer analyse             # static analysis incl. no-float-in-pricing rule
php artisan migrate:fresh --seed
npm run dev / npm run build          # Inertia/React frontend, from repo root
npm run dev / npm run build          # realtime gateway, from services/realtime-gateway/
```

Run `composer lint && composer test` before reporting any task complete.

**Never run `php artisan config:cache`, `route:cache` or `event:cache` in this project.**
A cached config makes Laravel ignore `phpunit.xml`'s environment overrides, so the suite
runs against the wrong settings and fails with 419 CSRF errors. If one was run by
mistake, clear it with `php artisan optimize:clear`.

## Conventions

- Domain logic in `app/Domain/{Context}/`, thin controllers, no business logic in models
- One aggregate per context; contexts talk via domain events, not cross-context queries
- Form Requests for validation, Policies for authorisation, never inline checks
- Every query on a hot path (see `02` §10, Q1–Q19) has an `EXPLAIN` assertion test
- Migrations are never edited after merge — add a new one
- Enums are PHP backed enums mirroring the Postgres `CHECK`-constrained text column,
  single source in the enum class
- Frontend pages are React (TS) function components under `resources/js/pages/`,
  rendered by Inertia — no full-page Blade views. `resources/views/app.blade.php` is
  Inertia's single required root template, not an exception to this
- `shadcn/ui` components are generated into `resources/js/components/ui/` via its CLI,
  not installed as an npm package — only their underlying primitives
  (`class-variance-authority`, `clsx`, `tailwind-merge`, `@radix-ui/*`, `lucide-react`)
  are dependencies
- Client-side ephemeral UI state uses Zustand; server-state caching/fetching uses
  TanStack Query. Neither duplicates data Inertia already passed as page props
- FilamentPHP resources live under `app/Filament/`, are staff/admin-only, and go
  through the same Policies as everything else — no separate authorisation path
- The real-time gateway (`services/realtime-gateway/`) is stateless and holds no
  domain data: Laravel publishes to Redis after commit, the gateway only relays to
  Socket.io rooms. See `11-realtime-gateway.md`

## Things that will look wrong and are not

- `batch_id` is **nullable** on `stock_levels`, `stock_movements` and `stock_allocations`,
  with identity enforced by `UNIQUE NULLS NOT DISTINCT`. NULL means "not batch-tracked".
  There is no sentinel row. See `02` §7.3
- `stock_movements` has **no foreign keys** and a composite `(id, occurred_at)` PK, and is
  partitioned by range on `occurred_at` from day one. The FK omission is a write-cost
  choice, not a platform limit — Postgres permits them on partitioned tables. See `02` §7.4
- Validity windows are `tstzrange` with `EXCLUDE USING gist` constraints, so overlapping
  active price lists, tax rates, delivery rates, duty rates and commission rules are
  **impossible to persist**. Do not "simplify" these to two date columns. See `02` §6.3
- Partial unique indexes carry real business rules — one default pack per SKU, one default
  contact per company, one live account per email. They look like optional filters and are
  not. See `02` §5.6, §2.4
- `price_list_items_resolve_idx` leads with the low-cardinality `price_list_id` on purpose,
  and uses `INCLUDE` rather than key columns for the payload. See `02` §6.4
- Index-only scans need a current visibility map. Tests assert `Heap Fetches: 0`; a failure
  there means autovacuum is behind, not that the index is wrong. See `02` §9 rule 11
- Two indexes exist on `price_list_items` in different column orders. Both are used —
  one for single resolution, one for the bulk order-pad path. Not redundant

## Open decisions — do not guess

If work touches these, stop and ask:

- Coupon stacking rules beyond one per order
- Which SKUs enable batch/serial tracking at launch
- Multi-currency

Order-wide spend breaks ("£1,000 total → 3% off") are **settled**, not open: a second
pricing pass via `order_spend_breaks`, apportioned to lines. See `02` §6.7 and `03` §7A.

## Reference system

`londontopchoice.co.uk` is the incumbent WooCommerce system being replaced. Its
catalogue (~920 SKUs) is the migration and load-test fixture. Its gaps — no volume
breaks, no pack structure, no traceability, 92-page paginated order table — are why
this project exists. Do not copy its behaviour.
