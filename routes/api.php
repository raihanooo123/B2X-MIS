<?php

use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\PricingController;
use App\Http\Controllers\Api\V1\StockController;
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
});
