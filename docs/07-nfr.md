# Non-Functional Requirements

**B2B Wholesale & Distribution Platform**

| | |
|---|---|
| Document | 07 — Non-Functional Requirements |
| Status | Draft for review |
| Depends on | 01 §8–9 (success criteria, constraints), 02 §10–11 (budgets, concurrency), 03 §14, 04 §13, 05.1–05.8, 06 §12 |
| Feeds into | 09 — Test Strategy, `docs/ROADMAP.md` (Correction 2026-09-20: no `docs/10-*` exists — the roadmap is the delivery plan) |

---

## 1. Purpose

Everything in this document is testable, and nothing in it is aspirational. A performance target with no measurement is a wish; a security requirement with no verification is a comment.

Each requirement below states what is required, how it is verified, and what happens when it is breached.

---

## 2. Performance budgets

Consolidated from every prior document. These are the authoritative figures; where an earlier doc disagrees, this table wins.

Conditions: single PostgreSQL 16 instance, warm buffer cache, 5,000 SKUs, 95th percentile unless stated.

### 2.1 Customer-facing

| # | Operation | Budget | Index / Reference |
|---|---|---|---|
| P1 | Order pad page, 100 rows | **800 ms** | 05.1 §9 |
| P2 | Bulk price resolution, 100 SKUs × 5 lists | **15 ms** | `price_list_items_resolve_idx`, 03 §8 |
| P3 | Stock availability, 100 SKUs | **10 ms** | `stock_levels` PRIMARY |
| P4 | Category page, faceted, 50 products | **1.5 s** | `products_cat_active_idx` + facets |
| P5 | Product detail page | **600 ms** | 02 §10 Q5 |
| P6 | Search, keystroke to results | **300 ms** | `products_search_gin` + `products_name_trgm` (02 §5.4a) |
| P7 | "My orders", any page | **20 ms** query / **900 ms** page | `orders_company_placed_idx` |
| P8 | Checkout preview | **400 ms** | 06 §9.2 |
| P9 | Checkout, end to end | **2 s** | excludes gateway round trip |
| P10 | Quote detail with lines | **30 ms** | 05.3 §12 |

### 2.2 Transactional correctness paths

| # | Operation | Budget | Reference |
|---|---|---|---|
| P11 | Allocation lock hold, 6-line order | **5 ms** | 04 §13 |
| P12 | Credit check and hold | **5 ms** | 05.2 §8 |
| P13 | Collection slot lock hold | **5 ms** | 05.6 §9 |
| P14 | Dispatch transaction | **30 ms** lock hold | 05.5 §11 |
| P15 | FEFO batch selection per line | **10 ms** | `batches_fefo_idx` |
| P16 | Serial selection per line | **10 ms** | `stock_serials_pick_idx` |

Lock-hold budgets are the important ones: they bound checkout throughput. A regression here is a capacity regression, not a latency one.

### 2.3 Internal and batch

| # | Operation | Budget |
|---|---|---|
| P17 | Pick list for a shipment | 50 ms |
| P18 | Goods-in receipt line write | 20 ms |
| P19 | Low stock / reorder report | 300 ms |
| P20 | Container apportionment | 500 ms |
| P21 | Recall trace, batch to customers | 500 ms |
| P22 | Rep statement for a period | 50 ms |
| P23 | Nightly full reconciliation | 10 min |
| P24 | Catalogue import, 1,000 rows | 60 s (queued) |
| P25 | Invoice/quote PDF generation | 3 s (queued) |

### 2.4 Front-end

| Metric | Target |
|---|---|
| Largest Contentful Paint | under 2.5 s on 4G |
| Interaction to Next Paint | under 200 ms |
| Cumulative Layout Shift | under 0.1 |
| Initial JS bundle, gzipped | under 250 KB |
| Order pad quantity change to recomputed total | under 50 ms, **no network** (05.1 §5.1) |

### 2.5 Verification

