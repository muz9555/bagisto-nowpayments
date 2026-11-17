<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\NowPayments\Models\PaymentAttempt;
use Webkul\NowPayments\Payment\NowPayments;
use Webkul\NowPayments\Services\HostedCheckout;
use Webkul\Sales\Models\Order;

beforeEach(fn () => $this->configureGateway());

// ============================================================================
// Hosted Checkout
// ============================================================================

it('reserves a pending order and sends its exact fiat total to the hosted invoice', function () {
    $attempt = $this->startPayment();
    $order = Order::query()->findOrFail($attempt->order_id);

    expect($order->status)->toBe(Order::STATUS_PENDING_PAYMENT)
        ->and($order->invoices)->toHaveCount(0)
        ->and($attempt->price_currency)->toBe(strtolower($order->order_currency_code))
        ->and(CartModel::query()->find($attempt->cart_id)->is_active)->toBeFalse();

    Http::assertSent(fn ($request) => $request->url() === 'https://api-sandbox.nowpayments.io/v1/invoice'
        && $request->hasHeader('x-api-key', 'test-api-key')
        && $request['order_id'] === $attempt->reference
        && (string) $request['price_amount'] === $attempt->price_amount
        && ! isset($request['pay_currency'])
        && str_contains($request['ipn_callback_url'], $attempt->reference));
});

it('does not create another invoice or order for the same cart', function () {
    $attempt = $this->startPayment();
    $cart = CartModel::query()->findOrFail($attempt->cart_id);
    $repeated = app(HostedCheckout::class)->start($cart);

    expect($repeated->id)->toBe($attempt->id);
    Http::assertSentCount(1);
});

it('rolls back the reserved order when invoice creation fails', function () {
    $cart = $this->createCartWithItems('nowpayments');
    Http::fake(['*' => Http::response(['message' => 'Unavailable'], 503)]);

    expect(fn () => app(HostedCheckout::class)->start($cart))->toThrow(RequestException::class);

    expect(PaymentAttempt::query()->where('cart_id', $cart->id)->exists())->toBeFalse()
        ->and(Order::query()->where('cart_id', $cart->id)->exists())->toBeFalse()
        ->and($cart->fresh()->is_active)->toBeTrue();
});

it('rejects an invoice pointing outside the provider checkout hosts', function () {
    $cart = $this->createCartWithItems('nowpayments');
    Http::fake(['*' => Http::response(['id' => 123, 'invoice_url' => 'https://attacker.example/pay'])]);

    expect(fn () => app(HostedCheckout::class)->start($cart))->toThrow(UnexpectedValueException::class);
    expect(Order::query()->where('cart_id', $cart->id)->exists())->toBeFalse();
});

it('hides the gateway until active credentials include an IPN secret', function () {
    $this->setConfig('sales.payment_methods.nowpayments.test_ipn_secret', '');

    expect(app(NowPayments::class)->isAvailable())->toBeFalse();
    Http::assertNothingSent();
});

it('does not accept a direct redirect for another selected payment method', function () {
    $cart = $this->createCartWithItems('moneytransfer');

    expect(fn () => app(HostedCheckout::class)->start($cart))->toThrow(DomainException::class);
    Http::assertNothingSent();
});

it('requires a valid signed return URL and never marks an order paid from its query string', function () {
    $attempt = $this->startPayment();
    $url = app(HostedCheckout::class)->returnUrl($attempt);

    $this->get(route('nowpayments.status', ['reference' => $attempt->reference]))->assertForbidden();
    $this->get($url)->assertOk()->assertSee(trans('nowpayments::app.pending'));

    expect($attempt->fresh()->settled_at)->toBeNull()
        ->and(Order::query()->find($attempt->order_id)->status)->toBe(Order::STATUS_PENDING_PAYMENT);
    Http::assertSentCount(1);
});

it('reserves only the buy now cart and preserves the regular basket', function () {
    $basket = $this->createCartWithItems('moneytransfer');
    session()->put('cart', $basket->id);
    $attempt = $this->startPayment(['is_buy_now' => true]);

    expect(CartModel::query()->find($attempt->cart_id)->is_active)->toBeFalse()
        ->and($basket->fresh()->is_active)->toBeTrue()
        ->and(session('cart'))->toBe($basket->id);
});

it('sends a guest through the public checkout entry point', function () {
    $this->setConfig('sales.checkout.shopping_cart.allow_guest_checkout', '1');
    $cart = $this->createCartWithItems('nowpayments', ['customer_id' => null, 'is_guest' => true]);
    session()->put('cart', $cart->id);
    Http::fake(['api-sandbox.nowpayments.io/v1/invoice' => Http::response([
        'id' => '123456',
        'invoice_url' => 'https://sandbox.nowpayments.io/payment/?iid=123456',
    ])]);

    $this->get(route('nowpayments.redirect'))->assertRedirect('https://sandbox.nowpayments.io/payment/?iid=123456');

    expect(PaymentAttempt::query()->where('cart_id', $cart->id)->exists())->toBeTrue();
});
