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
use App\Http\Controllers\CompanyInvitationController;
use App\Http\Controllers\CompanyMemberController;
use App\Http\Controllers\GuestOrderController;
use App\Http\Controllers\OrderConfirmationController;
use App\Http\Controllers\OrderLookupController;
use App\Http\Controllers\OrderPadController;
use App\Http\Controllers\Storefront\CatalogueController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\PriceDisplayController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Warehouse\DispatchPageController;
use App\Http\Controllers\Warehouse\GoodsInPageController;
use App\Http\Controllers\Warehouse\PickListPageController;
use App\Http\Controllers\Warehouse\StocktakePageController;
use Illuminate\Support\Facades\Route;

// 05.15 — the public storefront. Guests, public customers and trade users.
Route::get('/', HomeController::class)->name('home');
Route::get('/c/{slug}', [CatalogueController::class, 'category'])->where('slug', '[A-Za-z0-9-]+')->name('storefront.category');
Route::get('/search', [CatalogueController::class, 'search'])->name('storefront.search');
Route::get('/search/suggest', [CatalogueController::class, 'suggest'])->middleware('throttle:60,1')->name('storefront.suggest');
Route::get('/p/{slug}', [ProductController::class, 'show'])->where('slug', '[A-Za-z0-9-]+')->name('storefront.product');
Route::post('/price-display', PriceDisplayController::class)->middleware('throttle:30,1')->name('price-display');

// Doc 05.1. No auth middleware: guests have carts too (02 §14.3) and see
// base prices (05.13 §14).
Route::get('/order-pad', [OrderPadController::class, 'index'])->name('order-pad');

// 06 §8–9: cart and checkout (guests too — they have carts, 02 §14.3, and
// may check out without an account, 05.15 §6.1), and the confirmation.
Route::get('/cart', [CartPageController::class, 'show'])->name('cart');
Route::get('/checkout', [CheckoutPageController::class, 'show'])->name('checkout');
Route::get('/checkout/sign-in', [CheckoutPageController::class, 'signIn'])->name('checkout.sign-in');
Route::middleware('auth')->group(function (): void {
    Route::get('/orders/{order}/confirmation', [OrderConfirmationController::class, 'show'])
        ->whereUlid('order')
        ->name('orders.confirmation');
    // 05.4 §13.2: a consumer cancels before dispatch.
    Route::post('/orders/{order}/cancel', [OrderConfirmationController::class, 'cancel'])
        ->whereUlid('order')->middleware('throttle:10,1')->name('orders.cancel');
    // 05.4 §13.3: cancel items of a dispatched order.
    Route::post('/orders/{order}/cancel-items', [OrderConfirmationController::class, 'cancelItems'])
        ->whereUlid('order')->middleware('throttle:10,1')->name('orders.cancel-items');
});

// 05.15 §6.2–6.3: a guest's order page by signed link (not a sign-in
// link), saving their details as an account, and Find my order.
Route::get('/orders/{order}/guest/{expires}/{signature}', [GuestOrderController::class, 'show'])
    ->whereUlid('order')->whereNumber('expires')->where('signature', '[a-f0-9]{64}')
    ->name('orders.guest');
Route::post('/orders/{order}/guest/{expires}/{signature}/account', [GuestOrderController::class, 'createAccount'])
    ->whereUlid('order')->whereNumber('expires')->where('signature', '[a-f0-9]{64}')
    ->middleware('throttle:10,1')->name('orders.guest.account');
Route::post('/orders/{order}/guest/{expires}/{signature}/cancel', [GuestOrderController::class, 'cancel'])
    ->whereUlid('order')->whereNumber('expires')->where('signature', '[a-f0-9]{64}')
    ->middleware('throttle:10,1')->name('orders.guest.cancel');
Route::post('/orders/{order}/guest/{expires}/{signature}/cancel-items', [GuestOrderController::class, 'cancelItems'])
    ->whereUlid('order')->whereNumber('expires')->where('signature', '[a-f0-9]{64}')
    ->middleware('throttle:10,1')->name('orders.guest.cancel-items');
Route::get('/orders/lookup', [OrderLookupController::class, 'show'])->name('orders.lookup');
Route::post('/orders/lookup', [OrderLookupController::class, 'send'])->name('orders.lookup.send');

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

// 05.13 §9: the same invitation page for new and existing accounts.
Route::get('/company-invitations/{token}', [CompanyInvitationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')->name('company-invitations.show');
Route::get('/company-invitations/{token}/sign-in', [CompanyInvitationController::class, 'signIn'])
    ->where('token', '[A-Za-z0-9]{64}')->name('company-invitations.sign-in');
Route::post('/company-invitations/{token}', [CompanyInvitationController::class, 'accept'])
    ->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:10,1')->name('company-invitations.accept');

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

    Route::post('/account/companies/{company:public_id}/invitations', [CompanyInvitationController::class, 'store'])
        ->middleware('throttle:10,1')->name('account.invitations.store');
    Route::post('/account/invitations/{invitation:public_id}/resend', [CompanyInvitationController::class, 'resend'])
        ->middleware('throttle:10,1')->name('account.invitations.resend');
    Route::delete('/account/invitations/{invitation:public_id}', [CompanyInvitationController::class, 'revoke'])->name('account.invitations.revoke');
    Route::post('/account/invitations/{invitation:public_id}/accept', [CompanyInvitationController::class, 'acceptFromAccount'])->name('account.invitations.accept');
    // The member is a user, not a relation of the company: CompanyMemberService
    // resolves the membership itself (and 404s if there is none), so Laravel's
    // implicit parent-child scoping must not look for Company::members().
    Route::patch('/account/companies/{company:public_id}/members/{member:public_id}', [CompanyMemberController::class, 'update'])
        ->withoutScopedBindings()->name('account.members.update');
    Route::delete('/account/companies/{company:public_id}/members/{member:public_id}', [CompanyMemberController::class, 'destroy'])
        ->withoutScopedBindings()->name('account.members.destroy');

    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::get('/account/team', [AccountController::class, 'team'])->name('account.team');

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
