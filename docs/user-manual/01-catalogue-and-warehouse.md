# 01 - Catalogue Setup and Warehouse Operations

| Document control | Value |
|---|---|
| Product | B2X Wholesale |
| Audience | Catalogue administrators, warehouse operators, purchasing and accounts viewers |
| Scope | New item setup; goods in; picking; dispatch; stocktake and stocktake history |
| Reviewed against | `feat/stocktake` working tree, 2026-09-26 |
| Status | Working draft for operational review, not a production sign-off |

## 1. Purpose and process map

Use this chapter to create an item record and manage physical stock through the warehouse screens. These are distinct activities:

```text
Define item:  Brand/Category -> Product -> SKU -> Pack(s)
Receive:      Supplier or other inbound stock -> Goods in -> Stock available
Fulfil:       Eligible customer order -> Picking -> Dispatch
Reconcile:    Physical count -> Stocktake review -> Stocktake posting
```

**Goods in means goods physically arriving at your warehouse.** A customer's paid or approved order does not itself pass through Goods in. If stock is already on the shelf, its warehouse journey begins at Picking. Stocktake is a periodic reconciliation, not a mandatory final step for each order. A new item record alone has no stock and no customer selling price.

This chapter describes the screens in the current application. It does not describe a complete purchasing, packing-station, or price-authoring workflow; those interfaces are not available as part of this chapter's reviewed build.

## 2. Access and prerequisites

Sign in through `/login`. Staff must complete two-factor authentication before using staff screens. Open `/admin` for catalogue administration and the Warehouse menu, or use the links in the warehouse page navigation.

| Role | Catalogue records | Goods in | Picking and dispatch | Stocktake |
|---|---|---|---|---|
| Admin | Create, view and edit | Operate | Operate | Count, post, view history |
| Warehouse | View Product, SKU and Pack | Operate | Operate | Count, post, view history |
| Purchasing | View catalogue | View receipts | No access | View counts and history |
| Accounts | View Product, SKU and Pack | No access | No access | View counts and history |
| Rep, sales manager | Catalogue viewing according to policy | No access | No access | No access |

Catalogue deletion is disabled. Admin is the only role permitted to create or edit Brand, Category, Product, SKU, or Pack. Warehouse navigation follows the same policies as the underlying pages; a hidden link does not grant or remove access by itself.

Before receiving stock, confirm that the item has a **Product**, a stock-tracked **SKU**, at least one **Pack**, and a valid **Location**. Product creation requires a primary Category; SKU creation requires a Tax class. Demo data, if installed, includes `MAIN` / Main Warehouse, example categories, brands, and tax classes. Do not assume these exist in a fresh or production database.

For a PO receipt, a receivable purchase order must already exist. The current Filament sidebar does not provide a complete purchase-order authoring workflow. For a controlled practice run, use a manual receipt and an existing location. Do not use fictional receipts in production.

## 3. Create a new item

An item has three levels:

| Level | Meaning | Example |
|---|---|---|
| Product | The commercial item and its description/category | Lemon Floor Cleaner 1L |
| SKU | The specific stock-keeping unit, tax and tracking rules | `TEST-FLOOR-1L-001` |
| Pack | A transaction quantity for that SKU | 1 bottle or 12-bottle case |

### 3.1 Create or select a brand and category

In **Admin -> Catalogue**, check **Brands** and **Categories** first. Reuse the correct existing records; do not create near-duplicates. A brand is optional on the Product form, but a primary category is required. An administrator can create a missing brand or category. Category may have a parent; a top-level category has none.

### 3.2 Create the Product

Open **Admin -> Catalogue -> Products -> New product**. Enter the name, check the generated slug, select **Simple** for an item with one SKU (or **Variant parent** for several variations), choose status, brand and primary category, and enter any description or RRP. Save.

`RRP` is a recommended retail price, **not** the customer's resolved selling price. `Published at` may be left blank to keep the product unpublished. Do not publish test products to a live storefront.

