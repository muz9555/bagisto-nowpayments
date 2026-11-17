<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Webkul\NowPayments\Contracts\PaymentAttempt;
use Webkul\NowPayments\Models\PaymentAttempt as PaymentAttemptModel;
use Webkul\NowPayments\Models\PaymentAttemptProxy;

// ============================================================================
// Package Registration
// ============================================================================

it('registers the contract proxy routes commands and reconciliation schedule', function () {
    expect(app(PaymentAttempt::class))->toBeInstanceOf(PaymentAttemptModel::class)
        ->and(PaymentAttemptProxy::modelClass())->toBe(PaymentAttemptModel::class)
        ->and(Route::has('nowpayments.webhook'))->toBeTrue()
        ->and(Route::getRoutes()->getByName('nowpayments.webhook')->gatherMiddleware())->not->toContain('web')
        ->and(array_keys(Artisan::all()))->toContain('nowpayments:install', 'nowpayments:reconcile');

    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'nowpayments:reconcile'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/5 * * * *');
});
