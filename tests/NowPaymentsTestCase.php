<?php

namespace Webkul\NowPayments\Tests;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Webkul\Customer\Models\Customer;
use Webkul\NowPayments\Contracts\PaymentAttempt;
use Webkul\NowPayments\Services\HostedCheckout;
use Webkul\Payment\Tests\Concerns\ProvidePaymentHelpers;

class NowPaymentsTestCase extends TestCase
{
    use ProvidePaymentHelpers;

    /**
     * Configure test credentials without contacting the real gateway.
     */
    public function configureGateway(): void
    {
        Http::preventStrayRequests();
        $this->setConfig([
            'sales.payment_methods.nowpayments.active' => '1',
            'sales.payment_methods.nowpayments.sandbox' => '1',
            'sales.payment_methods.nowpayments.test_api_key' => 'test-api-key',
            'sales.payment_methods.nowpayments.test_ipn_secret' => 'test-ipn-secret',
        ]);
    }

    /**
     * Start a real Bagisto pending order against a fake hosted invoice.
     */
    public function startPayment(array $cartOverrides = []): PaymentAttempt
    {
        $cart = $this->createCartWithItems('nowpayments', $cartOverrides);
        $this->actingAs(Customer::query()->findOrFail($cart->customer_id), 'customer');

        Http::fake([
            'api-sandbox.nowpayments.io/v1/invoice' => Http::response([
                'id' => '123456',
                'invoice_url' => 'https://sandbox.nowpayments.io/payment/?iid=123456',
            ]),
        ]);

        return app(HostedCheckout::class)->start($cart);
    }

    /**
     * Build authoritative payment data matching an invoice.
     */
    public function paymentData(PaymentAttempt $attempt, array $overrides = []): array
    {
        return array_replace([
            'payment_id' => '987654',
            'invoice_id' => $attempt->invoice_id,
            'order_id' => $attempt->reference,
            'payment_status' => 'finished',
            'price_amount' => $attempt->price_amount,
            'price_currency' => $attempt->price_currency,
            'pay_currency' => 'btc',
            'pay_amount' => '0.001234567891234567',
            'actually_paid' => '0.001234567891234567',
        ], $overrides);
    }

    /**
     * Send an independently signed notification to the stateless endpoint.
     */
    public function notifyPayment(PaymentAttempt $attempt, array $payload, bool $valid = true)
    {
        ksort($payload, SORT_STRING);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha512', $body, $valid ? 'test-ipn-secret' : 'wrong-secret');

        return $this->call('POST', route('nowpayments.webhook', ['reference' => $attempt->reference]), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_NOWPAYMENTS_SIG' => $signature,
        ], $body);
    }
}