- Every budget in §2.1–2.3 has an automated test asserting both the timing and, where relevant, the `EXPLAIN` plan (no filesort, no temporary table, expected index).
- CI fails on a **20% regression** against the recorded baseline for any budget.
- Query count is asserted constant where row count varies — the N+1 guard (05.1 §11).
- Front-end metrics are measured by Lighthouse CI on every build.

---

## 3. Capacity

| Dimension | Launch | Design headroom |
|---|---|---|
| SKUs | 920 imported, ~2,000 expected | 20,000 |
| Companies | 200–500 | 10,000 |
| Orders | ~200/day | 2,000/day |
| Order lines | ~1,200/day | 12,000/day |
| `stock_movements` growth | ~3,600 rows/day (1.3M/yr) | Partition trigger at 20M (04 §10) |
| Concurrent authenticated sessions | 50 | 500 |
| Peak checkouts per minute | 10 | 100 |
| Media storage | ~20 GB | 500 GB, CDN-fronted |

The binding constraint at scale is **allocation lock contention**, not CPU or query throughput. 100 checkouts per minute against overlapping SKUs is the scenario to load-test, not 10,000 catalogue page views.

---

## 4. Availability and recovery

| Requirement | Target |
|---|---|
| Uptime, business hours (07:00–19:00 UK, Mon–Sat) | **99.9%** |
| Uptime, overall | 99.5% |
| Single-node caveat | See §11.5 — on one VPS with no standby, 99.9% allows ~22 minutes of unplanned downtime per business month, which a single reboot can consume |
| Planned maintenance | Outside business hours, announced 48 h ahead |
| RPO (maximum data loss) | **15 minutes** |
| RTO (time to restore service) | **4 hours** |

Backups — as implemented on the launch target (§11.5):

- **Nightly physical base backup** (pgBackRest full weekly, differential nightly), retained 30 days; weekly retained 12 months. A nightly `pg_dump` is additionally taken as a logical safety net, since it survives a major-version or corruption scenario a physical backup does not.
- **WAL archiving to off-server object storage**, which is what delivers the 15-minute RPO. A nightly-only backup means an RPO of up to 24 hours, and for a stock ledger that is unacceptable — a lost day of movements cannot be reconstructed from anywhere, because the ledger *is* the source of truth.
- `wal_level = replica`, `archive_mode = on`, `archive_timeout = 300` (forcing a segment switch every 5 minutes even when idle), `synchronous_commit = on`, `full_page_writes = on`. Anything looser trades durability for write throughput, which is the wrong trade for a system whose ledger is authoritative.
- Managed with **pgBackRest** rather than hand-rolled `archive_command` scripts: it gives verified backups, retention policy, parallel restore and `--target-time` PITR, and its `check` command proves the archive is actually working rather than assuming it.
- `pg_basebackup`-style physical base backups, not `pg_dump`, as the PITR base. A logical dump cannot serve as a WAL replay target.
- **Backups must leave the server.** A VPS provider's snapshot lives in the same account as the VPS; it protects against disk failure, not against account loss, a mistaken `DROP`, or a compromised host. Backups and binlogs replicate to independent object storage with its own credentials.
- A provider snapshot of a running VPS is **not** a substitute for a database backup: PostgreSQL can usually recover from a filesystem-consistent snapshot, but a VPS snapshot gives no such guarantee across the data and WAL directories, and it cannot serve as a PITR base with a known consistent recovery point. Snapshots are for whole-server rebuild, not data recovery.
- Media backed up separately with versioning.
- **Restore is tested quarterly, into a scratch environment, with the result recorded.** An untested backup is an assumption.

Degraded-mode behaviour — what must keep working when a dependency fails:

| Dependency down | Behaviour |
|---|---|
| Search (in-database) | No separate service to fail. A degraded search path is a query-plan problem, not an availability one — one fewer failure mode than the external-engine design |
| Redis | Everything works, slower. Cold pricing resolution is survivable by design (03 §9) |
| Payment gateway | On-account and BACS checkout continue; card checkout blocked with a clear message |
| Mail | Queued, not lost. Orders complete; notifications catch up |
| PDF worker | Orders complete; documents generate when the worker returns |

**No dependency failure may cause an order to be partially committed.** Every transaction either completes or rolls back (04 §4.4).

