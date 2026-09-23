<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Dashboard');
});

// Doc 05.1. No auth middleware: guests have carts too (02 §14.3), and the
// storefront login flow (05.13) is not written yet.
Route::get('/order-pad', fn () => Inertia::render('OrderPad/Index'))->name('order-pad');