### 3.3 Create the SKU

Open **Admin -> Catalogue -> SKUs -> New SKU**, select the Product, then enter a unique SKU code. Choose the correct tax class and base unit. For a physically stocked item, leave **Stock tracked** on. Set the ordering limits and tracking mode deliberately:

- **None:** count quantities only; easiest for a first practice item.
- **Batch:** receive and count by batch; expiry can be required.
- **Serial:** record each individual unit's serial number.
- **Batch & serial:** both are required.

Do not switch on batch or serial tracking merely for a demonstration of a real item. Enter a real barcode only if verified; a made-up EAN could be scanned or reused by mistake. Saving a SKU does not create stock or pricing.

### 3.4 Add Packs

Open the saved SKU and its **Packs** section. Add at least one pack. `Base units` is how many base units the pack contains, and pack codes must be unique for that SKU. Mark only one pack as **Default sell pack**. An `EACH` pack of 1 and an `OUTER12` pack of 12 let an operative receive cases without mental conversion. Packs represent transaction quantities, not storage locations.

### 3.5 Practice data (non-production only)

Use these values only in a disposable demo or test environment. Select tax treatment based on the actual item before using it commercially.

| Screen | Field | Example |
|---|---|---|
| Product | Name | PrimeClean Lemon Floor Cleaner 1L |
| Product | Slug | `primeclean-lemon-floor-cleaner-1l` |
| Product | Type / status | Simple / Active |
| Product | Brand / category | PrimeClean / Cleaning Supplies > Household Cleaning, if seeded |
| Product | RRP | `3.99` (optional) |
| SKU | Code | `TEST-FLOOR-1L-001` |
| SKU | Status / base unit | Active / Each |
| SKU | Tax class | Applicable class; do not guess for real goods |
| SKU | Stock tracked / tracking | On / None |
| SKU | Minimum / increment | `1` / `1` |
| Pack 1 | Code / label / level / base units | `EACH` / Bottle / Each / `1` |
| Pack 1 | Sellable / default sell | Yes / Yes |
| Pack 2 | Code / label / level / base units | `OUTER12` / Case of 12 / Outer / `12` |
| Pack 2 | Sellable / default sell | Yes / No |

If a category, brand or tax class is absent, create or select an appropriate real one rather than forcing this example. The current admin UI has no complete price-list item authoring flow; **Active** and an RRP do not guarantee that customers can purchase the new SKU.

## 4. Goods in: receive physical stock

**Open:** **Admin -> Warehouse -> Goods in**, or `/warehouse/goods-in`.

1. Identify the source by scanning or typing a purchase-order number or container reference. For a receipt without paperwork, choose **No paperwork?**, select a location, and start a **manual receipt**. A manual receipt is flagged for purchasing to match later; it is not a substitute for an approved PO process.
2. Scan a case/SKU barcode or enter the SKU code. On a PO receipt, you can select an expected line. If the scan is ambiguous, choose the intended SKU from the results.
3. Select the pack and enter the number physically received. The screen shows the base-unit equivalent. Example: **5 x OUTER12 = 60 bottles**. Do not enter `60` as the number of cases.
4. Complete the fields required by this SKU. Batch-tracked stock requires a batch code; expiry is required when configured. Serial-tracked stock requires one serial for every base unit. An expiry warning must be explicitly confirmed if the date is correct.
5. Enter a bin code if known. For a manual receipt, cost per base unit is optional; a missing cost leaves margin information incomplete. Check the quantity and press **Book [quantity] units**. This is the point at which the receipt line is recorded and stock is added; opening a receipt alone does not add stock.
6. Repeat for other lines, then press **Close receipt**. For PO lines received short or over, choose the appropriate variance reason. For a short delivery expected later, the close form offers **Remainder expected on a later delivery**. Reopen the receipt from **Open receipts** if you must continue before closing it.