---

## 5. Correctness targets

Stated as absolutes because they are absolutes, not percentiles.

| # | Requirement | Verification |
|---|---|---|
| C1 | **Zero oversells.** No allocation of stock that does not exist | 04 §12 C1, 50 parallel orders against 10 units, 100 repetitions |
| C2 | **Zero over-limit credit approvals** | 05.2 §13 CR1, 100 repetitions |
| C3 | **Zero slot overbookings** | 05.6 §11 D1, 100 repetitions |
| C4 | **Zero double-commissioning** | 05.8 §13, duplicate invoicing job |
| C5 | Client and server order totals agree **exactly** | 03 §12, 1,000 generated baskets |
| C6 | Apportioned shares sum to the bucket total **exactly** | 03 §7A.3, 05.7 §8.2 |
| C7 | Every projection rebuilds exactly from its ledger | Stock, credit used, credit held, account balance, incoming |
| C8 | No `UPDATE` or `DELETE` on any append-only ledger | Query-log assertion in tests |
| C9 | No float arithmetic in any money path | Static analysis, CI-enforced |
| C10 | Deadlocks reported to callers: **zero** | 04 §12 C2, 05.2 §13 CR4 |

Breach handling: C1–C4 and C7 are **P1 incidents** (§9.3). C5, C6, C8 and C9 are CI gates — a build that breaks them does not ship.

---

## 6. Security

### 6.1 Authentication

- Passwords: Argon2id, minimum 12 characters, checked against a breached-password list. No composition rules, no forced rotation — both reduce real security.
- **2FA mandatory for every staff role** (admin, accounts, purchasing, rep, warehouse, sales_manager). Optional for trade customers, encouraged for company owners.

  **Correction 2026-09-17.** `sales_manager` added to the staff role list. `05.3-quotes-rfq.md` §6.3/§8 already specifies a quote margin-approval gate ("Blocked unless a manager approves") and a "sales manager" persona (U9) who approves discounts beyond a rep's authority, but the original five-role list here had no role that fit: `admin` is system administration, and `rep` is the role being escalated past, not the approver. `docs/02-domain-model-erd.md` §14.1 (`roles`/`role_user`, DRAFT) is updated to match.
- Session: 12-hour idle timeout for customers, 4-hour for staff, 1-hour for admin. Absolute maximum 7 days.
- Login rate limited to 5 attempts per minute per IP **and** per identifier (06 §12), with progressive lockout.
- Password reset tokens single-use, 60-minute expiry, invalidating all sessions on use.

### 6.2 Authorisation

- Policy-gated on every endpoint, with a **route-to-policy coverage test that permits no exemptions** (06 §16.10).
- Tenancy enforced in the `WHERE` clause, never by filtering the response (06 §10).
- Cost, margin and landed cost absent from customer-facing serialisers by construction — there is no field to omit (03 §11).
- Privilege escalation explicitly blocked: a rep cannot alter credit limits, lift suspensions, or approve their own quotes (05.8 §8).

### 6.3 Application security

OWASP Top 10, addressed specifically rather than by reference:

| Risk | Control |
|---|---|
| Injection | Parameterised queries only. Raw SQL requires review and appears in no request path. `pg_trgm` and full-text inputs go through `websearch_to_tsquery`, never string-concatenated into a `tsquery` |
| Broken access control | §6.2, plus the coverage test |
| Cryptographic failures | TLS 1.2+ only, HSTS, secrets in a managed store, never in the repo or environment files in version control |
| Insecure design | This document set |
| Security misconfiguration | Hardened defaults, CSP, `X-Content-Type-Options`, no directory listing, debug mode impossible in production |
| Vulnerable components | Dependabot, weekly `composer audit` and `npm audit` in CI, build fails on high severity |
| Auth failures | §6.1 |
| Integrity failures | Signed webhooks (06 §11), subresource integrity on third-party scripts |
| Logging failures | §9 |
| SSRF | No user-supplied URLs are fetched server-side. Media ingest accepts uploads, not URLs |

Additional:

