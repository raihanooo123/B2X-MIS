<?php

use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\CollectionSlotController;
use App\Http\Controllers\Api\V1\Credit\CreditControlController;
use App\Http\Controllers\Api\V1\DocumentRenderController;
use App\Http\Controllers\Api\V1\PricingController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\Trade\ApprovalController;
use App\Http\Controllers\Api\V1\Trade\CompanyUserController;
use App\Http\Controllers\Api\V1\Trade\OrderImportController;
use App\Http\Controllers\Api\V1\Trade\OrderPaymentController;
use App\Http\Controllers\Api\V1\Trade\SavedListController;
use App\Http\Controllers\Api\V1\Trade\StatementController;
use App\Http\Controllers\Api\V1\Trade\TradeHistoryController;
use App\Http\Controllers\Api\V1\Warehouse\GoodsReceiptController;
use App\Http\Controllers\Api\V1\Warehouse\ReturnController;
use App\Http\Controllers\Api\V1\Warehouse\ShipmentController;
use App\Http\Controllers\Api\V1\Warehouse\StocktakeController;
use App\Http\Controllers\Api\V1\Webhooks\PostmarkWebhookController;
use App\Http\Controllers\Api\V1\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/**
 * `withRouting(api: ...)` (bootstrap/app.php) already applies the
 * 'api' middleware group and an '/api' prefix (Laravel's own default),
 * so `prefix('v1')` here is what gets these to `/api/v1/...` exactly,
 * per doc 06 §2's base path.
 */