Do not book stock that has not been physically received. If the wrong quantity was booked, stop and follow the business correction process; do not quietly book another fictitious line to make the number look right. The ledger is append-only, and the current screen does not offer a general reversal button.

### Goods-in example

On `/warehouse/goods-in`, start a manual receipt at `MAIN` (if that location exists), enter `TEST-FLOOR-1L-001`, select `OUTER12`, enter `5`, optionally enter a verified cost per **bottle**, press **Book 60 units**, and close the receipt. The item should now be present as warehouse stock. This example does not create a customer order.

## 5. Picking: collect an eligible order

**Open:** **Admin -> Warehouse -> Picking**, or `/warehouse/pick-list`.

The **Ready to pick** queue shows orders with allocated stock at a location and a workable status. Prepaid orders are released only after payment is recorded. If the queue is empty, there may be no eligible allocated order; it does not mean Goods in failed. The page lists a maximum of 100 queue entries at a time.

1. Select the order in **Ready to pick**, or scan/type its order number. A shipment is opened or resumed for the order and location.
2. Follow the pick list in walk order. Check the SKU, suggested bin, pack-equivalent quantity, batch/expiry and any reserved serial numbers against the goods you take.
3. For a non-serial line, press **Picked all** only after physically collecting the full line. For a serial line, scan each reserved serial; an unallocated serial is refused.
4. If you cannot find enough stock, choose **Short**, enter what was actually picked, and select a reason. The system records the shortfall and may re-plan from other eligible stock or leave a backorder. Do not mark the line fully picked to clear the queue.
5. When offered, **Other batch** records an eligible batch substitution with a reason. Do not substitute a different product or an arbitrary serial.
6. When every line is picked or reported short, use **Go to dispatch**.

There is no separate packing-station screen in this reviewed build. Picking and dispatch are the operator-facing steps currently available.

## 6. Dispatch: confirm the goods leave

**Open:** **Admin -> Warehouse -> Dispatch**, or `/warehouse/dispatch`.

The **Picked, ready to go** queue contains picked/packed shipments. The shipment screen shows **On this shipment** and, for a partial shipment, **Still owed after this shipment**. Review those sections against the physical parcel before confirmation.

1. Open the shipment from the queue or by order number.
2. Check the item, batch and serial details. If anything remains unpicked, go back to Picking; dispatch is blocked until each line is picked or short-picked.
3. For delivery, enter the carrier. Record the tracking number if available; the field accepts a scanned courier label. Parcel count, total weight in kilograms and a note may be entered. For collection, use the handover form and record who collected the goods.
4. Press **Confirm dispatch** only when the parcel has actually been handed to the carrier or the goods have been handed over for collection. The server performs the dispatch as one operation; repeating the same request does not create a second dispatch.

Dispatch reduces stock for the shipped quantities. A partial dispatch leaves the remainder outstanding for a later shipment. Do not use Dispatch to correct a receiving or counting error.

## 7. Stocktake: reconcile physical and recorded stock

**Open:** **Admin -> Warehouse -> Stocktake**, or `/warehouse/stocktake`. **Stocktake history** in Filament is read-only; it is not the counting screen.

A stocktake is a session for one location. At most one open/review session exists for that location; starting another resumes it. Counting does **not** alter live stock. Trading may continue. This screen counts the SKU/batch identities you explicitly enter; **an omitted item is not automatically treated as zero**. Define the scope and count every intended identity before posting.