- **File uploads** (product images, application documents, CSV imports): MIME sniffing not extension trust, size limits, stored outside the web root, served through a signed URL, images reprocessed to strip metadata and any embedded payload.
- **CSV import** is treated as untrusted input: formula-injection prefixes neutralised on export, no shell interpolation on import.
- CSRF tokens on all session-authenticated state changes.
- Admin and warehouse routes IP-restrictable by configuration.

### 6.4 Payment card scope

**Card data never touches our servers.** Stripe Elements or Checkout tokenises in the browser; we store only the gateway reference and last four digits.

This places the business in **PCI DSS SAQ-A** scope, the lightest assessment. Any change that introduces card data into our request path — a custom card form posting to our backend, for example — moves us to SAQ-D and is out of scope for this platform. Recorded here because it is the kind of decision that gets made casually in a sprint and is expensive to reverse.

### 6.5 Audit

Immutable audit log for: authentication events, permission changes, credit limit changes, price and price-list changes, manual price overrides, discount authority grants, fee waivers, stock adjustments, configuration changes, rep order-on-behalf sessions, and every RMA disposition.

Each entry: actor, action, subject, before/after where applicable, IP, user agent, timestamp. **Append-only**, retained 7 years (§7.2).

---

## 7. Data protection

### 7.1 Lawful basis and minimisation

| Data | Basis | Note |
|---|---|---|
| Trade customer contact and account data | Contract | |
| Company financial data (credit, invoices) | Contract, legal obligation | |
| End-customer dropship addresses | Contract (as processor for the trade customer) | **Snapshot-only; no reusable store** (05.8 §5.3) |
| Marketing communications | Consent, separately captured | Opt-in, not bundled with account creation |
| Staff data | Contract, legal obligation | |

The dropship decision is the load-bearing one: **there is no end-customer table**, so there is no "all end customers" query to misuse and no store to unpick on a deletion request. The constraint is enforced by absence, which is stronger than a policy.

### 7.2 Retention

| Data | Period | Driver |
|---|---|---|
| Orders, invoices, credit notes, payments | **7 years** | VAT records: HMRC requires 6; 7 gives margin |
| Audit log | 7 years | Aligned with financial records |
| Stock movements | 7 years, then archival | Traceability and financial support |
| Batch and serial records | 7 years minimum | Recall capability; product-liability limitation periods run long |
| Quotes | 3 years | Commercial reference |
| Customer accounts, inactive | 7 years from last transaction | Then anonymised |
| Applications, rejected | 2 years | Duplicate detection and re-application history |
| Web/session logs | 90 days | |
| Marketing consent records | Duration of consent + 3 years | Proof of consent |
| Backups | Per §4 | Erasure requests reconciled against backup rotation |

### 7.3 Erasure versus statutory retention

The conflict that every retail system gets wrong, so it is specified rather than discovered:

**A deletion request does not delete transactions.** Financial records must be retained for VAT purposes regardless of a data subject's request; the right to erasure does not override a legal obligation.

The resolution is **anonymisation of the person, retention of the transaction**:

- `users.email` overwritten with a non-reversible tombstone, name replaced, phone nulled. The row survives so foreign keys hold and `placed_by_user_id` on a seven-year-old order still resolves.
- `orders`, `order_lines`, `invoices` retained in full. They record a transaction with a *company*, not a natural person.
- `order_addresses` retained — it is part of the invoice record. Contact name within it is anonymised on request where the order is past its retention-relevant period.
- `customer_activities` free text reviewed and redacted, since notes about a call may contain personal detail with no retention basis.

Doc 02 §4.2 already provides for this: `users` carries `deleted_at`, and the unique constraint on `email` is satisfied by tombstoning rather than deletion.

### 7.4 Subject rights

| Right | Implementation |
|---|---|
| Access | Self-service export of account, orders, invoices, addresses as JSON and PDF, within 30 days |
| Rectification | Self-service for contact data; account data via support |
| Erasure | §7.3 anonymisation workflow, with a written explanation of what is retained and why |
| Portability | JSON export, machine-readable |
| Objection to marketing | One-click unsubscribe, honoured immediately |

