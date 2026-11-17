<?php

namespace Webkul\NowPayments\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Webkul\NowPayments\Contracts\PaymentAttempt;

class NowPaymentsClient
{
    /**
     * Read a gateway setting in its original sales channel.
     */
    public function setting(string $field, string $channel): mixed
    {
        return core()->getConfigData('sales.payment_methods.nowpayments.'.$field, $channel);
    }

    /**
     * Create a hosted invoice without retrying a potentially successful POST.
     */
    public function createInvoice(PaymentAttempt $attempt, string $returnUrl): array
    {
        return $this->request($attempt)->post('invoice', [
            'price_amount' => $attempt->price_amount,
            'price_currency' => $attempt->price_currency,
            'order_id' => $attempt->reference,
            'order_description' => 'Order '.$attempt->order_id,
            'ipn_callback_url' => route('nowpayments.webhook', ['reference' => $attempt->reference]),
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
        ])->throw()->json();
    }

    /**
     * Retrieve authoritative payment details from NOWPayments.
     */
    public function payment(PaymentAttempt $attempt, string $paymentId): array
    {
        if (! preg_match('/^\d{1,30}$/D', $paymentId)) {
            throw new \InvalidArgumentException('Invalid NOWPayments payment identifier.');
        }

        return $this->request($attempt)
            ->connectTimeout(1)
            ->timeout(2)
            ->get('payment/'.$paymentId)
            ->throw()
            ->json();
    }

    /**
     * Accept checkout URLs only on the provider's HTTPS payment hosts.
     */
    public function validInvoiceUrl(string $url, bool $sandbox): bool
    {
        $parts = parse_url($url);
        $hosts = $sandbox ? ['sandbox.nowpayments.io'] : ['nowpayments.io'];

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && in_array($parts['host'] ?? '', $hosts, true)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port']);
    }

    /**
     * Create a bounded request with credentials for the attempt's original environment.
     */
    protected function request(PaymentAttempt $attempt): PendingRequest
    {
        $key = $this->setting(($attempt->sandbox ? 'test_' : '').'api_key', $attempt->channel_code);

        if (blank($key)) {
            throw new \RuntimeException('NOWPayments API credentials are missing.');
        }

        return Http::baseUrl($attempt->sandbox
            ? 'https://api-sandbox.nowpayments.io/v1/'
            : 'https://api.nowpayments.io/v1/')
            ->acceptJson()
            ->asJson()
            ->withHeaders(['x-api-key' => $key])
            ->connectTimeout(3)
            ->timeout(10)
            ->withoutRedirecting();
    }
}