1. Select the location and choose whether to run a **Blind count**. Blind mode hides expected quantities during counting. Press **Start**, or resume a session under **In progress**.
2. Scan a SKU or case barcode. For an untracked or batch-tracked SKU, select the pack and enter packs plus loose units. Enter the correct batch when required, then **Save count**. For a serial-tracked SKU, scan each physical serial individually; remove an accidental scan before finishing. Use the explicit zero option when none are on the shelf.
3. Repeat for all items and batches in the agreed count scope. A recount replaces that line's quantity and count time. Do not assume a missing line will be adjusted to zero.
4. Press **Finish counting**. Review displays counted quantity, the expected quantity **at the time that line was counted**, current on-hand if it has since changed, variance, and missing/found serial numbers. A later sale or receipt is not itself a variance.
5. Investigate each discrepancy. Use **Count more** to reopen and correct the count where necessary. Select a reason for every nonzero quantity difference or serial discrepancy. The **Post stocktake** button is blocked while review reports unresolved conditions, including a missing serial reserved to an order.
6. Press **Post stocktake** only after review and approval under your operating procedure. Posting writes the stocktake movements together. To abandon a count before posting, use **Cancel stocktake**; it discards the count without changing live stock.

After posting, use **Admin -> Warehouse -> Stocktake history** to view the count, line variances, reasons and scanned serials. The history resource does not create or edit stocktakes. Do not use a stocktake as a routine substitute for Goods in, Dispatch, or a properly investigated stock correction.

## 8. Practical controls and troubleshooting

| What you see | What to check or do |
|---|---|
| Warehouse link absent | Confirm your staff role and 2FA enrolment. Purchasing/accounts have view-only access to some screens; reps do not operate warehouse flows. |
| `404` on a warehouse URL | Check the exact path above and the deployed route cache. Report the URL and time to the application maintainer; do not change deployment caches as an operator. |
| Goods-in SKU not found | Confirm SKU code/barcode, Product/SKU creation, and the Pack setup. A manual receipt needs a real location. |
| Goods-in rejects a line | Check pack belongs to the SKU, Stock tracked is on, and required batch/expiry/serial fields are complete. Read the inline error before retrying. |
| No orders in Ready to pick | Confirm an order exists, is workable, has allocated stock, and is paid if prepaid. A newly received item does not automatically create an order. |
| Serial scan rejected while picking | Compare the serial with the exact ones reserved on the pick line; do not bypass the refusal. |
| Dispatch blocked | Return to Picking and finish or short-pick every relevant line. Check the delivery carrier field. |
| Stocktake variance looks wrong | Check SKU, batch, pack conversion, count time and any trading since counting. Recount before posting if needed. |
| Stocktake cannot post | Read the per-line blockers and choose a reason for every discrepancy. Missing reserved serials require the related order issue to be resolved first. |

Do not repeatedly submit a transaction just because a response is slow. First inspect the receipt, shipment or count status. When reporting a problem, record the screen URL, reference number, SKU, location, observed message and approximate time; never send passwords, 2FA codes or payment details.

## 9. Current boundaries and glossary

**Current boundaries:** Product/SKU/Pack administration, the four warehouse operator pages and read-only stocktake history are present. Full purchase-order authoring, price-list item authoring and a standalone packing station are not part of these screens. Supplier-return and transfer workflows are also outside this chapter. Technical specifications may describe later capabilities; they are not evidence that a user can perform them today.

| Term | Meaning |
|---|---|
| SKU | The distinct item whose stock is tracked. |
| Base unit | The unit used for stock quantities, such as one bottle. |
| Pack | The quantity handled in one transaction, such as a case of 12 bottles. |
| Location | Warehouse or stock-holding site, such as `MAIN`. |
| Bin | An optional, more precise storage position within a location. |
| Allocation | Stock reserved for an order; it is not yet dispatched. |
| Shipment | The part of an order being picked and dispatched from a location. |
| Variance | Difference between the physical count and the expected stock at the count time. |
| Posting | Final stocktake step that writes stock movements to the ledger. |

### Maintenance note

Review this chapter when catalogue permissions, receiving, fulfilment, stocktake, purchasing or pricing UI changes. The reviewed implementation is in `app/Filament/Resources/`, `app/Policies/`, `app/Http/Controllers/Warehouse/`, `app/Domain/Warehouse/`, and `resources/js/pages/Warehouse/`. Do not copy planned behaviour from `docs/05.5-goods-in-picking-dispatch.md` into the operator instructions without checking the running screen and server rules.