### 7.5 Processors

Every third party handling personal data is recorded with its purpose, location and DPA status: payment gateway, email delivery, error tracking, CDN, hosting, search. Error tracking is configured to **scrub personal data before transmission** — an exception payload containing a customer's address is a data transfer nobody assessed.

---

## 8. Accessibility

**WCAG 2.1 Level AA**, across customer, admin and warehouse surfaces. Not just the storefront — a warehouse operative with a visual impairment uses the picking screen.

| Requirement | Detail |
|---|---|
| Contrast | 4.5:1 body text, 3:1 large text and UI components |
| **Never colour alone** | Stock states carry a numeral and a text label as well as a colour band (05.1 §4.3). This is the most likely violation in a system with green/amber/red stock |
| Keyboard | Every flow completable without a pointer. Order pad entry of a 20-line order, keyboard only (05.1 §11) |
| Focus | Visible indicator, logical order, no traps, never stolen by an async update |
| Touch targets | 44 px minimum; **48 px on warehouse screens** (gloves, 05.5 §9) |
| Screen readers | Semantic HTML first, ARIA only where semantics fall short. Price and total changes announced via live regions |
| Forms | Programmatic label association; errors announced and linked to their field |
| Zoom | Usable at 200% without horizontal scrolling |
| Motion | Honours `prefers-reduced-motion` |
| Language | `lang` attribute set; ready for the multilingual phase |

Verification: automated axe-core checks in CI on every page type, plus **manual keyboard and screen-reader testing of the order pad, checkout and picking screens** before each release. Automated tools catch roughly a third of real issues; the three screens that matter get a person.

---

## 9. Observability

### 9.1 Logging

- Structured JSON, correlated by `request_id` (surfaced in every error response, 06 §4).
- Levels used meaningfully: `error` is actionable, `warning` is a trend to watch, `info` is business events, `debug` off in production.
- **Never logged:** passwords, card data, session tokens, full personal addresses, API secrets. Enforced by a redaction filter with a test.
- Business-event log distinct from the application log: orders placed, allocations, dispatches, price changes. This is the forensic trail when someone asks what happened on Tuesday.

### 9.2 Metrics

| Metric | Why |
|---|---|
| Request rate, error rate, latency percentiles by route | Baseline |
| Allocation transaction duration and lock wait time | The capacity constraint (§3) |
| Deadlock and retry counts | A rise means the lock ordering has been violated somewhere (04 §4.5) |
| Queue depth and job age by queue | Backlog before it becomes a symptom |
| Cache hit rate, pricing and config | A drop precedes a latency incident |
| Postgres: `Heap Fetches` on covering paths, `n_dead_tup`, replication/archive lag | Index-only scans and the 15-minute RPO both depend on these staying healthy |
| Projection reconciliation results | §5 C7 |
| Client/server total mismatches | §5 C5, should be zero |
| Checkout funnel: cart → preview → order | Commercial and technical signal in one |
| Zero-result searches | Catalogue gaps (05.1 §4) |

### 9.3 Alerts

**P1 — immediate, out of hours:**

- Projection drift detected: stock, credit used, credit held, or account balance
- Oversell detected (negative available, or allocation exceeding on-hand)
- Client/server pricing total mismatch
- Payment gateway webhook failures accumulating
- Database unavailable, WAL archiving failed or lagging, or backup verification failed
- Error rate above threshold, or checkout success rate collapse

**P2 — business hours:**

- Deadlock rate rising above baseline
- Queue age exceeding threshold
- Any performance budget breached in production
- Cache hit rate drop
- Disk or connection-pool headroom low
- Unresolved carriage-quote or approval queues ageing

**Drift is never auto-corrected** (04 §9). An automatic fix hides the bug that caused it and destroys the evidence.

### 9.4 Tracing

Distributed tracing across request, queue job and database, sampled. Full traces retained for any request breaching a §2 budget — the slow ones are the only ones anybody investigates.

---

## 10. Browsers and devices

