<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\CompanyChoiceController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\CartPageController;
use App\Http\Controllers\CheckoutPageController;
use App\Http\Controllers\OrderConfirmationController;
use App\Http\Controllers\OrderPadController;
use App\Http\Controllers\Warehouse\DispatchPageController;
use App\Http\Controllers\Warehouse\GoodsInPageController;
use App\Http\Controllers\Warehouse\PickListPageController;
use App\Http\Controllers\Warehouse\StocktakePageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Dashboard');
});

// Doc 05.1. No auth middleware: guests have carts too (02 §14.3) and see
// base prices (05.13 §14).
Route::get('/order-pad', [OrderPadController::class, 'index'])->name('order-pad');

// 06 §8–9: cart (guests too — they have carts, 02 §14.3), checkout and
// the confirmation (signed in, 05.13 §4.1).
Route::get('/cart', [CartPageController::class, 'show'])->name('cart');
Route::middleware('auth')->group(function (): void {
    Route::get('/checkout', [CheckoutPageController::class, 'show'])->name('checkout');
    Route::get('/orders/{order}/confirmation', [OrderConfirmationController::class, 'show'])
        ->whereUlid('order')
        ->name('orders.confirmation');
});

// Doc 05.13 — authentication and onboarding.
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->name('login.store');

    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.challenge.store');

    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register/trade', [RegisterController::class, 'storeTrade'])->name('register.trade');
    Route::post('/register/public', [RegisterController::class, 'storePublic'])->name('register.public');

    Route::get('/forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
});

// Not a sign-in link: works signed in or out, on any device (05.13 §11).
Route::get('/email/verify/{user}/{expires}/{signature}', [EmailVerificationController::class, 'verify'])
    ->whereUlid('user')
    ->whereNumber('expires')
    ->where('signature', '[a-f0-9]{64}')
    ->name('verification.verify');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('/two-factor/setup', [TwoFactorSetupController::class, 'show'])->name('two-factor.setup');
    Route::post('/two-factor/setup/confirm', [TwoFactorSetupController::class, 'confirm'])->name('two-factor.setup.confirm');
    Route::post('/two-factor/setup/complete', [TwoFactorSetupController::class, 'complete'])->name('two-factor.setup.complete');
    Route::post('/two-factor/setup/reset', [TwoFactorSetupController::class, 'reset'])->name('two-factor.setup.reset');
    Route::post('/two-factor/recovery-codes', [TwoFactorSetupController::class, 'regenerateCodes'])->name('two-factor.setup.recovery-codes');
    Route::post('/two-factor/recovery-codes/confirm', [TwoFactorSetupController::class, 'confirmRegeneratedCodes'])->name('two-factor.setup.recovery-codes.confirm');
    Route::post('/two-factor/recovery-codes/cancel', [TwoFactorSetupController::class, 'cancelRegeneratedCodes'])->name('two-factor.setup.recovery-codes.cancel');
    Route::delete('/two-factor', [TwoFactorSetupController::class, 'destroy'])->name('two-factor.setup.disable');

    Route::get('/account', [AccountController::class, 'show'])->name('account');

    // 05.5 §4 — goods-in. Reads and writes via /api/v1/warehouse/*.
    Route::get('/warehouse/goods-in', [GoodsInPageController::class, 'show'])->name('warehouse.goods-in');
    // 05.5 §5, §7 — picking and dispatch. Via /api/v1/warehouse/shipments*.
    Route::get('/warehouse/pick-list', [PickListPageController::class, 'show'])->name('warehouse.pick-list');
    Route::get('/warehouse/dispatch', [DispatchPageController::class, 'show'])->name('warehouse.dispatch');
    // 05.5 §8 — stocktake. Via /api/v1/warehouse/stocktakes*.
    Route::get('/warehouse/stocktake', [StocktakePageController::class, 'show'])->name('warehouse.stocktake');

    Route::get('/choose-company', [CompanyChoiceController::class, 'show'])->name('company.choose');
    Route::post('/choose-company', [CompanyChoiceController::class, 'store'])->name('company.choose.store');
});
