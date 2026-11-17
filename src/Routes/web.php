<?php

use Illuminate\Support\Facades\Route;
use Webkul\NowPayments\Http\Controllers\NowPaymentsController;

Route::controller(NowPaymentsController::class)->prefix('nowpayments')->group(function () {
    Route::get('redirect', 'redirect')->middleware(['web', 'shop', 'cart.buy_now'])->name('nowpayments.redirect');
    Route::get('status/{reference}', 'status')->middleware(['web', 'shop', 'signed'])->name('nowpayments.status');
    Route::post('webhook/{reference?}', 'webhook')->name('nowpayments.webhook');
});