| Surface | Support |
|---|---|
| Storefront, order pad | Last 2 versions of Chrome, Edge, Firefox, Safari. iOS Safari 16+, Chrome Android |
| Admin | Same, desktop-optimised |
| Warehouse | Chrome Android on rugged handhelds; specific device list agreed with the client |
| Graceful degradation | Core browsing and ordering functional without JavaScript enhancements; the order pad's local recomputation requires JS and says so |

No IE11. No polyfilling for browsers the customer base does not use — every polyfill is bundle weight paid by everyone.

---

## 11. Operational constraints

### 11.1 Zero-downtime migrations

The system holds authoritative stock; a blocking `ALTER` during business hours stops trading.

- **Expand/contract only.** Add nullable column → backfill in batches → deploy code writing both → deploy code reading new → drop old. Never a single destructive migration.
- No blocking `ALTER` on `stock_movements`, `order_lines`, `stock_levels` or `price_list_items` during business hours. Postgres makes most of these cheaper than MySQL did: adding a nullable column with no default is instant, `CREATE INDEX CONCURRENTLY` and `REINDEX CONCURRENTLY` avoid write locks, and `ALTER … ADD CONSTRAINT … NOT VALID` followed by `VALIDATE CONSTRAINT` takes only `SHARE UPDATE EXCLUSIVE` (02 §2.5). Any remaining rewrite-inducing change uses a new-table-and-swap pattern.
- Index additions verified for lock behaviour on the target PostgreSQL version before deployment.
- `stock_movements` is **already partitioned** (02 §7.4), so retention detachment and per-partition index maintenance need no whole-table operation at all. `lock_timeout` is set on every migration session so a migration blocked behind a long transaction fails fast rather than queueing writers behind it.
- Every migration has a tested down path, or is documented as irreversible with the reason.

### 11.5 Deployment topology

**Launch / demo target: Hostinger VPS (4 vCPU, 16 GB RAM), Ubuntu 24.04 LTS, London region.** Nginx, PHP-FPM, PostgreSQL 16, Redis and queue workers co-resident. No separate search service — Postgres full-text plus `pg_trgm` covers it (02 §5.4a), which removes one process from a contended box.

**Enterprise blueprint: AWS — ECS for the application, RDS for PostgreSQL, ElastiCache for Redis.** Recorded as the migration path, not the launch configuration. Nothing in this document set depends on which is in use: the schema, budgets and correctness guarantees are identical, which is the point of having specified them independently of infrastructure.

London region satisfies UK data residency, which keeps §7.5 simple — no international transfer assessment is needed for the primary store.

**What co-residency costs, stated honestly:**

| Concern | Consequence on a single VPS | Mitigation |
|---|---|---|
| No failover | A host incident or kernel reboot is full downtime. The §4 RTO of 4 hours is achievable only with a tested rebuild path | Infrastructure-as-code for the whole box; documented, rehearsed rebuild; provider snapshot as the base image |
| Resource contention | `shared_buffers` and the OS page cache compete with Redis and PHP-FPM workers. The §3 constraint — allocation lock contention — worsens when Postgres is starved of CPU or memory | Size deliberately (§11.6); cap PHP-FPM memory; move Postgres to its own instance before it is urgent |
| Noisy-neighbour variance | Shared-tenancy VPS CPU steal makes the §2.2 lock-hold budgets less predictable than on dedicated hardware | Monitor CPU steal as a first-class metric; treat a rise as a P2 |
| Autovacuum starvation | Postgres index-only scans need a current visibility map. Under CPU pressure autovacuum falls behind and the `Heap Fetches: 0` guarantee in 02 §10 silently degrades | Per-table autovacuum tuning (§11.6); monitor `n_dead_tup` and `last_autovacuum` per hot table |
| Single point for backups | A compromised host can destroy local backups | Off-server replication with separate credentials (§4) |

**This is a reasonable launch choice** at 200 orders/day and 50 concurrent sessions, and the cost difference against managed infrastructure is real. But the 99.9% business-hours target is the commitment most at risk from it, and the honest position is that it depends on a rehearsed rebuild rather than on redundancy. If that target is contractual, a warm standby with replication should be in scope before launch rather than after the first incident.

