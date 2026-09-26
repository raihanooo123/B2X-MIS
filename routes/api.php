<?php

use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\PricingController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\Warehouse\GoodsReceiptController;
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
    // 06 §9.3 — requires an Idempotency-Key header (06 §6).
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('auth');
    // 07 §6.4 — authorise a card for the previewed total (Stripe Elements confirms it).
    Route::post('/checkout/card-intent', [CheckoutController::class, 'cardIntent'])->middleware('auth');

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
