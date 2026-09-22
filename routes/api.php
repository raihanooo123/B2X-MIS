<?php

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
});