### 11.6 Sizing and triggers

Minimum specification, with the review trigger for each:

| Resource | Launch minimum | Move when |
|---|---|---|
| vCPU | 4 | Sustained above 60%, or CPU steal above 5% |
| RAM | 16 GB | `shared_buffers` + page cache cannot hold the working set |
| `shared_buffers` | 4 GB | Cache hit rate below 99% (`pg_stat_database`) |
| `effective_cache_size` | 12 GB | Planner favouring sequential scans on indexed paths |
| `work_mem` | 16 MB, raised per-session for reports | Sort or hash spills to disk on §2.3 queries |
| `max_connections` | 100, behind PgBouncer in transaction mode | Connection count approaching the cap |
| Autovacuum | `autovacuum_vacuum_scale_factor = 0.02` on hot tables | `n_dead_tup` rising, or `Heap Fetches` above 0 on Q1/Q2/Q5 |
| Disk | NVMe, 200 GB | Above 60% used, or `stock_movements` partitions due for detachment |

**Separate PostgreSQL onto its own host when any of:** cache hit rate drops below 99%, allocation lock-hold p95 exceeds the §2.2 budget of 5 ms, peak checkouts sustain above 30 per minute, or autovacuum cannot keep `Heap Fetches` at 0 on the covering paths. Deferring past those points means diagnosing a contention problem under load rather than planning a move.

### 11.2 Environments

Local (Docker) → CI → staging (production-like data volume, anonymised) → production. Staging carries a realistic 5,000-SKU catalogue and a year of synthetic movements, because performance problems only appear at volume.

### 11.3 Deployment

- Automated from `main`, tests and static analysis green.
- Migrations run before code, compatible with the outgoing version by the expand/contract rule.
- Feature flags for anything user-visible and incomplete.
- Rollback: previous release redeployable within 15 minutes; data migrations rolled forward, never back.
- Queue workers drained and restarted gracefully — a worker killed mid-job must leave no partial transaction (§4).

### 11.4 Secrets and configuration

Managed secret store, never in the repository. Rotation procedure documented for gateway keys, database credentials and signing secrets. Business configuration lives in `system_configurations` (02 §2.7) and is not a deployment concern.

---

## 12. Compliance

| Obligation | Implementation |
|---|---|
| **VAT records** | Per-line VAT on every invoice, rate snapshotted per line, 7-year retention, VAT report and accounting export (03 §10) |
| **Making Tax Digital** | VAT figures exportable in a format the accounting package can submit; we do not submit directly |
| **Xero integration** | Native API sync of invoices, credit notes and payments. OAuth 2.0 with tenant connection, refresh-token rotation, idempotent push keyed on our `invoice_number`, and reconciliation of Xero's rate limits (60/min, 5,000/day) against our invoice volume. Chart-of-accounts and tax-rate mapping held in `system_configurations` (02 §2.7), not hard-coded |
| **Sage-compatible CSV export** | Export engine producing Sage-importable ledger files for invoices, credit notes and payments, with a documented column contract and a rejection report on malformed rows |
| Companies Act record keeping | Financial records 7 years |
| **Product traceability** | Batch and serial capture with a recall trace from batch to customer in minutes (04 §7.5, 05.5 §4.3) |
| Customs and duty | HS code and country of origin captured per product; customs entry documents attached to containers (05.7 §9) |
| Consumer law | **Applies to public customers** (Correction 2026-09-24 — previously "not directly applicable — B2B"; the platform sells to the public, 01 §4). Trade returns remain contractual (05.4 §6). For public customers, statutory consumer rights apply — among them the distance-selling cancellation right and VAT-inclusive price display — and are **not yet specified** in 05.4 or 05.1: §16 Q10 |
| Non-UK VAT jurisdictions | Channel Islands and Isle of Man flagged for manual handling (05.6 §4.2); reverse charge deferred (03 §13) |
| **Accessibility** | WCAG 2.1 AA (§8) — a procurement requirement for many trade buyers, not only an ethical one |

---

## 13. Internationalisation readiness