Route::prefix('v1')->group(function (): void {
    Route::post('/pricing/bulk-resolve', [PricingController::class, 'bulkResolve']);
    Route::get('/stock/availability', [StockController::class, 'availability']);

    // 06 §8 — Cart and checkout. `{id}` is a cart line's ULID public_id.
    Route::get('/cart', [CartController::class, 'show']);
    Route::post('/cart/lines', [CartController::class, 'storeLine']);
    Route::patch('/cart/lines/{id}', [CartController::class, 'updateLine'])->whereUlid('id');
    Route::delete('/cart/lines/{id}', [CartController::class, 'destroyLine'])->whereUlid('id');
    Route::post('/cart/bulk-add', [CartController::class, 'bulkAdd']);
    Route::post('/checkout/preview', [CheckoutController::class, 'preview']);
    Route::get('/collection-slots', [CollectionSlotController::class, 'index']);
    // 06 §9.3 — requires an Idempotency-Key header (06 §6). Guests too
    // (05.15 §6.1): CartPolicy::checkout() requires the cart's owner.
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:60,1');
    // 07 §6.4 — authorise a card for the previewed total (Stripe Elements confirms it).
    Route::post('/checkout/card-intent', [CheckoutController::class, 'cardIntent'])->middleware('throttle:60,1');

    // 05.2 §18.3 — buyer approvals, company users, credit control and payouts. Decisions
    // and money movements require an Idempotency-Key (06 §18); every action re-checks
    // policy and state under the company lock.
    Route::middleware('auth')->group(function (): void {
        Route::post('/approvals/reject', [ApprovalController::class, 'bulkReject'])->middleware('throttle:30,1')->name('api.approvals.bulk-reject');
        Route::post('/approvals/{id}/approve', [ApprovalController::class, 'approve'])->whereUlid('id')->middleware('throttle:60,1')->name('api.approvals.approve');
        Route::post('/approvals/{id}/reject', [ApprovalController::class, 'reject'])->whereUlid('id')->middleware('throttle:60,1')->name('api.approvals.reject');
        Route::patch('/company-users/{userId}', [CompanyUserController::class, 'update'])->whereUlid('userId')->middleware('throttle:30,1')->name('api.company-users.update');

        // 05.2 §18.1: a trade order's buyer pays in advance, or pays once approved.
        Route::post('/orders/{order}/pay-in-advance', [OrderPaymentController::class, 'payInAdvance'])->whereUlid('order')->middleware('throttle:10,1')->name('api.orders.pay-in-advance');
        Route::post('/orders/{order}/card-intent', [OrderPaymentController::class, 'cardIntent'])->whereUlid('order')->middleware('throttle:20,1')->name('api.orders.card-intent');
        Route::post('/orders/{order}/pay', [OrderPaymentController::class, 'pay'])->whereUlid('order')->middleware('throttle:20,1')->name('api.orders.pay');

        // 05.1 §14.2 — bulk entry, saved lists and reorder. Previews never touch the basket;
        // confirmation merges once, re-checked under the cart lock.
        Route::post('/order-imports', [OrderImportController::class, 'store'])->middleware('throttle:20,1')->name('api.order-imports.store');
        Route::get('/order-imports/{id}', [OrderImportController::class, 'show'])->whereUlid('id')->middleware('throttle:120,1')->name('api.order-imports.show');
        Route::post('/order-imports/{id}/confirm', [OrderImportController::class, 'confirm'])->whereUlid('id')->middleware('throttle:30,1')->name('api.order-imports.confirm');
        Route::get('/saved-lists', [SavedListController::class, 'index'])->middleware('throttle:120,1')->name('api.saved-lists');
        Route::post('/saved-lists', [SavedListController::class, 'store'])->middleware('throttle:30,1')->name('api.saved-lists.store');
        Route::patch('/saved-lists/{id}', [SavedListController::class, 'update'])->whereUlid('id')->middleware('throttle:60,1')->name('api.saved-lists.update');
        Route::delete('/saved-lists/{id}', [SavedListController::class, 'destroy'])->whereUlid('id')->middleware('throttle:30,1')->name('api.saved-lists.destroy');
        Route::post('/saved-lists/{id}/preview', [SavedListController::class, 'preview'])->whereUlid('id')->middleware('throttle:30,1')->name('api.saved-lists.preview');
        Route::post('/orders/{id}/reorder-preview', [SavedListController::class, 'reorder'])->whereUlid('id')->middleware('throttle:30,1')->name('api.orders.reorder-preview');

        // 05.17 §4 — trade self-service reads, statements and PDF preparation.
        // GETs never queue; POSTs queue at most one render per document.
        Route::get('/trade/orders', [TradeHistoryController::class, 'orders'])->middleware('throttle:120,1')->name('api.trade.orders');
        Route::get('/trade/orders/{id}', [TradeHistoryController::class, 'order'])->whereUlid('id')->middleware('throttle:120,1')->name('api.trade.orders.show');
        Route::get('/trade/invoices', [TradeHistoryController::class, 'invoices'])->middleware('throttle:120,1')->name('api.trade.invoices');
        Route::post('/trade/statements', [StatementController::class, 'store'])->middleware('throttle:10,1')->name('api.trade.statements.store');
        Route::post('/document-renders', [DocumentRenderController::class, 'store'])->middleware('throttle:30,1')->name('api.document-renders.store');
        Route::get('/document-renders/{id}', [DocumentRenderController::class, 'show'])->whereUlid('id')->middleware('throttle:120,1')->name('api.document-renders.show');
        Route::post('/document-renders/{id}/retry', [DocumentRenderController::class, 'retry'])->whereUlid('id')->middleware('throttle:10,1')->name('api.document-renders.retry');

        // Accounts/admin (CompanyPolicy::manageCredit, OrderApprovalRequestPolicy).
        Route::patch('/companies/{company}/credit', [CreditControlController::class, 'update'])->whereUlid('company')->middleware('throttle:30,1')->name('api.companies.credit.update');
        Route::post('/credit-exceptions/{id}/approve', [CreditControlController::class, 'approveException'])->whereUlid('id')->middleware('throttle:60,1')->name('api.credit-exceptions.approve');
        Route::post('/credit-exceptions/{id}/reject', [CreditControlController::class, 'rejectException'])->whereUlid('id')->middleware('throttle:60,1')->name('api.credit-exceptions.reject');
        Route::post('/companies/{company}/credit-payouts', [CreditControlController::class, 'requestPayout'])->whereUlid('company')->middleware('throttle:10,1')->name('api.credit-payouts.store');
        Route::post('/credit-payouts/{id}/approve', [CreditControlController::class, 'approvePayout'])->whereUlid('id')->middleware('throttle:10,1')->name('api.credit-payouts.approve');
        Route::post('/credit-payouts/{id}/reject', [CreditControlController::class, 'rejectPayout'])->whereUlid('id')->middleware('throttle:10,1')->name('api.credit-payouts.reject');
    });

    // 06 §8 — goods-in (05.5 §4). Staff only; GoodsReceiptPolicy decides who.
    // Receiving a line requires an Idempotency-Key (06 §6, 05.5 §10).
    Route::middleware('auth')->prefix('warehouse')->group(function (): void {
        Route::get('/lookup', [GoodsReceiptController::class, 'lookup']);
        Route::post('/receipts', [GoodsReceiptController::class, 'store']);
        Route::get('/receipts/{id}', [GoodsReceiptController::class, 'show'])->whereUlid('id');
        Route::post('/receipts/{id}/lines', [GoodsReceiptController::class, 'storeLine'])->whereUlid('id');
        Route::post('/receipts/{id}/close', [GoodsReceiptController::class, 'close'])->whereUlid('id');

        // 05.5 §5–7: picking and dispatch. ShipmentPolicy decides who.
        // Dispatch requires an Idempotency-Key (06 §6, 05.5 §10).
        Route::post('/shipments', [ShipmentController::class, 'store']);
        Route::get('/shipments/{id}', [ShipmentController::class, 'show'])->whereUlid('id');
        Route::post('/shipments/{id}/serial-scans', [ShipmentController::class, 'scanSerial'])->whereUlid('id');
        Route::post('/shipments/{id}/picks', [ShipmentController::class, 'confirm'])->whereUlid('id');
        Route::post('/shipments/{id}/short-picks', [ShipmentController::class, 'shortPick'])->whereUlid('id');
        Route::post('/shipments/{id}/substitutions', [ShipmentController::class, 'substitute'])->whereUlid('id');
        Route::post('/shipments/{id}/dispatch', [ShipmentController::class, 'dispatch'])->whereUlid('id');

        // 05.4 §7.3, §13.5: returns. RmaPolicy decides who.
        // Booking in requires an Idempotency-Key (06 §6).
        Route::get('/returns/lookup', [ReturnController::class, 'lookup']);
        Route::post('/returns/{id}/receive', [ReturnController::class, 'receive'])->whereUlid('id');
        Route::post('/returns/{id}/reject-proof', [ReturnController::class, 'rejectProof'])->whereUlid('id');
        Route::post('/returns/{id}/inspect', [ReturnController::class, 'inspect'])->whereUlid('id');
        Route::post('/returns/{id}/resolve', [ReturnController::class, 'resolve'])->whereUlid('id');
        Route::post('/returns/{id}/advance-replacement', [ReturnController::class, 'advanceReplacement'])->whereUlid('id');
        Route::post('/returns/{id}/approve', [ReturnController::class, 'approve'])->whereUlid('id');
        Route::post('/returns/{id}/reject', [ReturnController::class, 'reject'])->whereUlid('id');
        Route::post('/returns/{id}/bank-refund', [ReturnController::class, 'bankRefund'])->whereUlid('id');
        Route::post('/returns/cancellations', [ReturnController::class, 'recordCancellation']);

        // 05.5 §8, 02 §24: stocktake. StocktakePolicy decides who.
        Route::post('/stocktakes', [StocktakeController::class, 'store']);
        Route::get('/stocktakes/{id}', [StocktakeController::class, 'show'])->whereUlid('id');
        Route::post('/stocktakes/{id}/lines', [StocktakeController::class, 'count'])->whereUlid('id');
        Route::post('/stocktakes/{id}/serials', [StocktakeController::class, 'scanSerial'])->whereUlid('id');
        Route::post('/stocktakes/{id}/serials/remove', [StocktakeController::class, 'removeSerial'])->whereUlid('id');
        Route::post('/stocktakes/{id}/review', [StocktakeController::class, 'review'])->whereUlid('id');
        Route::post('/stocktakes/{id}/reopen', [StocktakeController::class, 'reopen'])->whereUlid('id');
        Route::post('/stocktakes/{id}/post', [StocktakeController::class, 'post'])->whereUlid('id');
        Route::post('/stocktakes/{id}/cancel', [StocktakeController::class, 'cancel'])->whereUlid('id');
    });

    // Stripe → us. Signed, not session-authenticated.
    Route::post('/webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');
    // 05.12 §10.2 — email delivery, bounce and complaint events.
    Route::post('/webhooks/postmark', PostmarkWebhookController::class)->name('webhooks.postmark');
});
