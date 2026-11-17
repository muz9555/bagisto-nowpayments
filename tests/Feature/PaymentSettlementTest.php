<?php

use Illuminate\Support\Facades\Http;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderTransaction;

beforeEach(fn () => $this->configureGateway());

// ============================================================================
// Verified Payment Settlement
// ============================================================================

it('settles a signed notification once even when it is delivered twice', function () {
    $attempt = $this->startPayment();
    $data = $this->paymentData($attempt);
    Http::fake(['*/payment/987654' => Http::sequence()->push($data)->push($data)]);

    $this->notifyPayment($attempt, $data)->assertOk()->assertJsonPath('status', 'settled');
    $this->notifyPayment($attempt, $data)->assertOk()->assertJsonPath('status', 'already_settled');
    $order = Order::query()->findOrFail($attempt->order_id);

    expect($attempt->fresh()->settled_at)->not->toBeNull()
        ->and($order->invoices)->toHaveCount(1)
        ->and(OrderTransaction::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('rejects a forged signature before querying the payment API', function () {
    $attempt = $this->startPayment();
    $this->notifyPayment($attempt, $this->paymentData($attempt), false)->assertUnauthorized();

    Http::assertSentCount(1);
    expect($attempt->fresh()->settled_at)->toBeNull();
});

it('uses the authoritative API status instead of the signed callback status', function () {
    $attempt = $this->startPayment();
    Http::fake(['*/payment/987654' => Http::response($this->paymentData($attempt, ['payment_status' => 'confirming']))]);

    $this->notifyPayment($attempt, $this->paymentData($attempt))->assertOk()->assertJsonPath('status', 'confirming');
    expect($attempt->fresh()->settled_at)->toBeNull()
        ->and(Order::query()->find($attempt->order_id)->invoices)->toHaveCount(0);
});

it('does not settle another invoice reference amount or currency', function ($field, $value) {
    $attempt = $this->startPayment();
    Http::fake(['*/payment/987654' => Http::response($this->paymentData($attempt, [$field => $value]))]);

    $this->notifyPayment($attempt, $this->paymentData($attempt))->assertUnprocessable();
    expect($attempt->fresh()->settled_at)->toBeNull();
})->with([
    ['invoice_id', 'other-invoice'],
    ['order_id', 'other-order'],
    ['price_currency', 'wrong'],
    ['price_amount', '0.0001'],
    ['payment_id', 'other-payment'],
]);

it('holds partial payments and underpaid finished payments for review', function ($status, $expectedStatus) {
    $attempt = $this->startPayment();
    $data = $this->paymentData($attempt, ['payment_status' => $status, 'actually_paid' => '0.001']);
    Http::fake(['*/payment/987654' => Http::response($data)]);

    $this->notifyPayment($attempt, $data)->assertOk()->assertJsonPath('status', $expectedStatus);
    expect($attempt->fresh()->settled_at)->toBeNull()
        ->and(Order::query()->find($attempt->order_id)->invoices)->toHaveCount(0);
})->with([['partially_paid', 'partially_paid'], ['finished', 'needs_review']]);

it('returns a retry response on a temporary API failure without invoicing', function () {
    $attempt = $this->startPayment();
    Http::fake(['*/payment/987654' => Http::response([], 503)]);

    $this->notifyPayment($attempt, $this->paymentData($attempt))->assertStatus(503);
    expect(Order::query()->find($attempt->order_id)->invoices)->toHaveCount(0)
        ->and($attempt->fresh()->payment_id)->toBe('987654');
});

it('cancels expired unpaid reservations and holds a later payment for review', function () {
    $attempt = $this->startPayment();
    $data = $this->paymentData($attempt, ['payment_status' => 'expired']);
    Http::fake(['*/payment/987654' => Http::sequence()
        ->push($data)
        ->push(array_replace($data, ['payment_status' => 'finished']))]);
    $this->notifyPayment($attempt, $data)->assertOk();

    expect(Order::query()->find($attempt->order_id)->status)->toBe(Order::STATUS_CANCELED);

    $data['payment_status'] = 'finished';
    $this->notifyPayment($attempt, $data)->assertOk()->assertJsonPath('status', 'needs_review');
    expect(Order::query()->find($attempt->order_id)->invoices)->toHaveCount(0);
});

it('reconciles a missed update using the previously verified payment identifier', function () {
    $attempt = $this->startPayment();
    $data = $this->paymentData($attempt, ['payment_status' => 'waiting']);
    Http::fake(['*/payment/987654' => Http::sequence()
        ->push($data)
        ->push(array_replace($data, ['payment_status' => 'finished']))]);
    $this->notifyPayment($attempt, $data)->assertOk();

    $data['payment_status'] = 'finished';
    $this->artisan('nowpayments:reconcile')->assertSuccessful();

    expect($attempt->fresh()->settled_at)->not->toBeNull();
});

it('keeps the original environment when sandbox mode changes after invoice creation', function () {
    $attempt = $this->startPayment();
    $this->setConfig('sales.payment_methods.nowpayments.sandbox', '0');
    Http::fake(['api-sandbox.nowpayments.io/v1/payment/987654' => Http::response($this->paymentData($attempt))]);

    $this->notifyPayment($attempt, $this->paymentData($attempt))->assertOk()->assertJsonPath('status', 'settled');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/payment/987654')
        && $request->hasHeader('x-api-key', 'test-api-key'));
});

it('releases overdue reservations even when no payment notification ever arrived', function () {
    $attempt = $this->startPayment();
    $this->travel(25)->hours();

    $this->artisan('nowpayments:reconcile')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe('reservation_expired')
        ->and(Order::query()->find($attempt->order_id)->status)->toBe(Order::STATUS_CANCELED);
    Http::assertSentCount(1);
});

it('does not release a paid reservation when the reservation window expires', function () {
    $attempt = $this->startPayment();
    Http::fake(['*/payment/987654' => Http::response($this->paymentData($attempt))]);
    $this->notifyPayment($attempt, $this->paymentData($attempt))->assertOk();
    $this->travel(25)->hours();

    $this->artisan('nowpayments:reconcile')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe('finished')
        ->and(Order::query()->find($attempt->order_id)->status)->not->toBe(Order::STATUS_CANCELED);
});

it('recovers an initial missed notification with a manually supplied payment ID', function () {
    $attempt = $this->startPayment();
    Http::fake(['*/payment/987654' => Http::response($this->paymentData($attempt))]);

    $this->artisan('nowpayments:reconcile', [
        'reference' => $attempt->reference,
        '--payment-id' => '987654',
    ])->assertSuccessful();

    expect($attempt->fresh()->settled_at)->not->toBeNull();
});

it('ignores an older notification after the order has already settled', function () {
    $attempt = $this->startPayment();
    $data = $this->paymentData($attempt);
    Http::fake(['*/payment/987654' => Http::sequence()
        ->push($data)
        ->push(array_replace($data, ['payment_status' => 'waiting']))]);

    $this->notifyPayment($attempt, $data)->assertOk()->assertJsonPath('status', 'settled');
    $this->notifyPayment($attempt, array_replace($data, ['payment_status' => 'waiting']))
        ->assertOk()->assertJsonPath('status', 'already_settled');

    expect($attempt->fresh()->status)->toBe('finished')
        ->and(Order::query()->find($attempt->order_id)->invoices)->toHaveCount(1);
});