Not built at launch, but not designed out:

- All user-facing strings externalised from day one. Retrofitting extraction across a built application is expensive and always incomplete.
- `users.locale` present (02 §4.2); `lang` attribute set.
- Dates, numbers and currency formatted server-side through a locale-aware layer, never string-concatenated.
- `currency` columns present on price lists, orders and quotes (02 §6.3, §8.2); FX is additive (01 §11 Q1).
- Schema is `utf8mb4` throughout with an accent-insensitive collation for names and search (02 §2.6).

---

## 14. Verification summary

| Requirement class | Method | Gate |
|---|---|---|
| Performance budgets §2 | Automated timing + `EXPLAIN` assertions | CI fails on 20% regression |
| Correctness §5 | Concurrency and property tests | CI fails on any breach |
| Security §6 | SAST, dependency audit, route-policy coverage, annual penetration test | CI fails on high-severity findings |
| Data protection §7 | Retention job tests, export and anonymisation tests, log-redaction test | CI |
| Accessibility §8 | axe-core in CI + manual audit of three key screens | CI plus pre-release sign-off |
| Observability §9 | Alert-firing tests against synthetic conditions | Pre-release |
| Migrations §11.1 | Lock-behaviour check on staging at volume | Pre-release |
| Recovery §4 | Quarterly restore test, recorded | Quarterly |

---

## 15. Acceptance criteria

1. Every budget in §2.1–2.3 has an automated test asserting timing and query plan, with CI failing on a 20% regression.
2. C1–C4 correctness absolutes each verified across 100 repetitions with zero failures.
3. Every projection rebuilds exactly from its ledger on a production-scale dataset.
4. No float arithmetic in any money path, CI-enforced by static analysis.
5. Point-in-time recovery demonstrated to a 15-minute RPO, and a full restore within 4 hours, in a recorded quarterly test.
6. Route-to-policy coverage test passes with zero exempted endpoints.
7. Card data verifiably never reaches our servers; SAQ-A scope documented.
8. Log redaction verified: no password, token, card or full-address value appears in any log output under test.
9. Anonymisation workflow retains every financial record while rendering the person unidentifiable, verified per table.
10. WCAG 2.1 AA verified automatically on every page type and manually on the order pad, checkout and picking screens.
11. Degraded-mode behaviour verified for each dependency in §4, with no partially committed order in any case.
12. Zero-downtime migration demonstrated on a table of production scale under simulated load.

---

## 16. Open questions

| # | Question | Blocking |
|---|---|---|
| 1 | ~~Hosting target~~ | **CLOSED — Hostinger VPS (4 vCPU, 16 GB), Ubuntu 24.04 LTS, London**, PostgreSQL 16 with WAL archiving to independent object storage via pgBackRest. AWS (ECS/RDS/ElastiCache) as the enterprise blueprint. §11.5–11.6 |
| 2 | Business-hours definition for the 99.9% window — 07:00–19:00 Mon–Sat assumed | Client |
| 3 | Rugged handheld device list for warehouse testing | Client, before Stage 4 |
| 4 | Is an annual penetration test in budget? Strongly recommended given card-adjacent flows and credit data | Client |
| 5 | Retention of rejected applications — 2 years assumed | Client |
| 6 | Does the client have an existing DPA and processor register to fold into §7.5? | Client |
| 7 | ~~Accounting package~~ | **CLOSED — native Xero API integration, plus a Sage-compatible CSV export engine.** §12 |
| 8 | Is the 99.9% business-hours target contractual? If so, a warm standby with replication belongs in scope before launch, not after the first incident | **Worth answering** — §11.5 |
| 9 | Does the Xero integration warrant its own module spec (05.9)? Two-way sync, mapping and reconciliation are more than a §12 row can carry | **Worth answering** |
| 10 | Public customers (§12, 01 §4): how statutory consumer rights are met — cancellation window and process in 05.4, VAT-inclusive price display for public customers (03 §10 stores net and `companies.price_display_mode` needs a company, which a public customer does not have). Needs legal confirmation of the obligations, then spec work | **Yes, before public checkout goes live** |
